<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/document_filenames.php';
require dirname(__DIR__).'/auth_store.php';
require_once dirname(__DIR__).'/upload_validation.php';
require_once __DIR__.'/export_permission_policy.php';
require_once __DIR__.'/shipment_archive_core.php';
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
function sa_error(int $status, string $message): never {
    http_response_code($status); header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok'=>false,'error'=>$message]); exit;
}
try {
    $user = tt_require_login();
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') sa_error(405, 'Method not allowed.');
    if (!tt_user_can_open_module($user,'Exports') && !tt_user_can_open_module($user,'Accounts'))
        sa_error(403,'Exports or Accounts access is required.');
    $root = TT_DATA_DIR.'/office_agent_jobs';
    if (is_link($root)) throw new RuntimeException('Invalid archive storage.');
    $job = sa_job($root, (string)($_GET['id'] ?? ''));
    ep_require_document_target($user,(string)($job['contract']??''),(string)($job['lot']??''),'shipment-archive','View');
    $file = sa_pack($job);
    if (!$file) sa_error(404,'Shipment document pack not found.');
    $dir = $root.'/'.$job['id'].'/files'; $path = $dir.'/'.$file['storedName'];
    if (is_link($dir) || is_link($path) || !is_file($path)) sa_error(404,'Stored shipment pack is unavailable.');
    $handle = fopen($path,'rb');
    if (!$handle || !flock($handle,LOCK_SH)) throw new RuntimeException('Archive file unavailable.');
    try {
        $hash = hash_init('sha256'); hash_update_stream($hash,$handle); $digest = hash_final($hash);
        if (!hash_equals($file['sha256'],$digest) || fstat($handle)['size'] !== (int)$file['size'])
            sa_error(409,'Stored shipment pack failed verification. Review its original archive.');
        rewind($handle);
        header('Content-Type: application/pdf'); header('Content-Length: '.(int)$file['size']);
        tt_document_disposition(tt_export_document_name(ep_operations_root(),(string)($job['contract']??''),(string)($job['lot']??''),'Master Shipment Documents','pdf'),true);
        tt_uploaded_document_headers(); fpassthru($handle);
    } finally {flock($handle,LOCK_UN); fclose($handle);}
} catch (InvalidArgumentException $e) {sa_error(422,$e->getMessage());
} catch (OutOfBoundsException $e) {sa_error(404,$e->getMessage());
} catch (DomainException $e) {sa_error(403,$e->getMessage());
} catch (Throwable $e) {error_log('Shipment archive read: '.$e->getMessage()); sa_error(500,'Shipment archive is temporarily unavailable.');}
