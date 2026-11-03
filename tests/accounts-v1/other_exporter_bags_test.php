<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/api/other_exporter_bags_core.php';
(static function():void {
    $check=static function($ok,$why){if(!$ok)throw new RuntimeException($why);};
    $masters=['mills'=>[['id'=>'M1','values'=>['Own Mill','','Own Mill','','','Active']]]];$scope=['entity'=>'TTI','locationId'=>'M1','millName'=>'Own Mill','status'=>'Active'];
    $deal=['id'=>'HKM-1','entity'=>'TTI','direction'=>'SALE','status'=>'Open','partyId'=>'HKM','party'=>'HKM','brand'=>'Amros','date'=>'2026-10-01','version'=>3,'packing'=>'50 kg PP Bags','productionReady'=>true,'productionMillName'=>'Own Mill','productionBagTareGrams'=>80];
    $books=['exporterDeals'=>['HKM-1'=>$deal],'journals'=>[['unchanged'=>true]]];$original=$books;
    $values=['tt30bags'=>'[]','tt33bagreceipts'=>'[]','tt30prod'=>'[]','tt36bagissues'=>'[]','transtrade_export_v3_operational'=>'{}'];$state=['requests'=>[],'audit'=>[]];
    $user=['id'=>7,'full_name'=>'Mill receiver'];$body=['action'=>'receive','sodaId'=>'HKM-1','version'=>3,'packingKg'=>50,'bagType'=>'PP Woven','date'=>'2026-11-03','qty'=>100,'inspected'=>true,'requestKey'=>'other-exporter-receipt-0001'];$authorize=static function(){};
    $result=oeb_receive($values,$state,$masters,$books,$scope,$body,$user,'2026-11-03T10:00:00Z',$authorize);
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
    $alter=json_decode($values['tt30bags'],true);$alter[0]['externalOwnerId']='TTI';try{br_guard_write($values,'tt30bags',json_encode($alter));throw new RuntimeException('External owner could be changed by generic save');}catch(DomainException){}
    $check(bti_project($values,$masters,['entity'=>'BRM','locationId'=>'M1','millName'=>'Own Mill'],'2026-11-03','2026-11-03',$state)['lots']===[],'External bags leaked into another operating company');
    echo "PASS other exporter bags: instructed source, external ownership, actual multi-receipts, replay, quarantine, source allocation/consumption, closed history, protected identity and company isolation\n";
})();
