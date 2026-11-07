<?php
declare(strict_types=1);
require_once __DIR__.'/../../inventory_reconciliation.php';
function pt_check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function pt_reject(callable $fn,string $message):void{try{$fn();}catch(DomainException|InvalidArgumentException){return;}throw new RuntimeException($message);}
$scope=['entity'=>'BRM','millId'=>2,'millName'=>'Fixture Reprocessing Mill','locationId'=>''];
$source='IRRI-6 White Rice — SOURCE';$target='IRRI-6 White Rice — DEST';
$opening=$scope+['id'=>'opening','openingStock'=>true,'readyRiceKg'=>1000,'stockName'=>$source,'brand'=>$source];
$values=['tt32stockadj'=>tt_inv_json([$opening]),'tt32labourfeed'=>'[]'];
$row=$scope+['id'=>'move-1','date'=>'2026-10-27','type'=>'Paltai Transfer','paltaiVersion'=>1,'from'=>$source,'to'=>$target,'weightKg'=>500,'sourceDeductionKg'=>510,'sweepingKg'=>10,'rawRiceKg'=>10,'rawStockName'=>'IRRI-6 White Raw Rice','sourceBagSize'=>100,'sourceBags'=>5.1,'currentBrandBags'=>20,'outputBagSize'=>25,'labourRate'=>8];
$user=['role'=>'Super Admin'];
$save=static function(array $base,array $rows)use($user):array{$base['tt32stockadj']=tt_inv_prepare_write($base,'tt32stockadj',tt_inv_json($rows),$user,'2026-10-27T08:00:00Z');return tt_inv_reconcile($base,'2026-10-27T08:00:00Z');};
$saved=$save($values,[$opening,$row]);$stock=tt_inv_stock($saved,$scope);
pt_check($stock[$source]===490.0&&$stock[$target]===500.0&&$stock['IRRI-6 White Raw Rice']===10.0,'Paltai must conserve scoped physical kilograms.');
pt_check(tt_inv_stock($saved,['entity'=>'TTI','millName'=>'TTI Rice Mills'])===[],'Transfer leaked to default TTI mill.');
$feed=tt_inv_rows($saved,'tt32labourfeed');pt_check(count($feed)===1&&$feed[0]['entity']==='BRM'&&$feed[0]['millId']===2&&$feed[0]['quantity']===20&&$feed[0]['rate']===8,'Atomic labour source is missing or unscoped.');
pt_check($save($saved,[$opening,$row])===$saved,'Retry duplicated stock or labour.');
foreach(['entity'=>'TTI','millName'=>'Other Mill','date'=>'2026-02-30','sweepingKg'=>-1,'labourRate'=>-1,'weightKg'=>501,'sourceDeductionKg'=>509,'from'=>'Ready Rice — Invented']as$key=>$bad)pt_reject(fn()=>$save($values,[$opening,array_replace($row,[$key=>$bad])]),'Accepted invalid '.$key);
$tooMuch=array_replace($row,['id'=>'move-2']);pt_reject(fn()=>$save($saved,[$opening,$row,$tooMuch]),'Cumulative transfer overdrew stock.');
pt_reject(fn()=>$save($saved,[$opening]),'Saved transfer was removed.');
pt_reject(fn()=>$save($saved,[$opening,array_replace($row,['labourRate'=>9])]),'Saved transfer was rewritten.');
pt_reject(fn()=>tt_inv_prepare_write($saved,'tt32labourfeed','[]',$user,'2026-10-27T08:00:00Z'),'Derived labour was removed.');
pt_check(tt_inv_prepare_write($saved,'tt32labourfeed',$saved['tt32labourfeed'],$user,'2026-10-27T08:00:00Z')===$saved['tt32labourfeed'],'Unchanged labour replay should be allowed.');
echo "PASS Paltai stock/labour atomic derivation, scope, conservation, overdraw, validation, replay and immutability\n";
