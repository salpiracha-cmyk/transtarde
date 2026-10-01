<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/api/accounts_reviews_core.php';
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$s=['purchaseSodas'=>['S1'=>['id'=>'S1','entity'=>'TTI','sodaNo'=>'R26-001','commodity'=>'RICE','productStage'=>'READY','qtyFromKg'=>100000,'qtyToKg'=>100000,'arrivalDueDate'=>'2000-01-01']],
'events'=>['E1'=>['entity'=>'TTI','eventType'=>'COMMODITY_RECEIPT_ACCEPTED','journalId'=>'J1']],
'journals'=>['J1'=>['entity'=>'TTI','status'=>'Posted','meta'=>['soda'=>'R26-001','payableWeightKg'=>95000]]],
'payableHolds'=>['H1'=>['entity'=>'TTI','active'=>true,'sourceKey'=>'ARR1','sodaNo'=>'R26-001','reason'=>'Late arrival']],
'localSalesCandidates'=>['LS1'=>['entity'=>'TTI','status'=>'Pending Accounts Approval','soda'=>'LOCAL-1','amount'=>500]],
'localSalesPaymentCandidates'=>['LP1'=>['entity'=>'TTI','status'=>'Pending Accounts Approval','soda'=>'LOCAL-1','amount'=>250,'paymentType'=>'Cash','party'=>'Customer']],
'freightBillsV1'=>['F1'=>['entity'=>'TG','disputedTotal'=>500,'invoiceNo'=>'TG1']]];
$items=ar_items($s,'TTI');check(count($items)===3,'Ready rice at minus 5% must not have an overdue balance; include hold, sale and payment only.');check(count(ar_items($s,'TG'))===1,'Company queue separation');
$first=$items[0];$s['accountsReviewDismissals'][$first['id']]=['fingerprint'=>$first['fingerprint']];check(count(ar_items($s,'TTI'))===2,'Discard stays hidden on reload');check($s['payableHolds']['H1']['active']===true,'Discard cannot release hold');check($s['journals']['J1']['status']==='Posted','Discard cannot remove journal');
$s['payableHolds']['H1']['reason']='New missing document';check(count(ar_items($s,'TTI'))===3,'Changed reason must reappear');
$s['journals']['J1']['meta']['payableWeightKg']=94999;check(count(ar_items($s,'TTI'))===4,'Ready rice below minus 5% must need review');
$s['purchaseSodas']['S1']['manualStatus']='Short Closed';check(count(ar_items($s,'TTI'))===3,'Closed Soda should not reappear');
$s['purchaseSodas']['N1']=['id'=>'N1','entity'=>'TTI','sodaNo'=>'26001','commodity'=>'RICE','productStage'=>'READY','qtyFromKg'=>100000,'qtyToKg'=>100000,'arrivalDueDate'=>'2000-01-01'];
$numeric=ar_items($s,'TTI');check(count($numeric)===4,'Numeric Soda numbers must remain string references and not crash the dashboard');$numbered=array_values(array_filter($numeric,static fn($item)=>$item['reference']==='26001'));check(count($numbered)===1&&$numbered[0]['target']['sodaNo']==='26001','Numeric Soda references retain their exact text');
$s['events']['N1-EVENT']=['entity'=>'TTI','eventType'=>'COMMODITY_RECEIPT_ACCEPTED','journalId'=>'N1-JOURNAL'];$s['journals']['N1-JOURNAL']=['date'=>'1999-12-31','status'=>'Posted','meta'=>['soda'=>'26001','payableWeightKg'=>95000]];check(count(ar_items($s,'TTI'))===3,'Numeric Soda receipt matching must recognize the minus 5% completion threshold');
$auth=file_get_contents(dirname(__DIR__,2).'/auth_store.php');eval(substr($auth,strpos($auth,'function tt_accounts_uppercase_text(')));
$input=['reference'=>'ft/26265/vxwtl','reason'=>'corrected reference','lines'=>[['description'=>'extra charge','chequeNo'=>'ab123']],'csrf'=>'aBc','action'=>'post_receipt','bankAccountId'=>'bank-AbC','status'=>'Posted','email'=>'user@example.com'];$out=tt_accounts_uppercase_text($input);
check($out['reference']==='FT/26265/VXWTL'&&$out['lines'][0]['description']==='EXTRA CHARGE'&&$out['lines'][0]['chequeNo']==='AB123','Uppercase nested accounting free text');foreach(['csrf','action','bankAccountId','status','email']as$key)check($out[$key]===$input[$key],'Identity and enum preservation: '.$key);
echo "Review dismissal, source preservation, company isolation, Ready tolerance and uppercase identity tests passed.\n";
