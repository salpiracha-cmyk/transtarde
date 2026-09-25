<?php
declare(strict_types=1);
$sessionDir=sys_get_temp_dir().'/tt-inventory-test-'.bin2hex(random_bytes(6));mkdir($sessionDir,0700);session_save_path($sessionDir);
register_shutdown_function(static function()use($sessionDir):void{if(session_status()===PHP_SESSION_ACTIVE)session_write_close();foreach(glob($sessionDir.'/*')?:[]as$file)unlink($file);rmdir($sessionDir);});
require __DIR__.'/../../auth_store.php';
require_once __DIR__.'/../../inventory_reconciliation.php';
$count=0;
function check(bool $ok,string $description):void {global$count;$count++;if(!$ok)throw new RuntimeException('FAIL '.$description);}
function equal(mixed $actual,mixed $expected,string $description):void{check((is_int($actual)||is_float($actual))&&(is_int($expected)||is_float($expected))?abs((float)$actual-(float)$expected)<0.0000001:$actual===$expected,$description.'; actual='.json_encode($actual).' expected='.json_encode($expected));}
function rejects(callable $fn,string $description):void{try{$fn();}catch(DomainException|InvalidArgumentException $e){check(true,$description);return;}check(false,$description);}
$owner=['role'=>'Super Admin','username'=>'qaowner'];
$mill=['role'=>'Mill Manager','permissions'=>['Mill'=>['stock'=>['View','Create'],'production'=>['View','Create','Edit'],'export'=>['View','Create']]]];
$accounts=['role'=>'Accounts Operator','permissions'=>['Accounts'=>['reports'=>['View'],'entity-tti'=>['View','Create']]]];
$director=['role'=>'Director','permissions'=>['Directors'=>['reports'=>['View']]]];
check(!tt_inv_can_report($mill),'Mill role cannot see Ghati');
check(!tt_inv_can_report(['role'=>'Exports','permissions'=>['Exports'=>'all']]),'Exports cannot see Ghati');
check(!tt_inv_can_report(['role'=>'Mill','master_access'=>true,'master_permissions'=>['companies'=>['View']],'permissions'=>['Mill'=>'all']]),'Master visibility is not financial report permission');
check(tt_inv_can_report($accounts,'TTI'),'Permitted Accounts report');
check(!tt_inv_can_report($accounts,'BRM'),'Accounts report is company-scoped');
check(tt_user_can_access_entity(['role'=>'Accounts Operator','permissions'=>['Accounts'=>['reports'=>['View']]]],'BRM','View'),'Accounts operator without explicit entity limits can open each group company');
check(!tt_user_can_access_entity($accounts,'BRM','View'),'Explicit Super Admin company scope still limits other books');
check(tt_inv_can_report($director,'BRM'),'Directors report access');
check(tt_inv_can_report($owner,'TTI'),'Owner report access');
$scope=['entity'=>'TTI','millId'=>2,'millName'=>'Other Mill'];
$stock='IRRI-6 White Rice — ASAS';$rawStock='IRRI-6 White Raw Rice';
function values(array $rows):array{return array_map('tt_inv_json',$rows);}
function loadFixture(array $scope,string $stock):array{return values([
 'tt30slips'=>[$scope+['id'=>1,'baseVariety'=>'IRRI-6','riceType'=>'White','productStage'=>'RAW','payableWeight'=>100000,'purchaseRate'=>100,'unloadingDate'=>'2026-09-21']],
 'tt30ship'=>[$scope+['id'=>2,'contractRef'=>'QA-CONTRACT','_ttLotId'=>'QA-LOT','_ttShipmentId'=>'QA-SHIPMENT','baseVariety'=>'IRRI-6','riceType'=>'White','brand'=>'ASAS','containers'=>[['id'=>3,'container'=>'TEST1234567','weight'=>25000,'loadingAt'=>'2026-09-21T07:00:00Z','productionShiftDate'=>'2026-09-21','productionShift'=>'Day']]]]
]);}
function production(array $scope,int $id,float $kg,bool $complete,string $brand='ASAS',string $date='2026-09-21',string $type='White'):array{
 return $scope+['id'=>$id,'date'=>$date,'shift'=>'Day','baseVariety'=>'IRRI-6','riceType'=>$type,'inputStage'=>'RAW','shiftEntriesComplete'=>$complete,
 'rows'=>[['product'=>'Ready Rice — '.$brand,'bags'=>$kg/50,'bagWeight'=>50]]];
}
function writeSource(array $v,string $key,array $rows,string $at):array{global$owner;$v[$key]=tt_inv_prepare_write($v,$key,tt_inv_json($rows),$owner,$at);return tt_inv_reconcile($v,$at);}
function confirm(array $v,array $scope,string $stock,string $id='pc1',string $at='2026-09-21T08:00:00Z'):array{
 return writeSource($v,TT_INV_CONFIRMATIONS,[$scope+['id'=>$id,'stockName'=>$stock,'physicalKg'=>0,'snapshotKg'=>999999,'status'=>'Resolved']],$at);
}
$v=loadFixture($scope,$stock);
equal(tt_inv_stock($v,$scope)[$stock],-25000.0,'Actual loading can precede production');
equal(tt_inv_rows($v,'tt34ghati'),[],'Loading itself creates no Ghati');
$v=confirm($v,$scope,$stock);$c=tt_inv_rows($v,TT_INV_CONFIRMATIONS)[0];
equal($c['snapshotKg'],-25000.0,'Confirmation snapshot is computed server-side, not client-supplied');
equal($c['status'],'Awaiting current-shift production','Physical NIL waits for shift entries');
equal(tt_inv_rows($v,'tt34ghati'),[],'No immediate gain from an unentered current shift');
$v=writeSource($v,'tt30prod',[production($scope,10,10000,false)],'2026-09-21T08:10:00Z');
equal(tt_inv_rows($v,TT_INV_CONFIRMATIONS)[0]['status'],'Awaiting current-shift production','First partial lot does not finalize the shift');
equal(tt_inv_rows($v,'tt34ghati'),[],'Partial lot has no premature financial variance');
$reports=tt_inv_rows($v,'tt30prod');$reports[]=production($scope,11,15000,true);
$v=writeSource($v,'tt30prod',$reports,'2026-09-21T12:00:00Z');
equal(tt_inv_rows($v,TT_INV_CONFIRMATIONS)[0]['status'],'Resolved','Completed current shift reconciles');
equal(tt_inv_rows($v,'tt34ghati'),[],'Fully explained loading creates neither loss nor gain');
equal(tt_inv_rows($v,'tt32stockadj'),[],'No adjustment when current production explains the load');
equal(tt_inv_stock($v,$scope)[$stock],0.0,'Explained physical stock reconciles to zero');
$replay=writeSource($v,TT_INV_CONFIRMATIONS,tt_inv_rows(tt_inv_public_values($v)['values'],TT_INV_CONFIRMATIONS),'2026-09-21T12:01:00Z');
equal($replay,$v,'Filtered confirmation replay is an exact no-op');

$pending=confirm(loadFixture($scope,$stock),$scope,$stock);
$absent=writeSource($pending,'tt30prod',[production($scope,20,1000,true,'DIFFERENT')],'2026-09-21T12:00:00Z');
$events=tt_inv_rows($absent,'tt34ghati');equal(count($events),1,'Missing brand after final shift records one variance');
equal($events[0]['kind'],'gain','Physically loaded unreported rice is a gain');equal($events[0]['kg'],25000.0,'Unexplained excess quantity correct');
equal(tt_inv_stock($absent,$scope)[$stock],0.0,'One gain reconciles the exhausted physical product');
equal(tt_inv_stock($absent,$scope)[$rawStock],99000.0,'Gain does not consume Raw Rice a second time');
equal(tt_inv_reconcile($absent,'2026-09-21T12:01:00Z'),$absent,'Reconciliation retry creates no duplicate event or adjustment');
$carry=$absent;$prior=tt_inv_rows($carry,'tt34ghati');array_unshift($prior,$scope+['id'=>'old','ref'=>'old','kind'=>'shortage','kg'=>10000]);$carry['tt34ghati']=tt_inv_json($prior);
$statement=tt_inv_statement($carry,'TTI');equal($statement['ghatiKg'],0.0,'Gain first offsets existing Ghati');equal($statement['carryForwardGainKg'],15000.0,'Unused gain carries forward');
$prior=tt_inv_rows($carry,'tt34ghati');$prior[]=$scope+['id'=>'next','ref'=>'next','kind'=>'shortage','kg'=>18000];$carry['tt34ghati']=tt_inv_json($prior);$statement=tt_inv_statement($carry,'TTI');
equal($statement['ghatiKg'],3000.0,'Carry-forward gain offsets a later shortage');equal($statement['carryForwardGainKg'],0.0,'Carry-forward gain consumed only once');

$partial=writeSource($pending,'tt30prod',[production($scope,30,10000,false)],'2026-09-21T08:30:00Z');
$r=tt_inv_rows($partial,'tt30prod');$r[]=production($scope,31,1000,true,'DIFFERENT');$partial=writeSource($partial,'tt30prod',$r,'2026-09-21T12:00:00Z');
equal(tt_inv_rows($partial,'tt34ghati')[0]['kg'],15000.0,'Only the unexplained remainder is gain');
$wrong=writeSource($pending,'tt30prod',[production($scope,40,25000,true,'ASAS2')],'2026-09-21T12:00:00Z');
equal(tt_inv_rows($wrong,'tt34ghati')[0]['kg'],25000.0,'Substring brand cannot explain another brand');
$wrong=writeSource($pending,'tt30prod',[production($scope,41,25000,true,'ASAS','2026-09-21','Steam')],'2026-09-21T12:00:00Z');
equal(tt_inv_rows($wrong,'tt34ghati')[0]['kg'],25000.0,'Same brand with another Rice Type does not cancel variance');
$future=writeSource($pending,'tt30prod',[production($scope,50,25000,true,'ASAS','2026-09-22')],'2026-09-22T12:00:00Z');
equal(tt_inv_rows($future,'tt34ghati'),[],'Future shift does not explain or finalize the old shift');
$other=$scope;$other['millId']=9;$other['millName']='Unrelated Mill';
$wrong=writeSource($pending,'tt30prod',[production($other,51,25000,true)],'2026-09-21T12:00:00Z');
equal(tt_inv_rows($wrong,'tt34ghati'),[],'Another mill cannot complete or explain the current shift');

$rows=tt_inv_rows($absent,'tt30prod');$rows[]=production($other,60,0,true,'ASAS','2026-09-22');$next=writeSource($absent,'tt30prod',$rows,'2026-09-22T07:00:00Z');
equal(tt_inv_rows($next,'tt34nilqueue')[0]['status'],'Pending Production Report','Other mill cannot take fixed reconciliation row');
$rows=tt_inv_rows($next,'tt30prod');$rows[]=production($scope,61,0,true,'ASAS','2026-09-22');$next=writeSource($next,'tt30prod',$rows,'2026-09-22T07:10:00Z');$fixed=tt_inv_rows($next,'tt34nilqueue')[0];
equal($fixed['productionId'],61,'Fixed row is linked to first subsequent report at the correct mill');
check($fixed['systemFixed']&&$fixed['noStockPost']&&$fixed['managementOnly'],'Fixed row is informational and private');
equal(tt_inv_stock($next,$scope)[$stock],0.0,'Fixed row adds no finished stock');equal(tt_inv_stock($next,$scope)[$rawStock],99000.0,'Fixed row consumes no Raw Rice');
equal(tt_inv_reconcile($next,'2026-09-22T08:00:00Z'),$next,'Fixed row does not reattach or duplicate');
$amended=tt_inv_rows($next,'tt30prod');$amended[0]['rows'][0]['bags']=30;$amended=writeSource($next,'tt30prod',$amended,'2026-09-22T08:10:00Z');
# Amend the reconciled brand specifically to test immutable finalized variance and visible review.
$r=tt_inv_rows($absent,'tt30prod');$r[0]['rows'][]=['product'=>'Ready Rice — ASAS','bags'=>10,'bagWeight'=>50];$amended=writeSource($absent,'tt30prod',$r,'2026-09-22T08:11:00Z');
check(!empty(tt_inv_rows($amended,TT_INV_CONFIRMATIONS)[0]['reviewRequired']),'Post-reconciliation source amendment is flagged to management');
equal(count(tt_inv_rows($amended,'tt34ghati')),1,'Source amendment cannot silently post another gain');

$raw=values(['tt30slips'=>[$scope+['id'=>1,'baseVariety'=>'IRRI-6','riceType'=>'White','productStage'=>'RAW','payableWeight'=>10000],['id'=>2,'entity'=>'TTI','millName'=>'TTI Rice Mills','baseVariety'=>'IRRI-6','riceType'=>'White','productStage'=>'RAW','payableWeight'=>20000],array_replace($scope,['id'=>3,'entity'=>'BRM','baseVariety'=>'IRRI-6','riceType'=>'White','productStage'=>'RAW','payableWeight'=>90000])]]);
$raw=confirm($raw,$scope,$rawStock,'raw1');equal(tt_inv_stock($raw,$scope)[$rawStock],0.0,'Raw physical NIL applies only at selected mill');
equal(tt_inv_stock($raw,['entity'=>'TTI','millName'=>'TTI Rice Mills'])[$rawStock],20000.0,'Default mill unaffected by another mill confirmation');
$brm=$scope;$brm['entity']='BRM';equal(tt_inv_stock($raw,$brm)[$rawStock],90000.0,'Other legal owner stock unaffected');

$ready=loadFixture($scope,$stock);$ready['tt30slips']=tt_inv_json([$scope+['id'=>1,'baseVariety'=>'IRRI-6','riceType'=>'White','productStage'=>'READY','payableWeight'=>100000]]);
equal(tt_inv_stock($ready,$scope)['IRRI-6 White Ready Rice'],75000.0,'Ready receipt is consumed by its matching loading source');

$secret=$next;$secret['tt32processingrecon']=tt_inv_json([['value'=>'SECRET_GHATI']]);$secret['tt34ghati']=tt_inv_json([['value'=>'SECRET_GHATI']]);
$p=production($scope,70,100,false);$p['rows'][]=['id'=>'priv','product'=>'SECRET_GHATI','systemFixed'=>true,'noStockPost'=>true,'kg'=>99];$secret['tt30prod']=tt_inv_json([$p]);
$visible=tt_inv_public_values($secret,array_fill_keys(TT_INV_PRIVATE_KEYS,['value'=>'SECRET_GHATI']));
check(!str_contains(tt_inv_json($visible),'SECRET_GHATI'),'Operational payload and metadata do not expose private reconciliation');
foreach(TT_INV_PRIVATE_KEYS as$key)check(!isset($visible['values'][$key]),'Private key absent: '.$key);
equal(count(tt_inv_rows($visible['values'],'tt30prod')[0]['rows']),1,'Mill production rows exclude fixed management row');
$restored=tt_inv_prepare_write($secret,'tt30prod',$visible['values']['tt30prod'],$mill,'2026-09-22T09:00:00Z');
equal(count(json_decode($restored,true)[0]['rows']),2,'Filtered save preserves private historical fixed row on server');
rejects(static fn()=>tt_inv_prepare_write($secret,'tt34ghati','[]',$mill,'2026-09-22T09:00:00Z'),'Mill cannot directly write Ghati');
$evil=tt_inv_rows($visible['values'],'tt32stockadj');$evil[0]['readyRiceKg']=999999;
rejects(static fn()=>tt_inv_prepare_write($secret,'tt32stockadj',tt_inv_json($evil),$mill,'2026-09-22T09:00:00Z'),'Client cannot change server-owned stock adjustment');
rejects(static fn()=>tt_inv_date('2026-02-31'),'Impossible calendar date rejected');
rejects(static fn()=>tt_inv_kg('not-a-number'),'Non-numeric weight rejected');
rejects(static fn()=>tt_inv_kg(-1),'Negative physical quantity rejected');
equal(tt_inv_shift_at('2026-09-21T20:30:00Z'),['date'=>'2026-09-21','shift'=>'Night'],'After midnight belongs to previous night shift');
equal(tt_inv_shift_at('2026-09-21T01:00:00Z'),['date'=>'2026-09-21','shift'=>'Day'],'Day starts at 06:00 Pakistan');
equal(tt_inv_shift_at('2026-09-21T14:00:00Z')['shift'],'','Between shifts does not invent a production shift');
equal(tt_inv_rate(loadFixture($scope,$stock),'TTI','2026-09-22'),100.0,'Management rate uses ten-day Raw receipt quantity');
check(tt_inv_rate($ready,'TTI','2026-09-22')===null,'Ready receipt cannot supply a guessed Raw Rice rate');

// A subsequent save in the same second is still a distinct subsequent production report.
$rows=tt_inv_rows($absent,'tt30prod');$rows[]=production($scope,90,0,true,'ASAS','2026-09-22');
$sameSecond=writeSource($absent,'tt30prod',$rows,'2026-09-21T12:00:00Z');
equal(tt_inv_rows($sameSecond,'tt34nilqueue')[0]['productionId'],90,'Same-second later report receives the fixed row once');
rejects(static fn()=>tt_inv_prepare_write($sameSecond,'tt30prod',tt_inv_json(tt_inv_rows($absent,'tt30prod')),$mill,'2026-09-22T09:00:00Z'),'Deleting a production report cannot erase its fixed reference');
rejects(static fn()=>tt_inv_prepare_write($secret,'tt30prod','[]',$mill,'2026-09-22T09:00:00Z'),'Deleting legacy production cannot erase private fixed history');
rejects(static fn()=>tt_inv_prepare_write([],'tt30prod','[]',['role'=>'Clerk','permissions'=>['Mill'=>['arrival'=>['View','Create']]]],'2026-09-22T09:00:00Z'),'Arrival-only permission cannot certify production completion');
rejects(static fn()=>tt_inv_prepare_write([],'tt32stockadj',tt_inv_json([['id'=>'spoof','type'=>'Physical Nil fake','rawRiceKg'=>999]]),$mill,'2026-09-22T09:00:00Z'),'Client cannot create a fake old-style financial reconciliation');
$numberOnly=values(['tt30ship'=>[$scope+['id'=>2,'containers'=>[['id'=>3,'container'=>'TEST1234567','weight'=>0]]]]]);
$rows=tt_inv_rows($numberOnly,'tt30ship');$saved=tt_inv_prepare_write($numberOnly,'tt30ship',tt_inv_json($rows),$owner,'2026-09-21T20:30:00Z');
check(!isset(json_decode($saved,true)[0]['containers'][0]['productionShift']),'Number-only container does not pretend loading has been weighed');
$rows[0]['containers'][0]['weight']=25000;$numberOnly['tt30ship']=tt_inv_prepare_write($numberOnly,'tt30ship',tt_inv_json($rows),$owner,'2026-09-21T20:30:00Z');
$stamped=tt_inv_rows($numberOnly,'tt30ship')[0]['containers'][0];equal($stamped['productionShiftDate'],'2026-09-21','Weighed night load retains previous night start date');equal($stamped['productionShift'],'Night','Weighed container stores exact shift');
$rows=tt_inv_rows($numberOnly,'tt30ship');$rows[0]['containers'][0]['productionShift']='Day';$saved=tt_inv_prepare_write($numberOnly,'tt30ship',tt_inv_json($rows),$owner,'2026-09-22T09:00:00Z');
equal(json_decode($saved,true)[0]['containers'][0]['productionShift'],'Night','Editing container cannot silently rewrite the originating shift');
$night=loadFixture($scope,$stock);$nightRows=tt_inv_rows($night,'tt30ship');$nightRows[0]['containers'][0]=array_replace($nightRows[0]['containers'][0],['loadingAt'=>'2026-09-21T20:30:00Z','productionShift'=>'Night']);$night['tt30ship']=tt_inv_json($nightRows);
$night=confirm($night,$scope,$stock,'night','2026-09-22T02:00:00Z');$nc=tt_inv_rows($night,TT_INV_CONFIRMATIONS)[0];equal($nc['shift'],'Night','Morning physical confirmation waits for the night that loaded the cargo');equal($nc['shiftDate'],'2026-09-21','Morning confirmation uses load shift date not new morning');
$night=confirm(loadFixture($scope,$stock),$scope,$stock,'date-local','2026-09-21T20:30:00Z');equal(tt_inv_rows($night,TT_INV_CONFIRMATIONS)[0]['date'],'2026-09-22','Physical confirmation uses Pakistan calendar date');
$rework=values(['tt30slips'=>[$scope+['id'=>1,'baseVariety'=>'IRRI-6','riceType'=>'White','productStage'=>'READY','payableWeight'=>30000]],'tt30prod'=>[array_replace(production($scope,91,25000,true),['inputStockName'=>'IRRI-6 White Ready Rice','inputStage'=>'READY'])]]);
equal(tt_inv_stock($rework,$scope)['IRRI-6 White Ready Rice'],5000.0,'Reprocessing consumes existing Ready Rice');check(!array_key_exists($rawStock,tt_inv_stock($rework,$scope)),'Reprocessing does not invent RAW consumption');
$duplicate=tt_inv_rows($pending,TT_INV_CONFIRMATIONS);$duplicate[]=$scope+['id'=>'pc-another','stockName'=>$stock,'physicalKg'=>0];
rejects(static fn()=>tt_inv_prepare_write($pending,TT_INV_CONFIRMATIONS,tt_inv_json($duplicate),$mill,'2026-09-21T09:00:00Z'),'Same pending physical confirmation cannot be duplicated under another ID');

// A later zero-output or other-brand lot must not falsely reopen a reconciled product.
$r=tt_inv_rows($absent,'tt30prod');$r[]=production($scope,110,0,false);
$zero=writeSource($absent,'tt30prod',$r,'2026-09-21T12:01:00Z');
check(empty(tt_inv_rows($zero,TT_INV_CONFIRMATIONS)[0]['reviewRequired']),'Zero-output next report creates no false management warning');
$r=tt_inv_rows($zero,'tt30prod');$r[]=production($scope,111,500,false,'UNRELATED');
$unrelated=writeSource($zero,'tt30prod',$r,'2026-09-21T12:02:00Z');
check(empty(tt_inv_rows($unrelated,TT_INV_CONFIRMATIONS)[0]['reviewRequired']),'Other-brand production does not reopen this product reconciliation');
equal(count(tt_inv_rows($unrelated,'tt34ghati')),1,'Unrelated later reports cannot duplicate the gain');
equal(tt_inv_rows($unrelated,'tt34nilqueue')[0]['productionId'],110,'Fixed row remains attached to the first subsequent report');
$refs=tt_inv_rows($absent,TT_INV_CONFIRMATIONS)[0]['sourceShipments'];
equal($refs[0]['contractRef'],'QA-CONTRACT','Source Contract copied from the actual saved shipment');
equal($refs[0]['lotRef'],'QA-LOT','Source Lot copied from the actual saved shipment');
equal($refs[0]['shipmentId'],'QA-SHIPMENT','Source operational shipment identity retained');
equal(tt_inv_rows($absent,'tt34ghati')[0]['sourceShipments'],$refs,'Private event retains source references');
equal(tt_inv_rows($absent,'tt34nilqueue')[0]['sourceShipments'],$refs,'Private fixed row retains source references');
$bad=$scope+['id'=>'bad-ref','stockName'=>$stock,'physicalKg'=>0,'sourceShipmentId'=>'WRONG','contractRef'=>'SPOOF'];
rejects(static fn()=>tt_inv_prepare_write(loadFixture($scope,$stock),TT_INV_CONFIRMATIONS,tt_inv_json([$bad]),$mill,'2026-09-21T09:00:00Z'),'Unrelated source shipment cannot be used for stock confirmation');

echo "PASS inventory reconciliation: $count assertions; in-memory fixtures only; temporary sessions removed.\n";
