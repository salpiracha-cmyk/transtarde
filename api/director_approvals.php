<?php
declare(strict_types=1);
require dirname(__DIR__) . '/auth_store.php';
require __DIR__ . '/director_approvals_core.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
try {
    $user = tt_require_login();
    if (!tt_user_can_director_approve($user)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Director or Super Admin approval access required.']);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
        exit;
    }
    tt_release_read_session();
    tt_ensure_data_dir();
    $file = TT_DATA_DIR . '/accounts.json';
    $store = [];
    if (is_file($file)) {
        $handle = fopen($file, 'r');
        if ($handle === false) throw new RuntimeException('Accounts storage unavailable.');
        try {
            if (!flock($handle, LOCK_SH)) throw new RuntimeException('Accounts storage unavailable.');
            $raw = stream_get_contents($handle);
            if ($raw === false) throw new RuntimeException('Accounts storage unavailable.');
            $store = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($store)) throw new RuntimeException('Accounts storage unavailable.');
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
    $items=array_merge(tt_pending_director_rate_approvals($store),tt_pending_jv_approvals($store));$items=array_values(array_filter($items,static fn($item)=>($user['role']??'')==='Super Admin'||tt_api_record_entity_allowed($user,['entity'=>$item['entity']],$item['entity'])));echo json_encode(['ok'=>true,'approvals'=>$items],JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Director approvals could not be loaded. Refresh to try again.']);
}
