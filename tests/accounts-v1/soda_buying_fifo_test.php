<?php
declare(strict_types=1);
define('TT_DATA_DIR',sys_get_temp_dir().'/unused-buying-fifo-fixture');
require dirname(__DIR__,2).'/api/shipment_statement_core.php';
function assertTrue(bool $v,string $m):void {if(!$v)throw new RuntimeException($m);}
function closeTo(float $a,float $b,string $m):void {assertTrue(abs($a-$b)<.01,$m.' '.json_encode([$a,$b]));}
function invalid(callable $fn,string $m):void {try{$fn();}catch(InvalidArgumentException){return;}throw new RuntimeException($m);}
function receipt(array &$books,string $id,string $date,float $kg,float $rate,string $mill='Mill A',string $stage='RAW',string $entity='TTI',string $soda=''):void {
    $books['events'][$id]=['eventType'=>'COMMODITY_RECEIPT_ACCEPTED','entity'=>$entity,'sourceKey'=>$id,'journalId'=>$id];
    $books['journals'][$id]=['id'=>$id,'entity'=>$entity,'date'=>$date,'status'=>'Posted','totalDebit'=>$kg*$rate,'meta'=>['variety'=>'IRRI-6 White Rice','riceType'=>'White','locationName'=>$mill,'productStage'=>$stage,'payableWeightKg'=>$kg,'sourceSodaId'=>$soda]];
    $books['commodityBills'][$id]=['id'=>$id,'status'=>'Verified / Posted','finalCommodityValue'=>$kg*$rate,'receiptAllocations'=>[['sourceKey'=>$id,'provisionalAmount'=>$kg*$rate]]];
}
function production(string $id,string $date,float $kg,string $mill='Mill A'):array {
    return ['id'=>$id,'entity'=>'TTI','date'=>$date,'shift'=>'Day','millName'=>$mill,'sourceVariety'=>'IRRI-6 White Rice','riceType'=>'White','recoveryVersion'=>1,'rawInputs'=>[['grossKg'=>$kg]],'rows'=>[['id'=>1,'product'=>'Ready Rice','bags'=>$kg*.8/50,'bagWeight'=>50],['id'=>2,'product'=>'Stone','bags'=>$kg*.2/50,'bagWeight'=>50]],'recoveryContractRef'=>'C1'];
}
$root=['contracts'=>[['ref'=>'C1','seller'=>'TTI','commodity'=>'RICE','product'=>'IRRI-6','qty'=>3],['ref'=>'OTHER','seller'=>'BRM','commodity'=>'RICE','qty'=>3]],'shipments'=>[]];
$books=['exporterDeals'=>['D1'=>['id'=>'D1','entity'=>'TTI','direction'=>'SALE','reference'=>'LOCAL1','quantityMT'=>2,'product'=>'IRRI-6','commodity'=>'RICE'], 'D2'=>['id'=>'D2','entity'=>'TTI','direction'=>'PURCHASE','quantityMT'=>2]]];
$ops=['values'=>['transtrade_export_v3_operational'=>json_encode($root)]];
$targets=sb_targets($ops,$books,'TTI');assertTrue(array_column($targets,'id')===['EXPORT|C1','EXPORTER|D1'],'Only own export and local exporter sale contracts');
$ready=['productStage'=>'READY','commodity'=>'RICE','baseVariety'=>'IRRI-6'];$raw=array_replace($ready,['productStage'=>'RAW']);
assertTrue(sb_validate([],$ready,1000,$targets)===[],'Blank remains general stock');
$a=[['targetId'=>'EXPORT|C1','quantityKg'=>1000]];
assertTrue(sb_validate($a,$ready,2000,$targets)===$a,'Partial allocation keeps remainder general');
invalid(fn()=>sb_validate($a,$raw,2000,$targets),'Raw rice cannot select buying for');
invalid(fn()=>sb_validate($a,$ready,999,$targets),'No SODA over-allocation');
invalid(fn()=>sb_validate([$a[0],$a[0]],$ready,2000,$targets),'Duplicate target rejected');
invalid(fn()=>sb_validate([['targetId'=>'EXPORT|OTHER','quantityKg'=>1]],$ready,2000,$targets),'Cross-company target rejected');
invalid(fn()=>sb_validate([['targetId'=>'EXPORT|C1','quantityKg'=>INF]],$ready,2000,$targets),'Nonfinite rejected');
$books['purchaseSodas']['S1']=['id'=>'S1','entity'=>'TTI','productStage'=>'READY','qtyToKg'=>2000,'sodaNo'=>'001','buyingForAllocations'=>$a];
invalid(fn()=>sb_validate([['targetId'=>'EXPORT|C1','quantityKg'=>2500]],$ready,3000,$targets,$books,'TTI'),'Other SODAs reserve contract capacity');
assertTrue(sb_validate([['targetId'=>'EXPORT|C1','quantityKg'=>2000]],$ready,3000,$targets,$books,'TTI','S1')[0]['quantityKg']===2000.,'Amend excludes own old allocation');
receipt($books,'R1','2026-10-01',1000,60);receipt($books,'R2','2026-10-02',1000,80);
receipt($books,'WRONGMILL','2026-10-01',10000,1,'Mill B');receipt($books,'READY','2026-10-01',10000,1,'Mill A','READY');receipt($books,'BRM','2026-10-01',10000,1,'Mill A','RAW','BRM');
$runs=[production('P1','2026-10-03',1500),production('P2','2026-10-04',500),production('P3','2026-10-05',10)];$ops['values']['tt30prod']=json_encode($runs);
$before=serialize([$books,$ops]);$fifo=rf_sources($books,$ops,'TTI');
closeTo($fifo['P1']['rawValue'],100000,'FIFO consumes oldest 1000 at 60 then 500 at 80');closeTo($fifo['P2']['rawValue'],40000,'Second run consumes remainder once');assertTrue(!$fifo['P3']['available'],'No reuse, other mill, ready rice or other company');
assertTrue(array_column($fifo['P1']['layers'],'sourceKey')===['R1','R2'],'FIFO trace keeps original receipt identities');
receipt($books,'FUTURE','2026-10-06',100,90);assertTrue(!rf_sources($books,$ops,'TTI')['P3']['available'],'Future receipts never cover earlier production');
$books['journals']['R1']['reversedByPostId']='REV';assertTrue(!rf_sources($books,$ops,'TTI')['P1']['available'],'Reversed receipt excluded');unset($books['journals']['R1']['reversedByPostId']);
receipt($books,'PURCHASE','2026-10-02',2000,100,'Mill A','READY','TTI','S1');
$pool=sb_ready_pool($books,'TTI','EXPORT|C1','2026-10-04');closeTo($pool['kg'],1000,'Only reserved portion of physical receipt');closeTo($pool['value'],100000,'Ready cargo exact purchase cost');
assertTrue(sb_ready_pool($books,'TTI','EXPORT|C1','2026-10-01')['kg']===0.,'No future ready purchases');assertTrue(sb_ready_pool($books,'BRM','EXPORT|C1','2026-10-04')['kg']===0.,'Ready purchase costs stay in legal books');
unset($books['commodityBills']['PURCHASE']);assertTrue(sb_ready_pool($books,'TTI','EXPORT|C1','2026-10-04')['provisional'],'Unbilled accepted purchase remains labelled estimate');
$readyOps=$ops;$readyOps['values']['tt30prod']='[]';$goods=ss_contract_goods($books,$readyOps,['costEntity'=>'TTI','contract'=>'C1'],'2026-10-04',.5);closeTo($goods['amountPKR'],50000,'Ready SODA automatically feeds group shipment cost without production');assertTrue(str_contains($goods['basis'],'pending'),'Ready shipment provisional status visible');
try{ss_contract_goods($books,$readyOps,['costEntity'=>'TTI','contract'=>'C1'],'2026-10-04',2);throw new RuntimeException('Short ready stock accepted');}catch(DomainException){}
$readBefore=serialize([$books,$ops]);rf_sources($books,$ops,'TTI');sb_ready_pool($books,'TTI','EXPORT|C1','2026-10-04');assertTrue(serialize([$books,$ops])===$readBefore,'No report writes inventory, allocation, journal or source');
echo "PASS internal SODA allocations, company/capacity checks, general stock, amendments, FIFO layers, physical receipt estimates and automatic shipment cost\n";
