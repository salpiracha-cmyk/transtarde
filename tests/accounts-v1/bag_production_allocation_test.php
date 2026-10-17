<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/inventory_reconciliation.php';
require_once dirname(__DIR__,2).'/api/bag_inventory_core.php';
function tt_user_can_open_module($u,$m){return true;}
function tt_user_can_module_action($u,$m,$i,$a){return in_array($a,$u['actions']??[],true);}
function tt_user_can_access_entity($u,$e,$a){return $e==='TTI';}
function tt_list_masters(){return $GLOBALS['bagMasters'];}
function bp_check($yes,$why){if(!$yes)throw new RuntimeException($why);}
function bp_reject(callable $f,$why){try{$f();}catch(DomainException){return;}throw new RuntimeException($why);}
$GLOBALS['bagMasters']=['mills'=>[['id'=>'mills-1','values'=>['TTI Rice Mills','','Own Mill','','','Active','','TTI']],['id'=>'mills-2','values'=>['Other Mill','','Own Mill','','','Active','','TTI']]]];$masters=tt_list_masters();
$user=['id'=>7,'full_name'=>'Actual producer','role'=>'Staff','location'=>'TTI Rice Mills','actions'=>['View','Create','Edit'],'permissions'=>['Mill'=>['production'=>['View','Create','Edit']]]];
$scope=['entity'=>'TTI','locationId'=>'mills-1','millName'=>'TTI Rice Mills','status'=>'Active'];$date='2026-10-17';$now=$date.'T12:00:00Z';
$order=['id'=>123,'entity'=>'TTI','mill'=>'TTI Rice Mills','brand'=>'ASAS','size'=>'50 kg','bagType'=>'PP','received'=>100,'poNo'=>'PO-1','poLineKey'=>'L1'];$values=['tt30bags'=>json_encode([$order]),'tt33bagreceipts'=>json_encode([['id'=>'R1','bagId'=>123,'date'=>'2026-10-01','qty'=>100]])];$original=serialize($values);
$review=bti_project($values,$masters,$scope,$date,$date,[],'P1');$id='PO|123|mills-1';
$record=['id'=>'P1','entity'=>'TTI','millId'=>1,'millName'=>'TTI Rice Mills','date'=>$date,'shift'=>'Day','variety'=>'IRRI-6','baseVariety'=>'IRRI-6','riceType'=>'White','recoveryVersion'=>1,'rawInputs'=>[['grossKg'=>500]],'rows'=>[['product'=>'Ready Rice — ASAS','bags'=>10,'bagWeight'=>50]],'bagAllocationVersion'=>1,'bagStockFingerprint'=>$review['fingerprint'],'bagAllocations'=>[['lotId'=>$id,'qty'=>10,'sourceSnapshot'=>['brand'=>'FORGED','packingSize'=>'1 kg']]]];
$stored=json_decode(tt_inv_prepare_write($values,'tt30prod',json_encode([$record]),$user,$now),true)[0];bp_check($stored['bagAllocations'][0]['sourceSnapshot']['brand']==='ASAS','Client replaced authoritative bag source snapshot');bp_check($stored['bagAllocationActor']['userId']===7&&$stored['bagAllocationScope']['locationId']==='mills-1','Actual producer or stock scope lost');bp_check(serialize($values)===$original,'Validation wrote its sources');
$committed=$values;$committed['tt30prod']=json_encode([$stored]);$after=bti_project($committed,$masters,$scope,$date,$date);bp_check($after['lots'][$id]['availableQty']===90,'Canonical production did not consume exact source lot');
$stale=$record;$stale['bagStockFingerprint']='stale';bp_reject(fn()=>bti_prepare_production($values,json_encode([$stale]),$masters,$user,$now),'Stale bag review was accepted');
$wrong=$record;$wrong['bagAllocations'][0]['qty']=9;bp_reject(fn()=>bti_prepare_production($values,json_encode([$wrong]),$masters,$user,$now),'Packing/allocation mismatch accepted');
$tooMuch=$record;$tooMuch['rows'][0]['bags']=101;$tooMuch['bagAllocations'][0]['qty']=101;bp_reject(fn()=>bti_prepare_production($values,json_encode([$tooMuch]),$masters,$user,$now),'Source bag stock overdraw accepted');
$readonly=array_replace($user,['actions'=>['View']]);bp_reject(fn()=>bti_prepare_production($values,json_encode([$record]),$masters,$readonly,$now),'Read-only role created bag consumption');
$other=array_replace($user,['location'=>'Other Mill']);bp_reject(fn()=>bti_prepare_production($values,json_encode([$record]),$masters,$other,$now),'Location boundary bypassed');
// Unchanged bag facts retain the actual original source and actor during a raw-input edit.
$edit=$stored;$edit['rawInputs'][0]['grossKg']=600;$edit['bagAllocationActor']=['userId'=>999,'by'=>'Forged'];$edit['bagStockFingerprint']='no-new-stock-read-needed';
$out=json_decode(bti_prepare_production($committed,json_encode([$edit]),$masters,array_replace($user,['id'=>8]),$date.'T12:05:00Z'),true)[0];bp_check($out['bagAllocationActor']===$stored['bagAllocationActor']&&$out['bagAllocations']===$stored['bagAllocations'],'Unchanged source allocation rewrote its original actor/snapshot');
$creator=array_replace($user,['actions'=>['View','Create']]);bp_reject(fn()=>bti_prepare_production($committed,json_encode([$edit]),$masters,$creator,$now),'Create-only role edited a committed versioned run');
// A stale client cannot erase the source allocation by dropping its version fields.
$legacy=$stored;foreach(['bagAllocationVersion','bagAllocations','bagStockFingerprint','bagAllocationActor','bagAllocationScope'] as $key)unset($legacy[$key]);
$out=json_decode(bti_prepare_production($committed,json_encode([$legacy]),$masters,$user,$now),true)[0];bp_check($out['bagAllocations']===$stored['bagAllocations'],'Stale client erased canonical allocation');$legacy['rows'][0]['bags']=11;bp_reject(fn()=>bti_prepare_production($committed,json_encode([$legacy]),$masters,$user,$now),'Changed packing dropped its allocation marker');
// Actual whole empty-bag count is explicit; fractional food-equivalent bags are never rounded.
$partial=$record;$partial['rows'][0]['bags']=10.5;$partial['rows'][0]['actualBagCount']=11;$partial['bagAllocations'][0]['qty']=11;
$out=json_decode(bti_prepare_production($values,json_encode([$partial]),$masters,$user,$now),true)[0];bp_check($out['rows'][0]['bags']===10.5&&$out['rows'][0]['actualBagCount']===11,'Explicit actual bag count overwrote food output');
unset($partial['rows'][0]['actualBagCount']);bp_reject(fn()=>bti_prepare_production($values,json_encode([$partial]),$masters,$user,$now),'Fractional bags inferred a whole physical count');
// One collection write cannot independently spend the same stock review twice.
$second=$record;$second['id']='P2';bp_reject(fn()=>bti_prepare_production($values,json_encode([$record,$second]),$masters,$user,$now),'Two independent allocations overspent one review');
// Backdating cannot consume bags already committed to a later actual run.
$later=$stored;$later['date']='2026-10-16';$later['rows'][0]['bags']=95;$later['bagAllocations'][0]['qty']=95;$reserved=$values;$reserved['tt30prod']=json_encode([$later]);
$earlier=bti_project($reserved,$masters,$scope,'2026-10-10',$date,[],'P2');bp_check($earlier['lots'][$id]['availableQty']===100&&$earlier['lots'][$id]['committableQty']===5,'Historical availability did not preserve later committed consumption');
$backdated=$second;$backdated['date']='2026-10-10';$backdated['bagStockFingerprint']=$earlier['fingerprint'];bp_reject(fn()=>bti_prepare_production($reserved,json_encode([$later,$backdated]),$masters,$user,$now),'Backdated production stole later committed bags');
echo "PASS production source allocation: actual shared writer, source-owned snapshot, scope/roles/stale/quantity holds, exact stock consumption, original actor preservation, stale-client protection and explicit whole bag count without changing food output\n";
