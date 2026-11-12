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

if(PHP_SAPI!=='cli')exit(1);
define('REVIEW_PUBLIC_KEY','-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAmifWbXLSVBk2UgLoh/sS
ykYHdTe662txOF5hGYvn8tA5u++Icbas1D3Wl6Gf0zZqIIVItsyZpYIlLBTUL4W8
JG8siafMChbZ0fA5yp0ULe5/6Opczf3qXqNO4Kx3K/Vfd/azE75MRzvA+QFsxDpN
55FlPMsreOLl/sE66flvTMY+huMGjVZ9YWkht2IT7t3Fxw0u10LrrpLuRgCIvImb
1LNCwASmKH7JnjqQ8ksw2DFIkTrZKu4PsJVXNh1rtnGbHa2nndmmB3pa9tJvHIZf
awjDTbxlD/+nNnELd2gtiFTw1DzFS3gcOHf2Z9KEHEmPs+iwvnxxnDkAPUWnk3AB
lwIDAQAB
-----END PUBLIC KEY-----
');
$root='/home/u831010370/domains/app.transtradeinternational.com/public_html';
if(is_file($root.'/.user.ini')){$ini=parse_ini_file($root.'/.user.ini',false,INI_SCANNER_RAW);$p=$ini['auto_prepend_file']??'';if($p&&$p!=='none'){if(!str_starts_with($p,'/'))$p=$root.'/'.$p;if(is_file($p))require_once $p;}}
require_once $root.'/auth_store.php';require_once $root.'/inventory_reconciliation.php';require_once $root.'/api/group_daily_core.php';
$ops=gd_read_operations(TT_DATA_DIR.'/operations.json');$v=$ops['values'];$result=['backend'=>$ops['sourceBackend'],'production'=>[],'stock'=>[],'sales'=>[]];
foreach(tt_inv_rows($v,'tt30prod') as $r){$changes=[];$new=$r;if(!empty($r['clientSaveRequestId']))$new['recoveryVersion']=mr_is_calculated($r)?2:1;if(($r['arrivalPackingVersion']??null)===1)$new['arrivalPackingQty']=tt_inv_arrival_packing_qty($r);foreach($new as $f=>$val)if(($r[$f]??null)!=$val)$changes[$f]=['before'=>$r[$f]??null,'after'=>$val];if($changes||stripos(json_encode($r),'emrooz')!==false)$result['production'][]=['id'=>$r['id'],'date'=>$r['date'],'changes'=>$changes,'rows'=>array_map(fn($x)=>array_intersect_key($x,array_flip(['product','displayName','bags','bagWeight','systemKg'])), $r['rows']??[])];}
foreach(tt_inv_stock($v,['entity'=>'TTI','millName'=>'TTI Rice Mills']) as $name=>$kg)if(stripos($name,'emrooz')!==false)$result['stock'][$name]=$kg;
foreach(tt_inv_rows($v,'tt35exportersale') as $r)if(stripos(json_encode($r),'emrooz')!==false||str_contains((string)($r['ref']??''),'d32de747'))$result['sales'][]=array_intersect_key($r,array_flip(['ref','buyer','brand','stockSourceName','displayName','millName','entity','qty','containers']));
$h=fopen(TT_DATA_DIR.'/accounts.json','rb');if($h&&flock($h,LOCK_SH)){try{$a=json_decode(stream_get_contents($h),true);foreach($a['exporterDeals']??[] as $d)if(stripos(json_encode($d),'emrooz')!==false||str_contains((string)($d['id']??''),'d32de747'))$result['deals'][]=array_intersect_key($d,array_flip(['id','party','brand','productionStockName','productionMillName','entity','quantityMT']));}finally{flock($h,LOCK_UN);fclose($h);}}
echo 'ENCRYPTED_REVIEW='.json_encode(elr_encrypt($result,REVIEW_PUBLIC_KEY),JSON_THROW_ON_ERROR).PHP_EOL;
