<?php
declare(strict_types=1);
require_once __DIR__.'/bag_receipt_quantities.php';

/** Read-only order/receipt projection. Invoice dates are never receipt dates. */
function bor_date(mixed $value):string {
    $date=substr(trim((string)$value),0,10);
    $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    return $parsed&&$parsed->format('Y-m-d')===$date?$date:'';
}
/** Issued timestamps are stored in UTC; reporting months follow Karachi business dates. */
function bor_issue_date(mixed $value):string {
    $text=trim((string)$value);$date=bor_date($text);if($date===''||!str_contains($text,'T'))return $date;
    try{return (new DateTimeImmutable($text,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Karachi'))->format('Y-m-d');}catch(Throwable){return '';}
}
/** Recover the first issue date from the saved PO versions, without changing either source. */
function bor_original_dates(array $orders,array $exports,string $entity):array {
    $contracts=[];foreach((array)($exports['contracts']??[]) as $contract)if(is_array($contract))$contracts[(string)($contract['ref']??'')]=$contract;
    $dates=[];
    foreach((array)($exports['shipments']??[]) as $shipment){
        if(!is_array($shipment))continue;$contract=$contracts[(string)($shipment['contractRef']??'')]??[];
        $seller=strtoupper((string)($shipment['seller']??$contract['seller']??'TTI'));$owner=$seller==='TG'?strtoupper((string)($shipment['customs']['exporter']??$contract['customsExporter']??'')):$seller;
        if($owner!==$entity)continue;
        foreach((array)($shipment['bagOrders']??[]) as $po){
            if(!is_array($po))continue;$key=(string)($po['poNo']??'').'|'.strtolower(trim((string)($po['supplier']??'')));$candidates=[bor_issue_date($po['originalIssuedAt']??$po['issuedAt']??'')];
            foreach((array)($po['versions']??[]) as $version)if(is_array($version))$candidates[]=bor_issue_date($version['snapshot']['issuedAt']??'');
            $candidates=array_values(array_filter($candidates));if($candidates){sort($candidates);$dates[$key]=isset($dates[$key])?min($dates[$key],$candidates[0]):$candidates[0];}
        }
    }
    foreach($orders as &$po){if(!is_array($po)||($po['entity']??'TTI')!==$entity)continue;$key=(string)($po['poNo']??'').'|'.strtolower(trim((string)($po['supplier']??'')));if(isset($dates[$key]))$po['originalIssuedAt']=$dates[$key];}unset($po);return $orders;
}
function bor_report(array $orders,array $millOrders,array $receipts,string $entity,array $filters,array $dispositions=[]):array {
    $from=$filters['from'];$to=$filters['to'];$basis=$filters['basis']??'activity';$rows=[];$warnings=[];$totals=[];
    $inside=static fn($date)=>$date!==''&&$date>=$from&&$date<=$to;
    $normal=static fn($value)=>strtolower(trim((string)$value));
    $receiptIndex=[];$receiptIds=[];
    foreach($receipts as $receipt){
        if(!is_array($receipt))continue;$bagId=(string)($receipt['bagId']??'');$id=(string)($receipt['id']??'');
        if($bagId===''||!is_numeric($receipt['qty']??null)||(float)$receipt['qty']<0)continue;
        $key=$bagId.'|'.$id;if($id!==''&&isset($receiptIds[$key])){if($receiptIds[$key]!==$receipt)$warnings['duplicate-receipt']='Conflicting duplicate receipt IDs require source review.';continue;}
        if($id!=='')$receiptIds[$key]=$receipt;$receiptIndex[$bagId][]=$receipt;
    }
    $lineIndex=[];
    foreach($millOrders as $order){
        if(!is_array($order)||($order['_ttBridge']??'')!=='exports'||empty($order['poNo']))continue;
        $parts=explode('|',(string)($order['_ttBridgeId']??''));$n=end($parts);
        if(!ctype_digit((string)$n)||(int)$n<1)continue;
        $key=(string)$order['poNo'].'|L'.(int)$n;$lineIndex[$key][]=$order;
    }
    foreach($orders as $po){
        if(!is_array($po)||($po['entity']??'TTI')!==$entity)continue;
        foreach((array)($po['lines']??[]) as $line){
            if(!is_array($line))continue;
            $row=['poNo'=>(string)($po['poNo']??''),'lineKey'=>(string)($line['lineKey']??''),'orderDate'=>bor_issue_date($po['originalIssuedAt']??$po['issuedAt']??''),'supplier'=>(string)($po['supplier']??''),'brand'=>(string)($line['brand']??''),'bagType'=>(string)($line['bagType']??''),'packingSize'=>(string)($line['packingSize']??''),'shipment'=>(string)(($po['contractRef']??'')?:($po['shipmentRef']??''))];
            foreach(['supplier','brand','bagType','packingSize'] as $field)if(($filters[$field]??'')!==''&&$normal($row[$field])!==$normal($filters[$field]))continue 2;
            if(($filters['q']??'')!==''&&!str_contains($normal($row['poNo'].' '.$row['shipment']),$normal($filters['q'])))continue;
            $ordered=max(0,(float)($line['orderedQty']??0));$datedAll=0.0;$periodReceived=0.0;$throughEnd=0.0;$undatedEvents=0.0;$seenBagIds=[];
            $returnedCurrent=0.0;$returnedThroughEnd=0.0;$returnedInPeriod=0.0;$quarantinedCurrent=0.0;
            foreach($lineIndex[$row['poNo'].'|'.$row['lineKey']]??[] as $mill){
                $bagId=(string)($mill['id']??'');if(isset($seenBagIds[$bagId])){$warnings['duplicate-bag']='Duplicate Mill bag-order IDs require source review.';continue;}$seenBagIds[$bagId]=true;
                foreach($receiptIndex[$bagId]??[] as $receipt){
                    $qty=(float)$receipt['qty'];$date=bor_date($receipt['date']??'');
                    if(!empty($receipt['bagReceiptVersion'])){
                        $position=br_position($receipt,$dispositions);$cutoff=br_position($receipt,$dispositions,$to);
                        $returnedCurrent+=$position['returnedQty'];$returnedThroughEnd+=$cutoff['returnedQty'];$quarantinedCurrent+=$position['quarantinedQty'];
                        foreach($position['events'] as $event)if($event['action']==='return_supplier'&&$inside($event['date']))$returnedInPeriod+=$event['qty'];
                    }
                    if($date===''){$undatedEvents+=$qty;continue;}$datedAll+=$qty;if($inside($date))$periodReceived+=$qty;if($date<=$to)$throughEnd+=$qty;
                }
            }
            $snapshot=max(0,(float)($line['receivedQty']??0));
            foreach($lineIndex[$row['poNo'].'|'.$row['lineKey']]??[] as $mill)$snapshot=max($snapshot,(float)($mill['received']??0));
            $grossCurrent=max($snapshot,$datedAll+$undatedEvents);$undated=max($undatedEvents,$grossCurrent-$datedAll);$currentReceived=$grossCurrent-$returnedCurrent;
            if($currentReceived<0||$returnedThroughEnd>$throughEnd)throw new DomainException('Supplier returns exceed the linked physical receipt evidence.');
            $orderInPeriod=$inside($row['orderDate']);
            if($row['orderDate']==='')$warnings['order-date']='Some orders have no valid issue date; they cannot be assigned to an order month.';
            if($basis==='orders'&&!$orderInPeriod)continue;
            if($basis==='activity'&&!$orderInPeriod&&$periodReceived<=0&&$returnedInPeriod<=0)continue;
            $row+=['ordered'=>$ordered,'orderedInPeriod'=>$orderInPeriod?$ordered:0.0,'receivedInPeriod'=>$periodReceived,'receivedThroughEnd'=>$throughEnd,'netReceivedThroughEnd'=>$throughEnd-$returnedThroughEnd,'returnedInPeriod'=>$returnedInPeriod,'returnedCurrent'=>$returnedCurrent,'quarantinedCurrent'=>$quarantinedCurrent,'receivedCurrent'=>$currentReceived,'outstandingCurrent'=>max(0,$ordered-$currentReceived),'undatedReceived'=>$undated];
            $rows[]=$row;foreach(['ordered','orderedInPeriod','receivedInPeriod','receivedThroughEnd','netReceivedThroughEnd','returnedInPeriod','returnedCurrent','quarantinedCurrent','receivedCurrent','outstandingCurrent','undatedReceived'] as $key)$totals[$key]=($totals[$key]??0)+$row[$key];
        }
    }
    usort($rows,static fn($a,$b)=>strcmp($a['orderDate'],$b['orderDate'])?:strnatcasecmp($a['poNo'],$b['poNo'])?:strcmp($a['lineKey'],$b['lineKey']));
    if(($totals['undatedReceived']??0)>0)$warnings['receipt-date']='Undated prior receipts are excluded from period receipts and received-through-end figures. Current balance includes them.';
    return ['ok'=>true,'entity'=>$entity,'filters'=>$filters,'rows'=>$rows,'totals'=>$totals,'warnings'=>array_values($warnings)];
}
