<?php
declare(strict_types=1);
require __DIR__.'/../../api/intercompany_advance_core.php';
function tt_next_post_id(array $rows,string $a,string $b):string{return 'EXAMPLE-'.(count($rows)+1);}
function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function fixture():array {
    $journal=static fn($entity,$lines)=>['entity'=>$entity,'status'=>'Posted','lines'=>$lines];
    return ['revision'=>5,'journals'=>[
        'PKR-RECEIPT'=>$journal('TTI',[['account'=>'1110','debit'=>75000,'credit'=>0],['account'=>'2510','debit'=>0,'credit'=>75000]]),
        'PKR-SALE'=>$journal('TTI',[['account'=>'1240','debit'=>75000,'credit'=>0],['account'=>'4500','debit'=>0,'credit'=>75000]]),
        'TG-BILL'=>$journal('TG',[['account'=>'5100','debit'=>900,'credit'=>0],['account'=>'2500','debit'=>0,'credit'=>900]]),
        'TG-BANK'=>$journal('TG',[['account'=>'2110','debit'=>1200,'credit'=>0,'counterparty'=>'TTI','nativeDebit'=>400],['account'=>'1110','debit'=>0,'credit'=>1200]])],
        'exportReceipts'=>['ADVICE'=>['id'=>'ADVICE','entity'=>'TTI','status'=>'Accounts Approved / Posted','remitter'=>'TG','date'=>'2026-01-02','transactionCurrency'=>'USD','foreignAmount'=>300,'realizationRate'=>250,'bankAdviceRef'=>'EXAMPLE-ADVICE','journalId'=>'PKR-RECEIPT','tgRemittanceId'=>'REMIT','allocations'=>[['targetType'=>'UNAPPLIED_TG','foreignAmount'=>300]]]],
        'exportCandidates'=>[
            'SALE'=>['id'=>'SALE','entity'=>'TTI','candidateType'=>'TG_PAKISTAN_INTERCOMPANY','counterparty'=>'TG','transactionCurrency'=>'USD','transactionAmount'=>300,'functionalAmount'=>75000,'journalId'=>'PKR-SALE','recognitionDate'=>'2026-01-03','mirrorCandidateId'=>'BILL','reference'=>'EXAMPLE/LOT','meta'=>['contractRef'=>'EXAMPLE']],
            'BILL'=>['id'=>'BILL','entity'=>'TG','candidateType'=>'TG_INTERCOMPANY_PAYABLE','counterparty'=>'TTI','transactionCurrency'=>'USD','transactionAmount'=>300,'functionalAmount'=>900,'journalId'=>'TG-BILL','recognitionDate'=>'2026-01-03','linkedCandidateId'=>'SALE']],
        'tgRemittances'=>['REMIT'=>['status'=>'Posted','counterparty'=>'TTI','currency'=>'USD','receiptIds'=>['ADVICE'],'date'=>'2026-01-01','journalId'=>'TG-BANK']],
        'tgBankTransactions'=>['TX'=>['status'=>'Posted','kind'=>'Payment','journalId'=>'TG-BANK','amountNative'=>400,'liabilityAllocations'=>[]]]];
}
$request=['receiptId'=>'ADVICE','candidateId'=>'SALE','entity'=>'TTI','date'=>'2026-01-03','note'=>'Owner confirms shipment allocation.','expectedRevision'=>5];
$names=array_fill_keys(['1110','2510','1240','2110','1250','2500','7195'],'Example');$user=['username'=>'Example'];
$s=fixture();$original=$s['journals'];$a=ica_apply($s,$request,$user,$names);
check(count($s['journals'])===6,'exactly two noncash journals');
foreach($original as $id=>$j)check($s['journals'][$id]===$j,'original bank and recognition journals are immutable');
foreach([$a['pakJournalId'],$a['tgJournalId']] as $id){$j=$s['journals'][$id];check($j['totalDebit']===$j['totalCredit'],'balanced');foreach($j['lines'] as $l)check($l['account']!=='1110','no bank movement');}
check($s['exportReceipts']['ADVICE']['allocations'][0]['targetId']==='SALE','native receipt allocation updated');
check($s['tgBankTransactions']['TX']['amountNative']===400,'original payment preserved');
check($s['tgBankTransactions']['TX']['liabilityAllocations'][0]['amountNative']===300.0,'TG native payable settled');
check(ica_sources($s,'TTI')===[],'applied source cannot be offered twice');
check(ica_apply($s,$request,$user,$names)===$a&&count($s['journals'])===6,'retry is idempotent');
$s=fixture();$s['journals']['TG-BANK']['lines'][0]['debit']=1199.96;$s['journals']['TG-BANK']['lines'][1]['credit']=1199.96;
$a=ica_apply($s,$request,$user,$names);$lines=$s['journals'][$a['tgJournalId']]['lines'];
check($a['roundingAed']===.03&&$lines[2]['account']==='7195'&&$lines[2]['credit']===.03,'three-fils original carrying residual disclosed without changing bank');
foreach(['stale','early','currency','entity','mirror','valuation','tg-valuation','reversed','already-paid','reserved','used','contract'] as $case){
    $s=fixture();$b=$request;
    switch($case){
        case 'stale':$b['expectedRevision']=4;break;
        case 'early':$b['date']='2026-01-02';break;
        case 'currency':$s['exportCandidates']['BILL']['transactionCurrency']='AED';break;
        case 'entity':$b['entity']='BRM';break;
        case 'mirror':$s['exportCandidates']['BILL']['linkedCandidateId']='OTHER';break;
        case 'tg-valuation':$s['exportCandidates']['BILL']['functionalAmount']=901;break;
        case 'valuation':$s['exportCandidates']['SALE']['functionalAmount']=76000;break;
        case 'reversed':$s['journals']['REV']=['reversalOf'=>'TG-BANK'];break;
        case 'already-paid':$s['tgBankTransactions']['TX']['liabilityAllocations'][]=['id'=>'BILL','amountNative'=>1];break;
        case 'reserved':$s['tgRemittanceDrafts']['OTHER']=['status'=>'Pending','allocations'=>[['sourceLiabilityId'=>'BILL']]];break;
        case 'used':$s['intercompanyAdvanceApplications']['OTHER']=['tgRemittanceId'=>'REMIT','amountNative'=>200];break;
        case 'contract':$s['exportReceipts']['ADVICE']['allocations'][0]['contractRef']='OTHER';break;
    }
    $before=$s;try{ica_apply($s,$b,$user,$names);throw new RuntimeException('Accepted invalid case '.$case);}catch(DomainException $e){check($s===$before,'failed validation must not mutate '.$case);}
}
echo "intercompany advance application PASS\n";
