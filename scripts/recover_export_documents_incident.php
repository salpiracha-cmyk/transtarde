<?php
declare(strict_types=1);

/**
 * Recover genuine Export attachments removed by the legacy deletion QA run on
 * 2026-09-15. The operation is deliberately narrow, idempotent and CLI-only.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/auth_store.php';
require dirname(__DIR__) . '/backup_lib.php';

const TT_EXPORT_DOCUMENT_INCIDENT_SINCE = '2026-09-15 21:45:00';

function document_recovery_env(string $name): string {
    $constant = 'TT_' . $name;
    if (defined($constant)) return (string)constant($constant);
    $value = getenv($constant);
    return $value === false ? '' : (string)$value;
}

function document_recovery_is_test(string $value): bool {
    return (bool)preg_match('/(^|[\s\/_-])(QA|TEST|DUMMY|DEMO|SAMPLE|BULK|DOM)([\s\/_-]|$)/i', $value);
}

function document_recovery_business_refs(): array {
    $path = tt_operations_file();
    $store = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
    $raw = is_array($store) ? (string)($store['values']['transtrade_export_v3_operational'] ?? '') : '';
    $root = $raw !== '' ? json_decode($raw, true) : null;
    $refs = [];
    foreach ((array)($root['contracts'] ?? []) as $row) {
        if (!is_array($row)) continue;
        $ref = trim((string)($row['ref'] ?? ''));
        if ($ref !== '' && !document_recovery_is_test($ref)) $refs[strtolower($ref)] = true;
    }
    return $refs;
}

function document_recovery_db(): ?PDO {
    $host = document_recovery_env('DB_HOST');
    $name = document_recovery_env('DB_NAME');
    $user = document_recovery_env('DB_USER');
    if ($host === '' || $name === '' || $user === '') return null;
    return new PDO(
        "mysql:host={$host};dbname={$name};charset=utf8mb4",
        $user,
        document_recovery_env('DB_PASS'),
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]
    );
}

function document_recovery_snapshot_payload(string $entry): ?string {
    $snapshots = glob(tt_backup_dir() . '/*.zip') ?: [];
    rsort($snapshots, SORT_STRING);
    foreach ($snapshots as $snapshot) {
        try {
            $zip = new TT_SimpleZipReader($snapshot);
            $payload = $zip->get('System_Recovery/private/' . $entry);
            $zip->close();
            if (is_string($payload)) return $payload;
        } catch (Throwable) {
            continue;
        }
    }
    return null;
}

$apply = in_array('--apply', $argv, true);
$refs = document_recovery_business_refs();
$base = rtrim((string)TT_DATA_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'export_documents';
$db = document_recovery_db();
$candidates = [];

if ($db instanceof PDO) {
    $query = $db->prepare('SELECT id,contract_ref,stored_name,deleted_at FROM tt_export_documents WHERE deleted_at>=? ORDER BY deleted_at ASC');
    $query->execute([TT_EXPORT_DOCUMENT_INCIDENT_SINCE]);
    foreach ($query->fetchAll() as $row) {
        $ref = trim((string)($row['contract_ref'] ?? ''));
        $stored = basename((string)($row['stored_name'] ?? ''));
        if ($ref === '' || $stored === '' || document_recovery_is_test($ref) || !isset($refs[strtolower($ref)])) continue;
        $candidates[(string)$row['id']] = ['source'=>'db', 'id'=>(string)$row['id'], 'contractRef'=>$ref, 'storedName'=>$stored];
    }
}

$indexPath = $base . DIRECTORY_SEPARATOR . 'index.json';
$index = is_file($indexPath) ? json_decode((string)file_get_contents($indexPath), true) : [];
$index = is_array($index) ? $index : [];
foreach ($index as $id => $row) {
    if (!is_array($row) || empty($row['deleted_at']) || strtotime((string)$row['deleted_at']) < strtotime(TT_EXPORT_DOCUMENT_INCIDENT_SINCE . ' UTC')) continue;
    $ref = trim((string)($row['contract_ref'] ?? ''));
    $stored = basename((string)($row['stored_name'] ?? ''));
    if ($ref === '' || $stored === '' || document_recovery_is_test($ref) || !isset($refs[strtolower($ref)])) continue;
    $candidates[(string)$id] = ['source'=>'index', 'id'=>(string)$id, 'contractRef'=>$ref, 'storedName'=>$stored];
}

$summary = ['ok'=>true, 'applied'=>false, 'candidates'=>count($candidates), 'restored'=>0, 'alreadyPresent'=>0, 'unavailable'=>0];
if (!$apply || !$candidates) {
    $summary['reason'] = !$apply ? 'dry-run' : 'no-business-documents-in-incident-window';
    echo json_encode($summary, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

tt_backup_ensure_dirs();
if (!is_dir($base) && !mkdir($base, 0700, true) && !is_dir($base)) throw new RuntimeException('Export document directory is unavailable.');
$summary['safetySnapshot'] = tt_create_server_snapshot('pre-export-document-recovery');

foreach ($candidates as $id => $row) {
    $target = $base . DIRECTORY_SEPARATOR . $row['storedName'];
    if (is_file($target)) {
        $summary['alreadyPresent']++;
    } else {
        $payload = document_recovery_snapshot_payload('export_documents/' . $row['storedName']);
        if ($payload === null) {
            $summary['unavailable']++;
            continue;
        }
        $tmp = $target . '.restore-' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $payload, LOCK_EX) === false || !rename($tmp, $target)) {
            @unlink($tmp);
            throw new RuntimeException('A protected Export attachment could not be restored.');
        }
        @chmod($target, 0600);
    }
    if ($db instanceof PDO && $row['source'] === 'db') {
        $update = $db->prepare('UPDATE tt_export_documents SET deleted_at=NULL WHERE id=? AND deleted_at>=?');
        $update->execute([$id, TT_EXPORT_DOCUMENT_INCIDENT_SINCE]);
    }
    if (isset($index[$id]) && is_array($index[$id])) $index[$id]['deleted_at'] = null;
    $summary['restored']++;
}

if ($index) {
    $tmp = $indexPath . '.restore-' . bin2hex(random_bytes(4));
    file_put_contents($tmp, json_encode($index, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX);
    @chmod($tmp, 0600);
    if (!rename($tmp, $indexPath)) { @unlink($tmp); throw new RuntimeException('Export document index could not be restored.'); }
}

$summary['applied'] = $summary['restored'] > 0;
echo json_encode($summary, JSON_UNESCAPED_SLASHES) . PHP_EOL;
