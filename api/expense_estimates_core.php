<?php
declare(strict_types=1);
/** Saved management forecasts. Never posts invoices, tax claims, stock or payments. */
function ee_key(string $v):string { return strtolower(preg_replace('/[^\p{L}\p{N}]/u','',$v)??''); }
function ee_packing(string $v):string { return preg_replace('/kgs?$/','',ee_key($v))??''; }
function ee_num(mixed $v,string $label,float $max=100000000000):float {
    if(!is_numeric($v)||!is_finite((float)$v)||(float)$v<0||(float)$v>$max)throw new InvalidArgumentException($label.' is invalid.');
    return (float)$v;
}
function ee_text(array $b,string $key,int $limit=180):string {
    if(isset($b[$key])&&!is_scalar($b[$key]))throw new InvalidArgumentException('Invalid '.$key.'.');
    $v=trim((string)($b[$key]??''));if(strlen($v)>$limit)throw new InvalidArgumentException($key.' is too long.');return $v;
}
/** Only source documents backed by current posted journals are evidence. */
function ee_actuals(array $s,string $entity,?array $user=null):array {
    if($user!==null){
        require_once __DIR__.'/bag_read_scope.php';
        $s=bag_read_scope($s,$user);
        if(!os_location($user,''))$s['nonWovenBagSupplierBills']=array_filter((array)($s['nonWovenBagSupplierBills']??[]),static fn($b)=>isset($s['bagPurchaseOrders'][$b['poNo']??'']));
    }
    $out=[];
    foreach(['supplierBills','bagSupplierBills','nonWovenBagSupplierBills','otherPurchases'] as $collection)foreach((array)($s[$collection]??[]) as $id=>$b){
        if($user!==null){$icon=$collection!=='supplierBills'?'purchases':(!empty($b['expenseInvoice'])?'expenses':match($b['category']??''){'FREIGHT'=>'freight','TRANSPORT'=>'transport','BROKERAGE'=>'brokerage',default=>'services'});if(!tt_user_can_module_action($user,'Accounts',$icon,'View')&&!($collection==='supplierBills'&&tt_user_can_module_action($user,'Accounts','supplier','View')))continue;}
        if(!is_array($b)||($b['entity']??'')!==$entity||!in_array($b['status']??'',['Posted','Outstanding','Settled','Partially Paid','Paid'],true)||!empty($b['isEstimate']))continue;
        $posts=array_values(array_unique(array_filter(array_merge([(string)($b['journalId']??'')],(array)($b['postingJournalIds']??[])))));
        $live=array_values(array_filter($posts,static fn($id)=>($s['journals'][$id]['status']??'')==='Posted'&&empty($s['journals'][$id]['meta']['deletedFromBooks'])&&empty($s['journals'][$id]['reversalOf'])&&empty($s['journals'][$id]['meta']['reversalOf'])&&empty($s['journals'][$id]['amendedByPostId'])&&empty($s['journals'][$id]['reversedByPostId'])));
        if(!$live)continue;
        $origin=(array)($b['sourceRecord']??[]);$meta=(array)($s['journals'][end($live)]['meta']??[]);
        $currency=strtoupper((string)($b['transactionCurrency']??$b['currency']??$origin['currency']??$meta['transactionCurrency']??($entity==='TG'?'':'PKR')));
        if(!in_array($currency,['PKR','USD','AED'],true))continue; // Do not guess a foreign bill's currency.
        $amount=round((float)($b['totalAmount']??$b['supplierPayableTotal']??$b['amount']??0),2);
        if($entity==='TG'&&isset($b['transactionAmount']))$amount=round((float)$b['transactionAmount'],2);
        if($amount<=0||!is_finite($amount))continue;
        $quantity=(float)($b['bags']??$b['quantity']??$origin['quantity']??1);if($quantity<=0)$quantity=1;
        $tax=(float)($b['gstRate']??$b['taxRate']??0);
        $base=(float)($b['baseAmount']??($amount/(1+$tax/100)));
        $rate=$base/$quantity; // Derive a per-unit rate; never use a full bill as a unit price.
        $date=(string)($b['sellerInvoiceDate']??$b['billDate']??$b['invoiceDate']??$b['date']??'');
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))continue;
        $out[]=['key'=>$collection.'|'.$id,'supplier'=>(string)($b['supplier']??$b['vendor']??$b['payee']??$b['broker']??''),'reference'=>(string)($b['sellerInvoice']??$b['billNo']??$b['invoiceNo']??$id),'date'=>$date,'currency'=>$currency,'packingSize'=>(string)($b['packingSize']??''),'specification'=>(string)($b['bagType']??$b['category']??'OTHER'),'bagType'=>(string)($b['bagType']??''),'amount'=>$amount,'quantity'=>$quantity,'unitRate'=>round($rate,8),'taxRate'=>$tax,'whtRate'=>isset($b['whtRate'])?(float)$b['whtRate']:null,'journalIds'=>$live];
    }
    usort($out,static fn($a,$b)=>strcmp($b['date'],$a['date'])?:strcmp($b['key'],$a['key']));return $out;
}
function ee_actual(array $s,array $r,?array $user=null):?array {
    foreach(ee_actuals($s,$r['entity'],$user) as $a)if($a['key']===($r['actualBillKey']??'')&&ee_key($a['supplier'])===ee_key($r['supplier'])&&$a['currency']===$r['currency'])return $a;
    return null;
}
function ee_payload(array $s,string $entity,?array $user=null):array {
    $history=ee_actuals($s,$entity,$user);$visible=array_fill_keys(array_column($history,'key'),true);
    $rows=[];foreach((array)($s['estimatedExpenseBills']??[]) as $r)if(($r['entity']??'')===$entity){
        if($r['status']==='Replaced'){$a=ee_actual($s,$r);$r['replacementValid']=$a!==null;$r['actualBillAmount']=$a['amount']??null;$r['variance']=$a?round($a['amount']-$r['grossAmount'],2):null;}
        // A saved proof snapshot must not bypass the source workflow's read rights.
        if($user!==null&&!empty($r['rateSource'])&&!isset($visible[$r['rateSource']])){unset($r['rateSourceSnapshot']);$r['rateSourceRestricted']=true;}
        if($user!==null&&!empty($r['actualBillKey'])&&!isset($visible[$r['actualBillKey']])){unset($r['actualBillSnapshot']);$r['actualBillAmount']=null;$r['variance']=null;$r['replacementRestricted']=!empty($r['replacementValid']);}
        $rows[]=$r;
    }
    return ['estimatedBills'=>$rows,'estimateRateHistory'=>$history];
}
function ee_save(array &$s,string $entity,array $b,array $u):array {
    $id=ee_text($b,'estimateId');$old=$id!==''?($s['estimatedExpenseBills'][$id]??null):null;
    if($id!==''&&(!$old||$old['entity']!==$entity||$old['status']!=='Open'||(int)($b['version']??0)!==$old['version']))throw new InvalidArgumentException('Estimate changed. Reopen it before saving.');
    $party=ee_text($b,'supplier');$ref=ee_text($b,'reference');$purpose=ee_text($b,'purpose',500);
    $shipmentId=ee_text($b,'shipmentId',180);$expenseKind=ee_text($b,'expenseKind',80);$sourceKey=ee_text($b,'sourceKey',240);
    if($party===''||$ref===''||$purpose==='')throw new InvalidArgumentException('Enter supplier, unique shipment / cost reference and expense description.');
    $date=ev1_date(ee_text($b,'date',10));$currency=strtoupper(ee_text($b,'currency',3));if(!in_array($currency,['PKR','USD','AED'],true))throw new InvalidArgumentException('Choose PKR, USD or AED.');
    $quantity=ee_num($b['quantity']??null,'Quantity');$rate=ee_num($b['unitRate']??null,'Unit rate');if($quantity<=0||$rate<=0)throw new InvalidArgumentException('Quantity and rate must be positive.');
    $tax=ee_num($b['taxRate']??null,'Tax rate',100);$wht=ee_num($b['whtRate']??null,'WHT rate (enter 0 if not applicable)',99.99);
    $basis=ee_text($b,'rateBasis',10);if(!in_array($basis,['GROSS','NET'],true))throw new InvalidArgumentException('Choose the rate basis.');
    $whtBase=ee_text($b,'whtBase',10);if(!in_array($whtBase,['TOTAL','BASE'],true))throw new InvalidArgumentException('Choose whether WHT applies to the full amount or the amount before sales tax.');
    $factor=1+$tax/100;$whtFactor=$wht/100/($whtBase==='BASE'?$factor:1);
    $gross=round($basis==='NET'?($quantity*$rate)/(1-$whtFactor):($quantity*$rate)*$factor,2);
    if(!is_finite($gross)||$gross<=0||$gross>100000000000)throw new InvalidArgumentException('Estimate exceeds the amount limit.');
    $whtAmount=round($gross*$whtFactor,2);$source=ee_text($b,'rateSource');$proof=null;$packing=ee_text($b,'packingSize',80);$spec=ee_text($b,'specification',80);
    if($source!==''){
        foreach(ee_actuals($s,$entity,$u) as $r)if($r['key']===$source)$proof=$r;
        if(!$proof||ee_key($proof['supplier'])!==ee_key($party)||$proof['currency']!==$currency||$proof['date']>$date||ee_packing($proof['packingSize'])!==ee_packing($packing)||ee_key($proof['specification'])!==ee_key($spec)||$basis!=='GROSS'||abs($proof['unitRate']-$rate)>0.00000001||abs($proof['taxRate']-$tax)>0.00000001)throw new InvalidArgumentException('Rate source must match supplier, company, currency, packing, specification and date. Suggest again or clear the source for a manual rate.');
    }
    foreach((array)($s['estimatedExpenseBills']??[]) as $key=>$r)if((string)$key!==$id&&$r['entity']===$entity&&$r['status']!=='Cancelled'&&ee_key($r['supplier'])===ee_key($party)&&ee_key($r['reference'])===ee_key($ref))throw new InvalidArgumentException('An estimate already exists for this supplier and cost reference.');
    if($sourceKey!=='')foreach((array)($s['estimatedExpenseBills']??[]) as $key=>$r)if((string)$key!==$id&&$r['entity']===$entity&&$r['status']!=='Cancelled'&&($r['sourceKey']??'')===$sourceKey)throw new InvalidArgumentException('This source already has an estimate. Open its existing estimate.');
    if($id==='')$id=ev1_id((array)($s['estimatedExpenseBills']??[]),'EST');
    $record=['id'=>$id,'entity'=>$entity,'supplier'=>$party,'reference'=>$ref,'purpose'=>$purpose,'shipmentId'=>$shipmentId,'expenseKind'=>$expenseKind,'sourceKey'=>$sourceKey,'date'=>$date,'currency'=>$currency,'quantity'=>$quantity,'packingSize'=>$packing,'specification'=>$spec,'unitRate'=>$rate,'rateBasis'=>$basis,'taxRate'=>$tax,'whtRate'=>$wht,'whtBase'=>$whtBase,'grossAmount'=>$gross,'whtAmount'=>$whtAmount,'supplierNet'=>round($gross-$whtAmount,2),'rateSource'=>$source,'rateSourceSnapshot'=>$proof,'status'=>'Open','version'=>($old['version']??0)+1,'createdAt'=>$old['createdAt']??gmdate('c'),'updatedAt'=>gmdate('c')];
    $s['estimatedExpenseBills'][$id]=$record;ee_audit($s,$u,'SAVE_ESTIMATED_BILL',$old,$record);return ['estimateId'=>$id];
}
function ee_audit(array &$s,array $u,string $action,mixed $before,array $after):void {
    $s['expenseAudit'][]=['action'=>$action,'entity'=>$after['entity'],'id'=>$after['id'],'before'=>$before,'after'=>$after,'by'=>$u['username']??'Accounts','at'=>gmdate('c')];
}
function ee_resolve(array &$s,string $entity,array $b,array $u):array {
    $id=ee_text($b,'estimateId');$old=$s['estimatedExpenseBills'][$id]??null;
    if(!$old||$old['entity']!==$entity||!in_array($old['status'],['Open','Replaced'],true)||(int)($b['version']??0)!==$old['version'])throw new InvalidArgumentException('Estimate changed. Reopen it.');
    $key=ee_text($b,'actualBillKey');$actual=ee_actual($s,array_replace($old,['actualBillKey'=>$key]),$u);
    if(!$actual)throw new InvalidArgumentException('Select a current posted bill for the same supplier, company and currency.');
    $reason=ee_text($b,'resolutionNote',500);if(strlen($reason)<5)throw new InvalidArgumentException('Record how this actual bill replaces the estimated cost.');
    foreach((array)($s['estimatedExpenseBills']??[]) as $other)if(($other['id']??'')!==$id&&($other['actualBillKey']??'')===$key&&$other['status']==='Replaced')throw new InvalidArgumentException('This actual bill already replaces another estimate.');
    $s['estimatedExpenseBills'][$id]=array_replace($old,['status'=>'Replaced','actualBillKey'=>$key,'actualBillDate'=>$actual['date'],'actualBillAmount'=>$actual['amount'],'actualBillSnapshot'=>$actual,'resolutionNote'=>$reason,'variance'=>round($actual['amount']-$old['grossAmount'],2),'version'=>$old['version']+1,'updatedAt'=>gmdate('c')]);
    ee_audit($s,$u,'REPLACE_ESTIMATED_BILL',$old,$s['estimatedExpenseBills'][$id]);return ['estimateId'=>$id];
}
function ee_cancel(array &$s,string $entity,array $b,array $u):array {
    $id=ee_text($b,'estimateId');$old=$s['estimatedExpenseBills'][$id]??null;
    if(!$old||$old['entity']!==$entity||$old['status']==='Cancelled'||(int)($b['version']??0)!==$old['version'])throw new InvalidArgumentException('Estimate changed. Reopen it.');
    $reason=ee_text($b,'resolutionNote',500);if(strlen($reason)<5)throw new InvalidArgumentException('Enter a cancellation reason of at least 5 characters.');
    $date=ev1_date(ee_text($b,'cancelDate',10));if($date<$old['date'])throw new InvalidArgumentException('Cancellation cannot precede the estimate.');
    $s['estimatedExpenseBills'][$id]=array_replace($old,['status'=>'Cancelled','cancelDate'=>$date,'resolutionNote'=>$reason,'version'=>$old['version']+1,'updatedAt'=>gmdate('c')]);ee_audit($s,$u,'CANCEL_ESTIMATED_BILL',$old,$s['estimatedExpenseBills'][$id]);return ['estimateId'=>$id];
}
function ee_cashflow(array $source,array $s,string $date):array {
    foreach((array)($s['estimatedExpenseBills']??[]) as $r){
        if($r['date']>$date||($r['status']==='Cancelled'&&$r['cancelDate']<=$date))continue;
        $actual=!empty($r['actualBillKey'])?ee_actual($s,$r):null;
        if($actual&&$actual['date']<=$date)continue;
        if($r['status']==='Replaced'&&!$actual)$source['warnings'][]=$r['supplier'].' · '.$r['reference'].' — replacement bill is no longer posted; estimate included pending review.';
        // Keep native accrued/unbilled balances visible. Require review before adding
        // a forecast for the same party; never silently double-count their liability.
        $overlap=false;foreach($source['rows'] as $existing)if(empty($existing['excluded'])&&empty($existing['estimateId'])&&empty($existing['sourceKind'])&&!empty($existing['postIds'])&&($existing['account']??'')==='2210'&&$existing['entity']===$r['entity']&&$existing['currency']===$r['currency']&&ee_key((string)($existing['party']??''))===ee_key($r['supplier'])&&($existing['amount']??0)<0)$overlap=true;
        $reason=$overlap?'Existing accrued / unbilled liability for this supplier. Reconcile its coverage before including a separate forecast.':'';
        if($overlap)$source['warnings'][]=$r['supplier'].' · '.$r['reference'].' — '.$reason;
        $row=['id'=>'ESTIMATE-'.$r['id'],'type'=>'FINANCIAL','entity'=>$r['entity'],'account'=>'2210','party'=>$r['supplier'],'label'=>mb_strcut($r['supplier'].' — '.$r['reference'].' · '.$r['purpose'],0,280,'UTF-8'),'currency'=>$r['currency'],'originalCurrency'=>$r['currency'],'amount'=>-$r['grossAmount'],'originalAmount'=>-$r['grossAmount'],'cashflowSection'=>'estimates','source'=>'Estimated expense bill','sourceDate'=>$r['date'],'postIds'=>[],'excluded'=>$overlap,'reason'=>$reason,'estimateId'=>$r['id'],'shipmentId'=>$r['shipmentId']??'','expenseKind'=>$r['expenseKind']??'','sourceKey'=>$r['sourceKey']??'','sourceKind'=>'MANUAL','quantity'=>$r['quantity']??null,'unitRate'=>$r['unitRate']??null,'taxRate'=>$r['taxRate']??null,'whtAmount'=>$r['whtAmount'],'supplierNet'=>$r['supplierNet']??null,'grossAmount'=>$r['grossAmount'],'rateSource'=>$r['rateSource']??'','basis'=>'Gross forecast includes supplier net plus WHT; no invoice or payment.'];
        $row['sourceFingerprint']=hash('sha256',json_encode([$r,$actual,$overlap],JSON_THROW_ON_ERROR));$source['rows'][]=$row;
    }
    $source['warnings']=array_values(array_unique($source['warnings']??[]));return $source;
}
