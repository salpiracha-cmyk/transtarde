<?php
require __DIR__.'/../../api/accounts_bank_payment.php';
$base=['paymentAccountId'=>'BANK-FIXTURE','date'=>'2026-10-12','bankPaymentMethod'=>'CHEQUE','chequeNo'=>'FIXTURE','chequeDate'=>'2026-10-01'];
$result=tt_accounts_bank_payment_details($base);if($result['chequeDate']!=='2026-10-01')throw new RuntimeException('The entered cheque date was overwritten.');
foreach(['2026-02-30','invalid',''] as $bad){try{tt_accounts_bank_payment_details(array_replace($base,['chequeDate'=>$bad]));throw new RuntimeException('Invalid cheque date accepted.');}catch(DomainException $e){}}
echo "PASS independently editable cheque date retained; invalid dates rejected\n";

$online=tt_accounts_bank_payment_details(['paymentAccountId'=>'BANK-FIXTURE','bankPaymentMethod'=>'ONLINE_BANKING','date'=>'2026-10-12','bankPaymentDate'=>'2026-10-01']);if($online['bankPaymentDate']!=='2026-10-01'||$online['chequeDate']!=='')throw new RuntimeException('Online date must persist independently from posting date.');
try{tt_accounts_bank_payment_details(['paymentAccountId'=>'BANK-FIXTURE','bankPaymentMethod'=>'ONLINE_BANKING','date'=>'2026-10-12','bankPaymentDate'=>'2026-02-30']);throw new RuntimeException('Invalid Online date accepted.');}catch(DomainException){}
