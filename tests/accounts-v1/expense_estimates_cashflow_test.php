<?php
declare(strict_types=1);
require_once __DIR__.'/../../api/expense_estimates_core.php';
function ee_check(bool $condition,string $message):void {if(!$condition)throw new RuntimeException($message);}
$ee=['id'=>'EST1','entity'=>'TTI','supplier'=>'Example Bags','reference'=>'SHIP-1','purpose'=>'40 kg bags','date'=>'2026-10-01','currency'=>'PKR','grossAmount'=>59000.,'whtAmount'=>2950.,'supplierNet'=>56050.,'status'=>'Open','version'=>1,'sourceKey'=>'BAG|PO1|L1','shipmentId'=>'SHIP1','expenseKind'=>'BAGS'];
$ees=['estimatedExpenseBills'=>['EST1'=>$ee],'journals'=>['JB'=>['id'=>'JB','entity'=>'TTI','status'=>'Posted','date'=>'2026-10-15','lines'=>[['account'=>'2140','credit'=>60000,'debit'=>0]]]],'bagSupplierBills'=>['B1'=>['entity'=>'TTI','status'=>'Posted','journalId'=>'JB','supplier'=>'Example Bags','sellerInvoice'=>'ACTUAL1','sellerInvoiceDate'=>'2026-10-15','totalAmount'=>60000,'bags'=>1000,'baseAmount'=>60000,'gstRate'=>0]]];
$empty=['rows'=>[],'warnings'=>[]];$before=$ees;
ee_check(ee_cashflow($empty,$ees,'2026-09-30')['rows']===[],'future estimate excluded');
$forecast=ee_cashflow($empty,$ees,'2026-10-01')['rows'];ee_check(count($forecast)===1&&$forecast[0]['amount']===-59000.,'gross includes withholding exactly once');
ee_check($forecast[0]['sourceKey']==='BAG|PO1|L1'&&$forecast[0]['shipmentId']==='SHIP1','explicit source link reaches projection');
$ees['estimatedExpenseBills']['EST1']+=['actualBillKey'=>'bagSupplierBills|B1','actualBillDate'=>'2026-10-15'];$ees['estimatedExpenseBills']['EST1']['status']='Replaced';
ee_check(count(ee_cashflow($empty,$ees,'2026-10-14')['rows'])===1,'forecast remains before actual bill date');
ee_check(ee_cashflow($empty,$ees,'2026-10-15')['rows']===[],'actual supplier balance replaces estimate');
$ees['bagSupplierBills']['B1']['status']='Cancelled';$r=ee_cashflow($empty,$ees,'2026-10-16');ee_check(count($r['rows'])===1&&count($r['warnings'])===1,'cancelled replacement restores a visible forecast with review warning');
$ees['bagSupplierBills']['B1']['status']='Posted';$ees['bagSupplierBills']['B1']['totalAmount']=61000;
ee_check(ee_payload($ees,'TTI')['estimatedBills'][0]['variance']===2000.,'amended real bill refreshes variance without modifying audit');
$ees['journals']['JB']['meta']['deletedFromBooks']=true;
ee_check(count(ee_cashflow($empty,$ees,'2026-10-16')['rows'])===1,'deleted journal cannot retire a forecast');
$ees['journals']['JB']['meta']['deletedFromBooks']=false;$ees['journals']['JB']['reversedByPostId']='JR';ee_check(count(ee_cashflow($empty,$ees,'2026-10-16')['rows'])===1,'reversed original cannot retire forecast');
$native=['rows'=>[['id'=>'NATIVE','postIds'=>['NATIVE-JOURNAL'],'account'=>'2210','party'=>'Example Bags','entity'=>'TTI','currency'=>'PKR','amount'=>-59000.,'excluded'=>false]],'warnings'=>[]];
$r=ee_cashflow($native,$before,'2026-10-01');ee_check(count($r['rows'])===2&&$r['rows'][1]['excluded']&&count($r['warnings'])===1,'possible native accrual overlap held for review, never hidden double count');
$automatic=$native;$automatic['rows'][0]['sourceKind']='BAGS';ee_check(!ee_cashflow($automatic,$before,'2026-10-01')['rows'][1]['excluded'],'automatic forecasts do not trigger native accrual hold');
$native['rows'][0]['currency']='USD';ee_check(!ee_cashflow($native,$before,'2026-10-01')['rows'][1]['excluded'],'different currencies cannot offset forecasts');
$cancelled=$before;$cancelled['estimatedExpenseBills']['EST1']['status']='Cancelled';$cancelled['estimatedExpenseBills']['EST1']['cancelDate']='2026-10-10';
ee_check(count(ee_cashflow($empty,$cancelled,'2026-10-09')['rows'])===1&&ee_cashflow($empty,$cancelled,'2026-10-10')['rows']===[],'dated cancellation retains historical forecast');
ee_check($before['journals']===['JB'=>['id'=>'JB','entity'=>'TTI','status'=>'Posted','date'=>'2026-10-15','lines'=>[['account'=>'2140','credit'=>60000,'debit'=>0]]]],'cashflow reads never alter source journals');
echo "PASS estimated bill cashflow: cutoff, gross/WHT, live replacement, cancellation, amended variance and native overlap holds\n";
