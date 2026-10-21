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
    $traced=0.0;foreach((array)($s['salaryAdvances']??[]) as $advance)if(($advance['entity']??'')===$entity&&($advance['masterId']??'')===$row['masterId'])foreach((array)($advance['adjustments']??[]) as $adjustment)if(($adjustment['month']??'')===$month)$traced+=(float)$adjustment['amount'];
    // Historical periods without matching source adjustments need reconciliation,
    // not an inferred second instalment on opening the payment form.
    if($posted>$traced+.005)$extra=0.0;
    $unpaid=max(0,(float)$row['totalDue']-(float)$row['paidAfterPrepare']);
    $suggested=round(min($unpaid,$posted+$extra),2);
    // Old prepared forms forced recovery to zero. If its saved payment already
    // leaves room for the scheduled instalment, retain that payment and require
    // review of the recovered deduction. New explicit zero choices stay zero.
    $legacyZero=empty($row['recoveryChoiceSaved'])&&(float)($row['originalAdvanceApplied']??0)<.005&&(float)($row['advanceApplied']??0)<.005&&(float)($row['requestedRecovery']??0)<.005;
    if($legacyZero&&$suggested>.005&&$unpaid-(float)($row['payment']['amount']??0)>=$suggested-.005){$row['reviewed']=false;unset($row['requestedRecovery']);}
    $row['recoveryMaximum']=round(min($unpaid,$posted+(float)$row['advanceOutstanding']),2);
    if(empty($row['reviewed'])&&!array_key_exists('requestedRecovery',$row)){
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
        if(($s['salaryPeriods'][$periodId]['status']??'')==='Cancelled')continue;
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
    foreach($stored['rows'] as &$row)if(empty($row['groupPosted'])&&!empty($row['prepared'])&&($row['baseline']??'')===rsv2_fingerprint($s['salaryPeriods'][$row['periodId']]??[]))$row=smw_prepared_recovery($s,$row,$entity,$month);
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
/** Explicit Master application; reads never change accrued entries or saved plans. */
function smw_apply_master(array &$s,string $entity,string $month,array $b,array $u,array $names,?array $only=null):array {
    if(!src_allowed($u,$entity))throw new DomainException('Director or Super Admin access is required. Open Master Records → Salary Staff, then return to Salaries & Staff.');
    $key=$entity.'|'.$month;$sheet=smw_sheet($s,$entity,$month);
    if(($sheet['status']??'')==='Completed')throw new DomainException('This month is completed. Use Salaries & Staff → Payments / Amend for payments, or Correct month dates / accounts for posted records.');
    if((int)($b['version']??-1)!==(int)$sheet['version'])throw new DomainException('Salary data changed. Close the payment form, press Refresh, then reopen the person. Your saved payments are retained.');
    $reason=trim((string)($b['reason']??''));if(strlen($reason)<5)throw new DomainException('Enter why the current Salary Master applies to this month.');
    $groups=sfw_groups($sheet);$paid=[];foreach($groups as $txs)foreach($txs as $tx)if(($tx['status']??'')!=='Reversed')foreach($tx['allocations'] as $allocation)foreach(sea_charges($allocation) as $charge)$paid[$charge['masterId']]=round(($paid[$charge['masterId']]??0)+$charge['amount'],2);
    $lines=[];$changed=[];$removed=[];$kept=[];$baselineSignature=smw_master_signature($s,$entity,$month);
    foreach($sheet['rows'] as $id=>&$row){
        if(!empty($row['groupPosted']))continue;
        if($only!==null&&!in_array($id,$only,true))continue;
        $master=$s['salaryMasters'][$id]??null;$active=is_array($master)&&rsv2_active($master,$month);
        smw_check_baseline($s,$row);$period=$s['salaryPeriods'][$row['periodId']]??null;
        if(!$active){
            if(in_array($id,(array)($b['keepInactivePlans']??[]),true)&&((float)($paid[$id]??0)>.005||(float)$row['paidAfterPrepare']>.005||(float)$row['advanceApplied']>.005)){$kept[]=$id;continue;}
            if(array_filter((array)($groups[$id]??[]),static fn($tx)=>($tx['status']??'')!=='Reversed'&&(float)($tx['amount']??0)>.005)||($paid[$id]??0)>.005||(float)$row['paidAfterPrepare']>.005||(float)$row['advanceApplied']>.005)throw new DomainException($row['name'].' has a payment or advance recovery. Open Salaries & Staff → Payments / Amend, clear or reallocate that person’s payment/recovery, then remove the person.');
            $next=['netSalary'=>0,'zakatAmount'=>0,'otherAllowance'=>0];$removed[]=$id;
        }else $next=['netSalary'=>(float)$master['monthlyAmount'],'zakatAmount'=>(float)($master['zakatAmount']??0),'otherAllowance'=>(float)($master['otherAllowance']??0)];
        if(is_array($period)){
            $nextGross=$active?round(array_sum($next)+(float)($row['monthlyAddition']??0)-(float)($row['monthlyReduction']??0),2):0.0;
            if($nextGross+0.005<(float)$row['paidAfterPrepare']+(float)$row['advanceApplied']+($paid[$id]??0))throw new DomainException($row['name'].' has payments above the new Master entitlement. Open Salaries & Staff → Payments / Amend and correct the allocation first.');
            if($active&&($master['accountingTreatment']??'')!==$row['accountingTreatment'])throw new DomainException('Accounting treatment changed for '.$row['name'].'. Open the original salary Post ID for correction before applying a different expense/capital treatment.');
            $meta=['salaryMasterId'=>$id,'person'=>$row['name'],'party'=>$row['name'],'month'=>$month,'salaryMasterSync'=>true,'reason'=>$reason];
            $home=!empty($period['companyHomeExpense']);$base=$home?'6910':($row['accountingTreatment']==='FAMILY_ALLOCATION'?'3200':'6210');$allow=$home?'6910':($row['accountingTreatment']==='FAMILY_ALLOCATION'?'3200':'6220');
            $changes=[[$base,$next['netSalary']-(float)$row['netSalary']],['7210',$next['zakatAmount']-(float)$row['zakatAmount']],[$allow,$next['otherAllowance']-(float)$row['otherAllowance']]];
            if(!$active){$extra=(float)($row['monthlyAddition']??0)-(float)($row['monthlyReduction']??0);$changes[]=[$base,-$extra];}
            foreach($changes as [$code,$delta]){if(abs($delta)>.005)$lines[]=rsv2_line($code,max(0,$delta),max(0,-$delta),$names,$meta);}
            $delta=round($nextGross-(float)$period['gross'],2);if(abs($delta)>.005)$lines[]=rsv2_line('2230',max(0,-$delta),max(0,$delta),$names,$meta);
            $p=&$s['salaryPeriods'][$row['periodId']];if($active)$p=array_merge($p,$next,['gross'=>$nextGross,'outstanding'=>round(max(0,$nextGross-(float)$p['advanceApplied']-(float)$p['paidAfterPrepare']-(float)($p['personalDeducted']??0)),2)]);else $p['outstanding']=0;
            if($active){$p['name']=$master['name'];$p['category']=$master['category'];}else{$p['status']='Cancelled';$p['cancelledFromMasterAt']=gmdate('c');}unset($p);
        }
        if($active)$changed[]=$id;
    }unset($row);
    $dated=[];foreach($lines as $line){$period=$s['salaryPeriods'][rsv2_pid($entity,$month,(string)$line['salaryMasterId'])]??[];$date=(string)($s['journals'][$period['journalId']??'']['date']??rsv2_mend($month));if(substr($date,0,7)!==$month)throw new DomainException('The original salary posting date is outside '.$month.'. Open Salaries & Staff → Correct month dates / accounts before applying Master changes.');$dated[$date][]=$line;}
    $journalIds=[];foreach($dated as $date=>$datedLines)$journalIds[]=rsv2_journal($s,$entity,$date,'SALARY_MASTER_ADJUSTMENT','SALARY-MASTER-'.$month,'Salary Master applied — '.$month.' — '.$reason,$datedLines,$u,['month'=>$month,'salarySheetKey'=>$key,'reason'=>$reason]);$jid=$journalIds?end($journalIds):'';
    $fresh=smw_fresh_sheet($s,$entity,$month);$nextSheet=$sheet;$nextSheet['paymentGroups']=$groups;
    foreach($removed as $id){unset($nextSheet['rows'][$id],$nextSheet['paymentGroups'][$id],$nextSheet['masterChanges'][$id]);}
    foreach($fresh['rows'] as $id=>$current){if($only!==null&&!in_array($id,$only,true))continue;$old=$sheet['rows'][$id]??null;if(!empty($old['groupPosted']))continue;
        if($old){$different=false;foreach(['name','category','totalDue','netSalary','zakatAmount','otherAllowance','advanceApplied'] as $field){ $before=$old[$field]??null;$after=$current[$field]??null;if(is_numeric($before)&&is_numeric($after)?abs((float)$before-(float)$after)>.005:$before!==$after)$different=true; }$current['reviewed']=!$different&&!empty($old['reviewed']);$current['payment']=$old['payment']??null;if(!empty($old['recoveryChoiceSaved'])){$current['requestedRecovery']=$old['requestedRecovery']??$old['advanceApplied'];$current['recoveryChoiceSaved']=true;}}
        $current['oneOffInactive']=in_array($id,$kept,true);$nextSheet['rows'][$id]=$current;
    }
    foreach($kept as $id)if(isset($nextSheet['rows'][$id]))$nextSheet['rows'][$id]['oneOffInactive']=true;
    sfw_recalculate($nextSheet);$nextSheet['version']=(int)$sheet['version']+1;$nextSheet['updatedAt']=gmdate('c');
    if($only===null){unset($nextSheet['masterChanges']);$nextSheet['masterSignature']=$fresh['masterSignature'];$nextSheet['advanceSignature']=$fresh['advanceSignature'];$nextSheet['personalSignature']=$fresh['personalSignature'];unset($nextSheet['masterChanged'],$nextSheet['canRefreshFromMaster'],$nextSheet['sourceInputsChanged']);}
    $s['salaryMasterApplications'][]=['entity'=>$entity,'month'=>$month,'journalId'=>$jid,'reason'=>$reason,'by'=>$u['username']??'','at'=>gmdate('c'),'removed'=>$removed,'changed'=>$changed,'keptInactive'=>$kept,'beforeSheet'=>$sheet];$s['salarySheets'][$key]=$nextSheet;
    return ['masterApplied'=>true,'journalId'=>$jid,'journalIds'=>$journalIds,'removed'=>$removed,'keptInactive'=>$kept,'paymentsPreserved'=>true];
}
function smw_leave(array &$s,string $entity,string $month,array $b,array $u,array $names):array {
    if(!src_allowed($u,$entity))throw new DomainException('Ask a Director or Super Admin to remove staff from future salary months.');
    $id=(string)($b['masterId']??'');$master=$s['salaryMasters'][$id]??null;if(!$master||($master['entity']??'')!==$entity)throw new DomainException('Select staff in this company.');
    $old=$master;$replacementId='';$sheet=smw_sheet($s,$entity,$month);$row=$sheet['rows'][$id]??null;if(!empty($row['groupPosted']))throw new DomainException('This salary group is posted. Amend its Post ID before changing this month.');
    if(!empty($b['preserveReplacement'])&&is_array($row)&&rsv2_norm((string)$master['name'])!==rsv2_norm((string)$row['name'])){
        $replacementId=rsv2_next((array)$s['salaryMasters'],'SALM');$replacement=$master;$replacement['id']=$replacementId;$replacement['effectiveFrom']=$month.'-01';$replacement['effectiveTo']='';$replacement['status']='Active';$replacement['replacesMasterId']=$id;$replacement['createdAt']=gmdate('c');unset($replacement['inactiveFromMonth']);$s['salaryMasters'][$replacementId]=$replacement;
        $s['salaryMasters'][$id]['name']=$row['name'];$s['salaryMasters'][$id]['monthlyAmount']=$row['netSalary'];$s['salaryMasters'][$id]['zakatAmount']=$row['zakatAmount'];$s['salaryMasters'][$id]['otherAllowance']=$row['otherAllowance'];
    }
    $end=(new DateTimeImmutable($month.'-01'))->modify('-1 day')->format('Y-m-d');
    $s['salaryMasters'][$id]['status']='Inactive';$s['salaryMasters'][$id]['inactiveFromMonth']=$month;$s['salaryMasters'][$id]['effectiveTo']=max((string)($master['effectiveFrom']??''),$end);$s['salaryMasters'][$id]['updatedAt']=gmdate('c');$s['salaryMasters'][$id]['updatedBy']=$u['full_name']??$u['username']??'';
    $result=smw_apply_master($s,$entity,$month,$b,$u,$names,[$id]);
    $future=[];foreach((array)($s['salaryPeriods']??[]) as $period)if(($period['entity']??'')===$entity&&($period['salaryMasterId']??'')===$id&&($period['month']??'')>$month&&($period['status']??'')!=='Cancelled')$future[(string)$period['month']]=true;
    foreach(array_keys($future) as $nextMonth){$later=smw_sheet($s,$entity,$nextMonth);smw_apply_master($s,$entity,$nextMonth,array_merge($b,['version'=>$later['version']]),$u,$names,[$id]);}
    $s['salaryMasterHistory'][]=['masterId'=>$id,'month'=>$month,'operation'=>'leave','before'=>$old,'after'=>$s['salaryMasters'][$id],'replacementMasterId'=>$replacementId,'by'=>$u['username']??'','at'=>gmdate('c')];return $result+['replacementMasterId'=>$replacementId];
}
function smw_check_baseline(array $s,array $row):void {
    $period=$s['salaryPeriods'][$row['periodId']]??null;
    $actual=$period===null?'':rsv2_fingerprint($period);
    if($actual!==$row['baseline'])throw new DomainException('Salary balances changed after this sheet was opened. Refresh the sheet before completing it.');
}
/** Monthly recurring edits remain drafts until the final successful posting. */
function smw_edit_row(array &$s,string $entity,string $month,array $b,array $u):array {
    $key=$entity.'|'.$month;$sheet=smw_sheet($s,$entity,$month);
    if(!empty($sheet['rows'][(string)($b['masterId']??'')]['groupPosted']))throw new DomainException('This salary group is posted. Amend its Post ID before changing this month.');
    if(($sheet['status']??'')==='Completed')throw new DomainException('This month is completed. Edit future Salary Master or amend its Post ID.');
    if((int)($b['version']??-1)!==$sheet['version']||!empty($sheet['masterChanged']))throw new DomainException('Salary data changed. Refresh this draft first.');
    $id=trim((string)($b['masterId']??''));$row=$sheet['rows'][$id]??null;if(!empty($row['groupPosted']))throw new DomainException('This salary group is posted. Amend its Post ID before changing this month.');$master=$s['salaryMasters'][$id]??null;
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

/** Salary groups are posted independently; closing a month never posts money. */
function smw_group_key(array $row):string {
    return in_array($row['category'],['DIRECTOR_REMUNERATION','HOME_MONTHLY_GIVE'],true)?'DIRECTORS':(string)$row['category'];
}
function smw_close_month(array &$s,string $entity,string $month,array $b,array $u):array {
    $key=$entity.'|'.$month;$sheet=smw_sheet($s,$entity,$month);
    if($sheet['status']==='Completed')return ['journalId'=>$sheet['journalId'],'duplicate'=>true];
    if((int)($b['version']??-1)!==$sheet['version'])throw new DomainException('Salary data changed. Reopen Salaries & Staff and review the latest sheet.');
    if(!$sheet['rows'])throw new DomainException('There are no salary records to close.');
    foreach($sheet['rows'] as $row)if(empty($row['groupPosted']))throw new DomainException('Post all payments in '.smw_group_key($row).' before closing this month. Use its green Post all button.');
    if(!empty($sheet['masterChanged']))throw new DomainException('Salary Master changed. Review the current staff list before closing this month.');
    $sheet['paymentGroups']=array_replace((array)($sheet['postedPaymentGroups']??[]),(array)($sheet['paymentGroups']??[]));$sheet['status']='Completed';$sheet['completedAt']=gmdate('c');$sheet['closedBy']=$u['id']??0;$sheet['version']++;$s['salarySheets'][$key]=$sheet;
    return ['journalId'=>$sheet['journalId'],'monthClosed'=>true];
}
function smw_action(array &$s,string $entity,string $month,array $b,array $u,array $names):array {
    $key=$entity.'|'.$month;$sheet=smw_sheet($s,$entity,$month);
    $groupPost=($b['action']??'')==='post_salary_group';$group=(string)($b['group']??'');
    if($groupPost&&!in_array($group,['MILL_STAFF','HOME_STAFF','OFFICE_STAFF','DIRECTORS'],true))throw new DomainException('Choose a valid salary group.');
    if($groupPost&&isset($sheet['postedGroups'][$group]))return ['journalId'=>$sheet['postedGroups'][$group]['journalId'],'duplicate'=>true,'group'=>$group];
    $complete=$groupPost||($b['action']??'')==='complete_salary_month';
    if(!$groupPost&&$complete&&!empty($sheet['postedGroups']))return smw_close_month($s,$entity,$month,$b,$u);
    if($sheet['status']==='Completed'){
        if($complete)return ['journalId'=>$sheet['journalId'],'duplicate'=>true];
        throw new DomainException('This month is completed. Amend its Post ID with a reason.');
    }
    if((int)($b['version']??-1)!==$sheet['version'])throw new DomainException('Another salary draft changed. Refresh and review the latest sheet.');
    if(($b['action']??'')==='reset_salary_draft'){
        if(!empty($sheet['postedGroups']))throw new DomainException('A salary group is already posted. Use its Post ID to amend payments; reset cannot erase posted salary.');
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

    $wholeSheet=$sheet;
    if($groupPost){
        $sheet['rows']=array_filter($sheet['rows'],static fn($row)=>smw_group_key($row)===$group);
        if(!$sheet['rows'])throw new DomainException('This group has no salary records.');
        $allGroups=sfw_groups($wholeSheet);$sheet['paymentGroups']=array_intersect_key($allGroups,$sheet['rows']);
        foreach($allGroups as $owner=>$transactions)foreach($transactions as $tx)foreach($tx['allocations'] as $a)foreach(sea_charges($a) as $charge){
            if(isset($sheet['rows'][$owner])!==isset($sheet['rows'][$charge['masterId']]))throw new DomainException('A payment crosses salary groups. Amend its allocation before posting this group.');
        }
        $sheet['masterChanges']=array_intersect_key((array)($sheet['masterChanges']??[]),$sheet['rows']);
    }
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
    if(!$lines&&!$groupJournals&&!$groupPost)throw new DomainException('This month has no new accounting entries to post.');
    $jid=$lines?rsv2_journal($s,$entity,isset($sheet['paymentGroups'])?rsv2_mend($month):$date,'SALARY_MONTH_COMPLETED','SAL-'.$month,'Salary completed — '.$month,$lines,$u,['month'=>$month,'salarySheetKey'=>$key,'paymentPostIds'=>$groupJournals]):($groupJournals[0]??'');
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
        $s['salaryPeriods'][$pid]=array_merge($s['salaryPeriods'][$pid]??[],['id'=>$pid,'entity'=>$entity,'month'=>$month,'salaryMasterId'=>$mid,'name'=>$row['name'],'category'=>$row['category'],'accountingTreatment'=>$row['accountingTreatment'],'companyHomeExpense'=>!empty($row['companyHomeExpense']),'netSalary'=>$row['netSalary'],'zakatAmount'=>$row['zakatAmount'],'otherAllowance'=>$row['otherAllowance'],'monthlyAddition'=>$row['monthlyAddition']??0,'monthlyReduction'=>$row['monthlyReduction']??0,'adjustmentReason'=>$row['adjustmentReason']??'','gross'=>$row['totalDue'],'advanceApplied'=>$applied,'paidAfterPrepare'=>round($row['paidAfterPrepare']-(float)($s['salaryPeriods'][$pid]['personalDeducted']??0)+$row['payment']['amount'],2),'outstanding'=>$row['outstanding'],'journalId'=>$jid?:($s['salaryPeriods'][$pid]['journalId']??''),'productionCostEligible'=>$row['productionCostEligible'],'status'=>$row['outstanding']>.005?'Payable':'Paid','preparedAt'=>gmdate('c')]);
        if(!isset($sheet['paymentGroups'])&&$row['payment']['amount']>.005){$id=rsv2_next((array)$s['rentSalaryPayments'],'RSP');$s['rentSalaryPayments'][$id]=['id'=>$id,'type'=>'SALARY','entity'=>$entity,'masterId'=>$mid,'month'=>$month,'journalId'=>$jid]+$row['payment'];}
    }
    if($groupPost){
        $newGroupJournal=$jid;$jid=$jid?:($s['salaryPeriods'][reset($sheet['rows'])['periodId']]['journalId']??'');$postedAt=gmdate('c');$wholeSheet['paymentGroups']=sfw_groups($wholeSheet);
        foreach($sheet['rows'] as $mid=>$row){
            $period=$s['salaryPeriods'][$row['periodId']];$next=$row;
            $next['prepared']=true;$next['baseline']=rsv2_fingerprint($period);$next['paidAfterPrepare']=$period['paidAfterPrepare'];$next['outstanding']=$period['outstanding'];
            $next['originalAdvanceApplied']=$next['advanceApplied']=$next['requestedRecovery']=$period['advanceApplied'];$next['recoveryChoiceSaved']=true;$next['reviewed']=true;
            $next['payment']=null;$next['groupPosted']=true;$next['groupJournalId']=$jid;unset($next['advanceReviewRequired']);
            $wholeSheet['rows'][$mid]=$next;$wholeSheet['postedPaymentGroups'][$mid]=$wholeSheet['paymentGroups'][$mid]??[];unset($wholeSheet['paymentGroups'][$mid],$wholeSheet['masterChanges'][$mid]);
        }
        $wholeSheet['postedGroups'][$group]=['journalId'=>$jid,'paymentPostIds'=>$groupJournals,'postedAt'=>$postedAt,'postedBy'=>$u['id']??0];
        $wholeSheet['paymentPostIds']=array_values(array_unique(array_merge($wholeSheet['paymentPostIds']??[],$groupJournals)));
        if(empty($wholeSheet['journalId']))$wholeSheet['journalId']=$jid;
        $wholeSheet['advanceSignature']=smw_advance_signature($s,$entity,$month);$wholeSheet['personalSignature']=smw_personal_signature($s,$entity,$month);
        $wholeSheet['masterSignature']=smw_master_signature($s,$entity,$month);$wholeSheet['version']++;$wholeSheet['updatedAt']=$postedAt;$s['salarySheets'][$key]=$wholeSheet;
        foreach(array_filter(array_merge([$newGroupJournal],$groupJournals)) as $postId)$s['journals'][$postId]['meta']['salarySummary']=src_summary($s,$entity,$month,$sheet);
        return ['journalId'=>$jid,'paymentPostIds'=>$groupJournals,'group'=>$group,'groupPosted'=>true];
    }
    $sheet['paymentPostIds']=$groupJournals;$sheet['status']='Completed';$sheet['journalId']=$jid;$sheet['completedAt']=gmdate('c');$sheet['version']++;$s['salarySheets'][$key]=$sheet;
    $s['journals'][$jid]['meta']['salarySummary']=src_summary($s,$entity,$month,$sheet);
    return ['journalId'=>$jid];
}
