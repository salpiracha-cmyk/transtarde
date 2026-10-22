<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/api/tg_usd_history_core.php';
function expect(bool $ok,string $message):void { if(!$ok)throw new RuntimeException($message); }
function rejects(callable $test,string $message):void {try{$test();}catch(DomainException){return;}throw new RuntimeException($message);}
$u=['role'=>'Super Admin','id'=>9,'full_name'=>'Test Admin'];
$bank=['id'=>'banks-2','currency'=>'USD','bankName'=>'Test USD Bank','accountTitle'=>'Test TG','accountNumber'=>'TEST'];
$opening=['id'=>'OLD','entity'=>'TG','status'=>'Posted','date'=>'2026-07-01','sourceType'=>'OPENING_BALANCE_BF','lines'=>[['account'=>'1110','currency'=>'USD','bankAccountId'=>'banks-2','debit'=>367,'credit'=>0,'nativeDebit'=>100,'nativeCredit'=>0,'bankDebit'=>100,'bankCredit'=>0],['account'=>'3400','currency'=>'USD','debit'=>0,'credit'=>367,'nativeDebit'=>0,'nativeCredit'=>100]]];
$tti=['id'=>'TTI-KEEP','entity'=>'TTI','status'=>'Posted','date'=>'2026-05-01','lines'=>[['account'=>'1110','debit'=>500,'credit'=>0]]];
$aed=['id'=>'AED-KEEP','entity'=>'TG','status'=>'Posted','date'=>'2026-06-01','lines'=>[['account'=>'1110','currency'=>'AED','bankAccountId'=>'AED-BANK','bankDebit'=>500,'bankCredit'=>0]]];
$july=['id'=>'JULY-KEEP','entity'=>'TG','status'=>'Posted','date'=>'2026-07-12','lines'=>[['account'=>'1110','currency'=>'USD','bankAccountId'=>'banks-2','bankDebit'=>7,'bankCredit'=>0]]];
$s=['journals'=>['OLD'=>$opening,'TTI-KEEP'=>$tti,'AED-KEEP'=>$aed,'JULY-KEEP'=>$july]];
$p=['batch'=>'test-history-2026','reverseOpeningPostId'=>'OLD','expectedOldOpening'=>'100.00','expectedJuneClosing'=>'30.00','entries'=>[
 ['key'=>'opening-bank','kind'=>'OPENING','date'=>'2026-01-01','amount'=>'10.00','bank'=>true,'reference'=>'JAN-OPEN','narration'=>'Statement opening'],
 ['key'=>'invoice-1','kind'=>'INVOICE','date'=>'2026-05-01','amount'=>'25.00','party'=>'CUSTOMER','reference'=>'INV-1','narration'=>'Historical invoice; no stock'],
 ['key'=>'receipt-1','kind'=>'RECEIPT','date'=>'2026-06-01','amount'=>'20.00','party'=>'CUSTOMER','reference'=>'BANK-1','narration'=>'Statement receipt'],
]];
$r=tgh_prepare($s,$p,$u,$bank,3.67);
expect(count($r['journals'])===4,'Three entries and opening reversal required');
expect((float)$r['juneClosing']===30.0&&(float)$r['currentClosing']===37.0,'Original July receipt must remain in closing');
expect($r['store']['journals']['TTI-KEEP']===$tti,'TTI must be byte-for-byte unchanged');
expect($r['store']['journals']['AED-KEEP']===$aed,'AED bank must be unchanged');
expect($r['store']['journals']['JULY-KEEP']===$july,'July transaction must be unchanged');
expect($s['journals']['OLD']===$opening,'Preview must not mutate its input store');
foreach($r['journals'] as $j){expect($j['entity']==='TG','No other company may be written');foreach($j['lines'] as $l)expect(($l['currency']??'')==='USD','All created lines must retain USD');}
$bad=$p;$bad['expectedJuneClosing']='31.00';rejects(fn()=>tgh_prepare($s,$bad,$u,$bank,3.67),'An unreconciled batch must not pass');
$bad=$p;$bad['entries'][1]['date']='2026-07-02';rejects(fn()=>tgh_prepare($s,$bad,$u,$bank,3.67),'No July invoice writes');
$bad=$p;$bad['entries'][1]['kind']='INTERCOMPANY';rejects(fn()=>tgh_prepare($s,$bad,$u,$bank,3.67),'No intercompany actions');
$bad=$p;$bad['entries'][1]['amount']='25.001';rejects(fn()=>tgh_prepare($s,$bad,$u,$bank,3.67),'No silent amount rounding');
rejects(fn()=>tgh_prepare($s,$p,['role'=>'Accounts'],$bank,3.67),'Staff cannot use reconstruction');
$prior=$s;$prior['tgUsdHistoryBatches'][$p['batch']]=['hash'=>$r['hash'],'postIds'=>['A']];
expect(tgh_prepare($prior,$p,$u,$bank,3.67)['replayed']===true,'Retries must not duplicate journals');
$bad=$p;$bad['entries'][0]['amount']='11.00';rejects(fn()=>tgh_prepare($prior,$bad,$u,$bank,3.67),'Changed replay must fail');
$withBridge=$s;$withBridge['journals']['CF']=['id'=>'CF','entity'=>'TG','date'=>'2026-07-01','status'=>'Posted','sourceType'=>'CARRY_FORWARD_OPENING','lines'=>[['account'=>'1210','counterparty'=>'CUSTOMER','currency'=>'USD','nativeDebit'=>20,'nativeCredit'=>0,'debit'=>60,'credit'=>0]]];
$bridgePlan=$p;$bridgePlan['entries'][]=['key'=>'bridge-1','kind'=>'BRIDGE','date'=>'2026-07-01','amount'=>'20.00','account'=>'1210','party'=>'CUSTOMER','reference'=>'CF-BRIDGE','narration'=>'Offset existing carry-forward once','sourcePostId'=>'CF'];
$bridged=tgh_prepare($withBridge,$bridgePlan,$u,$bank,3.67);$bridgeJournal=array_values($bridged['journals'])[3];
expect($bridgeJournal['lines'][1]['credit']===60.0,'Bridge must offset original functional carrying value, not a new FX valuation');
$bridgePlan['entries'][3]['party']='DIFFERENT CUSTOMER';rejects(fn()=>tgh_prepare($withBridge,$bridgePlan,$u,$bank,3.67),'Bridge may not use another customer opening');
$shortfallStore=$s;
$shortfallStore['exportCandidates']['C1']=['entity'=>'TG','candidateType'=>'CUSTOMER_EXPORT_SALE','transactionCurrency'=>'USD','transactionAmount'=>25,'meta'=>['commercialInvoiceNo'=>'INV-1','contractRef'=>'CONTRACT-1']];
$actualReceipt=['id'=>'ACTUAL','kind'=>'Receipt','receiptType'=>'EXPORT_RECEIVABLE','status'=>'Posted','date'=>'2026-07-12','currency'=>'USD','bankAccountId'=>'banks-2','counterparty'=>'CUSTOMER','sourceCandidateId'=>'C1','settlementAmountNative'=>20,'bankCreditNative'=>20,'journalId'=>'JULY-KEEP'];
$shortfallStore['tgBankTransactions']['ACTUAL']=$actualReceipt;
$shortfallPlan=$p;$shortfallPlan['entries'][]=['key'=>'confirmed-shortfall','kind'=>'SHORTFALL','date'=>'2026-07-12','amount'=>'5.00','party'=>'CUSTOMER','reference'=>'SHORTFALL-INV-1','narration'=>'Owner-confirmed correspondent bank shortfall','sourcePostId'=>'JULY-KEEP','invoiceRef'=>'INV-1'];
$cleared=tgh_prepare($shortfallStore,$shortfallPlan,$u,$bank,3.67);
expect($cleared['currentClosing']===$r['currentClosing'],'Shortfall must not move bank money');
expect($cleared['store']['tgBankTransactions']['ACTUAL']===$actualReceipt,'Original receipt must remain unchanged');
$settled=0;foreach($cleared['store']['tgBankTransactions'] as $tx)$settled+=(float)$tx['settlementAmountNative'];expect($settled===25.0,'Shortfall must clear the linked invoice in the receipt register');
$charge=array_values($cleared['journals'])[3];expect($charge['lines'][0]['account']==='6810'&&$charge['lines'][1]['account']==='1210','Shortfall debits correspondent charges and credits receivables');
$bad=$shortfallPlan;$bad['entries'][3]['amount']='6.00';rejects(fn()=>tgh_prepare($shortfallStore,$bad,$u,$bank,3.67),'Shortfall must equal the remaining invoice balance');
$bad=$shortfallPlan;$bad['entries'][3]['date']='2026-07-13';rejects(fn()=>tgh_prepare($shortfallStore,$bad,$u,$bank,3.67),'Shortfall must retain the linked receipt date');
echo "TG USD history: isolation, exact reconciliation, retained July records and idempotency passed.\n";
