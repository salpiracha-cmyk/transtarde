<?php
declare(strict_types=1);
/** Draft-only monthly adjustments and actual payments. The endpoint holds the Accounts write lock. */
function sfw_recovery_month(array $a):string {
    if(!empty($a['recoveryFromMonth']))return (string)$a['recoveryFromMonth'];
    if(!empty($a['personalLink'])||($a['personalTreatment']??'')==='REMUNERATION'){
        $date=(string)($a['date']??'');if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))return (new DateTimeImmutable($date))->modify('first day of next month')->format('Y-m');
    }
    return substr((string)($a['date']??''),0,7);
}
function sfw_money(mixed $value,string $label):float {
    if(!is_numeric($value)||!is_finite((float)$value)||(float)$value<0||(float)$value>100000000000)throw new DomainException('Enter a valid '.$label.'.');
    return round((float)$value,2);
}
function sfw_editable(array $s,string $e,string $m,array $b):array {
    $sheet=smw_sheet($s,$e,$m);
    if(($sheet['status']??'')==='Completed')throw new DomainException('This month is completed. Correct the original Post ID with a reason.');
    if((int)($b['version']??-1)!==(int)$sheet['version']||!empty($sheet['masterChanged']))throw new DomainException('Salary inputs changed. Refresh and review this draft first.');
    $id=(string)($b['masterId']??'');if(!isset($sheet['rows'][$id]))throw new DomainException('Select a person on this salary sheet.');
    smw_check_baseline($s,$sheet['rows'][$id]);return $sheet;
}
function sfw_groups(array $sheet):array {
    if(isset($sheet['paymentGroups']))return $sheet['paymentGroups'];
    $groups=[];
    foreach($sheet['rows'] as $id=>$row){$p=$row['payment']??null;if(!$p||($p['amount']??0)<=.005)continue;
        foreach(($p['parts']??[['amount'=>$p['amount'],'paymentAccountId'=>$p['paymentAccountId']]+$p]) as $part)$groups[$id][]=array_merge($part,['date'=>$p['date'],'recipient'=>$row['name'],'allocations'=>[['type'=>'SALARY','masterId'=>$id,'amount'=>$part['amount']]]]);
    }return $groups;
}
function sfw_recalculate(array &$sheet):void {
    $paid=[];
    foreach(sfw_groups($sheet) as $transactions)foreach($transactions as $t)if(($t['status']??'')!=='Reversed')foreach($t['allocations'] as $a)if($a['type']==='SALARY')$paid[$a['masterId']]=round(($paid[$a['masterId']]??0)+$a['amount'],2);
    foreach($sheet['rows'] as $id=>&$r){$amount=$paid[$id]??0;$max=round($r['totalDue']-$r['paidAfterPrepare'],2);
        if($amount>$max+.005)throw new DomainException('Allocations exceed unpaid entitlement for '.$r['name'].'.');
        $recovery=(float)($r['requestedRecovery']??$r['advanceApplied']);
        $r['advanceApplied']=round(min($recovery,max(0,$max-$amount)),2);
        $r['outstanding']=round(max(0,$max-$amount-$r['advanceApplied']),2);
        $r['payment']=['amount'=>$amount,'date'=>$sheet['month'].'-01','paymentAccountId'=>'','parts'=>[],'allocatedTransactions'=>true];
    }unset($r);
}
function sfw_adjust(array &$s,string $e,string $m,array $b,array $u):array {
    $sheet=sfw_editable($s,$e,$m,$b);$id=(string)$b['masterId'];$row=$sheet['rows'][$id];
    if(!empty($row['prepared']))throw new DomainException('This salary is already accrued. Correct its source Post ID before changing the entitlement.');
    foreach(['netSalary','zakatAmount','otherAllowance','monthlyAddition','monthlyReduction'] as $field)$row[$field]=sfw_money($b[$field]??$row[$field]??0,'monthly amount');
    $row['adjustmentReason']=trim((string)($b['adjustmentReason']??''));
    if($row['monthlyAddition']+$row['monthlyReduction']>.005&&$row['adjustmentReason']==='')throw new DomainException('Explain the monthly addition or reduction.');
    if($row['monthlyReduction']>$row['netSalary']+$row['monthlyAddition']+.005)throw new DomainException('Reduction cannot exceed salary plus additions. Amend allowances separately.');
    $row['totalDue']=$row['gross']=round($row['netSalary']+$row['zakatAmount']+$row['otherAllowance']+$row['monthlyAddition']-$row['monthlyReduction'],2);
    $recovery=sfw_money($b['advanceApplied']??$row['advanceApplied'],'advance recovery');
    if($recovery>min($row['totalDue'],$row['advanceOutstanding'])+.005)throw new DomainException('Recovery exceeds the eligible advance or monthly entitlement.');
    $sheet['paymentGroups']=sfw_groups($sheet);$row['requestedRecovery']=$recovery;$row['advanceApplied']=$recovery;$row['reviewed']=false;
    $sheet['rows'][$id]=$row;sfw_recalculate($sheet);$sheet['version']++;$sheet['updatedAt']=gmdate('c');
    $sheet['adjustmentAudit'][]=['masterId'=>$id,'reason'=>$row['adjustmentReason'],'by'=>$u['id']??0,'at'=>gmdate('c')];$s['salarySheets'][$e.'|'.$m]=$sheet;return ['saved'=>true,'masterId'=>$id,'monthlyOnly'=>true];
}
function sfw_company_access(array $u,string $action='Create'):void {
    require_once __DIR__.'/company_investments_core.php';
    if(!far_permission($u,'Accounts',$action))throw new DomainException('Assets '.$action.' permission is required for company investment allocations.');
}
function sfw_validate_company(array $s,string $e,array $a,array $u):array {
    sfw_company_access($u);$route=inv_route($s,$e,(string)($a['routeId']??''));
    $broker=($a['type']??'')==='BROKER_RECEIVED'?inv_broker($s,$e,(string)($a['brokerId']??'')):null;
    if(trim((string)($a['reference']??''))==='')throw new DomainException('Enter the company transfer / broker receipt reference.');
    return ['route'=>$route,'broker'=>$broker];
}
function sfw_save(array &$s,string $e,string $m,array $b,array $u,array $names):array {
    $sheet=sfw_editable($s,$e,$m,$b);$id=(string)$b['masterId'];$owner=$sheet['rows'][$id];$family=in_array($owner['category'],['DIRECTOR_REMUNERATION','HOME_MONTHLY_GIVE'],true);
    $raw=$b['transactions']??null;if(!is_array($raw)||count($raw)>100)throw new DomainException('Enter up to 100 actual payment transactions.');
    $transactions=[];$references=[];
    foreach($raw as $t){if(!is_array($t))throw new DomainException('Invalid payment transaction.');
        $amount=sfw_money($t['amount']??null,'payment amount');if($amount<=.005)throw new DomainException('Remove empty transactions or enter a positive payment.');
        $date=rsv2_date((string)($t['date']??''));$source=(string)($t['paymentAccountId']??'');rsv2_pay_line($s,$e,$source,$amount,$names);
        $tracking=str_starts_with($source,'CASH|')?[]:tt_accounts_bank_payment_details($t+['date'=>$date]);
        $recipient=trim((string)($t['recipient']??$owner['name']));if($recipient==='')throw new DomainException('Enter the actual payment recipient.');
        $allocations=[];$sum=0;$parts=$t['allocations']??[];if(!is_array($parts)||!$parts||count($parts)>100)throw new DomainException('Allocate every payment to a purpose.');
        foreach($parts as $a){$type=(string)($a['type']??'SALARY');$n=sfw_money($a['amount']??null,'allocation amount');if($n<=.005)throw new DomainException('Allocation amounts must be positive.');
            $allocation=['type'=>$type,'amount'=>$n,'purpose'=>trim((string)($a['purpose']??''))];
            if($type==='SALARY'){$target=(string)($a['masterId']??$id);$r=$sheet['rows'][$target]??null;
                if(!$r||($target!==$id&&(!$family||!in_array($r['category'],['DIRECTOR_REMUNERATION','HOME_MONTHLY_GIVE'],true))))throw new DomainException('Choose an eligible remuneration or home-expense beneficiary.');
                smw_check_baseline($s,$r);$allocation['masterId']=$target;
            }elseif(in_array($type,['COMPANY_HELD','BROKER_RECEIVED'],true)&&$family){$allocation+=['routeId'=>(string)($a['routeId']??''),'brokerId'=>(string)($a['brokerId']??''),'reference'=>trim((string)($a['reference']??''))];sfw_validate_company($s,$e,$allocation,$u);
                $ref=$allocation['reference'].'|'.$allocation['routeId'];if(isset($references[$ref]))throw new DomainException('Use one company allocation for each transfer reference.');$references[$ref]=true;
                foreach((array)($s['investmentTransactions']??[]) as $previous)if(($previous['entity']??'')===$e&&($previous['status']??'')==='Posted'&&($previous['reference']??'')===$allocation['reference']&&($previous['paymentAccountId']??'')===$source)throw new DomainException('This company transfer is already recorded. Link/correct the original payment instead.');
            }else throw new DomainException('Choose a valid allocation purpose.');
            $sum+=(int)round($n*100);$allocations[]=$allocation;
        }
        if($sum!==(int)round($amount*100))throw new DomainException('Allocations must equal the actual transaction amount.');
        $transactions[]=['amount'=>$amount,'date'=>$date,'paymentAccountId'=>$source,'recipient'=>$recipient,'allocations'=>$allocations]+$tracking;
    }
    $groups=sfw_groups($sheet);$old=$groups[$id]??[];$groups[$id]=$transactions;$sheet['paymentGroups']=$groups;
    $affected=[];foreach(array_merge($old,$transactions) as $t)foreach($t['allocations'] as $a)if($a['type']==='SALARY')$affected[$a['masterId']]=true;
    foreach(array_keys($affected) as $target)$sheet['rows'][$target]['reviewed']=false;
    $recovery=sfw_money($b['advanceApplied']??$owner['advanceApplied'],'advance recovery');$max=$owner['prepared']?$owner['originalAdvanceApplied']:min($owner['totalDue'],$owner['advanceOutstanding']);
    if($recovery>$max+.005)throw new DomainException('Recovery exceeds the eligible advance balance.');
    $sheet['rows'][$id]['requestedRecovery']=$recovery;sfw_recalculate($sheet);$sheet['rows'][$id]['reviewed']=true;unset($sheet['rows'][$id]['advanceReviewRequired']);
    $sheet['version']++;$sheet['updatedAt']=gmdate('c');$s['salarySheets'][$e.'|'.$m]=$sheet;return ['saved'=>true,'masterId'=>$id];
}
function sfw_post_groups(array &$s,string $e,string $m,array $sheet,array $u,array $names):array {
    $ids=[];
    foreach($sheet['paymentGroups'] as $owner=>$transactions)foreach($transactions as $index=>$t){if(($t['status']??'')==='Reversed')continue;sfw_assert_reference($s,$e,$t);$amount=0;$lines=[];$events=[];
        foreach($t['allocations'] as $a){$n=sfw_money($a['amount'],'allocation');$amount=round($amount+$n,2);$meta=['month'=>$m,'paymentRecipient'=>$t['recipient'],'paymentGroupOwner'=>$owner,'allocationPurpose'=>$a['purpose']??''];
            if($a['type']==='SALARY'){$r=$sheet['rows'][$a['masterId']];$lines[]=rsv2_line('2140',$n,0,$names,$meta+['salaryMasterId'=>$a['masterId'],'party'=>$r['name'],'person'=>$r['name'],'category'=>$r['category']]);}
            else{foreach((array)($s['investmentTransactions']??[]) as $prior)if(($prior['entity']??'')===$e&&($prior['status']??'')==='Posted'&&($prior['reference']??'')===$a['reference']&&($prior['paymentAccountId']??'')===$t['paymentAccountId'])throw new DomainException('The company transfer reference was posted after this draft. Review the original investment payment.');$part=sfw_validate_company($s,$e,$a,$u);$route=$part['route'];$broker=$part['broker'];$meta+=['routeId'=>$route['id'],'routeName'=>$route['name'],'companyOwned'=>true,'reference'=>$a['reference']];
                $lines[]=rsv2_line('1440',$n,0,$names,$meta+['party'=>$route['name']]);$events[]=['type'=>'FUND_ROUTE','a'=>$a,'route'=>$route,'broker'=>null];
                if($broker){$bm=$meta+['brokerId'=>$broker['id'],'brokerName'=>$broker['name'],'party'=>$broker['name']];$lines[]=rsv2_line('1440',0,$n,$names,$meta+['party'=>$route['name']]);$lines[]=rsv2_line('1430',$n,0,$names,$bm);$events[]=['type'=>'ROUTE_TO_BROKER','a'=>$a,'route'=>$route,'broker'=>$broker];}
            }
        }
        if(abs($amount-$t['amount'])>.005)throw new DomainException('Saved allocation totals changed. Review the payments.');
        $tracking=str_starts_with($t['paymentAccountId'],'CASH|')?[]:tt_accounts_bank_payment_details($t);
        $lines[]=rsv2_pay_line($s,$e,$t['paymentAccountId'],$amount,$names)+$tracking+['paymentRecipient'=>$t['recipient']];
        $jid=rsv2_journal($s,$e,$t['date'],'SALARY_ALLOCATED_PAYMENT','SAL-'.$m,'Salary / home payment — '.$t['recipient'],$lines,$u,['month'=>$m,'salarySheetKey'=>$e.'|'.$m,'paymentGroupOwner'=>$owner,'paymentGroupIndex'=>$index]);$ids[]=$jid;
        foreach($t['allocations'] as $a)if($a['type']==='SALARY'){$paymentId=rsv2_next((array)$s['rentSalaryPayments'],'RSP');$s['rentSalaryPayments'][$paymentId]=['id'=>$paymentId,'type'=>'SALARY','entity'=>$e,'masterId'=>$a['masterId'],'month'=>$m,'journalId'=>$jid,'amount'=>$a['amount'],'date'=>$t['date'],'paymentAccountId'=>$t['paymentAccountId'],'salarySheetKey'=>$e.'|'.$m,'status'=>'Posted'];}
        foreach($events as $event){$a=$event['a'];$route=$event['route'];$broker=$event['broker'];$cents=(int)round($a['amount']*100);$rid=$route['id'];$bid=$broker['id']??'';$id=far_next((array)($s['investmentTransactions']??[]),'INV');
            $s['investmentTransactions'][$id]=['id'=>$id,'entity'=>$e,'date'=>$t['date'],'type'=>$event['type'],'amountCents'=>$cents,'grossCents'=>0,'feesCents'=>0,'costCents'=>0,'quantity'=>0,'price'=>0,'remunerationCents'=>0,'remunerationTreatment'=>'','brokerDeltas'=>$broker?[$bid=>$cents]:[],'routeDeltas'=>[$rid=>$broker?-$cents:$cents],'brokerId'=>$bid,'brokerName'=>$broker['name']??'','routeId'=>$rid,'routeName'=>$route['name'],'companyOwned'=>true,'reference'=>$a['reference'],'paymentAccountId'=>$event['type']==='FUND_ROUTE'?$t['paymentAccountId']:'','salaryPeriodId'=>'','journalId'=>$jid,'salarySheetKey'=>$e.'|'.$m,'status'=>'Posted','sequence'=>count((array)($s['investmentTransactions']??[]))+1,'createdBy'=>$u['username']??'Accounts'];
        }
    }
    if(function_exists('inv_validate_timeline'))inv_validate_timeline($s,$e);return $ids;
}

function sfw_refresh_sources(array &$s,string $e,string $m,array $sheet):array {
    $fresh=smw_fresh_sheet($s,$e,$m);
    foreach($sheet['rows'] as $id=>&$row){$current=$fresh['rows'][$id]??null;if(!$current||!empty($row['prepared']))continue;
        if(abs((float)$row['advanceOutstanding']-(float)$current['advanceOutstanding'])>.005||abs((float)$row['advanceSuggested']-(float)$current['advanceSuggested'])>.005){
            $row['advanceOutstanding']=$current['advanceOutstanding'];$row['advanceSuggested']=$current['advanceSuggested'];$row['originalAdvanceApplied']=min($row['totalDue'],$current['advanceSuggested']);
            $row['requestedRecovery']=min((float)($row['requestedRecovery']??$current['advanceSuggested']),$current['advanceOutstanding'],$row['totalDue']);
            $row['advanceApplied']=$row['requestedRecovery'];$row['reviewed']=false;$row['advanceReviewRequired']=true;
        }
    }unset($row);
    $sheet['paymentGroups']=sfw_groups($sheet);sfw_recalculate($sheet);$sheet['advanceSignature']=$fresh['advanceSignature'];$sheet['personalSignature']=$fresh['personalSignature'];
    unset($sheet['sourceInputsChanged'],$sheet['masterChanged'],$sheet['canRefreshFromMaster']);$sheet['version']++;$sheet['updatedAt']=gmdate('c');$s['salarySheets'][$e.'|'.$m]=$sheet;return ['refreshed'=>true,'paymentsPreserved'=>true];
}
/** Reverse the complete actual payment, including all beneficiaries and company allocations. */
function sfw_reverse_payment(array &$s,string $e,string $m,array $b,array $u,array $names):array {
    $id=(string)($b['journalId']??'');$j=$s['journals'][$id]??null;
    if(!$j||($j['entity']??'')!==$e||($j['sourceType']??'')!=='SALARY_ALLOCATED_PAYMENT'||($j['meta']['salarySheetKey']??'')!==$e.'|'.$m)throw new DomainException('Select an actual salary payment from this company and month.');
    if(!tt_user_can_access_entity($u,$e,'Edit')||(function_exists('tt_post_correction_allowed')&&!tt_post_correction_allowed($u,$j)))throw new DomainException('Salary payment correction permission is required.');
    if(!empty($j['reversedByPostId']))return ['journalId'=>$j['reversedByPostId'],'duplicate'=>true];
    $reason=trim((string)($b['reason']??''));if(strlen($reason)<5)throw new DomainException('Enter a reason for reversing the complete payment.');
    $date=rsv2_date((string)($b['date']??''));if($date<$j['date'])throw new DomainException('Reversal date cannot precede the payment.');
    $key=$e.'|'.$m;$owner=$j['meta']['paymentGroupOwner'];$index=$j['meta']['paymentGroupIndex'];$t=$s['salarySheets'][$key]['paymentGroups'][$owner][$index]??null;
    if(!$t||($t['status']??'')==='Reversed')throw new DomainException('The original salary transaction needs review.');
    foreach($t['allocations'] as $a)if($a['type']==='SALARY'){$pid=rsv2_pid($e,$m,$a['masterId']);$p=$s['salaryPeriods'][$pid]??null;if(!$p||(float)$p['paidAfterPrepare']<$a['amount']-.005)throw new DomainException('The beneficiary payment balance changed. Review the original period.');$s['salaryPeriods'][$pid]['paidAfterPrepare']=round($p['paidAfterPrepare']-$a['amount'],2);$s['salaryPeriods'][$pid]['outstanding']=round($p['outstanding']+$a['amount'],2);$s['salaryPeriods'][$pid]['status']='Payable';$r=&$s['salarySheets'][$key]['rows'][$a['masterId']];$r['payment']['amount']=round($r['payment']['amount']-$a['amount'],2);$r['outstanding']=round($r['outstanding']+$a['amount'],2);unset($r);}
    foreach((array)($s['investmentTransactions']??[]) as $eventId=>$event)if(($event['journalId']??'')===$id){sfw_company_access($u,'Edit');$s['investmentTransactions'][$eventId]['status']='Reversed';}
    if(function_exists('inv_validate_timeline'))inv_validate_timeline($s,$e);
    $lines=$j['lines'];foreach($lines as &$l){[$l['debit'],$l['credit']]=[$l['credit'],$l['debit']];if(isset($l['bankDebit']))[$l['bankDebit'],$l['bankCredit']]=[$l['bankCredit'],$l['bankDebit']];}unset($l);
    $rid=rsv2_journal($s,$e,$date,'SALARY_ALLOCATED_PAYMENT_REVERSAL',$id,'Reverse salary payment — '.$reason,$lines,$u,['salarySheetKey'=>$key,'originalPostId'=>$id,'reason'=>$reason]);$s['journals'][$rid]['reversalOf']=$id;$s['journals'][$id]['reversedByPostId']=$rid;
    foreach((array)$s['rentSalaryPayments'] as $paymentId=>$payment)if(($payment['journalId']??'')===$id){$s['rentSalaryPayments'][$paymentId]['originalAmount']=$payment['amount'];$s['rentSalaryPayments'][$paymentId]['amount']=0;$s['rentSalaryPayments'][$paymentId]['status']='Reversed';$s['rentSalaryPayments'][$paymentId]['reversalJournalId']=$rid;}
    $s['salarySheets'][$key]['paymentGroups'][$owner][$index]['status']='Reversed';$s['salarySheets'][$key]['version']++;$s['salarySheets'][$key]['corrections'][]=['originalPostId'=>$id,'reversalPostId'=>$rid,'reason'=>$reason,'at'=>gmdate('c'),'by'=>$u['id']??0];
    return ['journalId'=>$rid];
}

function sfw_assert_reference(array $s,string $e,array $t):void {
    $reference=trim((string)($t['bankReference']??$t['chequeNo']??''));if($reference===''||str_starts_with($t['paymentAccountId'],'CASH|'))return;
    foreach((array)$s['journals'] as $j){if(($j['entity']??'')!==$e||($j['status']??'')!=='Posted'||!empty($j['reversedByPostId'])||!empty($j['reversalOf']))continue;
        foreach((array)$j['lines'] as $line)if(($line['bankAccountId']??'')===$t['paymentAccountId']&&(float)($line['credit']??0)>.005&&trim((string)($line['bankReference']??$line['chequeNo']??$j['meta']['bankReference']??''))===$reference)throw new DomainException('This bank reference is already posted. Review the original payment instead of paying again.');
    }
}
function sfw_company_allowed(array $u):bool {
    if(($u['role']??'')==='Super Admin')return true;$p=$u['permissions']['Accounts']??[];
    return $p==='all'||(is_array($p)&&(in_array('Create',$p,true)||in_array('Create',(array)($p['assets']??[]),true)));
}
function sfw_legacy_review(array $s,string $e,string $m):array {
    $warnings=[];foreach((array)($s['expensePersonalLinks']??[]) as $link){if(($link['entity']??'')!==$e)continue;
        foreach((array)($link['rows']??[]) as $row)foreach((array)($row['periods']??[]) as $p){$period=$s['salaryPeriods'][$p['id']]??null;if(($period['month']??'')!==$m||($period['month']??'')>=sfw_recovery_month($row))continue;
            $warnings[]=['postId'=>$link['publicPostId']??$row['journalId']??'','date'=>$row['date']??'','recoveryFromMonth'=>sfw_recovery_month($row),'amount'=>$p['amount'],'person'=>$row['expenseForName']??'Director'];
        }
    }return $warnings;
}
