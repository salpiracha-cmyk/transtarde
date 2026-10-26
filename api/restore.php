<?php
declare(strict_types=1);
define('TT_RESTORE_CONTROL',true);
require dirname(__DIR__).'/auth_store.php';
require dirname(__DIR__).'/backup_lib.php';
require dirname(__DIR__).'/restore_jobs.php';
function ttr_response(array $data,int $status=200): never {http_response_code($status);header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');echo json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);exit;}
$lock=null;$gate=null;
try{
    $lock=ttr_lock(ttr_root().'/job.lock');
    $actor=tt_require_login();if(($actor['role']??'')!=='Super Admin')ttr_response(['ok'=>false,'error'=>'Super Admin access required.'],403);
    $method=$_SERVER['REQUEST_METHOD'];
    if($method==='GET'){$s=ttr_read();if($s)ttr_owner($s,$actor,(string)$s['id']);ttr_response($s?ttr_public($s):['ok'=>true,'state'=>'empty']);}
    if($method!=='POST')ttr_response(['ok'=>false,'error'=>'Method not allowed.'],405);
    if(!tt_verify_csrf((string)($_SERVER['HTTP_X_CSRF_TOKEN']??'')))ttr_response(['ok'=>false,'error'=>'Sign in again and reopen Restore to continue.'],419);
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    if((int)($_SERVER['CONTENT_LENGTH']??0)>TT_RESTORE_CHUNK+65536)throw new InvalidArgumentException('Upload pieces must be 1 MB or smaller.');
    $raw=file_get_contents('php://input',false,null,0,TT_RESTORE_CHUNK+65537);if($raw===false||strlen($raw)>TT_RESTORE_CHUNK+65536)throw new InvalidArgumentException('Request too large.');
    $chunk=(string)($_GET['action']??'')==='chunk';$b=$chunk?[]:json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($b))throw new InvalidArgumentException('Invalid recovery request.');
    $action=$chunk?'chunk':(string)($b['action']??'');
    if($action==='start')ttr_response(ttr_public(ttr_start($b,$actor)));
    $s=ttr_read();ttr_owner($s,$actor,(string)($chunk?($_SERVER['HTTP_X_RESTORE_ID']??''):($b['id']??'')));
    if($action==='chunk')ttr_response(ttr_public(ttr_chunk($s,(int)($_SERVER['HTTP_X_RESTORE_OFFSET']??-1),$raw)));
    if($action==='verify')ttr_response(ttr_public(ttr_verify($s,(string)($b['password']??''))));
    if(!in_array($action,['confirm','step','clear'],true))throw new InvalidArgumentException('Unknown recovery action.');
    $gate=ttr_lock(TT_DATA_DIR.'/restore-request.lock');
    if($action==='confirm')$s=ttr_confirm($s,(string)($b['confirmation']??''));
    if($action==='step')$s=ttr_step($s);
    if($action==='clear'){
        if(!in_array($s['state'],['uploading','verifying','verified','failed','done','rolled_back'],true))throw new InvalidArgumentException('Finish the active recovery before clearing its upload.');
        ttr_unpause($s);
        foreach(['upload.zip','staged','state.json']as$n)ttr_delete_work(ttr_root().'/'.$n);
        ttr_response(['ok'=>true,'state'=>'empty']);
    }
    ttr_response(ttr_public($s));
}catch(InvalidArgumentException|JsonException $e){ttr_response(['ok'=>false,'error'=>$e instanceof JsonException?'A recovery JSON file is invalid.':$e->getMessage()],422);}
catch(Throwable $e){error_log('Transtrade recovery: '.get_class($e).':'.basename($e->getFile()).':'.$e->getLine());ttr_response(['ok'=>false,'error'=>'Recovery paused safely. Wait a moment and use Continue recovery. If this repeats, keep the ZIP and contact support.'],503);}
finally{foreach([$gate,$lock]as$h)if(is_resource($h)){flock($h,LOCK_UN);fclose($h);}}
