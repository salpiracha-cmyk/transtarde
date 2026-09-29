<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
if (strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST') tt_api_json_error(405,'POST is required.');
$user=tt_current_user();
if (!$user) tt_api_json_error(401,'Your session has expired. Sign in again.');
$raw=file_get_contents('php://input')?:'';
$body=$raw!==''?json_decode($raw,true):[];
$token=(string)($_SERVER['HTTP_X_CSRF_TOKEN']??(is_array($body)?($body['csrf']??''):''));
if (!tt_verify_csrf($token)) tt_api_json_error(403,'Your session token is invalid.');
tt_touch_session_activity();
echo json_encode(['ok'=>true,'idleTimeoutSeconds'=>TT_SESSION_IDLE_TIMEOUT]);
