<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
require dirname(__DIR__).'/office_backup_auth.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
function pc_setup_json(array $data,int $code):never{http_response_code($code);echo json_encode($data,JSON_THROW_ON_ERROR);exit;}
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')pc_setup_json(['ok'=>false,'error'=>'Method not allowed.'],405);
if(!tt_request_is_https())pc_setup_json(['ok'=>false,'error'=>'HTTPS required.'],403);
$length=(int)($_SERVER['CONTENT_LENGTH']??0);
if($length<12||$length>256)pc_setup_json(['ok'=>false,'error'=>'Invalid setup request.'],400);
$ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');
if(tt_auth_retry_after('office-setup',$ip,5,900,false)>0)pc_setup_json(['ok'=>false,'error'=>'Please retry later.'],429);
$body=json_decode((string)file_get_contents('php://input'),true);
try{
    $token=tt_office_setup_redeem(is_array($body)?(string)($body['ticket']??''):'');
    tt_auth_clear_failures('office-setup',$ip,false);
    pc_setup_json(['ok'=>true,'token'=>$token],200);
}catch(InvalidArgumentException $e){
    tt_auth_record_failure('office-setup',$ip,5,900,900,false);
    pc_setup_json(['ok'=>false,'error'=>$e->getMessage()],403);
}catch(Throwable $e){pc_setup_json(['ok'=>false,'error'=>'PC connection could not be completed safely. Download a fresh setup.'],503);}
