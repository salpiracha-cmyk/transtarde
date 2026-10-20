<?php
declare(strict_types=1);
require_once __DIR__.'/accounts_ledger_visibility.php';
require_once __DIR__.'/accounts_reference.php';

/** Payroll's own liability; never use this head for supplier or asset instalments. */
function src_allowed(array $u,string $e):bool {
    return in_array($u['role']??'',['Super Admin','Director'],true)
        && tt_user_can_access_entity($u,$e,'Edit');
}
function src_payroll(array $j):bool {
    return in_array($j['meta']['originalSourceType']??$j['sourceType']??'',[
        'SALARY_MONTH_COMPLETED','SALARY_MONTHLY_ACCRUAL','SALARY_ALLOCATED_PAYMENT','SALARY_PAYMENT','SALARY_BATCH_PAYMENT'
    ],true);
}
function src_month(array $j):string {
    return (string)($j['meta']['month']??(preg_match('/^SAL(?:PAY)?-(\d{4}-\d{2})/',(string)($j['reference']??''),$m)?$m[1]:''));
}
function src_current(array $s,string $e):array {
    $hidden=alv_hidden($s);$out=[];
    foreach($s['journals']??[] as $id=>$j)if(($j['entity']??'')===$e&&($j['status']??'')==='Posted'&&!isset($hidden[$id])&&empty($j['reversalOf'])&&empty($j['amendedByPostId'])&&empty($j['reversedByPostId'])&&src_payroll($j))$out[$id]=$j;
    return $out;
}
function src_snapshot(array $s,string $e):string {
    return hash('sha256',json_encode([src_current($s,$e),$s['salarySheets']??[],$s['salaryPeriods']??[],$s['investmentTransactions']??[],$s['managedAssets']??[]],JSON_THROW_ON_ERROR));
}
function src_summary(array $s,string $e,string $m,array $sheet):array {
    $out=['month'=>$m,'entitlement'=>0,'advances'=>0,'bank'=>0,'cash'=>0,'otherPayments'=>0,'internalAdjustments'=>0,'remaining'=>0,'people'=>[]];
    foreach($sheet['rows']??[] as $r){$out['entitlement']+=(float)$r['totalDue'];$out['advances']+=(float)$r['advanceApplied'];$out['remaining']+=(float)$r['outstanding'];if((float)$r['outstanding']>.005)$out['people'][]=['name'=>$r['name'],'amount'=>(float)$r['outstanding']];}
    if(($sheet['status']??'')==='Completed'){
        foreach(src_current($s,$e) as $j){if(src_month($j)!==$m)continue;
            foreach($j['lines'] as $l){$a=$l['account'];if($a==='1110')$out['bank']+=(float)$l['credit']-(float)$l['debit'];if($a==='1120')$out['cash']+=(float)$l['credit']-(float)$l['debit'];}
        }
    }else foreach(sfw_groups($sheet) as $txs)foreach($txs as $t){if(($t['status']??'')==='Reversed')continue;$out[str_starts_with($t['paymentAccountId'],'CASH|')?'cash':'bank']+=(float)$t['amount'];}
    foreach(sfw_groups($sheet) as $txs)foreach($txs as $t){if(($t['status']??'')==='Reversed')continue;foreach($t['allocations'] as $a){if($a['type']!=='SALARY')$out['otherPayments']+=(float)$a['amount'];foreach(sea_charges($a) as $c)if(!empty($c['internal']))$out['internalAdjustments']+=(float)$c['amount'];}}
    foreach($out as $k=>$v)if(is_numeric($v))$out[$k]=round((float)$v,2);
    return $out;
}
/** Replace only dates and evidenced payroll control lines. Original journals remain immutable. */
function src_correct(array &$s,string $e,string $m,array $b,array $u,array $names):array {
    if(!src_allowed($u,$e))throw new DomainException('Director or Super Admin with company Edit access is required for payroll corrections.');
    $reason=trim((string)($b['reason']??''));if(strlen($reason)<5||strlen($reason)>300)throw new DomainException('Enter a correction reason (5–300 characters).');
    $key=$e.'|SALARY_RECORD_CORRECTION|'.rsv2_request_key($b['requestKey']??'');
    $fingerprint=rsv2_fingerprint(array_intersect_key($b,array_flip(['month','date','reason','snapshot'])));
    if(isset($s['postingKeys'][$key])){if(!hash_equals($s['postingKeys'][$key]['fingerprint'],$fingerprint))throw new DomainException('This correction key was already used for different details.');return $s['postingKeys'][$key]['result']+['duplicate'=>true];}
    if(!hash_equals(src_snapshot($s,$e),(string)($b['snapshot']??'')))throw new DomainException('Payroll records changed. Reopen and review this correction.');
    $date=trim((string)($b['date']??''));if($date!==''){$d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$d||$d->format('Y-m-d')!==$date||substr($date,0,7)!==$m)throw new DomainException('Choose a valid date in the selected salary month.');if(($s['salarySheets'][$e.'|'.$m]['status']??'')!=='Completed')throw new DomainException('Complete the salary month before correcting its posted dates.');}
    $next=$s;$map=[];$changes=[];$dateCount=0;$accountCount=0;
    foreach(src_current($s,$e) as $id=>$j){
        $changeDate=$date!==''&&src_month($j)===$m;$lines=$j['lines'];$reclassified=false;
        foreach($lines as &$l){
            // Company asset instalments in a mixed salary payment stay supplier liabilities.
            if(($l['account']??'')==='2140'&&($l['salaryMasterId']??$j['meta']['salaryMasterId']??'')!==''){$l['account']='2230';$l['accountName']=$names['2230'];$reclassified=true;}
            if($changeDate)foreach(['paymentDate','chequeDate','bankPaymentDate'] as $field)if(!empty($l[$field]))$l[$field]=$date;
        }unset($l);
        if(!$reclassified&&(!$changeDate||($j['date']===$date&&$lines===$j['lines'])))continue;
        $dr=0;$cr=0;foreach($j['lines'] as $l){$dr+=(int)round(100*(float)$l['debit']);$cr+=(int)round(100*(float)$l['credit']);}
        if($dr<=0||$dr!==$cr||$dr!==(int)round(100*(float)($j['totalDebit']??0))||$cr!==(int)round(100*(float)($j['totalCredit']??0)))throw new DomainException('A selected salary journal is unbalanced. Correct its original source first.');
        $reverse=$j['lines'];foreach($reverse as &$l){[$l['debit'],$l['credit']]=[$l['credit'],$l['debit']];foreach([['bankDebit','bankCredit'],['nativeDebit','nativeCredit']] as [$a,$c])if(isset($l[$a])||isset($l[$c]))[$l[$a],$l[$c]]=[$l[$c]??0,$l[$a]??0];}unset($l);
        $rid=rsv2_journal($next,$e,$j['date'],'SALARY_RECORD_CORRECTION_REVERSAL',$id,'Payroll correction reversal — '.$reason,$reverse,$u,['originalPostId'=>$id,'reason'=>$reason]);$next['journals'][$rid]['reversalOf']=$id;
        $meta=(array)($j['meta']??[]);$meta['publicPostId']=tt_accounts_public_post($s,$j);$meta['reconciliationPostId']=$meta['reconciliationPostId']??$id;$meta['amendmentOfPostId']=$id;$meta['amendmentReason']=$reason;$meta['reversalPostId']=$rid;$meta['amendmentNote']='Edited '.gmdate('Y-m-d').' by '.($u['full_name']??$u['username']??'Accounts').' — '.$reason.($changeDate?' — Date: '.$j['date'].' → '.$date:'').($reclassified?' — Payroll payable: 2140 → 2230':'');
        if($changeDate)foreach(['paymentDate','chequeDate','bankPaymentDate'] as $field)if(!empty($meta[$field]))$meta[$field]=$date;
        $nid=rsv2_journal($next,$e,$changeDate?$date:$j['date'],$j['sourceType'],(string)($j['reference']??''),(string)($j['narration']??''),$lines,$u,$meta);
        $next['journals'][$id]['amendedByPostId']=$nid;$next['journals'][$id]['amendmentReversalPostId']=$rid;$next['journals'][$id]['reversedByPostId']=$rid;$map[$id]=$nid;
        $changes[]=['originalPostId'=>$id,'replacementPostId'=>$nid,'publicPostId'=>$meta['publicPostId'],'oldDate'=>$j['date'],'date'=>$changeDate?$date:$j['date'],'payrollSeparated'=>$reclassified];
        $dateCount+=(int)($changeDate&&$j['date']!==$date);$accountCount+=(int)$reclassified;
        $next['postAmendments'][]=['originalPostId'=>$id,'reversalPostId'=>$rid,'replacementPostId'=>$nid,'reason'=>$reason,'date'=>$changeDate?$date:$j['date'],'actor'=>$u['username']??'Accounts','at'=>gmdate('c'),'entity'=>$e,'datesOnly'=>!$reclassified,'payrollAccountSeparation'=>$reclassified];
    }
    if(!$map)throw new DomainException('The selected payroll dates and accounts already match.');
    // Update canonical links, not amounts or allocations. No new investment event or instalment.
    foreach(['rentSalaryPayments','salaryPeriods','investmentTransactions'] as $collection)foreach($next[$collection]??[] as $id=>$r){$old=$r['journalId']??'';if(!isset($map[$old]))continue;$next[$collection][$id]['journalId']=$map[$old];if($date!==''&&src_month($s['journals'][$old])===$m&&isset($r['date']))$next[$collection][$id]['date']=$date;}
    foreach($next['managedAssets']??[] as $aid=>$asset)foreach($asset['payments']??[] as $pid=>$p)if(isset($map[$p['journalId']??''])){$old=$p['journalId'];$next['managedAssets'][$aid]['payments'][$pid]['journalId']=$map[$old];if($date!==''&&src_month($s['journals'][$old])===$m){if($date<($asset['purchaseDate']??'2026-07-01'))throw new DomainException('An asset payment cannot precede its agreement.');$next['managedAssets'][$aid]['payments'][$pid]['date']=$date;foreach(['chequeDate','bankPaymentDate'] as $field)if(!empty($p[$field]))$next['managedAssets'][$aid]['payments'][$pid][$field]=$date;}$next['managedAssets'][$aid]['version']=(int)$asset['version']+1;}
    foreach($next['salarySheets']??[] as $sk=>$sheet){if(($sheet['entity']??'')!==$e)continue;
        if(isset($map[$sheet['journalId']??'']))$next['salarySheets'][$sk]['journalId']=$map[$sheet['journalId']];
        $next['salarySheets'][$sk]['paymentPostIds']=array_map(static fn($id)=>$map[$id]??$id,$sheet['paymentPostIds']??[]);
        if($date!==''&&($sheet['month']??'')===$m){foreach($sheet['paymentGroups']??[] as $owner=>$txs)foreach($txs as $ix=>$tx){if(($tx['status']??'')==='Reversed')continue;$next['salarySheets'][$sk]['paymentGroups'][$owner][$ix]['date']=$date;foreach(['chequeDate','bankPaymentDate'] as $f)if(!empty($tx[$f]))$next['salarySheets'][$sk]['paymentGroups'][$owner][$ix][$f]=$date;}
            foreach($sheet['rows']??[] as $mid=>$r)if(is_array($r['payment']??null)){$next['salarySheets'][$sk]['rows'][$mid]['payment']['date']=$date;foreach($r['payment']['parts']??[] as $ix=>$part){foreach(['chequeDate','bankPaymentDate'] as $f)if(!empty($part[$f]))$next['salarySheets'][$sk]['rows'][$mid]['payment']['parts'][$ix][$f]=$date;}}
        }
        if(($sheet['status']??'')==='Completed')$next['salarySheets'][$sk]['version']++;
    }
    foreach($map as $old=>$new){$meta=&$next['journals'][$new]['meta'];if(isset($meta['paymentPostIds']))$meta['paymentPostIds']=array_map(static fn($id)=>$map[$id]??$id,$meta['paymentPostIds']);if(isset($map[$meta['salaryMonthPostId']??'']))$meta['salaryMonthPostId']=$map[$meta['salaryMonthPostId']];unset($meta);}
    foreach($next['salaryAdvances']??[] as $advance)foreach($advance['adjustments']??[] as $ix=>$adjustment){$old=$adjustment['journalId']??'';if(!isset($map[$old]))continue;if($date!==''&&src_month($s['journals'][$old])===$m&&$date<($advance['date']??''))throw new DomainException('Salary recovery cannot precede the original advance date.');}
    foreach($next['salaryAdvances']??[] as $aid=>$advance)foreach($advance['adjustments']??[] as $ix=>$adjustment)if(isset($map[$adjustment['journalId']??'']))$next['salaryAdvances'][$aid]['adjustments'][$ix]['journalId']=$map[$adjustment['journalId']];
    foreach($next['postingKeys']??[] as $pk=>$record){foreach(['journalId','replacementPostId'] as $field){if(isset($map[$record[$field]??'']))$next['postingKeys'][$pk][$field]=$map[$record[$field]];if(isset($map[$record['result'][$field]??'']))$next['postingKeys'][$pk]['result'][$field]=$map[$record['result'][$field]];}}
    if(function_exists('inv_validate_timeline'))inv_validate_timeline($next,$e);
    $monthId=$next['salarySheets'][$e.'|'.$m]['journalId']??reset($map);$summary=src_summary($next,$e,$m,smw_sheet($next,$e,$m));
    if(isset($next['journals'][$monthId]))$next['journals'][$monthId]['meta']['salarySummary']=$summary;
    $result=['journalId'=>$monthId,'correctedDates'=>$dateCount,'separatedJournals'=>$accountCount,'changes'=>$changes];
    $next['postingKeys'][$key]=['fingerprint'=>$fingerprint,'result'=>$result,'createdAt'=>gmdate('c')];$s=$next;return $result;
}
