<?php
declare(strict_types=1);
require __DIR__.'/../../api/outside_export_loading_core.php';
require __DIR__.'/../../api/directors_trading_core.php';
require __DIR__.'/../../api/bank_entries_core.php';
function tt_user_can_open_module(array $u,string $m):bool{return $u['module']===$m;}
function tt_user_can_module_action(array $u,string $m,string $i,string $a):bool{return $u['module']===$m;}
function tt_user_business_entity(array $u,string $e,string $a):bool{return $e==='TTI';}
function tt_managed_qa_write_blocked(array $u):bool{return false;}
function tt_find_user_by_id(int $id):?array{return $id===2?['id'=>2,'module'=>'Mill','full_name'=>'Inspector','active'=>true]:null;}
function tt_next_post_id(array $rows,...$args){return 'POST-'.(count($rows)+1);}
function ok($v,$m){if(!$v)throw new RuntimeException($m);}
function reject(callable $f,$m){try{$f();}catch(DomainException){return;}throw new RuntimeException($m);}
$export=['id'=>1,'module'=>'Exports','full_name'=>'Jazib','active'=>true];
$mill=tt_find_user_by_id(2);
$terms=['entity'=>'TTI','date'=>'2026-10-01','direction'=>'PURCHASE','arrangement'=>'PARTNERSHIP_MARGIN','partyId'=>'KHAN','currency'=>'USD','price'=>590,'quantityMT'=>27,'incoterm'=>'CNF','fx'=>280,'contractRef'=>'AMT','freightEstimateUSDPerMT'=>0,'fobEstimateUSDPerMT'=>0,'bankEstimatePct'=>0,'privateMarginUSDPerMT'=>20,'partnerSharePct'=>50];
$deal=dt_deal($terms,[['id'=>'KHAN','name'=>'Khan Rice Mills']])+['id'=>'D1','loadings'=>[],'settlementPosts'=>[],'payments'=>[]];
$contract=['ref'=>'AMT','seller'=>'TG','customsExporter'=>'TTI','currency'=>'USD','quality'=>'Maximum 5% broken','packings'=>[['brand'=>'AMT','size'=>25,'contractRate'=>610]]];
$lot=['id'=>'L1','kind'=>'lot','seller'=>'TG','customs'=>['exporter'=>'TTI'],'contractRef'=>'AMT','lotId'=>'AMT/L1','containers'=>1,'millActuals'=>[],'loadingPlan'=>['outsideDirect'=>true,'loadingDate'=>'2026-10-05','allocations'=>[['name'=>'Khan Rice Mills, Punjab','type'=>'Ex-Mill','packIndex'=>0,'containers'=>1]]]];
$root=['contracts'=>[$contract],'shipments'=>[$lot]];
$preLotContract=array_replace($contract,['ref'=>'AMT14','customsExporter'=>'']);
$preLotRoot=['contracts'=>[$preLotContract],'shipments'=>[['id'=>'P14','contractRef'=>'AMT14','customs'=>['exporter'=>'TTI']]]];
ok(count(dt_contracts($preLotRoot,[],'TTI'))===1&&dt_contracts($preLotRoot,[],'BRM')===[],'A TG process exposes its saved Pakistani exporter’s contract before the first lot');

$deals=['D1'=>$deal];
$assign=['lotId'=>'L1','version'=>0,'action'=>'assign','dealId'=>'D1'];
$actual=['lotId'=>'L1','version'=>1,'action'=>'container','date'=>'2026-10-05','number'=>'MSCU1234567','seal'=>'S1','truck'=>'ABC-123','bags'=>1080,'netKg'=>27000,'grossKg'=>27108,'evidence'=>'Loading sheet 18 / weighbridge 20'];
$today='2026-10-10';
$mills=['M1'=>'Khan Rice Mills, Punjab','M2'=>'Independent Loading Yard'];
ok(oel_default_mill_id('KHAN RICE MILLS',$mills)==='M1','Unique exporter mill defaults for a historical agreement');
ok(oel_default_mill_id('Khan Rice Mills',$mills+['M3'=>'Khan Rice Mills, Sindh'])==='','Ambiguous branches require a selection');
ok(oel_default_mill_id('Khan Rice Mills',$mills+['M3'=>'Khan Rice Mills'])==='M3','Exact saved name takes priority');
ok(oel_deal_choices($root,['D1'=>$deal],$mills,'TTI','AMT',$today)[0]['millId']==='M1','Exports sees the inferred location without re-saving historical SODAs');
$eligible=array_replace($deal,['outsideMillId'=>'M2','reference'=>'SODA-1','quantityMT'=>54]);
ok(oel_deal_choices($root,['D1'=>$eligible],$mills,'TTI','AMT',$today)[0]['millId']==='M2','An explicit Director selection takes priority');
$eligible['outsideMillId']='M1';
$choices=oel_deal_choices($root,['D1'=>$eligible,'D2'=>array_replace($eligible,['status'=>'Closed']),'D3'=>array_replace($eligible,['contractRef'=>'OTHER'])],['M1'=>'Khan Rice Mills, Punjab'],'TTI','AMT',$today);
ok(count($choices)===1&&$choices[0]['id']==='D1'&&$choices[0]['remainingMT']===54.,'Other Mill shows only eligible SODAs for this contract and company');
$reserved=$root;
$reserved['shipments'][0]['loadingPlan']['outsideDealId']='D1';
$reserved['shipments'][0]['loadingPlan']['outsideMillId']='M1';
$reserved['shipments'][0]['plannedQty']=27;
ok(oel_deal_remaining($eligible,$reserved)===27.&&oel_deal_remaining($eligible,$reserved,'L1')===54.,'Reservations reduce the dropdown balance without counting the lot being assigned');
ok(oel_deal_choices($reserved,['D1'=>array_replace($eligible,['quantityMT'=>27])],['M1'=>'Khan Rice Mills, Punjab'],'TTI','AMT',$today)===[],'Fully reserved SODAs are excluded');
reject(fn()=>oel_change($reserved,$export,'Exports',$assign+['route'=>'EXPORTS'],['D1'=>array_replace($eligible,['outsideMillId'=>'M2'])],$today),'The lot cannot substitute a different mill at assignment');

$automatic=oel_change($root,$export,'Exports',array_replace($actual,['version'=>0,'dealId'=>'D1','confirmComplete'=>'yes']),$deals,$today);
ok($automatic['shipments'][0]['outsideLoading']['assigneeId']===1&&count($automatic['shipments'][0]['millActuals'])===1&&$automatic['shipments'][0]['millLoadingCompleted'],'Saving the final Exports container links the SODA and confirms loading in one mutation');
$draft=dt_confirmed_outside_contributions($deal,dt_lots($automatic,'TTI'),$today);
ok(count($draft['loadings'])===1&&$draft['loadings'][0]['quantityMT']===27.&&$draft['loadings'][0]['supplierGoodsCostPKR']===null,'Confirmed B/L weights create one draft liability without guessing rice cost');
ok(count(dt_confirmed_outside_contributions($draft,dt_lots($automatic,'TTI'),$today)['loadings'])===1,'Repeated views keep one draft liability');
reject(fn()=>oel_change($root,$export,'Exports',array_replace($actual,['version'=>0,'dealId'=>'wrong']),$deals,$today),'First Exports container still requires a matching SODA');

$onSite=oel_change($root,$export,'Exports',$assign+['route'=>'MILL','assigneeId'=>2],$deals,$today);
ok(oel_rows($onSite,array_replace($mill,['id'=>3]),'Mill')===[],'Unassigned Mill users have no access');
reject(fn()=>oel_change($onSite,$export,'Exports',$actual,$deals,$today),'Exports cannot write the assigned Mill load');
reject(fn()=>oel_change($onSite,$mill,'Mill',$actual,$deals,$today),'Mill inspection cannot be omitted');
$onSite=oel_change($onSite,$mill,'Mill',$actual+['inspection'=>'Broken 4.8%, moisture 13%','qualityAccepted'=>'yes'],$deals,$today);
ok($onSite['shipments'][0]['millActuals'][0]['enteredBy']==='Inspector','On-site inspector is recorded');
$root=oel_change($root,$export,'Exports',$assign+['route'=>'EXPORTS'],$deals,$today);
$root=oel_change($root,$export,'Exports',$actual,$deals,$today);
reject(fn()=>oel_change($root,$export,'Exports',$actual,$deals,$today),'A stale version cannot overwrite a container');
$root=oel_change($root,$export,'Exports',['lotId'=>'L1','action'=>'complete','version'=>2],$deals,$today);
ok($root['shipments'][0]['millActuals'][0]['location']==='Khan Rice Mills, Punjab','Exports document row retains the real source');
$root['shipments'][0]['commercial']=['invoiceNo'=>'INV1','date'=>'2026-10-06','saved'=>true];
$lots=dt_lots($root,'TTI');
$load=dt_loading([],$deal,['shipmentId'=>'L1','date'=>'2026-10-05','asOf'=>$today,'quantityMT'=>27],$lots);
$deal['loadings'][]=$load+['partnerExpensesPKR'=>50000,'partnerCostReference'=>'Statement 18'];
$deal['loadings'][0]['supplierGoodsCostPKR']=3000000;
$state=['journals'=>[],'exporterDeals'=>['D1'=>$deal],
    'supplierBills'=>['F'=>['entity'=>'TTI','billDate'=>'2026-10-06','status'=>'Paid','supplierPayableTotal'=>100000,'category'=>'FREIGHT','sourceRecord'=>['shipmentId'=>'L1']]],
    'exportCandidates'=>['I'=>['transactionAmount'=>16470,'meta'=>['contractRef'=>'AMT','lotRef'=>'L1']]],
    'exportReceipts'=>[['id'=>'R','entity'=>'TTI','date'=>'2026-10-09','status'=>'Posted','realizationRate'=>280,'allocations'=>[['targetId'=>'I','foreignAmount'=>16470]],'deductions'=>[]]]];
$deal['loadings'][0]['expensesComplete']=true;
$deal['loadings'][0]['reviewHash']=dt_source_hash($state,$deal['loadings'][0],$lots,$today);
$working=dt_working($state,$deal,$lots,$today);
$pool=27*590*280-3000000-50000-100000;
ok(abs($working['retainedMarginPKR']-27*20*280)<.01,'Private uplift is exactly USD 20/MT');
ok(abs($working['totalProfitPKR']-$pool)<.01,'Profit is shared only from USD 590');
ok(abs($working['settlementPKR']-(3050000+$pool/2))<.01,'Payable includes rice, reimbursable expenses and only the agreed share');
reject(fn()=>dt_loading($state,$deal,['shipmentId'=>'L1','date'=>'2026-10-05','asOf'=>$today,'quantityMT'=>27],$lots),'Cannot count the same outside lot twice');
$post=dt_post_settlement($state,$deal,$working,$export,$today);
ok(count($state['journals'])===1&&$state['journals'][$post['postId']]['sourceType']==='FELLOW_EXPORTER_SETTLEMENT','One settlement journal uses the existing accounting path');
echo "PASS assigned outside routes, quality gate, actuals before invoice, private margin, shared cost pool and single settlement\n";
