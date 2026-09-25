<?php
declare(strict_types=1);

/** Owner-authorized Export reset that preserves one exact contract family. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function xp_arg(array $argv, string $name): string {
    $i = array_search($name, $argv, true);
    return ($i !== false && isset($argv[$i + 1])) ? trim((string)$argv[$i + 1]) : '';
}
function xp_env(string $name): string {
    $constant = 'TT_' . $name;
    if (defined($constant)) return (string)constant($constant);
    $value = getenv($constant);
    return $value === false ? '' : (string)$value;
}
function xp_atomic_json(string $path, array $data): void {
    $tmp = $path . '.preserve-reset-' . bin2hex(random_bytes(4));
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    if (file_put_contents($tmp, $json, LOCK_EX) === false) throw new RuntimeException('Could not write reset data.');
    @chmod($tmp, 0600);
    if (!rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException('Could not replace reset data.'); }
}
function xp_row_ref(mixed $row): string {
    if (!is_array($row)) return '';
    foreach (['contractRef','contract_ref','_ttContractRef'] as $key) {
        $ref = trim((string)($row[$key] ?? ''));
        if ($ref !== '') return $ref;
    }
    return '';
}
function xp_refs(mixed $root): array {
    $refs = [];
    if (!is_array($root)) return $refs;
    foreach ((array)($root['contracts'] ?? []) as $row) {
        $ref = is_array($row) ? trim((string)($row['ref'] ?? '')) : '';
        if ($ref !== '') $refs[$ref] = true;
    }
    foreach ((array)($root['shipments'] ?? []) as $row) {
        $ref = xp_row_ref($row);
        if ($ref !== '') $refs[$ref] = true;
    }
    return $refs;
}
function xp_contains_ref(mixed $row, string $ref): bool {
    if (!is_array($row)) return false;
    if (xp_row_ref($row) === $ref) return true;
    foreach (['detail','message','reference','lotRef','sourceKey'] as $key) {
        if (str_contains((string)($row[$key] ?? ''), $ref)) return true;
    }
    return false;
}
function xp_prune_root(array $root, string $preserveRef, string $resetId, array $historicRefs): array {
    $contracts = array_values(array_filter((array)($root['contracts'] ?? []), static fn($row) => is_array($row) && trim((string)($row['ref'] ?? '')) === $preserveRef));
    if (count($contracts) !== 1) throw new RuntimeException('Expected exactly one preserved contract: ' . $preserveRef);
    $customerId = trim((string)($contracts[0]['customerId'] ?? ''));
    if ($customerId === '') throw new RuntimeException('Preserved contract has no customer linkage.');
    $customers = array_values(array_filter((array)($root['customers'] ?? []), static fn($row) => is_array($row) && (string)($row['id'] ?? '') === $customerId));
    if (count($customers) !== 1 || stripos((string)($customers[0]['name'] ?? ''), 'AMT') === false) {
        throw new RuntimeException('Preserved customer is not the expected AMT record.');
    }
    $shipments = array_values(array_filter((array)($root['shipments'] ?? []), static fn($row) => xp_row_ref($row) === $preserveRef));
    if (!$shipments) throw new RuntimeException('AMT shipment shell is missing; reset stopped.');
    $customerName = trim((string)($customers[0]['name'] ?? ''));
    $fi = [];
    foreach ((array)($root['fi'] ?? []) as $row) {
        if (!is_array($row)) continue;
        $allocations = array_values(array_filter((array)($row['allocations'] ?? []), static fn($a) => xp_row_ref($a) === $preserveRef));
        $belongs = strcasecmp(trim((string)($row['customer'] ?? '')), $customerName) === 0 || $allocations !== [];
        if (!$belongs) continue;
        $row['allocations'] = $allocations;
        $fi[] = $row;
    }
    // Customer and supplier lists are Export master records, not operational
    // shipment data. Keep every master row; only AMT operational rows survive.
    $root['customers'] = array_values((array)($root['customers'] ?? []));
    $root['contracts'] = $contracts;
    $root['shipments'] = $shipments;
    $root['fi'] = $fi;
    $root['accountsReceipts'] = array_values(array_filter((array)($root['accountsReceipts'] ?? []), static fn($row) => xp_contains_ref($row, $preserveRef)));
    $root['audits'] = array_values(array_filter((array)($root['audits'] ?? []), static fn($row) => xp_contains_ref($row, $preserveRef)));
    $root['alerts'] = array_values(array_filter((array)($root['alerts'] ?? []), static fn($row) => xp_contains_ref($row, $preserveRef)));
    $root['millSync'] = is_array($root['millSync'] ?? null) ? $root['millSync'] : [];
    foreach (['newExportBags','productionInstructions','exportLoading'] as $key) {
        $root['millSync'][$key] = array_values(array_filter((array)($root['millSync'][$key] ?? []), static fn($row) => xp_row_ref($row) === $preserveRef));
    }
    $tombstones = [];
    foreach ((array)($root['deletedShipments'] ?? []) as $row) {
        if (!is_array($row) || trim((string)($row['contractRef'] ?? '')) === $preserveRef) continue;
        $tombstones[] = $row;
    }
    foreach (array_keys($historicRefs) as $ref) {
        if ($ref === '' || $ref === $preserveRef) continue;
        $tombstones[] = ['id'=>'AMT-ONLY-' . substr(hash('sha256', $ref), 0, 18),'contractRef'=>$ref,'deletedAt'=>gmdate('c'),'ownerAmtOnlyReset'=>true,'qaCleanup'=>true];
    }
    $unique = [];
    foreach ($tombstones as $row) $unique[(string)($row['contractRef'] ?? '') . '|' . (string)($row['processId'] ?? '')] = $row;
    $root['deletedShipments'] = array_values($unique);
    $root['settings'] = is_array($root['settings'] ?? null) ? $root['settings'] : [];
    $root['settings']['ownerAmtOnlyResetId'] = $resetId;
    $root['settings']['ownerAmtOnlyResetAt'] = gmdate('c');
    $root['settings']['ownerAmtOnlyPreservedRef'] = $preserveRef;
    return $root;
}
function xp_prune_bridge_payload(string $json, string $preserveRef): string {
    $rows = json_decode($json, true);
    if (!is_array($rows) || !array_is_list($rows)) return $json;
    $kept = array_values(array_filter($rows, static function ($row) use ($preserveRef): bool {
        $ref = xp_row_ref($row);
        return $ref === '' || $ref === $preserveRef;
    }));
    return json_encode($kept, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}
function xp_delete_other_documents(?PDO $db, string $preserveRef): array {
    $base = rtrim((string)TT_DATA_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'export_documents';
    $names = [];
    if ($db instanceof PDO) {
        try {
            $q = $db->prepare('SELECT stored_name FROM tt_export_documents WHERE contract_ref<>? AND deleted_at IS NULL');
            $q->execute([$preserveRef]);
            $names = array_merge($names, array_map(static fn($row) => (string)$row['stored_name'], $q->fetchAll()));
            $u = $db->prepare('UPDATE tt_export_documents SET deleted_at=UTC_TIMESTAMP(6) WHERE contract_ref<>? AND deleted_at IS NULL');
            $u->execute([$preserveRef]);
        } catch (Throwable) {}
    }
    $indexPath = $base . DIRECTORY_SEPARATOR . 'index.json';
    $rows = is_file($indexPath) ? json_decode((string)file_get_contents($indexPath), true) : [];
    if (!is_array($rows)) $rows = [];
    foreach ($rows as &$row) {
        if (!is_array($row) || !empty($row['deleted_at']) || (string)($row['contract_ref'] ?? '') === $preserveRef) continue;
        $names[] = (string)($row['stored_name'] ?? '');
        $row['deleted_at'] = gmdate('c');
    }
    unset($row);
    if (is_dir($base)) xp_atomic_json($indexPath, $rows);
    $deleted = 0;
    foreach (array_unique(array_filter($names)) as $name) {
        $path = $base . DIRECTORY_SEPARATOR . basename($name);
        if (is_file($path) && @unlink($path)) $deleted++;
    }
    return ['filesDeleted'=>$deleted,'preservedContract'=>$preserveRef];
}

$target = xp_arg($argv, '--target');
$resetId = xp_arg($argv, '--reset-id');
$preserveRef = xp_arg($argv, '--preserve-ref');
if (!in_array('--apply', $argv, true)) { fwrite(STDERR, "--apply is required.\n"); exit(2); }
if ($target === '' || !preg_match('~^/home/[^/]+/domains/app\.transtradeinternational\.com/public_html$~', $target)) { fwrite(STDERR, "Unexpected production target.\n"); exit(2); }
if (!preg_match('/^[A-Za-z0-9._:-]{8,120}$/', $resetId)) { fwrite(STDERR, "Valid reset id required.\n"); exit(2); }
if ($preserveRef !== 'TG/AMT/13') { fwrite(STDERR, "Only the approved TG/AMT/13 preservation target is allowed.\n"); exit(2); }
require $target . '/auth_store.php';
require $target . '/backup_lib.php';
const XP_EXPORT_KEY = 'transtrade_export_v3_operational';
const XP_BRIDGE_KEYS = ['tt30bags','tt30prodinst','tt30ship','tt32exportsync','tt35exmill','tt35exload','tt39bridgequarantine'];

$auth = tt_read_store();
if (!empty($auth['owner_operational_resets'][$resetId])) {
    echo json_encode(['ok'=>true,'applied'=>false,'reason'=>'already-applied','resetId'=>$resetId], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}
$db = null;
if (xp_env('DB_HOST') !== '' || xp_env('DB_NAME') !== '' || xp_env('DB_USER') !== '') {
    if (xp_env('DB_HOST') === '' || xp_env('DB_NAME') === '' || xp_env('DB_USER') === '') throw new RuntimeException('Incomplete production DB configuration.');
    $db = new PDO('mysql:host='.xp_env('DB_HOST').';dbname='.xp_env('DB_NAME').';charset=utf8mb4', xp_env('DB_USER'), xp_env('DB_PASS'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
}
$operationsPath = tt_operations_file();
$store = is_file($operationsPath) ? json_decode((string)file_get_contents($operationsPath), true) : ['revision'=>0,'values'=>[],'meta'=>[]];
if (!is_array($store)) throw new RuntimeException('Operational mirror is invalid.');
$currentJson = (string)($store['values'][XP_EXPORT_KEY] ?? '');
$dbRow = null;
if ($db instanceof PDO) {
    $q = $db->prepare('SELECT payload,version FROM tt_operation_records WHERE storage_key=? LIMIT 1');
    $q->execute([XP_EXPORT_KEY]);
    $dbRow = $q->fetch() ?: null;
    if ($dbRow) $currentJson = (string)$dbRow['payload'];
}
$currentRoot = json_decode($currentJson, true);
if (!is_array($currentRoot)) throw new RuntimeException('Live Export payload is unavailable.');
$historicRefs = xp_refs($currentRoot);
foreach (glob(tt_backup_dir() . '/*.zip') ?: [] as $snapshot) {
    try {
        $zip = new TT_SimpleZipReader($snapshot); $raw = $zip->get('System_Recovery/private/operations.json'); $zip->close();
        $snap = is_string($raw) ? json_decode($raw, true) : null;
        $root = is_array($snap) ? json_decode((string)($snap['values'][XP_EXPORT_KEY] ?? ''), true) : null;
        foreach (xp_refs($root) as $ref => $_) $historicRefs[$ref] = true;
    } catch (Throwable) {}
}
$cleanRoot = xp_prune_root($currentRoot, $preserveRef, $resetId, $historicRefs);
$cleanJson = json_encode($cleanRoot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$accountsPath = rtrim((string)TT_DATA_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'accounts.json';
$accountsBefore = is_file($accountsPath) ? hash_file('sha256', $accountsPath) : null;
$snapshot = basename(tt_create_server_snapshot('pre-amt-only-export-reset'));
$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
$changedVersions = [];
if ($db instanceof PDO) {
    $db->beginTransaction();
    try {
        $rows = $db->query('SELECT storage_key,payload,version FROM tt_operation_records FOR UPDATE')->fetchAll();
        $update = $db->prepare('UPDATE tt_operation_records SET payload=?,version=?,updated_at=?,updated_by=?,updated_by_user=NULL,updated_by_module=? WHERE storage_key=?');
        $history = $db->prepare('INSERT INTO tt_operation_history (storage_key,version,payload_sha256,updated_at,updated_by,updated_by_user,updated_by_module) VALUES (?,?,?,?,?,NULL,?)');
        foreach ($rows as $row) {
            $key = (string)$row['storage_key'];
            if ($key !== XP_EXPORT_KEY && !in_array($key, XP_BRIDGE_KEYS, true)) continue;
            $payload = $key === XP_EXPORT_KEY ? $cleanJson : xp_prune_bridge_payload((string)$row['payload'], $preserveRef);
            $version = (int)$row['version'] + 1;
            $update->execute([$payload,$version,$now,'Owner AMT-only Export reset','Super Admin',$key]);
            $history->execute([$key,$version,hash('sha256',$payload),$now,'Owner AMT-only Export reset','Super Admin']);
            $changedVersions[$key] = $version;
        }
        if (!isset($changedVersions[XP_EXPORT_KEY])) throw new RuntimeException('Live Export record is missing.');
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}
foreach (array_unique(array_merge([XP_EXPORT_KEY], XP_BRIDGE_KEYS)) as $key) {
    if (!array_key_exists($key, (array)($store['values'] ?? [])) && $key !== XP_EXPORT_KEY) continue;
    $payload = $key === XP_EXPORT_KEY ? $cleanJson : xp_prune_bridge_payload((string)($store['values'][$key] ?? '[]'), $preserveRef);
    $store['values'][$key] = $payload;
    $store['meta'][$key] = ['version'=>(int)($store['meta'][$key]['version'] ?? 0)+1,'updatedAt'=>gmdate('c'),'updatedBy'=>'Owner AMT-only Export reset','userId'=>0,'module'=>'Super Admin'];
}
$store['revision'] = (int)($store['revision'] ?? 0) + 1;
xp_atomic_json($operationsPath, $store);
$documents = xp_delete_other_documents($db, $preserveRef);
$marker = tt_mutate_store(function (&$data) use ($resetId, $preserveRef, $snapshot): array {
    $data['owner_operational_resets'] = is_array($data['owner_operational_resets'] ?? null) ? $data['owner_operational_resets'] : [];
    $data['owner_operational_resets'][$resetId] = ['applied_at'=>gmdate('c'),'scope'=>'Exports pruned to '.$preserveRef.' only; Accounts and masters preserved','safety_snapshot'=>$snapshot];
    $data['audit'] = is_array($data['audit'] ?? null) ? $data['audit'] : [];
    array_unshift($data['audit'], ['user_id'=>null,'username'=>'Super Admin','action'=>'Owner RESET: preserved '.$preserveRef.' and removed other Export operations','ip_address'=>'GitHub Actions / Hostinger SSH','created_at'=>gmdate('c')]);
    return ['ok'=>true];
});
unset($marker);
$accountsAfter = is_file($accountsPath) ? hash_file('sha256', $accountsPath) : null;
if ($accountsBefore !== $accountsAfter) throw new RuntimeException('Accounts preservation verification failed.');
if (count($cleanRoot['contracts']) !== 1 || (string)$cleanRoot['contracts'][0]['ref'] !== $preserveRef) throw new RuntimeException('Final preservation assertion failed.');
$preservedCustomerId = (string)$cleanRoot['contracts'][0]['customerId'];
$preservedCustomer = array_values(array_filter((array)$cleanRoot['customers'], static fn($row)=>is_array($row)&&(string)($row['id']??'')===$preservedCustomerId))[0] ?? [];
echo json_encode(['ok'=>true,'applied'=>true,'resetId'=>$resetId,'preservedRef'=>$preserveRef,'preservedCustomer'=>(string)($preservedCustomer['name']??''),'preservedShipments'=>count($cleanRoot['shipments']),'preservedFI'=>count($cleanRoot['fi']),'removedRefs'=>count(array_filter(array_keys($historicRefs), static fn($ref)=>$ref!==$preserveRef)),'documents'=>$documents,'snapshot'=>$snapshot,'accountsPreserved'=>true,'mastersPreserved'=>true,'mysqlVersions'=>$changedVersions], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
