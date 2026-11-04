<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/api/other_exporter_bags_core.php';
require_once dirname(__DIR__,2).'/api/fellow_exporters_core.php';
require_once dirname(__DIR__,2).'/api/directors_trading_links.php';
(static function():void {
    $check=static function($ok,$why){if(!$ok)throw new RuntimeException($why);};
    $masters=['mills'=>[['id'=>'M1','values'=>['Own Mill','','Own Mill','','','Active']]]];$scope=['entity'=>'TTI','locationId'=>'M1','millName'=>'Own Mill','status'=>'Active'];
    $deal=['id'=>'HKM-1','entity'=>'TTI','direction'=>'SALE','status'=>'Open','partyId'=>'HKM','party'=>'HKM','brand'=>'Amros','date'=>'2026-10-01','version'=>3,'packing'=>'50 kg PP Bags','productionReady'=>true,'productionMillName'=>'Own Mill','productionBagTareGrams'=>80];
    $books=['exporterDeals'=>['HKM-1'=>$deal],'journals'=>[['unchanged'=>true]]];$original=$books;
    $values=['tt30bags'=>'[]','tt33bagreceipts'=>'[]','tt30prod'=>'[]','tt36bagissues'=>'[]','transtrade_export_v3_operational'=>'{}'];$state=['requests'=>[],'audit'=>[]];
    $user=['id'=>7,'full_name'=>'Mill receiver'];$body=['action'=>'receive','sodaId'=>'HKM-1','version'=>3,'packingKg'=>50,'bagType'=>'PP Woven','date'=>'2026-11-03','qty'=>100,'inspected'=>true,'requestKey'=>'other-exporter-receipt-0001'];$authorize=static function(){};
    $result=oeb_receive($values,$state,$masters,$books,$scope,$body,$user,'2026-11-03T10:00:00Z',$authorize);
    $custody=bti_rows($values,'tt30bags');$check(fe_delete_issue($books,$deal,[],$custody)!=='','SODA with original bag custody could be deleted');
    try{dt_assert_other_bag_route($deal,array_replace($deal,['brand'=>'OTHER']),$custody);throw new RuntimeException('Received SODA brand was relabelled');}catch(DomainException){}
    foreach(['OUR','NONE'] as $owner){try{oeb_spec($deal+['bagTerms'=>['status'=>'CONFIRMED','owner'=>$owner]],$scope,$body);throw new RuntimeException('Conflicting confirmed bag ownership accepted');}catch(DomainException){}}
    try{dt_assert_other_bag_route($deal,$deal+['bagTerms'=>['status'=>'CONFIRMED','owner'=>'OUR']],$custody);throw new RuntimeException('Received fellow bags relabelled as our bags');}catch(DomainException){}
    $safe=ba_physical_responsibility($deal+['bagTerms'=>['status'=>'CONFIRMED','owner'=>'FELLOW','arrangementName'=>'Fellow bags','basis'=>'FELLOW','ratePKRPerBag'=>123,'supplierId'=>'PRIVATE']]);$check(!isset($safe['ratePKRPerBag'],$safe['supplierId'])&&$safe['owner']==='FELLOW','Physical bag feed exposed commercial bag rates');
    $check($books===$original,'Receipt changed SODA/commercial books');$check($result['order']['received']===100,'Actual receipt not reflected');
    $check($result['receipt']['sourceSnapshot']['externalOwnerId']==='HKM'&&$result['receipt']['sourceSnapshot']['stockOwnerType']==='OTHER_EXPORTER','External ownership was relabelled as TTI');
    $after=serialize([$values,$state]);$repeat=oeb_receive($values,$state,$masters,$books,$scope,$body,$user,'2026-11-03T10:01:00Z',$authorize);$check($repeat['replayed']&&serialize([$values,$state])===$after,'Receipt retry duplicated quantity/audit');
    $inventory=bti_project($values,$masters,$scope,'2026-11-03','2026-11-03',$state);$lot=array_values($inventory['lots'])[0];$check($lot['availableQty']===100&&$lot['externalOwnerName']==='HKM','External physical stock unavailable or owner lost');
    $choices=oeb_choices($values,$inventory,$books,$scope);$check(count($choices['choices'])===1&&$choices['choices'][0]['brand']==='Amros','Received marking missing from production');
    foreach(['qty'=>0,'date'=>'2026-09-01','version'=>1,'packingKg'=>25,'inspected'=>false] as $key=>$value){$v=$values;$st=$state;try{oeb_receive($v,$st,$masters,$books,$scope,array_replace($body,[$key=>$value,'requestKey'=>'invalid-other-receipt-'.$key]),$user,'2026-11-03T10:00:00Z',$authorize);throw new RuntimeException('Invalid receipt '.$key.' accepted');}catch(DomainException){}}
    $second=array_replace($body,['qty'=>20,'printingMismatch'=>true,'requestKey'=>'other-exporter-quarantine-0002']);oeb_receive($values,$state,$masters,$books,$scope,$second,$user,'2026-11-03T10:00:00Z',$authorize);
    $inventory=bti_project($values,$masters,$scope,'2026-11-03','2026-11-03',$state);$lot=array_values($inventory['lots'])[0];$check($lot['availableQty']===100&&$lot['quarantinedQty']===20,'Mismatched bags entered available stock');
    $run=['id'=>'PROD1','entity'=>'TTI','locationId'=>'M1','millName'=>'Own Mill','date'=>'2026-11-03','rows'=>[['product'=>'Ready Rice — Amros','bags'=>10,'bagWeight'=>50]],'bagAllocationVersion'=>1,'bagStockFingerprint'=>$inventory['fingerprint'],'bagAllocations'=>[['lotId'=>$lot['id'],'qty'=>10]]];
    if(!function_exists('tt_user_can_open_module')){function tt_user_can_open_module($u,$m){return true;}function tt_user_can_module_action($u,$m,$i,$a){return true;}}
    $prepared=json_decode(bti_prepare_production($values,json_encode([$run]),$masters,$user,'2026-11-03T10:00:00Z'),true)[0];$check($prepared['bagAllocations'][0]['sourceSnapshot']['externalOwnerId']==='HKM','Production lost bag ownership');
    $values['tt30prod']=json_encode([$prepared]);$inventory=bti_project($values,$masters,$scope,'2026-11-03','2026-11-03',$state);$lot=array_values($inventory['lots'])[0];$check($lot['availableQty']===90&&$lot['issuedQty']===10,'Production did not deduct the original bags once');
    $closed=$books;$closed['exporterDeals']['HKM-1']['status']='Closed';$check(oeb_choices($values,$inventory,$closed,$scope)['choices']===[],'Completed SODA became current production demand');
    $check(count(oeb_choices($values,$inventory,$closed,$scope)['diagnostics'])===1,'Completed bag stock disappeared from history');
    $historical=$values;$oldOrders=bti_rows($historical,'tt30bags');$oldReceipts=bti_rows($historical,'tt33bagreceipts');
    for($i=1;$i<=4;$i++){$oldOrders[]=['id'=>900+$i,'entity'=>'TTI','mill'=>'Own Mill','brand'=>'OLD-'.$i,'contractRef'=>'OLD-CONTRACT','size'=>'50 kg','bagType'=>'PP','received'=>10,'poNo'=>'OLD-PO-'.$i,'poLineKey'=>'L1'];$oldReceipts[]=['id'=>'OLD-R'.$i,'bagId'=>900+$i,'date'=>'2026-10-01','qty'=>10];}
    $historical['tt30bags']=json_encode($oldOrders);$historical['tt33bagreceipts']=json_encode($oldReceipts);$historical['transtrade_export_v3_operational']=json_encode(['contracts'=>[['ref'=>'OLD-CONTRACT','status'=>'Completed']]]);
    $oldInventory=bti_project($historical,$masters,$scope,'2026-11-03','2026-11-03',$state);$explain=oeb_choices($historical,$oldInventory,$books,$scope);
    $check(count($explain['choices'])===1&&$explain['choices'][0]['brand']==='Amros','Four historical received brands polluted current production');
    $check(count($explain['diagnostics'])===5,'Original historical source explanation was lost');
    $alter=json_decode($values['tt30bags'],true);$alter[0]['externalOwnerId']='TTI';try{br_guard_write($values,'tt30bags',json_encode($alter));throw new RuntimeException('External owner could be changed by generic save');}catch(DomainException){}
    $check(bti_project($values,$masters,['entity'=>'BRM','locationId'=>'M1','millName'=>'Own Mill'],'2026-11-03','2026-11-03',$state)['lots']===[],'External bags leaked into another operating company');
    echo "PASS other exporter bags: instructed source, external ownership, actual multi-receipts, replay, quarantine, source allocation/consumption, closed history, protected identity and company isolation\n";
})();

$calendarValues=[];$calendarState=['requests'=>[]];$calendarScope=['entity'=>'TTI','locationId'=>'M1','millName'=>'Own Mill'];$calendarBooks=['exporterDeals'=>['MID'=>['id'=>'MID','entity'=>'TTI','direction'=>'SALE','status'=>'Open','date'=>'2026-11-04','party'=>'Fixture exporter','partyId'=>'MID-P','brand'=>'','quantityMT'=>1,'product'=>'IRRI-6 White Rice','version'=>1]]];
$calendarResult=oeb_register_production_brand($calendarValues,$calendarState,$calendarBooks,$calendarScope,['orderId'=>'FELLOW|MID','version'=>1,'brand'=>'Midnight marking','requestKey'=>'midnight-brand-fixture-0001'],['id'=>7,'full_name'=>'Fixture operator'],'2026-11-03T22:00:00Z');
if(($calendarResult['brand']['brand']??'')!=='Midnight marking')throw new RuntimeException('Karachi current-day SODA unavailable after midnight');
echo "PASS production brand registration uses Karachi business date across the UTC midnight boundary\n";
