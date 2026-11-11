<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
require_once __DIR__.'/accounts_reviews_core.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
function ar_out(array $v,int $code=200):never{http_response_code($code);echo json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
try{
 $u=tt_require_login();if(!tt_user_can_open_module($u,'Accounts'))ar_out(['ok'=>false,'error'=>'Accounts permission required.'],403);
 $b=json_decode(file_get_contents('php://input')?:'{}',true);if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')ar_out(['ok'=>false,'error'=>'Use POST to update a review.'],405);
 if(!is_array($b)||!tt_verify_csrf((string)($b['csrf']??'')))ar_out(['ok'=>false,'error'=>'Refresh your session and try again.'],419);
 $e=strtoupper((string)($b['entity']??''));if(!in_array($e,['TTI','BRM','TG'],true))ar_out(['ok'=>false,'error'=>'Select company books.'],422);
 $action=$b['action']??'';
 if(!in_array($action,['discard','approve_late_pohanch'],true))ar_out(['ok'=>false,'error'=>'Choose a supported review action.'],422);
 $approve=$action==='approve_late_pohanch';
 if($approve){
  if(!ar_late_pohanch_can_approve($u,$e))ar_out(['ok'=>false,'error'=>'Accounts purchase approval permission required.'],403);
 }elseif(($u['role']??'')!=='Super Admin'){
  $permissions=$u['permissions']['Accounts']??[];$write=$permissions==='all';
  if(is_array($permissions))foreach($permissions as$rights){
   if(is_string($rights)&&in_array($rights,['Create','Edit','Approve'],true))$write=true;
   elseif(is_array($rights)&&array_intersect(['Create','Edit','Approve'],array_values($rights)))$write=true;
  }
  if(!$write||!tt_user_can_access_entity($u,$e,'View')||!(tt_user_can_access_entity($u,$e,'Edit')||tt_user_can_access_entity($u,$e,'Approve')))ar_out(['ok'=>false,'error'=>'Accounts review permission required for this company.'],403);
 }
 $pohanches=$approve?[]:ar_issued_pohanches($e);
 tt_ensure_data_dir();$h=fopen(TT_DATA_DIR.'/accounts.json','r+b');if(!$h||!flock($h,LOCK_EX))throw new RuntimeException('Accounts storage unavailable.');
 try{rewind($h);$raw=stream_get_contents($h);$s=$raw?json_decode($raw,true):[];if(!is_array($s))throw new RuntimeException('Accounts storage invalid.');$match=null;foreach(ar_items($s,$e,$pohanches)as$item)if($item['id']===($b['id']??'')&&$item['fingerprint']===($b['fingerprint']??'')){$match=$item;break;}
 if(!$match)ar_out(['ok'=>false,'error'=>'This review changed or has already been removed. Refresh the list.'],409);
 if($match['kind']==='REMITTANCE')ar_out(['ok'=>false,'error'=>'This payment must be reviewed and posted; it cannot be discarded.'],422);
 if($approve&&empty($match['approval']))ar_out(['ok'=>false,'error'=>'Only a late Pohanch can be approved here.'],422);
 $at=gmdate('c');$actor=(string)($u['username']??$u['full_name']??'User');
 if($approve){
  $approval=$match['approval'];$key=$approval['key'];
  $s['accountsReviewApprovals'][$key]=['entity'=>$e,'receiptFingerprint'=>$approval['receiptFingerprint'],'approvedAt'=>$at,'approvedBy'=>$actor];
  if($approval['releaseHold']){
   $s['payableHolds'][$key]['active']=false;
   $s['payableHolds'][$key]['releasedAt']=$at;
   $s['payableHolds'][$key]['releasedBy']=$actor;
   $s['payableHolds'][$key]['releaseReason']='Late Pohanch approved in Accounts';
  }
 }else{
  $s['accountsReviewDismissals'][$match['id']]=['entity'=>$e,'fingerprint'=>$match['fingerprint'],'discardedAt'=>$at,'discardedBy'=>(string)($u['full_name']??$actor)];
 }
 $s['revision']=(int)($s['revision']??0)+1;
 $s['workflowAudit'][]=['at'=>gmdate('c'),'type'=>'ACCOUNTS_REVIEW','id'=>$match['id'],'action'=>$approve?'APPROVE_LATE_POHANCH':'DISCARD','user'=>$actor,'before'=>$match,'after'=>[]];
 $encoded=json_encode($s,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);rewind($h);if(!ftruncate($h,0)||fwrite($h,$encoded)!==strlen($encoded)||!fflush($h))throw new RuntimeException('Could not save review.');
 }finally{flock($h,LOCK_UN);fclose($h);}ar_out(['ok'=>true]);
}catch(Throwable $x){error_log('Accounts review: '.$x->getMessage());ar_out(['ok'=>false,'error'=>'Could not save this review. Try again.'],500);}
