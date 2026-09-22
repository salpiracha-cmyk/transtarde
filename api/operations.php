<?php
declare(strict_types=1);

require dirname(__DIR__) . '/auth_store.php';
require dirname(__DIR__) . '/backup_lib.php';
require_once dirname(__DIR__) . '/inventory_reconciliation.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

const TT_OPERATIONS_FILE = TT_DATA_DIR . '/operations.json';

function operations_respond(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function operations_key_allowed(string $key): bool {
    return $key === 'transtrade_export_v2_operational'
        || (bool)preg_match('/^tt[0-9]{2}[a-z0-9_]{2,60}$/', $key);
}

function operations_can_write(array $user): bool {
    if (($user['role'] ?? '') === 'Super Admin') return true;
    foreach ((array)($user['permissions'] ?? []) as $module) {
        if ($module === 'all') return true;
        foreach ((array)$module as $actions) {
            if (is_array($actions) && (in_array('Create', $actions, true) || in_array('Edit', $actions, true))) return true;
        }
    }
    return false;
}

function operations_read(): array {
    tt_ensure_data_dir();
    if (!is_file(TT_OPERATIONS_FILE)) return ['revision' => 0, 'values' => [], 'meta' => []];
    $handle = fopen(TT_OPERATIONS_FILE, 'r');
    if ($handle === false || !flock($handle, LOCK_SH)) throw new RuntimeException('Shared operational storage is unavailable.');
    try {
        $raw = stream_get_contents($handle);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
    $decoded = $raw ? json_decode($raw, true) : null;
    return is_array($decoded) ? array_merge(['revision' => 0, 'values' => [], 'meta' => []], $decoded) : ['revision' => 0, 'values' => [], 'meta' => []];
}

try {
    $user = tt_require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $store = operations_read();
        operations_respond([
            'ok' => true,
            'revision' => (int)$store['revision'],
            ...tt_inv_public_values((array)$store['values'],(array)$store['meta']),
            'serverNow' => gmdate('c'),
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') operations_respond(['ok' => false, 'error' => 'Method not allowed.'], 405);
    if (!operations_can_write($user)) operations_respond(['ok' => false, 'error' => 'Create or Edit permission is required.'], 403);

    $raw = file_get_contents('php://input') ?: '';
    if (strlen($raw) > 16 * 1024 * 1024) operations_respond(['ok' => false, 'error' => 'Operational update is too large.'], 413);
    $body = json_decode($raw, true);
    if (!is_array($body) || !tt_verify_csrf((string)($body['csrf'] ?? ''))) operations_respond(['ok' => false, 'error' => 'Your session expired. Refresh and try again.'], 419);
    tt_maybe_auto_backup();
    $key = (string)($body['key'] ?? '');
    $value = $body['value'] ?? null;
    if (!operations_key_allowed($key) || !is_string($value)) operations_respond(['ok' => false, 'error' => 'Invalid operational update.'], 422);
    if(in_array($key,TT_INV_PRIVATE_KEYS,true)||in_array($key,TT_INV_SOURCE_KEYS,true))operations_respond(['ok'=>false,'error'=>'Use the current operational save endpoint for this record.'],409);
    json_decode($value, true);
    if (json_last_error() !== JSON_ERROR_NONE) operations_respond(['ok' => false, 'error' => 'Operational data must be valid JSON.'], 422);

    tt_ensure_data_dir();
    $handle = fopen(TT_OPERATIONS_FILE, 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) throw new RuntimeException('Shared operational storage is unavailable.');
    try {
        rewind($handle);
        $existing = stream_get_contents($handle);
        $store = $existing ? json_decode($existing, true) : null;
        if (!is_array($store)) $store = ['revision' => 0, 'values' => [], 'meta' => []];
        $old = (string)($store['values'][$key] ?? '');
        if (!hash_equals(hash('sha256', $old), hash('sha256', $value))) {
            $store['values'][$key] = $value;
            $store['revision'] = (int)($store['revision'] ?? 0) + 1;
            $store['meta'][$key] = [
                'updatedAt' => gmdate('c'),
                'updatedBy' => (string)($user['full_name'] ?? $user['username'] ?? 'Staff'),
                'userId' => (int)($user['id'] ?? 0),
            ];
            rewind($handle);
            if (!ftruncate($handle, 0)) throw new RuntimeException('Shared operational storage could not be updated.');
            $encoded = json_encode($store, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (fwrite($handle, $encoded) === false) throw new RuntimeException('Shared operational storage could not be written.');
            fflush($handle);
        }
        $revision = (int)($store['revision'] ?? 0);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    operations_respond(['ok' => true, 'revision' => $revision, 'updatedAt' => gmdate('c')]);
} catch (Throwable $e) {
    operations_respond(['ok' => false, 'error' => 'The shared operational update could not be completed.'], 500);
}
