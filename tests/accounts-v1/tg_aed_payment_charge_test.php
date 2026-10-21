<?php
require dirname(__DIR__,2).'/api/accounts_bank_payment.php';
function tt_company_vat_rate($entity,$default=5){return 5;}
function check($condition,$message){if(!$condition)throw new RuntimeException($message);}
$original=['id'=>'PRINCIPAL','entity'=>'TG','status'=>'Posted','narration'=>'Principal payment','lines'=>[['account'=>'6900','debit'=>100,'credit'=>0,'currency'=>'AED'],['account'=>'1110','bankAccountId'=>'TG-AED','currency'=>'AED','debit'=>0,'credit'=>100,'bankDebit'=>0,'bankCredit'=>100]],'meta'=>[],'totalDebit'=>100,'totalCredit'=>100];
$store=['journals'=>['PRINCIPAL'=>$original],'accountSubaccounts'=>['WEB'=>['id'=>'WEB','entity'=>'TG','parentCode'=>'6800','name'=>'WEB BANKING CHARGES','active'=>true]]];
$tracking=tt_accounts_bank_payment_details(['paymentAccountId'=>'TG-AED','bankPaymentMethod'=>'ONLINE_BANKING','date'=>'2026-10-03','addBankCharge'=>true,'extraBankChargeNative'=>30,'bankVatPct'=>99]);
tt_accounts_track_bank_payment($store,[],$tracking);$j=$store['journals']['PRINCIPAL'];
check($j['lines'][0]===$original['lines'][0]&&$j['lines'][1]===$original['lines'][1],'Never alter the principal payment');
check($j['totalDebit']===131.5&&$j['totalCredit']===131.5,'Fee plus VAT must balance');
check($j['lines'][2]['subaccountId']==='WEB'&&$j['lines'][3]['vatNative']===1.5,'Configured charge subledger and master VAT');
$bank=array_values(array_filter($j['lines'],fn($line)=>$line['account']==='1110'));check(array_column($bank,'bankCredit')===[100,30.0,1.5],'Principal, fee, and VAT must be distinct statement debits');
foreach(['USD','PKR'] as $ccy){$copy=$store;$bad=$original;$bad['lines'][1]['currency']=$ccy;$copy['journals']=['BAD'=>$bad];try{tt_accounts_track_bank_payment($copy,[],$tracking);throw new RuntimeException('Wrong currency accepted');}catch(DomainException $expected){}}
$copy=['journals'=>['REV'=>$original+['reversalOf'=>'OLD']]];try{tt_accounts_track_bank_payment($copy,[],$tracking);throw new RuntimeException('Reversal charged');}catch(DomainException $expected){}
try{tt_accounts_apply_aed_bank_charge($store,$store['journals']['PRINCIPAL'],$tracking);throw new RuntimeException('Duplicate charge');}catch(DomainException $expected){}
echo "PASS optional AED fee: principal unchanged, configured subledger, server VAT, separate statement lines, wrong currencies/reversals/double fees rejected\n";
