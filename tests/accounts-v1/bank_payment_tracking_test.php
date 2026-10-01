<?php
declare(strict_types=1);
require __DIR__.'/../../api/accounts_bank_payment.php';
require __DIR__.'/../../api/accounts_reference.php';
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$input=['paymentAccountId'=>'bank-a','date'=>'2026-10-01','bankPaymentMethod'=>'CHEQUE','chequeNo'=>'123456','chequeDate'=>'2026-10-01','paymentNarration'=>'Supplier settlement'];
$tracking=tt_accounts_bank_payment_details($input);
$lines=[['account'=>'6900','debit'=>100,'credit'=>0],['account'=>'1110','debit'=>0,'credit'=>100,'bankAccountId'=>'bank-a']];
$old=['reference'=>'EXTERNAL-INVOICE-1','narration'=>'Original expense','meta'=>[],'lines'=>$lines];
$store=['journals'=>['OLD'=>$old,'NEW'=>$old,'CASH'=>['reference'=>'CASH-1','narration'=>'Cash expense','meta'=>[],'lines'=>[['account'=>'1120','credit'=>20]]]]];
tt_accounts_track_bank_payment($store,['OLD'],$tracking);
check($store['journals']['OLD']===$old,'Never modify existing records.');
check($store['journals']['NEW']['lines']===$lines,'Payment tracking cannot change accounting amounts.');
check($store['journals']['NEW']['reference']==='EXTERNAL-INVOICE-1','Keep invoice reference separate from cheque number.');
check($store['journals']['NEW']['meta']['chequeNo']==='123456','Cheque tracking missing.');
check(str_contains($store['journals']['NEW']['narration'],'Supplier settlement'),'Custom narration missing.');
check($store['journals']['CASH']['meta']===[],'Do not tag cash as cheque.');
foreach([['chequeNo'=>''],['chequeDate'=>'2026-10-02'],['paymentAccountId'=>'CASH|TTI'],['bankPaymentMethod'=>'INVALID']] as $bad){try{tt_accounts_bank_payment_details(array_replace($input,$bad));throw new RuntimeException('Invalid bank details were accepted.');}catch(DomainException){}}
$online=tt_accounts_bank_payment_details(array_replace($input,['bankPaymentMethod'=>'ONLINE_BANKING','bankReference'=>'ONLINE-1']));check($online['chequeNo']===''&&$online['bankReference']==='ONLINE-1','Online reference must not become cheque number.');
echo "Bank tracking, separate invoice reference, narration and unchanged accounting amounts passed\n";

foreach(['9','000009','2026-9','202600009','20269'] as $query)check(tt_accounts_reference_matches($query,'AUTO-2026-000009'),'Short or joined reference search failed.');
check(!tt_accounts_reference_matches('10','AUTO-2026-000009'),'Wrong reference suffix matched.');

$optional=tt_accounts_bank_payment_details(['paymentAccountId'=>'bank-a','date'=>'2026-10-01','bankPaymentMethod'=>'ONLINE_BANKING']);check($optional['bankReference']==='','Online reference must be optional.');
$legacy=tt_accounts_bank_payment_details(['paymentAccountId'=>'bank-a','date'=>'2026-10-01','bankPaymentMethod'=>'BANK_TRANSFER']);check($legacy['bankPaymentMethod']==='ONLINE_BANKING','Legacy method must normalize to online banking.');
try{tt_accounts_bank_payment_details(['paymentAccountId'=>'bank-a','date'=>'2026-10-01']);throw new RuntimeException('Default cheque accepted without number.');}catch(DomainException){}
