<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';require dirname(__DIR__).'/office_backup_auth.php';require dirname(__DIR__).'/office_pc_pairing.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
function pc_pair_json(array $data,int $code=200):never{http_response_code($code);echo json_encode($data,JSON_THROW_ON_ERROR);exit;}
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')pc_pair_json(['ok'=>false,'error'=>'Method not allowed.'],405);
if(!tt_request_is_https())pc_pair_json(['ok'=>false,'error'=>'HTTPS required.'],403);
if((int)($_SERVER['CONTENT_LENGTH']??0)>1024)pc_pair_json(['ok'=>false,'error'=>'Invalid authorization request.'],400);
$ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');$body=json_decode((string)file_get_contents('php://input'),true);
if(!is_array($body))pc_pair_json(['ok'=>false,'error'=>'Invalid authorization request.'],400);
try{
    if(tt_auth_retry_after('pc-pair',$ip,5,900,false)>0)pc_pair_json(['ok'=>false,'error'=>'Please wait before trying setup again.'],429);
    $id=(string)($body['id']??'');$secret=(string)($body['secret']??'');
    if(($body['action']??'')==='start'){
        tt_auth_record_failure('pc-pair-start',$ip,5,900,900,false);
        if(tt_auth_retry_after('pc-pair-start',$ip,5,900,false)>0)pc_pair_json(['ok'=>false,'error'=>'Please wait before trying setup again.'],429);
        pc_pair_json(tt_pc_pair_start($id,$secret,(string)($body['computer']??'')));
    }
    if(($body['action']??'')==='poll')pc_pair_json(tt_pc_pair_poll($id,$secret));
    pc_pair_json(['ok'=>false,'error'=>'Unknown authorization action.'],400);
}catch(InvalidArgumentException $e){tt_auth_record_failure('pc-pair',$ip,5,900,900,false);pc_pair_json(['ok'=>false,'error'=>$e->getMessage()],403);}
catch(Throwable $e){pc_pair_json(['ok'=>false,'error'=>'PC authorization could not be completed safely. Start setup again.'],503);}
