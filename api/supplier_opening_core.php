<?php
declare(strict_types=1);

/** Project saved creditor openings into payment choices; reads never create liabilities. */
function sop_openings(array $s,string $entity):array {
    $rows=[];
    foreach((array)($s['journals']??[]) as $id=>$j){
        if(($j['entity']??'')!==$entity||($j['status']??'')!=='Posted'||($j['sourceType']??'')!=='OPENING_BALANCE_BF'||!empty($j['openingReversalId'])||!empty($j['amendedByPostId'])||!empty($j['reversalOf'])||!empty($j['reversedByPostId'])||!empty($j['meta']['deletedFromBooks']))continue;
        foreach((array)($j['lines']??[]) as $index=>$l){
            $account=(string)($l['account']??'');
            if(!in_array($account,['2110','2120','2130','2140','2190'],true))continue;
            $party=trim((string)($l['subledger']??$l['counterparty']??$j['meta']['openingParty']??''));
            $amount=round((float)($l['credit']??0)-(float)($l['debit']??0),2);
            if($party===''||$amount<=.005)continue;
            // Carry-forward bills already expose their assigned part through the bill register.
            $native=round((float)($l['nativeCredit']??$amount)-(float)($l['nativeDebit']??0),2);$rate=$native>.005?$amount/$native:1;
            foreach((array)($s['carryForwardOpeningLinks']??[]) as $link)if(($link['journalId']??'')===$id&&(string)($link['lineIndex']??'')===(string)$index)$amount=round($amount-(float)($link['nativeAmount']??0)*$rate,2);
            if($amount<=.005)continue;
            $key='BF:'.$id.':'.$index;
            $rows[$key]=['id'=>$key,'journalId'=>(string)$id,'sourceKey'=>'OPENING|'.$index,'party'=>$party,'account'=>$account,'amount'=>$amount,'date'=>(string)$j['date'],'currency'=>$entity==='TG'?'AED':'PKR'];
        }
    }
    return $rows;
}
/** Prevent changing/deleting an opening while a linked payment still settles it. */
function sop_assert_unpaid(array $s,string $postId):void {
    $prefix='BF:'.$postId.':';
    foreach((array)($s['supplierSettlements']??[]) as $pay){
        if(!in_array($pay['status']??'',['Posted','Approved / Posted','Approved'],true))continue;
        foreach((array)($pay['allocations']??[]) as $a)if(str_starts_with((string)($a['billId']??''),$prefix))throw new DomainException('Reverse the linked supplier payment before changing this opening balance.');
    }
    foreach((array)($s['tgBankTransactions']??[]) as $pay){
        if(($pay['kind']??'')!=='Payment'||in_array($pay['status']??'',['Reversed for Amendment','Cancelled','Deleted'],true))continue;
        foreach(array_merge((array)($pay['liabilityAllocations']??[]),[['id'=>$pay['sourceLiabilityId']??'']]) as $a)if(str_starts_with((string)($a['id']??''),$prefix))throw new DomainException('Reverse the linked TG payment before changing this opening balance.');
    }
}
