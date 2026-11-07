<?php
declare(strict_types=1);

/**
 * Recover genuine Export records lost by the 2026-09-14 broad operational reset.
 *
 * Safe properties:
 * - CLI only and no-op unless --apply is supplied.
 * - current records always win;
 * - deliberate tombstones are respected;
 * - only issued/received, non-test contracts and directly related records return;
 * - a server snapshot is created before the atomic write;
 * - repeated runs are idempotent.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/auth_store.php';
require dirname(__DIR__) . '/backup_lib.php';

const TT_EXPORT_KEY = 'transtrade_export_v3_operational';
const TT_BAD_RESET_MARKER = '2026-09-14-operational-reset-v3';

function recovery_payload(array $store): ?array {
    $raw = $store['values'][TT_EXPORT_KEY] ?? null;
    if (!is_string($raw) || $raw === '') return null;
    $payload = json_decode($raw, true);
    return is_array($payload) ? $payload : null;
}

function recovery_store_from_snapshot(string $path): ?array {
    try {
        $zip = new TT_SimpleZipReader($path);
        $raw = $zip->get('System_Recovery/private/operations.json');
        $zip->close();
        if (!is_string($raw) || $raw === '') return null;
        $store = json_decode($raw, true);
        return is_array($store) ? $store : null;
    } catch (Throwable) {
        return null;
    }
}

function recovery_is_test_value(mixed $value): bool {
    $text = is_scalar($value) ? (string)$value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return (bool)preg_match('/(^|[\s\/_-])(QA|TEST|DUMMY|DEMO|SAMPLE|BULK|DOM)([\s\/_-]|$)/i', (string)$text);
}

function recovery_is_business_contract(mixed $row): bool {
    if (!is_array($row)) return false;
    $ref = trim((string)($row['ref'] ?? ''));
    if ($ref === '' || recovery_is_test_value($ref) || recovery_is_test_value($row['product'] ?? '')) return false;
    $status = strtolower(trim((string)($row['status'] ?? '')));
    return !empty($row['issued']) || !empty($row['received']) || ($status !== '' && $status !== 'draft');
}

function recovery_identity(array $row, array $fields): string {
    foreach ($fields as $field) {
        $value = strtolower(trim((string)($row[$field] ?? '')));
        if ($value !== '') return $field . ':' . $value;
    }
    return 'hash:' . hash('sha256', json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function recovery_add_missing(array $current, array $historic, array $fields, ?callable $allow = null): array {
    $seen = [];
    foreach ($current as $row) if (is_array($row)) $seen[recovery_identity($row, $fields)] = true;
    foreach ($historic as $row) {
        if (!is_array($row) || ($allow && !$allow($row))) continue;
        $identity = recovery_identity($row, $fields);
        if (isset($seen[$identity])) continue;
        $current[] = $row;
        $seen[$identity] = true;
    }
    return array_values($current);
}

$operationsPath = tt_operations_file();
$currentRaw = is_file($operationsPath) ? (string)file_get_contents($operationsPath) : '';
$currentStore = $currentRaw !== '' ? json_decode($currentRaw, true) : null;
$currentStore = is_array($currentStore) ? $currentStore : ['revision'=>0, 'values'=>[], 'meta'=>[]];
$current = recovery_payload($currentStore);
if ($current === null) {
    echo json_encode(['ok'=>true, 'applied'=>false, 'reason'=>'file-store-not-active']) . PHP_EOL;
    exit(0);
}

$snapshots = glob(tt_backup_dir() . '/auto-*.zip') ?: [];
rsort($snapshots, SORT_STRING);
$candidate = null;
$candidateCount = 0;
foreach ($snapshots as $snapshot) {
    $store = recovery_store_from_snapshot($snapshot);
    $payload = $store ? recovery_payload($store) : null;
    if (!$payload || (($payload['settings']['exportOperationalReset'] ?? '') === TT_BAD_RESET_MARKER)) continue;
    $contracts = array_values(array_filter((array)($payload['contracts'] ?? []), 'recovery_is_business_contract'));
    if (count($contracts) > $candidateCount) {
        $candidate = $payload;
        $candidateCount = count($contracts);
    }
}

if (!$candidate || $candidateCount === 0) {
    echo json_encode(['ok'=>true, 'applied'=>false, 'reason'=>'no-pre-reset-business-snapshot', 'snapshotsChecked'=>count($snapshots)]) . PHP_EOL;
    exit(0);
}

$tombstoneRefs = [];
foreach ((array)($current['deletedShipments'] ?? []) as $row) {
    if (!is_array($row) || !empty($row['restoredAt'])) continue;
    $ref = strtolower(trim((string)($row['contractRef'] ?? '')));
    if ($ref !== '') $tombstoneRefs[$ref] = true;
}
$currentRefs = [];
foreach ((array)($current['contracts'] ?? []) as $row) {
    if (!is_array($row)) continue;
    $ref = strtolower(trim((string)($row['ref'] ?? '')));
    if ($ref !== '') $currentRefs[$ref] = true;
}

$contracts = [];
foreach ((array)($candidate['contracts'] ?? []) as $row) {
    if (!recovery_is_business_contract($row)) continue;
    $ref = strtolower(trim((string)($row['ref'] ?? '')));
    if ($ref === '' || isset($currentRefs[$ref]) || isset($tombstoneRefs[$ref])) continue;
    $contracts[] = $row;
}
if (!$contracts) {
    echo json_encode(['ok'=>true, 'applied'=>false, 'reason'=>'no-missing-business-contracts', 'snapshotsChecked'=>count($snapshots)]) . PHP_EOL;
    exit(0);
}

$restoredRefs = [];
$customerIds = [];
foreach ($contracts as $row) {
    $restoredRefs[strtolower((string)$row['ref'])] = true;
    if (!empty($row['customerId'])) $customerIds[(string)$row['customerId']] = true;
}
$related = static fn(array $row): bool => isset($restoredRefs[strtolower(trim((string)($row['contractRef'] ?? ''))) ]);
$historicShipments = array_values(array_filter((array)($candidate['shipments'] ?? []), $related));
$fiIds = [];
foreach ($historicShipments as $shipment) {
    foreach ((array)($shipment['customs']['fiAllocations'] ?? []) as $allocation) {
        if (is_array($allocation) && !empty($allocation['fiId'])) $fiIds[(string)$allocation['fiId']] = true;
    }
}

$merged = $current;
$merged['contracts'] = recovery_add_missing((array)($current['contracts'] ?? []), $contracts, ['id', 'ref']);
$merged['customers'] = recovery_add_missing((array)($current['customers'] ?? []), (array)($candidate['customers'] ?? []), ['id', 'code', 'name'], static fn(array $row): bool => isset($customerIds[(string)($row['id'] ?? '')]));
$merged['shipments'] = recovery_add_missing((array)($current['shipments'] ?? []), $historicShipments, ['id', 'lotId', 'contractRef']);
$merged['fi'] = recovery_add_missing((array)($current['fi'] ?? []), (array)($candidate['fi'] ?? []), ['id', 'number'], static fn(array $row): bool => isset($fiIds[(string)($row['id'] ?? '')]));

$merged['millSync'] = (array)($current['millSync'] ?? []);
foreach (['newExportBags', 'productionInstructions', 'exportLoading'] as $key) {
    $historic = array_values(array_filter((array)($candidate['millSync'][$key] ?? []), $related));
    $merged['millSync'][$key] = recovery_add_missing((array)($current['millSync'][$key] ?? []), $historic, ['id', 'lotId', 'contractRef']);
}

$auditRows = [];
foreach ((array)($candidate['audits'] ?? []) as $row) {
    if (!is_array($row)) continue;
    $detail = strtolower((string)($row['detail'] ?? ''));
    foreach ($restoredRefs as $ref => $_) if ($ref !== '' && str_contains($detail, $ref)) { $auditRows[] = $row; break; }
}
$merged['audits'] = recovery_add_missing((array)($current['audits'] ?? []), $auditRows, ['id', 'at']);
$merged['audits'][] = [
    'id'=>'AUD-EXPORT-RECOVERY-' . gmdate('YmdHis'), 'at'=>gmdate('c'), 'user'=>'System',
    'area'=>'Exports', 'action'=>'Recovered missing issued contracts from protected pre-reset snapshot',
    'detail'=>count($contracts) . ' contract(s) and directly related records restored; current records preserved.'
];

$summary = [
    'ok'=>true, 'applied'=>false, 'contracts'=>count($contracts),
    'customers'=>count($merged['customers']) - count((array)($current['customers'] ?? [])),
    'shipments'=>count($merged['shipments']) - count((array)($current['shipments'] ?? [])),
    'fi'=>count($merged['fi']) - count((array)($current['fi'] ?? [])),
    'snapshotsChecked'=>count($snapshots)
];
if (!in_array('--apply', $argv, true)) {
    echo json_encode($summary, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$safety = tt_create_server_snapshot('pre-export-record-recovery');
$handle = fopen($operationsPath, 'c+');
if ($handle === false || !flock($handle, LOCK_EX)) throw new RuntimeException('Operational store lock failed.');
try {
    rewind($handle);
    $latestRaw = stream_get_contents($handle);
    $latestStore = $latestRaw ? json_decode($latestRaw, true) : null;
    if (!is_array($latestStore) || !hash_equals(hash('sha256', $currentRaw), hash('sha256', $latestRaw))) {
        throw new RuntimeException('Operational data changed during recovery; no records were written.');
    }
    $latestStore['values'][TT_EXPORT_KEY] = json_encode($merged, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $latestStore['revision'] = (int)($latestStore['revision'] ?? 0) + 1;
    $version = (int)($latestStore['meta'][TT_EXPORT_KEY]['version'] ?? 0) + 1;
    $latestStore['meta'][TT_EXPORT_KEY] = ['version'=>$version, 'updatedAt'=>gmdate('c'), 'updatedBy'=>'Protected Export recovery', 'userId'=>0, 'module'=>'Super Admin'];
    $encoded = json_encode($latestStore, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    rewind($handle);
    if (!ftruncate($handle, 0) || fwrite($handle, $encoded) === false) throw new RuntimeException('Operational recovery write failed.');
    fflush($handle);
} finally {
    flock($handle, LOCK_UN);
    fclose($handle);
}

$summary['applied'] = true;
$summary['safetySnapshot'] = basename($safety);
echo json_encode($summary, JSON_UNESCAPED_SLASHES) . PHP_EOL;
