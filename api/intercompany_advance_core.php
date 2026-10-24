<?php
declare(strict_types=1);

/** Apply a complete, posted Pakistan credit advice to its linked TG shipment.
 * Original bank postings and dates are immutable; application is a separate
 * pair of non-cash journals in the same locked Accounts store.
 */
function ica_live_journal(array $s,string $id,string $entity):array {
    $j=$s['journals'][$id]??null;
    if(!is_array($j)||($j['entity']??'')!==$entity||($j['status']??'')!=='Posted')throw new DomainException('A source journal is unavailable.');
    foreach((array)($s['journals']??[]) as $other)if(($other['reversalOf']??'')===$id)throw new DomainException('A source journal has been reversed.');
    return $j;
}
function ica_sources(array $s,string $entity):array {
    $out=[];
    foreach((array)($s['exportReceipts']??[]) as $r){
        if(($r['entity']??'')!==$entity||($r['status']??'')!=='Accounts Approved / Posted'||strtoupper((string)($r['remitter']??''))!=='TG'||empty($r['tgRemittanceId']))continue;
        $alloc=(array)($r['allocations']??[]);
        if(count($alloc)!==1||($alloc[0]['targetType']??'')!=='UNAPPLIED_TG')continue;
        $out[]=['id'=>$r['id'],'reference'=>$r['bankAdviceRef'],'date'=>$r['date'],'currency'=>$r['transactionCurrency'],'amount'=>(float)$alloc[0]['foreignAmount'],'rate'=>(float)$r['realizationRate'],'postId'=>$r['journalId']];
    }
    return $out;
}
function ica_line(string $code,float $dr,float $cr,array $names,array $extra=[]):array {
    if(!isset($names[$code]))throw new DomainException('Missing accounting classification '.$code.'.');
    return ['account'=>$code,'accountName'=>$names[$code],'debit'=>round($dr,2),'credit'=>round($cr,2)]+$extra;
}
function ica_journal(array &$s,array $u,string $entity,string $date,string $ref,array $lines,array $meta):array {
    $dr=round(array_sum(array_column($lines,'debit')),2);$cr=round(array_sum(array_column($lines,'credit')),2);
    if((int)round($dr*100)!==(int)round($cr*100))throw new RuntimeException('Advance application is unbalanced.');
    $id=tt_next_post_id((array)$s['journals'],'Accounts','Journal');
    return $s['journals'][$id]=['id'=>$id,'entity'=>$entity,'date'=>$date,'sourceType'=>'INTERCOMPANY_ADVANCE_APPLIED','reference'=>$ref,'narration'=>'Apply existing TG advance — '.$ref,'lines'=>$lines,'totalDebit'=>$dr,'totalCredit'=>$cr,'status'=>'Posted','meta'=>$meta,'createdAt'=>gmdate('c'),'createdBy'=>(string)($u['full_name']??$u['username']??'Accounts'),'userId'=>(int)($u['id']??0),'reversalOf'=>null];
}
function ica_apply(array &$s,array $b,array $u,array $names):array {
    $rid=(string)($b['receiptId']??'');$cid=(string)($b['candidateId']??'');$date=(string)($b['date']??'');$note=trim((string)($b['note']??''));
    $id='ICA-'.substr(hash('sha256',$rid.'|'.$cid),0,24);$hash=hash('sha256',json_encode([$rid,$cid,$date,$note]));
    if(isset($s['intercompanyAdvanceApplications'][$id])){
        $old=$s['intercompanyAdvanceApplications'][$id];
        if(($old['requestHash']??'')!==$hash)throw new DomainException('This application already exists with different details.');
        return $old;
    }
    if((int)($b['expectedRevision']??-1)!==(int)($s['revision']??0))throw new DomainException('Accounts changed. Reopen the advance application before posting.');
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    if(!$d||$d->format('Y-m-d')!==$date||$date>gmdate('Y-m-d')||strlen($note)<10)throw new DomainException('Enter a valid application date and a settlement note.');
    $r=$s['exportReceipts'][$rid]??[];$c=$s['exportCandidates'][$cid]??[];$entity=(string)($r['entity']??'');
    if(!in_array($entity,['TTI','BRM'],true)||($b['entity']??'')!==$entity||($r['status']??'')!=='Accounts Approved / Posted'||strtoupper((string)($r['remitter']??''))!=='TG')throw new DomainException('Select a posted TG credit advice in these company books.');
    $alloc=(array)($r['allocations']??[]);
    if(count($alloc)!==1||($alloc[0]['targetType']??'')!=='UNAPPLIED_TG')throw new DomainException('This workflow requires a complete unapplied TG credit advice.');
    $a=$alloc[0];$amount=round((float)($a['foreignAmount']??0),2);$currency=(string)($r['transactionCurrency']??'');
    if($amount<=0||$amount!==round((float)($r['foreignAmount']??0),2)||$amount!==round((float)($c['transactionAmount']??0),2)||($c['entity']??'')!==$entity||($c['candidateType']??'')!=='TG_PAKISTAN_INTERCOMPANY'||($c['counterparty']??'')!=='TG'||($c['transactionCurrency']??'')!==$currency||empty($c['journalId']))throw new DomainException('Choose the posted linked shipment with the same full advance amount and currency.');
    $mid=(string)($c['mirrorCandidateId']??'');$m=$s['exportCandidates'][$mid]??[];
    if(($m['linkedCandidateId']??'')!==$cid||($m['entity']??'')!=='TG'||($m['candidateType']??'')!=='TG_INTERCOMPANY_PAYABLE'||($m['counterparty']??'')!==$entity||($m['transactionCurrency']??'')!==$currency||round((float)($m['transactionAmount']??0),2)!==$amount||empty($m['journalId']))throw new DomainException('Recognize the matching TG payable before applying the advance.');
    foreach([$r['date']??'',$c['recognitionDate']??'',$m['recognitionDate']??''] as $sourceDate)if($sourceDate===''||$date<$sourceDate)throw new DomainException('Application date cannot precede the receipt or either shipment recognition.');
    if(($a['contractRef']??'')!==''&&($a['contractRef']??'')!==($c['meta']['contractRef']??''))throw new DomainException('The credit advice belongs to a different shipment contract.');
    foreach((array)($s['exportReceipts']??[]) as $prior)if(($prior['status']??'')==='Accounts Approved / Posted')foreach((array)($prior['allocations']??[]) as $pa)if(($pa['targetId']??'')===$cid&&(float)($pa['foreignAmount']??0)>0)throw new DomainException('The shipment already has an allocation.');
    $remitId=(string)($r['tgRemittanceId']??'');$remit=$s['tgRemittances'][$remitId]??[];
    if(($remit['status']??'')!=='Posted'||($remit['counterparty']??'')!==$entity||($remit['currency']??'')!==$currency||!in_array($rid,(array)($remit['receiptIds']??[]),true)||$date<($remit['date']??''))throw new DomainException('A matching confirmed TG remittance is required.');
    $pj=ica_live_journal($s,(string)$r['journalId'],$entity);ica_live_journal($s,(string)$c['journalId'],$entity);ica_live_journal($s,(string)$m['journalId'],'TG');$tj=ica_live_journal($s,(string)($remit['journalId']??''),'TG');
    $txid='';foreach((array)($s['tgBankTransactions']??[]) as $key=>$tx)if(($tx['journalId']??'')===($remit['journalId']??'')&&($tx['kind']??'')==='Payment'&&($tx['status']??'')==='Posted'){$txid=(string)$key;break;}
    if($txid==='')throw new DomainException('The original TG payment record is unavailable.');
    foreach((array)($s['tgBankTransactions']??[]) as $tx)if(!in_array($tx['status']??'',['Deleted','Reversed','Reversed for Amendment'],true)){
        if(($tx['sourceLiabilityId']??'')===$mid)throw new DomainException('The TG payable already has a payment.');
        foreach((array)($tx['liabilityAllocations']??[]) as $la)if(($la['id']??'')===$mid)throw new DomainException('The TG payable already has an allocation.');
    }
    foreach((array)($s['tgRemittanceDrafts']??[]) as $draft)if(($draft['status']??'')==='Pending')foreach((array)($draft['allocations']??[]) as $part)if(($part['sourceLiabilityId']??'')===$mid)throw new DomainException('The TG payable is reserved by a pending remittance.');
    $pakAdvance=0.0;foreach((array)$pj['lines'] as $l)if(($l['account']??'')==='2510')$pakAdvance+=(float)$l['credit']-(float)$l['debit'];
    $pakBook=round((float)($c['functionalAmount']??0),2);$pakAdvance=round($pakAdvance,2);
    if($pakAdvance<=0||abs($pakAdvance-$amount*(float)($r['realizationRate']??0))>.011||abs($pakBook-$pakAdvance)>.011)throw new DomainException('Review the historical advance valuation against the shipment before application.');
    $advanceAccount='';$tgAdvance=0.0;$advanceNative=0.0;
    foreach((array)$tj['lines'] as $l)if(in_array($l['account']??'',['1250','2110'],true)&&(float)($l['debit']??0)>0&&($l['counterparty']??'')===$entity){
        if($advanceAccount!==''&&$advanceAccount!==$l['account'])throw new DomainException('The TG payment uses multiple advance controls; review its source first.');
        $advanceAccount=$l['account'];$tgAdvance+=(float)$l['debit'];$advanceNative+=(float)($l['nativeDebit']??0);
    }
    if($advanceNative<=0||$tgAdvance<=0||$amount>$advanceNative+.001)throw new DomainException('The original TG payment has no matching native-currency advance.');
    $used=0.0;foreach((array)($s['intercompanyAdvanceApplications']??[]) as $app)if(($app['tgRemittanceId']??'')===$remitId)$used+=(float)$app['amountNative'];
    if($amount>$advanceNative-$used+.001)throw new DomainException('Application exceeds the TG remittance advance remaining.');
    $tgBook=round((float)($m['functionalAmount']??0),2);$tgAdvancePart=round($amount*$tgAdvance/$advanceNative,2);
    if($tgBook<=0||abs($tgBook-$tgAdvancePart)>.011)throw new DomainException('Review TG advance and payable carrying rates before application.');
    $meta=['intercompanyAdvanceApplicationId'=>$id,'receiptId'=>$rid,'candidateId'=>$cid,'mirrorCandidateId'=>$mid,'tgRemittanceId'=>$remitId,'transactionCurrency'=>$currency,'transactionAmount'=>$amount,'nonCash'=>true,'excludeFromManagementCashflow'=>true,'note'=>$note];
    $pakLines=[ica_line('2510',$pakAdvance,0,$names,['counterparty'=>'TG','currency'=>$currency,'nativeDebit'=>$amount,'nativeCredit'=>0]),ica_line('1240',0,$pakBook,$names,['counterparty'=>'TG','sourceCandidateId'=>$cid,'currency'=>$currency,'nativeDebit'=>0,'nativeCredit'=>$amount])];
    $tgLines=[ica_line('2500',$tgBook,0,$names,['counterparty'=>$entity,'sourceCandidateId'=>$mid,'currency'=>$currency,'nativeDebit'=>$amount,'nativeCredit'=>0]),ica_line($advanceAccount,0,$tgAdvancePart,$names,['counterparty'=>$entity,'currency'=>$currency,'nativeDebit'=>0,'nativeCredit'=>$amount])];
    $ref=(string)($c['reference']??$cid);$pak=ica_journal($s,$u,$entity,$date,$ref,$pakLines,$meta);$tg=ica_journal($s,$u,'TG',$date,$ref,$tgLines,$meta);
    $allocation=$a;$allocation['targetType']='INTERCOMPANY_RECEIVABLE';$allocation['targetId']=$cid;$allocation['invoiceRef']=$ref;$allocation['contractRef']=(string)($c['meta']['contractRef']??'');$allocation['customer']='TG';$allocation['carryingPkr']=$pakBook;$allocation['applicationDate']=$date;$allocation['intercompanyAdvanceApplicationId']=$id;
    $s['exportReceipts'][$rid]['allocations']=[$allocation];$s['exportReceipts'][$rid]['advanceApplicationId']=$id;
    $s['tgBankTransactions'][$txid]['liabilityAllocations'][]=['id'=>$mid,'amountNative'=>$amount,'applicationDate'=>$date,'intercompanyAdvanceApplicationId'=>$id];
    $record=$meta+['id'=>$id,'entity'=>$entity,'date'=>$date,'amountNative'=>$amount,'originalAllocation'=>$a,'pakJournalId'=>$pak['id'],'tgJournalId'=>$tg['id'],'tgBankTransactionId'=>$txid,'requestHash'=>$hash,'status'=>'Posted','createdAt'=>gmdate('c'),'createdBy'=>(string)($u['full_name']??$u['username']??'Accounts')];
    $s['intercompanyAdvanceApplications'][$id]=$record;$s['workflowAudit'][]=['type'=>'INTERCOMPANY_ADVANCE_APPLICATION','action'=>'POST','id'=>$id,'user'=>$record['createdBy'],'at'=>$record['createdAt'],'note'=>$note];
    return $record;
}
