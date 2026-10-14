<?php
declare(strict_types=1);
require_once __DIR__.'/accounts_posting_preview.php';
header('X-TT-Posting-Preview: available');
require dirname(__DIR__).'/auth_store.php';require_once __DIR__.'/forward_booking_core.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
function fwb_out(array $v,int $status=200):never{http_response_code($status);echo json_encode($v,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit;}
try{
 $u=tt_require_login();$write=$_SERVER['REQUEST_METHOD']==='POST';if(!$write&&$_SERVER['REQUEST_METHOD']!=='GET')fwb_out(['ok'=>false,'error'=>'Method not allowed.'],405);$body=$write?json_decode((function_exists('tt_accounts_input')?tt_accounts_input():file_get_contents('php://input'))?:'{}',true,512,JSON_THROW_ON_ERROR):[];$e=fwb_entity(strtoupper((string)($body['entity']??$_GET['entity']??'')));
 if(!tt_user_can_open_module($u,'Accounts')||!tt_user_can_access_entity($u,$e,'View'))fwb_out(['ok'=>false,'error'=>'Accounts and company View permission required.'],403);
 $action=(string)($body['action']??'');$right=in_array($action,['edit','reverse_settlement'],true)?'Edit':($action==='cancel'?'Delete':'Create');
 if($write&&(!tt_verify_csrf((string)($body['csrf']??''))||!tt_user_can_module_action($u,'Accounts','cashbank',$right)||!tt_user_can_access_entity($u,$e,$right)))fwb_out(['ok'=>false,'error'=>'A valid session and Bank '.$right.' permission for this company are required.'],403);
 if($write&&(($action==='create'&&!empty($body['id']))||($action==='edit'&&empty($body['id']))))throw new DomainException('Choose the correct create or edit booking action.');
 $asOf=fwb_date((string)($_GET['asOf']??fwb_today()),true);tt_ensure_data_dir();$h=fopen(TT_DATA_DIR.'/accounts.json','c+');if(!$h||!flock($h,$write?LOCK_EX:LOCK_SH))throw new RuntimeException('Accounts storage unavailable.');
 try{rewind($h);$raw=stream_get_contents($h);$s=$raw!==''?json_decode($raw,true,512,JSON_THROW_ON_ERROR):[];$s['journals']??=[];$result=[];$previewBefore=array_map('strval',array_keys((array)($s['journals']??[])));
 if($write){$key=(string)($body['requestKey']??'');if(!preg_match('/^[A-Za-z0-9._:-]{8,128}$/',$key))throw new DomainException('Reopen the forward booking form before saving.');$request=$e.'|'.($u['id']??0).'|'.$key;$hash=hash('sha256',json_encode(tt_preview_request_body($body),JSON_THROW_ON_ERROR));$saved=$s['forwardBookingRequests'][$request]??null;
 if($saved){if($saved['fingerprint']!==$hash)fwb_out(['ok'=>false,'error'=>'This request already saved different details.'],409);$result=$saved['result'];}
 else {if((int)($body['revision']??-1)!==(int)($s['revision']??0))fwb_out(['ok'=>false,'error'=>'Accounts changed. Reload this booking before saving; nothing has posted.'],409);
 $result=match($action){'create','edit'=>fwb_save($s,$e,$body,$u),'settle'=>fwb_settle($s,$e,$body,$u),'reverse_settlement'=>fwb_reverse_settlement($s,$e,(string)($body['id']??''),(string)($body['reason']??''),$u),'cancel'=>fwb_cancel($s,$e,(string)($body['id']??''),(string)($body['reason']??''),$u),default=>throw new DomainException('Unknown forward booking action.')};tt_posting_preview_exit($body,$s,$previewBefore);$s['revision']=(int)($s['revision']??0)+1;$s['forwardBookingRequests'][$request]=['fingerprint'=>$hash,'result'=>$result];$encoded=json_encode($s,JSON_THROW_ON_ERROR);rewind($h);if(!ftruncate($h,0)||fwrite($h,$encoded)!==strlen($encoded)||!fflush($h))throw new RuntimeException('Save failed.');}}
 $out=['ok'=>true,'entity'=>$e,'asOf'=>$asOf,'revision'=>$s['revision']??0,'bookings'=>fwb_rows($s,$e,$asOf),'banks'=>fwb_banks($e),'result'=>$result];foreach(['Create','Edit','Delete'] as $r)$out['can'.$r]=tt_user_can_module_action($u,'Accounts','cashbank',$r)&&tt_user_can_access_entity($u,$e,$r);
 }finally{flock($h,LOCK_UN);fclose($h);}fwb_out($out);
}catch(DomainException $x){fwb_out(['ok'=>false,'error'=>$x->getMessage()],422);}catch(Throwable $x){error_log('Forward bookings: '.$x->getMessage());fwb_out(['ok'=>false,'error'=>'The forward booking could not be loaded or saved. No new action was committed.'],500);}
