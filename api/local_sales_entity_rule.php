<?php
declare(strict_types=1);
require dirname(__DIR__) . '/auth_store.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

const TT_LSER_FILE = TT_DATA_DIR . '/accounts.json';

function lser_out(array $d,int $s=200):never{http_response_code($s);echo json_encode($d,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
function lser_can_write(array $u,string $m):bool{if(($u['role']??'')==='Super Admin')return true;$p=$u['permissions'][$m]??null;if($p==='all')return true;if(!is_array($p))return false;if(in_array('Create',$p,true)||in_array('Edit',$p,true)||in_array('Approve',$p,true))return true;foreach($p as $a)if(is_array($a)&&(in_array('Create',$a,true)||in_array('Edit',$a,true)||in_array('Approve',$a,true)))return true;return false;}
function lser_def():array{return ['revision'=>0,'localSalesCandidates'=>[],'localSalesPaymentCandidates'=>[]];}
function lser_posted(array $r):bool{return !empty($r['journalId'])||preg_match('/Approved|Posted/i',(string)($r['status']??''))===1;}
function lser_target_id(string $target,string $kind,array $r):string{return $target.'|'.$kind.'|'.(string)($r['sourceKey']??'');}

try{
 $u=tt_require_login();
 if($_SERVER['REQUEST_METHOD']!=='POST')lser_out(['ok'=>false,'error'=>'Method not allowed.'],405);
 $b=json_decode(file_get_contents('php://input')?:'{}',true);
 if(!is_array($b)||!tt_verify_csrf((string)($b['csrf']??'')))lser_out(['ok'=>false,'error'=>'Your session expired. Refresh and try again.'],419);
 if(!lser_can_write($u,'Mill')&&!lser_can_write($u,'Accounts'))lser_out(['ok'=>false,'error'=>'Mill or Accounts permission required.'],403);
 $target=strtoupper(trim((string)($b['targetEntity']??'')));$soda=trim((string)($b['soda']??''));
 if(!in_array($target,['TTI','BRM'],true)||$soda==='')lser_out(['ok'=>false,'error'=>'Valid Local Soda and company are required.'],422);
 tt_ensure_data_dir();$h=fopen(TT_LSER_FILE,'c+');if($h===false||!flock($h,LOCK_EX))throw new RuntimeException('Accounts storage unavailable.');
 try{
  rewind($h);$raw=stream_get_contents($h);$s=$raw?json_decode($raw,true):null;if(!is_array($s))$s=lser_def();$s=array_replace_recursive(lser_def(),$s);
  $groups=[['key'=>'localSalesCandidates','kind'=>'LOCAL_SALE'],['key'=>'localSalesPaymentCandidates','kind'=>'LOCAL_PAYMENT']];$conflicts=[];
  foreach($groups as $g){foreach((array)$s[$g['key']] as $id=>$r){if(!is_array($r)||trim((string)($r['soda']??''))!==$soda)continue;$entity=strtoupper((string)($r['entity']??''));if($entity===$target)continue;if(lser_posted($r))$conflicts[]=['id'=>$id,'entity'=>$entity,'status'=>(string)($r['status']??''),'journalId'=>(string)($r['journalId']??'')];}}
  if($conflicts)lser_out(['ok'=>false,'error'=>'This Local Soda already has an Accounts posting under another company. Accounts must correct that posting before the company can change.','conflicts'=>$conflicts],409);
  $moved=['sales'=>0,'payments'=>0];
  foreach($groups as $g){$rows=(array)$s[$g['key']];foreach(array_keys($rows) as $id){$r=$rows[$id]??null;if(!is_array($r)||trim((string)($r['soda']??''))!==$soda)continue;$entity=strtoupper((string)($r['entity']??''));if($entity===$target)continue;$newId=lser_target_id($target,$g['kind'],$r);if(trim((string)($r['sourceKey']??''))==='')continue;$existing=$rows[$newId]??null;$r['id']=$newId;$r['entity']=$target;$r['reclassifiedFrom']=$entity;$r['reclassifiedAt']=gmdate('c');$r['reclassifiedBy']='Bank account rule';if(is_array($existing)&&!lser_posted($existing)){$r=array_replace($r,$existing,['id'=>$newId,'entity'=>$target,'reclassifiedFrom'=>$entity,'reclassifiedAt'=>gmdate('c'),'reclassifiedBy'=>'Bank account rule']);}unset($rows[$id]);$rows[$newId]=$r;$moved[$g['key']==='localSalesCandidates'?'sales':'payments']++;}$s[$g['key']]=$rows;}
  $s['revision']=(int)($s['revision']??0)+1;rewind($h);ftruncate($h,0);fwrite($h,json_encode($s,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));fflush($h);
 }finally{flock($h,LOCK_UN);fclose($h);}
 lser_out(['ok'=>true,'soda'=>$soda,'entity'=>$target,'moved'=>$moved,'revision'=>$s['revision']]);
}catch(Throwable $e){lser_out(['ok'=>false,'error'=>'The Local Sales company rule could not be applied.'],500);}

