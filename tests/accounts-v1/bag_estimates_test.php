<?php
declare(strict_types=1);
require_once __DIR__.'/../../api/bag_estimates_core.php';
function be_check(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
$bs=['bagPurchaseOrders'=>['P1'=>['entity'=>'TTI','poNo'=>'P1','supplier'=>'Example Bags','shipmentId'=>'S1','contractRef'=>'ASAS','issuedAt'=>'2026-09-01','lines'=>[['lineKey'=>'L1','packingSize'=>'40 kg','bagType'=>'Woven','orderedQty'=>1000,'receivedQty'=>400],['lineKey'=>'L2','packingSize'=>'50 kg','bagType'=>'Woven','orderedQty'=>800,'receivedQty'=>300]]]],'journals'=>[],'bagSupplierBills'=>[]];
foreach([['OLD40','40 kg',50,100],['OLD50','50 kg',70,100],['BILLED','40 kg',50,100]] as [$id,$packing,$rate,$quantity]){
 $bs['journals']['J'.$id]=['status'=>'Posted','entity'=>'TTI','date'=>'2026-09-01'];
 $bs['bagSupplierBills'][$id]=['entity'=>'TTI','status'=>'Posted','supplier'=>'Example Bags','sellerInvoice'=>$id,'sellerInvoiceDate'=>'2026-09-01','journalId'=>'J'.$id,'packingSize'=>$packing,'bagType'=>'Woven','bags'=>$quantity,'baseAmount'=>$rate*$quantity,'totalAmount'=>$rate*$quantity*1.18,'gstRate'=>18];
}
$bs['bagSupplierBills']['BILLED']+=['poNo'=>'P1','lineKey'=>'L1'];
$bv=['tt30bags'=>[['id'=>1,'poNo'=>'P1','_ttBridge'=>'exports','_ttBridgeId'=>'contract|1'],['id'=>2,'poNo'=>'P1','_ttBridge'=>'exports','_ttBridgeId'=>'contract|2']],'tt33bagreceipts'=>[['id'=>1,'bagId'=>1,'date'=>'2026-09-15','qty'=>400],['id'=>2,'bagId'=>2,'date'=>'2026-09-15','qty'=>300],['id'=>3,'bagId'=>1,'date'=>'2026-10-15','qty'=>600]]];
$before=[$bs,$bv];$r=bag_estimates_rows($bs,'2026-09-30',$bv);$rows=$r['rows'];
be_check(count($rows)===2,'one estimate per received unbilled packing line');be_check($rows[0]['quantity']===300.&&$rows[0]['grossAmount']===17700.,'40 kg received quantity less posted bill, multiplied by comparable rate and tax');
be_check($rows[1]['quantity']===300.&&$rows[1]['grossAmount']===24780.,'50 kg uses its own comparable rate');be_check($rows[0]['whtAmount']===null&&$rows[0]['sourceKey']==='BAG|P1|L1','unknown withholding is explicit and gross not inflated');
be_check($before===[$bs,$bv],'no book or operational mutation');
be_check(bag_estimates_rows($bs,'2026-09-14',$bv)['rows']===[],'orders and future deliveries are not actual quantities');
$bs['bagSupplierBills']['BILLED']['bags']=400;be_check(count(bag_estimates_rows($bs,'2026-09-30',$bv)['rows'])===1,'actual bill automatically retires received estimate');
$bs['bagSupplierBills']['BILLED']['status']='Cancelled';be_check(bag_estimates_rows($bs,'2026-09-30',$bv)['rows'][0]['quantity']===400.,'cancelled actual bill restores estimate');
$bs['bagSupplierBills']['OLD50']['packingSize']='25 kg';$missing=bag_estimates_rows($bs,'2026-09-30',$bv)['rows'][1];be_check($missing['amount']===null&&$missing['excluded'],'missing comparable is held, never treated as zero');
$bv['tt33bagreceipts'][]=$bv['tt33bagreceipts'][0];be_check(bag_estimates_rows($bs,'2026-09-30',$bv)['rows'][0]['quantity']===400.,'duplicate receipt ID not counted twice');
$bv['tt33bagreceipts']=[];$r=bag_estimates_rows($bs,'2026-09-30',$bv);be_check(!$r['rows']&&count($r['warnings'])>0,'undated snapshot received quantity never backdated');
echo "PASS automatic bag estimates: actual dated receipts, packing-specific history, partial bills, cancellation, no guessed WHT, missing-evidence holds and immutable sources\n";
