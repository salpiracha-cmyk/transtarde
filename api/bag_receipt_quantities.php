<?php
declare(strict_types=1);
require_once __DIR__.'/bag_transfer_core.php';

/** Keep original receipt evidence immutable; dated dispositions account for quarantined quantities. */
function br_position(array $receipt,array $events,?string $asOf=null): array {
    $qty=bt_qty($receipt['qty']??null);$versioned=!empty($receipt['bagReceiptVersion']);
    $accepted=$versioned?($receipt['acceptedQty']??null):$qty;$quarantined=$versioned?($receipt['quarantinedQty']??null):0;
    foreach([$accepted,$quarantined] as $n)if(!is_int($n)||$n<0)throw new DomainException('The saved accepted/quarantined quantity needs reconciliation.');
    if($accepted+$quarantined!==$qty)throw new DomainException('The saved accepted/quarantined quantities do not equal physical receipts.');
    $returned=0;$seen=[];$applied=[];
    foreach($events as $event){if(($event['receiptId']??'')!==($receipt['id']??''))continue;
        $id=(string)($event['id']??'');if($id===''||isset($seen[$id])||!bt_is_date((string)($event['date']??''))||$event['date']<($receipt['date']??''))throw new DomainException('Quarantine disposition history requires reconciliation.');$seen[$id]=true;
        if($asOf!==null&&$event['date']>$asOf)continue;$count=bt_qty($event['qty']??null);if($count>$quarantined)throw new DomainException('Quarantine dispositions exceed the actual held receipt.');
        $snapshot=(array)($receipt['sourceSnapshot']??[]);if(($event['entity']??'')!==($snapshot['entity']??'')||($event['locationId']??'')!==($snapshot['locationId']??''))throw new DomainException('Quarantine disposition belongs to another physical source.');
        if(($event['action']??'')==='accept_corrected')$accepted+=$count;elseif(($event['action']??'')==='return_supplier')$returned+=$count;else throw new DomainException('Unknown quarantine disposition.');$quarantined-=$count;$applied[]=$event;
    }
    return ['acceptedQty'=>$accepted,'quarantinedQty'=>$quarantined,'returnedQty'=>$returned,'netReceivedQty'=>$qty-$returned,'events'=>$applied];
}
