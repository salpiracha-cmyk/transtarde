<?php
declare(strict_types=1);

/**
 * Owner-authorized full TEST DATA reset for Transtrade.
 *
 * Clears operational/test data from Milling and Exports, plus customer/buyer
 * Parties master records. Preserves Accounts and every other Master Console
 * record, user, permission, document artwork, backup and application file.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function reset_arg(array $argv, string $name): string {
    $i = array_search($name, $argv, true);
    return ($i !== false && isset($argv[$i + 1])) ? trim((string)$argv[$i + 1]) : '';
}

$target = reset_arg($argv, '--target');
$resetId = reset_arg($argv, '--reset-id');
$apply = in_array('--apply', $argv, true);
if (!$apply) { fwrite(STDERR, "--apply is required.\n"); exit(2); }
if ($target === '' || !preg_match('~^/home/[^/]+/domains/app\.transtradeinternational\.com/public_html$~', $target)) {
    fwrite(STDERR, "Unexpected production target.\n"); exit(2);
}
if (!preg_match('/^[A-Za-z0-9._:-]{8,120}$/', $resetId)) { fwrite(STDERR, "Valid reset id required.\n"); exit(2); }
if (!is_file($target . '/auth_store.php') || !is_file($target . '/backup_lib.php')) {
    fwrite(STDERR, "Transtrade production files are unavailable.\n"); exit(2);
}

require $target . '/auth_store.php';
require $target . '/backup_lib.php';

const TT_RESET_EXPORT_KEY = 'transtrade_export_v3_operational';

function reset_atomic_json(string $path, array $data): void {
    $tmp = $path . '.reset-' . bin2hex(random_bytes(4));
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    if (file_put_contents($tmp, $json, LOCK_EX) === false) throw new RuntimeException('Could not write reset data.');
    @chmod($tmp, 0600);
    if (!rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException('Could not replace reset data.'); }
}
function reset_db_env(string $name): string {
    $constant = 'TT_' . $name;
    if (defined($constant)) return (string)constant($constant);
    $value = getenv($constant);
    return $value === false ? '' : (string)$value;
}
function reset_clean_export_root(string $resetId, array $tombstoneRefs): array {
    $tombstones = [];
    foreach (array_keys($tombstoneRefs) as $ref) {
        if ($ref === '') continue;
        $tombstones[] = [
            'id'=>'OWNER-RESET-' . substr(hash('sha256', $ref), 0, 18),
            'contractRef'=>$ref,
            'deletedAt'=>gmdate('c'),
            'ownerFullReset'=>true,
            'qaCleanup'=>true,
        ];
    }
    return [
        'version'=>'clean-v3',
        'customers'=>[], 'suppliers'=>[], 'fi'=>[], 'contracts'=>[], 'shipments'=>[],
        'accountsReceipts'=>[],
        'millSync'=>['newExportBags'=>[], 'productionInstructions'=>[], 'exportLoading'=>[]],
        'audits'=>[], 'alerts'=>[],
        'settings'=>['ownerTestResetId'=>$resetId, 'ownerTestResetAt'=>gmdate('c')],
        // Tombstones are technical reset guards only. They stop the legacy
        // recovery job from restoring pre-reset TEST contracts from snapshots.
        'deletedShipments'=>$tombstones,
    ];
}
function reset_collect_refs_from_root(mixed $root, array &$refs): void {
    if (!is_array($root)) return;
    foreach ((array)($root['contracts'] ?? []) as $row) {
        if (!is_array($row)) continue;
        $ref = trim((string)($row['ref'] ?? ''));
        if ($ref !== '') $refs[$ref] = true;
    }
    foreach ((array)($root['shipments'] ?? []) as $row) {
        if (!is_array($row)) continue;
        $ref = trim((string)($row['contractRef'] ?? ''));
        if ($ref !== '') $refs[$ref] = true;
    }
}
function reset_collect_refs_from_store(array $store, array &$refs): void {
    $raw = $store['values'][TT_RESET_EXPORT_KEY] ?? null;
    if (!is_string($raw) || $raw === '') return;
    reset_collect_refs_from_root(json_decode($raw, true), $refs);
}
function reset_collect_snapshot_refs(array &$refs): void {
    foreach (glob(tt_backup_dir() . '/*.zip') ?: [] as $snapshot) {
        try {
            $zip = new TT_SimpleZipReader($snapshot);
            $raw = $zip->get('System_Recovery/private/operations.json');
            $zip->close();
            if (!is_string($raw) || $raw === '') continue;
            $store = json_decode($raw, true);
            if (is_array($store)) reset_collect_refs_from_store($store, $refs);
        } catch (Throwable) {
            continue;
        }
    }
}
function reset_is_mill_transaction_key(string $key): bool {
    if (!preg_match('/^tt(?:30|32|33|34|35|36|37|38|39)[a-z0-9_]+$/', $key)) return false;
    // These are Mill configuration/master lists, not transactions.
    return !in_array($key, [
        'tt30mills','tt30arrivaldefaults','tt35brandmeta','tt37users',
        'tt38costmaster','tt38labourrates','tt39rentmaster','tt39salarymaster',
    ], true);
}
function reset_export_documents_dir(): array {
    $base = rtrim((string)TT_DATA_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'export_documents';
    if (!is_dir($base)) return ['filesDeleted'=>0,'indexReset'=>false];
    $deleted = 0;
    foreach (scandir($base) ?: [] as $name) {
        if ($name === '.' || $name === '..' || $name === 'index.json') continue;
        $path = $base . DIRECTORY_SEPARATOR . $name;
        if (is_file($path) && @unlink($path)) $deleted++;
    }
    reset_atomic_json($base . DIRECTORY_SEPARATOR . 'index.json', []);
    return ['filesDeleted'=>$deleted,'indexReset'=>true];
}

$summary = [
    'ok'=>false, 'resetId'=>$resetId, 'snapshot'=>'',
    'customerPartiesDeleted'=>0, 'operations'=>[], 'mysql'=>[], 'documents'=>[],
    'accounts'=>['preserved'=>false],
];

// Refuse accidental repeat after a successful owner reset marker.
$pre = tt_read_store();
if (!empty($pre['owner_operational_resets'][$resetId])) {
    echo json_encode(['ok'=>true,'applied'=>false,'resetId'=>$resetId,'reason'=>'already-applied'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

// Validate DB connectivity before touching anything. If production is using the
// file fallback, all three values are empty and this block is intentionally skipped.
$dbHost = reset_db_env('DB_HOST'); $dbName = reset_db_env('DB_NAME'); $dbUser = reset_db_env('DB_USER');
$db = null;
if ($dbHost !== '' || $dbName !== '' || $dbUser !== '') {
    if ($dbHost === '' || $dbName === '' || $dbUser === '') throw new RuntimeException('Incomplete production DB configuration; reset stopped.');
    $db = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        reset_db_env('DB_PASS'),
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]
    );
    $db->query('SELECT storage_key FROM tt_operation_records LIMIT 1');
}

$summary['snapshot'] = basename(tt_create_server_snapshot('pre-owner-full-test-reset'));
$accountsPath = rtrim((string)TT_DATA_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'accounts.json';
$accountsHashBefore = is_file($accountsPath) ? hash_file('sha256', $accountsPath) : null;

// Collect every historical Export reference before clearing. These references
// are retained only as hidden tombstones so the old recovery job cannot bring
// TEST contracts back after a later deployment.
$historicRefs = [];
$operationsPath = tt_operations_file();
$currentFileStore = is_file($operationsPath) ? json_decode((string)file_get_contents($operationsPath), true) : null;
if (is_array($currentFileStore)) reset_collect_refs_from_store($currentFileStore, $historicRefs);
reset_collect_snapshot_refs($historicRefs);

if ($db instanceof PDO) {
    $rows = $db->query('SELECT storage_key,payload,version FROM tt_operation_records')->fetchAll();
    foreach ($rows as $row) {
        if ((string)$row['storage_key'] === TT_RESET_EXPORT_KEY) {
            $root = json_decode((string)$row['payload'], true);
            reset_collect_refs_from_root($root, $historicRefs);
        }
    }
}
$cleanRoot = reset_clean_export_root($resetId, $historicRefs);
$cleanRootJson = json_encode($cleanRoot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

// Reset the live MySQL operational store when configured. Bumping versions
// invalidates stale open browser tabs so they cannot overwrite the reset.
if ($db instanceof PDO) {
    $db->beginTransaction();
    try {
        $rows = $db->query('SELECT storage_key,payload,version FROM tt_operation_records FOR UPDATE')->fetchAll();
        $foundExport = false; $clearedKeys = [];
        $update = $db->prepare('UPDATE tt_operation_records SET payload=?,version=?,updated_at=?,updated_by=?,updated_by_user=NULL,updated_by_module=? WHERE storage_key=?');
        $history = $db->prepare('INSERT INTO tt_operation_history (storage_key,version,payload_sha256,updated_at,updated_by,updated_by_user,updated_by_module) VALUES (?,?,?,?,?,NULL,?)');
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        foreach ($rows as $row) {
            $key = (string)$row['storage_key'];
            if ($key !== TT_RESET_EXPORT_KEY && !reset_is_mill_transaction_key($key)) continue;
            $payload = $key === TT_RESET_EXPORT_KEY ? $cleanRootJson : '[]';
            if ($key === TT_RESET_EXPORT_KEY) $foundExport = true;
            $version = (int)$row['version'] + 1;
            $update->execute([$payload,$version,$now,'Owner full TEST reset','Super Admin',$key]);
            $history->execute([$key,$version,hash('sha256',$payload),$now,'Owner full TEST reset','Super Admin']);
            $clearedKeys[$key] = $version;
        }
        if (!$foundExport) {
            $insert = $db->prepare('INSERT INTO tt_operation_records (storage_key,payload,version,updated_at,updated_by,updated_by_user,updated_by_module) VALUES (?,?,?,?,?,NULL,?)');
            $insert->execute([TT_RESET_EXPORT_KEY,$cleanRootJson,1,$now,'Owner full TEST reset','Super Admin']);
            $history->execute([TT_RESET_EXPORT_KEY,1,hash('sha256',$cleanRootJson),$now,'Owner full TEST reset','Super Admin']);
            $clearedKeys[TT_RESET_EXPORT_KEY] = 1;
        }
        try {
            $db->exec('UPDATE tt_export_documents SET deleted_at=UTC_TIMESTAMP(6) WHERE deleted_at IS NULL');
        } catch (Throwable) {
            // File metadata below is still reset; older installations may not have this table.
        }
        $db->commit();
        $summary['mysql'] = ['configured'=>true,'clearedKeys'=>$clearedKeys];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
} else {
    $summary['mysql'] = ['configured'=>false,'mode'=>'file-fallback'];
}

// Reset only the Exports root and Mill transaction keys in the shared mirror.
// Mill configuration/master keys and unrelated module data remain intact.
$store = is_array($currentFileStore) ? $currentFileStore : ['revision'=>0,'values'=>[],'meta'=>[]];
$allKeys = array_values(array_filter(
    array_unique(array_merge(array_keys((array)($store['values'] ?? [])), [TT_RESET_EXPORT_KEY])),
    fn($key) => $key === TT_RESET_EXPORT_KEY || reset_is_mill_transaction_key((string)$key)
));
foreach ($allKeys as $key) {
    $oldVersion = (int)($store['meta'][$key]['version'] ?? 0);
    $store['values'][$key] = $key === TT_RESET_EXPORT_KEY ? $cleanRootJson : '[]';
    $store['meta'][$key] = [
        'version'=>$oldVersion + 1,
        'updatedAt'=>gmdate('c'),
        'updatedBy'=>'Owner full TEST reset',
        'userId'=>0,
        'module'=>'Super Admin',
    ];
}
$store['revision'] = (int)($store['revision'] ?? 0) + 1;
reset_atomic_json($operationsPath, $store);
$summary['operations'] = ['keysReset'=>count($allKeys),'tombstoneRefs'=>count($historicRefs)];

$summary['documents'] = reset_export_documents_dir();

// Only customer/buyer parties are included. Suppliers, brokers, service
// providers and every other Super Admin master record remain untouched.
$partyResult = tt_mutate_store(function (&$data) use ($resetId, $summary): array {
    if (!isset($data['masters']) || !is_array($data['masters'])) $data['masters'] = [];
    $deleted = 0; $preserved = [];
    foreach ((array)($data['masters']['parties'] ?? []) as $party) {
        $values = is_array($party['values'] ?? null) ? array_values($party['values']) : [];
        $roles = (string)($values[2] ?? '');
        if (preg_match('/\b(?:export\s*)?(?:buyer|customer)\b/i', $roles)) { $deleted++; continue; }
        $preserved[] = $party;
    }
    $data['masters']['parties'] = $preserved;
    if (!isset($data['owner_operational_resets']) || !is_array($data['owner_operational_resets'])) $data['owner_operational_resets'] = [];
    $data['owner_operational_resets'][$resetId] = [
        'applied_at'=>gmdate('c'),
        'scope'=>'Exports + Milling transactional data and customer/buyer parties only; Accounts and other masters preserved',
        'safety_snapshot'=>$summary['snapshot'],
    ];
    if (!isset($data['audit']) || !is_array($data['audit'])) $data['audit'] = [];
    array_unshift($data['audit'], [
        'user_id'=>null,'username'=>'Super Admin',
        'action'=>'Owner RESET: cleared Exports, Milling transactions and customer/buyer parties',
        'ip_address'=>'GitHub Actions / Hostinger SSH','created_at'=>gmdate('c'),
    ]);
    return ['deleted'=>$deleted];
});
$summary['customerPartiesDeleted'] = (int)($partyResult['deleted'] ?? 0);

// Final server-side assertions.
$accountsHashAfter = is_file($accountsPath) ? hash_file('sha256', $accountsPath) : null;
if ($accountsHashBefore !== $accountsHashAfter) throw new RuntimeException('Accounts preservation verification failed.');
$summary['accounts'] = ['preserved'=>true,'present'=>$accountsHashBefore !== null];
$verifyAuth = tt_read_store();
foreach ((array)($verifyAuth['masters']['parties'] ?? []) as $party) {
    $values = is_array($party['values'] ?? null) ? array_values($party['values']) : [];
    if (preg_match('/\b(?:export\s*)?(?:buyer|customer)\b/i', (string)($values[2] ?? ''))) {
        throw new RuntimeException('Customer parties verification failed.');
    }
}
$verifyOps = json_decode((string)file_get_contents($operationsPath), true);
$verifyRootRaw = is_array($verifyOps) ? (string)($verifyOps['values'][TT_RESET_EXPORT_KEY] ?? '') : '';
$verifyRoot = $verifyRootRaw !== '' ? json_decode($verifyRootRaw, true) : null;
if (!is_array($verifyRoot) || count((array)($verifyRoot['contracts'] ?? [])) !== 0 || count((array)($verifyRoot['shipments'] ?? [])) !== 0) {
    throw new RuntimeException('Exports reset verification failed.');
}
if ($db instanceof PDO) {
    $q = $db->prepare('SELECT payload FROM tt_operation_records WHERE storage_key=? LIMIT 1');
    $q->execute([TT_RESET_EXPORT_KEY]);
    $payload = $q->fetchColumn();
    $root = is_string($payload) ? json_decode($payload, true) : null;
    if (!is_array($root) || count((array)($root['contracts'] ?? [])) !== 0 || count((array)($root['shipments'] ?? [])) !== 0) {
        throw new RuntimeException('MySQL Exports reset verification failed.');
    }
}
$summary['ok'] = true;
$summary['applied'] = true;
echo json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
