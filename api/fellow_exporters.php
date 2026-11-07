<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';require_once __DIR__.'/directors_trading_core.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');
function fem_out(array $v,int $status=200):never{http_response_code($status);echo json_encode($v,JSON_THROW_ON_ERROR);exit;}
try{
 $u=tt_require_login();$write=$_SERVER['REQUEST_METHOD']==='POST';if(!$write&&$_SERVER['REQUEST_METHOD']!=='GET')fem_out(['ok'=>false,'error'=>'Method not allowed.'],405);
 $b=$write?json_decode((function_exists('tt_accounts_input')?tt_accounts_input():file_get_contents('php://input'))?:'{}',true,64,JSON_THROW_ON_ERROR):[];
 $entity=dt_entity($b['entity']??$_GET['entity']??'');$right=$write?(($b['action']??'')==='delete_exporter'?'Delete':(!empty($b['partyId'])?'Edit':'Create')):'View';
 if(!tt_user_can_master($u,'business_parties','View')||!tt_user_can_master($u,'business_parties',$right)||!tt_user_can_access_entity($u,$entity,'View')||!tt_user_can_access_entity($u,$entity,$right))fem_out(['ok'=>false,'error'=>'Matching Parties master and company permission required.'],403);
 if($write&&!tt_verify_csrf((string)($b['csrf']??'')))fem_out(['ok'=>false,'error'=>'Session expired. Reopen this form.'],419);
 if($write&&!in_array($b['action']??'',['save_exporter','delete_exporter'],true))throw new DomainException('Choose a master action.');
 $h=fopen(TT_DATA_DIR.'/accounts.json',$write?'r+':'rb');if(!$h||!flock($h,$write?LOCK_EX:LOCK_SH))throw new RuntimeException('Storage unavailable');
 try{
  $s=json_decode((string)stream_get_contents($h),true,512,JSON_THROW_ON_ERROR);$result=[];
  if($write){$key=gm_text($b['requestKey']??'',128);if(!preg_match('/^[A-Za-z0-9._:-]{8,128}$/D',$key))throw new DomainException('Reopen the form before saving.');$id=$entity.'|'.$u['id'].'|'.$key;$fp=hash('sha256',json_encode($b,JSON_THROW_ON_ERROR));$old=$s['fellowExporterRequests'][$id]??null;
   if($old){if(!hash_equals($old['fingerprint'],$fp))fem_out(['ok'=>false,'error'=>'Save key already used. Reopen the form.'],409);$result=$old['result'];}
   else{$result=fe_save_party($s,$entity,$b,$u,$b['action']==='delete_exporter');$s['fellowExporterRequests'][$id]=['fingerprint'=>$fp,'result'=>$result];$s['revision']=(int)($s['revision']??0)+1;$raw=json_encode($s,JSON_THROW_ON_ERROR);rewind($h);if(!ftruncate($h,0)||fwrite($h,$raw)!==strlen($raw)||!fflush($h))throw new RuntimeException('Save failed');}
  }
  $access=[];foreach(['Create','Edit','Delete']as$a)$access['can'.$a]=tt_user_can_master($u,'business_parties',$a)&&tt_user_can_access_entity($u,$entity,$a);
  $out=['ok'=>true,'entity'=>$entity,'parties'=>fe_parties($s,$entity),'access'=>$access,'result'=>$result];
 }finally{flock($h,LOCK_UN);fclose($h);}fem_out($out);
}catch(DomainException|JsonException $e){fem_out(['ok'=>false,'error'=>$e->getMessage()],422);}catch(Throwable $e){error_log('Fellow exporter master: '.$e->getMessage());fem_out(['ok'=>false,'error'=>'Master could not be loaded or saved. No success is confirmed.'],500);}
