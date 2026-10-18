<?php
declare(strict_types=1);
/** Strict read only: damaged/missing books must not become an empty successful report. */
function sp_read_books(string $file):array {
    $h=fopen($file,'rb');if(!$h||!flock($h,LOCK_SH))throw new RuntimeException('Accounts source unavailable.');
    try{$raw=stream_get_contents($h);$state=json_decode((string)$raw,true,512,JSON_THROW_ON_ERROR);}
    finally{flock($h,LOCK_UN);fclose($h);}
    if(!is_array($state)||($state!==[]&&array_is_list($state)))throw new RuntimeException('Invalid Accounts source.');
    foreach(['journals','supplierBills','exportCandidates','loadingProgrammes'] as $key)if(isset($state[$key])&&!is_array($state[$key]))throw new RuntimeException('Invalid Accounts source.');
    return $state;
}
function sp_amount(mixed $value):float {
    if(!is_numeric($value)||!is_finite((float)$value)||abs((float)$value)>1e14)throw new DomainException('Reconcile the original amount.');
    return round((float)$value,2);
}
/** Reconcile a current bill to all its signed original liability postings, including amendments.
 * Payment/settlement journals do not create another shipment cost.
 */
function sp_bill_evidence(array $state,array $bill,string $entity):void {
    if(!in_array($bill['status']??'',['Outstanding','Settled','Paid','Posted','Verified / Posted','Approved / Posted'],true))throw new DomainException('Original liability is not posted.');
    $id=(string)($bill['id']??'');$expense=(string)($bill['expenseAccount']??'');
    $expected=['TRANSPORT'=>'5510','FREIGHT'=>'5520','CLEARING'=>'5530','FUMIGATION'=>'5540','INSPECTION'=>'5530'];
    $expense=$expected[$bill['category']??'']??$expense;
    $payable=(string)($bill['payableAccount']??'2130');
    $ids=$bill['postingJournalIds']??(!empty($bill['journalId'])?[$bill['journalId']]:[]);
    if($id===''||$expense===''||!is_array($ids)||!array_is_list($ids)||!$ids||count(array_unique($ids,SORT_STRING))!==count($ids))throw new DomainException('Reconcile missing or repeated liability Post IDs.');
    $cost=0.;$liability=0.;
    foreach($ids as $postId){
        if(!is_string($postId)||$postId==='')throw new DomainException('Reconcile the original liability Post ID.');
        $j=$state['journals'][$postId]??null;
        if(!is_array($j)||($j['entity']??'')!==$entity||($j['status']??'')!=='Posted')throw new DomainException('Original liability Post '.$postId.' is unavailable for this company.');
        $owner=(string)($j['meta']['supplierBillId']??$j['meta']['billId']??'');
        if($owner!==$id)throw new DomainException('Original liability Post '.$postId.' does not identify this bill.');
        $dr=0.;$cr=0.;
        foreach((array)($j['lines']??[]) as $line){
            if(!is_array($line))throw new DomainException('Reconcile the original liability lines.');
            $debit=sp_amount($line['debit']??0);$credit=sp_amount($line['credit']??0);
            if($debit<0||$credit<0)throw new DomainException('Reconcile the original liability lines.');
            $dr+=$debit;$cr+=$credit;
            if((string)($line['account']??'')===$expense)$cost+=$debit-$credit;
            if((string)($line['account']??'')===$payable)$liability+=$credit-$debit;
        }
        if(abs($dr-$cr)>.005)throw new DomainException('Original liability Post '.$postId.' is out of balance.');
    }
    $amount=sp_amount($bill['supplierPayableTotal']??null);
    if($amount<0||abs(round($cost,2)-$amount)>.005||abs(round($liability,2)-$amount)>.005)throw new DomainException('Current accepted amount does not reconcile to its original liability postings.');
    $record=(array)($bill['sourceRecord']??[]);
    if(($bill['category']??'')==='TRANSPORT'){
        $allocated=0.;$lines=$record['lines']??[];
        if(!is_array($lines)||!array_is_list($lines)||!$lines)throw new DomainException('Reconcile the original transport allocation.');
        foreach($lines as $line){if(!is_array($line))throw new DomainException('Reconcile the original transport allocation.');$allocated+=sp_amount($line['amount']??null);}
        foreach((array)($record['adjustments']??[]) as $adjustment){if(!in_array($adjustment['type']??'',['ADD','DEDUCT'],true))throw new DomainException('Reconcile the transport adjustment type.');$allocated+=(($adjustment['type']??'')==='DEDUCT'?-1:1)*sp_amount($adjustment['amount']??null);}
        if(abs(round($allocated,2)-$amount)>.005)throw new DomainException('Transport allocations do not reconcile to the posted accepted amount.');
    }elseif(!empty($record['shipmentSections'])){
        foreach($record['shipmentSections'] as $section)sp_amount($section['amount']??null);
    }
}
