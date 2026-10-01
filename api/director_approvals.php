<?php
declare(strict_types=1);
require dirname(__DIR__) . '/auth_store.php';
require __DIR__ . '/director_approvals_core.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
try {
    $user = tt_require_login();
    if (($user['role'] ?? '') !== 'Super Admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Super Admin access required.']);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
        exit;
    }
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
    echo json_encode(['ok' => true, 'approvals' => tt_pending_director_rate_approvals($store)], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Director approvals could not be loaded. Refresh to try again.']);
}
