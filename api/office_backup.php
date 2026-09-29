<?php
declare(strict_types=1);
require dirname(__DIR__) . '/auth_store.php';
require dirname(__DIR__) . '/backup_lib.php';
require dirname(__DIR__) . '/office_backup_auth.php';

header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
function office_backup_error(int $status, string $message): never {
    http_response_code($status); header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok'=>false,'error'=>$message]); exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') office_backup_error(405, 'Method not allowed.');
if (!tt_request_is_https()) office_backup_error(403, 'HTTPS is required.');
$ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');
$retry=tt_auth_retry_after('office-backup',$ip,5,900,false);
if ($retry > 0) { header('Retry-After: '.$retry); office_backup_error(429, 'Backup credential temporarily locked.'); }
$token=(string)($_SERVER['HTTP_X_TT_BACKUP_TOKEN']??'');
if (!tt_office_backup_token_valid($token)) {
    tt_auth_record_failure('office-backup',$ip,5,900,900,false);
    office_backup_error(403, 'Invalid backup credential.');
}
tt_auth_clear_failures('office-backup',$ip,false);
$length=(int)($_SERVER['CONTENT_LENGTH']??0);
if ($length < 12 || $length > 1024) office_backup_error(400, 'Invalid backup request.');
$body=json_decode(file_get_contents('php://input') ?: '',true);
$password=is_array($body) ? (string)($body['password']??'') : '';
if (strlen($password)<12 || strlen($password)>256) office_backup_error(400, 'A recovery password of 12–256 characters is required.');

$path='';$failure=null;
try {
    $built=tt_build_download_backup(true,$password);
    $path=$built['path'];
    $digest=hash_file('sha256',$path);
    if ($digest===false) throw new RuntimeException('Could not verify office backup transfer.');
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="TRANSTRADE_OFFICE_BACKUP_'.gmdate('Y-m-d_His').'.zip"');
    header('Content-Length: '.filesize($path));
    header('X-Backup-SHA256: '.$digest);
    $handle=fopen($path,'rb');
    if ($handle===false) throw new RuntimeException('Could not stream office backup.');
    try { while (!feof($handle)) { $chunk=fread($handle,1024*1024); if ($chunk===false) throw new RuntimeException('Office backup transfer was interrupted.'); echo $chunk; flush(); } }
    finally { fclose($handle); }
} catch (Throwable $error) {
    error_log('Transtrade office backup failed: '.$error->getMessage());
    $failure=$error;
} finally { if ($path!=='' && is_file($path)) @unlink($path); }
if ($failure!==null && !headers_sent()) office_backup_error(500,'The encrypted backup could not be prepared. Check server backup status.');
