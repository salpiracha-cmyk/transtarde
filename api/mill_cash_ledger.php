<?php
declare(strict_types=1);
define('TT_EXPENSES_FUNCTIONS_ONLY',true);
require_once __DIR__.'/expenses_v1.php';
require_once __DIR__.'/mill_cash_core.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
function mcl_out(array $value,int $status=200): never {http_response_code($status);echo json_encode($value,JSON_THROW_ON_ERROR);exit;}
try{
    $method=$_SERVER['REQUEST_METHOD']??'GET';if(!in_array($method,['GET','POST'],true))mcl_out(['ok'=>false,'error'=>'Method not allowed.'],405);
    $u=tt_require_login();$write=$method==='POST';$b=$write?json_decode(tt_accounts_input(),true,512,JSON_THROW_ON_ERROR):$_GET;
    if(!tt_user_can_open_module($u,'Mill')||!tt_user_can_module_action($u,'Mill','petty','View'))mcl_out(['ok'=>false,'error'=>'Mill Petty Cash View permission required.'],403);
    $entity=strtoupper((string)($b['entity']??'TTI'));
    if(!in_array($entity,['TTI','BRM'],true))mcl_out(['ok'=>false,'error'=>'Select the company mill.'],422);
    if(!tt_user_business_entity($u,$entity,'View')||($write&&(!tt_user_business_entity($u,$entity,'Create')||!tt_user_can_module_action($u,'Mill','petty','Create'))))mcl_out(['ok'=>false,'error'=>'Mill Petty Cash and company permission required.'],403);
    $locationId=trim((string)($b['locationId']??''));$name=(string)($b['millName']??'');
    if($locationId==='')foreach(mcl_locations($entity) as $row)if(tt_location_identity($row['name'])===tt_location_identity($name)){$locationId=$row['id'];break;}
    $location=mcl_location($entity,$locationId);
    if(!$write){tt_release_read_session();mcl_out(mcl_payload(ev1_read(),$entity,$location,$u));}
    if(!tt_verify_csrf((string)($b['csrf']??'')))mcl_out(['ok'=>false,'error'=>'Session expired. Reload before posting.'],419);
    $key=ev1_request_key($b['requestKey']??'');$fingerprint=ev1_fingerprint($b);$identity=$entity.'|'.($u['id']??0).'|'.$key;
    tt_ensure_data_dir();$h=fopen(EV1_FILE,'c+');if(!$h||!flock($h,LOCK_EX))throw new RuntimeException('Accounts unavailable.');
    try{
        $raw=stream_get_contents($h);$s=$raw!==''?json_decode($raw,true,512,JSON_THROW_ON_ERROR):ev1_def();if(!is_array($s))throw new RuntimeException('Invalid Accounts store.');$s=array_replace_recursive(ev1_def(),$s);
        $saved=$s['millPettyRequests'][$identity]??null;
        if($saved){if(!hash_equals($saved['fingerprint'],$fingerprint))mcl_out(['ok'=>false,'error'=>'This save identity already has different details. Confirm the previous payment first.'],409);$result=$saved['result'];}
        else{
            if((int)($b['revision']??-1)!==(int)$s['revision'])mcl_out(['ok'=>false,'code'=>'STALE_REVISION','error'=>'Accounts changed. Refresh balances and review again; your payment has not posted.'],409);
            $before=array_map('strval',array_keys((array)$s['journals']));$GLOBALS['TT_PETTY_PREVIEW']=!empty($b['preview']);
            $result=mcl_post($s,$entity,$location,$b,$u);tt_posting_preview_exit($b,$s,$before);
            $s['millPettyRequests'][$identity]=['fingerprint'=>$fingerprint,'result'=>$result];$s['revision']=(int)$s['revision']+1;
            $encoded=json_encode($s,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);rewind($h);if(!ftruncate($h,0)||fwrite($h,$encoded)!==strlen($encoded)||!fflush($h))throw new RuntimeException('Mill cash payment could not be saved.');
        }
        $out=mcl_payload($s,$entity,$location,$u)+['result'=>$result,'journal'=>$s['journals'][$result['journalId']]??null];
    }finally{flock($h,LOCK_UN);fclose($h);}
    mcl_out($out);
}catch(DomainException|InvalidArgumentException $e){mcl_out(['ok'=>false,'error'=>$e->getMessage()],422);}catch(Throwable $e){error_log('Mill cash ledger: '.$e->getMessage());mcl_out(['ok'=>false,'error'=>'Mill cash could not be loaded or saved. Your draft is retained.'],500);}
