<?php
declare(strict_types=1);

/** Bags are counted as whole units; never round an invoice into the PO allowance. */
function bqa_quantity(mixed $value):int {
    if(!is_numeric($value))throw new DomainException('Enter a positive whole number of bags.');
    $n=(float)$value;
    if(!is_finite($n)||$n<=0||floor($n)!==$n||$n>9007199254740991)throw new DomainException('Enter a positive whole number of bags.');
    return (int)$n;
}

/** All invoices reserve their PO quantity, including pending approvals, across both bag routes. */
function bqa_billed(array $store,string $po,string $line,string $exclude=''):array {
    $qty=0;$kg=0.0;$kgBags=0;
    foreach(['bagSupplierBills','nonWovenBagSupplierBills'] as $collection)foreach((array)($store[$collection]??[]) as $id=>$bill){
        if(!is_array($bill)||(string)($bill['id']??$id)===$exclude||in_array($bill['status']??'',['Cancelled','Deleted'],true))continue;
        if(($bill['poNo']??'')!==$po||($bill['lineKey']??'')!==$line)continue;
        $qty+=(int)($bill['bags']??0);
        if(($bill['billingBasis']??'BAG')==='KG'){$kgBags+=(int)($bill['bags']??0);$kg+=(float)($bill['totalWeightKg']??((float)($bill['bags']??0)*(float)($bill['bagWeightGrams']??0)/1000));}
    }
    return ['bags'=>$qty,'kg'=>round($kg,6),'kgBags'=>$kgBags];
}
function bqa_po_receipt_closure(array $po):array {
    $ordered=0;$received=0;$short=0;$keep=false;
    foreach((array)($po['lines']??[]) as $line){
        $o=max(0,(int)($line['orderedQty']??0));$r=max(0,(int)($line['receivedQty']??0));
        $ordered+=$o;$received+=$r;$short+=max(0,$o-$r);$keep=$keep||!empty($line['keepReceiptOpen']);
    }
    $closed=$ordered>0&&($short===0||($received>0&&$short<=20&&!$keep));
    return ['closed'=>$closed,'shortBags'=>$short,'reason'=>$closed?($short?'Closed short — within 20 bags':'Fully received'):'Open'];
}
function bqa_receipt_closure(array $line,?array $po=null):array {
    $state=bqa_po_receipt_closure($po??['lines'=>[$line]]);
    $ordered=max(0,(int)($line['orderedQty']??0));$received=max(0,(int)($line['receivedQty']??0));
    $short=max(0,$ordered-$received);$closed=$ordered>0&&($received>=$ordered||$state['closed']);
    return ['closed'=>$closed,'shortBags'=>$short,'reason'=>$closed?($short?'Closed short — within 20 bags':'Fully received'):'Open'];
}

/** Current Mill handoff IDs carry the line in field two, plus an optional MASTER suffix. */
function bqa_mill_line_key(array $order):string {
    $explicit=(string)($order['poLineKey']??'');
    if(preg_match('/^L[1-9][0-9]*(?:-MASTER)?$/',$explicit))return $explicit;
    $parts=explode('|',(string)($order['_ttBridgeId']??''));$index=$parts[1]??'';
    if(!ctype_digit($index)||(int)$index<1)return '';
    return 'L'.(int)$index.((!empty($order['isMasterBag'])||end($parts)==='MASTER')?'-MASTER':'');
}

/** Committed Mill evidence is authoritative even before the handoff snapshot catches up. */
function bqa_current_line(array $line,array $bill):array {
    if(!defined('TT_DATA_DIR'))return $line;
    $env=static fn($key)=>(string)(defined('TT_'.$key)?constant('TT_'.$key):(getenv('TT_'.$key)?:''));$mysql=$env('DB_HOST')!==''&&$env('DB_NAME')!==''&&$env('DB_USER')!=='';
    if(!$mysql&&!is_file(TT_DATA_DIR.'/operations.json'))return $line;
    static $ops=null;
    if($ops===null){require_once __DIR__.'/bag_transfers_store.php';$ops=bts_with_sources(false,[],static fn($values,$state,$now)=>['values'=>$values]);}
    require_once __DIR__.'/bag_receipt_quantities.php';
    $decode=static function(string $key)use($ops):array{$v=$ops['values'][$key]??[];if(is_string($v))$v=json_decode($v,true,512,JSON_THROW_ON_ERROR);return is_array($v)?$v:[];};
    require_once __DIR__.'/bag_inventory_core.php';$poSources=bti_po_sources($decode('transtrade_export_v3_operational'));
    $orders=$decode('tt30bags');$allReceipts=$decode('tt33bagreceipts');$movementState=$decode('tt41bagtransfers');$allDispositions=(array)($movementState['receiptDispositions']??[]);
    $flags=['printing'=>false,'material'=>false,'quality'=>false,'tareMismatch'=>false,'exception'=>false];$found=false;$received=0;$measured=0.;$weight=0.;$keep=false;$evidence=[];$dispositionEvidence=[];$ids=[];
    foreach($orders as $order)if(is_array($order))$ids[(string)($order['id']??'')]=($ids[(string)($order['id']??'')]??0)+1;
    foreach($orders as $order){
        if(!is_array($order)||($order['_ttBridge']??'')!=='exports'||($order['poNo']??'')!==($bill['poNo']??'')||bqa_mill_line_key($order)!==($bill['lineKey']??''))continue;
        $candidates=array_values($poSources[(string)$order['poNo'].'|'.bqa_mill_line_key($order)]??[]);$owner=(string)($order['entity']??$order['bagReceiptScope']['entity']??(count($candidates)===1?$candidates[0]['entity']:''));
        if($owner===''&&!empty($order['received']))throw new DomainException('Receipt stock ownership requires reconciliation before billing.');if($owner!==($bill['entity']??''))continue;
        $id=(string)($order['id']??'');if($id===''||($ids[$id]??0)!==1)throw new DomainException('Receipt identities require reconciliation before billing.');$found=true;
        $receipts=array_values(array_filter($allReceipts,static fn($r)=>(string)($r['bagId']??'')===$id));$receiptIds=array_column($receipts,'id');$dispositions=array_values(array_filter($allDispositions,static fn($r)=>in_array($r['receiptId']??'',$receiptIds,true)));$returned=0;
        $keep=$keep||!empty($order['keepReceiptOpen']);$flags['exception']=$flags['exception']||!empty($order['exceptionAlert']);
        foreach(['printing'=>'printingMismatch','material'=>'materialMismatch','quality'=>'qualityMismatch','tareMismatch'=>'tareMismatch'] as $out=>$in)$flags[$out]=$flags[$out]||!empty($order[$in]);$flags['tareMismatch']=$flags['tareMismatch']||!empty($order['actualTare']);
        foreach($receipts as $r){
            $position=br_position($r,$dispositions);$returned+=$position['returnedQty'];$grams=(float)($r['actualTareGrams']??0);$net=$position['netReceivedQty'];
            if($grams>0){$measured+=$net;$weight+=$net*$grams/1000;}
            foreach($position['events'] as $event)if($event['action']==='accept_corrected'&&(float)($event['actualTareGrams']??0)>0){$count=(int)$event['qty'];if($grams>0)$weight-=$count*$grams/1000;else $measured+=$count;$weight+=$count*(float)$event['actualTareGrams']/1000;}
            if(empty($r['bagReceiptVersion'])||$position['quarantinedQty']>0)foreach(['printing'=>'printingMismatch','material'=>'materialMismatch','quality'=>'qualityMismatch','tareMismatch'=>'tareMismatch'] as $out=>$in)$flags[$out]=$flags[$out]||!empty($r[$in]);
        }
        $counter=bti_count($order['received']??null);if($counter===null||$returned>$counter)throw new DomainException('Actual receipt and supplier return quantities need reconciliation before billing.');$received+=$counter-$returned;
        $evidence[]=['orderId'=>$id,'receipts'=>$receipts];array_push($dispositionEvidence,...$dispositions);if(!isset($line['agreedTareGrams']))$line['agreedTareGrams']=(float)($order['tare']??0);
    }
    if($found){$line['keepReceiptOpen']=$keep;$line['receivedQty']=$received;$line['acceptance']=$flags+['receivedWeightKg'=>$measured+.0001>=$received&&$received>0?round($weight,6):null,'weightComplete'=>$measured+.0001>=$received,'receiptEvidence'=>$evidence,'physicalDispositions'=>$dispositionEvidence];}

    return $line;
}

/** Physical acceptance is independent of the supplier's invoice rate. */
function bqa_assess(array $store,array $bill):array {
    if(!empty($bill['historical'])||($bill['billPurpose']??'EXPORT')==='GENERAL')return ['issues'=>[],'fingerprint'=>'','receivedWeightKg'=>null];
    $po=(array)($store['bagPurchaseOrders'][$bill['poNo']??'']??[]);$line=[];
    foreach((array)($po['lines']??[]) as $row)if(($row['lineKey']??'')===($bill['lineKey']??'')){$line=$row;break;}
    if(!$line||(!empty($po['entity'])&&($po['entity']??'')!==($bill['entity']??'')))return ['issues'=>[],'fingerprint'=>'','receivedWeightKg'=>null];
    $line=bqa_current_line($line,$bill);$acceptance=(array)($line['acceptance']??[]);$issues=[];$prior=bqa_billed($store,(string)($bill['poNo']??''),(string)($bill['lineKey']??''),(string)($bill['id']??''));$cumulative=$prior['bags']+(int)($bill['bags']??0);if($cumulative>(int)($line['orderedQty']??0))$issues[]='Cumulative billed bags '.$cumulative.' exceed PO quantity '.(int)($line['orderedQty']??0);if($cumulative>(int)($line['receivedQty']??0))$issues[]='Cumulative billed bags '.$cumulative.' exceed actual bags received '.(int)($line['receivedQty']??0);
    foreach(['printing'=>'Printing / artwork differs from the order','material'=>'Bag material differs from the order','quality'=>'Bag quality is not accepted'] as $key=>$message)if(!empty($acceptance[$key]))$issues[]=$message;
    if(!empty($acceptance['exception'])&&!array_filter($issues)&&empty($acceptance['tareMismatch']))$issues[]='Mill receipt has an unresolved bag specification discrepancy';
    $received=(float)($line['receivedQty']??0);$weight=isset($acceptance['receivedWeightKg'])?(float)$acceptance['receivedWeightKg']:null;
    if(!empty($acceptance['tareMismatch'])&&$weight===null)$issues[]='Actual received bag weight is missing; reconcile the Mill receipt';
    $actual=(float)($acceptance['actualTareGrams']??($received>0&&$weight!==null?$weight*1000/$received:0));$agreed=(float)($line['agreedTareGrams']??0);
    if($weight===null&&$actual>0)$weight=round($received*$actual/1000,6);
    $invoiceKg=($bill['billingBasis']??'BAG')==='KG'?(float)($bill['totalWeightKg']??((float)($bill['bags']??0)*(float)($bill['bagWeightGrams']??0)/1000)):null;
    // Reserve physical weight for per-bag invoices too. Without lot allocation, use the
    // measured average and hold a heavier individual invoice for explicit review.
    $averageKg=$weight!==null&&$received>0?$weight/$received:null;
    $allowedKg=$averageKg!==null?max(0,min($weight-$prior['kg']-max(0,$prior['bags']-$prior['kgBags'])*$averageKg,(int)($bill['bags']??0)*$averageKg)):null;
    if($invoiceKg!==null&&$allowedKg!==null&&$invoiceKg>$allowedKg+.0005)$issues[]='Invoice weight '.number_format($invoiceKg,3,'.','').' kg exceeds actual received weight '.number_format($allowedKg,3,'.','').' kg';
    if(!empty($acceptance['tareMismatch'])&&($actual<=0||$agreed<=0||$actual>=$agreed||$invoiceKg===null))$issues[]='Tare weight differs from the PO; supplier settlement requires review';
    $fingerprint=hash('sha256',json_encode([$bill['poNo']??'',$bill['lineKey']??'',$bill['sellerInvoice']??'',$bill['bags']??0,$bill['billingBasis']??'BAG',$bill['bagWeightGrams']??0,$bill['baseAmount']??0,$bill['totalAmount']??0,$line['agreedTareGrams']??null,$line['orderedQty']??0,$line['receivedQty']??0,$prior,$acceptance,$issues],JSON_THROW_ON_ERROR));
    return ['issues'=>$issues,'fingerprint'=>$fingerprint,'receivedWeightKg'=>$allowedKg];
}

function bqa_approved(array $bill,array $assessment):bool {
    return !empty($bill['acceptanceApprovedAt'])&&hash_equals((string)($bill['acceptanceApprovedFingerprint']??''),(string)$assessment['fingerprint']);
}

function bqa_hold(array $store,array $bill):?string {
    $a=bqa_assess($store,$bill);
    return $a['issues']&&!bqa_approved($bill,$a)?'Director approval required: '.implode('; ',$a['issues']).'. Contact the bag supplier and record the settlement before requesting approval.':null;
}

function bqa_approve(array &$store,string $collection,string $id,array $user,string $reason,string $expectedFingerprint):void {
    if(strlen(trim($reason))<5)throw new DomainException('Record the supplier settlement and approval reason (at least 5 characters).');
    $bill=$store[$collection][$id]??null;if(!is_array($bill)||!empty($bill['journalId']))throw new DomainException('Choose an unposted bag bill.');
    $assessment=bqa_assess($store,$bill);if($expectedFingerprint===''||!hash_equals($assessment['fingerprint'],$expectedFingerprint))throw new DomainException('The bill or receipt changed. Reopen the approval and review the current discrepancy.');if(!$assessment['issues'])throw new DomainException('No bag specification, quantity or weight exception currently requires approval. PO rates are not a posting condition.');
    $actor=(string)($user['full_name']??$user['username']??'');
    $store[$collection][$id]['acceptanceApprovedAt']=gmdate('c');
    $store[$collection][$id]['acceptanceApprovedBy']=$actor;
    $store[$collection][$id]['acceptanceApprovedFingerprint']=$assessment['fingerprint'];
    $store[$collection][$id]['acceptanceApprovalReason']=trim($reason);
    $store['bagAcceptanceAudit'][]=['billId'=>$id,'collection'=>$collection,'actor'=>$actor,'userId'=>$user['id']??null,'reason'=>trim($reason),'issues'=>$assessment['issues'],'fingerprint'=>$assessment['fingerprint'],'at'=>gmdate('c')];
}
