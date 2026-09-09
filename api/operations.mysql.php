<?php
declare(strict_types=1);

require dirname(__DIR__) . '/auth_store.php';
require dirname(__DIR__) . '/backup_lib.php';

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
    $actions = (array)$granted;
    return in_array('Create', $actions, true) || in_array('Edit', $actions, true);
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

function operations_merge_export(string $currentJson, string $incomingJson, string $sourceModule): string {
    $current = json_decode($currentJson, true);
    $incoming = json_decode($incomingJson, true);
    if (!is_array($current)) return $incomingJson;
    if (!is_array($incoming)) return $currentJson;

    if (strcasecmp($sourceModule, 'Accounts') === 0) {
        $merged = $current;
        $merged['accountsReceipts'] = operations_union_rows(
            (array)($current['accountsReceipts'] ?? []),
            (array)($incoming['accountsReceipts'] ?? []),
            ['id', 'receiptNo', 'reference']
        );
        $merged['alerts'] = operations_union_rows((array)($current['alerts'] ?? []), (array)($incoming['alerts'] ?? []), ['id', 'at']);
        $merged['audits'] = operations_union_rows((array)($current['audits'] ?? []), (array)($incoming['audits'] ?? []), ['id', 'at']);
        return json_encode($merged, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
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
        return json_encode($merged, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    $merged = $incoming;
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
    $merged['alerts'] = operations_union_rows((array)($current['alerts'] ?? []), (array)($incoming['alerts'] ?? []), ['id', 'at']);
    $merged['audits'] = operations_union_rows((array)($current['audits'] ?? []), (array)($incoming['audits'] ?? []), ['id', 'at']);
    return json_encode($merged, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
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
        if (!is_file($path)) operations_respond(['ok'=>true,'revision'=>0,'values'=>[],'meta'=>[],'serverNow'=>gmdate('c')]);
        $handle = fopen($path, 'r');
        if ($handle === false || !flock($handle, LOCK_SH)) throw new RuntimeException('Shared operational storage is unavailable.');
        try { $raw = stream_get_contents($handle); }
        finally { flock($handle, LOCK_UN); fclose($handle); }
        $store = $raw ? json_decode($raw, true) : null;
        if (!is_array($store)) $store = ['revision'=>0,'values'=>[],'meta'=>[]];
        operations_respond(['ok'=>true,'revision'=>(int)($store['revision']??0),'values'=>(array)($store['values']??[]),'meta'=>(array)($store['meta']??[]),'serverNow'=>gmdate('c')]);
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
            if (!hash_equals(hash('sha256',$old),hash('sha256',$value))) {
                $store['values'][$key]=$value; $store['revision']=(int)($store['revision']??0)+1; $keyVersion++;
                $store['meta'][$key]=['version'=>$keyVersion,'updatedAt'=>gmdate('c'),'updatedBy'=>(string)($user['full_name']??$user['username']??'Staff'),'userId'=>(int)($user['id']??0),'module'=>$sourceModule];
                rewind($handle); if (!ftruncate($handle,0)) throw new RuntimeException('Shared operational storage could not be updated.');
                $encoded=json_encode($store,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                if (fwrite($handle,$encoded)===false) throw new RuntimeException('Shared operational storage could not be written.'); fflush($handle);
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
        operations_respond(['ok' => true, 'revision' => $revision, 'values' => $values, 'meta' => $meta, 'serverNow' => gmdate('c')]);
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

    tt_maybe_auto_backup();
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
    $changed = $oldPayload === '' || !hash_equals(hash('sha256', $oldPayload), hash('sha256', $value));
    if ($changed) {
        $version++;
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $name = (string)($user['full_name'] ?? $user['username'] ?? 'Staff');
        $userId = isset($user['id']) ? (int)$user['id'] : null;
        $upsert = $db->prepare('INSERT INTO tt_operation_records (storage_key,payload,version,updated_at,updated_by,updated_by_user,updated_by_module) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload),version=VALUES(version),updated_at=VALUES(updated_at),updated_by=VALUES(updated_by),updated_by_user=VALUES(updated_by_user),updated_by_module=VALUES(updated_by_module)');
        $upsert->execute([$key, $value, $version, $now, $name, $userId, $sourceModule]);
        $history = $db->prepare('INSERT INTO tt_operation_history (storage_key,version,payload_sha256,updated_at,updated_by,updated_by_user,updated_by_module) VALUES (?,?,?,?,?,?,?)');
        $history->execute([$key, $version, hash('sha256', $value), $now, $name, $userId, $sourceModule]);
    }
    $db->commit();
    operations_respond(['ok' => true, 'revision' => $version, 'keyVersion' => $version, 'updatedAt' => gmdate('c')]);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) $db->rollBack();
    error_log('Transtrade operations API: ' . $e->getMessage());
    operations_respond(['ok' => false, 'error' => 'The shared operational update could not be completed.'], 500);
}
