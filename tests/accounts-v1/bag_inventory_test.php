<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/api/bag_inventory_core.php';
function bi_check(bool $yes,string $why): void {if(!$yes)throw new RuntimeException($why);}
$masters=['mills'=>[['id'=>'mills-1','values'=>['TTI Rice Mills','','Own Mill','','','Active']],['id'=>'mills-2','values'=>['Indus Rice Mill','','External Mill','','','Active']]]];
$scope=['entity'=>'TTI','locationId'=>'mills-1','millName'=>'TTI Rice Mills'];$dest=['entity'=>'TTI','locationId'=>'mills-2','millName'=>'Indus Rice Mill'];$today='2026-10-17';
$order=['id'=>123,'entity'=>'TTI','mill'=>'TTI Rice Mills','brand'=>'ASAS','size'=>'50 kg','bagType'=>'PP','received'=>1000,'poNo'=>'PO-1','poLineKey'=>'L1'];
$receipt=['id'=>'R1','bagId'=>123,'date'=>'2026-10-01','qty'=>1000];$run=['id'=>'P1','entity'=>'TTI','millName'=>'TTI Rice Mills','date'=>'2026-10-02','rows'=>[['product'=>'Ready Rice — ASAS','bags'=>100,'bagWeight'=>50]]];$issue=['productionId'=>'P1','date'=>'2026-10-02','usage'=>['ASAS'=>100]];
$values=['tt30bags'=>json_encode([$order]),'tt33bagreceipts'=>json_encode([$receipt]),'tt30prod'=>json_encode([$run]),'tt36bagissues'=>json_encode([$issue])];$original=serialize([$values,$masters]);
$view=bti_project($values,$masters,$scope,$today,$today);$id='PO|123|mills-1';bi_check($view['lots'][$id]['availableQty']===900,'Actual original PO receipts/production did not reconcile');bi_check($view['unallocatedConsumption']===[],'Unique physical source incorrectly held');
bi_check(serialize([$values,$masters])===$original,'Inventory read rewrote source data');bi_check(bti_project($values,$masters,$scope,$today,$today)===$view,'Repeated read changed quantities/fingerprint');
// Legacy physical evidence outranks an amended PO, even when its old display name is missing.
foreach(['locationId'=>'mills-2','entity'=>'BRM','poNo'=>'ORIGINAL-PO','poLineKey'=>'L2','brand'=>'OTHER','size'=>'25 kg','bagType'=>'Jute'] as $field=>$saved){
    $conflicting=$values;$conflicting['tt33bagreceipts']=json_encode([array_replace($receipt,[$field=>$saved])]);$before=serialize($conflicting);
    $held=bti_project($conflicting,$masters,$scope,$today,$today);
    bi_check($held['lots'][$id]['availableQty']===null&&$held['lots'][$id]['committableQty']===null,'Conflicting saved receipt '.$field.' followed its amended PO');
    bi_check(serialize($conflicting)===$before,'Receipt conflict review rewrote original facts');
}
// Same brand at another packing size must retain separate actual PO balances.
$second=array_replace($order,['id'=>456,'poNo'=>'PO-2','size'=>'25 kg','received'=>500]);$values['tt30bags']=json_encode([$order,$second]);$values['tt33bagreceipts']=json_encode([$receipt,['id'=>'R2','bagId'=>456,'date'=>'2026-10-01','qty'=>500]]);
$v=bti_project($values,$masters,$scope,$today,$today);bi_check($v['lots'][$id]['availableQty']===900&&$v['lots']['PO|456|mills-1']['availableQty']===500,'Brand-only consumption deducted from both packing lines');
// Two identical eligible PO lines require actual allocation; no FIFO or double subtraction.
$second['size']='50 kg';$values['tt30bags']=json_encode([$order,$second]);$v=bti_project($values,$masters,$scope,$today,$today);
bi_check($v['lots'][$id]['availableQty']===null&&$v['lots']['PO|456|mills-1']['availableQty']===null,'Ambiguous source stock was presented as usable');bi_check(count($v['unallocatedConsumption'])===1&&$v['unallocatedConsumption'][0]['qty']===100,'Actual unallocated use was duplicated or lost');bi_check($v['lots'][$id]['issuedQty']===0&&$v['lots']['PO|456|mills-1']['issuedQty']===0,'Read invented a PO allocation');
// A later delivery must not make an older uniquely sourced run ambiguous.
$values['tt33bagreceipts']=json_encode([$receipt,['id'=>'R2','bagId'=>456,'date'=>'2026-10-03','qty'=>500]]);$v=bti_project($values,$masters,$scope,$today,$today);bi_check($v['lots'][$id]['availableQty']===900&&$v['lots']['PO|456|mills-1']['availableQty']===500,'Future receipt became the source of earlier production');
$values['tt30bags']=json_encode([$order]);$values['tt33bagreceipts']=json_encode([$receipt]);$view=bti_project($values,$masters,$scope,$today,$today);$state=[];$actor=['id'=>7,'full_name'=>'Actual sender'];$auth=static function(){};
$dispatch=['entity'=>'TTI','sourceLocationId'=>'mills-1','destinationId'=>'mills-2','date'=>$today,'sourceFingerprint'=>$view['fingerprint'],'requestKey'=>'inventory-dispatch-0001','allocations'=>[['lotId'=>$id,'qty'=>100]]];
$record=bt_dispatch($state,$view,['id'=>'mills-2','name'=>'Indus Rice Mill','status'=>'Active'],$dispatch,$actor,$today.'T10:00:00Z','BT-1',$auth)['record'];$line=$record['lines'][0]['lineId'];$targetId='BT|BT-1|'.$line;
$sourceBefore=serialize($values);$atSource=bti_project($values,$masters,$scope,$today,$today,$state);$atTarget=bti_project($values,$masters,$dest,$today,$today,$state);
bi_check($atSource['lots'][$id]['availableQty']===800,'Dispatch did not deduct source once');bi_check($atTarget['lots'][$targetId]['availableQty']===0,'Dispatch invented destination stock before receiving');
$ack=['entity'=>'TTI','transferId'=>'BT-1','date'=>$today,'requestKey'=>'inventory-receipt-0001','allocations'=>[['lineId'=>$line,'qty'=>40]]];bt_receive($state,$ack,['id'=>8,'full_name'=>'Actual receiver'],$today.'T11:00:00Z',$auth);
$atTarget=bti_project($values,$masters,$dest,$today,$today,$state);bi_check($atTarget['lots'][$targetId]['availableQty']===40&&$atTarget['lots'][$targetId]['originBagId']==='123','Partial receipt lost the original PO or created extra stock');bi_check($atTarget['lots'][$targetId]['locationId']==='mills-2','Source snapshot replaced actual destination location');
$return=['entity'=>'TTI','transferId'=>'BT-1','date'=>$today,'requestKey'=>'inventory-return-0001','reason'=>'Physically returned to original source','allocations'=>[['lineId'=>$line,'qty'=>20]]];bt_receive($state,$return,$actor,$today.'T12:00:00Z',$auth,true);
$atSource=bti_project($values,$masters,$scope,$today,$today,$state);bi_check($atSource['lots'][$id]['availableQty']===820,'Physical return failed to restore the same source lot');bi_check(serialize($values)===$sourceBefore,'Transfer/receipt projection manufactured or changed a purchase order');
// Use acknowledged stock at its actual destination; do not consume another company's PO.
$destRun=['id'=>'P2','entity'=>'TTI','millName'=>'Indus Rice Mill','date'=>$today,'rows'=>[['product'=>'Ready Rice — ASAS','bags'=>40,'bagWeight'=>50]],'bagAllocationVersion'=>1,'bagAllocations'=>[['lotId'=>$targetId,'qty'=>40]]];$values['tt30prod']=json_encode([$run,$destRun]);
$foreign=array_replace($order,['id'=>789,'entity'=>'BRM','mill'=>'Indus Rice Mill','poNo'=>'BRM-1','received'=>999]);$values['tt30bags']=json_encode([$order,$foreign]);$values['tt33bagreceipts']=json_encode([$receipt,['id'=>'RF','bagId'=>789,'date'=>'2026-10-01','qty'=>999]]);
$atTarget=bti_project($values,$masters,$dest,$today,$today,$state);bi_check($atTarget['lots'][$targetId]['availableQty']===0&&count($atTarget['lots'])===1,'Destination production mixed company/PO balances');
// Undated quantities are retained without assigning them to a historical month.
$undated=['tt30bags'=>json_encode([$order])];$v=bti_project($undated,$masters,$scope,$today,$today);bi_check($v['lots'][$id]['availableQty']===1000&&$v['lots'][$id]['undatedReceivedQty']===1000,'Undated current quantity disappeared');$v=bti_project($undated,$masters,$scope,'2026-10-01',$today);bi_check($v['lots'][$id]['availableQty']===null&&$v['lots'][$id]['receivedQty']===0,'Undated receipt assigned to guessed historical date');
// Source ownership may come from one actual issued PO, never a default company.
$legacy=$order;unset($legacy['entity'],$legacy['bagType']);$owned=['tt30bags'=>json_encode([$legacy]),'tt33bagreceipts'=>json_encode([$receipt]),'transtrade_export_v3_operational'=>json_encode(['contracts'=>[['ref'=>'C1','seller'=>'TTI']],'shipments'=>[['contractRef'=>'C1','bagOrders'=>[['poNo'=>'PO-1','supplier'=>'Supplier','lines'=>[['brand'=>'ASAS','size'=>50,'unit'=>'kg','type'=>'PP']]]]]]])];
$v=bti_project($owned,$masters,$scope,$today,$today);bi_check($v['lots'][$id]['availableQty']===1000,'Exact issued PO owner/specifications not resolved');unset($owned['transtrade_export_v3_operational']);$v=bti_project($owned,$masters,$scope,$today,$today);bi_check($v['lots']===[]&&count($v['warnings'])>0,'Unassigned ownership became TTI stock');
// An unassigned peer source prevents an invented unique historical allocation.
$unknown=array_replace($order,['id'=>456,'poNo'=>'PO-2','received'=>500]);unset($unknown['entity']);$amb=['tt30bags'=>json_encode([$order,$unknown]),'tt33bagreceipts'=>json_encode([$receipt,['id'=>'R2','bagId'=>456,'date'=>'2026-10-01','qty'=>500]]),'tt30prod'=>json_encode([$run]),'tt36bagissues'=>json_encode([$issue])];
$v=bti_project($amb,$masters,$scope,$today,$today);bi_check($v['lots'][$id]['availableQty']===null&&count($v['unallocatedConsumption'])===1,'Unassigned possible source was silently excluded from a historical allocation');
// New allocation validation uses the actual source SKU, not a client-made snapshot.
$fakeLot=$view['lots'][$id];$fakeLot['brand']='OTHER BRAND';$forged=[['lotId'=>$id,'qty'=>100,'sourceSnapshot'=>['brand'=>'ASAS','packingSize'=>'50 kg']]];
try{bti_validate_allocations($run,$forged,[$id=>$fakeLot],$masters);throw new RuntimeException('Forged source SKU snapshot accepted');}catch(DomainException){}
bi_check(bti_location($masters,'Historical old name','mills-1')['millName']==='TTI Rice Mills','Stable location identity lost after a name correction');
echo "PASS actual bag inventory projection: immutable reads, original PO/packing separation, historical allocation holds, later-receipt exclusion, source/destination partial/return conservation, acknowledged production stock, company isolation, undated history and exact PO ownership\n";
