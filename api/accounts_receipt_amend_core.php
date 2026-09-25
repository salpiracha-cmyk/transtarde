<?php
declare(strict_types=1);

/** Reverse one receipt and every journal it created, leaving the source audit intact. */
function er_reverse_receipt_store(array &$store, string $receiptId, string $entity, string $date, string $reason, array $user): array {
    $receipt=$store['exportReceipts'][$receiptId]??null;
    if(!is_array($receipt)||($receipt['entity']??'')!==$entity||($receipt['recordType']??'')==='FOREIGN_BANK_SHORTFALL_ADJUSTMENT')throw new DomainException('Posted credit advice was not found in these company books.');
    if(($receipt['status']??'')==='Reversed for Amendment')throw new DomainException('This advice is already reversed. Continue its correction from the Post ID Register.');
    if(($receipt['status']??'')!=='Accounts Approved / Posted')throw new DomainException('Only a posted credit advice can be amended.');
    foreach((array)($store['exportBankShortfalls']??[]) as $shortfall)
        if(is_array($shortfall)&&($shortfall['linkedReceiptId']??'')===$receiptId)throw new DomainException('A foreign bank shortfall is linked to this advice. Reverse that adjustment first.');
    foreach(['exportTaxCertificateMatches','retentionRemittances','salesTaxRefundReceipts'] as $collection)
        foreach((array)($store[$collection]??[]) as $linked)
            if(is_array($linked)&&in_array($receiptId,[(string)($linked['receiptId']??''),(string)($linked['linkedReceiptId']??'')],true))
                throw new DomainException('A tax or retention settlement is linked to this advice. Correct that linked record first.');
    $ids=array_values(array_filter(array_merge([(string)($receipt['journalId']??''),(string)($receipt['separateChargeJournalId']??'')],(array)($receipt['indentorPayableJournalIds']??[]),(array)($receipt['tgMirrorPostIds']??[]))));
    if(!$ids||count($ids)!==count(array_unique($ids)))throw new DomainException('Receipt journal links are incomplete; accounting review is required.');
    foreach((array)($store['journals']??[]) as $linked)
        if(is_array($linked)&&($linked['status']??'')==='Posted'&&($linked['meta']['receiptId']??'')===$receiptId&&!in_array((string)($linked['id']??''),$ids,true))
            throw new DomainException('Another posted entry depends on this advice. Correct that linked entry first.');
    foreach($ids as $id){$journal=$store['journals'][$id]??null;if(!is_array($journal)||($journal['status']??'')!=='Posted'||!in_array((string)($journal['entity']??''),[$entity,'TG'],true)||($journal['meta']['receiptId']??'')!==$receiptId)throw new DomainException('A linked posting cannot be verified; accounting review is required.');
        foreach((array)($store['journals']??[]) as $existing)if(is_array($existing)&&($existing['reversalOf']??'')===$id)throw new DomainException('A linked posting is already reversed.');}
    $reversed=[];
    foreach($ids as $id){
        $original=$store['journals'][$id];$lines=[];
        foreach((array)$original['lines'] as $originalLine){$line=$originalLine;$line['debit']=round((float)($originalLine['credit']??0),2);$line['credit']=round((float)($originalLine['debit']??0),2);
            if(array_key_exists('bankDebit',$line)||array_key_exists('bankCredit',$line)){$line['bankDebit']=round((float)($originalLine['bankCredit']??0),2);$line['bankCredit']=round((float)($originalLine['bankDebit']??0),2);} $lines[]=$line;}
        $reverseId=er_next_id((array)$store['journals'],'RV');
        $store['journals'][$reverseId]=['id'=>$reverseId,'entity'=>$original['entity'],'date'=>$date,'sourceType'=>'RECEIPT_AMENDMENT_REVERSAL','reference'=>$id,'narration'=>'Amendment reversal of '.$id.' — '.$reason,'lines'=>$lines,'totalDebit'=>round((float)$original['totalCredit'],2),'totalCredit'=>round((float)$original['totalDebit'],2),'status'=>'Posted','meta'=>['receiptId'=>$receiptId,'reason'=>$reason,'originalPostId'=>$id],'createdAt'=>gmdate('c'),'createdBy'=>(string)($user['full_name']??$user['username']??'Accounts'),'userId'=>(int)($user['id']??0),'reversalOf'=>$id];
        $reversed[$id]=$reverseId;
    }
    foreach((array)($store['tgBankTransactions']??[]) as &$tx)if(is_array($tx)&&in_array((string)($tx['journalId']??''),$ids,true)){$tx['status']='Reversed for Amendment';$tx['reversalJournalId']=$reversed[$tx['journalId']];}unset($tx);
    foreach((array)($store['exportCandidates']??[]) as &$candidate){if(!is_array($candidate)||!is_array($candidate['meta']??null))continue;$meta=&$candidate['meta'];if(in_array((string)($meta['indentorPayableJournalId']??''),(array)($receipt['indentorPayableJournalIds']??[]),true)&&($meta['indentorFirmReceiptId']??'')===$receiptId){foreach(['indentorPayableJournalId','indentorFirmNativeAmount','indentorFirmPkrAmount','indentorCreditAdviceRate','indentorFirmReceiptId'] as $key)unset($meta[$key]);$meta['indentorPayableStatus']='Estimated — awaiting full invoice payment';}unset($meta);}unset($candidate);
    $store['exportReceipts'][$receiptId]['status']='Reversed for Amendment';$store['exportReceipts'][$receiptId]['reversalPostIds']=$reversed;$store['exportReceipts'][$receiptId]['amendmentReason']=$reason;$store['exportReceipts'][$receiptId]['reversedAt']=gmdate('c');$store['exportReceipts'][$receiptId]['reversedBy']=(string)($user['full_name']??$user['username']??'Accounts');
    return $store['exportReceipts'][$receiptId];
}
