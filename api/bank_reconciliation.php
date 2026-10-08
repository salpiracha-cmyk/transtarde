<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
require_once __DIR__.'/bank_reconciliation_core.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
function br_out(array $d,int $status=200):never{http_response_code($status);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
function br_permission(array $user,string $action):bool {
 if($action!=='View')return tt_user_can_module_action($user,'Accounts','reconciliation',$action);
 foreach(['cashbank','bank','reconciliation'] as $icon)if(tt_user_can_module_action($user,'Accounts',$icon,$action))return true;return false;
}
try{
 $u=tt_require_login();$write=$_SERVER['REQUEST_METHOD']==='POST';
 if(!$write&&$_SERVER['REQUEST_METHOD']!=='GET')br_out(['ok'=>false,'error'=>'Method not allowed.'],405);
 $b=$write?json_decode(file_get_contents('php://input')?:'{}',true,512,JSON_THROW_ON_ERROR):[];
 $entity=strtoupper((string)($b['entity']??$_GET['entity']??''));$action='View';$canWrite=false;foreach(['Edit','Create','Approve'] as $right)if(tt_user_can_access_entity($u,$entity,$right)&&br_permission($u,$right)){$canWrite=true;if($write)$action=$right;break;}
 if(!in_array($entity,['TTI','BRM','TG'],true)||!tt_user_can_access_entity($u,$entity,$action)||!br_permission($u,$action)||($write&&!$canWrite))br_out(['ok'=>false,'error'=>'Company and bank reconciliation permission required.'],403);
 if($write&&!tt_verify_csrf((string)($b['csrf']??'')))br_out(['ok'=>false,'error'=>'Session expired.'],419);
 $today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Karachi')))->format('Y-m-d');
 $date=br_date((string)($b['statementDate']??$_GET['statementDate']??$today));$bankId=trim((string)($b['bankId']??$_GET['bankId']??''));
 tt_ensure_data_dir();$h=fopen(TT_DATA_DIR.'/accounts.json','c+');if(!$h||!flock($h,$write?LOCK_EX:LOCK_SH))throw new RuntimeException('Accounts storage unavailable.');
 try{
  $raw=stream_get_contents($h);$s=$raw!==''?json_decode($raw,true,512,JSON_THROW_ON_ERROR):[];if(!is_array($s))throw new RuntimeException('Invalid Accounts storage.');
  $rec=[];
  if($write){
   $key=(string)($b['requestKey']??'');if(!preg_match('/^[A-Za-z0-9._:-]{8,128}$/',$key))throw new DomainException('Reopen reconciliation before saving.');
   $identity=$entity.'|'.($u['id']??0).'|'.$key;$fingerprint=hash('sha256',json_encode($b,JSON_THROW_ON_ERROR));$saved=$s['bankReconciliationRequests'][$identity]??null;
   if($saved){if($saved['fingerprint']!==$fingerprint)br_out(['ok'=>false,'error'=>'Request key already used.'],409);$rec=$saved['result'];}
   else{
    if((int)($b['revision']??-1)!==(int)($s['revision']??0))br_out(['ok'=>false,'error'=>'Accounts changed. Reload reconciliation before saving.'],409);
    $rec=br_save($s,$entity,$b,$u);$s['bankReconciliationRequests'][$identity]=['fingerprint'=>$fingerprint,'result'=>$rec];$s['revision']=(int)($s['revision']??0)+1;
    $encoded=json_encode($s,JSON_THROW_ON_ERROR);rewind($h);if(!ftruncate($h,0)||fwrite($h,$encoded)!==strlen($encoded)||!fflush($h))throw new RuntimeException('Reconciliation save failed.');
   }
  }
  $out=br_payload($s,$entity,$bankId,$date)+['canEdit'=>$canWrite,'saved'=>$rec];
 }finally{flock($h,LOCK_UN);fclose($h);}
 br_out($out);
}catch(DomainException $x){br_out(['ok'=>false,'error'=>$x->getMessage()],422);}catch(Throwable $x){error_log('Bank reconciliation: '.$x->getMessage());br_out(['ok'=>false,'error'=>'Bank reconciliation could not be loaded or saved.'],500);}
