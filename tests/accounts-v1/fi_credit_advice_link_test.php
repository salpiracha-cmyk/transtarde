<?php
declare(strict_types=1);
require __DIR__.'/../../api/fi_credit_advice_link.php';
function check(bool $value,string $message): void {if(!$value)throw new RuntimeException($message);}
$fi=['id'=>'F1','number'=>'FI-100','exporter'=>'TTI','customer'=>'TRANS GRAINS FOODSTUFF TRADING L.L.C','date'=>'2026-10-01','currency'=>'USD','value'=>100000,'allocations'=>[['amount'=>40000]]];
$receipt=['id'=>'ER1','entity'=>'TTI','remitter'=>'TG','date'=>'2026-10-03','transactionCurrency'=>'USD','foreignAmount'=>99990,'pkrBankCredit'=>27000000,'realizationRate'=>280,'bankAdviceRef'=>'ADVICE1','journalId'=>'J1','status'=>'Accounts Approved / Posted'];
$journal=['id'=>'J1','entity'=>'TTI','status'=>'Posted','meta'=>['receiptId'=>'ER1'],'narration'=>'Original narration','totalDebit'=>28000000,'totalCredit'=>28000000];
$root=['fi'=>[$fi]];$store=['revision'=>7,'exportReceipts'=>['ER1'=>$receipt],'journals'=>['J1'=>$journal,'CHG'=>array_replace($journal,['id'=>'CHG']),'TG'=>array_replace($journal,['id'=>'TG','entity'=>'TG'])]];
$links=tt_fi_advice_matches($root,$store);check($links['F1']['status']==='Matched','Two-day, ten-dollar match');check($links['F1']['amountDifference']===10.0,'Difference retained, not absorbed');
$projected=tt_fi_advice_project($store,$root);check($projected['exportReceipts']['ER1']['fiTag']['fiNumber']==='FI-100','Advice gets FI tag');
foreach(['J1','CHG','TG'] as $key)check($projected['journals'][$key]['fiTag']['receiptId']==='ER1','Main, charges and TG mirror get same tag');
check($projected['revision']===7&&$projected['journals']['J1']['narration']==='Original narration'&&$projected['journals']['J1']['totalDebit']===28000000,'Read projection never posts, alters narration or balances');
check(!isset($store['exportReceipts']['ER1']['fiTag'])&&$root['fi'][0]['allocations'][0]['amount']===40000,'Sources and FI utilisation unchanged');
foreach(['date'=>'2026-10-04','foreignAmount'=>99989.99,'entity'=>'BRM','remitter'=>'OTHER CUSTOMER','transactionCurrency'=>'AED','status'=>'Reversed for Amendment','replacementReceiptId'=>'ER2'] as $key=>$value){$other=$store;$other['exportReceipts']['ER1'][$key]=$value;check(tt_fi_advice_matches($root,$other)['F1']['status']!=='Matched','Mismatch rejected: '.$key);}
foreach(['date'=>'2026-02-30','value'=>0,'exporter'=>'','customer'=>'','cancelled'=>true] as $key=>$value){$other=$root;$other['fi'][0][$key]=$value;$result=tt_fi_advice_matches($other,$store);check(($result['F1']['status']??'')!=='Matched','Invalid FI rejected: '.$key);}
$other=$store;$other['journals']['J1']['status']='Reversed';check(tt_fi_advice_matches($root,$other)['F1']['status']!=='Matched','Reversed main journal rejected');
$other=$store;$other['exportReceipts']['ER2']=array_replace($receipt,['id'=>'ER2']);check(tt_fi_advice_matches($root,$other)['F1']['status']==='Ambiguous','Duplicate possible advices never guessed');
$other=$root;$other['fi'][]=array_replace($fi,['id'=>'F2','number'=>'FI-101']);$duplicates=tt_fi_advice_matches($other,$store);check($duplicates['F1']['status']==='Ambiguous'&&$duplicates['F2']['status']==='Ambiguous','Advice never tagged to two possible FIs');
$other=$store;$other['exportReceipts']['ER1']['date']='2026-09-29';check(tt_fi_advice_matches($root,$other)['F1']['status']==='Matched','Date tolerance works in both directions');
$other=$store;$other['exportReceipts']['ER1']['status']='Reversed for Amendment';$removed=tt_fi_advice_project($other,$root);check(!isset($removed['journals']['J1']['fiTag']),'Amendment removes old tag automatically');
$other=$root;$other['fi'][0]['value']=100020;check(!isset(tt_fi_advice_project($store,$other)['exportReceipts']['ER1']['fiTag']),'FI total amendment rechecks match');
$other=$root;$other['fi'][0]['currency']='EUR';$books=$store;$books['exportReceipts']['ER1']['transactionCurrency']='EUR';check(tt_fi_advice_matches($other,$books)['F1']['status']!=='Matched','USD tolerance never applied as EUR or PKR ten units');
$books['exportReceipts']['ER1']['foreignAmount']=100000;check(tt_fi_advice_matches($other,$books)['F1']['status']==='Matched','Other currencies match exact foreign amounts');
$other=$root;$other['fi'][0]['customer']='Amt Enterprise';$books=$store;$books['exportReceipts']['ER1']['remitter']='AMT ENTERPRISE';check(tt_fi_advice_matches($other,$books)['F1']['status']==='Matched','Direct buyer matches case-insensitively');
check(str_contains(tt_fi_advice_label($links['F1']),'01-10-2026'),'Displayed dates DD-MM-YYYY');
echo "Automatic FI/credit advice matching, ambiguity, amendment and read-only projection checks passed.\n";
