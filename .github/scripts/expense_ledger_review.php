<?php
declare(strict_types=1);

/** Read-only projection. No source record, journal, account or audit is changed. */
function elr_collect(array $store,array $chart):array {
    $records=[];
    foreach(['generalExpenses','utilityPayments','supplierBills','cardBillBreakdowns'] as $collection){
        foreach((array)($store[$collection]??[]) as $id=>$record){
            if(!is_array($record)||!in_array($record['entity']??'',['TTI','BRM'],true))continue;
            if($collection==='supplierBills'&&empty($record['expenseInvoice']))continue;
            if(in_array($record['status']??'',['Deleted','Amended','Cancelled','Reversed'],true))continue;
            $records[]=['collection'=>$collection,'id'=>(string)$id,'fingerprint'=>hash('sha256',json_encode($record,JSON_THROW_ON_ERROR)),'record'=>$record];
        }
    }
    $journals=[];$subaccountReferences=[];$partyReferences=[];
    foreach((array)($store['journals']??[]) as $id=>$journal){
        if(!is_array($journal)||!in_array($journal['entity']??'',['TTI','BRM'],true))continue;
        $expense=false;
        foreach((array)($journal['lines']??[]) as $line){
            if(!is_array($line))continue;
            if(($chart[(string)($line['account']??'')]['class']??'')==='Expense')$expense=true;
            if(!empty($line['subaccountId']))$subaccountReferences[(string)$line['subaccountId']]=($subaccountReferences[(string)$line['subaccountId']]??0)+1;
            foreach(['expenseRecipientId','payeeId'] as $key)if(!empty($line[$key]))$partyReferences[(string)$line[$key]]=($partyReferences[(string)$line[$key]]??0)+1;
        }
        if($expense||!empty($journal['meta']['directExpense'])||!empty($journal['meta']['expenseInvoiceId']))$journals[(string)$id]=$journal;
    }
    $subs=[];foreach((array)($store['accountSubaccounts']??[]) as $id=>$a)if(is_array($a)&&in_array($a['entity']??'',['TTI','BRM'],true))$subs[(string)$id]=['account'=>$a,'historicalJournalReferences'=>$subaccountReferences[(string)$id]??0];
    $payees=[];foreach((array)($store['paymentPayees']??[]) as $id=>$a)if(is_array($a)&&in_array($a['entity']??'',['TTI','BRM'],true))$payees[(string)$id]=['account'=>$a,'historicalJournalReferences'=>$partyReferences[(string)($a['id']??'')]??0];
    return ['readOnly'=>true,'revision'=>(int)($store['revision']??0),'records'=>$records,'journals'=>$journals,'accountSubaccounts'=>$subs,'paymentPayees'=>$payees,'chart'=>$chart];
}

function elr_encrypt(array $payload,string $publicKey):array {
    $plain=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $key=random_bytes(32);$iv=random_bytes(12);$tag='';
    $cipher=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag);
    if($cipher===false||!openssl_public_encrypt($key,$wrapped,$publicKey,OPENSSL_PKCS1_OAEP_PADDING))throw new RuntimeException('Review encryption failed.');
    return ['format'=>'transtrade-expense-review-v1','algorithm'=>'RSA-OAEP-SHA1+AES-256-GCM','wrappedKey'=>base64_encode($wrapped),'iv'=>base64_encode($iv),'tag'=>base64_encode($tag),'ciphertext'=>base64_encode($cipher),'sha256'=>hash('sha256',$plain)];
}

if(defined('TT_EXPENSE_REVIEW_LIBRARY'))return;
try{
    if(PHP_SAPI!=='cli')throw new RuntimeException('CLI only.');
    $root=(string)getenv('TT_EXPENSE_REVIEW_ROOT');
    if(!preg_match('~^/home/[A-Za-z0-9_-]+/domains/app\.transtradeinternational\.com/public_html$~D',$root))throw new RuntimeException('Unexpected review target.');
    $public=base64_decode((string)getenv('TT_EXPENSE_REVIEW_PUBLIC_KEY_B64'),true);
    if($public===false||openssl_pkey_get_public($public)===false)throw new RuntimeException('Review encryption key unavailable.');
    require $root.'/auth_store.php';
    $handle=fopen(TT_DATA_DIR.'/accounts.json','rb');
    if(!$handle||!flock($handle,LOCK_SH))throw new RuntimeException('Accounts source unavailable.');
    try{
        $raw=(string)stream_get_contents($handle);$store=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($store)||($store!==[]&&array_is_list($store)))throw new RuntimeException('Invalid Accounts source.');
        $master=json_decode((string)file_get_contents($root.'/accounts/accounting_master_v1.json'),true,512,JSON_THROW_ON_ERROR);$chart=[];
        foreach(array_merge((array)($master['chart']??[]),(array)($master['peopleSubledgers']??[])) as $row)$chart[(string)$row['code']]=$row;
        foreach(['settlement_policy_v1.json','export_realization_policy_v1.json'] as $file){$path=$root.'/accounts/'.$file;if(!is_file($path))continue;$policy=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);foreach((array)($policy['accounts']??[]) as $row)if(!isset($chart[(string)$row['code']]))$chart[(string)$row['code']]=$row;}
        $payload=elr_collect($store,$chart)+['sourceSha256'=>hash('sha256',$raw),'collectedAt'=>gmdate('c')];
        rewind($handle);if(hash('sha256',(string)stream_get_contents($handle))!==$payload['sourceSha256'])throw new RuntimeException('Accounts source changed during locked review.');
    }finally{flock($handle,LOCK_UN);fclose($handle);}
    echo json_encode(elr_encrypt($payload,$public),JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $error){fwrite(STDERR,"Read-only expense review failed. No correction was attempted.\n");exit(1);}
