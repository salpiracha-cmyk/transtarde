<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
require_once __DIR__.'/accounts_subaccounts_core.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
function payee_out(array $d,int $status=200):never{http_response_code($status);echo json_encode($d,JSON_THROW_ON_ERROR);exit;}
try{
 $u=tt_require_login();$write=($_SERVER['REQUEST_METHOD']??'')==='POST';if(!$write&&($_SERVER['REQUEST_METHOD']??'')!=='GET')payee_out(['ok'=>false,'error'=>'Method not allowed.'],405);
 $b=$write?json_decode(file_get_contents('php://input')?:'{}',true,512,JSON_THROW_ON_ERROR):[];$e=strtoupper((string)($b['entity']??$_GET['entity']??''));$kind=(string)($b['kind']??$_GET['kind']??'');$icon=$kind==='EXPENSE'?'expenses':'cashbank';
 if(!tt_user_can_module_action($u,'Accounts',$icon,'View')||!in_array($e,['TTI','BRM','TG'],true)||!tt_user_can_access_entity($u,$e,'View')||!sac_permission($u,$icon,'View'))payee_out(['ok'=>false,'error'=>'Accounts and company View permission required.'],403);
 if($write){if(!tt_verify_csrf((string)($b['csrf']??'')))payee_out(['ok'=>false,'error'=>'Session expired.'],419);if(!sac_permission($u,$icon,'Edit')||!tt_user_can_access_entity($u,$e,'Edit'))payee_out(['ok'=>false,'error'=>'Accounts setup requires Edit permission.'],403);}
 tt_ensure_data_dir();$h=fopen(TT_DATA_DIR.'/accounts.json','c+');if(!$h||!flock($h,$write?LOCK_EX:LOCK_SH))throw new RuntimeException('Accounts storage unavailable.');
 try{$raw=stream_get_contents($h);$s=$raw!==''?json_decode($raw,true,512,JSON_THROW_ON_ERROR):[];if(!is_array($s))throw new RuntimeException('Invalid Accounts storage.');$result=[];
 if($write){if((int)($b['revision']??-1)!==(int)($s['revision']??0))payee_out(['ok'=>false,'error'=>'Accounts setup changed. Reopen recipient setup.'],409);$result=sac_save_payee($s,$e,$b,$u);$s['revision']=(int)($s['revision']??0)+1;$encoded=json_encode($s,JSON_THROW_ON_ERROR);rewind($h);if(!ftruncate($h,0)||fwrite($h,$encoded)!==strlen($encoded)||!fflush($h))throw new RuntimeException('Save failed.');}
 $out=['ok'=>true,'entity'=>$e,'revision'=>(int)($s['revision']??0),'payees'=>sac_payees($s,$e,$kind),'heads'=>array_values(sac_entity_chart($s,$e)),'subaccounts'=>array_values(sac_payload($s,$e)['subaccounts']),'canEdit'=>sac_permission($u,$icon,'Edit')&&tt_user_can_access_entity($u,$e,'Edit'),'result'=>$result];
 }finally{flock($h,LOCK_UN);fclose($h);}payee_out($out);
}catch(DomainException $x){payee_out(['ok'=>false,'error'=>$x->getMessage()],422);}catch(Throwable $x){error_log('Recipient setup: '.$x->getMessage());payee_out(['ok'=>false,'error'=>'Recipient setup could not be loaded or saved.'],500);}
