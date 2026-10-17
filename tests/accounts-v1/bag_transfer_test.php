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
$out=bt_receive($state,$return,$sender,$now,$authorize,true);$totals=bt_totals($out['record']);bt_check($totals[$line]===['dispatched'=>100,'received'=>70,'returned'=>20,'inTransit'=>10],'Physical return did not preserve actual quantities');
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
