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
$out=array_values(array_filter($jobs,static fn($j)=>str_starts_with($j['key'],'container|')));
check(count($out)===1&&str_contains($out[0]['text'],'1 containers loaded out of 2'),'Unfinished containers must not count as out.');
$run=['id'=>1,'entity'=>'TTI','date'=>'2026-11-06','shift'=>'Night','inputBasis'=>'OUTPUT_DERIVED','shiftEntriesComplete'=>true,'rows'=>[['product'=>'Ready Rice — Emrooz','bags'=>20,'bagWeight'=>50],['product'=>'B2','bags'=>10,'bagWeight'=>50]]];
$state['observed']['production|TTI|1']='old';
$ops['values']['tt30prod']=json_encode([$run]);
$jobs=iterator_to_array(tg_notification_jobs($ops,$state,$now));
$pdfs=array_values(array_filter($jobs,static fn($j)=>isset($j['report'])));
check(count($pdfs)===1&&$pdfs[0]['report']['totals']['readyKg']===1000.0,'A late saved prior-day shift must send its actual production.');
$todayAtNine=$now->setTime(9,59);
check(!array_filter(iterator_to_array(tg_notification_jobs($ops,$state,$todayAtNine)),static fn($j)=>str_starts_with($j['key'],'arrival|')||isset($j['morning'])),'Daily reports must respect Pakistan time.');
echo "PASS Telegram timing, stable arrival retry, running totals, saved Exports user, physical container progress and late production PDF\n";
