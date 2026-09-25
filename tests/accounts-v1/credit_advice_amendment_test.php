<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/api/accounts_receipt_amend_core.php';
function er_next_id(array $items,string $prefix):string{$n=count($items)+1;do{$id=$prefix.'-2026-'.str_pad((string)$n,6,'0',STR_PAD_LEFT);++$n;}while(isset($items[$id]));return $id;}
$store=['journals'=>[
    'AUTO-1'=>['id'=>'AUTO-1','entity'=>'TTI','date'=>'2026-09-25','status'=>'Posted','meta'=>['receiptId'=>'ER-1'],'lines'=>[['account'=>'1110','debit'=>295,'credit'=>0,'bankDebit'=>295,'bankCredit'=>0],['account'=>'2510','debit'=>0,'credit'=>300],['account'=>'6810','debit'=>5,'credit'=>0]],'totalDebit'=>300,'totalCredit'=>300],
    'AUTO-2'=>['id'=>'AUTO-2','entity'=>'TG','date'=>'2026-09-25','status'=>'Posted','meta'=>['receiptId'=>'ER-1'],'lines'=>[['account'=>'1250','debit'=>300,'credit'=>0],['account'=>'1110','debit'=>0,'credit'=>300,'bankDebit'=>0,'bankCredit'=>300]],'totalDebit'=>300,'totalCredit'=>300]],
    'exportReceipts'=>['ER-1'=>['id'=>'ER-1','entity'=>'TTI','status'=>'Accounts Approved / Posted','journalId'=>'AUTO-1','tgMirrorPostIds'=>['AUTO-2'],'allocations'=>[['targetType'=>'UNAPPLIED_TG','foreignAmount'=>300]]]],
    'tgBankTransactions'=>['TGBK-1'=>['id'=>'TGBK-1','journalId'=>'AUTO-2','amountNative'=>300]]];
$updated=er_reverse_receipt_store($store,'ER-1','TTI','2026-09-25','Wrong bank amount',['username'=>'Accountant','id'=>2]);
if(count($updated['reversalPostIds'])!==2||$updated['status']!=='Reversed for Amendment'||($store['tgBankTransactions']['TGBK-1']['status']??'')!=='Reversed for Amendment')throw new RuntimeException('Linked receipt reversal incomplete.');
foreach($updated['reversalPostIds'] as $original=>$reverse){$old=$store['journals'][$original];$new=$store['journals'][$reverse];if($new['reversalOf']!==$original||$new['entity']!==$old['entity']||abs($new['totalDebit']-$old['totalCredit'])>.001)throw new RuntimeException('Audit trail or entity was lost.');foreach($old['lines'] as $i=>$line){$corrected=$new['lines'][$i];if(abs($corrected['debit']-$line['credit'])>.001||abs($corrected['credit']-$line['debit'])>.001)throw new RuntimeException('Journal line did not reverse.');if(isset($line['bankDebit'])&&(abs($corrected['bankCredit']-$line['bankDebit'])>.001||abs($corrected['bankDebit']-$line['bankCredit'])>.001))throw new RuntimeException('Native bank balance did not reverse.');}}
try{er_reverse_receipt_store($store,'ER-1','TTI','2026-09-25','Repeat',['username'=>'Accountant']);throw new RuntimeException('Duplicate reversal was allowed.');}catch(DomainException){}
$store['exportReceipts']['ER-1']['status']='Accounts Approved / Posted';$store['exportBankShortfalls']=[['linkedReceiptId'=>'ER-1']];
try{er_reverse_receipt_store($store,'ER-1','TTI','2026-09-25','Test',['username'=>'Accountant']);throw new RuntimeException('Linked shortfall was ignored.');}catch(DomainException){}
echo "Credit advice amendment reversal fixture passed.\n";
