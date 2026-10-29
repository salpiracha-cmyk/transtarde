<?php
declare(strict_types=1);
require __DIR__.'/../../api/directors_trading_core.php';
function tt_list_masters(){return ['business_parties'=>[['id'=>'HKM','values'=>['Haji Khushi Muhammad','','Fellow Exporter']],['id'=>'VENDOR','values'=>['Ordinary Supplier','','Supplier']]]];}
function ok($v,$m){if(!$v)throw new RuntimeException($m);}
function blocked($f){try{$f();}catch(DomainException){return;}throw new RuntimeException('Expected protected operation to be rejected');}
$u=['username'=>'fixture'];$s=['journals'=>[]];
ok(array_column(fe_parties($s,'TTI'),'id')===['HKM'],'General suppliers must not enter the Fellow Exporter list');
$r=fe_save_party($s,'TTI',['name'=>'Ordinary Supplier'],$u);ok($r['partyId']==='VENDOR','Explicitly adopting a fellow exporter reuses its original canonical business identity');
blocked(fn()=>fe_save_party($s,'TTI',['partyId'=>'VENDOR','version'=>9,'name'=>'Changed','reason'=>'Correct contact'],$u));
$r=fe_save_party($s,'TTI',['partyId'=>'VENDOR','version'=>1,'name'=>'Khan Exporter','contact'=>'Fixture contact','reason'=>'Correct name'],$u);ok(count(fe_parties($s,'TTI'))===2&&count(fe_parties($s,'BRM'))===1,'Master edits are company scoped');
$base=['entity'=>'TTI','date'=>'2026-10-01','direction'=>'SALE','arrangement'=>'EX_MILL','partyId'=>'VENDOR','currency'=>'PKR','price'=>109000,'quantityMT'=>100,'incoterm'=>'EX_MILL','fx'=>1,'fobEstimateUSDPerMT'=>0,'bankEstimatePct'=>0];
$d=dt_deal($base,fe_parties($s,'TTI'))+['id'=>'DEAL','version'=>1,'loadings'=>[],'payments'=>[],'settlementPosts'=>[],'history'=>[]];$s['exporterDeals']['DEAL']=$d;
blocked(fn()=>fe_save_party($s,'TTI',['partyId'=>'VENDOR','version'=>2,'reason'=>'Remove unused master'],$u,true));
$posted=$s;$posted['journals']['P']=['entity'=>'TTI','meta'=>['exporterDealId'=>'DEAL']];ok(fe_delete_issue($posted,$d,[])!=='','Any accounting history protects the SODA');
$planned=[['entity'=>'TTI','ref'=>'DEAL|MILL|M1','exporterDealId'=>'DEAL','containers'=>[]]];ok(fe_delete_issue($s,$d,$planned)!=='','Original Mill references protect the SODA even before dispatch');
$allocated=$s;$allocated['purchaseSodas']['P']=['buyingForAllocations'=>[['targetId'=>'EXPORTER|DEAL','quantityKg'=>1000]]];ok(fe_delete_issue($allocated,$d,[])!=='','Buying-for allocations protect original purchase links');
$stable=$s['journals'];$r=fe_delete_deal($s,$d,['reason'=>'Duplicate unused agreement'],$u,[]);ok($r['deleted']&&$s['journals']===$stable&&$s['exporterDeals']['DEAL']['status']==='Deleted','Unused deletion retains source and audit without changing books');ok(dt_cashflow($s,[],'TTI','2026-10-02')===[],'Deleted commitments leave active forecasts');
fe_save_party($s,'TTI',['partyId'=>'VENDOR','version'=>2,'reason'=>'Remove unused master'],$u,true);ok(array_column(fe_parties($s,'TTI'),'id')===['HKM'],'Archived master cannot reappear through its old business-party identity');
$parties=[['id'=>'HKM','name'=>'HKM']];$m=dt_deal(array_replace($base,['partyId'=>'HKM','arrangement'=>'MARGIN','direction'=>'PURCHASE','currency'=>'USD','price'=>400,'incoterm'=>'FOB','fx'=>280,'contractRef'=>'AMT']),$parties)+['id'=>'MARGIN','version'=>1,'loadings'=>[],'payments'=>[],'settlementPosts'=>[],'history'=>[]];
$lot=['id'=>'L1','lot'=>'AMT/L1','contract'=>'AMT','pakistanExporter'=>'TTI','quantityMT'=>100,'invoice'=>'INV1','invoiceDate'=>'2026-10-01','invoiceSaved'=>true,'contractPrice'=>500,'currency'=>'USD'];$m['loadings'][]=dt_loading([],$m,['date'=>'2026-10-02','asOf'=>'2026-10-02','quantityMT'=>100,'shipmentId'=>'L1'],['L1'=>$lot]);$w=dt_working([],$m,['L1'=>$lot],'2026-10-02');ok($w['settlementPKR']===11200000.0&&$w['retainedMarginPKR']===2800000.0,'Agreed payable is separate from private retained margin');ok($w['rows'][0]['grossPKR']===11200000.0,'Public settlement row shows the agreed amount, not the internal buyer-rate margin');
$supplier=dt_deal(array_replace($base,['partyId'=>'HKM','arrangement'=>'PARTNERSHIP','direction'=>'SALE','currency'=>'USD','price'=>500,'incoterm'=>'FOB','fx'=>280,'contractRef'=>'AMT','goodsEstimatePKRPerMT'=>100000]),$parties);ok($supplier['direction']==='SALE'&&$supplier['partnerSharePct']===null,'Supplier role is allowed; unspecified sharing is not invented');
// Exercise the actual operational write guard against a cached row after deletion.
require_once __DIR__.'/../../api/operations_policy.php';
$dir=sys_get_temp_dir().'/fellow-soda-guard-'.bin2hex(random_bytes(6));mkdir($dir,0700);define('TT_DATA_DIR',$dir);file_put_contents($dir.'/accounts.json',json_encode(['exporterDeals'=>['DELETED'=>['entity'=>'TTI','status'=>'Deleted']]]));
$incoming=json_encode([['id'=>'MILL1','entity'=>'TTI','exporterDealId'=>'DELETED','containers'=>[]]]);blocked(fn()=>operations_guard_fellow_soda('tt35exportersale','[]',$incoming));unlink($dir.'/accounts.json');rmdir($dir);
echo "PASS Fellow Exporter master isolation/adoption/versioning, protected audited deletion, forecast removal, supplier role and private margin separation\n";

$contractChoices=dt_contracts(['contracts'=>[['ref'=>'A','seller'=>'TTI','product'=>'PK386 Rice','containers'=>10,'weightPer'=>27,'podPort'=>'Mombasa','packings'=>[['brand'=>'STAR','size'=>50,'unit'=>'KG','type'=>'PP Bags']],'specRows'=>[['name'=>'Broken','value'=>'5% max']],'additionalQuality'=>'Crop 2026'],['ref'=>'OTHER','seller'=>'BRM','product'=>'Other']]],[],'TTI');ok(count($contractChoices)===1&&$contractChoices[0]['brand']==='STAR'&&$contractChoices[0]['packing']==='50 KG PP Bags'&&$contractChoices[0]['port']==='Mombasa'&&$contractChoices[0]['quantityMT']===270.0&&str_contains($contractChoices[0]['fullSpecification'],'5% max'),'Company contract choices expose their saved product/packing/brand/port/specification, no unrelated company');
echo "PASS contract planning metadata, complete saved specifications and company scope\n";
