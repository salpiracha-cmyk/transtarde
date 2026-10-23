<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
const TT_STR_FILE=TT_DATA_DIR.'/accounts.json';
function sr_out(array $d,int $s=200):never{http_response_code($s);echo json_encode($d,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
function sr_payload(array $s,string $e):array{$rows=[];foreach((array)($s['salesTaxRefundReceipts']??[]) as $r)if(is_array($r)&&($r['entity']??'')===$e)$rows[]=$r;usort($rows,static fn($a,$b)=>strcmp($b['receiptDate'],$a['receiptDate']));return ['ok'=>true,'entity'=>$e,'revision'=>(int)($s['revision']??0),'receipts'=>$rows,'totalReceived'=>round(array_sum(array_column($rows,'amount')),2)];}
try{
    $u=tt_require_login();$write=($_SERVER['REQUEST_METHOD']??'GET')==='POST';if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','POST'],true))sr_out(['ok'=>false,'error'=>'Method not allowed.'],405);
    $b=$write?json_decode(tt_accounts_input(),true,512,JSON_THROW_ON_ERROR):[];$e=strtoupper((string)($b['entity']??$_GET['entity']??'TTI'));
    if(!in_array($e,['TTI','BRM'],true))throw new DomainException('Sales tax refunds are available for TTI or BRM.');
    if(!tt_user_can_open_module($u,'Accounts')||!tt_user_can_access_entity($u,$e,'View'))sr_out(['ok'=>false,'error'=>'Accounts and company View permission required.'],403);
    if($write&&(!tt_user_can_module_action($u,'Accounts','purchases','Create')||!tt_user_can_access_entity($u,$e,'Create')))sr_out(['ok'=>false,'error'=>'Purchases and company Create permission required.'],403);
    if($write&&!tt_verify_csrf((string)($b['csrf']??'')))sr_out(['ok'=>false,'error'=>'Session expired. Reopen the receipt.'],419);
    tt_ensure_data_dir();$h=fopen(TT_STR_FILE,$write?'c+':'c+');if(!$h||!flock($h,$write?LOCK_EX:LOCK_SH))throw new RuntimeException('Accounts storage unavailable.');
    try{
        $raw=stream_get_contents($h);$s=$raw!==''?json_decode($raw,true,512,JSON_THROW_ON_ERROR):['revision'=>0,'journals'=>[],'salesTaxRefundReceipts'=>[]];if(!is_array($s))throw new RuntimeException('Invalid Accounts storage.');
        if(!$write)sr_out(sr_payload($s,$e));
        $key=(string)($b['requestKey']??'');if(!preg_match('/^[A-Za-z0-9._:-]{8,128}$/',$key))throw new DomainException('Reopen the refund form before posting.');$identity=$e.'|'.($u['id']??0).'|'.$key;
        $fp=$b;unset($fp['csrf'],$fp['preview'],$fp['requestKey']);ksort($fp);$fp=hash('sha256',json_encode($fp,JSON_THROW_ON_ERROR));$saved=$s['salesTaxRefundRequests'][$identity]??null;
        if($saved){if(!hash_equals($saved['fingerprint'],$fp))sr_out(['ok'=>false,'error'=>'This receipt request was already used with different details.'],409);$rid=$saved['receiptId'];$receipt=$s['salesTaxRefundReceipts'][$rid];$j=$s['journals'][$receipt['journalId']];}
        else{
            if(isset($b['revision'])&&(int)$b['revision']!==(int)($s['revision']??0))sr_out(['ok'=>false,'error'=>'Accounts changed. Reopen the refund form before posting. Your receipt has not posted.'],409);
            $date=(string)($b['receiptDate']??'');$d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$d||$d->format('Y-m-d')!==$date)throw new DomainException('Enter a valid receipt date.');
            if(!is_numeric($b['amount']??null)||!is_finite((float)$b['amount'])||($amount=round((float)$b['amount'],2))<=0||$amount>100000000000)throw new DomainException('Enter a positive refund amount.');
            $bankId=(string)($b['bankAccountId']??'');$bank=null;foreach((array)(tt_list_masters()['banks']??[]) as $r)if((string)($r['id']??'')===$bankId){$bank=$r;break;}$v=(array)($bank['values']??[]);$link=strtoupper((string)($v[1]??''));$owner=str_contains($link,'BUKSH')||preg_match('/(^|\W)BRM($|\W)/',$link)?'BRM':(str_contains($link,'TRANSTRADE INTERNATIONAL')||preg_match('/(^|\W)TTI($|\W)/',$link)?'TTI':'');
            if(!$bank||$owner!==$e||!tt_bank_can_transact($bankId)||strtoupper((string)($v[7]??''))!=='PKR')throw new DomainException('Choose an active PKR bank from these company books.');
            $ref=trim((string)($b['reference']??''));$note=trim((string)($b['note']??''));if(strlen($ref)>180||strlen($note)>1000)throw new DomainException('Reference or note is too long.');
            $preview=!empty($b['preview']);$n=count((array)($s['salesTaxRefundReceipts']??[]))+1;do{$rid='STR-'.substr($date,0,4).'-'.str_pad((string)$n++,6,'0',STR_PAD_LEFT);}while(isset($s['salesTaxRefundReceipts'][$rid]));
            $jid=$preview?'PREVIEW':tt_next_post_id((array)($s['journals']??[]),'Accounts','Journal',$date);$actor=(string)($u['full_name']??$u['username']??'Accounts');
            $receipt=['id'=>$rid,'entity'=>$e,'receiptDate'=>$date,'amount'=>$amount,'bankAccountId'=>$bankId,'reference'=>$ref,'note'=>$note,'createdAt'=>gmdate('c'),'createdBy'=>$actor,'journalId'=>$jid];
            $j=['id'=>$jid,'entity'=>$e,'date'=>$date,'sourceType'=>'SALES_TAX_REFUND_RECEIPT','reference'=>$ref?:$rid,'narration'=>'Sales tax refund received from Government'.($note!==''?' — '.$note:''),'lines'=>[['account'=>'1110','accountName'=>'Bank Accounts','debit'=>$amount,'credit'=>0,'bankAccountId'=>$bankId,'bankName'=>(string)($v[4]??''),'accountTitle'=>(string)($v[3]??''),'accountNumber'=>(string)($v[8]??''),'iban'=>(string)($v[9]??''),'currency'=>'PKR','bankDebit'=>$amount,'bankCredit'=>0],['account'=>'1230','accountName'=>'Other Receivables / Advances','debit'=>0,'credit'=>$amount,'subledger'=>'Sales Tax Recoverable / Refund Receivable']],'totalDebit'=>$amount,'totalCredit'=>$amount,'status'=>'Posted','meta'=>['salesTaxRefundReceiptId'=>$rid,'bankAccountId'=>$bankId],'createdAt'=>gmdate('c'),'createdBy'=>$actor];
            if($preview)sr_out(['ok'=>true,'previews'=>[$j]]);
            $s['journals'][$jid]=$j;$s['salesTaxRefundReceipts'][$rid]=$receipt;$s['salesTaxRefundRequests'][$identity]=['fingerprint'=>$fp,'receiptId'=>$rid];$s['revision']=(int)($s['revision']??0)+1;
            $encoded=json_encode($s,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);rewind($h);if(!ftruncate($h,0)||fwrite($h,$encoded)!==strlen($encoded)||!fflush($h))throw new RuntimeException('Accounts storage could not be saved.');
        }
    }finally{flock($h,LOCK_UN);fclose($h);}
    sr_out(sr_payload($s,$e)+['receipt'=>$receipt,'journalId'=>$j['id'],'journal'=>$j]);
}catch(DomainException $x){sr_out(['ok'=>false,'error'=>$x->getMessage()],422);}catch(Throwable $x){error_log('Sales tax refund: '.$x->getMessage());sr_out(['ok'=>false,'error'=>'Sales tax refund receipt could not be loaded or posted.'],500);}
