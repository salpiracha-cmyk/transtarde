<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/scripts/arrival_dependency_core.php';
function check(bool $condition,string $message):void {if (!$condition) throw new RuntimeException($message);}
$slips=[];
foreach (arrival_test_manifest() as $slip=>$expected) {
    $i=count($slips);$slips[]=array_merge($expected,['slip'=>$slip,'id'=>1800000000000+$i,'queueId'=>1700000000000+$i,'bridgeId'=>'PHB-180000000000'.$i,'broker'=>'Shams','unloadingDate'=>'2026-09-23']);
}
$values=['tt30slips'=>json_encode($slips),'tt30queue'=>json_encode([['id'=>$slips[0]['queueId']]]),'tt38labourbills'=>json_encode([['id'=>'LAB-1','sourceSlips'=>[$slips[1]['id']]]]),'tt30ship'=>json_encode([['id'=>'REAL-SHIP','soda'=>'26002']])];
$books=['events'=>['EVENT-1'=>['id'=>'EVENT-1','sourceKey'=>'TTI|'.$slips[0]['bridgeId'],'journalId'=>'POST-1']],
    'journals'=>['POST-1'=>['id'=>'POST-1','meta'=>['pohanch'=>'PH-00001']]],
    'commodityBills'=>['BILL-1'=>['allocations'=>[['eventId'=>'EVENT-1']]]],
    'supplierSettlements'=>['PAY-1'=>['billId'=>'BILL-1']]];
$before=serialize([$values,$books]);$r=arrival_dependency_audit($values,$books);
check($r['identityVerified'],'Expected exact identities');
check(serialize([$values,$books])===$before,'Diagnostic mutated input');
check(count(array_filter($r['references'],fn($x)=>$x['collection']==='tt30ship'))===0,'Shared Soda incorrectly treated as a dependency');
check(count(array_filter($r['references'],fn($x)=>$x['collection']==='tt38labourbills'))===1,'Missed numeric source identity');
check(count(array_filter($r['financialLinks'],fn($x)=>$x['collection']==='commodityBills'))===1,'Missed indirect bill');
check(count(array_filter($r['financialLinks'],fn($x)=>$x['collection']==='supplierSettlements'))===1,'Missed transitive payment');
$bad=$slips;$bad[0]['bags']=401;$v=$values;$v['tt30slips']=json_encode($bad);
check(!arrival_dependency_audit($v,$books)['identityVerified'],'Changed identity accepted');
$bad=$slips;$bad[]=$slips[0];$v['tt30slips']=json_encode($bad);
check(!arrival_dependency_audit($v,$books)['identityVerified'],'Duplicate public PH accepted');
check(!arrival_reference_paths('PH-000010',['PH-00001']),'Public ID prefix matched another receipt');
check(!arrival_reference_paths('XPH-00001',['PH-00001']),'Embedded public ID matched');
check(arrival_reference_paths('receipt PH-00001 / arrived',['PH-00001'])!==[],'Delimited public ID missed');
echo "PASS exact arrival manifest, dependency evidence, financial links, prefix protection, duplicate/changed identity rejection and zero mutation\n";
