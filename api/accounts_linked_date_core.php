<?php
declare(strict_types=1);
require_once __DIR__.'/accounts_reference.php';

/** Date-only corrections retain the native receipt/remittance and all monetary allocations.
 * The caller owns the Accounts lock and validates the original workflow/company Edit right.
 */
function ald_live(array $s,string $id,string $entity):array {
    $j=$s['journals'][$id]??null;
    if(!is_array($j)||($j['entity']??'')!==$entity||($j['status']??'')!=='Posted'||!empty($j['reversalOf'])||!empty($j['amendedByPostId'])||!empty($j['reversedByPostId']))throw new DomainException('This posting changed. Reopen its current Post ID before correcting the date.');
    foreach((array)($s['journals']??[]) as $other)if(($other['reversalOf']??'')===$id)throw new DomainException('This posting has already been reversed. Complete its existing correction first.');
    return $j;
}
function ald_correct(array &$store,array $u,string $id,array $b):array {
    $entity=(string)($store['journals'][$id]['entity']??'');$original=ald_live($store,$id,$entity);
    $rid=(string)($original['meta']['receiptId']??'');$remitId=(string)($original['meta']['tgRemittanceId']??'');
    $receipt=$store['exportReceipts'][$rid]??null;$remit=$store['tgRemittances'][$remitId]??null;
    $isReceipt=($original['sourceType']??'')==='EXPORT_FOREIGN_RECEIPT'&&in_array($entity,['TTI','BRM'],true)&&is_array($receipt)&&($receipt['entity']??'')===$entity&&($receipt['remitter']??'')==='TG'&&($receipt['status']??'')==='Accounts Approved / Posted'&&($receipt['journalId']??'')===$id&&($receipt['date']??'')===($original['date']??'');
    $isRemit=($original['sourceType']??'')==='TG_REMITTANCE_CONFIRMED'&&$entity==='TG'&&is_array($remit)&&($remit['status']??'')==='Posted'&&($remit['journalId']??'')===$id&&($remit['date']??'')===($original['date']??'');
    if(!$isReceipt&&!$isRemit)throw new DomainException('Date-only correction requires the current TG remittance or its Pakistan credit advice.');
    if(($b['entity']??'')!==$entity||($b['expectedDate']??'')!==($original['date']??''))throw new DomainException('The company or posting date changed. Reopen this posting before saving.');
    $date=trim((string)($b['date']??''));$d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);$today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Karachi')))->format('Y-m-d');
    if(!$d||$d->format('Y-m-d')!==$date||$date>$today)throw new DomainException('Enter a valid posting date no later than today.');
    if($date===$original['date'])throw new DomainException('Choose the corrected posting date.');
    $reason=trim((string)($b['reason']??''));if(mb_strlen($reason)<5||mb_strlen($reason)>300)throw new DomainException('Enter a correction reason of 5–300 characters.');
    foreach((array)($store['intercompanyAdvanceApplications']??[]) as $app)if(($app['status']??'')==='Posted'&&($isReceipt?($app['receiptId']??'')===$rid:($app['tgRemittanceId']??'')===$remitId)&&$date>(string)($app['date']??''))throw new DomainException('The corrected date cannot follow the linked advance application date. Correct that application first.');
    if($isReceipt&&!empty($receipt['forwardBookingId'])){
        $forward=$store['forwardBookings'][$receipt['forwardBookingId']]??[];
        if(!$forward||$date<($forward['startDate']??'')||$date>($forward['expiryDate']??''))throw new DomainException('The corrected date must stay within the linked forward booking window.');
    }
    $ids=[$id];
    if($isReceipt){
        foreach(array_merge([(string)($receipt['separateChargeJournalId']??'')],(array)($receipt['indentorPayableJournalIds']??[])) as $linked)if($linked!=='')$ids[]=$linked;
        if(count($ids)!==count(array_unique($ids)))throw new DomainException('Receipt journal links need review before correcting the date.');
        foreach($ids as $linked){$j=ald_live($store,$linked,$entity);if(($j['meta']['receiptId']??'')!==$rid||$j['date']!==$original['date'])throw new DomainException('A linked receipt journal has a different source or date. Review it before correcting this advice.');}
    }
    // Validate every dependency first; any later failure still leaves the caller unchanged.
    $next=$store;$map=[];$reversals=[];$now=gmdate('c');$actor=(string)($u['full_name']??$u['username']??'Accounts');
    foreach($ids as $oldId){
        $old=$store['journals'][$oldId];$reverse=$old;$reverseId=tt_next_post_id((array)$next['journals'],'Accounts','Journal',$old['date']);
        $reverse['id']=$reverseId;$reverse['sourceType']='POST_AMENDMENT_REVERSAL';$reverse['reference']=$oldId;$reverse['narration']='Date correction reversal — '.$reason;$reverse['reversalOf']=$oldId;
        $reverse['meta']=['originalPostId'=>$oldId,'reason'=>$reason,'dateCorrection'=>true];
        foreach($reverse['lines'] as &$line)foreach([['debit','credit'],['bankDebit','bankCredit'],['nativeDebit','nativeCredit']] as [$dr,$cr])if(isset($line[$dr])||isset($line[$cr]))[$line[$dr],$line[$cr]]=[$line[$cr]??0,$line[$dr]??0];unset($line);
        [$reverse['totalDebit'],$reverse['totalCredit']]=[$old['totalCredit'],$old['totalDebit']];$reverse['createdAt']=$now;$reverse['createdBy']=$actor;$reverse['userId']=(int)($u['id']??0);$next['journals'][$reverseId]=$reverse;
        $new=$old;$newId=tt_next_post_id((array)$next['journals'],'Accounts','Journal',$date);$new['id']=$newId;$new['date']=$date;$new['createdAt']=$now;$new['createdBy']=$actor;$new['userId']=(int)($u['id']??0);
        $new['meta']['publicPostId']=tt_accounts_public_post($store,$old);$new['meta']['amendmentOfPostId']=$oldId;$new['meta']['amendmentReason']=$reason;$new['meta']['reversalPostId']=$reverseId;
        $new['meta']['amendmentNote']='Posting date: '.$old['date'].' → '.$date.' — '.$reason;
        $next['journals'][$newId]=$new;$next['journals'][$oldId]['amendedByPostId']=$newId;$next['journals'][$oldId]['amendmentReversalPostId']=$reverseId;$map[$oldId]=$newId;$reversals[$oldId]=$reverseId;
        $next['postAmendments'][]=['originalPostId'=>$oldId,'replacementPostId'=>$newId,'reversalPostId'=>$reverseId,'reason'=>$reason,'date'=>$date,'actor'=>$actor,'at'=>$now,'entity'=>$entity,'datesOnly'=>true];
    }
    if($isReceipt){
        $r=&$next['exportReceipts'][$rid];$r['date']=$date;$r['journalId']=$map[$id];
        if(!empty($r['separateChargeJournalId']))$r['separateChargeJournalId']=$map[$r['separateChargeJournalId']];
        if(!empty($r['indentorPayableJournalIds']))$r['indentorPayableJournalIds']=array_map(static fn($key)=>$map[$key],$r['indentorPayableJournalIds']);
        unset($r);
        foreach((array)($next['exportCandidates']??[]) as $key=>$candidate){$linked=(string)($candidate['meta']['indentorPayableJournalId']??'');if(isset($map[$linked]))$next['exportCandidates'][$key]['meta']['indentorPayableJournalId']=$map[$linked];}
        foreach((array)($next['tgRemittanceDrafts']??[]) as $key=>$draft)$next['tgRemittanceDrafts'][$key]['pakJournalIds']=array_map(static fn($key)=>$map[$key]??$key,(array)($draft['pakJournalIds']??[]));
        foreach((array)($next['tgRemittances']??[]) as $key=>$record)foreach((array)($record['reviewSources']??[]) as $i=>$source)$next['tgRemittances'][$key]['reviewSources'][$i]['pakJournalIds']=array_map(static fn($key)=>$map[$key]??$key,(array)($source['pakJournalIds']??[]));
    }else{
        $next['tgRemittances'][$remitId]['date']=$date;$next['tgRemittances'][$remitId]['journalId']=$map[$id];$next['journals'][$map[$id]]['meta']['date']=$date;
        foreach((array)($next['tgBankTransactions']??[]) as $key=>$tx)if(($tx['journalId']??'')===$id){$next['tgBankTransactions'][$key]['date']=$date;$next['tgBankTransactions'][$key]['journalId']=$map[$id];}
        foreach((array)($remit['receiptIds']??[]) as $receiptId){
            if(($next['exportReceipts'][$receiptId]['tgRemittancePostId']??'')!==$id)throw new DomainException('A linked credit advice changed. Reopen the current remittance.');
            $next['exportReceipts'][$receiptId]['tgRemittancePostId']=$map[$id];$pj=$next['exportReceipts'][$receiptId]['journalId'];
            $next['journals'][$pj]['meta']['tgPostIds']=array_map(static fn($key)=>$map[$key]??$key,(array)($next['journals'][$pj]['meta']['tgPostIds']??[]));
        }
    }
    $next['workflowAudit'][]=['type'=>'LINKED_POST_DATE','action'=>'CORRECT','postId'=>$id,'replacementPostId'=>$map[$id],'entity'=>$entity,'oldDate'=>$original['date'],'date'=>$date,'reason'=>$reason,'actor'=>$actor,'at'=>$now];
    $store=$next;
    return ['originalPostId'=>$id,'replacementPostId'=>$map[$id],'reversalPostId'=>$reversals[$id],'publicPostId'=>$next['journals'][$map[$id]]['meta']['publicPostId'],'entity'=>$entity,'receiptId'=>$isReceipt?$rid:'','datesOnly'=>true];
}
