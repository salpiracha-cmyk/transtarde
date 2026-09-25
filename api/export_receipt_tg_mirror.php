<?php
declare(strict_types=1);

/** The Pakistan receipt and TG bank payment are written under the same Accounts lock. */
function er_mirror_tg_receipt(array &$store,array $user,array $body,array $alloc,array $catalog,string $receiptId,string $pakJournalId,string $entity,string $date,string $currency,string $bankRef): array {
    if(strtoupper(trim((string)($body['remitter']??'')))!=='TG'||trim((string)($body['tgPaymentId']??''))!=='')return [];
    $tgBankId=trim((string)($body['tgBankAccountId']??''));
    if($tgBankId==='')er_respond(['ok'=>false,'error'=>'Choose the TG '.$currency.' bank account paying this credit advice.'],422);
    $bank=er_bank_master($tgBankId,'TG');
    if(($bank['accountType']??'')!=='Company Account'||($bank['currency']??'')!==$currency||strcasecmp((string)($bank['masterStatus']??'Active'),'Active')!==0||((string)$bank['accountNumber']===''&&(string)$bank['iban']===''))er_respond(['ok'=>false,'error'=>'Choose an active TG bank account in the receipt currency with an account number or IBAN.'],422);
    $native=0.0;$carrying=0.0;
    foreach((array)($store['journals']??[]) as $posted){
        if(!is_array($posted)||($posted['entity']??'')!=='TG'||($posted['status']??'')!=='Posted')continue;
        foreach((array)($posted['lines']??[]) as $line){
            if(!is_array($line)||($line['account']??'')!=='1110'||(string)($line['bankAccountId']??$posted['meta']['bankAccountId']??'')!==$tgBankId)continue;
            $native+=(float)($line['bankDebit']??0)-(float)($line['bankCredit']??0);
            $carrying+=(float)($line['debit']??0)-(float)($line['credit']??0);
        }
    }
    $rate=$currency==='AED'?1:($native>0?$carrying/$native:0);
    $total=round(array_sum(array_map(static fn($a)=>(float)($a['foreignAmount']??0),$alloc)),2);
    if($total<=0||$native+0.0001<$total||$rate<=0)er_respond(['ok'=>false,'error'=>'The TG '.$currency.' bank needs sufficient posted book balance and an AED carrying value before this linked payment can be posted.'],422);
    $out=[];$sequence=0;
    foreach($alloc as $allocation){
        $amount=round((float)($allocation['foreignAmount']??0),2);if($amount<=0)continue;
        $type=(string)($allocation['targetType']??'');
        if(!in_array($type,['INTERCOMPANY_RECEIVABLE','UNAPPLIED_TG'],true))er_respond(['ok'=>false,'error'=>'A TG credit advice must allocate to a TG invoice or advance.'],422);
        if($type==='UNAPPLIED_TG'&&trim((string)($allocation['invoiceRef']??''))!=='')er_respond(['ok'=>false,'error'=>'This saved TG Pack invoice needs its TG payable and Pakistan receivable recognized before payment. Select Advance only for a genuine advance.'],422);
        $liabilityId='';$payableRate=$rate;$target='1250';
        if($type==='INTERCOMPANY_RECEIVABLE'){
            $candidate=$store['exportCandidates'][(string)($allocation['targetId']??'')]??null;
            $liabilityId=(string)($candidate['mirrorCandidateId']??'');$liability=$store['exportCandidates'][$liabilityId]??null;
            if(!is_array($liability)||($liability['entity']??'')!=='TG'||($liability['candidateType']??'')!=='TG_INTERCOMPANY_PAYABLE'||empty($liability['journalId'])||($liability['counterparty']??'')!==$entity)er_respond(['ok'=>false,'error'=>'The TG invoice payable must be posted and linked before this payment.'],422);
            $paid=0.0;foreach((array)($store['tgBankTransactions']??[]) as $prior)if(is_array($prior)&&($prior['kind']??'')==='Payment'&&(string)($prior['sourceLiabilityId']??'')===$liabilityId)$paid+=(float)($prior['amountNative']??0);
            if($amount>(float)$liability['transactionAmount']-$paid+.0001)er_respond(['ok'=>false,'error'=>'TG payment exceeds the outstanding payable for '.(string)($allocation['invoiceRef']??'the invoice').'.'],422);
            $payableRate=(float)($liability['currentCarryingRate']??$liability['functionalRate']??0);
            if($payableRate<=0)er_respond(['ok'=>false,'error'=>'The TG invoice payable has no recorded AED carrying rate.'],422);
            $target='2500';
        }
        ++$sequence;$bankAed=round($amount*$rate,2);$targetAed=round($amount*$payableRate,2);$reference=$bankRef.'-TG-'.$sequence;
        foreach((array)($store['tgBankTransactions']??[]) as $prior)if(is_array($prior)&&(string)($prior['bankAccountId']??'')===$tgBankId&&strcasecmp((string)($prior['bankReference']??''),$reference)===0)er_respond(['ok'=>false,'error'=>'This TG bank payment reference is already posted.'],409);
        $lines=[er_line($target,$targetAed,0,$catalog,['counterparty'=>$entity,'sourceLiabilityId'=>$liabilityId,'receiptId'=>$receiptId]),er_line('1110',0,$bankAed,$catalog,['bankAccountId'=>$tgBankId,'bankName'=>$bank['bankName'],'bankAccountTitle'=>$bank['accountTitle'],'currency'=>$currency,'bankDebit'=>0,'bankCredit'=>$amount,'receiptId'=>$receiptId])];
        $difference=round($bankAed-$targetAed,2);
        if($difference>0)$lines[]=er_line('7100',$difference,0,$catalog,['fxDirection'=>'Loss','receiptId'=>$receiptId]);
        elseif($difference<0)$lines[]=er_line('7100',0,abs($difference),$catalog,['fxDirection'=>'Gain','receiptId'=>$receiptId]);
        $txId=er_next_id((array)($store['tgBankTransactions']??[]),'TGBK');
        $meta=['tgBankTransactionId'=>$txId,'receiptId'=>$receiptId,'linkedReceiptJournalId'=>$pakJournalId,'kind'=>'Payment','bankAccountId'=>$tgBankId,'bankReference'=>$reference,'counterparty'=>$entity,'currency'=>$currency,'amountNative'=>$amount,'sourceLiabilityId'=>$liabilityId,'notes'=>'Mirrored settlement for Pakistan receipt '.$receiptId];
        $journal=er_post_journal($store,$user,'TG',$date,'TG_BANK_PAYMENT',$reference,'TG payment to '.$entity.' · Pakistan receipt '.$receiptId,$lines,$meta);
        $store['tgBankTransactions'][$txId]=['id'=>$txId,'kind'=>'Payment','paymentType'=>$liabilityId!==''?'LIABILITY':'SUPPLIER_ADVANCE','date'=>$date,'bankAccountId'=>$tgBankId,'bank'=>$bank['bankName'],'currency'=>$currency,'bankReference'=>$reference,'counterparty'=>$entity,'amountNative'=>$amount,'bankDebitNative'=>$amount,'sourceLiabilityId'=>$liabilityId,'invoiceRef'=>(string)($allocation['invoiceRef']??''),'pakistanCandidateId'=>(string)($allocation['targetId']??''),'referenceType'=>'UNALLOCATED','notes'=>$meta['notes'],'journalId'=>$journal['id'],'createdAt'=>gmdate('c')];
        $out[]=['transaction'=>$store['tgBankTransactions'][$txId],'journal'=>$journal];
    }
    $store['journals'][$pakJournalId]['meta']['tgPostIds']=array_column(array_column($out,'journal'),'id');
    return $out;
}
