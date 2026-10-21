<?php
declare(strict_types=1);
require dirname(__DIR__) . '/auth_store.php';
require dirname(__DIR__) . '/backup_lib.php';
require dirname(__DIR__) . '/office_backup_auth.php';
require dirname(__DIR__) . '/office_backup_jobs.php';

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
$length=(int)($_SERVER['CONTENT_LENGTH']??0);
if ($length < 12 || $length > 1024) office_backup_error(400, 'Invalid backup request.');
$body=json_decode(file_get_contents('php://input') ?: '',true);
$password=is_array($body) ? (string)($body['password']??'') : '';
if (strlen($password)<12 || strlen($password)>256) office_backup_error(400, 'A recovery password of 12–256 characters is required.');
try{$passwordAllowed=tt_office_password_allowed($password);}catch(Throwable $e){office_backup_error(503,'Office security policy requires Super Admin review.');}
if(!$passwordAllowed){tt_auth_record_failure('office-backup',$ip,5,900,900,false);office_backup_error(403,'Invalid Office PC recovery password.');}
tt_auth_clear_failures('office-backup',$ip,false);

if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
$action=(string)($_GET['action']??'');
if(!in_array($action,['prepare','status','chunk','complete'],true))office_backup_error(426,'Install the updated Office Agent for background backup preparation.');
$client=hash_hmac('sha256',$password,$token);
function office_backup_json(array $value,int $status=200):void {
    http_response_code($status);header('Content-Type: application/json; charset=UTF-8');echo json_encode($value,JSON_THROW_ON_ERROR);
}
$jobLock=null;
try {
    if($action==='prepare'){
        if(!function_exists('litespeed_finish_request')&&!function_exists('fastcgi_finish_request'))office_backup_error(503,'Background preparation is unavailable.');
        $jobLock=tt_office_job_lock(LOCK_EX);$m=tt_office_job_read();
        if(!$jobLock){
            if(hash_equals((string)($m['client']??''),$client)&&isset($m['id'])){office_backup_json(tt_office_job_public($m),202);exit;}
            office_backup_error(409,'Another office backup is being prepared. Retry shortly.');
        }
        if(($m['state']??'')==='ready'&&hash_equals((string)($m['client']??''),$client)&&time()-(int)$m['readyAt']<900&&tt_office_job_identity(tt_office_job_dir().'/transfer.zip')===$m['identity']){
            office_backup_json(tt_office_job_public($m));
        }else{
            $m=['id'=>bin2hex(random_bytes(16)),'client'=>$client,'state'=>'preparing','stage'=>'building','createdAt'=>time()];tt_office_job_write($m);
            // Reply before any large archive work. The observed production SAPI
            // is LiteSpeed and provides litespeed_finish_request().
            ignore_user_abort(true);@set_time_limit(900);ini_set('zlib.output_compression','0');while(ob_get_level()>0)ob_end_clean();
            office_backup_json(tt_office_job_public($m),202);
            if(function_exists('litespeed_finish_request'))litespeed_finish_request();else fastcgi_finish_request();
            tt_office_job_generate($m,$password);
        }
    }else{
        $m=tt_office_job_read();$id=(string)($body['job']??'');
        if(!tt_office_job_matches($m,$id,$client))office_backup_error(409,'Backup job changed. Start a fresh preparation request.');
        if($action==='status'){office_backup_json(tt_office_job_public($m));}
        else{
            $jobLock=tt_office_job_lock($action==='chunk'?LOCK_SH:LOCK_EX);if(!$jobLock)office_backup_error(409,'Backup transfer is busy. Retry shortly.');
            $m=tt_office_job_read();if(!tt_office_job_matches($m,$id,$client))office_backup_error(409,'Backup job changed.');
            if($action==='complete'){
                if($m['state']!=='ready'||!hash_equals((string)$m['sha256'],(string)($body['sha256']??'')))office_backup_error(409,'Backup acknowledgement mismatch.');
                $p=tt_office_job_dir().'/transfer.zip';if(tt_office_job_identity($p)!==$m['identity'])throw new RuntimeException('Transfer changed');
                if(!unlink($p))throw new RuntimeException('Cannot remove acknowledged transfer');$m['state']='acknowledged';tt_office_job_write($m);office_backup_json(['ok'=>true]);
            }else{
                $offset=$body['offset']??null;$length=$body['length']??null;if(!is_int($offset)||!is_int($length))office_backup_error(400,'Invalid backup chunk request.');
                $data=tt_office_job_chunk($m,$offset,$length);ini_set('zlib.output_compression','0');while(ob_get_level()>0)ob_end_clean();
                header('Content-Type: application/octet-stream');header('Content-Encoding: identity');header('Cache-Control: no-store, no-transform');header('Content-Length: '.strlen($data));header('X-Backup-Chunk-SHA256: '.hash('sha256',$data));echo $data;
            }
        }
    }
}catch(Throwable $error){error_log('Transtrade office transfer failed: '.get_class($error));if(!headers_sent())office_backup_error(500,'Office backup preparation or transfer failed. Existing completed backups were preserved.');}
finally{if(is_resource($jobLock)){flock($jobLock,LOCK_UN);fclose($jobLock);}}
