<?php
declare(strict_types=1);
function tt_next_post_id(array $existing, string $module = 'Accounts', string $area = 'Journal', ?string $date = null): string {
    $year=substr($date ?: date('Y-m-d'),0,4);
    $n=count($existing)+1;
    do {$id='POST-'.$year.'-'.str_pad((string)$n,5,'0',STR_PAD_LEFT);$n++;} while (isset($existing[$id]));
    return $id;
}
require __DIR__.'/../../api/assets_registry_core.php';
require __DIR__.'/../../api/accounts_bank_payment.php';
function tt_list_masters():array{return ['banks'=>[['id'=>'B1','values'=>['Company Account','TTI','','TEST COMPANY','BANK','','','PKR','123']]]];}
function tt_bank_can_transact(string $id):bool{return $id==='B1';}
function check(bool $v,string $label):void{if(!$v)throw new RuntimeException($label);}
function rejects(callable $f,string $label):void{try{$f();}catch(DomainException){return;}throw new RuntimeException('Expected rejection: '.$label);}
$u=['role'=>'Super Admin','id'=>1,'username'=>'TEST'];$s=['journals'=>[]];
$base=['type'=>'PROPERTY','name'=>'Private Dubai Apartment','assetTag'=>'DXB-001','ownerType'=>'PERSONAL','ownerName'=>'TEST OWNER','countryId'=>'AE','cityId'=>'DXB','address'=>'PRIVATE PROJECT ADDRESS','unitNo'=>'PRIVATE UNIT 19','purchaseDate'=>'2025-01-01','cost'=>1000,'currency'=>'PKR','purpose'=>'PERSONAL_USE','accountingMode'=>'REGISTER_ONLY','paymentPattern'=>'FULLY_PAID','privateNotes'=>'SECRET NOTE','documentReferences'=>'SECRET DEED','payments'=>[['instalmentNo'=>'1','date'=>'2025-03-01','amount'=>400],['instalmentNo'=>'2','date'=>'2026-06-30','amount'=>600]]];
$result=far_register($s,'TTI',$base,$u);$id=$result['postId'];check(count($s['journals'])===0,'All pre-July history is nonfinancial');
check(far_payload($s,'TTI',false)['assets']===[],'Accounts cannot see fully paid properties');
$public=json_encode(far_payload($s,'TTI',false));foreach(['PRIVATE PROJECT','SECRET NOTE','SECRET DEED','DXB-001','TEST OWNER','100000'] as $secret)check(!str_contains($public,$secret),'No private field in Accounts response: '.$secret);
check(count(far_payload($s,'TTI',true)['assets'])===1,'Directors can review fully paid property');
check(far_permission(['permissions'=>['Directors'=>['tgmaster'=>['View']]]],'Directors','View')===false,'Unrelated director icon cannot read private assets');
check(far_permission(['permissions'=>['Directors'=>['assets'=>['View']]]],'Directors','View'),'Explicit director Asset permission');
check(!far_permission(['permissions'=>['Accounts'=>['assets'=>['View']]]],'Accounts','Edit'),'Read-only asset permission');
$flex=$base;$flex['assetTag']='PK-002';$flex['paymentPattern']='MONTHLY';$flex['payments']=[['instalmentNo'=>'1','date'=>'2026-06-30','amount'=>250]];$flex['firstDueDate']='2026-07-31';$flex['monthlyAmount']=300;$flex['instalmentCount']=3;
$r=far_register($s,'TTI',$flex,$u);$a=$s['managedAssets'][$r['postId']];$schedule=far_schedule($a);check(array_column($schedule,'dueDate')===['2026-07-31','2026-08-31','2026-09-30'],'Month-end schedule dates do not drift');check(array_column($schedule,'amountCents')===[30000,30000,15000],'Final instalment is capped to balance');check($schedule[0]['paidCents']===0,'Previous payments do not settle future schedule twice');
$p=far_payment($s,$a,['instalmentNo'=>'2','date'=>'2026-07-01','amount'=>300,'cashAmount'=>150,'bankAmount'=>150,'paymentAccountId'=>'B1','bankPaymentMethod'=>'ONLINE_BANKING'],$u);check(!$p['historicalOnly']&&count($s['journals'])===1,'1 July posts; online reference optional');
$j=$s['journals'][$p['journalId']];check($j['totalDebit']===$j['totalCredit']&&$j['totalDebit']===300.0,'Split payment balances');check($j['lines'][0]['account']==='3200','Personal asset does not become a company asset');check($j['lines'][1]['credit']==150.0&&$j['lines'][2]['credit']==150.0,'Separate petty cash and bank movement');check(!str_contains(json_encode($j),'Private Dubai'),'Private descriptions never enter generic ledger/search');
check(far_schedule($a)[0]['balanceCents']===0,'Payment settles first scheduled instalment');
rejects(fn()=>far_payment($s,$a,['instalmentNo'=>'3','date'=>'2026-07-01','amount'=>100,'cashAmount'=>0,'bankAmount'=>100,'paymentAccountId'=>'B1','bankPaymentMethod'=>'CHEQUE'],$u),'Missing cheque');
rejects(fn()=>far_payment($s,$a,['instalmentNo'=>'3','date'=>'2026-07-01','amount'=>500,'cashAmount'=>500,'bankAmount'=>0],$u),'Overpayment');
rejects(fn()=>far_date('2026-02-30'),'Impossible date');
$snapshot=$s;far_location_action($s,['kind'=>'countries','operation'=>'delete','id'=>'AE']);check(!$s['assetLocations']['cities']['DXB']['active'],'Deleting country removes linked cities from future choices');check($s['managedAssets'][$id]['city']==='DUBAI','Historical snapshots preserved');far_location_action($s,['kind'=>'countries','operation'=>'add','name'=>'United Arab Emirates']);far_location_action($s,['kind'=>'cities','operation'=>'add','countryId'=>'AE','name'=>'Dubai']);
$company=$base;$company['assetTag']='CO-001';$company['ownerType']='COMPANY';$company['accountingMode']='NEW_PURCHASE';$company['purchaseDate']='2026-07-01';$company['paymentPattern']='FLEXIBLE';$company['payments']=[];
$r=far_register($s,'TTI',$company,$u);$companyAsset=$s['managedAssets'][$r['postId']];$purchase=$s['journals'][$companyAsset['purchaseJournalId']];check($purchase['lines'][0]['account']==='1550'&&$purchase['lines'][1]['account']==='2140','New company property records asset and payable once');
$payment=['instalmentNo'=>'1','date'=>'2026-07-01','amount'=>100,'cashAmount'=>100,'bankAmount'=>0];far_payment($s,$companyAsset,$payment,$u);$last=end($s['journals']);check($last['lines'][0]['account']==='2140','Company instalments reduce liability, not repeat cost');
echo "Asset registry cutoff, privacy, schedule, split, ownership, country/city history and balanced journals passed\n";

$vehicle=far_vehicle_identity($s,'TTI',['name'=>'Company Corolla','registrationNo'=>'ABC-123'],$u);
$car=$s['managedAssets'][$vehicle['postId']];check($car['trackingOnly']&&$car['costCents']===0,'Existing vehicle does not invent purchase cost');check(far_visible($car,false)&&!far_totals($car)['fullyPaid'],'Tracking vehicles stay visible without a false fully-paid claim');
rejects(fn()=>far_vehicle_identity($s,'TTI',['name'=>'Duplicate','registrationNo'=>'abc 123'],$u),'Same registration cannot create two vehicles');
