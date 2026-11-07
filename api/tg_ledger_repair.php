<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
require_once __DIR__.'/tg_ledger_repair_core.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
function tglr_out(array $data,int $status=200):never{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
try{
 $user=tt_require_login();if(($user['role']??'')!=='Super Admin'||!tt_user_can_open_module($user,'Accounts')||!tt_user_can_access_entity($user,'TG','Edit'))tglr_out(['ok'=>false,'error'=>'Super Admin and TG Edit access are required.'],403);
 $method=$_SERVER['REQUEST_METHOD'];if(!in_array($method,['GET','POST'],true))tglr_out(['ok'=>false,'error'=>'Method not allowed.'],405);
 $body=[];if($method==='POST'){$body=json_decode(file_get_contents('php://input')?:'{}',true);if(!is_array($body)||!tt_verify_csrf((string)($body['csrf']??'')))tglr_out(['ok'=>false,'error'=>'Session expired. Refresh and try again.'],419);if(($body['action']??'')!=='apply')tglr_out(['ok'=>false,'error'=>'Choose the reviewed correction action.'],422);}
 $h=fopen(TT_DATA_DIR.'/accounts.json','r+');if(!$h||!flock($h,$method==='GET'?LOCK_SH:LOCK_EX))throw new RuntimeException('Accounts unavailable.');
 try{$raw=stream_get_contents($h);$store=json_decode($raw,true,512,JSON_THROW_ON_ERROR);if($method==='GET')$response=['ok'=>true,'plan'=>tglr_plan($store),'history'=>array_slice((array)($store['tgLedgerRepairAudit']??[]),-5)];else{$result=tglr_apply($store,$user,(string)($body['fingerprint']??''));$encoded=json_encode($store,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);if($encoded!==$raw){rewind($h);if(!ftruncate($h,0)||fwrite($h,$encoded)!==strlen($encoded)||!fflush($h))throw new RuntimeException('Accounts could not be saved.');}$response=['ok'=>true,'result'=>$result,'plan'=>tglr_plan($store)];}}finally{flock($h,LOCK_UN);fclose($h);}tglr_out($response);
}catch(DomainException|InvalidArgumentException $e){tglr_out(['ok'=>false,'error'=>$e->getMessage()],422);}catch(Throwable $e){error_log('TG ledger repair: '.$e->getMessage());tglr_out(['ok'=>false,'error'=>'TG corrections could not be completed. Review the saved correction history before retrying.'],500);}
