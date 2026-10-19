<?php
declare(strict_types=1);
require __DIR__.'/../../api/commodity_quantity_core.php';
function cq_check(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
$soda=['id'=>'P1','entity'=>'TTI','sodaNo'=>'26001','sodaDate'=>'2026-09-01','commodity'=>'MAKAI','displayName'=>'Makai','qtyFromKg'=>10000,'qtyToKg'=>12000,'status'=>'Open'];
$contract=['ref'=>'C1','seller'=>'TTI','date'=>'2026-09-01','commodity'=>'CORN','product'=>'Makai','brand'=>'STAR','qty'=>20,'status'=>'Open'];
$arrival=['id'=>'A1','entity'=>'TTI','sourceSodaId'=>'P1','unloadingDate'=>'2026-09-15','payableWeight'=>3000];
$ship=['id'=>'S1','entity'=>'TTI','contractRef'=>'C1','containers'=>[['id'=>'LOAD1','container'=>'ABCD1234567','date'=>'2026-10-01','weight'=>2000]]];
$local=['soda'=>'LS1','entity'=>'TTI','date'=>'2026-09-01','party'=>'Buyer','product'=>'Rice','brand'=>'BLUE','tons'=>5,'closed'=>false,'loads'=>[['bridgeId'=>'L1','gatePassRef'=>'LGP-LS1-1','date'=>'2026-09-10','kg'=>1000],['id'=>'L2','date'=>'2026-10-01','kg'=>500]]];
$books=['revision'=>8,'purchaseSodasV2'=>['P1'=>$soda],'journals'=>['BF'=>['entity'=>'TTI','date'=>'2026-09-01','meta'=>['riceKg'=>99999999,'bags'=>99999]]],'historicalExportOpenings'=>[['entity'=>'TTI','weightKg'=>888888]]];
$values=['transtrade_export_v3_operational'=>json_encode(['contracts'=>[$contract,array_replace($contract,['ref'=>'FOREIGN','seller'=>'BRM','brand'=>'SECRET'])],'shipments'=>[]]),'tt30slips'=>json_encode([$arrival]),'tt30ship'=>json_encode([$ship]),'tt35localsales'=>json_encode([$local,array_replace($local,['soda'=>'HIDDEN','entity'=>'BRM','brand'=>'SECRET'])]),'tt35exportersale'=>'[]'];$values['tt35exload']='[]';$ops=['values'=>$values,'revision'=>4];$before=serialize([$books,$ops]);
$r=cq_report($books,$ops,'TTI','2026-10-01','2026-10-15');$rows=array_column($r['rows'],null,'sourceId');
cq_check(count($rows)===3&&!$r['sourceHolds'],'Only the selected company original commitments are visible');
cq_check($rows['PURCHASE|TTI|P1']['movedBeforeMT']===3.&&$rows['PURCHASE|TTI|P1']['movedPeriodMT']===0.&&$rows['PURCHASE|TTI|P1']['outstandingMinMT']===7.,'Original purchase range, prior receipts and outstanding retained');
cq_check($rows['SALE|TTI|C1']['movedPeriodMT']===2.&&$rows['SALE|TTI|C1']['outstandingMinMT']===18.&&$rows['SALE|TTI|C1']['brand']==='STAR','Export quantity and original brand');
cq_check($rows['tt35localsales|LS1']['movedBeforeMT']===1.&&$rows['tt35localsales|LS1']['movedPeriodMT']===.5&&$rows['tt35localsales|LS1']['outstandingMinMT']===3.5,'Local loading split across periods');
cq_check(!str_contains(json_encode($r),'SECRET')&&!str_contains(json_encode($r),'99999999')&&serialize([$books,$ops])===$before,'No foreign data leak, financial BF quantities or writes');
$closed=$ops;$closed['values']['transtrade_export_v3_operational']=json_encode(['contracts'=>[array_replace($contract,['status'=>'Completed'])],'shipments'=>[]]);cq_check(cq_report($books,$closed,'TTI','2026-10-01','2026-10-15')['rows'][1]['outstandingMinMT']===0.,'Completed sale has no new physical requirement');
$bad=$ops;$bad['values']['tt35localsales']=json_encode([array_replace($local,['loads'=>[['id'=>'L1','date'=>'','kg'=>1000]]])]);$held=cq_report($books,$bad,'TTI','2026-10-01','2026-10-15');$heldRows=array_column($held['rows'],null,'sourceId');cq_check($heldRows['tt35localsales|LS1']['movedToDateMT']===null&&$held['sourceHolds'],'Undated loads never become verified zero');
$bad=$ops;$bad['values']['tt30slips']=json_encode([$arrival,$arrival]);try{cq_report($books,$bad,'TTI','2026-10-01','2026-10-15');throw new RuntimeException('Duplicate accepted');}catch(DomainException){}
$missing=$ops;unset($missing['values']['tt30slips']);$missingRows=array_column(cq_report($books,$missing,'TTI','2026-10-01','2026-10-15')['rows'],null,'sourceId');cq_check($missingRows['PURCHASE|TTI|P1']['movedToDateMT']===null&&$missingRows['PURCHASE|TTI|P1']['outstandingMinMT']===null,'Missing source is not a verified zero');
echo "PASS commodity quantities: original purchase/export/local sources, company isolation, date splits, brands, closed requirements, duplicate/undated holds, financial BF exclusion and zero writes\n";
