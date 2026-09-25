<?php
declare(strict_types=1);

require dirname(__DIR__) . '/auth_store.php';
require dirname(__DIR__) . '/backup_lib.php';
require_once dirname(__DIR__) . '/inventory_reconciliation.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function operations_respond(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function operations_key_allowed(string $key): bool {
    return $key === 'transtrade_export_v3_operational'
        || (bool)preg_match('/^tt[0-9]{2}[a-z0-9_]{2,60}$/', $key);
}

function operations_can_write(array $user, string $module): bool {
    if (($user['role'] ?? '') === 'Super Admin') return true;
    $permissions = (array)($user['permissions'] ?? []);
    $permissionName = strcasecmp($module, 'Milling') === 0 ? 'Mill' : $module;
    $granted = $permissions[$permissionName] ?? [];
    if ($granted === 'all') return true;
    if (!is_array($granted)) return false;
    foreach (['Create','Edit'] as $action) if (in_array($action,$granted,true)) return true;
    foreach ($granted as $actions) if (is_array($actions) && (in_array('Create',$actions,true)||in_array('Edit',$actions,true))) return true;
    return false;
}

function operations_env(string $name): string {
    $constant = 'TT_' . $name;
    if (defined($constant)) return (string)constant($constant);
    $value = getenv($constant);
    return $value === false ? '' : $value;
}

function operations_db(): PDO {
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    $host = operations_env('DB_HOST');
    $name = operations_env('DB_NAME');
    $user = operations_env('DB_USER');
    $pass = operations_env('DB_PASS');
    if ($host === '' || $name === '' || $user === '') {
        throw new RuntimeException('MySQL is not configured.');
    }
    $pdo = new PDO(
        "mysql:host={$host};dbname={$name};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    return $pdo;
}

function operations_install(PDO $db): void {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS tt_operation_records (
        storage_key VARCHAR(96) NOT NULL PRIMARY KEY,
        payload LONGTEXT NOT NULL,
        version BIGINT UNSIGNED NOT NULL DEFAULT 1,
        updated_at DATETIME(6) NOT NULL,
        updated_by VARCHAR(160) NOT NULL,
        updated_by_user BIGINT NULL,
        updated_by_module VARCHAR(32) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS tt_operation_history (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        storage_key VARCHAR(96) NOT NULL,
        version BIGINT UNSIGNED NOT NULL,
        payload_sha256 CHAR(64) NOT NULL,
        updated_at DATETIME(6) NOT NULL,
        updated_by VARCHAR(160) NOT NULL,
        updated_by_user BIGINT NULL,
        updated_by_module VARCHAR(32) NOT NULL,
        INDEX idx_operation_history_key_version (storage_key, version),
        INDEX idx_operation_history_time (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS tt_export_documents (
        id CHAR(32) NOT NULL PRIMARY KEY,
        contract_ref VARCHAR(100) NOT NULL,
        lot_ref VARCHAR(100) NOT NULL DEFAULT '',
        category VARCHAR(64) NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        stored_name VARCHAR(80) NOT NULL UNIQUE,
        mime_type VARCHAR(100) NOT NULL,
        file_size BIGINT UNSIGNED NOT NULL,
        uploaded_at DATETIME(6) NOT NULL,
        uploaded_by VARCHAR(160) NOT NULL,
        uploaded_by_user BIGINT NULL,
        deleted_at DATETIME(6) NULL,
        INDEX idx_export_documents_lot (contract_ref, lot_ref, category),
        INDEX idx_export_documents_uploaded (uploaded_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (PDOException $e) {
        // Production may intentionally revoke CREATE after operations_schema.sql is run.
        $db->query('SELECT storage_key FROM tt_operation_records LIMIT 1');
        $db->query('SELECT storage_key FROM tt_operation_history LIMIT 1');
        $db->query('SELECT id FROM tt_export_documents LIMIT 1');
    }
}

function operations_list_by_identity(array $rows, string $identity): array {
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $key = (string)($row[$identity] ?? '');
        if ($key === '') $key = hash('sha256', json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $out[$key] = $row;
    }
    return $out;
}

function operations_union_rows(array $current, array $incoming, array $identities): array {
    $identity = '';
    foreach ($identities as $candidate) {
        foreach (array_merge($current, $incoming) as $row) {
            if (is_array($row) && isset($row[$candidate]) && (string)$row[$candidate] !== '') {
                $identity = $candidate;
                break 2;
            }
        }
    }
    if ($identity === '') {
        $seen = [];
        foreach (array_merge($current, $incoming) as $row) {
            $hash = hash('sha256', json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $seen[$hash] = $row;
        }
        return array_values($seen);
    }
    return array_values(array_replace(
        operations_list_by_identity($current, $identity),
        operations_list_by_identity($incoming, $identity)
    ));
}

function operations_export_reset_marker(): string {
    return '';
}

function operations_reset_export_payload(string $json): array {
    // Historical cleanup is permanently disabled. Operational records may only
    // be removed through an explicit, record-scoped deletion workflow.
    return [$json, false];
    $root = json_decode($json, true);
    if (!is_array($root)) return [$json, false];
    $settings = (array)($root['settings'] ?? []);
    if (($settings['exportOperationalReset'] ?? '') === operations_export_reset_marker()) return [$json, false];
    foreach (['customers', 'suppliers', 'fi', 'contracts', 'shipments', 'accountsReceipts', 'alerts', 'deletedShipments'] as $key) $root[$key] = [];
    $root['millSync'] = ['newExportBags'=>[], 'productionInstructions'=>[], 'exportLoading'=>[]];
    $root['audits'] = [[
        'id'=>'AUD-EXPORT-RESET-20260914',
        'at'=>gmdate('c'),
        'user'=>'System',
        'area'=>'Exports',
        'action'=>'Operational data reset approved by Super Admin',
        'detail'=>'All user-entered and bulk-test Export operational data cleared. Module code, users, permissions, settings and shared master definitions preserved.'
    ]];
    $settings['exportOperationalReset'] = operations_export_reset_marker();
    $root['settings'] = $settings;
    return [json_encode($root, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), true];
}

function operations_reset_export_documents(?PDO $db = null): void {
    $base = rtrim((string)TT_DATA_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'export_documents';
    $stored = [];
    if ($db instanceof PDO) {
        try {
            $rows = $db->query('SELECT stored_name FROM tt_export_documents WHERE deleted_at IS NULL')->fetchAll();
            foreach ($rows as $row) $stored[] = basename((string)($row['stored_name'] ?? ''));
            $db->exec('UPDATE tt_export_documents SET deleted_at=UTC_TIMESTAMP(6) WHERE deleted_at IS NULL');
        } catch (Throwable $e) {
            error_log('Export document reset DB cleanup: ' . $e->getMessage());
        }
    }
    $index = $base . DIRECTORY_SEPARATOR . 'index.json';
    if (is_file($index)) {
        $rows = json_decode((string)file_get_contents($index), true);
        if (is_array($rows)) {
            foreach ($rows as &$row) {
                if (!is_array($row) || !empty($row['deleted_at'])) continue;
                $stored[] = basename((string)($row['stored_name'] ?? ''));
                $row['deleted_at'] = gmdate('c');
            }
            unset($row);
            file_put_contents($index, json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX);
        }
    }
    foreach (array_unique(array_filter($stored)) as $name) {
        $path = $base . DIRECTORY_SEPARATOR . $name;
        if (is_file($path) && !@unlink($path)) error_log('Export reset could not remove protected file ' . $name);
    }
}

function operations_apply_export_reset_file(string $path, array $user): void {
    return;
    if (!is_file($path)) return;
    $preview = json_decode((string)file_get_contents($path), true);
    $old = is_array($preview) ? (string)($preview['values']['transtrade_export_v3_operational'] ?? '') : '';
    [, $needed] = operations_reset_export_payload($old);
    if (!$needed) return;
    tt_maybe_auto_backup();
    $handle = fopen($path, 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) throw new RuntimeException('Shared operational storage is unavailable.');
    $changed = false;
    try {
        rewind($handle);
        $raw = stream_get_contents($handle);
        $store = $raw ? json_decode($raw, true) : null;
        if (!is_array($store)) $store = ['revision'=>0, 'values'=>[], 'meta'=>[]];
        [$value, $changed] = operations_reset_export_payload((string)($store['values']['transtrade_export_v3_operational'] ?? ''));
        if ($changed) {
            $key = 'transtrade_export_v3_operational';
            $store['values'][$key] = $value;
            $store['revision'] = (int)($store['revision'] ?? 0) + 1;
            $version = (int)($store['meta'][$key]['version'] ?? 0) + 1;
            $store['meta'][$key] = ['version'=>$version, 'updatedAt'=>gmdate('c'), 'updatedBy'=>'System reset approved by Super Admin', 'userId'=>(int)($user['id'] ?? 0), 'module'=>'Exports'];
            rewind($handle);
            if (!ftruncate($handle, 0)) throw new RuntimeException('Shared operational storage could not be reset.');
            if (fwrite($handle, json_encode($store, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) === false) throw new RuntimeException('Shared operational storage could not be reset.');
            fflush($handle);
        }
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
    if ($changed) operations_reset_export_documents();
}

function operations_apply_export_reset_db(PDO $db, array $user): void {
    return;
    $db->beginTransaction();
    $changed = false;
    try {
        $select = $db->prepare('SELECT payload, version FROM tt_operation_records WHERE storage_key=? FOR UPDATE');
        $select->execute(['transtrade_export_v3_operational']);
        $row = $select->fetch();
        if ($row) {
            [$value, $changed] = operations_reset_export_payload((string)$row['payload']);
            if ($changed) {
                tt_maybe_auto_backup();
                $version = (int)$row['version'] + 1;
                $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
                $update = $db->prepare('UPDATE tt_operation_records SET payload=?,version=?,updated_at=?,updated_by=?,updated_by_user=?,updated_by_module=? WHERE storage_key=?');
                $update->execute([$value, $version, $now, 'System reset approved by Super Admin', (int)($user['id'] ?? 0), 'Exports', 'transtrade_export_v3_operational']);
                $history = $db->prepare('INSERT INTO tt_operation_history (storage_key,version,payload_sha256,updated_at,updated_by,updated_by_user,updated_by_module) VALUES (?,?,?,?,?,?,?)');
                $history->execute(['transtrade_export_v3_operational', $version, hash('sha256', $value), $now, 'System reset approved by Super Admin', (int)($user['id'] ?? 0), 'Exports']);
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    if ($changed) operations_reset_export_documents($db);
}

function operations_merge_export(string $currentJson, string $incomingJson, string $sourceModule): string {
    $current = json_decode($currentJson, true);
    $incoming = json_decode($incomingJson, true);
    if (!is_array($current)) return $incomingJson;
    if (!is_array($incoming)) return $currentJson;
    $applyTombstones = static function (array $merged) use ($current, $incoming, $sourceModule): array {
        $authoritative = strcasecmp($sourceModule, 'Exports') === 0 || strcasecmp($sourceModule, 'Super Admin') === 0;
        $currentTombstones = (array)($current['deletedShipments'] ?? []);
        $incomingTombstones = (array)($incoming['deletedShipments'] ?? []);
        $tombstones = $authoritative
            ? operations_union_rows($currentTombstones, $incomingTombstones, ['id', 'contractRef'])
            : $currentTombstones;
        // A stale browser may still hold the deletion marker from before a
        // contract reference was legitimately reused. Restoration is a
        // permanent server decision: never let that browser reactivate the
        // marker by submitting an older copy without restoredAt.
        $restoredByRef = [];
        foreach ($currentTombstones as $row) {
            if (!is_array($row) || empty($row['restoredAt'])) continue;
            $ref = trim((string)($row['contractRef'] ?? ''));
            if ($ref !== '' && empty($row['processId'])) $restoredByRef[$ref] = $row;
        }
        foreach ($tombstones as &$row) {
            if (!is_array($row) || !empty($row['restoredAt'])) continue;
            $ref = trim((string)($row['contractRef'] ?? ''));
            if ($ref === '' || !empty($row['processId']) || !isset($restoredByRef[$ref])) continue;
            $row['restoredAt'] = $restoredByRef[$ref]['restoredAt'];
            $row['restoredBy'] = $restoredByRef[$ref]['restoredBy'] ?? 'Server';
            $row['restoredReason'] = $restoredByRef[$ref]['restoredReason'] ?? 'Contract reference legitimately reused after deletion';
        }
        unset($row);
        // A deletion marker protects a deleted shipment from being resurrected by
        // an old browser. It must not permanently reserve the contract reference.
        // A genuinely recreated Sales Contract has both a live contract row and a
        // new creation audit after the deletion time, which an old browser cannot
        // manufacture. Retire only that stale marker and preserve its history.
        $liveContractRefs = [];
        foreach ((array)($merged['contracts'] ?? []) as $contract) {
            if (!is_array($contract)) continue;
            $ref = trim((string)($contract['ref'] ?? ''));
            if ($ref !== '') $liveContractRefs[$ref] = true;
        }
        $recreatedAt = [];
        foreach ((array)($merged['audits'] ?? []) as $audit) {
            if (!is_array($audit)) continue;
            $area = (string)($audit['area'] ?? '');
            $action = (string)($audit['action'] ?? '');
            if (!preg_match('/^Sales Contract(?: Import)?$/i', $area)) continue;
            if (!preg_match('/^(?:Created|Draft created after Buyer & Reference|Reviewed contract saved and workflow started)$/i', $action)) continue;
            $ref = trim((string)($audit['detail'] ?? ''));
            if ($ref === '' || !isset($liveContractRefs[$ref])) continue;
            $at = strtotime((string)($audit['at'] ?? '')) ?: 0;
            if ($at > (int)($recreatedAt[$ref] ?? 0)) $recreatedAt[$ref] = $at;
        }
        foreach ($tombstones as &$row) {
            if (!is_array($row) || !empty($row['restoredAt'])) continue;
            $ref = trim((string)($row['contractRef'] ?? ''));
            if ($ref === '' || !empty($row['processId']) || !isset($recreatedAt[$ref])) continue;
            $deletedAt = strtotime((string)($row['deletedAt'] ?? '')) ?: PHP_INT_MAX;
            if ($recreatedAt[$ref] <= $deletedAt) continue;
            $row['restoredAt'] = gmdate('c', $recreatedAt[$ref]);
            $row['restoredReason'] = 'Contract reference legitimately reused after deletion';
        }
        unset($row);
        $merged['deletedShipments'] = $tombstones;
        $ids = [];$refs = [];$qaRefs = [];
        foreach ($tombstones as $row) {
            if (!is_array($row) || !empty($row['restoredAt'])) continue;
            $id = (string)($row['processId'] ?? $row['id'] ?? '');
            $ref = (string)($row['contractRef'] ?? '');
            if ($id !== '') $ids[$id] = true;
            if ($ref !== '' && (empty($row['processId']) || !empty($row['qaCleanup']))) $refs[$ref] = true;
            if ($ref !== '' && !empty($row['qaCleanup'])) $qaRefs[$ref] = true;
        }
        $merged['shipments'] = array_values(array_filter((array)($merged['shipments'] ?? []), static function ($row) use ($ids, $refs): bool {
            if (!is_array($row)) return false;
            return !isset($ids[(string)($row['id'] ?? '')]) && !isset($ids[(string)($row['parentProcessId'] ?? '')]) && !isset($refs[(string)($row['contractRef'] ?? '')]);
        }));
        $merged['contracts'] = array_values(array_filter((array)($merged['contracts'] ?? []), static function ($row) use ($qaRefs): bool {
            return is_array($row) && !isset($qaRefs[(string)($row['ref'] ?? '')]);
        }));
        return $merged;
    };

    if (strcasecmp($sourceModule, 'Accounts') === 0) {
        $merged = $current;
        $merged['accountsReceipts'] = operations_union_rows(
            (array)($current['accountsReceipts'] ?? []),
            (array)($incoming['accountsReceipts'] ?? []),
            ['id', 'receiptNo', 'reference']
        );
        $merged['alerts'] = operations_union_rows((array)($current['alerts'] ?? []), (array)($incoming['alerts'] ?? []), ['id', 'at']);
        $merged['audits'] = operations_union_rows((array)($current['audits'] ?? []), (array)($incoming['audits'] ?? []), ['id', 'at']);
        return json_encode($applyTombstones($merged), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    if (strcasecmp($sourceModule, 'Mill') === 0 || strcasecmp($sourceModule, 'Milling') === 0) {
        $merged = $current;
        $currentShipments = operations_list_by_identity((array)($current['shipments'] ?? []), 'id');
        foreach ((array)($incoming['shipments'] ?? []) as $incomingShipment) {
            if (!is_array($incomingShipment)) continue;
            $id = (string)($incomingShipment['id'] ?? '');
            if ($id === '' || !isset($currentShipments[$id])) continue;
            $currentShipments[$id]['millActuals'] = operations_union_rows(
                (array)($currentShipments[$id]['millActuals'] ?? []),
                (array)($incomingShipment['millActuals'] ?? []),
                ['number', 'container', 'id']
            );
        }
        $merged['shipments'] = array_values($currentShipments);
        $merged['alerts'] = operations_union_rows((array)($current['alerts'] ?? []), (array)($incoming['alerts'] ?? []), ['id', 'at']);
        $merged['audits'] = operations_union_rows((array)($current['audits'] ?? []), (array)($incoming['audits'] ?? []), ['id', 'at']);
        return json_encode($applyTombstones($merged), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    // A second browser must not start another process for the same contract.
    // Existing duplicate records remain untouched for explicit review.
    $currentProcesses = [];
    foreach ((array)($current['shipments'] ?? []) as $row) {
        if (!is_array($row) || ($row['kind'] ?? '') === 'lot' || !empty($row['cancelled'])) continue;
        $ref = (string)($row['contractRef'] ?? '');$id = (string)($row['id'] ?? '');
        if ($ref !== '' && $id !== '') $currentProcesses[$ref][$id] = true;
    }
    $deletedProcesses = [];
    foreach ((array)($incoming['deletedShipments'] ?? []) as $row) if (is_array($row) && empty($row['restoredAt'])) $deletedProcesses[(string)($row['processId'] ?? '')] = true;
    foreach ((array)($incoming['shipments'] ?? []) as $row) {
        if (!is_array($row) || ($row['kind'] ?? '') === 'lot' || !empty($row['cancelled'])) continue;
        $ref = (string)($row['contractRef'] ?? '');$id = (string)($row['id'] ?? '');
        if ($ref === '' || $id === '' || empty($currentProcesses[$ref]) || isset($currentProcesses[$ref][$id])) continue;
        foreach ($currentProcesses[$ref] as $existingId => $_) if (!isset($deletedProcesses[$existingId])) throw new InvalidArgumentException('A shipment already exists for '.$ref.'. Refresh before starting another one.');
    }
    // Export clients may have opened before another device or a protected
    // recovery added records. Merge every identified collection so a later
    // save cannot replace the server's complete business history with that
    // client's older partial copy. Incoming rows still replace matching IDs;
    // explicit shipment tombstones are applied below.
    $merged = $incoming;
    $merged['customers'] = operations_union_rows((array)($current['customers'] ?? []), (array)($incoming['customers'] ?? []), ['id', 'code', 'name']);
    $merged['suppliers'] = operations_union_rows((array)($current['suppliers'] ?? []), (array)($incoming['suppliers'] ?? []), ['id', 'code', 'name']);
    $merged['contracts'] = operations_union_rows((array)($current['contracts'] ?? []), (array)($incoming['contracts'] ?? []), ['id', 'ref']);
    $merged['fi'] = operations_union_rows((array)($current['fi'] ?? []), (array)($incoming['fi'] ?? []), ['id', 'number']);
    $merged['accountsReceipts'] = operations_union_rows((array)($current['accountsReceipts'] ?? []), (array)($incoming['accountsReceipts'] ?? []), ['id', 'receiptNo', 'reference']);
    $merged['shipments'] = operations_union_rows((array)($current['shipments'] ?? []), (array)($incoming['shipments'] ?? []), ['id', 'lotId']);
    $merged['millSync'] = (array)($incoming['millSync'] ?? []);
    foreach (['newExportBags', 'productionInstructions', 'exportLoading'] as $key) {
        $merged['millSync'][$key] = operations_union_rows(
            (array)($current['millSync'][$key] ?? []),
            (array)($incoming['millSync'][$key] ?? []),
            ['id', 'shipmentId', 'lotId', 'contractRef']
        );
    }
    $currentShipments = operations_list_by_identity((array)($current['shipments'] ?? []), 'id');
    foreach ((array)($merged['shipments'] ?? []) as &$shipment) {
        if (!is_array($shipment)) continue;
        $id = (string)($shipment['id'] ?? '');
        if ($id === '' || !isset($currentShipments[$id])) continue;
        $shipment['millActuals'] = operations_union_rows(
            (array)($shipment['millActuals'] ?? []),
            (array)($currentShipments[$id]['millActuals'] ?? []),
            ['number', 'container', 'id']
        );
    }
    unset($shipment);
    // Exports submits the complete operational document against an exact
    // baseVersion. Its alert list is therefore authoritative: retaining alerts
    // omitted by that document makes acknowledged/deleted alerts impossible to
    // clear. Concurrent writers are still protected by the version check above,
    // while Accounts and Milling keep their additive alert merge branches.
    $merged['alerts'] = array_values((array)($incoming['alerts'] ?? []));
    $merged['audits'] = operations_union_rows((array)($current['audits'] ?? []), (array)($incoming['audits'] ?? []), ['id', 'at']);
    return json_encode($applyTombstones($merged), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

/**
 * Hostinger-compatible persistence when no MySQL credentials are configured.
 * It uses the existing private operations.json shared by Mill and Accounts,
 * while preserving V3 revision checks and module-aware Export merging.
 */
function operations_file_fallback(array $user): never {
    $path = rtrim((string)TT_DATA_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'operations.json';
    tt_ensure_data_dir();
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!is_file($path)) operations_respond(['ok'=>true,'revision'=>0,'serverNow'=>gmdate('c')]+tt_inv_public_values([]));
        $handle = fopen($path, 'r');
        if ($handle === false || !flock($handle, LOCK_SH)) throw new RuntimeException('Shared operational storage is unavailable.');
        try { $raw = stream_get_contents($handle); }
        finally { flock($handle, LOCK_UN); fclose($handle); }
        $store = $raw ? json_decode($raw, true) : null;
        if (!is_array($store)) $store = ['revision'=>0,'values'=>[],'meta'=>[]];
        operations_respond(['ok'=>true,'revision'=>(int)($store['revision']??0),'serverNow'=>gmdate('c')]+tt_inv_public_values((array)($store['values']??[]),(array)($store['meta']??[])));
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') operations_respond(['ok'=>false,'error'=>'Method not allowed.'],405);
    $raw = file_get_contents('php://input') ?: '';
    if (strlen($raw) > 16 * 1024 * 1024) operations_respond(['ok'=>false,'error'=>'Operational update is too large.'],413);
    $body = json_decode($raw, true);
    if (!is_array($body) || !tt_verify_csrf((string)($body['csrf']??''))) operations_respond(['ok'=>false,'error'=>'Your session expired. Refresh and try again.'],419);
    $key=(string)($body['key']??''); $value=$body['value']??null; $baseVersion=max(0,(int)($body['baseVersion']??0)); $sourceModule=trim((string)($body['sourceModule']??''));
    if (!operations_key_allowed($key) || !is_string($value)) operations_respond(['ok'=>false,'error'=>'Invalid operational update.'],422);
    json_decode($value,true); if (json_last_error()!==JSON_ERROR_NONE) operations_respond(['ok'=>false,'error'=>'Operational data must be valid JSON.'],422);
    if (!in_array($sourceModule,['Exports','Mill','Milling','Accounts','Super Admin'],true)) operations_respond(['ok'=>false,'error'=>'Invalid source module.'],422);
    if (!operations_can_write($user,$sourceModule)) operations_respond(['ok'=>false,'error'=>'Create or Edit permission is required for this module.'],403);
    if(in_array($key,TT_INV_PRIVATE_KEYS,true))operations_respond(['ok'=>false,'error'=>'Reconciliation is maintained by the server; open the Accounts or Directors report.'],403);
    if($key===TT_INV_CONFIRMATIONS && !tt_inv_has_action($user,'Mill',['stock','export'],'Create') && !tt_inv_has_action($user,'Mill',['stock','export'],'Edit'))operations_respond(['ok'=>false,'error'=>'Stock confirmation permission required.'],403);
    tt_maybe_auto_backup();
    $handle=fopen($path,'c+'); if ($handle===false || !flock($handle,LOCK_EX)) throw new RuntimeException('Shared operational storage is unavailable.');
    $conflict=false;
    try {
        rewind($handle); $existing=stream_get_contents($handle); $store=$existing?json_decode($existing,true):null;
        if (!is_array($store)) $store=['revision'=>0,'values'=>[],'meta'=>[]];
        $old=(string)($store['values'][$key]??''); $keyVersion=(int)($store['meta'][$key]['version']??0);
        if ($baseVersion!==$keyVersion) $conflict=true;
        else {
            if ($key==='transtrade_export_v3_operational' && $old!=='') $value=operations_merge_export($old,$value,$sourceModule);
            $now=gmdate('c');$beforeValues=(array)($store['values']??[]);
            $value=tt_inv_prepare_write($beforeValues,$key,$value,$user,$now);
            $nextValues=$beforeValues;$nextValues[$key]=$value;
            if(in_array($key,TT_INV_SOURCE_KEYS,true))$nextValues=tt_inv_reconcile($nextValues,$now);
            foreach($nextValues as$changedKey=>$changedValue){
                if(($beforeValues[$changedKey]??'')===$changedValue)continue;
                $store['values'][$changedKey]=$changedValue;$store['revision']=(int)($store['revision']??0)+1;
                $ver=(int)($store['meta'][$changedKey]['version']??0)+1;
                $store['meta'][$changedKey]=['version'=>$ver,'updatedAt'=>$now,'updatedBy'=>(string)($user['full_name']??$user['username']??'Staff'),'userId'=>(int)($user['id']??0),'module'=>$changedKey===$key?$sourceModule:'System'];
            }
            $keyVersion=(int)($store['meta'][$key]['version']??0);
            if($nextValues!==$beforeValues){
                $encoded=json_encode($store,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                rewind($handle);if(!ftruncate($handle,0)||fwrite($handle,$encoded)!==strlen($encoded))throw new RuntimeException('Shared operational storage could not be written.');fflush($handle);
            }
        }
        $revision=(int)($store['revision']??0);
    } finally { flock($handle,LOCK_UN); fclose($handle); }
    if ($conflict) operations_respond(['ok'=>false,'conflict'=>true,'error'=>'A newer shared update is available. Refresh before saving again.','keyVersion'=>$keyVersion],409);
    operations_respond(['ok'=>true,'revision'=>$revision,'keyVersion'=>$keyVersion,'updatedAt'=>gmdate('c')]);
}

try {
    $user = tt_require_login();
    if (operations_env('DB_HOST') === '' || operations_env('DB_NAME') === '' || operations_env('DB_USER') === '') {
        operations_file_fallback($user);
    }
    $db = operations_db();
    operations_install($db);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $rows = $db->query('SELECT storage_key, payload, version, updated_at, updated_by, updated_by_user, updated_by_module FROM tt_operation_records')->fetchAll();
        $values = $meta = [];
        $revision = 0;
        foreach ($rows as $row) {
            $key = (string)$row['storage_key'];
            $values[$key] = (string)$row['payload'];
            $version = (int)$row['version'];
            $revision = max($revision, $version);
            $meta[$key] = [
                'version' => $version,
                'updatedAt' => (string)$row['updated_at'],
                'updatedBy' => (string)$row['updated_by'],
                'userId' => $row['updated_by_user'] === null ? null : (int)$row['updated_by_user'],
                'module' => (string)$row['updated_by_module'],
            ];
        }
        operations_respond(['ok'=>true,'revision'=>$revision,'serverNow'=>gmdate('c')]+tt_inv_public_values($values,$meta));
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') operations_respond(['ok' => false, 'error' => 'Method not allowed.'], 405);

    $raw = file_get_contents('php://input') ?: '';
    if (strlen($raw) > 16 * 1024 * 1024) operations_respond(['ok' => false, 'error' => 'Operational update is too large.'], 413);
    $body = json_decode($raw, true);
    if (!is_array($body) || !tt_verify_csrf((string)($body['csrf'] ?? ''))) operations_respond(['ok' => false, 'error' => 'Your session expired. Refresh and try again.'], 419);
    $key = (string)($body['key'] ?? '');
    $value = $body['value'] ?? null;
    $baseVersion = max(0, (int)($body['baseVersion'] ?? 0));
    $sourceModule = trim((string)($body['sourceModule'] ?? ''));
    if (!operations_key_allowed($key) || !is_string($value)) operations_respond(['ok' => false, 'error' => 'Invalid operational update.'], 422);
    json_decode($value, true);
    if (json_last_error() !== JSON_ERROR_NONE) operations_respond(['ok' => false, 'error' => 'Operational data must be valid JSON.'], 422);
    if (!in_array($sourceModule, ['Exports', 'Mill', 'Milling', 'Accounts', 'Super Admin'], true)) {
        operations_respond(['ok' => false, 'error' => 'Invalid source module.'], 422);
    }
    if (!operations_can_write($user, $sourceModule)) {
        operations_respond(['ok' => false, 'error' => 'Create or Edit permission is required for this module.'], 403);
    }

    if(in_array($key,TT_INV_PRIVATE_KEYS,true))operations_respond(['ok'=>false,'error'=>'Reconciliation is maintained by the server; open the Accounts or Directors report.'],403);
    if($key===TT_INV_CONFIRMATIONS && !tt_inv_has_action($user,'Mill',['stock','export'],'Create') && !tt_inv_has_action($user,'Mill',['stock','export'],'Edit'))operations_respond(['ok'=>false,'error'=>'Stock confirmation permission required.'],403);
    tt_maybe_auto_backup();
    $inventoryLock=false;
    if(in_array($key,TT_INV_SOURCE_KEYS,true)){
        $lock=$db->prepare('SELECT GET_LOCK(?,10)');$lock->execute(['tt_inventory_'.substr(hash('sha256',operations_env('DB_NAME')),0,24)]);
        if((int)$lock->fetchColumn()!==1)throw new RuntimeException('Inventory is being saved; retry.');$inventoryLock=true;
    }
    $db->beginTransaction();
    $select = $db->prepare('SELECT payload, version FROM tt_operation_records WHERE storage_key = ? FOR UPDATE');
    $select->execute([$key]);
    $existing = $select->fetch();
    $oldPayload = $existing ? (string)$existing['payload'] : '';
    $version = $existing ? (int)$existing['version'] : 0;
    if ($baseVersion !== $version) {
        $db->rollBack();
        operations_respond([
            'ok' => false,
            'conflict' => true,
            'error' => 'A newer shared update is available. Refresh before saving again.',
            'keyVersion' => $version,
        ], 409);
    }
    if ($key === 'transtrade_export_v3_operational' && $oldPayload !== '') {
        $value = operations_merge_export($oldPayload, $value, $sourceModule);
    }
    $nowIso=gmdate('c');$beforeValues=[];
    if(in_array($key,TT_INV_SOURCE_KEYS,true))foreach($db->query('SELECT storage_key,payload FROM tt_operation_records')->fetchAll()as$row)$beforeValues[(string)$row['storage_key']]=(string)$row['payload'];
    else $beforeValues[$key]=$oldPayload;
    $value=tt_inv_prepare_write($beforeValues,$key,$value,$user,$nowIso);$nextValues=$beforeValues;$nextValues[$key]=$value;
    if(in_array($key,TT_INV_SOURCE_KEYS,true))$nextValues=tt_inv_reconcile($nextValues,$nowIso);
    $now=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    $name=(string)($user['full_name']??$user['username']??'Staff');$userId=isset($user['id'])?(int)$user['id']:null;
    foreach($nextValues as$changedKey=>$changedValue){
        if(($beforeValues[$changedKey]??'')===$changedValue)continue;
        $select->execute([$changedKey]);$record=$select->fetch();$newVersion=(int)($record['version']??0)+1;
        if($changedKey===$key)$version=$newVersion;
        $writer=$changedKey===$key?$sourceModule:'System';
        $upsert=$db->prepare('INSERT INTO tt_operation_records (storage_key,payload,version,updated_at,updated_by,updated_by_user,updated_by_module) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload),version=VALUES(version),updated_at=VALUES(updated_at),updated_by=VALUES(updated_by),updated_by_user=VALUES(updated_by_user),updated_by_module=VALUES(updated_by_module)');
        $upsert->execute([$changedKey,$changedValue,$newVersion,$now,$name,$userId,$writer]);
        $history=$db->prepare('INSERT INTO tt_operation_history (storage_key,version,payload_sha256,updated_at,updated_by,updated_by_user,updated_by_module) VALUES (?,?,?,?,?,?,?)');
        $history->execute([$changedKey,$newVersion,hash('sha256',$changedValue),$now,$name,$userId,$writer]);
    }
    $db->commit();
    if($inventoryLock){$release=$db->prepare('SELECT RELEASE_LOCK(?)');$release->execute(['tt_inventory_'.substr(hash('sha256',operations_env('DB_NAME')),0,24)]);$inventoryLock=false;}
    operations_respond(['ok' => true, 'revision' => $version, 'keyVersion' => $version, 'updatedAt' => gmdate('c')]);
} catch (DomainException|InvalidArgumentException $e) {
    if(isset($db)&&$db instanceof PDO&&$db->inTransaction())$db->rollBack();
    operations_respond(['ok'=>false,'error'=>$e->getMessage()],$e instanceof DomainException?403:422);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) $db->rollBack();
    error_log('Transtrade operations API: ' . $e->getMessage());
    operations_respond(['ok' => false, 'error' => 'The shared operational update could not be completed.'], 500);
}
