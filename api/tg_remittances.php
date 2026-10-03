<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
require_once __DIR__.'/tg_remittance_core.php';
require_once __DIR__.'/fi_credit_advice_link.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
function tgr_out(array $v,int $status=200):never{http_response_code($status);echo json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
function tgr_write(array $u):bool{
 if(($u['role']??'')==='Super Admin')return true;$p=$u['permissions']['Accounts']??[];if($p==='all')return true;
 foreach((array)$p as $v)if(is_string($v)&&in_array($v,['Create','Edit','Approve'],true)||is_array($v)&&array_intersect(['Create','Edit','Approve'],$v))return true;return false;
}
function tgr_banks():array{
 $out=[];foreach((array)(tt_list_masters()['banks']??[]) as $r){$v=array_values((array)($r['values']??[]));$linked=strtoupper((string)($v[1]??''));
  if(($v[0]??'')!=='Company Account'||!(str_contains($linked,'TRANS GRAINS')||preg_match('/(^|\W)TG($|\W)/',$linked))||strcasecmp((string)($v[13]??'Active'),'Active')!==0||((string)($v[8]??'')===''&&(string)($v[9]??'')===''))continue;
  $id=(string)($r['id']??'');$out[$id]=['id'=>$id,'bank'=>(string)($v[4]??''),'title'=>(string)($v[3]??''),'currency'=>strtoupper((string)($v[7]??''))];
 }return $out;
}
try{
 $u=tt_require_login();if(!tt_user_can_open_module($u,'Accounts')||!tt_user_can_access_entity($u,'TG','View'))tgr_out(['ok'=>false,'error'=>'TG Accounts access required.'],403);
 $write=($_SERVER['REQUEST_METHOD']??'GET')==='POST';if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','POST'],true))tgr_out(['ok'=>false,'error'=>'Method not allowed.'],405);
 $b=$write?json_decode((function_exists('tt_accounts_input')?tt_accounts_input():file_get_contents('php://input'))?:'{}',true):[];
 if($write&&(!is_array($b)||!tt_verify_csrf((string)($b['csrf']??''))))tgr_out(['ok'=>false,'error'=>'Refresh your session and try again.'],419);
 if($write&&(!tgr_write($u)||!(tt_user_can_access_entity($u,'TG','Edit')||tt_user_can_access_entity($u,'TG','Approve')||tt_user_can_access_entity($u,'TG','Create'))))tgr_out(['ok'=>false,'error'=>'TG posting permission required.'],403);
 $banks=tgr_banks();$rates=[];foreach($banks as $bank)$rates[$bank['currency']]=$bank['currency']==='AED'?1:(float)(tt_company_fx_rate('TG',$bank['currency'],'AED')??0);
 $names=[];foreach(['accounting_master_v1.json','export_realization_policy_v1.json','settlement_policy_v1.json'] as $f){$m=json_decode((string)file_get_contents(dirname(__DIR__).'/accounts/'.$f),true);foreach(array_merge((array)($m['chart']??[]),(array)($m['accounts']??[])) as $a)if(isset($a['code']))$names[(string)$a['code']]=(string)($a['name']??$a['code']);}
 tt_ensure_data_dir();$h=fopen(TT_DATA_DIR.'/accounts.json','c+');if(!$h||!flock($h,$write?LOCK_EX:LOCK_SH))throw new RuntimeException('Accounts storage unavailable.');
 try{$raw=stream_get_contents($h);$s=$raw?json_decode($raw,true):[];if(!is_array($s))throw new RuntimeException('Accounts storage unreadable.');
  $s=tt_fi_advice_project($s,tt_fi_advice_root());$result=null;
  if($write){if(($b['action']??'')==='reopen')$result=tgr_reopen($s,$b,$u);elseif(($b['action']??'')==='confirm')$result=tgr_confirm($s,$b,$u,$banks,$names,$rates);else throw new DomainException('Choose POST to confirm the remittance.');$s['revision']=(int)($s['revision']??0)+1;
   $encoded=json_encode($s,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);rewind($h);if(!ftruncate($h,0)||fwrite($h,$encoded)!==strlen($encoded)||!fflush($h))throw new RuntimeException('Could not save remittance.');
  }
  $items=tgr_items($s);foreach($items as &$x)$x['fingerprint']=tgr_fingerprint($x);unset($x);
  foreach($banks as &$bank)$bank['balance']=tgr_bank_balance($s,$bank['id'],$bank['currency']);unset($bank);
 }finally{flock($h,LOCK_UN);fclose($h);}
 tgr_out(['ok'=>true,'entity'=>'TG','items'=>$items,'banks'=>array_values($banks),'posted'=>$result,'history'=>array_values((array)($s['tgRemittances']??[])),'csrf'=>function_exists('tt_csrf_token')?tt_csrf_token():null]);
}catch(DomainException $e){tgr_out(['ok'=>false,'error'=>$e->getMessage()],422);}catch(Throwable $e){error_log('TG remittance: '.$e->getMessage());tgr_out(['ok'=>false,'error'=>'TG remittance could not be saved. Reopen the review and try again.'],500);}
