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
function sp_active_reversals(array $state,array $hidden):array {
    $out=[];foreach((array)($state['journals']??[]) as $key=>$journal){
        if(!is_array($journal)||($journal['status']??'')!=='Posted'||isset($hidden[(string)($journal['id']??$key)]))continue;
        $original=(string)($journal['reversalOf']??$journal['meta']['reversalOf']??'');
        if($original!=='')$out[(string)($journal['entity']??'').'|'.$original]=true;
    }return $out;
}
function sp_posted_journal(array $state,string $id,string $entity,array $hidden,array $activeReversals):array {
    $j=$state['journals'][$id]??null;
    if(!is_array($j)||($j['id']??'')!==$id||($j['entity']??'')!==$entity||($j['status']??'')!=='Posted'||isset($hidden[$id])||!empty($j['amendedByPostId'])||!empty($j['reversalOf'])||!empty($j['reversedByPostId'])||!empty($j['meta']['reversedByPostId']))throw new DomainException('Linked Post '.$id.' is unavailable or superseded; reconcile the source.');
    if(isset($activeReversals[$entity.'|'.$id]))throw new DomainException('The linked posting has an unreconciled reversal.');
    $dr=0.;$cr=0.;$lines=$j['lines']??null;
    if(!is_array($lines)||!array_is_list($lines)||count($lines)<2)throw new DomainException('Reconcile the linked posting lines.');
    foreach($lines as $line){
        if(!is_array($line)||!is_scalar($line['account']??null)||trim((string)$line['account'])==='')throw new DomainException('Reconcile the linked posting lines.');
        $debit=sp_amount($line['debit']??0);$credit=sp_amount($line['credit']??0);
        if($debit<0||$credit<0)throw new DomainException('Reconcile the linked posting lines.');
        $dr+=$debit;$cr+=$credit;
    }
    if($dr<=0||abs($dr-$cr)>.005||(isset($j['totalDebit'])&&abs($dr-sp_amount($j['totalDebit']))>.005)||(isset($j['totalCredit'])&&abs($cr-sp_amount($j['totalCredit']))>.005))throw new DomainException('The linked posting does not balance.');
    return $j;
}
function sp_source_post(array $state,string $id,string $entity,string $type,string $ownerField,string $owner,array $hidden,array $activeReversals):array {
    $j=sp_posted_journal($state,$id,$entity,$hidden,$activeReversals);
    $source=($j['sourceType']??'')==='POST_AMENDMENT_CORRECTION'?($j['meta']['originalSourceType']??''):($j['sourceType']??'');
    if($source!==$type||($j['meta'][$ownerField]??'')!==$owner)throw new DomainException('Linked Post '.$id.' does not identify this source.');
    return $j;
}
function sp_post_net(array $journal,string $account):float {
    $net=0.;foreach($journal['lines'] as $line)if((string)$line['account']===$account)$net+=(float)($line['debit']??0)-(float)($line['credit']??0);return round($net,2);
}
/** A linked opening receivable is not a second sale or historical cost posting. */
function sp_historical_opening(array $state,array $candidate,string $entity,array $hidden,array $activeReversals):bool {
    $meta=(array)($candidate['meta']??[]);$recordId=(string)($meta['carryForwardShipmentId']??'');
    if($recordId===''&&empty($meta['historicalCostExcluded']))return false;
    $record=$state['carryForwardShipments'][$recordId]??null;$id=(string)($candidate['id']??'');
    $intercompany=($candidate['candidateType']??'')==='TG_PAKISTAN_INTERCOMPANY';
    $balanceKey=$intercompany?'pakistanBalance':'buyerBalance';$pointer=$intercompany?'pakistanCandidateId':'buyerCandidateId';
    if(!is_array($record)||($record['id']??'')!==$recordId||($record['status']??'')!=='Ready for Accounts'
        ||($meta['historicalCostExcluded']??false)!==true||($record[$pointer]??'')!==$id
        ||($meta['contractRef']??'')!==($record['contractRef']??null)||($meta['lotRef']??'')!==($record['lotRef']??null)
        ||($candidate['sourceKey']??'')!==(($record['operationalShipmentId']??'')?:$recordId)
        ||($intercompany&&!($record['tgPack']??false)))throw new DomainException('Reconcile the historical shipment source identity.');
    $ownerEntity=!$intercompany&&!empty($record['tgPack'])?'TG':($record['entity']??'');
    $balance=$record[$balanceKey]??[];$native=sp_amount($candidate['transactionAmount']??null);
    if($ownerEntity!==$entity||($candidate['entity']??'')!==$entity||$native<=0
        ||($candidate['reference']??'')!==($balance['invoiceNo']??null)
        ||($candidate['transactionCurrency']??'')!==($balance['currency']??null)
        ||abs($native-sp_amount($balance['outstanding']??null))>.005
        ||!in_array($candidate['status']??'',['Opening receivable / payable linked','Registered balance valued at settlement'],true)
        ||!empty($candidate['costJournalId'])||sp_amount($candidate['costAmount']??0)!=0)
        throw new DomainException('Reconcile the historical opening balance; it cannot be counted as current sale/cost.');
    $post=(string)($candidate['journalId']??'');$journal=sp_posted_journal($state,$post,$entity,$hidden,$activeReversals);
    $account=$intercompany?'1240':'1210';$amount=sp_amount($candidate['functionalAmount']??null);
    if($amount<=0||sp_post_net($journal,'4200')!=0||sp_post_net($journal,'4500')!=0||sp_post_net($journal,'5100')!=0)
        throw new DomainException('Historical opening contains sale/cost amounts requiring reconciliation.');
    if(($journal['sourceType']??'')==='CARRY_FORWARD_OPENING'){
        if(($journal['meta']['carryForwardShipmentId']??'')!==$recordId
            ||(!empty($journal['meta']['candidateId'])&&$journal['meta']['candidateId']!==$id)
            ||abs(sp_post_net($journal,$account)-$amount)>.005||abs(-sp_post_net($journal,'3400')-$amount)>.005)
            throw new DomainException('Reconcile the linked historical opening posting.');
    }elseif(($journal['sourceType']??'')==='OPENING_BALANCE_BF'){
        // Existing shared openings are owned by their explicit allocation link, not a guessed journal owner.
        $link=$state['carryForwardOpeningLinks'][$id]??[];$index=$link['lineIndex']??null;
        if(($link['shipmentId']??'')!==$recordId||($link['entity']??'')!==$entity||($link['journalId']??'')!==$post
            ||!is_int($index)||$index<0||($link['currency']??'')!==$candidate['transactionCurrency']
            ||abs(sp_amount($link['nativeAmount']??null)-$native)>.005||!empty($journal['openingReversalId']))
            throw new DomainException('Reconcile the existing opening allocation.');
        $line=$journal['lines'][$index]??[];$capacity=sp_amount($line['nativeDebit']??(($line['nativeCurrency']??$line['currency']??'')===($candidate['functionalCurrency']??'')?($line['debit']??0):0));
        if(($line['account']??'')!==$account||($line['nativeCurrency']??$line['currency']??'')!==$candidate['transactionCurrency']
            ||$capacity<$native||$capacity<=0||abs(round($native*sp_amount($line['debit']??0)/$capacity,2)-$amount)>.005)
            throw new DomainException('Reconcile the existing opening line and allocated amount.');
    }else throw new DomainException('Historical shipment does not link an opening posting.');
    return true;
}
/** Read accepted revenue/COGS only after validating their posted source identities. */
function sp_sale_evidence(array $state,array $candidate,string $entity,array $hidden,array $activeReversals):?array {
    $id=(string)($candidate['id']??'');$saleId=(string)($candidate['journalId']??'');$costId=(string)($candidate['costJournalId']??'');
    if($id===''||$saleId==='')throw new DomainException('Reconcile missing sale source/Post identities.');
    foreach([$saleId,$costId] as $postId)if($postId!==''&&!empty($state['journals'][$postId]['amendedByPostId']))throw new DomainException('A linked posting was amended; reconcile the current source amount.');
    if(isset($hidden[$saleId])){if($costId===''||isset($hidden[$costId]))return null;throw new DomainException('The sale was cancelled but its stock cost remains active.');}
    if(sp_historical_opening($state,$candidate,$entity,$hidden,$activeReversals))return ['historicalOpening'=>true];
    if(($candidate['status']??'')!=='Recognized / Posted')throw new DomainException('The sale source is not recognized/posted.');
    $type=($candidate['candidateType']??'')==='TG_PAKISTAN_INTERCOMPANY'?'TG_INTERCOMPANY_PAKISTAN':'EXPORT_SALE_RECOGNIZED';
    $sale=sp_source_post($state,$saleId,$entity,$type,'candidateId',$id,$hidden,$activeReversals);
    $revenue=sp_amount($candidate['functionalAmount']??null);$account=$type==='TG_INTERCOMPANY_PAKISTAN'?'4500':'4200';
    if($revenue<=0||abs(-sp_post_net($sale,$account)-$revenue)>.005||abs(sp_amount($sale['meta']['functionalAmount']??null)-$revenue)>.005)throw new DomainException('Accepted sale amount does not reconcile to its revenue posting.');
    if($costId===''){
        if(isset($candidate['costAmount'])&&sp_amount($candidate['costAmount'])!=0)throw new DomainException('Stock cost has no linked posting.');
        return ['revenue'=>$revenue,'stockCost'=>null];
    }
    $cost=$state['journals'][$costId]??[];$costType=($cost['sourceType']??'')==='POST_AMENDMENT_CORRECTION'?($cost['meta']['originalSourceType']??''):($cost['sourceType']??'');
    if($entity==='TG'&&$costType==='TG_INTERCOMPANY_PAYABLE_RECOGNIZED'){
        $linkedId=(string)($candidate['tgPayableCandidateId']??$cost['meta']['candidateId']??'');$linked=$state['exportCandidates'][$linkedId]??null;
        if(!is_array($linked)||($linked['id']??'')!==$linkedId||($linked['entity']??'')!==$entity||($linked['candidateType']??'')!=='TG_INTERCOMPANY_PAYABLE'||($linked['journalId']??'')!==$costId||($linked['status']??'')!=='Recognized / Posted'||($linked['costAccount']??'')!=='5100'||empty($candidate['sourceKey'])||($linked['sourceKey']??'')!==$candidate['sourceKey'])throw new DomainException('Reconcile the linked TG direct stock-cost source.');
        $cost=sp_source_post($state,$costId,$entity,$costType,'candidateId',$linkedId,$hidden,$activeReversals);
        if(abs(sp_amount($linked['functionalAmount']??null)-sp_amount($candidate['costAmount']??null))>.005)throw new DomainException('TG linked cost amounts do not reconcile.');
    }else{
        $cost=sp_source_post($state,$costId,$entity,'EXPORT_COGS','exportCandidateId',$id,$hidden,$activeReversals);
        if(($cost['meta']['saleJournalId']??'')!==$saleId)throw new DomainException('Stock cost does not identify this sale posting.');
    }
    $amount=sp_amount($candidate['costAmount']??null);
    if($amount<=0||abs(sp_post_net($cost,'5100')-$amount)>.005)throw new DomainException('Accepted stock cost does not reconcile to its cost posting.');
    return ['revenue'=>$revenue,'stockCost'=>$amount];
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
