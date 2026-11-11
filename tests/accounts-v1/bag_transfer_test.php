<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/api/bag_transfer_core.php';
function bt_check(bool $yes,string $why): void {if(!$yes)throw new RuntimeException($why);}
function bt_reject(callable $f,string $why): void {try{$f();}catch(DomainException){return;}throw new RuntimeException($why);}
$now='2026-10-17T13:00:00Z';$sender=['id'=>11,'full_name'=>'Actual sender'];$receiver=['id'=>33,'full_name'=>'Actual receiver'];
$source=['entity'=>'TTI','locationId'=>'mills-1','millName'=>'TTI Rice Mills'];$destination=['id'=>'mills-2','name'=>'Indus Rice Mill','status'=>'Active'];
$lot=['entity'=>'TTI','locationId'=>'mills-1','millName'=>'TTI Rice Mills','originBagId'=>'123','poNo'=>'PO-1','poLineKey'=>'L1','brand'=>'ASAS','packingSize'=>'50 kg','bagType'=>'PP','skuKey'=>'ASAS|50kg|PP','availableQty'=>100,'hold'=>''];
$inventory=['scope'=>$source,'asOfDate'=>'2026-10-17','fingerprint'=>bt_hash(['actual inventory snapshot']),'lots'=>['PO|123'=>$lot]];
$state=['transfers'=>[],'requests'=>[],'audit'=>[],'purchaseOrders'=>['PO-1'=>['received'=>100]],'journals'=>['KEEP'=>['amount'=>1000]]];$original=serialize([$inventory,$state['purchaseOrders'],$state['journals']]);
$body=['entity'=>'TTI','sourceLocationId'=>'mills-1','destinationId'=>'mills-2','date'=>'2026-10-17','sourceFingerprint'=>$inventory['fingerprint'],'allocations'=>[['lotId'=>'PO|123','qty'=>100]],'requestKey'=>'dispatch-original-0001'];
$authorize=static function($entity,$mill,$action){if($entity!=='TTI'||$action!=='Create'||!in_array($mill,['TTI Rice Mills','Indus Rice Mill'],true))throw new DomainException('Scope denied');};
$out=bt_dispatch($state,$inventory,$destination,$body,$sender,$now,'BT-1',$authorize);$dispatch=$out['record'];$line=$dispatch['lines'][0]['lineId'];
bt_check($out['status']==='In transit'&&$dispatch['lines'][0]['originBagId']==='123','Dispatch lost its actual source PO');bt_check($dispatch['receipts']===[],'Dispatch invented a destination receipt');
bt_check(serialize([$inventory,$state['purchaseOrders'],$state['journals']])===$original,'Physical transfer changed PO or financial books');
$saved=serialize($state);$emptyStock=$inventory;$emptyStock['lots']['PO|123']['availableQty']=0;
$out=bt_dispatch($state,$emptyStock,$destination,$body,$sender,$now,'DIFFERENT-RETRY-ID',$authorize);bt_check($out['replayed']&&serialize($state)===$saved,'Lost dispatch acknowledgement duplicated stock movement');
bt_reject(function()use(&$state,$inventory,$destination,$body,$sender,$now,$authorize){$b=$body;$b['allocations'][0]['qty']=20;bt_dispatch($state,$inventory,$destination,$b,$sender,$now,'BT-2',$authorize);},'Same request identity accepted different quantity');
$receipt=['entity'=>'TTI','transferId'=>'BT-1','date'=>'2026-10-17','allocations'=>[['lineId'=>$line,'qty'=>70]],'requestKey'=>'receipt-partial-0001'];
$out=bt_receive($state,$receipt,$receiver,$now,$authorize);bt_check(bt_totals($out['record'])[$line]['inTransit']===30,'Partial receipt closed the remaining quantity');bt_check($out['record']['receipts'][0]['userId']===33,'Receiver actor was replaced by sender');
$paid=serialize($state);$over=$receipt;$over['requestKey']='receipt-overflow-0001';$over['allocations'][0]['qty']=31;bt_reject(fn()=>bt_receive($state,$over,$receiver,$now,$authorize),'Receipt exceeded remaining transit');bt_check(serialize($state)===$paid,'Refused receipt changed state');
$denied=static function(){throw new DomainException('Assigned destination denied');};$wrong=$receipt;$wrong['requestKey']='receipt-other-mill-0001';bt_reject(fn()=>bt_receive($state,$wrong,$receiver,$now,$denied),'Location restriction bypassed');
$return=['entity'=>'TTI','transferId'=>'BT-1','date'=>'2026-10-17','reason'=>'Unloaded back at source after partial delivery','allocations'=>[['lineId'=>$line,'qty'=>20]],'requestKey'=>'physical-return-0001'];
$out=bt_receive($state,$return,$sender,$now,$authorize,true);$totals=bt_totals($out['record']);bt_check($totals[$line]===['dispatched'=>100,'received'=>70,'returned'=>20,'inTransit'=>10,'quarantined'=>0],'Physical return did not preserve actual quantities');
$last=$receipt;$last['requestKey']='receipt-final-00001';$last['allocations'][0]['qty']=10;$out=bt_receive($state,$last,$receiver,$now,$authorize);bt_check($out['status']==='Closed — received and returned','Receipt/return result was mislabeled fully delivered');
$finished=serialize($state);$out=bt_receive($state,$receipt,$receiver,$now,$authorize);bt_check($out['replayed']&&serialize($state)===$finished,'Receipt replay duplicated destination stock');
bt_check($state['transfers']['BT-1']['lines']===$dispatch['lines'],'Receipt rewrote original dispatch');bt_check(serialize([$inventory,$state['purchaseOrders'],$state['journals']])===$original,'Later acknowledgement manufactured PO or money');
foreach([0,-1,1.5,'1.5',INF,true,'1e3'] as $qty)bt_reject(fn()=>bt_qty($qty),'Invalid whole-bag quantity accepted');
bt_check(bt_date('2026-10-18','2026-10-17T20:00:00Z')==='2026-10-18','Movement date ignored Karachi day');bt_reject(fn()=>bt_date('2026-10-18',$now),'Future physical movement accepted');bt_reject(fn()=>bt_date('2026-02-30',$now),'Invalid calendar date accepted');
$state2=['transfers'=>[]];$b=$body;$b['requestKey']='dispatch-new-stock-0001';$low=$inventory;$low['lots']['PO|123']['availableQty']=-2;bt_reject(fn()=>bt_dispatch($state2,$low,$destination,$b,$sender,$now,'BT-2',$authorize),'Negative source balance hidden');
$stale=$body;$stale['requestKey']='dispatch-stale-0001';$stale['sourceFingerprint']='stale';bt_reject(fn()=>bt_dispatch($state2,$inventory,$destination,$stale,$sender,$now,'BT-2',$authorize),'Unreviewed stock dispatched');
$foreign=$inventory;$foreign['lots']['PO|123']['entity']='BRM';bt_reject(fn()=>bt_dispatch($state2,$foreign,$destination,$b,$sender,$now,'BT-2',$authorize),'Cross-company lot dispatched');
$ambiguous=$inventory;$ambiguous['lots']['PO|123']['hold']='Unallocated historical consumption';bt_reject(fn()=>bt_dispatch($state2,$ambiguous,$destination,$b,$sender,$now,'BT-2',$authorize),'Unallocated source lot silently chosen');
$multi=$inventory;$multi['lots']['PO|456']=array_replace($lot,['originBagId'=>'456','poNo'=>'PO-2','availableQty'=>60]);$b['allocations']=[['lotId'=>'PO|123','qty'=>40],['lotId'=>'PO|456','qty'=>60]];
$out=bt_dispatch($state2,$multi,$destination,$b,$sender,$now,'BT-2',$authorize);bt_check(count($out['record']['lines'])===2&&array_column($out['record']['lines'],'originBagId')===['123','456'],'Multiple explicit PO allocations were merged or invented');
echo "PASS physical bag transfer source identity/whole quantities, explicit multi-PO lines, destination partial acknowledgement, real source return, immutable dispatch, actor/scope/stale holds, replay and unchanged purchase/financial records\n";

$heldState=['transfers'=>[]];$b=$body;$b['requestKey']='quarantine-dispatch-01';$now2='2026-10-18T13:00:00Z';
$dispatch=bt_dispatch($heldState,$inventory,$destination,$b,$sender,$now2,'BT-HELD',$authorize)['record'];$line=$dispatch['lines'][0]['lineId'];
$held=['entity'=>'TTI','action'=>'quarantine','transferId'=>'BT-HELD','date'=>'2026-10-18','reason'=>'Damaged delivery held at destination','requestKey'=>'quarantine-hold-001','allocations'=>[['lineId'=>$line,'qty'=>100]]];
$out=bt_quarantine($heldState,$held,$receiver,$now2,$authorize);$tot=bt_totals($out['record'])[$line];bt_check($tot['quarantined']===100&&$tot['inTransit']===0&&$tot['received']===0&&$tot['returned']===0,'Rejected delivery invented available stock or a source return');
$before=serialize($heldState);bt_check(bt_quarantine($heldState,$held,$receiver,$now2,$authorize)['replayed']&&serialize($heldState)===$before,'Quarantine replay wrote twice');
$release=array_replace($held,['action'=>'accept_quarantine','requestKey'=>'quarantine-release-01','reason'=>'Inspected and accepted after correction','allocations'=>[['lineId'=>$line,'qty'=>60]]]);
$bad=array_replace($release,['date'=>'2026-10-17']);bt_reject(fn()=>bt_quarantine($heldState,$bad,$receiver,$now2,$authorize),'Quarantine accepted before physical arrival');bt_check(serialize($heldState)===$before,'Refused historical release changed stock');
$out=bt_quarantine($heldState,$release,$receiver,$now2,$authorize);bt_check(bt_totals($out['record'])[$line]['quarantined']===40,'Partial acceptance consumed the whole hold');
$back=array_replace($held,['action'=>'return_quarantine','requestKey'=>'quarantine-return-01','reason'=>'Physically unloaded back at source','allocations'=>[['lineId'=>$line,'qty'=>40]]]);
$out=bt_quarantine($heldState,$back,$sender,$now2,$authorize);bt_check($out['status']==='Closed — received and returned','Quarantine resolution did not close exactly');$tot=bt_totals($out['record'])[$line];bt_check($tot['received']===60&&$tot['returned']===40&&$tot['quarantined']===0&&$tot['inTransit']===0,'Quarantine quantities did not conserve dispatch');
$before=serialize($heldState);$over=array_replace($release,['requestKey'=>'quarantine-overflow-01']);bt_reject(fn()=>bt_quarantine($heldState,$over,$receiver,$now2,$authorize),'Resolved quarantine accepted twice');bt_check(serialize($heldState)===$before,'Overflow modified history');
echo "PASS quarantine: rejected delivery unavailable, immutable actor/date evidence, partial inspection/physical return, date chronology, whole quantities and exact retries\n";

require_once dirname(__DIR__,2).'/api/bag_shipment_usage_core.php';
$masters=['mills'=>[
    ['id'=>'mills-1','values'=>['TTI Rice Mills','','Own Mill','','','Active']],
    ['id'=>'mills-2','values'=>['Al Harmain','','External Mill','','','Active']],
]];
$sourceScope=['entity'=>'TTI','locationId'=>'mills-1','millName'=>'TTI Rice Mills'];
$destinationScope=['entity'=>'TTI','locationId'=>'mills-2','millName'=>'Al Harmain'];
$shipmentOrder=['id'=>123,'entity'=>'TTI','mill'=>'TTI Rice Mills','brand'=>'GHAZAL','size'=>'50 kg','bagType'=>'PP','received'=>5508,'poNo'=>'PO-260001','poLineKey'=>'L1','contractRef'=>'TG/AMT/13'];
$shipmentReceipt=['id'=>'R1','bagId'=>123,'date'=>'2026-10-01','qty'=>5508];
$actuals=array_map(static fn($i)=>[
    'number'=>'ABCD'.str_pad((string)$i,7,'0',STR_PAD_LEFT),'location'=>'Al Harmain','brand'=>'GHAZAL','bags'=>540,'loadedDate'=>'2026-10-06',
],range(1,10));
$exports=['contracts'=>[['ref'=>'TG/AMT/13','packingUnit'=>'KG','packings'=>[['brand'=>'GHAZAL','size'=>50]]]],
    'shipments'=>[['id'=>'shipment-13','kind'=>'lot','lotId'=>'TG/AMT/13/L01','contractRef'=>'TG/AMT/13','completed'=>true,'containers'=>10,
        'loadingPlan'=>['allocations'=>[['name'=>'Al Harmain','packIndex'=>0,'emptyBags'=>108]]],'millActuals'=>$actuals]]];
$values=['tt30bags'=>json_encode([$shipmentOrder]),'tt33bagreceipts'=>json_encode([$shipmentReceipt]),'transtrade_export_v3_operational'=>json_encode($exports)];
$state=['transfers'=>[],'requests'=>[],'audit'=>[]];
$today='2026-10-17';
$view=bti_project($values,$masters,$sourceScope,'2026-10-03',$today,$state);
$allow=static function($entity,$name,$action,$id):void {
    bt_check($entity==='TTI'&&$action==='Create'&&in_array($id,['mills-1','mills-2'],true),'Unexpected shipment bag usage scope');
};
$dispatch=bt_dispatch($state,$view,['id'=>'mills-2','name'=>'Al Harmain','status'=>'Active'],[
    'entity'=>'TTI','sourceLocationId'=>'mills-1','destinationId'=>'mills-2','date'=>'2026-10-03','sourceFingerprint'=>$view['fingerprint'],
    'allocations'=>[['lotId'=>'PO|123|mills-1','qty'=>5508]],'requestKey'=>'dispatch-shipment-bags-1',
],$sender,$now,'BT-SHIPMENT',$allow)['record'];
$line=$dispatch['lines'][0]['lineId'];
bt_check(bsu_candidates($values,$dispatch)===[],'Dispatched bags became eligible before physical acceptance');
$accepted=bt_receive($state,[
    'entity'=>'TTI','transferId'=>$dispatch['id'],'date'=>'2026-10-04','allocations'=>[['lineId'=>$line,'qty'=>5508]],'requestKey'=>'receive-shipment-bags-1',
],$receiver,$now,$allow)['record'];
$matches=bsu_candidates($values,$accepted);
bt_check(count($matches)===1&&$matches[0]['packedQty']===5400&&$matches[0]['plannedEmptyQty']===108,'Packed actuals and planned spares did not match');
$request=['entity'=>'TTI','transferId'=>$dispatch['id'],'lineId'=>$line,'shipmentId'=>'shipment-13','fingerprint'=>bt_hash($matches[0]),
    'emptyQty'=>108,'requestKey'=>'confirm-shipment-bags-1'];
bt_reject(fn()=>bsu_record($values,$state,$masters,array_replace($request,['emptyQty'=>109]),$receiver,$now,$allow),'Usage exceeded accepted stock');
bt_reject(fn()=>bsu_record($values,$state,$masters,array_replace($request,['fingerprint'=>'stale']),$receiver,$now,$allow),'Stale shipment usage was accepted');
bt_reject(fn()=>bsu_record($values,$state,$masters,$request,$receiver,$now,$denied),'Shipment usage bypassed destination permission');
bsu_record($values,$state,$masters,$request,$receiver,$now,$allow);
$committed=$state;
bt_check(bsu_record($values,$state,$masters,$request,$receiver,$now,$allow)['replayed']&&$state===$committed,'Retry deducted shipment bags twice');
bt_reject(fn()=>bsu_record($values,$state,$masters,array_replace($request,['requestKey'=>'confirm-shipment-bags-2']),$receiver,$now,$allow),'Another request deducted the same shipment twice');
$stock=bti_project($values,$masters,$destinationScope,$today,$today,$state);
$lotId='BT|'.$dispatch['id'].'|'.$line;
bt_check($stock['lots'][$lotId]['availableQty']===0,'Accepted destination stock did not reach zero');
$movements=array_column($stock['movements'],'qty','type');
bt_check($movements['SHIPMENT_PACKED_BAGS']===-5400&&$movements['SHIPMENT_EMPTY_BAGS']===-108,'Shipment usage did not keep packed and empty bags distinct');
$beforeLoading=bti_project($values,$masters,$destinationScope,'2026-10-05',$today,$state);
bt_check($beforeLoading['lots'][$lotId]['availableQty']===5508&&$beforeLoading['lots'][$lotId]['committableQty']===0,'Historical stock ignored later committed shipment usage');
echo "PASS completed shipment consumes 5,400 packed and 108 confirmed empty bags from the accepted transfer exactly once\n";
