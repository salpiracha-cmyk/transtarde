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
    $current=src_current($s,$e);$planned=0;
    foreach($current as $j){$needed=$date!==''&&src_month($j)===$m&&$j['date']!==$date;foreach($j['lines'] as $l){if(($l['account']??'')==='2140'&&($l['salaryMasterId']??$j['meta']['salaryMasterId']??'')!=='')$needed=true;if($date!==''&&src_month($j)===$m)foreach(['paymentDate','chequeDate','bankPaymentDate'] as $f)if(!empty($l[$f])&&$l[$f]!==$date)$needed=true;}if($needed)$planned++;}
    if(!$planned)throw new DomainException('The selected payroll dates and accounts already match.');
    if(empty($b['preview'])&&function_exists('tt_reserve_post_id_block'))$ids=tt_reserve_post_id_block($s['journals'],$planned*2);
    else{$ids=[];$year=gmdate('Y');$minimum=0;foreach($s['journals'] as $id=>$j)if(preg_match('/^POST-'.$year.'-(\\d+)$/',(string)$id,$match))$minimum=max($minimum,(int)$match[1]);for($i=1;$i<=$planned*2;$i++)$ids[]='POST-'.$year.'-'.str_pad((string)($minimum+$i),5,'0',STR_PAD_LEFT);}
    $next=$s;$map=[];$changes=[];$dateCount=0;$accountCount=0;
    foreach($current as $id=>$j){
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
        $rid=rsv2_journal($next,$e,$j['date'],'SALARY_RECORD_CORRECTION_REVERSAL',$id,'Payroll correction reversal — '.$reason,$reverse,$u,['originalPostId'=>$id,'reason'=>$reason],array_shift($ids));$next['journals'][$rid]['reversalOf']=$id;
        $meta=(array)($j['meta']??[]);$meta['publicPostId']=tt_accounts_public_post($s,$j);$meta['reconciliationPostId']=$meta['reconciliationPostId']??$id;$meta['amendmentOfPostId']=$id;$meta['amendmentReason']=$reason;$meta['reversalPostId']=$rid;$meta['amendmentNote']='Edited '.gmdate('Y-m-d').' by '.($u['full_name']??$u['username']??'Accounts').' — '.$reason.($changeDate?' — Date: '.$j['date'].' → '.$date:'').($reclassified?' — Payroll payable: 2140 → 2230':'');
        if($changeDate)foreach(['paymentDate','chequeDate','bankPaymentDate'] as $field)if(!empty($meta[$field]))$meta[$field]=$date;
        $nid=rsv2_journal($next,$e,$changeDate?$date:$j['date'],$j['sourceType'],(string)($j['reference']??''),(string)($j['narration']??''),$lines,$u,$meta,array_shift($ids));
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

/** Active source transactions for the payment list. Labels never change journal identity. */
function src_payments(array $s,string $e,string $m,array $u):array {
    $out=[];$sheet=$s['salarySheets'][$e.'|'.$m]??[];
    foreach(src_current($s,$e) as $id=>$j){
        if(($j['sourceType']??'')!=='SALARY_ALLOCATED_PAYMENT'||src_month($j)!==$m)continue;
        $owner=$j['meta']['paymentGroupOwner']??'';$index=$j['meta']['paymentGroupIndex']??-1;
        $t=$sheet['paymentGroups'][$owner][$index]??null;if(!$t||($t['status']??'')==='Reversed')continue;
        foreach($t['allocations'] as &$a)if(($a['type']??'')==='ASSET_PAYMENT'&&isset($s['managedAssets'][$a['assetId']]))$a['assetVersion']=$s['managedAssets'][$a['assetId']]['version'];unset($a);
        $out[]=['journalId'=>$id,'publicPostId'=>tt_accounts_public_post($s,$j),'owner'=>$owner,'transaction'=>$t,
            'canAmend'=>tt_user_can_access_entity($u,$e,'Edit')&&(!function_exists('tt_post_correction_allowed')||tt_post_correction_allowed($u,$j))];
    }
    return $out;
}
/** Amend one complete source payment through the same canonical allocation engine. */
function src_amend_payment(array &$s,string $e,string $m,array $b,array $u,array $names):array {
    $key=$e.'|SALARY_PAYMENT_AMEND|'.rsv2_request_key($b['requestKey']??'');
    $fp=rsv2_fingerprint(array_intersect_key($b,array_flip(['month','journalId','transaction','reason','snapshot'])));
    if(isset($s['postingKeys'][$key])){if(!hash_equals($s['postingKeys'][$key]['fingerprint'],$fp))throw new DomainException('This amendment key was already used for different details.');return $s['postingKeys'][$key]['result']+['duplicate'=>true];}
    if(!hash_equals(src_snapshot($s,$e),(string)($b['snapshot']??'')))throw new DomainException('Salary records changed. Reopen this payment before amending it.');
    $id=(string)($b['journalId']??'');$j=src_current($s,$e)[$id]??null;
    if(!$j||($j['sourceType']??'')!=='SALARY_ALLOCATED_PAYMENT'||($j['meta']['salarySheetKey']??'')!==$e.'|'.$m)throw new DomainException('Select a current actual salary payment from this company and month.');
    $reason=trim((string)($b['reason']??''));if(strlen($reason)<5||strlen($reason)>300)throw new DomainException('Enter an amendment reason (5–300 characters).');
    $sk=$e.'|'.$m;$closed=$s['salarySheets'][$sk]??[];
    if(($closed['status']??'')!=='Completed')throw new DomainException('Use the draft payment editor for an unfinished salary month.');
    $owner=$j['meta']['paymentGroupOwner'];$index=$j['meta']['paymentGroupIndex'];$original=$closed['paymentGroups'][$owner][$index]??null;
    $tx=$b['transaction']??null;if(!is_array($tx)||!$original)throw new DomainException('The original payment requires review.');
    // The recipient belongs to this source; amendments must not silently change it.
    $tx['recipient']=$original['recipient'];
    $next=$s;$reverse=sfw_reverse_payment($next,$e,$m,['journalId'=>$id,'reason'=>$reason,'date'=>$j['date']],$u,$names,true);
    $rid=$reverse['journalId'];$closed=$next['salarySheets'][$sk];
    // Isolate the replacement from other already-posted groups. Current period
    // balances count those payments; revalidating/reposting them would double-pay.
    $temp=$closed;$temp['status']='Draft';$temp['version']=0;$temp['paymentGroups']=[];
    unset($temp['masterChanged'],$temp['sourceInputsChanged'],$temp['canRefreshFromMaster']);
    $temp['masterSignature']=smw_master_signature($next,$e,$m);
    $temp['advanceSignature']=smw_advance_signature($next,$e,$m);$temp['personalSignature']=smw_personal_signature($next,$e,$m);
    foreach($temp['rows'] as $mid=>&$row){$p=$next['salaryPeriods'][$row['periodId']]??null;if(!$p)throw new DomainException('The original salary period is unavailable.');
        $row['prepared']=true;$row['baseline']=rsv2_fingerprint($p);$row['paidAfterPrepare']=(float)$p['paidAfterPrepare']+(float)($p['personalDeducted']??0);
        $row['advanceApplied']=$row['originalAdvanceApplied']=$row['requestedRecovery']=(float)$p['advanceApplied'];$row['payment']=null;$row['outstanding']=$p['outstanding'];
    }unset($row);
    // Removing this source's asset instalment increments its register version.
    foreach($tx['allocations']??[] as $ix=>$a)if(($a['type']??'')==='ASSET_PAYMENT')foreach($original['allocations'] as $old)if(($old['type']??'')==='ASSET_PAYMENT'&&($old['assetId']??'')===($a['assetId']??'')&&(int)($a['assetVersion']??-1)===(int)($s['managedAssets'][$a['assetId']]['version']??-2))$tx['allocations'][$ix]['assetVersion']=$next['managedAssets'][$a['assetId']]['version'];
    $next['salarySheets'][$sk]=$temp;
    sfw_save($next,$e,$m,['masterId'=>$owner,'version'=>0,'transactions'=>[$tx],'advanceApplied'=>$temp['rows'][$owner]['advanceApplied']],$u,$names,true);
    $valid=$next['salarySheets'][$sk];
    foreach($valid['rows'] as $mid=>$row)if(abs($row['advanceApplied']-$temp['rows'][$mid]['advanceApplied'])>.005)throw new DomainException('Payment exceeds the unpaid entitlement after posted advance recovery for '.$row['name'].'.');
    $ids=sfw_post_groups($next,$e,$m,$valid,$u,$names);if(count($ids)!==1)throw new DomainException('Amend exactly one actual payment.');$nid=$ids[0];$tx=$valid['paymentGroups'][$owner][0];
    foreach($tx['allocations'] as $a)foreach(sea_charges($a) as $c){$pid=rsv2_pid($e,$m,$c['masterId']);$p=&$next['salaryPeriods'][$pid];$p['paidAfterPrepare']=round($p['paidAfterPrepare']+$c['amount'],2);$p['outstanding']=round($p['outstanding']-$c['amount'],2);if($p['outstanding']<-.005)throw new DomainException('Payment exceeds the beneficiary balance.');$p['status']=$p['outstanding']>.005?'Payable':'Paid';unset($p);}
    $closed['paymentGroups'][$owner][$index]=$tx;
    $groupPaid=[];foreach(sfw_groups($closed) as $ts)foreach($ts as $t)if(($t['status']??'')!=='Reversed')foreach($t['allocations'] as $a)foreach(sea_charges($a) as $c)$groupPaid[$c['masterId']]=round(($groupPaid[$c['masterId']]??0)+$c['amount'],2);
    foreach($closed['rows'] as $mid=>&$row){$p=$next['salaryPeriods'][$row['periodId']];$row['paidAfterPrepare']=round($p['paidAfterPrepare']+($p['personalDeducted']??0)-($groupPaid[$mid]??0),2);}unset($row);
    sfw_recalculate($closed);
    // Preserve the completed month's posted recovery and entitlement exactly.
    foreach($closed['rows'] as $mid=>$row)if(abs($row['advanceApplied']-$s['salarySheets'][$sk]['rows'][$mid]['advanceApplied'])>.005)throw new DomainException('A payment amendment cannot change posted advance recovery.');
    $closed['paymentPostIds']=array_map(static fn($v)=>$v===$id?$nid:$v,$closed['paymentPostIds']);
    $closed['corrections'][array_key_last($closed['corrections'])]['replacementPostId']=$nid;$closed['updatedAt']=gmdate('c');$next['salarySheets'][$sk]=$closed;
    $meta=&$next['journals'][$nid]['meta'];$meta['paymentGroupIndex']=$index;$meta['salaryMonthPostId']=$closed['journalId'];$meta['publicPostId']=tt_accounts_public_post($s,$j);$meta['amendmentOfPostId']=$id;$meta['amendmentReason']=$reason;$meta['reversalPostId']=$rid;$meta['amendmentNote']='Edited '.gmdate('Y-m-d').' by '.($u['full_name']??$u['username']??'Accounts').' — '.$reason;
    // Retain a cleared-bank identity only when its amount, date and source are unchanged.
    if($tx['date']===$original['date']&&$tx['paymentAccountId']===$original['paymentAccountId']&&abs($tx['amount']-$original['amount'])<.005)$meta['reconciliationPostId']=$j['meta']['reconciliationPostId']??$id;
    $meta['salarySummary']=src_summary($next,$e,$m,$closed);unset($meta);
    $next['journals'][$id]['amendedByPostId']=$nid;$next['journals'][$id]['amendmentReversalPostId']=$rid;
    foreach($next['postingKeys']??[] as $pk=>$r)foreach(['journalId','replacementPostId'] as $f)if(($r['result'][$f]??'')===$id)$next['postingKeys'][$pk]['result'][$f]=$nid;
    $next['postAmendments'][]=['originalPostId'=>$id,'reversalPostId'=>$rid,'replacementPostId'=>$nid,'entity'=>$e,'reason'=>$reason,'date'=>$tx['date'],'actor'=>$u['username']??'Accounts','at'=>gmdate('c')];
    $result=['journalId'=>$nid,'replacementPostId'=>$nid,'reversalPostId'=>$rid];$next['postingKeys'][$key]=['fingerprint'=>$fp,'result'=>$result,'createdAt'=>gmdate('c')];$s=$next;return $result;
}
