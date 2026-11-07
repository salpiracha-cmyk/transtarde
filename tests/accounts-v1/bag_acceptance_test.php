<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/api/bag_acceptance_core.php';
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$line=['lineKey'=>'L1','orderedQty'=>3000,'receivedQty'=>3000,'agreedTareGrams'=>75,'ratePerBag'=>1,'acceptance'=>['tareMismatch'=>true,'receivedWeightKg'=>210,'weightComplete'=>true]];
$bill=['id'=>'B3','poNo'=>'P1','lineKey'=>'L1','entity'=>'TTI','bags'=>1000,'billingBasis'=>'KG','bagWeightGrams'=>75,'totalWeightKg'=>75,'baseAmount'=>30000,'totalAmount'=>35400,'sellerInvoice'=>'LAST'];
$s=['bagPurchaseOrders'=>['P1'=>['entity'=>'TTI','lines'=>[$line]]],'bagSupplierBills'=>['B1'=>array_replace($bill,['id'=>'B1','bags'=>1000,'totalWeightKg'=>70]),'B3'=>$bill],'nonWovenBagSupplierBills'=>['B2'=>array_replace($bill,['id'=>'B2','bags'=>1000,'totalWeightKg'=>70])]];
$a=bqa_assess($s,$bill);check(count($a['issues'])===1&&str_contains($a['issues'][0],'70.000'),'Last invoice must respect remaining physical kilograms across all bills');
$corrected=array_replace($bill,['bagWeightGrams'=>70,'totalWeightKg'=>70]);check(bqa_assess($s,$corrected)['issues']===[],'Reduced supplier invoice at actual 70g needs no rate approval');
$s['bagPurchaseOrders']['P1']['lines'][0]['ratePerBag']=999;check(bqa_assess($s,$corrected)['issues']===[],'PO rate never gates posting');
$excess=array_replace($bill,['bags'=>1020,'totalWeightKg'=>76.5]);$s['bagSupplierBills']['B3']=$excess;$a=bqa_assess($s,$excess);check(count($a['issues'])===3,'Excess cumulative count, received count and kilograms are independently disclosed');
bqa_approve($s,'bagSupplierBills','B3',['id'=>5,'full_name'=>'Director'],'Supplier contacted; accepted documented excess',$a['fingerprint']);check(bqa_hold($s,$s['bagSupplierBills']['B3'])===null,'Exact Director decision permits this exception');check(count($s['bagAcceptanceAudit'])===1,'Decision audited once');
$s['bagPurchaseOrders']['P1']['lines'][0]['acceptance']['printing']=true;check(bqa_hold($s,$s['bagSupplierBills']['B3'])!==null,'A later quality change invalidates the old approval');
$s['bagSupplierBills']['B3']['journalId']='POST-1';try{bqa_approve($s,'bagSupplierBills','B3',[],'Different reason',$a['fingerprint']);throw new RuntimeException('Posted bill changed');}catch(DomainException $e){}
foreach([0=>false,1=>false,2979=>false,2980=>true,2985=>true,3000=>true,3005=>true] as $received=>$closed){$state=bqa_receipt_closure(['orderedQty'=>3000,'receivedQty'=>$received]);check($state['closed']===$closed,'Short receipt boundary '.$received);}
check(!bqa_receipt_closure(['orderedQty'=>3000,'receivedQty'=>2985,'keepReceiptOpen'=>true])['closed'],'User may retain an open short PO');
check(bqa_billed($s,'P1','L1','B3')['bags']===2000,'Multiple bills counted through both routes');$s['nonWovenBagSupplierBills']['B2']['status']='Cancelled';check(bqa_billed($s,'P1','L1','B3')['bags']===1000,'Cancelled bill releases capacity');
echo "PASS bag actual tare, corrected kilograms, no PO-rate gate, cumulative cross-route bills, exact Director approval, stale-approval protection and 20-bag closure boundaries\n";

check(!bqa_receipt_closure(['orderedQty'=>100,'receivedQty'=>85],['lines'=>[['orderedQty'=>100,'receivedQty'=>85],['orderedQty'=>100,'receivedQty'=>85]]])['closed'],'The 20-bag tolerance applies to the whole PO, not 20 per packing line');

$s2=['bagPurchaseOrders'=>['P1'=>['entity'=>'TTI','lines'=>[$line]]],'bagSupplierBills'=>[]];
check(count(bqa_assess($s2,$bill)['issues'])===1,'The first partial KG invoice also respects actual lighter tare, not just the final invoice');
$s2['bagSupplierBills']['OLD']=array_replace($bill,['id'=>'OLD','bags'=>2000,'billingBasis'=>'BAG','totalWeightKg'=>0]);
check(count(bqa_assess($s2,$bill)['issues'])===1,'Per-bag invoices reserve their share of received weight for later KG invoices');
check(bqa_assess($s2,$corrected)['issues']===[],'Mixed billing bases accept the actual remaining weight');

check(bqa_mill_line_key(['_ttBridgeId'=>'PO1|2|CONTRACT|BRAND|50|75'])==='L2','Real multi-field Mill bridge maps line two, not the tare at its end');
check(bqa_mill_line_key(['_ttBridgeId'=>'PO1|2|CONTRACT|BRAND|50|75|MASTER'])==='L2-MASTER','Master bag receipts retain their distinct packing line');
check(bqa_mill_line_key(['poLineKey'=>'L3-MASTER','_ttBridgeId'=>'legacy'])==='L3-MASTER','Explicit modern line identity wins');

$base=bqa_assess($s2,$bill)['fingerprint'];
foreach(['id','entity','supplier','sellerInvoiceDate','status','createdBy','supplierSettlement','supplierSettlementBy','supplierSettlementAt','totalWeightKg','billingRate','gstRate','gstAmount','salesTaxInvoice','stockTreatment','bagType','packingSize','brand'] as $field){
 $changed=$bill;$changed[$field]=in_array($field,['totalWeightKg','billingRate','gstRate','gstAmount'],true)?123.45:'changed';
 check(bqa_assess($s2,$changed)['fingerprint']!==$base,'Reviewed field must invalidate old decision: '.$field);
}
$metadata=$bill;foreach(['acceptanceApprovedAt','acceptanceApprovedBy','acceptanceApprovedFingerprint','acceptanceApprovalReason'] as $key)$metadata[$key]='decision metadata';
check(bqa_assess($s2,$metadata)['fingerprint']===$base,'The approval must not invalidate itself');
echo "PASS all reviewed settlement/identity/financial fields bind approval; decision metadata stays stable\n";
