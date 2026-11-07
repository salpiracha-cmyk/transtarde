<?php
declare(strict_types=1);

/** Read-only accounting-event classification. Original journals and Post IDs stay intact. */
function jac_cash_movement(array $journal): bool {
    foreach((array)($journal['lines']??[]) as $line)if(in_array((string)($line['account']??''),['1110','1120'],true)&&(abs((float)($line['debit']??0))>.005||abs((float)($line['credit']??0))>.005))return true;
    return false;
}
function jac_event(array $journal): array {
    $source=(string)($journal['sourceType']??'');$cash=jac_cash_movement($journal);$month=(string)($journal['meta']['month']??substr((string)($journal['date']??''),0,7));
    $kind='USER_VOUCHER';$label=(string)($journal['narration']??'');$recent=true;
    if(!$cash&&in_array($source,['SALARY_MONTH_COMPLETED','SALARY_MONTHLY_ACCRUAL','SALARY_MASTER_ADJUSTMENT','SALARY_RECORD_CORRECTION_REVERSAL'],true)){
        $payableCredit=0.;$expenseDebit=0.;foreach((array)($journal['lines']??[]) as $line){if((string)($line['account']??'')==='2230')$payableCredit+=(float)($line['credit']??0);if(in_array((string)($line['account']??''),['6210','6220'],true))$expenseDebit+=(float)($line['debit']??0);}
        $kind=$payableCredit>.005&&$expenseDebit>.005?'SALARY_ACCRUAL':'PAYROLL_NONCASH_ADJUSTMENT';$label=($kind==='SALARY_ACCRUAL'?'Salary accrued / prepared':'Payroll adjustments — no cash payment').($month!==''?' · '.$month:'');$recent=false;
    }elseif(!$cash){
        $rentDebit=0.;$prepaidCredit=0.;$other=false;foreach((array)($journal['lines']??[]) as $line){$dr=(float)($line['debit']??0);$cr=(float)($line['credit']??0);$account=(string)($line['account']??'');if($account==='6300')$rentDebit+=$dr;elseif($account==='1400')$prepaidCredit+=$cr;elseif($dr>.005||$cr>.005)$other=true;}
        $rentRelease=$rentDebit>.005&&abs($rentDebit-$prepaidCredit)<.005&&!$other&&($source==='RENT_MONTHLY_ACCRUAL'||!empty($journal['meta']['prepaidRentRelease'])||preg_match('/(?:release.*prepaid\s+rent|prepaid\s+rent.*(?:monthly|month|release))/i',(string)($journal['narration']??'')));
        if($rentRelease){$kind='PREPAID_RENT_RELEASE';$label='Monthly prepaid rent release'.($month!==''?' · '.$month:'').' — no new payment';$recent=false;}
        elseif(in_array($source,['RENT_MONTHLY_ACCRUAL','DEPRECIATION_MONTHLY','MONTHLY_DEPRECIATION'],true)||!empty($journal['meta']['systemAccountingEvent'])){$kind='NONCASH_ACCOUNTING_EVENT';$recent=false;}
    }elseif(str_starts_with($source,'SALARY_')){$kind='SALARY_PAYMENT';if($source==='SALARY_MONTH_COMPLETED')$label='Salary payment and processing'.($month!==''?' · '.$month:'');}
    return ['kind'=>$kind,'cashMovement'=>$cash,'recentVoucher'=>$recent,'displayNarration'=>$label,'documentTitle'=>$recent?'Voucher':'Accounting Journal','paymentLabel'=>$cash?'Cash / bank movement recorded':'No cash / bank movement'];
}
function jac_salary_payment_state(float $entitlement,float $paid,float $deductions,bool $prepared): array {
    $entitlement=round(max(0,$entitlement),2);$paid=round(max(0,$paid),2);$deductions=round(max(0,$deductions),2);$remaining=round(max(0,$entitlement-$paid-$deductions),2);
    if(!$prepared)return ['code'=>'NOT_PREPARED','label'=>'Not prepared','remaining'=>$remaining];
    if($remaining<=.005&&$entitlement>0)return ['code'=>$paid>.005?'FULLY_PAID':'SETTLED_BY_DEDUCTIONS','label'=>$paid>.005?'Fully paid / settled':'Settled by deductions — no new payment','remaining'=>0.];
    return ['code'=>$paid>.005?'PARTIALLY_PAID':'ACCRUED_PREPARED','label'=>$paid>.005?'Partially paid':'Accrued / Prepared','remaining'=>$remaining];
}

/** Existing posted releases, including manual JVs; never invent rent masters or repost them. */
function jac_rent_releases(array $store,string $entity,string $month):array {
    $rows=[];
    foreach((array)($store['journals']??[]) as $journal){
        if(!is_array($journal)||($journal['entity']??'')!==$entity||($journal['status']??'')!=='Posted'||substr((string)($journal['date']??''),0,7)!==$month||!empty($journal['meta']['deletedFromBooks'])||!empty($journal['amendedByPostId'])||!empty($journal['reversalOf'])||!empty($journal['reversedByPostId']))continue;
        if(jac_event($journal)['kind']!=='PREPAID_RENT_RELEASE')continue;
        $amount=0.;$parties=[];foreach((array)$journal['lines'] as $line){if((string)($line['account']??'')==='6300')$amount+=(float)($line['debit']??0);if((string)($line['account']??'')==='1400'){$party=trim((string)($line['payee']??$line['subledger']??$line['party']??''));if($party!=='')$parties[$party]=true;}}
        $rows[]=['postId'=>(string)($journal['id']??''),'date'=>(string)$journal['date'],'payee'=>implode(' / ',array_keys($parties)),'amount'=>round($amount,2),'narration'=>(string)($journal['narration']??''),'sourceType'=>(string)($journal['sourceType']??'')];
    }
    usort($rows,static fn($a,$b)=>strcmp($a['date'],$b['date'])?:strcmp($a['postId'],$b['postId']));return $rows;
}
