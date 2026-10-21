<?php
declare(strict_types=1);

// Drafts and the final journal share the Accounts storage lock in rent_salary_v2.php.
function smw_master_signature(array $s,string $entity,string $month):string {
    $masters=[];
    foreach((array)$s['salaryMasters'] as $id=>$m){
        if(!is_array($m)||($m['entity']??'')!==$entity||!rsv2_active($m,$month))continue;
        $masters[(string)$id]=array_intersect_key($m,array_flip(['name','category','monthlyAmount','zakatAmount','otherAllowance','accountingTreatment','productionCostEligible','effectiveFrom','effectiveTo']));
    }
    ksort($masters);
    return hash('sha256',json_encode($masters,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}
function smw_advance_signature(array $s,string $entity,string $month):string {
    $rows=[];foreach((array)$s['salaryMasters'] as $id=>$m)if(($m['entity']??'')===$entity)$rows[$id]=rsv2_open_advances($s,$entity,(string)$id,$month);
    return hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));
}
function smw_personal_signature(array $s,string $entity,string $month=''):string {
    $rows=[];foreach((array)($s['expensePersonalLinks']??[]) as $link){if(($link['entity']??'')!==$entity)continue;foreach((array)$link['rows'] as $r)if(($r['personalTreatment']??'')==='REMUNERATION'&&($month===''||sfw_recovery_month($r)<=$month))$rows[]=[$link['key'],$r['expenseFor'],$r['amount'],$r['advanceId'],$r['periods']];}
    return hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));
}
// A prepared entitlement may predate an advance opening. Suggest only this
// month's unapplied instalment; never repeat a recovery already posted for it.
function smw_pending_instalment(array $advance,string $month):float {
    $used=0.0;foreach((array)($advance['adjustments']??[]) as $adjustment)if(($adjustment['month']??'')===$month)$used+=(float)$adjustment['amount'];
    return round(max(0,rsv2_advance_suggestion([$advance])-$used),2);
}
function smw_prepared_recovery(array $s,array $row,string $entity,string $month):array {
    $posted=(float)($s['salaryPeriods'][$row['periodId']]['advanceApplied']??0);$extra=0.0;
    foreach(rsv2_open_advances($s,$entity,(string)$row['masterId'],$month) as $advance)$extra+=smw_pending_instalment($advance,$month);
    $unpaid=max(0,(float)$row['totalDue']-(float)$row['paidAfterPrepare']);
    $row['recoveryMaximum']=round(min($unpaid,$posted+(float)$row['advanceOutstanding']),2);
    if(empty($row['reviewed'])&&!array_key_exists('requestedRecovery',$row)){
        $suggested=round(min($unpaid,$posted+$extra),2);
        if(abs($suggested-(float)$row['advanceApplied'])>.005)$row['advanceReviewRequired']=true;
        $row['advanceApplied']=round(min($suggested,max(0,$unpaid-(float)($row['payment']['amount']??0))),2);
        $row['requestedRecovery']=$suggested;
        $row['outstanding']=round(max(0,$unpaid-$row['advanceApplied']-(float)($row['payment']['amount']??0)),2);
    }
    return $row;
}
function smw_fresh_sheet(array $s,string $entity,string $month):array {
    $rows=[];
    foreach((array)$s['salaryMasters'] as $m){
        if(!is_array($m)||($m['entity']??'')!==$entity)continue;
        $periodId=rsv2_pid($entity,$month,(string)$m['id']);
        if(!rsv2_active($m,$month)&&!isset($s['salaryPeriods'][$periodId]))continue;
        $row=rsv2_salary_row($s,$m,$month);
        $period=$s['salaryPeriods'][$row['periodId']]??null;
        $row['baseline']=$period===null?'':rsv2_fingerprint($period);
        $row['advanceApplied']=$period===null?min($row['totalDue'],$row['advanceSuggested']):$row['advanceApplied'];
        $row['outstanding']=round(max(0,$row['totalDue']-$row['advanceApplied']-$row['paidAfterPrepare']),2);
        $row['originalAdvanceApplied']=$row['advanceApplied'];$row['reviewed']=false;$row['payment']=null;
        if($period!==null)$row=smw_prepared_recovery($s,$row,$entity,$month);
        $rows[$row['masterId']]=$row;
    }
    $sheet=['entity'=>$entity,'month'=>$month,'version'=>0,'status'=>'Draft','rows'=>$rows,'advanceSignature'=>smw_advance_signature($s,$entity,$month),'personalSignature'=>smw_personal_signature($s,$entity,$month),'masterSignature'=>smw_master_signature($s,$entity,$month)];
    // Prepared periods own this month's entitlement. Later master defaults must not
    // prevent settlement or silently rewrite the original accrued salary.
    return $sheet;
}
function smw_sheet(array $s,string $entity,string $month):array {
    $key=$entity.'|'.$month;
    $stored=$s['salarySheets'][$key]??null;
    if(!is_array($stored))return smw_fresh_sheet($s,$entity,$month);
    if(($stored['status']??'Draft')==='Completed')return $stored;
    foreach($stored['rows'] as &$row)if(!empty($row['prepared'])&&($row['baseline']??'')===rsv2_fingerprint($s['salaryPeriods'][$row['periodId']]??[]))$row=smw_prepared_recovery($s,$row,$entity,$month);
    unset($row);
    $fresh=smw_fresh_sheet($s,$entity,$month);
    if(($stored['personalSignature']??smw_personal_signature([],$entity,$month))!==$fresh['personalSignature']){$stored['sourceInputsChanged']=true;$stored['masterChanged']=true;$stored['canRefreshFromMaster']=!array_filter((array)$stored['rows'],static fn($row)=>!empty($row['prepared']));return $stored;}
    if(($stored['advanceSignature']??'')!==$fresh['advanceSignature']){
        $advanceChanged=false;foreach((array)$stored['rows'] as $id=>$row)if(isset($fresh['rows'][$id])&&abs((float)($row['advanceOutstanding']??0)-(float)$fresh['rows'][$id]['advanceOutstanding'])>.005)$advanceChanged=true;
        if($advanceChanged){$stored['sourceInputsChanged']=true;$stored['masterChanged']=true;$stored['canRefreshFromMaster']=!array_filter((array)$stored['rows'],static fn($row)=>!empty($row['prepared']));return $stored;}
    }
    if(isset($stored['masterSignature']))$changed=!hash_equals((string)$stored['masterSignature'],$fresh['masterSignature']);
    else{
        $fields=['name','category','netSalary','zakatAmount','otherAllowance','accountingTreatment','productionCostEligible'];
        $storedRows=(array)($stored['rows']??[]);
        $changed=array_diff(array_keys($storedRows),array_keys($fresh['rows']))||array_diff(array_keys($fresh['rows']),array_keys($storedRows));
        foreach($fresh['rows'] as $id=>$row)if(!$changed){foreach($fields as $field){
            $old=$storedRows[$id][$field]??null;$current=$row[$field]??null;
            if(is_numeric($old)&&is_numeric($current)?abs((float)$old-(float)$current)>.005:$old!==$current){$changed=true;break;}
        }}
    }
    // A fully accrued sheet can keep its saved payment choices when only future
    // staff defaults changed. New/unprepared people and recovery-source changes
    // still require review; every payment also checks the exact period baseline.
    $preparedOnly=!empty($stored['rows'])&&empty($stored['masterChanges'])
        &&!array_diff_key($stored['rows'],$fresh['rows'])&&!array_diff_key($fresh['rows'],$stored['rows']);
    foreach((array)$stored['rows'] as $id=>$row)if(empty($row['prepared'])||empty($fresh['rows'][$id]['prepared']))$preparedOnly=false;
    if($preparedOnly){
        unset($stored['masterChanged'],$stored['canRefreshFromMaster']);
        $stored['masterSignature']=$fresh['masterSignature'];
        return $stored;
    }
    if(!$changed){
        if(!empty($fresh['masterChanged'])){$stored['masterChanged']=true;$stored['canRefreshFromMaster']=false;}
        return $stored;
    }
    $rows=(array)($stored['rows']??[]);
    $prepared=array_filter($rows,static fn($row)=>!empty($row['prepared']));
    $reviewed=array_filter($rows,static fn($row)=>!empty($row['reviewed']));
    if(!$prepared&&!$reviewed&&empty($stored['masterChanges'])){$fresh['version']=(int)($stored['version']??0)+1;return $fresh;}
    $stored['masterChanged']=true;
    $stored['canRefreshFromMaster']=!$prepared;
    return $stored;
}
function smw_check_baseline(array $s,array $row):void {
    $period=$s['salaryPeriods'][$row['periodId']]??null;
    $actual=$period===null?'':rsv2_fingerprint($period);
    if($actual!==$row['baseline'])throw new DomainException('Salary balances changed after this sheet was opened. Refresh the sheet before completing it.');
}
/** Monthly recurring edits remain drafts until the final successful posting. */
function smw_edit_row(array &$s,string $entity,string $month,array $b,array $u):array {
    $key=$entity.'|'.$month;$sheet=smw_sheet($s,$entity,$month);
    if(($sheet['status']??'')==='Completed')throw new DomainException('This month is completed. Edit future Salary Master or amend its Post ID.');
    if((int)($b['version']??-1)!==$sheet['version']||!empty($sheet['masterChanged']))throw new DomainException('Salary data changed. Refresh this draft first.');
    $id=trim((string)($b['masterId']??''));$row=$sheet['rows'][$id]??null;$master=$s['salaryMasters'][$id]??null;
    if($row&&!empty($row['prepared']))throw new DomainException('This salary was already posted. Keep its history and edit future Salary Master instead.');
    if($master&&($master['entity']??'')!==$entity)throw new DomainException('Select staff in this company.');
    if(($b['operation']??'')==='remove'){
        if(!$row)throw new DomainException('Select a salary row.');
        foreach(sfw_groups($sheet) as $transactions)foreach($transactions as $t)foreach($t['allocations'] as $a)if(($a['masterId']??'')===$id)throw new DomainException('Remove or reallocate this person’s saved payments before removing the salary row.');
        $sheet['masterChanges'][$id]=['operation'=>'remove','baseline'=>isset($master)?rsv2_fingerprint($master):''];unset($sheet['rows'][$id]);
    }else{
        $name=trim((string)($b['name']??$row['name']??''));$category=rsv2_cat((string)($b['category']??$row['category']??'OFFICE_STAFF'));
        if($name==='')throw new DomainException('Enter the staff name.');
        if($entity!=='TTI'&&$category==='MILL_STAFF')throw new DomainException('Mill staff belong to TTI.');
        foreach($sheet['rows'] as $otherId=>$other)if($otherId!==$id&&rsv2_norm($other['name'])===rsv2_norm($name))throw new DomainException('This person already appears in this month.');
        foreach((array)$s['salaryMasters'] as $otherId=>$other)if(($other['entity']??'')===$entity&&$otherId!==$id&&($other['status']??'Active')==='Active'&&rsv2_norm($other['name'])===rsv2_norm($name))throw new DomainException('This person already exists in Salary Master. Edit their existing row.');
        $amounts=[];foreach(['netSalary','zakatAmount','otherAllowance'] as $field){$value=$b[$field]??$row[$field]??0;if(!is_numeric($value)||!is_finite((float)$value)||(float)$value<0||(float)$value>100000000000)throw new DomainException('Enter valid recurring salary amounts.');$amounts[$field]=round((float)$value,2);}
        if(array_sum($amounts)<=0)throw new DomainException('Total recurring salary must be positive.');
        if($id==='')$id='SALM-'.bin2hex(random_bytes(8));elseif(!$row)throw new DomainException('Select a salary row.');
        $treatment=rsv2_treatment((string)($b['accountingTreatment']??$row['accountingTreatment']??'STAFF_COST'),$category);
        $next=array_merge($master??[],['id'=>$id,'entity'=>$entity,'name'=>$name,'category'=>$category,'monthlyAmount'=>$amounts['netSalary'],'zakatAmount'=>$amounts['zakatAmount'],'otherAllowance'=>$amounts['otherAllowance'],'accountingTreatment'=>$treatment,'productionCostEligible'=>$category==='MILL_STAFF'&&$treatment==='STAFF_COST','status'=>'Active','effectiveFrom'=>$master['effectiveFrom']??$month.'-01','effectiveTo'=>$master['effectiveTo']??'']);
        $fresh=rsv2_salary_row($s,$next,$month);$fresh['baseline']='';$fresh['advanceApplied']=min($fresh['totalDue'],$fresh['advanceSuggested']);$fresh['originalAdvanceApplied']=$fresh['advanceApplied'];$fresh['outstanding']=round(max(0,$fresh['totalDue']-$fresh['advanceApplied']-$fresh['paidAfterPrepare']),2);$fresh['reviewed']=false;$fresh['payment']=null;
        $sheet['rows'][$id]=$fresh;$sheet['masterChanges'][$id]=['operation'=>'update','master'=>$next,'baseline'=>$master?rsv2_fingerprint($master):''];
    }
    if(isset($sheet['paymentGroups']))sfw_recalculate($sheet);
    $sheet['version']++;$sheet['updatedAt']=gmdate('c');$s['salarySheets'][$key]=$sheet;return ['masterId'=>$id,'draft'=>true];
}

function smw_action(array &$s,string $entity,string $month,array $b,array $u,array $names):array {
    $key=$entity.'|'.$month;$sheet=smw_sheet($s,$entity,$month);
    $complete=($b['action']??'')==='complete_salary_month';
    if($sheet['status']==='Completed'){
        if($complete)return ['journalId'=>$sheet['journalId'],'duplicate'=>true];
        throw new DomainException('This month is completed. Amend its Post ID with a reason.');
    }
    if((int)($b['version']??-1)!==$sheet['version'])throw new DomainException('Another salary draft changed. Refresh and review the latest sheet.');
    if(($b['action']??'')==='reset_salary_draft'){
        if(!src_allowed($u,$entity))throw new DomainException('Director or Super Admin Edit permission is required to reset a salary draft.');
        $reason=trim((string)($b['reason']??''));if(strlen($reason)<5)throw new DomainException('Enter the reason for resetting this salary draft.');
        $fresh=smw_fresh_sheet($s,$entity,$month);
        $fresh['version']=$sheet['version']+1;$fresh['updatedAt']=gmdate('c');
        $s['salaryDraftResets'][]=['entity'=>$entity,'month'=>$month,'reason'=>$reason,'before'=>$s['salarySheets'][$key]??$sheet,'at'=>gmdate('c'),'by'=>$u['username']??$u['full_name']??'Accounts'];
        $s['salarySheets'][$key]=$fresh;
        return ['draftReset'=>true,'postedEntriesPreserved'=>true];
    }
    if(($b['action']??'')==='refresh_salary_draft'){
        if(empty($sheet['masterChanged'])||empty($sheet['canRefreshFromMaster']))throw new DomainException('This sheet cannot be refreshed from Salary Master.');
        if(!empty($sheet['sourceInputsChanged'])&&($sheet['masterSignature']??'')===smw_master_signature($s,$entity,$month))return sfw_refresh_sources($s,$entity,$month,$sheet);
        $fresh=smw_fresh_sheet($s,$entity,$month);
        $fresh['version']=$sheet['version']+1;
        $fresh['updatedAt']=gmdate('c');
        $s['salarySheets'][$key]=$fresh;
        return ['refreshed'=>true];
    }
    if(!empty($sheet['masterChanged'])&&!empty($sheet['canRefreshFromMaster']))throw new DomainException('Salary Master changed. Refresh this draft and review the payments again.');
    if(!$complete){
        if(isset($sheet['paymentGroups']))throw new DomainException('Salary payments now use transaction rows. Reload Accounts to review and save this draft.');
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
        $row['advanceApplied']=round(min($row['prepared']?$row['advanceApplied']:min($row['totalDue'],$row['advanceSuggested']),max(0,$maximum-$amount)),2);
        $row['outstanding']=round(max(0,$maximum-$row['advanceApplied']-$amount),2);
        $row['reviewed']=true;unset($row['advanceReviewRequired']);$row['payment']=['amount'=>$amount,'date'=>$date,'paymentAccountId'=>$source,'parts'=>$parts]+$tracking;
        $sheet['rows'][$mid]=$row;$sheet['version']++;$sheet['updatedAt']=gmdate('c');
        $s['salarySheets'][$key]=$sheet;return ['saved'=>true,'masterId'=>$mid];
    }
    if(!$sheet['rows'])throw new DomainException('There are no active salary records for this month.');
    foreach((array)($sheet['masterChanges']??[]) as $mid=>$change){$current=$s['salaryMasters'][$mid]??null;if(($current?rsv2_fingerprint($current):'')!==$change['baseline'])throw new DomainException('Salary Master changed. Refresh before posting.');}

    $lines=[];$date=rsv2_date((string)($b['date']??gmdate('Y-m-d')));
    foreach($sheet['rows'] as $mid=>$row){
        if(!$row['reviewed'])throw new DomainException('Review and save the payment or zero-payment deferral for '.$row['name'].'.');
        smw_check_baseline($s,$row);
        if($row['accountingTreatment']==='TO_CONFIRM')throw new DomainException('Choose accounting treatment in Salary Master for '.$row['name'].'.');
        $meta=['salaryMasterId'=>$mid,'person'=>$row['name'],'party'=>$row['name'],'subledgerId'=>$mid,'month'=>$month,'category'=>$row['category'],'accountingTreatment'=>$row['accountingTreatment'],'productionCostEligible'=>$row['productionCostEligible']];
        $applied=$row['advanceApplied'];$companyHome=$row['category']==='HOME_MONTHLY_GIVE'&&!empty($row['companyHomeExpense']);$homeMeta=$companyHome?sea_home_meta($s,$entity,$row):[];
        if(!$row['prepared']){
            foreach([[$companyHome?'6910':($row['accountingTreatment']==='FAMILY_ALLOCATION'?'3200':'6210'),round($row['netSalary']+(float)($row['monthlyAddition']??0)-(float)($row['monthlyReduction']??0),2)],['7210',$row['zakatAmount']],[$companyHome?'6910':($row['accountingTreatment']==='FAMILY_ALLOCATION'?'3200':'6220'),$row['otherAllowance']]] as [$account,$amount])if($amount>.005)$lines[]=rsv2_line($account,$amount,0,$names,$meta+($account==='6910'?$homeMeta:[]));
            if($applied>.005)$lines[]=rsv2_line('1230',0,$applied,$names,$meta+['salaryAdvanceApplied'=>$applied]);
            if($row['totalDue']-$applied>.005)$lines[]=rsv2_line('2230',0,$row['totalDue']-$applied,$names,$meta);
            $available=array_sum(array_column(rsv2_open_advances($s,$entity,$mid,$month),'remaining'));
            if($applied>$available+.005)throw new DomainException('Advance balance changed for '.$row['name'].'. Amend this payment before completing.');
        }else{
            if($companyHome&&empty($s['salaryPeriods'][$row['periodId']]['companyHomeExpense'])){$base=round($row['netSalary']+(float)($row['monthlyAddition']??0)-(float)($row['monthlyReduction']??0)+(float)$row['otherAllowance'],2);$old=$row['accountingTreatment']==='FAMILY_ALLOCATION'?'3200':'6210';if($base>.005){$lines[]=rsv2_line('6910',$base,0,$names,$meta+$homeMeta);if($old==='3200')$lines[]=rsv2_line('3200',0,$base,$names,$meta);else{foreach([['6210',$base-$row['otherAllowance']],['6220',$row['otherAllowance']]] as [$code,$part])if($part>.005)$lines[]=rsv2_line($code,0,$part,$names,$meta);}}}
            $previous=(float)$s['salaryPeriods'][$row['periodId']]['advanceApplied'];$restore=round($previous-$applied,2);
            if($restore>.005){$lines[]=rsv2_line('1230',$restore,0,$names,$meta);$lines[]=rsv2_line('2230',0,$restore,$names,$meta);}
            if($restore<-.005){
                $available=array_sum(array_column(rsv2_open_advances($s,$entity,$mid,$month),'remaining'));
                if(-$restore>$available+.005)throw new DomainException('Advance balance changed for '.$row['name'].'. Refresh before completing.');
                $lines[]=rsv2_line('2230',-$restore,0,$names,$meta);$lines[]=rsv2_line('1230',0,-$restore,$names,$meta+['salaryAdvanceApplied'=>-$restore]);
            }
        }
        $payment=$row['payment'];
        if(!isset($sheet['paymentGroups'])&&$payment['amount']>.005){
            $lines[]=rsv2_line('2230',$payment['amount'],0,$names,$meta);
            $parts=$payment['parts']??[['amount'=>$payment['amount'],'paymentAccountId'=>$payment['paymentAccountId']]+$payment];
            $sum=0;foreach($parts as $part){$partAmount=round((float)$part['amount'],2);if($partAmount<=0)throw new DomainException('Invalid saved salary split.');$sum+=(int)round($partAmount*100);$tracking=str_starts_with($part['paymentAccountId'],'CASH|')?[]:tt_accounts_bank_payment_details(array_merge($part,['date'=>$payment['date']]));$lines[]=array_merge(rsv2_pay_line($s,$entity,$part['paymentAccountId'],$partAmount,$names),$meta,$tracking,['paymentDate'=>$payment['date']]);}
            if($sum!==(int)round($payment['amount']*100))throw new DomainException('Saved salary portions do not equal the payment.');
        }
    }
    $groupJournals=isset($sheet['paymentGroups'])?sfw_post_groups($s,$entity,$month,$sheet,$u,$names):[];
    if(!$lines&&!$groupJournals)throw new DomainException('This month has no new accounting entries to post.');
    $jid=$lines?rsv2_journal($s,$entity,isset($sheet['paymentGroups'])?rsv2_mend($month):$date,'SALARY_MONTH_COMPLETED','SAL-'.$month,'Salary completed — '.$month,$lines,$u,['month'=>$month,'salarySheetKey'=>$key,'paymentPostIds'=>$groupJournals]):$groupJournals[0];
    foreach($groupJournals as $paymentId)$s['journals'][$paymentId]['meta']['salaryMonthPostId']=$jid;
    foreach((array)($sheet['masterChanges']??[]) as $mid=>$change){
        $before=$s['salaryMasters'][$mid]??null;
        if($change['operation']==='remove'){if(!$before)continue;$after=$before;$after['status']='Inactive';$after['effectiveTo']=rsv2_prev_day($month.'-01');}
        else $after=$change['master'];
        $after['updatedAt']=gmdate('c');$after['updatedBy']=(string)($u['full_name']??$u['username']??'Accounts');$s['salaryMasters'][$mid]=$after;
        $s['salaryMasterHistory'][]=['masterId'=>$mid,'month'=>$month,'journalId'=>$jid,'operation'=>$change['operation'],'before'=>$before,'after'=>$after,'userId'=>$u['id']??0,'at'=>gmdate('c')];
    }
    foreach($sheet['rows'] as $mid=>$row){
        $pid=$row['periodId'];$applied=$row['advanceApplied'];
        if(!$row['prepared']){
            $left=$applied;
            foreach(rsv2_open_advances($s,$entity,$mid,$month) as $advance){if($left<=.005)break;$id=$advance['id'];$take=min($left,$s['salaryAdvances'][$id]['remaining']);$s['salaryAdvances'][$id]['remaining']=round($s['salaryAdvances'][$id]['remaining']-$take,2);$s['salaryAdvances'][$id]['monthsRemaining']=max(0,(int)($s['salaryAdvances'][$id]['monthsRemaining']??1)-1);$s['salaryAdvances'][$id]['adjustments'][]=['month'=>$month,'amount'=>$take,'journalId'=>$jid];$left=round($left-$take,2);}
        }else{
            $restore=round($s['salaryPeriods'][$pid]['advanceApplied']-$applied,2);
            if($restore<-.005){
                $left=-$restore;
                foreach([true,false] as $scheduled)foreach(rsv2_open_advances($s,$entity,$mid,$month) as $advance){if($left<=.005)break;$id=$advance['id'];$take=min($left,$scheduled?smw_pending_instalment($advance,$month):(float)$advance['remaining']);if($take<=.005)continue;$already=false;foreach((array)$advance['adjustments'] as $adjustment)if(($adjustment['month']??'')===$month&&(float)$adjustment['amount']>.005)$already=true;
                    $s['salaryAdvances'][$id]['remaining']=round($s['salaryAdvances'][$id]['remaining']-$take,2);if(!$already)$s['salaryAdvances'][$id]['monthsRemaining']=max(0,(int)($advance['monthsRemaining']??1)-1);$s['salaryAdvances'][$id]['adjustments'][]=['month'=>$month,'amount'=>$take,'journalId'=>$jid];$left=round($left-$take,2);
                }
                if($left>.005)throw new DomainException('Advance recovery could not be allocated. Refresh this salary period.');
            }
            foreach($s['salaryAdvances'] as &$advance){if(($advance['entity']??'')!==$entity||($advance['masterId']??'')!==$mid)continue;foreach($advance['adjustments'] as &$adjustment){if($restore<=.005)break;if(($adjustment['month']??'')!==$month)continue;$take=min($restore,(float)$adjustment['amount']);$adjustment['amount']=round($adjustment['amount']-$take,2);$advance['remaining']=round($advance['remaining']+$take,2);$restore=round($restore-$take,2);}unset($adjustment);}unset($advance);
            if($restore>.005)throw new DomainException('The original advance adjustment cannot be restored. Review this salary period.');
        }
        $s['salaryPeriods'][$pid]=array_merge($s['salaryPeriods'][$pid]??[],['id'=>$pid,'entity'=>$entity,'month'=>$month,'salaryMasterId'=>$mid,'name'=>$row['name'],'category'=>$row['category'],'accountingTreatment'=>$row['accountingTreatment'],'companyHomeExpense'=>!empty($row['companyHomeExpense']),'netSalary'=>$row['netSalary'],'zakatAmount'=>$row['zakatAmount'],'otherAllowance'=>$row['otherAllowance'],'monthlyAddition'=>$row['monthlyAddition']??0,'monthlyReduction'=>$row['monthlyReduction']??0,'adjustmentReason'=>$row['adjustmentReason']??'','gross'=>$row['totalDue'],'advanceApplied'=>$applied,'paidAfterPrepare'=>round($row['paidAfterPrepare']-(float)($s['salaryPeriods'][$pid]['personalDeducted']??0)+$row['payment']['amount'],2),'outstanding'=>$row['outstanding'],'journalId'=>$jid,'productionCostEligible'=>$row['productionCostEligible'],'status'=>$row['outstanding']>.005?'Payable':'Paid','preparedAt'=>gmdate('c')]);
        if(!isset($sheet['paymentGroups'])&&$row['payment']['amount']>.005){$id=rsv2_next((array)$s['rentSalaryPayments'],'RSP');$s['rentSalaryPayments'][$id]=['id'=>$id,'type'=>'SALARY','entity'=>$entity,'masterId'=>$mid,'month'=>$month,'journalId'=>$jid]+$row['payment'];}
    }
    $sheet['paymentPostIds']=$groupJournals;$sheet['status']='Completed';$sheet['journalId']=$jid;$sheet['completedAt']=gmdate('c');$sheet['version']++;$s['salarySheets'][$key]=$sheet;
    $s['journals'][$jid]['meta']['salarySummary']=src_summary($s,$entity,$month,$sheet);
    return ['journalId'=>$jid];
}
