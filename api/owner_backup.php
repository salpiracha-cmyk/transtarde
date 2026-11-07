<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';require dirname(__DIR__).'/backup_lib.php';
require dirname(__DIR__).'/office_backup_auth.php';require dirname(__DIR__).'/owner_backup_jobs.php';
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
function owner_backup_json(array $data,int $code=200):void{http_response_code($code);header('Content-Type: application/json; charset=UTF-8');echo json_encode($data,JSON_THROW_ON_ERROR);}
$lock=null;
try{
    $admin=tt_require_login();if(($admin['role']??'')!=='Super Admin'){owner_backup_json(['ok'=>false,'error'=>'Super Admin access required.'],403);exit;}
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){owner_backup_json(['ok'=>false,'error'=>'Method not allowed.'],405);exit;}
    if((int)($_SERVER['CONTENT_LENGTH']??0)>8192)throw new InvalidArgumentException('Invalid backup request.');
    $body=str_contains(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'multipart/form-data')?$_POST:json_decode((string)file_get_contents('php://input'),true);
    if(!is_array($body))$body=[];$csrf=(string)($body['csrf']??'');
    if(!tt_verify_csrf($csrf)){owner_backup_json(['ok'=>false,'error'=>'Refresh the page and try again.'],419);exit;}
    $kind=(string)($body['kind']??'');if(!in_array($kind,['business','full'],true))throw new InvalidArgumentException('Invalid backup type.');
    $password=tt_backup_password_get();if($password===null)throw new InvalidArgumentException('Set your backup protection password first.');
    $client=hash_hmac('sha256',(string)$admin['id'].'|'.$csrf,$password);
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    $action=(string)($body['action']??'');
    if($action==='prepare'){
        if(!function_exists('litespeed_finish_request')&&!function_exists('fastcgi_finish_request'))throw new RuntimeException('Background preparation unavailable.');
        $lock=tt_owner_job_lock($kind,LOCK_EX);$m=tt_owner_job_read($kind);
        if(!$lock){if(isset($m['id'])&&tt_owner_job_matches($m,$m['id'],$client)){owner_backup_json(tt_owner_job_public($m),202);exit;}owner_backup_json(['ok'=>false,'error'=>'Another backup is being prepared. Retry shortly.'],409);exit;}
        // Each explicit download request builds a current copy; never silently reuse stale business data.
        $m=['id'=>bin2hex(random_bytes(16)),'client'=>$client,'state'=>'preparing','stage'=>'building','createdAt'=>time()];tt_owner_job_write($kind,$m);
        ignore_user_abort(true);@set_time_limit(900);ini_set('zlib.output_compression','0');while(ob_get_level()>0)ob_end_clean();
        owner_backup_json(tt_owner_job_public($m),202);
        if(function_exists('litespeed_finish_request'))litespeed_finish_request();else fastcgi_finish_request();
        tt_owner_job_generate($kind,$m,$password);exit;
    }
    $m=tt_owner_job_read($kind);$id=(string)($body['job']??'');
    if(!tt_owner_job_matches($m,$id,$client)){owner_backup_json(['ok'=>false,'error'=>'Backup preparation changed. Start a fresh download.'],409);exit;}
    if($action==='status'){owner_backup_json(tt_owner_job_public($m));exit;}
    if($action!=='file')throw new InvalidArgumentException('Unknown backup action.');
    $lock=tt_owner_job_lock($kind,LOCK_SH);if(!$lock)throw new InvalidArgumentException('Backup is still being prepared.');
    $m=tt_owner_job_read($kind);$path=tt_owner_job_dir($kind).'/transfer.zip';
    if(!tt_owner_job_matches($m,$id,$client)||($m['state']??'')!=='ready'||tt_owner_job_identity($path)!==$m['identity'])throw new InvalidArgumentException('Prepared backup changed. Request a fresh download.');
    tt_backup_write_state(['last_owner_download_at'=>gmdate('c'),'last_owner_download_type'=>$kind]);
    tt_audit((int)$admin['id'],$admin['username'],'Requested verified encrypted '.$kind.' backup download');
    tt_backup_stream_file($path,$m['filename'],$m['sha256']);
}catch(InvalidArgumentException $e){if(!headers_sent())owner_backup_json(['ok'=>false,'error'=>$e->getMessage()],422);}
catch(Throwable $e){if(!headers_sent())owner_backup_json(['ok'=>false,'error'=>'Backup preparation could not be started safely. Previous backups were preserved.'],500);}
finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
