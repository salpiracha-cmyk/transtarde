<?php
declare(strict_types=1);
define('TT_EXPENSES_FUNCTIONS_ONLY',true);
require_once __DIR__.'/expenses_v1.php';
require_once __DIR__.'/bank_entries_core.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
try {
    $u=tt_require_login();$write=($_SERVER['REQUEST_METHOD']??'GET')==='POST';
    if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','POST'],true))ev1_out(['ok'=>false,'error'=>'Method not allowed.'],405);
    $b=$write?json_decode(tt_accounts_input(),true,512,JSON_THROW_ON_ERROR):[];
    $e=strtoupper((string)($b['entity']??$_GET['entity']??''));
    if(!in_array($e,['TTI','BRM'],true))ev1_out(['ok'=>false,'error'=>'Petty cash is available in TTI and BRM.'],422);
    if(!tt_user_can_open_module($u,'Accounts')||!tt_user_can_access_entity($u,$e,'View')||!tt_user_can_module_action($u,'Accounts','expenses','View'))ev1_out(['ok'=>false,'error'=>'Expenses and company View permission are required.'],403);
    if(!$write)ev1_out(pc_payload(ev1_read(),$e,$u));
    if(!tt_verify_csrf((string)($b['csrf']??'')))ev1_out(['ok'=>false,'error'=>'Session expired. Refresh and try again.'],419);
    $action=(string)($b['action']??'');$right=$action==='manage'?'Edit':($action==='reverse'?'Edit':'Create');
    if(!in_array($action,['manage','post','reverse'],true))throw new DomainException('Unknown petty cash action.');
    if(!tt_user_can_module_action($u,'Accounts','expenses',$right)||!tt_user_can_access_entity($u,$e,$right))ev1_out(['ok'=>false,'error'=>'Expenses and company '.$right.' permission are required.'],403);
    if($action==='post'&&($b['type']??'')==='RECEIPT'&&($b['receiptSource']??'')==='BANK'&&(!tt_user_can_module_action($u,'Accounts','cashbank','Create')||!tt_user_can_module_action($u,'Accounts','cashbank','View')))ev1_out(['ok'=>false,'error'=>'Bank & Cash View and Create permission are required for a bank withdrawal.'],403);
    $key=ev1_request_key($b['requestKey']??'');$fingerprint=ev1_fingerprint($b);$identity=$e.'|'.($u['id']??0).'|'.$key;
    tt_ensure_data_dir();$h=fopen(EV1_FILE,'c+');if(!$h||!flock($h,LOCK_EX))throw new RuntimeException('Accounts storage unavailable.');
    try {
        $raw=stream_get_contents($h);$s=$raw!==''?json_decode($raw,true,512,JSON_THROW_ON_ERROR):ev1_def();if(!is_array($s))throw new RuntimeException('Accounts storage is invalid.');$s=array_replace_recursive(ev1_def(),$s);
        $saved=$s['pettyCashRequests'][$identity]??null;
        if($saved){if(!hash_equals($saved['fingerprint'],$fingerprint))ev1_out(['ok'=>false,'error'=>'This request was already used with different details.'],409);$result=$saved['result'];}
        else {
            if((int)($b['revision']??-1)!==(int)($s['revision']??0))ev1_out(['ok'=>false,'error'=>'Accounts changed. Reopen Petty Cash before saving. Your entry has not posted.'],409);
            $before=array_map('strval',array_keys((array)$s['journals']));$GLOBALS['TT_PETTY_PREVIEW']=!empty($b['preview']);$names=ev1_names();
            if($action==='manage')$result=pc_manage($s,$e,$b,$u);
            elseif($action==='post')$result=pc_post($s,$e,$b,$u,$names);
            else {
                $id=(string)($b['journalId']??'');$j=$s['journals'][$id]??null;
                if(!$j||($j['entity']??'')!==$e||!in_array($j['sourceType']??'',['PETTY_CASH_TRANSFER','PETTY_CASH_RECEIPT'],true)||!empty($j['reversedByPostId']))throw new DomainException('Choose an active petty cash transfer. Expenses are corrected through their original expense form.');
                if(($j['meta']['receiptSource']??'')==='BANK'&&(!tt_user_can_module_action($u,'Accounts','cashbank','Edit')||!tt_user_can_module_action($u,'Accounts','cashbank','View')))ev1_out(['ok'=>false,'error'=>'Bank & Cash Edit permission required to reverse this bank withdrawal.'],403);
                $reason=trim((string)($b['reason']??''));if(strlen($reason)<5||strlen($reason)>500)throw new DomainException('Enter a reversal reason of 5 to 500 characters.');
                $ids=ev1_reverse_journals($s,[$id],$e,$u,$names,$reason);pc_assert_balances($s,$e);$result=['journalId'=>$ids[0]??''];
            }
            tt_posting_preview_exit($b,$s,$before);
            $s['pettyCashRequests'][$identity]=['fingerprint'=>$fingerprint,'result'=>$result];$s['revision']=(int)$s['revision']+1;
            $encoded=json_encode($s,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);rewind($h);if(!ftruncate($h,0)||fwrite($h,$encoded)!==strlen($encoded)||!fflush($h))throw new RuntimeException('Petty cash could not be saved.');
        }
        $out=pc_payload($s,$e,$u)+['result'=>$result];if(!empty($result['journalId']))$out['journal']=$s['journals'][$result['journalId']]??null;
    }finally{flock($h,LOCK_UN);fclose($h);}
    ev1_out($out);
}catch(DomainException|InvalidArgumentException $x){ev1_out(['ok'=>false,'error'=>$x->getMessage()],422);}catch(Throwable $x){error_log('Petty cash: '.$x->getMessage());ev1_out(['ok'=>false,'error'=>'Petty cash could not be loaded or saved.'],500);}
