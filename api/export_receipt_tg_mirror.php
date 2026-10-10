<?php
declare(strict_types=1);
require_once __DIR__.'/tg_remittance_core.php';

/** The Pakistan receipt and TG bank payment are written under the same Accounts lock. */
function er_mirror_tg_receipt(array &$store,array $user,array $body,array $alloc,array $catalog,string $receiptId,string $pakJournalId,string $entity,string $date,string $currency,string $bankRef): array {
    if(strtoupper(trim((string)($body['remitter']??'')))!=='TG'||trim((string)($body['tgPaymentId']??''))!=='')return [];
    $tgBankId=trim((string)($body['tgBankAccountId']??''));
    if($tgBankId==='')er_respond(['ok'=>false,'error'=>'Choose the TG '.$currency.' bank account paying this credit advice.'],422);
    $bank=er_bank_master($tgBankId,'TG');
    if(!in_array(($bank['accountType']??''),['Company Account','Proprietor / Owner Account','Personal Account'],true)||($bank['currency']??'')!==$currency||strcasecmp((string)($bank['masterStatus']??'Active'),'Active')!==0||((string)$bank['accountNumber']===''&&(string)$bank['iban']===''))er_respond(['ok'=>false,'error'=>'Choose an active TG bank account in the receipt currency with an account number or IBAN.'],422);
    $native=0.0;$carrying=0.0;
    foreach((array)($store['journals']??[]) as $posted){
        if(!is_array($posted)||($posted['entity']??'')!=='TG'||($posted['status']??'')!=='Posted')continue;
        foreach((array)($posted['lines']??[]) as $line){
            if(!is_array($line)||($line['account']??'')!=='1110'||(string)($line['bankAccountId']??$posted['meta']['bankAccountId']??'')!==$tgBankId)continue;
            $native+=(float)($line['bankDebit']??0)-(float)($line['bankCredit']??0);
            $carrying+=(float)($line['debit']??0)-(float)($line['credit']??0);
        }
    }
    // A book overdraft is allowed. Preserve its valuation; use the Company FX master
    // when this bank has no usable carrying rate (including an empty book balance).
    $rate=$currency==='AED'?1:(abs($native)>.0001?$carrying/$native:0);
    if($rate<=0)$rate=(float)(tt_company_fx_rate('TG',$currency,'AED')??0);
    if($rate<=0)er_respond(['ok'=>false,'error'=>'Set the TG '.$currency.'/AED exchange rate in Super Admin → Companies → Trans Grains → Exchange rates.'],422);
    $total=round(array_sum(array_map(static fn($a)=>(float)($a['foreignAmount']??0),$alloc)),2);
    if($total<=0)er_respond(['ok'=>false,'error'=>'Enter a positive TG payment amount.'],422);
    $parts=[];$invoices=[];
    foreach($alloc as $allocation){
        $amount=round((float)($allocation['foreignAmount']??0),2);if($amount<=0)continue;
        $type=(string)($allocation['targetType']??'');
        if(!in_array($type,['INTERCOMPANY_RECEIVABLE','UNAPPLIED_TG'],true))er_respond(['ok'=>false,'error'=>'A TG credit advice must allocate to a TG invoice or advance.'],422);
        if($type==='UNAPPLIED_TG'&&trim((string)($allocation['invoiceRef']??''))!=='')er_respond(['ok'=>false,'error'=>'This saved TG Pack invoice needs its TG payable and Pakistan receivable recognized before payment. Select Advance only for a genuine advance.'],422);
        $liabilityId='';$payableRate=$rate;$target='1250';
        if($type==='INTERCOMPANY_RECEIVABLE'){
            $candidate=$store['exportCandidates'][(string)($allocation['targetId']??'')]??null;
            $liabilityId=(string)($candidate['mirrorCandidateId']??'');if(!empty($store['exportCandidates'][$liabilityId]['pendingValuation'])){require_once __DIR__.'/carry_forward_core.php';cf_value_pending_candidate($store,$user,$liabilityId,'TG',$currency,$rate);}$liability=$store['exportCandidates'][$liabilityId]??null;
            if(!is_array($liability)||($liability['entity']??'')!=='TG'||($liability['candidateType']??'')!=='TG_INTERCOMPANY_PAYABLE'||empty($liability['journalId'])||($liability['counterparty']??'')!==$entity)er_respond(['ok'=>false,'error'=>'The TG invoice payable must be posted and linked before this payment.'],422);
            $paid=0.0;foreach((array)($store['tgBankTransactions']??[]) as $prior)if(is_array($prior)&&($prior['status']??'')!=='Reversed for Amendment'&&($prior['kind']??'')==='Payment'&&(string)($prior['sourceLiabilityId']??'')===$liabilityId)$paid+=(float)($prior['amountNative']??0);foreach((array)($store['tgBankTransactions']??[]) as $prior)if(($prior['status']??'')!=='Reversed for Amendment')foreach((array)($prior['liabilityAllocations']??[]) as $la)if(($la['id']??'')===$liabilityId)$paid+=(float)$la['amountNative'];
            if($amount>(float)$liability['transactionAmount']-$paid+.0001)er_respond(['ok'=>false,'error'=>'TG payment exceeds the outstanding payable for '.(string)($allocation['invoiceRef']??'the invoice').'.'],422);
            foreach(tgr_items($store) as $draft)if(empty($draft['legacy']))foreach($draft['allocations'] as $part)if(($part['sourceLiabilityId']??'')===$liabilityId)$paid+=(float)($part['foreignAmount']??0);
            if($amount>(float)$liability['transactionAmount']-$paid+.0001)er_respond(['ok'=>false,'error'=>'TG payable is already reserved by another credit advice.'],422);
            $payableRate=(float)($liability['currentCarryingRate']??$liability['functionalRate']??0);
            if($payableRate<=0)er_respond(['ok'=>false,'error'=>'The TG invoice payable has no recorded AED carrying rate.'],422);
            $target='2500';
        }
        $parts[]=$allocation+['targetAccount'=>$target,'sourceLiabilityId'=>$liabilityId,'payableRate'=>$payableRate];
        if(!empty($allocation['invoiceRef']))$invoices[]=$allocation['invoiceRef'];
    }
    $id=tgr_id((array)($store['tgRemittanceDrafts']??[]),'TGRD');
    $draft=['id'=>$id,'status'=>'Pending','legacy'=>false,'receiptIds'=>[$receiptId],'pakJournalIds'=>[$pakJournalId],'counterparty'=>$entity,'date'=>$date,'currency'=>$currency,'bankAccountId'=>$tgBankId,'bank'=>$bank['bankName'],'amountNative'=>$total,'receivedNative'=>(float)($body['foreignAmount']??$total),'correspondentChargeNative'=>max(0,round($total-(float)($body['foreignAmount']??$total),2)),'bankAdviceRefs'=>[$bankRef],'allocations'=>$parts,'invoiceRefs'=>array_values(array_unique($invoices)),'version'=>1,'createdAt'=>gmdate('c'),'createdBy'=>(string)($user['full_name']??$user['username']??'Accounts')];
    $store['tgRemittanceDrafts'][$id]=$draft;
    $store['journals'][$pakJournalId]['meta']['tgRemittanceDraftId']=$id;
    return [['draft'=>$draft]];
}
