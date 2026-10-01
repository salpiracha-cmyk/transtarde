<?php
declare(strict_types=1);

// Drafts and the final journal share the Accounts storage lock in rent_salary_v2.php.
function smw_sheet(array $s,string $entity,string $month):array {
    $key=$entity.'|'.$month;
    if(isset($s['salarySheets'][$key]))return $s['salarySheets'][$key];
    $rows=[];
    foreach((array)$s['salaryMasters'] as $m){
        if(!is_array($m)||($m['entity']??'')!==$entity||!rsv2_active($m,$month))continue;
        $row=rsv2_salary_row($s,$m,$month);
        $period=$s['salaryPeriods'][$row['periodId']]??null;
        $row['baseline']=$period===null?'':rsv2_fingerprint($period);
        $row['advanceApplied']=$period===null?min($row['totalDue'],$row['advanceSuggested']):$row['advanceApplied'];
        $row['outstanding']=round(max(0,$row['totalDue']-$row['advanceApplied']-$row['paidAfterPrepare']),2);
        $row['originalAdvanceApplied']=$row['advanceApplied'];$row['reviewed']=false;$row['payment']=null;
        $rows[$row['masterId']]=$row;
    }
    return ['entity'=>$entity,'month'=>$month,'version'=>0,'status'=>'Draft','rows'=>$rows];
}
function smw_check_baseline(array $s,array $row):void {
    $period=$s['salaryPeriods'][$row['periodId']]??null;
    $actual=$period===null?'':rsv2_fingerprint($period);
    if($actual!==$row['baseline'])throw new DomainException('Salary balances changed after this sheet was opened. Refresh the sheet before completing it.');
}
function smw_action(array &$s,string $entity,string $month,array $b,array $u,array $names):array {
    $key=$entity.'|'.$month;$sheet=smw_sheet($s,$entity,$month);
    $complete=($b['action']??'')==='complete_salary_month';
    if($sheet['status']==='Completed'){
        if($complete)return ['journalId'=>$sheet['journalId'],'duplicate'=>true];
        throw new DomainException('This month is completed. Amend its Post ID with a reason.');
    }
    if((int)($b['version']??-1)!==$sheet['version'])throw new DomainException('Another salary draft changed. Refresh and review the latest sheet.');
    if(!$complete){
        $mid=(string)($b['masterId']??'');$row=$sheet['rows'][$mid]??null;
        if(!$row)throw new DomainException('Select a person on this salary sheet.');
        smw_check_baseline($s,$row);
        $raw=$b['amount']??null;if(!is_numeric($raw))throw new DomainException('Enter a valid salary payment amount.');
        $amount=round((float)$raw,2);$maximum=round($row['totalDue']-$row['paidAfterPrepare'],2);
        if($amount<0||$amount>$maximum+.005)throw new DomainException('Payment cannot exceed the unpaid total salary entitlement.');
        $date=rsv2_date((string)($b['date']??''));$source=(string)($b['paymentAccountId']??'');
        $tracking=[];
        $parts=[];
        if(array_key_exists('cashAmount',$b)||array_key_exists('bankAmount',$b)){
            foreach(['cashAmount','bankAmount'] as $field)if(!isset($b[$field])||!is_numeric($b[$field])||!is_finite((float)$b[$field])||(float)$b[$field]<0)throw new DomainException('Enter valid cash and bank amounts.');
            $cash=round((float)$b['cashAmount'],2);$bank=round((float)$b['bankAmount'],2);
            if((int)round(($cash+$bank)*100)!==(int)round($amount*100))throw new DomainException('Cash and bank amounts must equal the total salary payment.');
            if($cash>.005){rsv2_pay_line($s,$entity,'CASH|'.$entity,$cash,$names);$parts[]=['amount'=>$cash,'paymentAccountId'=>'CASH|'.$entity];}
            if($bank>.005){if(str_starts_with($source,'CASH|'))throw new DomainException('Choose a bank account for the bank portion.');rsv2_pay_line($s,$entity,$source,$bank,$names);$tracking=tt_accounts_bank_payment_details($b);$parts[]=['amount'=>$bank,'paymentAccountId'=>$source]+$tracking;}
        }elseif($amount>.005){rsv2_pay_line($s,$entity,$source,$amount,$names);if(!str_starts_with($source,'CASH|'))$tracking=tt_accounts_bank_payment_details($b);$parts[]=['amount'=>$amount,'paymentAccountId'=>$source]+$tracking;}
        $row['advanceApplied']=round(min($row['prepared']?$row['originalAdvanceApplied']:min($row['totalDue'],$row['advanceSuggested']),max(0,$maximum-$amount)),2);
        $row['outstanding']=round(max(0,$maximum-$row['advanceApplied']-$amount),2);
        $row['reviewed']=true;$row['payment']=['amount'=>$amount,'date'=>$date,'paymentAccountId'=>$source,'parts'=>$parts]+$tracking;
        $sheet['rows'][$mid]=$row;$sheet['version']++;$sheet['updatedAt']=gmdate('c');
        $s['salarySheets'][$key]=$sheet;return ['saved'=>true,'masterId'=>$mid];
    }
    if(!$sheet['rows'])throw new DomainException('There are no active salary records for this month.');
    $lines=[];$date=rsv2_date((string)($b['date']??gmdate('Y-m-d')));
    foreach($sheet['rows'] as $mid=>$row){
        if(!$row['reviewed'])throw new DomainException('Review and save the payment or zero-payment deferral for '.$row['name'].'.');
        smw_check_baseline($s,$row);
        if($row['accountingTreatment']==='TO_CONFIRM')throw new DomainException('Choose accounting treatment in Salary Master for '.$row['name'].'.');
        $meta=['salaryMasterId'=>$mid,'person'=>$row['name'],'party'=>$row['name'],'subledgerId'=>$mid,'month'=>$month,'category'=>$row['category'],'accountingTreatment'=>$row['accountingTreatment'],'productionCostEligible'=>$row['productionCostEligible']];
        $applied=$row['advanceApplied'];
        if(!$row['prepared']){
            foreach([[$row['accountingTreatment']==='FAMILY_ALLOCATION'?'3200':'6210',$row['netSalary']],['7210',$row['zakatAmount']],[$row['accountingTreatment']==='FAMILY_ALLOCATION'?'3200':'6220',$row['otherAllowance']]] as [$account,$amount])if($amount>.005)$lines[]=rsv2_line($account,$amount,0,$names,$meta);
            if($applied>.005)$lines[]=rsv2_line('1230',0,$applied,$names,$meta+['salaryAdvanceApplied'=>$applied]);
            if($row['totalDue']-$applied>.005)$lines[]=rsv2_line('2140',0,$row['totalDue']-$applied,$names,$meta);
            $available=array_sum(array_column(rsv2_open_advances($s,$entity,$mid),'remaining'));
            if($applied>$available+.005)throw new DomainException('Advance balance changed for '.$row['name'].'. Amend this payment before completing.');
        }else{
            $previous=(float)$s['salaryPeriods'][$row['periodId']]['advanceApplied'];$restore=round($previous-$applied,2);
            if($restore>.005){$lines[]=rsv2_line('1230',$restore,0,$names,$meta);$lines[]=rsv2_line('2140',0,$restore,$names,$meta);}
        }
        $payment=$row['payment'];
        if($payment['amount']>.005){
            $lines[]=rsv2_line('2140',$payment['amount'],0,$names,$meta);
            $parts=$payment['parts']??[['amount'=>$payment['amount'],'paymentAccountId'=>$payment['paymentAccountId']]+$payment];
            $sum=0;foreach($parts as $part){$partAmount=round((float)$part['amount'],2);if($partAmount<=0)throw new DomainException('Invalid saved salary split.');$sum+=(int)round($partAmount*100);$tracking=str_starts_with($part['paymentAccountId'],'CASH|')?[]:tt_accounts_bank_payment_details($part);$lines[]=array_merge(rsv2_pay_line($s,$entity,$part['paymentAccountId'],$partAmount,$names),$meta,$tracking,['paymentDate'=>$payment['date']]);}
            if($sum!==(int)round($payment['amount']*100))throw new DomainException('Saved salary portions do not equal the payment.');
        }
    }
    if(!$lines)throw new DomainException('This month has no new accounting entries to post.');
    $jid=rsv2_journal($s,$entity,$date,'SALARY_MONTH_COMPLETED','SAL-'.$month,'Salary completed — '.$month,$lines,$u,['month'=>$month,'salarySheetKey'=>$key]);
    foreach($sheet['rows'] as $mid=>$row){
        $pid=$row['periodId'];$applied=$row['advanceApplied'];
        if(!$row['prepared']){
            $left=$applied;
            foreach(rsv2_open_advances($s,$entity,$mid) as $advance){if($left<=.005)break;$id=$advance['id'];$take=min($left,$s['salaryAdvances'][$id]['remaining']);$s['salaryAdvances'][$id]['remaining']=round($s['salaryAdvances'][$id]['remaining']-$take,2);$s['salaryAdvances'][$id]['monthsRemaining']=max(0,(int)($s['salaryAdvances'][$id]['monthsRemaining']??1)-1);$s['salaryAdvances'][$id]['adjustments'][]=['month'=>$month,'amount'=>$take,'journalId'=>$jid];$left=round($left-$take,2);}
        }else{
            $restore=round($s['salaryPeriods'][$pid]['advanceApplied']-$applied,2);
            foreach($s['salaryAdvances'] as &$advance){if(($advance['entity']??'')!==$entity||($advance['masterId']??'')!==$mid)continue;foreach($advance['adjustments'] as &$adjustment){if($restore<=.005)break;if(($adjustment['month']??'')!==$month)continue;$take=min($restore,(float)$adjustment['amount']);$adjustment['amount']=round($adjustment['amount']-$take,2);$advance['remaining']=round($advance['remaining']+$take,2);$restore=round($restore-$take,2);}unset($adjustment);}unset($advance);
            if($restore>.005)throw new DomainException('The original advance adjustment cannot be restored. Review this salary period.');
        }
        $s['salaryPeriods'][$pid]=array_merge($s['salaryPeriods'][$pid]??[],['id'=>$pid,'entity'=>$entity,'month'=>$month,'salaryMasterId'=>$mid,'name'=>$row['name'],'category'=>$row['category'],'accountingTreatment'=>$row['accountingTreatment'],'netSalary'=>$row['netSalary'],'zakatAmount'=>$row['zakatAmount'],'otherAllowance'=>$row['otherAllowance'],'gross'=>$row['totalDue'],'advanceApplied'=>$applied,'paidAfterPrepare'=>round($row['paidAfterPrepare']+$row['payment']['amount'],2),'outstanding'=>$row['outstanding'],'journalId'=>$jid,'productionCostEligible'=>$row['productionCostEligible'],'status'=>$row['outstanding']>.005?'Payable':'Paid','preparedAt'=>gmdate('c')]);
        if($row['payment']['amount']>.005){$id=rsv2_next((array)$s['rentSalaryPayments'],'RSP');$s['rentSalaryPayments'][$id]=['id'=>$id,'type'=>'SALARY','entity'=>$entity,'masterId'=>$mid,'month'=>$month,'journalId'=>$jid]+$row['payment'];}
    }
    $sheet['status']='Completed';$sheet['journalId']=$jid;$sheet['completedAt']=gmdate('c');$sheet['version']++;$s['salarySheets'][$key]=$sheet;
    return ['journalId'=>$jid];
}
