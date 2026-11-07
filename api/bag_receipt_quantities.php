<?php
declare(strict_types=1);
require_once __DIR__.'/bag_transfer_core.php';

/** Keep original receipt evidence immutable; dated dispositions account for quarantined quantities. */
function br_position(array $receipt,array $events,?string $asOf=null): array {
    $qty=bt_qty($receipt['qty']??null);$versioned=!empty($receipt['bagReceiptVersion']);
    $accepted=$versioned?($receipt['acceptedQty']??null):$qty;$quarantined=$versioned?($receipt['quarantinedQty']??null):0;
    foreach([$accepted,$quarantined] as $n)if(!is_int($n)||$n<0)throw new DomainException('The saved accepted/quarantined quantity needs reconciliation.');
    if($accepted+$quarantined!==$qty)throw new DomainException('The saved accepted/quarantined quantities do not equal physical receipts.');
    $returned=0;$seen=[];$applied=[];$stockReturns=[];
    foreach($events as $event){if(($event['receiptId']??'')!==($receipt['id']??''))continue;
        $id=(string)($event['id']??'');if($id===''||isset($seen[$id])||!bt_is_date((string)($event['date']??''))||$event['date']<($receipt['date']??''))throw new DomainException('Quarantine disposition history requires reconciliation.');$seen[$id]=true;
        if($asOf!==null&&$event['date']>$asOf)continue;$count=bt_qty($event['qty']??null);
        $snapshot=(array)($receipt['sourceSnapshot']??[]);if(($event['entity']??'')!==($snapshot['entity']??'')||($event['locationId']??'')!==($snapshot['locationId']??''))throw new DomainException('Quarantine disposition belongs to another physical source.');
        switch($event['action']??''){
            case 'return_available':
                if($count>$accepted)throw new DomainException('Supplier returns exceed this receipt’s accepted quantity.');
                $accepted-=$count;$returned+=$count;$stockReturns[$id]=$event;
                break;
            case 'reverse_supplier_return':
                $original=$stockReturns[$event['reverses']??'']??null;
                if(!$original||$original['qty']!==$count||$event['date']<$original['date'])throw new DomainException('Supplier return reversal requires its original outstanding movement.');
                $accepted+=$count;$returned-=$count;unset($stockReturns[$original['id']]);
                break;
            case 'accept_corrected':
            case 'return_supplier':
                if($count>$quarantined)throw new DomainException('Quarantine dispositions exceed the actual held receipt.');
                if($event['action']==='accept_corrected')$accepted+=$count;else $returned+=$count;
                $quarantined-=$count;
                break;
            default: throw new DomainException('Unknown supplier receipt disposition.');
        }
        $applied[]=$event;
    }
    return ['acceptedQty'=>$accepted,'quarantinedQty'=>$quarantined,'returnedQty'=>$returned,'netReceivedQty'=>$qty-$returned,'events'=>$applied,'stockReturnedQty'=>array_sum(array_column($stockReturns,'qty')),'outstandingStockReturns'=>array_values($stockReturns)];
}
