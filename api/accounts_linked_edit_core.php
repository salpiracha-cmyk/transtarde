<?php
declare(strict_types=1);
require_once __DIR__.'/accounts_reference.php';
require_once __DIR__.'/tg_remittance_core.php';
require_once __DIR__.'/intercompany_advance_core.php';

/** All helpers run on the caller's locked, uncommitted Accounts copy. */
function ale_reverse(array &$s,array $j,array $u,string $reason):string {
    foreach((array)($s['journals']??[]) as $other)if(($other['reversalOf']??'')===$j['id'])throw new DomainException('This posting changed. Reopen its current entry form.');
    $id=tt_next_post_id((array)$s['journals'],'Accounts','Journal',$j['date']);$r=$j;$r['id']=$id;$r['sourceType']='POST_AMENDMENT_REVERSAL';$r['reference']=$j['id'];$r['narration']='Amendment reversal — '.$reason;$r['reversalOf']=$j['id'];$r['meta']=['originalPostId'=>$j['id'],'reason'=>$reason];
    foreach($r['lines'] as &$l)foreach([['debit','credit'],['bankDebit','bankCredit'],['nativeDebit','nativeCredit']] as [$dr,$cr])if(isset($l[$dr])||isset($l[$cr]))[$l[$dr],$l[$cr]]=[$l[$cr]??0,$l[$dr]??0];unset($l);
    [$r['totalDebit'],$r['totalCredit']]=[$j['totalCredit'],$j['totalDebit']];$r['createdAt']=gmdate('c');$r['createdBy']=$u['full_name']??$u['username']??'Accounts';$r['userId']=(int)($u['id']??0);$s['journals'][$id]=$r;return $id;
}
function ale_link(array &$s,string $oldId,string $newId,string $reverseId,string $reason):void {
    $old=$s['journals'][$oldId];$new=&$s['journals'][$newId];$identity=(string)($old['meta']['reconciliationPostId']??$oldId);$sameBank=true;
    $bankShape=static fn($l)=>array_intersect_key($l,array_flip(['account','bankAccountId','currency','debit','credit','bankDebit','bankCredit','bankReference','chequeNo','bankPaymentMethod']));
    foreach($old['lines'] as $i=>$l)if(($l['account']??'')==='1110'&&$bankShape($l)!=$bankShape($new['lines'][$i]??[]))$sameBank=false;
    foreach($new['lines'] as $i=>$l)if(($l['account']??'')==='1110'&&$bankShape($l)!=$bankShape($old['lines'][$i]??[]))$sameBank=false;
    if(!$sameBank){$records=(array)($s['bankReconciliations']??[]);foreach((array)($s['bankReconciliationHistory']??[]) as $history)$records=array_merge($records,(array)$history);foreach($records as $rec)foreach((array)($rec['clearedKeys']??[]) as $key)if(str_starts_with((string)$key,$identity.'|'))throw new DomainException('Unclear this posting in Bank Reconciliation before changing its bank amount, bank or transaction reference.');}
    if($sameBank)$new['meta']['reconciliationPostId']=$identity;else unset($new['meta']['reconciliationPostId']);
    foreach((array)($s['exportTaxCertificateMatches']??[]) as $key=>$certId)if(str_starts_with((string)$key,$oldId.'|')&&$certId!==''){
        $cert=$s['exportTaxCertificates'][$certId]??[];$date=$new['date'];$year=(int)substr($date,0,4)-((int)substr($date,5,2)<7?1:0);$fy=sprintf('%d-%02d',$year,($year+1)%100);
        if(($old['meta']['exportTaxDeductions']??[])!=($new['meta']['exportTaxDeductions']??[])||($cert['financialYear']??'')!==$fy||(!empty($cert['periodFrom'])&&$date<$cert['periodFrom'])||(!empty($cert['periodTo'])&&$date>$cert['periodTo']))throw new DomainException('Unmatch this receipt in Tax Certificates before changing its certified deduction or period.');
        $s['exportTaxCertificateMatches'][$newId.substr((string)$key,strlen($oldId))]=$certId;unset($s['exportTaxCertificateMatches'][$key]);
    }
    $new['meta']['publicPostId']=tt_accounts_public_post($s,$old);$new['meta']['amendmentOfPostId']=$oldId;$new['meta']['amendmentReason']=$reason;$new['meta']['reversalPostId']=$reverseId;$new['meta']['amendmentNote']='Corrected '.$old['date'].' → '.$new['date'].' — '.$reason;unset($new);
    $s['journals'][$oldId]['amendedByPostId']=$newId;$s['journals'][$oldId]['amendmentReversalPostId']=$reverseId;$s['postAmendments'][]=['originalPostId'=>$oldId,'replacementPostId'=>$newId,'reversalPostId'=>$reverseId,'reason'=>$reason,'date'=>$s['journals'][$newId]['date'],'entity'=>$old['entity'],'actor'=>$s['journals'][$newId]['createdBy']??'Accounts','at'=>gmdate('c')];
}
function ale_retire_side(array &$s,string $id,string $replacementId,string $reverseId):void {
    foreach((array)($s['exportTaxCertificateMatches']??[]) as $key=>$certificate)if($certificate!==''&&str_starts_with((string)$key,$id.'|'))throw new DomainException('Unmatch the separate tax deduction before removing it from this credit advice.');
    $identity=(string)($s['journals'][$id]['meta']['reconciliationPostId']??$id);$records=(array)($s['bankReconciliations']??[]);foreach((array)($s['bankReconciliationHistory']??[]) as $history)$records=array_merge($records,(array)$history);
    foreach($records as $record)foreach((array)($record['clearedKeys']??[]) as $key)if(str_starts_with((string)$key,$identity.'|'))throw new DomainException('Unclear the separate bank debit before removing it from this credit advice.');
    $s['journals'][$id]['amendedByPostId']=$replacementId;$s['journals'][$id]['amendmentReversalPostId']=$reverseId;
}
function ale_require_edit(array $u,array $journal):void {
    if(!tt_user_can_access_entity($u,(string)$journal['entity'],'Edit')||!tt_post_correction_allowed($u,$journal))throw new DomainException('Edit permission for every affected company and original workflow is required.');
}
function ale_receipt_allocations(array $s,array $receipt):array {
    return array_map(static function($a)use($s){$app=$s['intercompanyAdvanceApplications'][$a['intercompanyAdvanceApplicationId']??'']??null;return is_array($app)&&($app['status']??'')==='Posted'?(array)$app['originalAllocation']:$a;},(array)($receipt['allocations']??[]));
}
function ale_begin_remit(array &$s,string $id,array $u,string $reason):array {
    $r=$s['tgRemittances'][$id]??[];$j=$s['journals'][$r['journalId']??'']??[];
    if(($r['status']??'')!=='Posted'||($j['status']??'')!=='Posted'||($j['entity']??'')!=='TG'||($j['sourceType']??'')!=='TG_REMITTANCE_CONFIRMED'||!empty($j['amendedByPostId']))throw new DomainException('The remittance changed. Reopen its current full form.');
    ale_require_edit($u,$j);$reversal=ale_reverse($s,$j,$u,$reason);$s['tgRemittances'][$id]['status']='Reversed for Amendment';
    foreach((array)($s['tgBankTransactions']??[]) as $key=>$tx)if(($tx['journalId']??'')===$j['id']){$s['tgBankTransactions'][$key]['status']='Reversed for Amendment';$s['tgBankTransactions'][$key]['reversalJournalId']=$reversal;}
    foreach($r['receiptIds'] as $rid){$receipt=$s['exportReceipts'][$rid]??[];if(($receipt['tgRemittanceId']??'')!==$id)throw new DomainException('A linked credit advice changed. Reopen this remittance.');unset($s['exportReceipts'][$rid]['tgRemittanceId'],$s['exportReceipts'][$rid]['tgRemittancePostId']);$pj=$receipt['journalId'];unset($s['journals'][$pj]['meta']['tgRemittanceId']);$s['journals'][$pj]['meta']['tgPostIds']=[];}
    return ['record'=>$r,'journal'=>$j,'reversal'=>$reversal,'reason'=>$reason];
}
function ale_begin_applications(array &$s,string $rid,array $u,string $reason):array {
    $sourceAllocations=ale_receipt_allocations($s,$s['exportReceipts'][$rid]);$out=[];foreach((array)($s['intercompanyAdvanceApplications']??[]) as $id=>$app)if(($app['receiptId']??'')===$rid&&($app['status']??'')==='Posted'){
        $reversals=[];foreach(['pakJournalId','tgJournalId'] as $key){$j=ica_live_journal($s,(string)$app[$key],$key==='tgJournalId'?'TG':$app['entity']);ale_require_edit($u,$j);$reversals[$key]=ale_reverse($s,$j,$u,$reason);}
        $out[]=['record'=>$app,'reversals'=>$reversals];$s['intercompanyAdvanceApplicationAmendments'][]=['original'=>$app,'reversals'=>$reversals,'reason'=>$reason,'at'=>gmdate('c')];unset($s['intercompanyAdvanceApplications'][$id]);
        $s['exportReceipts'][$rid]['allocations']=$sourceAllocations;unset($s['exportReceipts'][$rid]['advanceApplicationId']);
    }return $out;
}
function ale_finish_applications(array &$s,array $contexts,string $rid,array $u,array $catalog,string $reason):void {
    $names=[];foreach($catalog as $code=>$row)$names[(string)$code]=is_array($row)?($row['name']??$code):$row;
    foreach($contexts as $context){
        $old=$context['record'];$r=$s['exportReceipts'][$rid];$remit=$s['tgRemittances'][$r['tgRemittanceId']??'']??[];$currency=$r['transactionCurrency'];$amount=(float)$old['amountNative'];$date=$old['date'];$cid=$old['candidateId'];$mid=$old['mirrorCandidateId'];
        $candidate=$s['exportCandidates'][$cid]??[];$mirror=$s['exportCandidates'][$mid]??[];
        if(($candidate['entity']??'')!==$r['entity']||($candidate['mirrorCandidateId']??'')!==$mid||($mirror['linkedCandidateId']??'')!==$cid||($mirror['entity']??'')!=='TG'||($candidate['transactionCurrency']??'')!==$currency||($mirror['transactionCurrency']??'')!==$currency||abs((float)($candidate['transactionAmount']??0)-$amount)>.005||abs((float)($mirror['transactionAmount']??0)-$amount)>.005)throw new DomainException('The linked shipment changed. Review its advance application before correcting this receipt.');
        ica_live_journal($s,(string)($candidate['journalId']??''),$r['entity']);ica_live_journal($s,(string)($mirror['journalId']??''),'TG');
        foreach((array)($s['exportReceipts']??[]) as $other)if(($other['status']??'')==='Accounts Approved / Posted'&&($other['id']??'')!==$rid)foreach((array)($other['allocations']??[]) as $part)if(($part['targetId']??'')===$cid&&(float)($part['foreignAmount']??0)>0)throw new DomainException('Another receipt now settles the linked shipment. Review that allocation first.');
        if($currency!==$old['transactionCurrency']||$r['date']>$date||($remit['date']??'9999')>$date)throw new DomainException('The corrected receipt/remittance must retain the application currency and precede its application date.');
        $index=null;foreach($r['allocations'] as $i=>$a)if(($a['targetType']??'')==='UNAPPLIED_TG'&&(float)$a['foreignAmount']>=$amount-.005&&(($a['contractRef']??'')===''||($a['contractRef']??'')===($s['exportCandidates'][$cid]['meta']['contractRef']??''))){$index=$i;break;}
        if($index===null)throw new DomainException('The existing shipment application requires an unapplied TG advance of '.$currency.' '.number_format($amount,2).'. Correct the application before removing that advance.');
        $a=$r['allocations'][$index];$pakAdvance=round($amount*(float)$r['realizationRate'],2);$pakBook=(float)$s['exportCandidates'][$cid]['functionalAmount'];$tgBook=(float)$s['exportCandidates'][$mid]['functionalAmount'];$tgAdvance=0.0;$native=0.0;$control='';
        foreach($s['journals'][$remit['journalId']]['lines'] as $l)if(in_array($l['account']??'',['1250','2110'],true)&&(float)($l['debit']??0)>0){$control=$l['account'];$tgAdvance+=(float)$l['debit'];$native+=(float)($l['nativeDebit']??0);}
        $used=0.0;foreach((array)($s['intercompanyAdvanceApplications']??[]) as $app)if(($app['tgRemittanceId']??'')===$remit['id']&&($app['status']??'')==='Posted')$used+=(float)$app['amountNative'];
        if($native<=0||$amount>$native-$used+.005)throw new DomainException('The corrected remittance has insufficient advance for the linked shipment application.');
        $tgPart=round($amount*$tgAdvance/$native,2);$meta=$s['journals'][$old['pakJournalId']]['meta'];$meta['receiptId']=$rid;$meta['tgRemittanceId']=$remit['id'];$meta['amendmentReason']=$reason;
        $pakLines=[ica_line('2510',$pakAdvance,0,$names,['counterparty'=>'TG','currency'=>$currency,'nativeDebit'=>$amount,'nativeCredit'=>0]),ica_line('1240',0,$pakBook,$names,['counterparty'=>'TG','sourceCandidateId'=>$cid,'currency'=>$currency,'nativeDebit'=>0,'nativeCredit'=>$amount])];
        $fx=round($pakAdvance-$pakBook,2);if($fx>0)$pakLines[]=ica_line('7100',0,$fx,$names,['fxDirection'=>'Gain']);elseif($fx<0)$pakLines[]=ica_line('7100',-$fx,0,$names,['fxDirection'=>'Loss']);
        $tgLines=[ica_line('2500',$tgBook,0,$names,['counterparty'=>$r['entity'],'sourceCandidateId'=>$mid,'currency'=>$currency,'nativeDebit'=>$amount,'nativeCredit'=>0]),ica_line($control,0,$tgPart,$names,['counterparty'=>$r['entity'],'currency'=>$currency,'nativeDebit'=>0,'nativeCredit'=>$amount])];
        $fx=round($tgBook-$tgPart,2);if($fx>0)$tgLines[]=ica_line('7100',0,$fx,$names,['fxDirection'=>'Gain']);elseif($fx<0)$tgLines[]=ica_line('7100',-$fx,0,$names,['fxDirection'=>'Loss']);
        $pj=ica_journal($s,$u,$r['entity'],$date,$s['exportCandidates'][$cid]['reference'],$pakLines,$meta);$tj=ica_journal($s,$u,'TG',$date,$s['exportCandidates'][$cid]['reference'],$tgLines,$meta);
        foreach(['pakJournalId'=>$pj['id'],'tgJournalId'=>$tj['id']] as $key=>$jid)ale_link($s,$old[$key],$jid,$context['reversals'][$key],$reason);
        $txid='';foreach($s['tgBankTransactions'] as $key=>$tx)if(($tx['journalId']??'')===$remit['journalId'])$txid=$key;
        $record=$old;$record['receiptId']=$rid;$record['pakJournalId']=$pj['id'];$record['tgJournalId']=$tj['id'];$record['tgBankTransactionId']=$txid;$record['originalAllocation']=$a;$record['originalAllocation']['foreignAmount']=$amount;$record['originalAllocation']['realizedPkr']=$pakAdvance;$record['originalAllocation']['carryingPkr']=$pakAdvance;$record['amendedAt']=gmdate('c');$s['intercompanyAdvanceApplications'][$old['id']]=$record;
        $applied=$a;$applied['targetType']='INTERCOMPANY_RECEIVABLE';$applied['targetId']=$cid;$applied['invoiceRef']=$s['exportCandidates'][$cid]['reference'];$applied['contractRef']=$s['exportCandidates'][$cid]['meta']['contractRef']??'';$applied['foreignAmount']=$amount;$applied['realizedPkr']=$pakAdvance;$applied['carryingPkr']=$pakBook;$applied['applicationDate']=$date;$applied['intercompanyAdvanceApplicationId']=$old['id'];
        $remaining=round((float)$a['foreignAmount']-$amount,2);$alloc=$r['allocations'];array_splice($alloc,$index,1,[$applied]);if($remaining>.005){$a['foreignAmount']=$remaining;$a['realizedPkr']=round($remaining*$r['realizationRate'],2);$a['carryingPkr']=$a['realizedPkr'];$alloc[]=$a;}$s['exportReceipts'][$rid]['allocations']=$alloc;$s['exportReceipts'][$rid]['advanceApplicationId']=$old['id'];$s['tgBankTransactions'][$txid]['liabilityAllocations'][]=['id'=>$mid,'amountNative'=>$amount,'applicationDate'=>$date,'intercompanyAdvanceApplicationId'=>$old['id']];
    }
}
function ale_remit_banks():array {
    $out=[];foreach((array)(tt_list_masters()['banks']??[]) as $r){$v=array_values((array)($r['values']??[]));$linked=strtoupper((string)($v[1]??''));if(($v[0]??'')!=='Company Account'||!(str_contains($linked,'TRANS GRAINS')||preg_match('/(^|\W)TG($|\W)/',$linked))||strcasecmp((string)($v[13]??'Active'),'Active')!==0||(($v[8]??'')===''&&($v[9]??'')===''))continue;$out[$r['id']]=['id'=>$r['id'],'bank'=>$v[4]??'','title'=>$v[3]??'','currency'=>strtoupper($v[7]??'')];}return $out;
}
function ale_advance_basis(array $s,array $source):array {
    $basis=[];foreach((array)($source['allocations']??[]) as $a){
        if(isset($a['legacyJournalId'])){$j=$s['journals'][$a['legacyJournalId']]??[];foreach((array)($j['lines']??[]) as $line)if(in_array($line['account']??'',['1250','2110'],true)&&(float)($line['debit']??0)>0){$native=(float)($j['meta']['amountNative']??0);if($native>0)$basis[$line['account']]=(float)$line['debit']/$native;}}
        elseif(in_array($a['targetAccount']??'',['1250','2110'],true))$basis[$a['targetAccount']]=(float)($a['payableRate']??0);
    }return count($basis)===1?['account'=>array_key_first($basis),'rate'=>reset($basis)]:[];
}
function ale_finish_remit(array &$s,array $context,array $u,array $catalog,array $changes=[],array $receiptMap=[]):array {
    $old=$context['record'];$sources=[];$ids=[];$fingerprints=[];$banks=ale_remit_banks();$bankId=(string)($changes['bankAccountId']??$old['bankAccountId']);$bank=$banks[$bankId]??[];
    if(($bank['currency']??'')!==$old['currency'])throw new DomainException('The remittance bank currency must match its linked credit advices.');
    $available=(array)($old['reviewSources']??[]);$oldSources=array_column($available,null,'id');$pending=array_column(tgr_items($s),null,'id');$requested=array_key_exists('ids',$changes)?array_values((array)$changes['ids']):array_keys($oldSources);
    if(!$requested||count($requested)!==count(array_unique($requested)))throw new DomainException('Select the credit advices belonging to this remittance.');
    foreach($requested as $sid)if(!isset($oldSources[$sid])){if(!isset($pending[$sid])||!empty($pending[$sid]['legacy']))throw new DomainException('A selected credit advice changed. Reopen the full form.');$available[]=$pending[$sid];}
    foreach($available as $source){
        $include=in_array($source['id'],$requested,true);
        if(($source['counterparty']??'')!==$old['counterparty']||($source['currency']??'')!==$old['currency'])throw new DomainException('Combine only advices for the original receiving company and currency.');
        $mapped=array_map(static fn($rid)=>$receiptMap[$rid]??$rid,$source['receiptIds']);
        if(count($mapped)===1&&$mapped[0]!==$source['receiptIds'][0]){$basis=ale_advance_basis($s,$source);$receipt=$s['exportReceipts'][$mapped[0]];$source=$s['tgRemittanceDrafts'][$receipt['tgRemittanceDraftId']??'']??[];if(!$source)throw new DomainException('The corrected receipt must retain its TG remittance source.');if($basis)foreach($source['allocations'] as &$part)if(($part['targetAccount']??'')==='1250'){$part['targetAccount']=$basis['account'];if($basis['rate']>0)$part['payableRate']=$basis['rate'];}unset($part);}
        else{foreach($source['allocations'] as &$a)if(isset($a['legacyJournalId'])){$legacy=$s['journals'][$a['legacyJournalId']]??[];$part=null;foreach((array)($legacy['lines']??[]) as $l)if(!in_array($l['account']??'',['1110','7100'],true)){$native=(float)($legacy['meta']['amountNative']??0);$part=['targetAccount'=>$l['account'],'sourceLiabilityId'=>$l['sourceLiabilityId']??'','foreignAmount'=>$native,'payableRate'=>(float)$l['debit']/max(.01,$native)];}if(!$part)throw new DomainException('An original remittance allocation is unavailable.');$a=$part;}unset($a);$source['id']=tgr_id((array)($s['tgRemittanceDrafts']??[]),'TGRD');}
        $source['receiptIds']=$mapped;$source['pakJournalIds']=array_map(static fn($rid)=>$s['exportReceipts'][$rid]['journalId'],$mapped);$source['bankAdviceRefs']=array_map(static fn($rid)=>$s['exportReceipts'][$rid]['bankAdviceRef'],$mapped);$source['bankAccountId']=$bankId;$source['bank']=$bank['bank'];$source['legacy']=false;$source['status']='Pending';unset($source['reopenedFrom'],$source['fingerprint']);
        foreach($mapped as $rid)if(($s['exportReceipts'][$rid]['entity']??'')!==$old['counterparty']||($s['exportReceipts'][$rid]['remitter']??'')!=='TG'||($s['exportReceipts'][$rid]['transactionCurrency']??'')!==$old['currency'])throw new DomainException('All advices in this remittance must retain the same receiving company, TG remitter and currency.');
        $s['tgRemittanceDrafts'][$source['id']]=$source;
        if(!$include){foreach($mapped as $rid){foreach((array)($s['intercompanyAdvanceApplications']??[]) as $app)if(($app['receiptId']??'')===$rid&&($app['status']??'')==='Posted')throw new DomainException('Correct the linked advance application before removing this credit advice from its remittance.');$s['exportReceipts'][$rid]['tgRemittanceDraftId']=$source['id'];$s['journals'][$s['exportReceipts'][$rid]['journalId']]['meta']['tgRemittanceDraftId']=$source['id'];}continue;}
        $ids[]=$source['id'];$fingerprints[$source['id']]=tgr_fingerprint($source);$sources[]=$source;
    }
    if(!$ids)throw new DomainException('The original remittance sources are unavailable.');
    $names=[];foreach($catalog as $code=>$a)$names[(string)$code]=is_array($a)?($a['name']??$code):$a;
    $rate=0.0;foreach($context['journal']['lines'] as $l)if(($l['account']??'')==='1110'&&($l['bankAccountId']??'')===$old['bankAccountId']&&(float)($l['bankCredit']??0)>0){$rate=(float)$l['credit']/(float)$l['bankCredit'];break;}
    $b=['requestKey'=>'amend-'.bin2hex(random_bytes(12)),'ids'=>$ids,'fingerprints'=>$fingerprints,'sameRemittanceConfirmed'=>true,'date'=>$changes['date']??$old['date'],'bankReference'=>$changes['bankReference']??$old['bankReference'],'chargeAmount'=>$changes['chargeAmount']??$old['bankChargeNative'],'vatAmount'=>$changes['vatAmount']??$old['bankVatNative'],'chargeBankAccountId'=>$changes['chargeBankAccountId']??$old['chargeBankAccountId'],'reason'=>$context['reason']];
    $chargeRate=0.0;foreach($context['journal']['lines'] as $l)if(($l['account']??'')==='1110'&&($l['bankAccountId']??'')===$old['chargeBankAccountId']&&!empty($l['chargeType'])&&(float)($l['bankCredit']??0)>0){$chargeRate=(float)$l['credit']/(float)$l['bankCredit'];break;}
    $rates=[];foreach($banks as $bank)$rates[$bank['currency']]=$bank['currency']==='AED'?1:(float)(tt_company_fx_rate('TG',$bank['currency'],'AED')??0);
    $internal=['principalRate'=>$rate];if($chargeRate>0&&($banks[$b['chargeBankAccountId']]['currency']??'')===($old['chargeCurrency']??$old['currency']))$internal['chargeRate']=$chargeRate;$new=tgr_confirm($s,$b,$u,$banks,$names,$rates,$internal);$new=$s['tgRemittances'][$new['id']];$newId=$new['id'];$new['id']=$old['id'];$new['amendedAt']=gmdate('c');$new['amendmentReason']=$context['reason'];
    $s['tgRemittanceAmendments'][]=['original'=>$old,'replacementJournalId'=>$new['journalId'],'reversalJournalId'=>$context['reversal'],'reason'=>$context['reason'],'at'=>gmdate('c')];$s['tgRemittances'][$old['id']]=$new;unset($s['tgRemittances'][$newId]);
    foreach($new['receiptIds'] as $rid){$s['exportReceipts'][$rid]['tgRemittanceId']=$old['id'];$s['exportReceipts'][$rid]['tgBankAccountId']=$bankId;$pj=$s['exportReceipts'][$rid]['journalId'];$s['journals'][$pj]['meta']['tgRemittanceId']=$old['id'];}
    foreach($ids as $did)$s['tgRemittanceDrafts'][$did]['remittanceId']=$old['id'];foreach($s['tgRemittanceRequests'] as &$request)if($request['id']===$newId)$request['id']=$old['id'];unset($request);
    $newTx='';foreach($s['tgBankTransactions'] as $tid=>&$tx)if(($tx['journalId']??'')===$new['journalId']){$newTx=$tid;$tx['remittanceId']=$old['id'];$tx['tgRemittanceId']=$old['id'];}unset($tx);$s['journals'][$new['journalId']]['meta']['id']=$old['id'];$s['journals'][$new['journalId']]['meta']['tgRemittanceId']=$old['id'];
    foreach((array)($s['intercompanyAdvanceApplications']??[]) as $aid=>$app)if(($app['tgRemittanceId']??'')===$old['id']&&($app['status']??'')==='Posted'){
        if($new['date']>$app['date'])throw new DomainException('The remittance cannot follow its posted advance application.');$s['intercompanyAdvanceApplications'][$aid]['tgBankTransactionId']=$newTx;$s['tgBankTransactions'][$newTx]['liabilityAllocations'][]=['id'=>$app['mirrorCandidateId'],'amountNative'=>$app['amountNative'],'applicationDate'=>$app['date'],'intercompanyAdvanceApplicationId'=>$aid];
    }
    ale_link($s,$old['journalId'],$new['journalId'],$context['reversal'],$context['reason']);return $new;
}
