<?php
declare(strict_types=1);
require_once __DIR__.'/../../api/shipment_estimates_core.php';
function se_check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$seState=['freightAgreements'=>['A1'=>['entity'=>'TTI','contractRef'=>'C1','containerCount'=>2,'dateAgreed'=>'2026-09-01','currency'=>'USD','ratePerContainer'=>1200,'forwarder'=>'Freight Partner']]];
$seRoot=['contracts'=>[['ref'=>'C1','seller'=>'TTI']],'shipments'=>[['id'=>'S1','kind'=>'lot','contractRef'=>'C1','lotId'=>'L1','seller'=>'TTI']]];
$seValues=['tt30ship'=>[['id'=>'M1','_ttShipmentId'=>'S1','status'=>'Completed','completedAt'=>'2026-09-20T15:00:00+05:00','totalContainers'=>2,'containers'=>[['container'=>'TSTU1234567'],['container'=>'TSTU7654321']]]]];
$original=[$seState,$seRoot,$seValues];$f=se_freight($seState,$seRoot,$seValues,'2026-09-30');
se_check(count($f['rows'])===1&&$f['rows'][0]['amount']===-2400.,'completed freight uses saved rate and actual containers');
se_check($f['rows'][0]['currency']==='USD'&&$f['rows'][0]['quantity']===2,'original currency and quantity retained');
se_check($original===[$seState,$seRoot,$seValues],'estimate reads do not mutate books or operations');
se_check(!se_freight($seState,$seRoot,$seValues,'2026-09-19')['rows'],'loading cutoff respected');
$notComplete=$seValues;$notComplete['tt30ship'][0]['status']='Loading';se_check(!se_freight($seState,$seRoot,$notComplete,'2026-09-30')['rows'],'plan without completion creates no freight');
$partial=$seState;$partial['supplierBills']['B1']=['id'=>'B1','entity'=>'TTI','category'=>'FREIGHT','status'=>'Posted','billDate'=>'2026-09-22','postingJournalIds'=>['J1'],'sourceRecord'=>['shipmentId'=>'S1','containerCount'=>1]];$partial['journals']['J1']=['status'=>'Posted','date'=>'2026-09-22'];
se_check(se_freight($partial,$seRoot,$seValues,'2026-09-30')['rows'][0]['amount']===-1200.,'partial actual bill removes only its covered container');
se_check(se_freight($partial,$seRoot,$seValues,'2026-09-21')['rows'][0]['amount']===-2400.,'later invoice does not remove earlier estimate');
$partial['supplierBills']['B1']['status']='Cancelled';se_check(se_freight($partial,$seRoot,$seValues,'2026-09-30')['rows'][0]['amount']===-2400.,'cancelled actual restores freight');
$partial['supplierBills']['B1']['status']='Posted';$partial['supplierBills']['B1']['sourceRecord']['containerCount']=2;se_check(!se_freight($partial,$seRoot,$seValues,'2026-09-30')['rows'],'fully invoiced freight has no forecast');
$fullNoAgreement=$partial;unset($fullNoAgreement['freightAgreements']);se_check(!se_freight($fullNoAgreement,$seRoot,$seValues,'2026-09-30')['rows'],'already invoiced freight does not demand missing old agreement');
$missing=se_freight([],$seRoot,$seValues,'2026-09-30');se_check($missing['rows'][0]['amount']===null&&count($missing['warnings'])===1,'missing agreement is unresolved not zero');
$short=$seState;$short['freightAgreements']['A1']['containerCount']=1;$r=se_freight($short,$seRoot,$seValues,'2026-09-30');se_check(count($r['rows'])===2&&$r['rows'][0]['amount']===null,'uncovered containers keep a missing amount visible');
$tgRoot=$seRoot;$tgRoot['contracts'][0]['seller']='TG';$tgRoot['contracts'][0]['customsExporter']='TTI';$tgRoot['shipments'][0]['seller']='TG';$tgState=$seState;$tgState['freightAgreements']['A1']['entity']='TG';$r=se_freight($tgState,$tgRoot,$seValues,'2026-09-30');se_check(count($r['rows'])===1&&$r['rows'][0]['entity']==='TTI'&&$r['rows'][0]['amount']===-2400.,'Directors TG planning agreement flows once into actual Pakistan shipment books');
$ex=$seValues;$ex['tt30ship']=[];$ex['tt40exinstructions']=[['id'=>'EX1','shipmentId'=>'S1','loadingComplete'=>true,'completedAt'=>'2026-09-20','totalContainers'=>2]];$ex['tt35exload']=[['instructionId'=>'EX1','container'=>'TSTU1234567'],['instructionId'=>'EX1','container'=>'TSTU7654321']];se_check(se_freight($seState,$seRoot,$ex,'2026-09-30')['rows'][0]['amount']===-2400.,'Ex-Mill loading complete uses same rule');
$slips=[];foreach(['RICE','CORN','WHEAT','SESAME'] as $n=>$commodity)$slips[]=['entity'=>'TTI','slip'=>'P'.($n+1),'bridgeId'=>'R'.($n+1),'millId'=>'M','unloadingDate'=>'2026-09-20','party'=>'Grower','commodity'=>$commodity,'purchaseRate'=>100+$n,'payableWeight'=>1000,'truck'=>'T'.($n+1)];
$p=se_pohanch([],['tt30slips'=>$slips],'2026-09-30');se_check(count($p['rows'])===4&&$p['rows'][2]['amount']===-102000.,'all four commodity Pohanch use saved SODA rates');
$slips[]=$slips[0];se_check(count(se_pohanch([],['tt30slips'=>$slips],'2026-09-30')['rows'])===4,'same issued source cannot count twice');
$posted=['journals'=>['JP'=>['entity'=>'TTI','status'=>'Posted','date'=>'2026-09-20']],'events'=>[['journalId'=>'JP','entity'=>'TTI','eventType'=>'COMMODITY_RECEIPT_ACCEPTED','sourceKey'=>'POHANCH|M|R1']]];se_check(count(se_pohanch($posted,['tt30slips'=>$slips],'2026-09-30')['rows'])===3,'native GRNI event is not duplicated');
se_check(!se_pohanch([],['tt30slips'=>$slips],'2026-09-19')['rows'],'future Pohanch not included');
$manual=$seState;$manual['estimatedExpenseBills']=[['entity'=>'TTI','sourceKey'=>'FREIGHT|TTI|S1','date'=>'2026-09-20','status'=>'Open']];$source=shipment_estimates_source(['rows'=>[],'warnings'=>[]],$manual,$seRoot,$seValues,'2026-09-30');se_check(!$source['rows'],'explicit linked manual forecast owns automatic source');
$manual['estimatedExpenseBills'][0]['status']='Replaced';se_check(!shipment_estimates_source(['rows'=>[]],$manual,$seRoot,$seValues,'2026-09-30')['rows'],'replaced manual source cannot revive duplicate automatic estimate');
$manual['estimatedExpenseBills'][0]['status']='Cancelled';$manual['estimatedExpenseBills'][0]['cancelDate']='2026-09-25';se_check(count(shipment_estimates_source(['rows'=>[]],$manual,$seRoot,$seValues,'2026-09-30')['rows'])===1,'cancelled override restores underlying operational forecast');
echo "PASS shipment estimates: four commodities, native GRNI deduplication, Accounts/Directors agreed freight, mill completion, cutoff, partial actuals, cancellation, missing evidence and immutable source books\n";

$posted['journals']['JP']['date']='2026-10-01';se_check(count(se_pohanch($posted,['tt30slips'=>$slips],'2026-09-30')['rows'])===4,'later bridge cannot suppress earlier pending forecast');
$posted['journals']['JP']['date']='2026-09-20';$posted['journals']['JP']['reversedByPostId']='JR';se_check(count(se_pohanch($posted,['tt30slips'=>$slips],'2026-09-30')['rows'])===4,'reversed bridge cannot suppress current unbilled source');
se_check(se_day('2026-02-30')===''&&se_day('yesterday')==='','invalid or relative dates cannot become completion evidence');
$override=$short;$override['estimatedExpenseBills']=[['entity'=>'TTI','sourceKey'=>'FREIGHT|TTI|S1','date'=>'2026-09-20','status'=>'Open']];$r=shipment_estimates_source(['rows'=>[],'warnings'=>[]],$override,$seRoot,$seValues,'2026-09-30');se_check(!$r['rows']&&!$r['warnings'],'manual freight override owns uncovered fragment and its warning');
$override=['estimatedExpenseBills'=>$override['estimatedExpenseBills']];$r=shipment_estimates_source(['rows'=>[],'warnings'=>[]],$override,$seRoot,$seValues,'2026-09-30');se_check(!$r['rows']&&!$r['warnings'],'manual freight estimate resolves absent-agreement projection warning');
if(function_exists('gm_cashflow_scope')){$r=gm_cashflow_scope(['basis'=>'Fixture','rows'=>[['account'=>'2210','party'=>'External Supplier','label'=>'External Supplier — TTI shipment','excluded'=>false,'reason'=>'']]]);se_check(!$r['rows'][0]['excluded'],'shipment reference does not redefine external supplier as an internal party');}
echo "PASS estimate review safeguards: posted bridge cutoff, reversals, valid dates, source-specific override warnings and explicit party scope\n";

$nativeText=$seValues;$nativeText['tt30ship'][0]['completedAt']='20 Sep 2026, 04:30:00 pm PKT';se_check(se_freight($seState,$seRoot,$nativeText,'2026-09-30')['rows'][0]['amount']===-2400.,'native Mill PKT completion timestamp is authoritative');
se_check(se_day('27 Oct 2026, 04:30:00 pm PKT')==='2026-10-27'&&se_day('30 Feb 2026, 04:30:00 pm PKT')===''&&se_day('27 Oct 2026, 14:30:00 pm PKT')==='','known native timestamp parses strictly');

se_check(se_day('20 Sept 2026, 04:30:00 pm PKT')==='2026-09-20','native en-GB September abbreviation is supported');
$nativeSept=$seValues;$nativeSept['tt30ship'][0]['completedAt']='20 Sept 2026, 04:30:00 pm PKT';se_check(se_freight($seState,$seRoot,$nativeSept,'2026-09-30')['rows'][0]['amount']===-2400.,'actual native September loading creates freight');
$nativeSept['tt30ship'][0]['completedAt']='';$missingDate=se_freight($seState,$seRoot,$nativeSept,'2026-09-30');se_check(!$missingDate['rows']&&count($missingDate['warnings'])===1,'completed loading with an unknown date cannot silently disappear');
