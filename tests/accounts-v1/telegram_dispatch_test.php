<?php
declare(strict_types=1);
define('TT_DATA_DIR', sys_get_temp_dir().'/tt-telegram-dispatch-fixture');
require dirname(__DIR__,2).'/api/telegram_dispatch_core.php';
function check(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message); }
$now=new DateTimeImmutable('2026-11-07 11:05:00',new DateTimeZone('Asia/Karachi'));
$a=['id'=>1,'date'=>'2026-11-07','vehicle'=>'ABC-123','weight'=>24000,'broken'=>12.5,'chalky'=>4,'moisture'=>14];
$b=$a; $b['id']=2; $b['vehicle']='DEF-456';
$root=['contracts'=>[],'shipments'=>[],'audits'=>[['id'=>'AUD1','at'=>'2026-11-07T05:59:00Z','area'=>'Bag PO','action'=>'Issued','user'=>'Jazib','detail'=>'PO42']]];
$ops=['values'=>['tt30queue'=>json_encode([$a,$b]),'tt30prod'=>'[]','tt30ship'=>'[]','transtrade_export_v3_operational'=>json_encode($root)]];
$state=['since'=>$now->setTime(0,0)->getTimestamp(),'sent'=>[],'observed'=>[],'summaryRows'=>['2026-11-07'=>[$a]]];
$jobs=iterator_to_array(tg_notification_jobs($ops,$state,$now));
$texts=array_column($jobs,'text');
check(str_contains(implode("\n",$texts),'Total trucks today: 1'),'Summary retries must keep their original snapshot.');
check(str_contains(implode("\n",$texts),'Broken % 12.5'),'Arrival quality must be included.');
check(count(array_filter($jobs,static fn($j)=>isset($j['morning'])))===1,'The Directors PDF must become due after 11 a.m.');
check(str_contains(implode("\n",$texts),'Jazib'),'Saved Exports audit must identify the user.');
$state['sent']['arrivals-summary|2026-11-07']=['hash'=>'1'];
$state['sent']['arrival|2026-11-07|1']=['hash'=>tg_hash($a)];
$jobs=iterator_to_array(tg_notification_jobs($ops,$state,$now));
$new=array_values(array_filter($jobs,static fn($j)=>str_starts_with($j['key'],'arrival|')&&($state['sent'][$j['key']]['hash']??null)!==$j['hash']));
check(count($new)===1&&str_contains($new[0]['text'],'DEF-456'),'Only the added truck needs a new alert.');
check(str_contains($new[0]['text'],'Total trucks today now: 2'),'The second truck must include the new running count.');
$shipment=['id'=>1,'contractRef'=>'C1','lotRef'=>'LOT1','brand'=>'Emrooz','totalContainers'=>2,'containers'=>[
 ['container'=>'AAA1','loadingComplete'=>true,'bags'=>500,'weight'=>25000,'truck'=>'ABC-123','seal'=>'S'],
 ['container'=>'AAA2','loadingComplete'=>false],
]];
$ops['values']['tt30ship']=json_encode([$shipment]);
$jobs=iterator_to_array(tg_notification_jobs($ops,$state,$now));
$out=array_values(array_filter($jobs,static fn($j)=>str_starts_with($j['key'],'loading-row|')));
check(count($out)===1&&str_contains($out[0]['text'],'1 containers loaded out of 2'),'Unfinished containers must not count as out.');
$run=['id'=>1,'entity'=>'TTI','date'=>'2026-11-06','shift'=>'Night','inputBasis'=>'OUTPUT_DERIVED','shiftEntriesComplete'=>true,'rows'=>[['product'=>'Ready Rice — Emrooz','bags'=>20,'bagWeight'=>50],['product'=>'B2','bags'=>10,'bagWeight'=>50]]];
$run['updatedAt']='2026-11-07T05:00:00Z';
$ops['values']['tt30prod']=json_encode([array_replace($run,['shiftEntriesComplete'=>false])]);
check(!array_filter(iterator_to_array(tg_notification_jobs($ops,$state,$now)),static fn($job)=>str_starts_with($job['key'],'production|')),'Saving progress must not deliver a final shift report.');
$ops['values']['tt30prod']=json_encode([$run]);
$jobs=iterator_to_array(tg_notification_jobs($ops,$state,$now));
$pdfs=array_values(array_filter($jobs,static fn($j)=>isset($j['report'])));
check(count($pdfs)===1&&$pdfs[0]['report']['totals']['readyKg']===1000.0,'A late saved prior-day shift must send its actual production.');
$todayAtNine=$now->setTime(9,59);
check(!array_filter(iterator_to_array(tg_notification_jobs($ops,$state,$todayAtNine)),static fn($j)=>str_starts_with($j['key'],'arrival|')||isset($j['morning'])),'Daily reports must respect Pakistan time.');
echo "PASS Telegram timing, stable arrival retry, running totals, saved Exports user, physical container progress and late production PDF\n";

$sale=['id'=>'fixture-sale','soda'=>'LS-0042','entity'=>'TTI','date'=>'2026-11-07',
    'party'=>'Fixture buyer','broker'=>'Fixture broker','displayName'=>'IRRI-6 White Rice — Powder',
    'tons'=>3.592,'rate'=>62,'byProductSale'=>true,'billingWeightBasis'=>'GROSS',
    'loads'=>[['date'=>'2026-11-07','truck'=>'ABC-123','container'=>'TGCU233430-4','kg'=>3592,'printed'=>true,'gatePassRef'=>'LGP-42']],
    'pay'=>[
        ['date'=>'2026-11-07','type'=>'Transtrade Bank','amount'=>100000,'where'=>'Meezan Bank — •••••48217'],
        ['date'=>'2026-11-07','type'=>'Third-Party Account','amount'=>122704,'where'=>'Fixture third party'],
    ]];
$ops['values']['tt35localsales']=json_encode([$sale]);
$saleAlerts=static fn(array $state):array=>array_values(array_filter(
    iterator_to_array(tg_notification_jobs($ops,$state,$now)),
    static fn(array $job):bool=>str_starts_with($job['key'],'mill-entry|tt35localsales|')
));
$alert=$saleAlerts($state)[0];
foreach(['LS-0042','IRRI-6 White Rice — Powder','3,592 kg','62 PKR/kg','PKR 222,704.00','LGP-42','Container TGCU233430-4',
    'Meezan Bank — •••••48217','Third-party bank: Fixture third party','Accounts approval'] as $fact)
    check(str_contains($alert['text'],$fact),'Local Sale alert should include '.$fact);
$state['observed'][$alert['key']]=$alert['hash'];
check($saleAlerts($state)===[],'An unchanged saved sale must not resend after a formatter update.');
$sale['byProductSale']=false;$sale['billingWeightBasis']='NET';$sale['loads'][0]['emptyTareKg']=12;
check(str_contains(tg_local_sale_text($sale),'Billable: 3,580 kg'),'Historical net billing keeps its saved tare.');
echo "PASS Local Sale details, split payments, deposit destinations, legacy weight and alert deduplication\n";


// Reused equipment must not alternate one sent fingerprint forever.
$loads=[
 ['id'=>'load-a','date'=>'2026-11-11','c'=>'HPCU242094-4','t'=>'E-8717','b'=>625,'net'=>25007.5],
 ['id'=>'load-b','date'=>'2026-11-14','c'=>'HPCU242094-4','t'=>'CAX-5430','b'=>625,'net'=>25017.5],
];
$repeatOps=['values'=>['tt35exportersale'=>json_encode([['id'=>'sale-one','brand'=>'emrooz','containers'=>$loads]]),
 'transtrade_export_v3_operational'=>'{}']];
$repeatState=['since'=>$state['since'],'sent'=>[],'observed'=>[]];
$loadingJobs=static fn(array $ops,array $state):array=>array_values(array_filter(
 iterator_to_array(tg_notification_jobs($ops,$state,$now)),
 static fn(array $job):bool=>str_starts_with($job['key'],'loading-row|')
));
$simulate=static function(array $jobs,array &$state):int {
 $sent=0;
 foreach($jobs as $job) {
  if(($state['sent'][$job['key']]['hash']??null)===$job['hash'])continue;
  if(isset($state['pending'][$job['key']])||isset($state['pending'][$job['legacyKey']??'']))continue;
  if(empty($job['markOnly']))$sent++;
  $state['sent'][$job['key']]=['hash'=>$job['hash']];
 }
 return $sent;
};
$initial=$loadingJobs($repeatOps,$repeatState);
check(count(array_unique(array_column($initial,'key')))===2,'Two saved loads using the same equipment need separate delivery identities.');
check(str_contains($initial[0]['text'],'Loading date 2026-11-11'),'Repeated equipment alerts must identify the loading date.');
check(!str_contains($initial[0]['text'],'? planned'),'An unknown loading target must not be printed as a broken reference.');
check($simulate($initial,$repeatState)===2,'Both new loads should notify once.');
for($i=0;$i<3;$i++)check($simulate($loadingJobs($repeatOps,$repeatState),$repeatState)===0,'Unchanged reused containers must not repeat on later worker runs.');
$repeatOps['values']['tt35exportersale']=json_encode([['id'=>'sale-one','containers'=>array_reverse($loads)]]);
check($simulate($loadingJobs($repeatOps,$repeatState),$repeatState)===0,'Reordering loads must not resend them.');
$loads[0]['net']=25010;
$repeatOps['values']['tt35exportersale']=json_encode([['id'=>'sale-one','containers'=>$loads]]);
check($simulate($loadingJobs($repeatOps,$repeatState),$repeatState)===1,'A genuine weight amendment must notify only its saved loading.');
check($simulate($loadingJobs($repeatOps,$repeatState),$repeatState)===0,'The amended loading must not notify repeatedly.');
$loads[]=array_replace($loads[0],['id'=>'load-c','date'=>'2026-11-16']);
$repeatOps['values']['tt35exportersale']=json_encode([['id'=>'sale-one','containers'=>$loads]]);
check($simulate($loadingJobs($repeatOps,$repeatState),$repeatState)===1,'A new load of previously used equipment must still notify.');
$legacyState=['since'=>$state['since'],'observed'=>[],'sent'=>[$initial[1]['legacyKey']=>['hash'=>$initial[1]['hash']]]];
$jobs=$loadingJobs($repeatOps,$legacyState);
check(!empty($jobs[1]['markOnly'])&&empty($jobs[0]['markOnly'])&&empty($jobs[2]['markOnly']),'Only an exact previously confirmed legacy row may migrate silently.');
$pendingState=$legacyState;
$pendingState['pending'][$initial[1]['legacyKey']]=['hash'=>$initial[1]['hash']];
check($simulate($loadingJobs($repeatOps,$pendingState),$pendingState)===0,'Uncertain legacy delivery must remain held for review.');
check($simulate($jobs,$legacyState)===2,'Migration must not suppress other unconfirmed loads with the same equipment.');
check($simulate($loadingJobs($repeatOps,$legacyState),$legacyState)===0,'After migration, all loading identities must remain stable.');
$aLegacy=['container'=>'HPCU242094-4','date'=>'2026-11-11','truck'=>'E-8717','weight'=>25000];
$bLegacy=array_replace($aLegacy,['date'=>'2026-11-14','truck'=>'CAX-5430']);
check(tg_loading_key('s',$aLegacy)!==tg_loading_key('s',$bLegacy),'ID-less historical trips must remain separate.');
check(tg_loading_key('s',$aLegacy)===tg_loading_key('s',array_replace($aLegacy,['weight'=>25100])),'Legacy weight edits must keep their loading identity.');
check(tg_loading_key('s',$aLegacy)!==tg_loading_key('another-sale',$aLegacy),'Different sales must not share a loading identity.');
echo "PASS reused-container delivery, repeated runs, edits, new loads, legacy migration and uncertain-delivery hold\n";
