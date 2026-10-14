<?php
declare(strict_types=1);
require __DIR__.'/../../api/bank_reconciliation_core.php';
function check($condition,$message){if(!$condition)throw new RuntimeException($message);}
function entry($id,$date,$debit,$credit,$nativeDebit,$nativeCredit,$reversal=''){
 return ['id'=>$id,'status'=>'Posted','entity'=>'TTI','date'=>$date,'reversalOf'=>$reversal,'lines'=>[
 ['account'=>'1110','bankAccountId'=>'BANK','debit'=>$debit,'credit'=>$credit,'bankDebit'=>$nativeDebit,'bankCredit'=>$nativeCredit],
 ['account'=>'3100','debit'=>$credit,'credit'=>$debit]]];
}
$store=['journals'=>['OPEN'=>entry('OPEN','2026-07-01',12501923.29,0,12512650.29,0)]];
$before=$store;$pkr=br_txns($store,'TTI','BANK','2026-10-14','PKR');
check($pkr['bookBalance']===12501923.29,'PKR reconciliation must use the PKR journal legs, not foreign tracking metadata.');
$fx=br_txns($store,'TTI','BANK','2026-10-14','USD');check($fx['bookBalance']===12512650.29,'Foreign accounts must retain native amounts.');
$store['journals']['PAY']=entry('PAY','2026-08-01',0,28000,0,100);
$store['journals']['REV']=entry('REV','2026-09-01',28000,0,100,0,'PAY');
$early=br_txns($store,'TTI','BANK','2026-08-31','USD');check(count($early['rows'])===2,'An earlier statement retains the original payment.');
$late=br_txns($store,'TTI','BANK','2026-09-30','USD');check(count($late['rows'])===1,'Complete cancelled pairs do not remain in clearing choices.');
check($late['bookBalance']===$fx['bookBalance'],'Cancellation projection must preserve the bank balance.');
$store['journals']['REV']['lines'][0]['bankDebit']=99;
check(count(br_txns($store,'TTI','BANK','2026-09-30','USD')['rows'])===3,'A mismatched native reversal remains visible for review.');
$store['journals']['REV']['lines'][0]['debit']=27999;
check(count(br_txns($store,'TTI','BANK','2026-09-30','PKR')['rows'])===3,'Partial accounting reversals must not disappear.');
check($before['journals']['OPEN']===$store['journals']['OPEN'],'Read-only projection must not amend journals.');
$multi=['journals'=>['MULTI'=>['id'=>'MULTI','entity'=>'TTI','date'=>'2026-10-01','status'=>'Posted','reference'=>'PAYMENT','meta'=>['bankReference'=>'VOUCHER-REF','bankPaymentMethod'=>'CHEQUE','chequeDate'=>'2026-10-02'],'lines'=>[
 ['account'=>'1110','bankAccountId'=>'BANK','debit'=>0,'credit'=>10,'bankReference'=>'CHQ-1','chequeDate'=>'2026-10-03'],
 ['account'=>'1110','bankAccountId'=>'BANK','debit'=>0,'credit'=>20,'bankReference'=>'ONLINE-2','bankPaymentMethod'=>'ONLINE_BANKING','bankPaymentDate'=>'2026-10-04']]]]];
$original=$multi;$projection=br_txns($multi,'TTI','BANK','2026-10-15','PKR');
check(array_column($projection['rows'],'bankReference')===['CHQ-1','ONLINE-2'],'Each bank leg retains its own statement reference.');
check(array_column($projection['rows'],'key')===['MULTI|0','MULTI|1'],'Tracking display cannot change clearing keys.');
check($projection['rows'][0]['chequeDate']==='2026-10-03'&&$projection['rows'][1]['bankPaymentDate']==='2026-10-04','Line payment dates override voucher defaults.');
check($projection['bookBalance']===-30.0&&$multi===$original,'Tracking projection preserves balances and journals.');
echo "PASS reconciliation PKR/native amounts, dated cancellation projection and incomplete reversals\n";
