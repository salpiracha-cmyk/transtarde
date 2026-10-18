<?php
declare(strict_types=1);
define('TT_DATA_DIR',sys_get_temp_dir().'/unused-mill-liability-cost-fixture');
require dirname(__DIR__,2).'/api/production_costing_core.php';
function check_cost(bool $v,string $m):void {if(!$v)throw new RuntimeException($m);}
$run=static fn($id,$date,$mill,$variety,$kg)=>['id'=>$id,'entity'=>'TTI','date'=>$date,'millName'=>$mill,'variety'=>$variety,'sourceVariety'=>$variety,'recoveryVersion'=>1,'rawInputs'=>[['grossKg'=>$kg]],'rows'=>[['id'=>1,'product'=>'Ready Rice — '.$id,'bags'=>1,'bagWeight'=>$kg*.7]]];
$bill=['id'=>'KE1','entity'=>'TTI','millName'=>'TTI Rice Mills','date'=>'2026-10-17','readFrom'=>'2026-10-01','readTo'=>'2026-10-17','ref'=>'KE-OCT','amount'=>2000];
$ops=['values'=>['tt30prod'=>json_encode([$run('A','2026-10-03','TTI Rice Mills','IRRI-6 White Rice',1000),$run('B','2026-10-03','Other Mill','Super Basmati White Rice',3000)]),'tt38kebills'=>json_encode([$bill])]];
$groups=pc_group_production(pc_production($ops));$sources=pc_cost_sources($ops,$groups);$source=$sources[0];
$irri=array_values(array_filter($groups,fn($g)=>str_contains($g['varietyKey'],'IRRI')))[0];
check_cost($source['productionKg']===1000.0&&$source['allocations'][$irri['id']]===2000.0,'Mill bill spread across another mill / variety');
check_cost(count($source['allocations'])===1,'Outside mill received an own-mill charge');
$key='MILL|TTI|1|tt38kebills|KE1';
$store=['millLiabilities'=>[$key=>['key'=>$key,'entity'=>'TTI','sourceCollection'=>'tt38kebills','sourceRecordId'=>'KE1','sourceSnapshot'=>$bill,'status'=>'Posted','journalId'=>'POST1']],
    'journals'=>['POST1'=>['status'=>'Posted','meta'=>['millSourceKey'=>$key]]]];
$changed=$bill;$changed['amount']=99999;$ops['values']['tt38kebills']=json_encode([$changed]);
$frozen=pc_cost_sources($ops,$groups,$store)[0];check_cost($frozen['amount']===2000.0&&$frozen['sourceAmended']&&!$frozen['inputComplete'],'Unposted amendment changed actual booked cost');
check_cost($frozen['postId']==='POST1','Cost lost canonical Post ID');
$ops['values']['tt38kebills']='[]';$orphan=pc_cost_sources($ops,$groups,$store);check_cost(count($orphan)===1&&$orphan[0]['amount']===2000.0,'Missing operational source erased posted cost');
$store['utilityPayments']=['U1'=>['id'=>'U1','entity'=>'TTI','status'=>'Posted','productionCostEligible'=>true,'utilityType'=>'ELECTRICITY','readFrom'=>$bill['readFrom'],'readTo'=>$bill['readTo'],'reference'=>$bill['ref'],'locationName'=>$bill['millName'],'amount'=>2000]];
check_cost(count(pc_cost_sources($ops,$groups,$store))===1,'Same KE bill and recorded utility payment counted twice');
unset($store['utilityPayments']);$store['millLiabilities'][$key]['status']='Reversed';check_cost(pc_cost_sources($ops,$groups,$store)===[],'Reversed liability remained in conversion cost');
$unknown=$bill;unset($unknown['millName']);$ops['values']['tt38kebills']=json_encode([$unknown]);$u=pc_cost_sources($ops,$groups)[0];
check_cost(!$u['inputComplete']&&$u['allocations']===[]&&$u['allocationStatus']==='Mill location unverified','Unknown mill silently assigned to TTI');
$parts=[];for($i=1;$i<=3;$i++)$parts[]=$run('ROUND-'.$i,'2026-10-0'.$i,'TTI Rice Mills','IRRI-6 White Rice',1);
$ops['values']['tt30prod']=json_encode($parts);$bill['amount']=1000;$ops['values']['tt38kebills']=json_encode([$bill]);
$g=pc_group_production(pc_production($ops));$r=pc_cost_sources($ops,$g)[0];check_cost(array_sum($r['allocations'])===1000.0&&array_values($r['allocations'])[2]===333.34,'Rounded allocations did not reconcile to the exact bill');
$ops['values']['tt38labourbills']=json_encode([['id'=>'MAIZE','entity'=>'TTI','millName'=>'TTI Rice Mills','commodity'=>'Makai','from'=>'2026-10-01','till'=>'2026-10-18','amount'=>999999]]);
check_cost(count(pc_cost_sources($ops,$g))===1,'Maize labour contaminated Rice conversion costs');
echo "PASS cost sources: same mill only, immutable booked amount and Post ID, amendment hold, missing-source preservation, KE/payment deduplication, reversal removal, unknown location hold and exact rounded allocation\n";
