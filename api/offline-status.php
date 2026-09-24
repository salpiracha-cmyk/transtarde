<?php
declare(strict_types=1);

require dirname(__DIR__) . '/auth_store.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

try {
    $user=tt_require_login();
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){
        http_response_code(405);
        echo json_encode(['ok'=>false,'error'=>'Method not allowed.']);
        exit;
    }
    $ids=array_slice(explode(',',(string)($_GET['ids']??'')),0,100);
    echo json_encode(['ok'=>true,'transactions'=>tt_offline_transaction_status($user,$ids),'serverNow'=>gmdate('c')],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
} catch(Throwable $error) {
    error_log('Offline status: '.$error->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Saved-entry status could not be checked.']);
}
