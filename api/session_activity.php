<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if (!in_array($method,['GET','POST'],true)) tt_api_json_error(405,'GET or POST is required.');
// A resumed tab checks identity without extending the session's lifetime.
if ($method==='GET') unset($_SERVER['HTTP_X_TT_USER_ACTIVITY']);
$user=tt_current_user();
if (!$user) tt_api_json_error(401,'Your session has expired. Sign in again.');
if ($method==='GET') {
    $remaining=max(0,TT_SESSION_IDLE_TIMEOUT-(time()-(int)($_SESSION['last_activity_at']??time())));
    tt_release_read_session();
    echo json_encode(['ok'=>true,'idleTimeoutSeconds'=>TT_SESSION_IDLE_TIMEOUT,'idleRemainingSeconds'=>$remaining]);
    exit;
}
$raw=file_get_contents('php://input')?:'';
$body=$raw!==''?json_decode($raw,true):[];
$token=(string)($_SERVER['HTTP_X_CSRF_TOKEN']??(is_array($body)?($body['csrf']??''):''));
if (!tt_verify_csrf($token)) tt_api_json_error(403,'Your session token is invalid.');
tt_touch_session_activity();
echo json_encode(['ok'=>true,'idleTimeoutSeconds'=>TT_SESSION_IDLE_TIMEOUT]);
