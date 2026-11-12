<?php
declare(strict_types=1);
require __DIR__.'/../../inventory_reconciliation.php';
function checkReturn($ok,$message){if(!$ok)throw new RuntimeException($message);}
function rejectReturn($fn,$message){try{$fn();}catch(DomainException|InvalidArgumentException){return;}throw new RuntimeException($message);}
$scope=['entity'=>'TTI','millId'=>1,'millName'=>'TTI Rice Mills'];$opening=$scope+['id'=>'OPEN','date'=>'2026-11-10','openingStock'=>true,'byProducts'=>['Sweeping'=>600]];$base=['tt32stockadj'=>tt_inv_json([$opening])];
$row=$scope+['id'=>'RETURN-1','type'=>'Return to Production','returnVersion'=>1,'date'=>'2026-11-11','from'=>'Sweeping','to'=>'IRRI-6 White Raw Rice','weightKg'=>400,'rawRiceKg'=>400,'rawStockName'=>'IRRI-6 White Raw Rice','byProducts'=>['Sweeping'=>-400],'remarks'=>'Actual return'];
$save=static function($values,$rows){$values['tt32stockadj']=tt_inv_prepare_write($values,'tt32stockadj',tt_inv_json($rows),['role'=>'Super Admin'],'2026-11-12T08:00:00Z');return tt_inv_reconcile($values,'2026-11-12T08:00:00Z');};
$saved=$save($base,[$opening,$row]);$stock=tt_inv_stock($saved,$scope);checkReturn($stock['Sweeping']===200.0&&$stock['IRRI-6 White Raw Rice']===400.0,'Balanced return failed');checkReturn(array_sum($stock)===600.0,'Stock invented or lost');checkReturn(empty(tt_inv_rows($saved,'tt32labourfeed')),'Return invented labour');checkReturn($save($saved,[$opening,$row])===$saved,'Retry duplicated stock');
foreach(['date'=>'2026-11-09','entity'=>'BRM','millName'=>'Other mill','weightKg'=>0,'rawRiceKg'=>500,'readyRiceKg'=>1,'from'=>'B3','to'=>'IRRI-6 White Rice'] as $field=>$value)rejectReturn(fn()=>$save($base,[$opening,array_replace($row,[$field=>$value])]),'Invalid '.$field.' accepted');
rejectReturn(fn()=>$save($base,[$opening,array_replace($row,['date'=>'2026-11-13'])]),'Future return accepted');
rejectReturn(fn()=>$save($saved,[$opening,$row,array_replace($row,['id'=>'RETURN-2'])]),'Cumulative overdraw accepted');rejectReturn(fn()=>$save($saved,[$opening]),'Saved return removed');rejectReturn(fn()=>$save($saved,[$opening,array_replace($row,['remarks'=>'Rewrite'])]),'Saved return overwritten');
$saved['tt30prod']=tt_inv_json([$scope+['id'=>'PROD','date'=>'2026-11-11','inputStockName'=>'IRRI-6 White Raw Rice','rows'=>[['product'=>'Ready Rice — Emrooz','displayName'=>'IRRI-6 White Rice','bags'=>6,'bagWeight'=>50],['product'=>'B2 — LOCAL','bags'=>2,'bagWeight'=>50]]]]);$stock=tt_inv_stock($saved,$scope);checkReturn($stock['IRRI-6 White Raw Rice']===0.0&&$stock['Sweeping']===200.0&&$stock['IRRI-6 White Rice — Emrooz']===300.0,'Production did not consume returned raw stock once');
echo "PASS return to production: balanced weight, raw consumption, dates, scope, replay, overdraw, retained history and no labour\n";
