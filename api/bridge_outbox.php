<?php
declare(strict_types=1);

require dirname(__DIR__).'/auth_store.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

const BO_FILE = TT_DATA_DIR.'/accounts.json';

function bo_out(array $value, int $status = 200): never {
    http_response_code($status);
    echo json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function bo_can_write(array $user, string $module): bool {
    if (($user['role'] ?? '') === 'Super Admin') {
        return true;
    }
    $permission = $user['permissions'][$module] ?? null;
    if ($permission === 'all') {
        return true;
    }
    if (!is_array($permission)) {
        return false;
    }
    if (in_array('Create', $permission, true) || in_array('Edit', $permission, true)) {
        return true;
    }
    foreach ($permission as $actions) {
        if (is_array($actions) && (in_array('Create', $actions, true) || in_array('Edit', $actions, true))) {
            return true;
        }
    }
    return false;
}

function bo_kind(string $kind): string {
    if (!in_array($kind, ['accountEvent', 'exportCandidate', 'localSaleCandidate', 'localPaymentCandidate'], true)) {
        bo_out(['ok' => false, 'error' => 'Unsupported bridge handoff type.'], 422);
    }
    return $kind;
}

function bo_module(string $kind): string {
    return $kind === 'exportCandidate' ? 'Exports' : 'Mill';
}

function bo_entity(string $kind, array $body): string {
    $field = $kind === 'exportCandidate' ? 'sellerEntity' : 'entity';
    $entity = strtoupper(trim((string)($body[$field] ?? '')));
    if (!in_array($entity, ['TTI', 'BRM', 'TG'], true)) {
        bo_out(['ok' => false, 'error' => 'A valid legal entity is required for this handoff.'], 422);
    }
    if (($kind === 'localSaleCandidate' || $kind === 'localPaymentCandidate') && $entity === 'TG') {
        bo_out(['ok' => false, 'error' => 'Local Milling handoffs cannot post to TG.'], 422);
    }
    return $entity;
}

function bo_authorize(array $user, string $module, string $entity, bool $write): void {
    if (!bo_can_write($user, $module)) {
        bo_out(['ok' => false, 'error' => $module.' Create or Edit permission required.'], 403);
    }
    $allowed = $write
        ? (tt_user_can_access_entity($user, $entity, 'Create') || tt_user_can_access_entity($user, $entity, 'Edit'))
        : tt_user_can_access_entity($user, $entity, 'View');
    if (!$allowed) {
        bo_out(['ok' => false, 'error' => 'You do not have permission for this legal entity.'], 403);
    }
}

function bo_read_locked(int $lock, callable $callback): mixed {
    tt_ensure_data_dir();
    $handle = fopen(BO_FILE, $lock === LOCK_EX ? 'c+' : 'r');
    if ($handle === false || !flock($handle, $lock)) {
        throw new RuntimeException('Accounts bridge storage unavailable.');
    }
    try {
        rewind($handle);
        $raw = stream_get_contents($handle);
        $store = $raw ? json_decode($raw, true) : null;
        if (!is_array($store)) {
            $store = ['revision' => 0];
        }
        if (!isset($store['bridgeOutbox']) || !is_array($store['bridgeOutbox'])) {
            $store['bridgeOutbox'] = [];
        }
        $result = $callback($store);
        if ($lock === LOCK_EX) {
            $store['revision'] = (int)($store['revision'] ?? 0) + 1;
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($store, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            fflush($handle);
        }
        return ['result' => $result, 'revision' => (int)($store['revision'] ?? 0)];
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

try {
    $user = tt_require_login();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $read = bo_read_locked(LOCK_SH, function(array $store) use ($user): array {
            $items = [];
            foreach ((array)$store['bridgeOutbox'] as $item) {
                if (!is_array($item) || ($item['status'] ?? 'Pending') === 'Posted') {
                    continue;
                }
                $module = (string)($item['module'] ?? '');
                $entity = (string)($item['entity'] ?? '');
                if (!bo_can_write($user, $module) || !tt_user_can_access_entity($user, $entity, 'View')) {
                    continue;
                }
                $items[] = $item;
            }
            usort($items, static fn(array $a, array $b): int => strcmp((string)($a['createdAt'] ?? ''), (string)($b['createdAt'] ?? '')));
            return $items;
        });
        bo_out(['ok' => true, 'items' => $read['result'], 'revision' => $read['revision']]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        bo_out(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }

    $raw = file_get_contents('php://input') ?: '';
    if (strlen($raw) > 256 * 1024) {
        bo_out(['ok' => false, 'error' => 'Bridge handoff is too large.'], 413);
    }
    $input = json_decode($raw, true);
    if (!is_array($input) || !tt_verify_csrf((string)($input['csrf'] ?? ''))) {
        bo_out(['ok' => false, 'error' => 'Your session expired. Refresh and try again.'], 419);
    }

    $action = (string)($input['action'] ?? '');
    $key = trim((string)($input['key'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9|._:-]{8,240}$/', $key)) {
        bo_out(['ok' => false, 'error' => 'A stable bridge key is required.'], 422);
    }

    if ($action === 'enqueue') {
        $kind = bo_kind((string)($input['kind'] ?? ''));
        $body = is_array($input['body'] ?? null) ? $input['body'] : [];
        $module = bo_module($kind);
        $entity = bo_entity($kind, $body);
        bo_authorize($user, $module, $entity, true);
        $fingerprint = hash('sha256', json_encode([$kind, $body], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $written = bo_read_locked(LOCK_EX, function(array &$store) use ($user, $key, $kind, $body, $module, $entity, $fingerprint): array {
            $old = $store['bridgeOutbox'][$key] ?? null;
            if (is_array($old)) {
                if (!hash_equals((string)($old['fingerprint'] ?? ''), $fingerprint)) {
                    bo_out(['ok' => false, 'error' => 'This bridge key already belongs to different source data.'], 409);
                }
                return $old;
            }
            $item = [
                'key' => $key,
                'kind' => $kind,
                'body' => $body,
                'module' => $module,
                'entity' => $entity,
                'status' => 'Pending',
                'tries' => 0,
                'lastError' => '',
                'fingerprint' => $fingerprint,
                'createdAt' => gmdate('c'),
                'createdBy' => (string)($user['full_name'] ?? $user['username'] ?? 'Staff'),
                'userId' => (int)($user['id'] ?? 0),
            ];
            $store['bridgeOutbox'][$key] = $item;
            return $item;
        });
        bo_out(['ok' => true, 'item' => $written['result'], 'revision' => $written['revision']]);
    }

    if ($action === 'mark_sent') {
        $result = is_array($input['result'] ?? null) ? $input['result'] : [];
        $written = bo_read_locked(LOCK_EX, function(array &$store) use ($user, $key, $result): array {
            $item = $store['bridgeOutbox'][$key] ?? null;
            if (!is_array($item)) {
                bo_out(['ok' => false, 'error' => 'Bridge handoff not found.'], 404);
            }
            bo_authorize($user, (string)$item['module'], (string)$item['entity'], true);
            if (($item['status'] ?? '') === 'Posted') {
                return $item;
            }
            $store['bridgeOutbox'][$key]['status'] = 'Posted';
            $store['bridgeOutbox'][$key]['postedAt'] = gmdate('c');
            $store['bridgeOutbox'][$key]['postedBy'] = (string)($user['full_name'] ?? $user['username'] ?? 'Staff');
            $store['bridgeOutbox'][$key]['result'] = $result;
            return $store['bridgeOutbox'][$key];
        });
        bo_out(['ok' => true, 'item' => $written['result'], 'revision' => $written['revision']]);
    }

    if ($action === 'record_failure') {
        $message = substr(trim((string)($input['error'] ?? 'Automatic Accounts handoff failed.')), 0, 500);
        $written = bo_read_locked(LOCK_EX, function(array &$store) use ($user, $key, $message): array {
            $item = $store['bridgeOutbox'][$key] ?? null;
            if (!is_array($item)) {
                bo_out(['ok' => false, 'error' => 'Bridge handoff not found.'], 404);
            }
            bo_authorize($user, (string)$item['module'], (string)$item['entity'], true);
            $store['bridgeOutbox'][$key]['tries'] = (int)($item['tries'] ?? 0) + 1;
            $store['bridgeOutbox'][$key]['lastError'] = $message;
            $store['bridgeOutbox'][$key]['lastTriedAt'] = gmdate('c');
            return $store['bridgeOutbox'][$key];
        });
        bo_out(['ok' => true, 'item' => $written['result'], 'revision' => $written['revision']]);
    }

    bo_out(['ok' => false, 'error' => 'Unknown bridge outbox action.'], 422);
} catch (Throwable $error) {
    error_log('Accounts bridge outbox failed: '.$error->getMessage());
    bo_out(['ok' => false, 'error' => 'Accounts bridge handoff could not be updated.'], 500);
}
