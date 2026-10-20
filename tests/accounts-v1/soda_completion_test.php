<?php
declare(strict_types=1);
// Execute the actual read projection, isolated from login/endpoint dispatch.
$code=file_get_contents(__DIR__.'/../../api/purchase_sodas.php');
$a=strpos($code,'function ps_paid_for_bill(');$b=strpos($code,'function ps_validate(',$a);
eval(substr($code,$a,$b-$a));
define('PS_TRUCK_DOUBLE_KG',35000);define('PS_MAX_OVER_KG',950);
function check_soda(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);}
$soda=['entity'=>'TTI','sodaNo'=>'26002','sodaDate'=>'2026-09-11','commodity'=>'RICE','productStage'=>'READY','qtyFromKg'=>81000,'qtyToKg'=>81000,'expectedTrucks'=>3,'readyRoute'=>'EX_MILL','status'=>'Open'];
$s=['purchaseSodas'=>['P1'=>$soda],'commodityBills'=>['B1'=>['entity'=>'TTI','sodas'=>['26002'],'commodity'=>'RICE','status'=>'Verified / Posted','supplierPayableTotal'=>9000]],'supplierSettlements'=>['S1'=>['entity'=>'TTI','status'=>'Posted','allocations'=>[['billId'=>'B1','amount'=>9000,'paidAmount'=>8990,'discountAmount'=>10]]]]];
for($i=1;$i<=3;$i++){$source='EXMILL|P1|L'.$i;$s['purchaseSodaLiftings']['L'.$i]=['entity'=>'TTI','sourceSodaId'=>'P1','soda'=>'26002','liftingId'=>'L'.$i,'kg'=>27000,'date'=>'2026-09-22'];$s['events']['E'.$i]=['entity'=>'TTI','sourceKey'=>$source,'status'=>'Accepted / Liability Posted','billId'=>'B1'];}
$before=serialize($s);$r=ps_rows($s,'TTI')[0];check_soda($r['calculatedStatus']==='Completed'&&$r['payableOutstanding']===0.&&$r['completionBlockers']===[],'Fully delivered, posted and settled closes, including recorded discount');check_soda(serialize($s)===$before,'Read changed source');
$t=$s;$t['supplierSettlements']['S1']['allocations'][0]['amount']=8999;$r=ps_rows($t,'TTI')[0];check_soda($r['calculatedStatus']==='Payment Pending'&&$r['payableOutstanding']===1.&&count($r['completionBlockers'])===1,'One rupee outstanding stays open with blocker');
foreach(['Reversed','Pending Approval'] as $status){$t=$s;$t['supplierSettlements']['S1']['status']=$status;check_soda(ps_rows($t,'TTI')[0]['calculatedStatus']==='Payment Pending','Unposted/reversed payment closed SODA');}
$t=$s;$t['supplierSettlements']['S1']['entity']='BRM';check_soda(ps_rows($t,'TTI')[0]['paidAmount']===0.,'Foreign company payment counted');
$t=$s;$t['events']['E3']['billId']='';$r=ps_rows($t,'TTI')[0];check_soda($r['calculatedStatus']==='Received / Bill Pending'&&$r['unbilledArrivals']===1,'Partly billed Ex-Mill incorrectly closes');
$t=$s;$t['commodityBills']['B1']['status']='Reversed';check_soda(ps_rows($t,'TTI')[0]['calculatedStatus']==='Received / Bill Pending','Reversed bill counted');
$t=$s;$t['purchaseSodaLiftings']['L3']['reversed']=true;check_soda(ps_rows($t,'TTI')[0]['calculatedStatus']==='Part Received','Reversed lifting counted');
$t=$s;$t['purchaseSodaLiftings']['L3']['kg']=33000;check_soda(ps_rows($t,'TTI')[0]['calculatedStatus']==='Over Maximum — Review','Over maximum closes');
$t=$s;$t['commodityBills']['B2']=$t['commodityBills']['B1'];$t['commodityBills']['B2']['supplierPayableTotal']=10;$t['supplierSettlements']['S1']['allocations'][0]['amount']=9010;check_soda(ps_rows($t,'TTI')[0]['payableOutstanding']===10.,'Overpaid bill masks another unpaid bill');
$t=$s;$t['purchaseSodas']['P1']['readyRoute']='DELIVER_TO_STOCK';$t['purchaseSodaLiftings']=[];
for($i=1;$i<=3;$i++){$t['events']['E'.$i]=['entity'=>'TTI','eventType'=>'COMMODITY_RECEIPT_ACCEPTED','journalId'=>'J'.$i,'billId'=>'B1'];$t['journals']['J'.$i]=['entity'=>'TTI','status'=>'Posted','meta'=>['soda'=>'26002','commodity'=>'RICE','payableWeightKg'=>27000]];}
check_soda(ps_rows($t,'TTI')[0]['calculatedStatus']==='Completed','Delivered-to-stock completion failed');$t['events']['E3']['status']='Reversed';check_soda(ps_rows($t,'TTI')[0]['calculatedStatus']==='Part Received','Reversed receipt counted');
foreach(['Completed','Short Closed','Cancelled'] as $status){$t=$s;$t['purchaseSodas']['P1']['status']=$status;check_soda(ps_rows($t,'TTI')[0]['calculatedStatus']===$status,'Explicit historical closure changed');}
check_soda(ps_rows($s,'BRM')===[],'Company scope leaked');
echo "PASS SODA completion: committed receipts, individual bills, settled allocations, reversals, company scope, blockers, historical statuses and no writes\n";
