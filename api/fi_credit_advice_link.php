<?php
declare(strict_types=1);
require_once __DIR__.'/receipt_invoice_links.php';

// Read-only metadata projection from committed records. No journals or balances change.
function tt_fi_advice_name(string $name): string {
    $name=strtoupper(trim($name));
    $name=preg_replace('/[^\p{L}\p{N}]+/u',' ', $name)??'';
    $name=trim(preg_replace('/\s+/',' ',$name)??'');
    return in_array($name,['TG','TRANS GRAINS','TRANS GRAINS FOODSTUFF TRADING','TRANS GRAINS FOODSTUFF TRADING L L C','TRANS GRAINS FOODSTUFF TRADING LLC'],true)?'TG':$name;
}
function tt_fi_advice_day(string $value): ?int {
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('UTC'));
    return $date&&$date->format('Y-m-d')===$value?(int)($date->getTimestamp()/86400):null;
}
function tt_fi_advice_matches(array $root,array $store): array {
    $fiRows=[];$receipts=[];$candidates=[];$reverse=[];$links=[];
    foreach((array)($root['fi']??[]) as $fi){
        if(!is_array($fi)||empty($fi['id'])||!empty($fi['cancelled'])||in_array(strtoupper((string)($fi['status']??'')),['CANCELLED','DELETED'],true))continue;
        $fiRows[(string)$fi['id']]=$fi;
    }
    foreach((array)($store['exportReceipts']??[]) as $receipt){
        if(!is_array($receipt)||empty($receipt['id'])||!in_array($receipt['status']??'',['Posted','Accounts Approved / Posted'],true)||!empty($receipt['replacementReceiptId'])||($receipt['recordType']??'')==='FOREIGN_BANK_SHORTFALL_ADJUSTMENT')continue;
        $journal=$store['journals'][(string)($receipt['journalId']??'')]??[];
        if(($journal['status']??'')!=='Posted'||($journal['entity']??'')!==($receipt['entity']??''))continue;
        $receipts[(string)$receipt['id']]=$receipt;
    }
    foreach($fiRows as $fid=>$fi){
        $day=tt_fi_advice_day((string)($fi['date']??''));$payer=tt_fi_advice_name((string)($fi['customer']??''));
        $entity=strtoupper(trim((string)($fi['exporter']??'')));$currency=strtoupper(trim((string)($fi['currency']??'')));$amount=(int)round((float)($fi['value']??0)*100);
        if($day===null||$payer===''||!in_array($entity,['TTI','BRM'],true)||$amount<=0||!preg_match('/^[A-Z]{3}$/',$currency))continue;
        foreach($receipts as $rid=>$receipt){
            $rd=tt_fi_advice_day((string)($receipt['date']??''));$ra=(int)round((float)($receipt['foreignAmount']??0)*100);
            // USD tolerance is USD 10; other currencies require the same amount (one cent rounding).
            if($rd===null||abs($day-$rd)>2||$ra<=0||abs($amount-$ra)>($currency==='USD'?1000:1))continue;
            if(strtoupper((string)($receipt['entity']??''))!==$entity||strtoupper((string)($receipt['transactionCurrency']??''))!==$currency||tt_fi_advice_name((string)($receipt['remitter']??''))!==$payer)continue;
            $candidates[$fid][]=$rid;$reverse[$rid][]=$fid;
        }
    }
    foreach($fiRows as $fid=>$fi){
        $options=$candidates[$fid]??[];
        $links[$fid]=['status'=>$options?'Ambiguous':'Unmatched'];
        if(count($options)!==1||count($reverse[$options[0]]??[])!==1)continue;
        $receipt=$receipts[$options[0]];
        $links[$fid]=['status'=>'Matched','fiId'=>$fid,'fiNumber'=>(string)($fi['number']??''),'fiDate'=>(string)$fi['date'],'fiAmount'=>round((float)$fi['value'],2),'currency'=>(string)$fi['currency'],'receiptId'=>(string)$receipt['id'],'bankAdviceRef'=>(string)($receipt['bankAdviceRef']??''),'receiptDate'=>(string)$receipt['date'],'receiptAmount'=>round((float)$receipt['foreignAmount'],2),'amountDifference'=>round((float)$fi['value']-(float)$receipt['foreignAmount'],2),'dateDifferenceDays'=>tt_fi_advice_day((string)$fi['date'])-tt_fi_advice_day((string)$receipt['date'])];
    }
    return $links;
}
function tt_fi_advice_root(): array {
    $env=static function(string $name): string { $key='TT_'.$name;return defined($key)?(string)constant($key):(string)(getenv($key)?:''); };
    if($env('DB_HOST')!==''&&$env('DB_NAME')!==''&&$env('DB_USER')!==''){
        $db=new PDO('mysql:host='.$env('DB_HOST').';dbname='.$env('DB_NAME').';charset=utf8mb4',$env('DB_USER'),$env('DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $q=$db->prepare('SELECT payload FROM tt_operation_records WHERE storage_key=? LIMIT 1');$q->execute(['transtrade_export_v3_operational']);$payload=$q->fetchColumn();
    }else{
        $ops=tt_fi_advice_read_json(TT_DATA_DIR.'/operations.json');$payload=$ops['values']['transtrade_export_v3_operational']??'';
    }
    $root=is_string($payload)?json_decode($payload,true):null;return is_array($root)?$root:[];
}
function tt_fi_advice_read_json(string $path): array {
    if(!is_file($path))return [];
    $h=fopen($path,'r');if(!$h||!flock($h,LOCK_SH))throw new RuntimeException('FI tagging source unavailable.');
    try{$raw=stream_get_contents($h);}finally{flock($h,LOCK_UN);fclose($h);}
    $value=$raw?json_decode($raw,true):null;if(!is_array($value))throw new RuntimeException('FI tagging source unreadable.');return $value;
}
function tt_fi_advice_label(array $tag): string {
    if(($tag['status']??'')!=='Matched')return '';
    $text='FI '.$tag['fiNumber'].' · '.implode('-',array_reverse(explode('-',$tag['fiDate'])));
    if(abs((float)$tag['amountDifference'])>.001)$text.=' · FI/advice difference '.$tag['currency'].' '.number_format((float)$tag['amountDifference'],2,'.','');
    return $text;
}
function tt_fi_advice_project(array $store,array $root): array {
    $links=tt_fi_advice_matches($root,$store);$byReceipt=[];
    foreach($links as $tag)if(($tag['status']??'')==='Matched')$byReceipt[$tag['receiptId']]=$tag;
    foreach((array)($store['exportReceipts']??[]) as $key=>$receipt)if(is_array($receipt)){
        unset($receipt['fiTag']);if(isset($byReceipt[(string)($receipt['id']??'')]))$receipt['fiTag']=$byReceipt[(string)$receipt['id']];$store['exportReceipts'][$key]=$receipt;
    }
    foreach((array)($store['journals']??[]) as $key=>$journal)if(is_array($journal)){
        unset($journal['fiTag']);$rid=(string)($journal['meta']['receiptId']??'');if(($journal['status']??'')==='Posted'&&isset($byReceipt[$rid]))$journal['fiTag']=$byReceipt[$rid];$store['journals'][$key]=$journal;
    }
    return tt_receipt_invoice_project($store,$root,$links);
}
