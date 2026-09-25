<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/scripts/accounts_reset_core.php';
$store=['revision'=>9,'masters'=>['charges'=>[['code'=>'WHT']]],'bankAccountSettings'=>['b1'=>['defaultReceiptAccount'=>true]],
    'salaryMasters'=>['person'=>'retain'],'journals'=>['AUTO-1'=>['id'=>'AUTO-1']],
    'exportReceipts'=>['ER-1'=>['journalId'=>'AUTO-1']],
    'tgBankTransactions'=>['TGBK-1'=>['journalId'=>'AUTO-1']],
    'exportCandidates'=>['TG|1'=>['id'=>'TG|1','status'=>'Recognized / Posted','journalId'=>'AUTO-1','meta'=>['contractRef'=>'TG/1','indentorPayableJournalId'=>'AUTO-2']]]];
$new=tt_accounts_reset_store($store,'ACCOUNT-RESET-TEST');
if(isset($new['journals'],$new['exportReceipts'],$new['tgBankTransactions'])||
    ($new['masters']??null)!==$store['masters']||($new['bankAccountSettings']??null)!==$store['bankAccountSettings']||
    ($new['salaryMasters']??null)!==$store['salaryMasters']||
    ($new['exportCandidates']['TG|1']['status']??'')!=='Pending Accounting Recognition'||
    isset($new['exportCandidates']['TG|1']['journalId'],$new['exportCandidates']['TG|1']['meta']['indentorPayableJournalId'])||
    ($new['exportCandidates']['TG|1']['meta']['contractRef']??'')!=='TG/1')throw new RuntimeException('Accounts-only reset scope failed.');
$root=['contracts'=>[['id'=>'C1']],'shipments'=>[['id'=>'S1']],'fi'=>[['id'=>'FI1']],'accountsReceipts'=>[['id'=>'ER1','_accountsReceiptId'=>'ER1','source'=>'Accounts Bank Receipt'],['id'=>'OTHER','source'=>'Exports']] ];
$projection=tt_accounts_reset_export_root($root);
if($projection['contracts']!==$root['contracts']||$projection['shipments']!==$root['shipments']||$projection['fi']!==$root['fi']||$projection['accountsReceipts']!==[['id'=>'OTHER','source'=>'Exports']])throw new RuntimeException('Exports receipt-only scope failed.');
echo "Accounts reset scope fixture passed.\n";
