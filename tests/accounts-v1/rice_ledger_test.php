<?php
declare(strict_types=1);
require __DIR__.'/../../api/rice_ledger_core.php';
function eq($a,$b,$why){if($a!==$b)throw new RuntimeException($why.' '.json_encode([$a,$b]));}
$scope=['entity'=>'TTI','millName'=>'TTI Rice Mills'];$raw='Raw';$ready='Finished';
$slip=fn($id,$date,$kg)=>$scope+['id'=>$id,'arrivalDate'=>$date,'displayName'=>$raw,'payableWeight'=>$kg];
$prod=$scope+['id'=>'P1','date'=>'2026-09-05','recoveryVersion'=>1,'actualInputKg'=>600,'inputStockName'=>$raw,'rows'=>[['product'=>$ready,'systemKg'=>550],['product'=>'Bran','systemKg'=>40],['product'=>'PRIVATE','systemKg'=>999,'managementOnly'=>true]]];
$records=['tt30slips'=>[$slip('A0','2026-08-31',200),$slip('A1','2026-09-01',800),array_replace($slip('X','2026-09-01',9000),['millName'=>'Other Mill']),array_replace($slip('Y','2026-09-01',4000),['entity'=>'BRM'])],'tt30prod'=>[$prod],'tt30ship'=>[$scope+['id'=>'S1','stockSourceName'=>$ready,'containers'=>[['date'=>'2026-09-07','weight'=>60],['date'=>'2026-09-08','weight'=>40]]]]];
function values($records){return array_map(fn($r)=>json_encode($r,JSON_THROW_ON_ERROR),$records);}
function report($records,$f=[]){global $scope;return rl_report(values($records),$scope,$f+['from'=>'2026-09-01','to'=>'2026-09-30','movement'=>'All','product'=>'']);}
function summary($r,$p){return array_values(array_filter($r['summaries'],fn($s)=>$s['product']===$p))[0];}
$r=report($records);eq(summary($r,$raw)['openingKg'],200.0,'Opening before range');eq(summary($r,$raw)['closingKg'],400.0,'Actual production input consumed');eq(summary($r,$ready)['closingKg'],450.0,'Loading consumed finished stock');eq(summary($r,'Bran')['closingKg'],40.0,'Byproduct separate');eq(in_array('PRIVATE',$r['products']),false,'Management row excluded');
foreach(tt_inv_stock(values($records),$scope) as $p=>$kg)eq(summary($r,$p)['closingKg'],$kg,'All dated closing matches canonical stock');
$range=report($records,['from'=>'2026-09-06']);eq(summary($range,$ready)['openingKg'],550.0,'Opening includes earlier production');eq(summary($range,$ready)['closingKg'],450.0,'Range keeps opening');
$before=report($records,['to'=>'2026-09-07']);eq(summary($before,$ready)['closingKg'],490.0,'Individual container dates respected');
$out=report($records,['movement'=>'Outward']);eq(summary($out,$raw)['closingKg'],400.0,'Movement filter cannot change balance');eq($out['rows'][0]['balanceKg'],400.0,'Running balance includes hidden arrivals');
$amend=$records;$amend['tt30prod'][0]['actualInputKg']=650;eq(summary(report($amend),$raw)['closingKg'],350.0,'Amended source reflected without duplicate');unset($amend['tt30ship']);eq(summary(report($amend),$ready)['closingKg'],550.0,'Removed loading restores quantity');
$unknown=$records;$unknown['tt30slips'][]=$slip('MISSING','',50);$u=report($unknown);eq(summary($u,$raw)['closingKg'],400.0,'No invented date');eq(summary($u,$raw)['undatedKg'],50.0,'Unknown date quantity disclosed');eq(summary($u,$raw)['undatedCount'],1,'Undated movement makes dated balance unverified');
$legacy=$records;unset($legacy['tt30prod'][0]['recoveryVersion']);$legacy['tt30prod'][0]['rows']=[['product'=>$ready,'bags'=>11,'bagWeight'=>50]];eq(summary(report($legacy),$raw)['closingKg'],450.0,'Preserve canonical historical inference');eq(summary(report($legacy),$raw)['provisional'],true,'Historical inferred consumption never presented as actual');
foreach(['{broken','{"not":"list"}','[2]'] as $bad){$v=values($records);$v['tt30slips']=$bad;$failed=false;try{rl_report($v,$scope,[]);}catch(Throwable $e){$failed=true;}eq($failed,true,'Malformed source must fail closed');}
$rounded=$records;$rounded['tt30ship'][0]['containers']=[['date'=>'2026-09-07','weight'=>.0006],['date'=>'2026-09-08','weight'=>.0006]];$rr=report($rounded);eq(summary($rr,$ready)['closingKg'],tt_inv_stock(values($rounded),$scope)[$ready],'Component rounding preserves canonical aggregate');
$unowned=['tt30slips'=>[['id'=>'LEGACY','arrivalDate'=>'2026-09-01','displayName'=>$raw,'payableWeight'=>100]]];$ur=report($unowned);eq(summary($ur,$raw)['closingKg'],100.0,'Retain canonical legacy quantities without assigning a new owner');eq(str_contains(summary($ur,$raw)['status'],'company/mill'),true,'Missing source ownership cannot be labelled recorded/verified');eq(summary($ur,$raw)['scopeUnverifiedCount'],1,'Disclose the affected movement count');
$staged=['tt30slips'=>[]];foreach(['RAW','READY','FINISHED'] as $stage)$staged['tt30slips'][]=$scope+['id'=>$stage,'baseVariety'=>'IRRI-6','riceType'=>'White','productStage'=>$stage,'arrivalDate'=>'2026-09-01','payableWeight'=>100];
$stageReport=report($staged);$identities=[];foreach(['RAW','READY','FINISHED'] as $stage){$name=tt_inv_product(['baseVariety'=>'IRRI-6','riceType'=>'White'],$stage);$identities[]=$name;eq(summary($stageReport,$name)['closingKg'],100.0,'Retain the actual arrival stage in its own stock balance');}eq(count(array_unique($identities)),3,'Raw, Ready and Finished identities remain distinct');
echo "PASS Rice Ledger: scoped canonical stock, production, openings, container dates, hidden movements, amendments/removal, provisional/undated history and malformed-source failures.\n";

// Pool the physical quantity projection only. Accounting ownership stays untouched.
$poolRows=[];
foreach([['A','TTI','IRRI-6','TTI Rice Mills',100],['B','BRM','IRRI-6','TTI Rice Mills',200],['C','TTI','C-9','TTI Rice Mills',300],['D','BRM','IRRI-6','Other Mill',900],['E','TTI','','TTI Rice Mills',40]]as[$id,$entity,$variety,$mill,$kg])$poolRows[]=['id'=>$id,'entity'=>$entity,'baseVariety'=>$variety,'riceType'=>'White','millName'=>$mill,'date'=>'2026-11-01','rows'=>[['product'=>'B2 — '.($id==='A'?'ASAS':'AMT'),'bags'=>1,'bagWeight'=>$kg],['product'=>'Powder','bags'=>1,'bagWeight'=>35]]];
$poolValues=values(['tt30prod'=>$poolRows]);$bytes=serialize($poolValues);
$physical=rl_report($poolValues,$scope+['physicalPool'=>true],['movement'=>'All']);
eq(summary($physical,'IRRI-6 White Rice — B2')['closingKg'],300.0,'Same-mill same-variety TTI/BRM ASAS/AMT by-products pool');
eq(summary($physical,'C-9 White Rice — B2')['closingKg'],300.0,'Different varieties stay separate');
eq(summary($physical,'IRRI-6 White Rice — Powder')['closingKg'],70.0,'Different products stay separate and other mills excluded');
eq(summary($physical,'UNASSIGNED VARIETY — B2')['closingKg'],40.0,'Unknown legacy variety is never inferred');
eq(tt_inv_stock($poolValues,$scope)['B2 — ASAS'],100.0,'Company accounting source projection preserved');
eq(isset(tt_inv_stock($poolValues,$scope)['B2 — AMT']),true,'Existing company source names remain intact');
eq(serialize($poolValues),$bytes,'Physical projection does not rewrite sources');
eq(count(array_filter($physical['rows'],fn($r)=>($r['sourceEntity']??'')==='BRM'))>0,true,'Original company remains traceable');
echo "PASS physical pooling: same mill/variety/product across companies and brands, distinct varieties/mills, unassigned legacy identity, immutable accounting sources and source-company trace.\n";
$opening=$scope+['id'=>'OPENSTOCK-TTI-IRRI6-20260701','date'=>'2026-07-01','openingStock'=>true,'baseVariety'=>'IRRI-6','byProducts'=>['B3'=>5000,'CSR'=>2000,'B2 CSR'=>1000,'Sweeping'=>7000]];
$legacyWhite=$scope+['id'=>'LW','date'=>'2026-09-01','variety'=>'IRRI-6 White Rice','rows'=>[['product'=>'B3','bags'=>2,'bagWeight'=>50]]];
$legacyParboil=$scope+['id'=>'LP','date'=>'2026-09-01','baseVariety'=>'IRRI-6','variety'=>'IRRI-6 Parboil Rice','rows'=>[['product'=>'B3','bags'=>4,'bagWeight'=>50]]];
$otherUntyped=array_replace($opening,['id'=>'OTHER','byProducts'=>['B3'=>10]]);
$r=rl_report(values(['tt32stockadj'=>[$opening,$otherUntyped],'tt30prod'=>[$legacyWhite,$legacyParboil]]),$scope+['physicalPool'=>true],['from'=>'2026-09-01','movement'=>'All']);
eq(summary($r,'IRRI-6 White Rice — B3')['openingKg'],5000.0,'Confirmed July opening classified as White');
eq(summary($r,'IRRI-6 White Rice — B3')['closingKg'],5100.0,'Legacy White quantity adds to confirmed opening');
eq(summary($r,'IRRI-6 Parboiled / Sella Rice — B3')['closingKg'],200.0,'Parboiled history stays separate');
eq(summary($r,'IRRI-6 — B3')['closingKg'],10.0,'Unrelated untyped opening remains untyped');

$pending=$scope+['id'=>'TRUCK-1','date'=>'2026-09-01','displayName'=>$raw,'weight'=>1000,'bags'=>20,'brokenKat'=>1];
$estimates=['tt30queue'=>[
    $pending,
    array_replace($pending,['id'=>'REJECTED','rejected'=>true]),
    array_replace($pending,['id'=>'ELSEWHERE','millName'=>'Other Mill']),
]];
$estimateScope=$scope+['arrivalEstimates'=>true,'physicalPool'=>true];
$estimated=rl_report(values($estimates),$estimateScope,[]);
eq(count($estimated['rows']),1,'Only the accepted arrival at this mill contributes');
eq(summary($estimated,$raw)['closingKg'],1000.0,'Estimate uses Arrival Report loading weight');
eq($estimated['rows'][0]['basis'],TT_INV_POHANCH_ESTIMATE,'Estimate is labelled');
eq($estimated['rows'][0]['receiptDetails']['brokenKat'],1.0,'Estimate shows Arrival Report KAT');
eq(tt_inv_stock(values($estimates),$scope),[],'Estimates do not enter confirmed stock');
$estimates['tt30slips']=[array_replace($slip('PH-1','2026-09-01',960),['entity'=>'BRM','queueId'=>'TRUCK-1','unloadingDate'=>'2026-09-02','brokenKat'=>2])];
$actual=rl_report(values($estimates),$estimateScope,[]);
eq(count($actual['rows']),1,'One linked Pohanch replaces the estimate even if it assigns a different owner');
eq(summary($actual,$raw)['closingKg'],960.0,'Closing quantity uses actual payable weight once');
eq(summary($actual,$raw)['arrivalEstimate'],false,'Actual stock clears the estimate status');
eq($actual['rows'][0]['date'],'2026-09-02','Actual unloading date replaces Arrival Report date');
eq($actual['rows'][0]['receiptDetails']['brokenKat'],2.0,'Actual Pohanch KAT replaces the estimate');
echo "PASS accepted arrivals are provisional until linked Pohanch replaces their details\n";
