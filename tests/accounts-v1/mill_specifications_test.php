<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/api/mill_specifications_core.php';
$check=static function($ok,$why){if(!$ok)throw new RuntimeException($why);};
$scope=['entity'=>'TTI','locationId'=>'M1','millName'=>'TTI Rice Mills'];
$contract=['ref'=>'OWN','seller'=>'TTI','status'=>'Open','product'=>'IRRI-6','packingUnit'=>'KG'];
$instruction=['contractRef'=>'OWN','product'=>'IRRI-6','quality'=>'Original quality','specifications'=>[['name'=>'Broken','value'=>'5%']],'packings'=>[['brand'=>'ACTIVE','size'=>50,'containers'=>2,'weightPer'=>28]],'production'=>[]];
$deal=['id'=>'HKM','entity'=>'TTI','direction'=>'SALE','status'=>'Open','date'=>'2026-11-01','productionReady'=>true,'productionMillName'=>'TTI Rice Mills','party'=>'HKM','brand'=>'emrooz','quantityMT'=>1875,'product'=>'IRRI-6','specificationMode'=>'FULL','fullSpecification'=>'Emrooz full quality','productionNotes'=>'Original mill instructions','packing'=>'40 kg bags','rate'=>99999,'profit'=>123456];
$root=['contracts'=>[$contract],'shipments'=>[],'millSync'=>['productionInstructions'=>[$instruction]]];
$books=['exporterDeals'=>['HKM'=>$deal],'journals'=>[['unchanged'=>true]]];$values=['transtrade_export_v3_operational'=>json_encode($root),'tt30prodinst'=>json_encode([['brand'=>'STALE CLOSED']])];$original=[$books,$values];
$rows=msp_rows($values,$books,$scope,'2026-11-07');$check(count($rows)===2&&$rows[1]['brand']==='emrooz','Active own and fellow instructions missing');$check($rows[1]['quality']==='Emrooz full quality','Full fellow specification lost');
$check(!str_contains(json_encode($rows),'99999')&&!str_contains(json_encode($rows),'123456'),'Financial terms leaked');
foreach(['Completed','Closed','Cancelled','Archived','Deleted'] as $status){$r=$root;$r['contracts'][0]['status']=$status;$v=$values;$v['transtrade_export_v3_operational']=json_encode($r);$out=msp_rows($v,$books,$scope,'2026-11-07');$check(count($out)===1&&$out[0]['brand']==='emrooz','Closed contract or stale instruction displayed');}
$r=$root;$r['shipments']=[['id'=>'LOT1','kind'=>'lot','contractRef'=>'OWN','lotId'=>'L1','completed'=>true]];$v=$values;$v['transtrade_export_v3_operational']=json_encode($r);$check(count(msp_rows($v,$books,$scope,'2026-11-07'))===1,'Closed lot displayed under open contract');
$r['shipments'][]=['id'=>'LOT2','kind'=>'lot','contractRef'=>'OWN','lotId'=>'L2','status'=>'Open'];$v['transtrade_export_v3_operational']=json_encode($r);$check(count(msp_rows($v,$books,$scope,'2026-11-07'))===2,'Mixed open/closed contract lost active work');
$r['millSync']['productionInstructions'][0]['shipmentId']='LOT1';$v['transtrade_export_v3_operational']=json_encode($r);$check(count(msp_rows($v,$books,$scope,'2026-11-07'))===1,'Instruction linked to closed lot displayed');
foreach(['Closed','Completed','Cancelled'] as $status){$b=$books;$b['exporterDeals']['HKM']['status']=$status;$check(count(msp_rows($values,$b,$scope,'2026-11-07'))===1,'Closed fellow SODA displayed');}
$check(msp_rows($values,$books,array_replace($scope,['millName'=>'Another Mill']),'2026-11-07')===[],'Another physical mill instruction leaked');
$check(msp_rows($values,$books,array_replace($scope,['entity'=>'BRM']),'2026-11-07')===[],'Another company instruction leaked');
$b=$books;$b['exporterDeals']['HKM']['productionReady']=false;$check(count(msp_rows($values,$b,$scope,'2026-11-07'))===1,'Unsent fellow instructions displayed');
$check([$books,$values]===$original,'Reading specifications modified source data');
echo "PASS active own/fellow specifications, closed contracts/lots, mixed lots, sent-only source, mill/company scope, private financial terms and unchanged original data\n";
