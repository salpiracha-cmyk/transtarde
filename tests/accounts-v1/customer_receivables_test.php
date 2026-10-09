<?php
declare(strict_types=1);
require __DIR__.'/../../api/customer_receivables_core.php';
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
function candidate(string $entity,string $invoice,float $amount,string $type='CUSTOMER_EXPORT_SALE'):array{return ['id'=>$invoice,'entity'=>$entity,'candidateType'=>$type,'transactionCurrency'=>'USD','transactionAmount'=>$amount,'journalId'=>'J-'.$invoice,'meta'=>['contractRef'=>'C-'.$entity,'commercialInvoiceNo'=>$invoice,'invoiceStage'=>'Final','customer'=>'Buyer '.$entity]];}
function lot(string $entity,string $invoice,float $amount,string $status='Final'):array{return ['id'=>'LOT-'.$invoice,'kind'=>'lot','seller'=>$entity,'contractRef'=>'C-'.$entity,'commercial'=>['saved'=>true,'status'=>$status,'invoiceNo'=>$invoice,'date'=>'2026-10-08','lastInvoiceValue'=>$amount],'tgdocs'=>['saved'=>true,'invoiceValue'=>999999],'customs'=>['saved'=>true,'invoiceValue'=>888888]];}
$root=['contracts'=>[['ref'=>'C-TG','seller'=>'TG','currency'=>'USD','customer'=>'TG Buyer'],['ref'=>'C-TTI','seller'=>'TTI','currency'=>'USD','customer'=>'TTI Buyer'],['ref'=>'C-BRM','seller'=>'BRM','currency'=>'USD','customer'=>'BRM Buyer']],'shipments'=>[lot('TG','TG-1',1000),lot('TTI','TTI-1',800),lot('TTI','TTI-2',200),lot('BRM','BRM-1',400),lot('TG','DRAFT',123,'Draft')]];
$store=['exportCandidates'=>['TG-1'=>candidate('TG','TG-1',1000),'TTI-1'=>candidate('TTI','TTI-1',700),'PACK'=>candidate('TTI','PACK',999999,'TG_PAKISTAN_INTERCOMPANY')],'exportReceipts'=>[
 ['entity'=>'TTI','status'=>'Accounts Approved / Posted','transactionCurrency'=>'USD','allocations'=>[['targetType'=>'EXPORT_RECEIVABLE','targetId'=>'TTI-1','foreignAmount'=>100]],'invoiceUtilisations'=>[['source'=>'TG PACK CUSTOMS','lotId'=>'LOT-TTI-1','currency'=>'USD','amount'=>999999]]],
 ['entity'=>'TTI','status'=>'Reversed for Amendment','transactionCurrency'=>'USD','allocations'=>[['targetType'=>'EXPORT_RECEIVABLE','targetId'=>'TTI-1','foreignAmount'=>400]]],
 ['entity'=>'BRM','status'=>'Accounts Approved / Posted','transactionCurrency'=>'USD','allocations'=>[['targetType'=>'UNAPPLIED_ADVANCE','contractRef'=>'C-BRM','foreignAmount'=>400]]],
 ['entity'=>'TTI','status'=>'Accounts Approved / Posted','transactionCurrency'=>'USD','allocations'=>[],'invoiceUtilisations'=>[['source'=>'CUSTOMS','lotId'=>'LOT-TTI-2','currency'=>'USD','amount'=>50]]],
 ],'tgBankTransactions'=>[
 ['kind'=>'Receipt','receiptType'=>'EXPORT_RECEIVABLE','sourceCandidateId'=>'TG-1','currency'=>'USD','settlementAmountNative'=>200],
 ['kind'=>'Receipt','receiptType'=>'EXPORT_RECEIVABLE','sourceCandidateId'=>'TG-1','currency'=>'USD','settlementAmountNative'=>900,'status'=>'Reversed for Amendment'],
 ['kind'=>'Receipt','receiptType'=>'CUSTOMER_ADVANCE','contractRef'=>'C-TG','currency'=>'USD','settlementAmountNative'=>9999]
 ],'tgAdvanceApplications'=>[['candidateId'=>'TG-1','amountNative'=>100]]];
$before=serialize([$store,$root]);$result=tt_customer_receivables($store,$root,['TTI','BRM','TG']);$rows=array_column($result['rows'],null,'reference');
check(count($rows)===4,'Four buyer invoices, no pack or duplicate recognition candidate');
check($rows['TG-1']['amount']===700.0,'TG receipt and applied advance counted once; reversed receipt excluded');
check($rows['TTI-1']['amount']===700.0,'Latest buyer CI overrides stale candidate value and receipt reduces it');
check($rows['TTI-2']['amount']===150.0,'Direct FI advance applies only to committed lot');
check($rows['BRM-1']['amount']===400.0,'Unapplied contract advances cannot clear an arbitrary invoice');
check(count($result['drafts'])===1,'Drafts are separate');
check(array_sum(array_column($result['rows'],'amount'))===1950.0,'Group total from customer invoices only');
check($before===serialize([$store,$root]),'Reads create no financial postings');
$limited=tt_customer_receivables($store,$root,['TG']);check(count($limited['rows'])===1&&$limited['rows'][0]['entity']==='TG','Entity permissions do not leak other books');
$store['tgBankTransactions'][]=['kind'=>'Receipt','receiptType'=>'EXPORT_RECEIVABLE','sourceCandidateId'=>'TG-1','currency'=>'USD','settlementAmountNative'=>700];check(!in_array('TG-1',array_column(tt_customer_receivables($store,$root,['TG'])['rows'],'reference'),true),'Fully paid invoices excluded');
echo "Customer receivable source, group, receipt and permission tests passed\n";
