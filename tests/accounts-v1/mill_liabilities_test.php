<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/api/mill_liabilities_core.php';
function verify(bool $v,string $message):void {if (!$v) throw new RuntimeException($message);}
function denied(callable $f,string $message):void {try {$f();}catch (DomainException $e) {return;}throw new RuntimeException($message);}
$provider=['id'=>'PROVIDER','values'=>['K-Electric','','Service Provider','','','','','','','','Active']];
$contractor=['id'=>'CONTRACTOR','values'=>['Labour Co','','Labour Contractor','','','','','','','','Active']];
$masters=['mills'=>[['id'=>'mills-1','values'=>['TTI Rice Mills','','Own Mill','','','Active','','TTI']]],'business_parties'=>[$provider,$contractor]];
$bill=['id'=>'KE-1','entity'=>'TTI','millId'=>1,'millName'=>'TTI Rice Mills','date'=>'2026-10-17','readFrom'=>'2026-09-01','readTo'=>'2026-09-30','ref'=>'KE-SEP','party'=>'K-Electric','amount'=>1000];
$values=['tt38kebills'=>json_encode([$bill])];$before=serialize([$values,$masters]);$source=ml_source($values,$masters,'ELECTRICITY','KE-1','TTI');
verify(serialize([$values,$masters])===$before,'Review changed source or masters');
denied(fn()=>ml_source($values,$masters,'ELECTRICITY','KE-1','BRM'),'Wrong company accepted');
$bad=$masters;$bad['business_parties'][0]['values'][10]='Inactive';denied(fn()=>ml_source($values,$bad,'ELECTRICITY','KE-1','TTI'),'Inactive payee accepted');
$bad=$masters;$bad['mills'][0]['values'][7]='BRM';denied(fn()=>ml_source($values,$bad,'ELECTRICITY','KE-1','TTI'),'Wrong mill owner accepted');
$bad=$values;$bad['tt38kebills']=json_encode([$bill,$bill]);denied(fn()=>ml_source($bad,$masters,'ELECTRICITY','KE-1','TTI'),'Duplicate source accepted');
$store=['journals'=>[],'supplierBills'=>[]];$user=['id'=>7,'full_name'=>'Fixture reviewer'];$date='2026-10-17T11:00:00Z';
denied(function()use(&$store,$source,$user,$date){ml_post($store,$source,$user,'stale','POST-1',$date);},'Stale source accepted');verify(!$store['journals'],'Failed review wrote a journal');
$result=ml_post($store,$source,$user,$source['fingerprint'],'POST-1',$date);$posted=serialize($store);
verify($store['journals']['POST-1']['lines'][0]['account']==='5200'&&$store['journals']['POST-1']['lines'][1]['account']==='2140','Wrong liability accounts');
verify(!array_filter($store['journals']['POST-1']['lines'],fn($l)=>in_array($l['account'],['1110','1120'],true)),'Bill spent cash');
verify(ml_post($store,$source,$user,$source['fingerprint'],'UNUSED',$date)['duplicate']&&serialize($store)===$posted,'Lost acknowledgement duplicated the posting');
$id=$result['record']['billId'];$store['supplierSettlements']=['P'=>['entity'=>'TTI','status'=>'Posted','allocations'=>[['billId'=>$id,'sourceKey'=>$source['key'],'amount'=>400]]]];
$settled=serialize($store);denied(function()use(&$store,$source,$user,$date){ml_reverse($store,$source['key'],'TTI',$user,'Correct','REV-1','2026-10-17',$date);},'Part-paid reversal accepted');verify(serialize($store)===$settled,'Blocked correction mutated books');
unset($store['supplierSettlements']);
$store['productionCostRuns']=['COST'=>['entity'=>'TTI','calculation'=>['conversionSources'=>[['id'=>'KE|KE-1']]]]];
$used=serialize($store);denied(function()use(&$store,$source,$user,$date){ml_reverse($store,$source['key'],'TTI',$user,'Correct used bill','REV-1','2026-10-17',$date);},'Capitalized / approved cost source was reversed');verify(serialize($store)===$used,'Cost-linked refusal changed books');unset($store['productionCostRuns']);
$original=$store['journals']['POST-1'];ml_reverse($store,$source['key'],'TTI',$user,'Wrong reading period','REV-1','2026-10-17',$date);
verify($store['journals']['POST-1']===$original,'Original journal was rewritten');verify($store['supplierBills'][$id]['supplierPayableTotal']===0,'Cancelled bill remains payable');
verify($store['journals']['REV-1']['lines'][0]['credit']===1000.0,'Reversal does not offset expense');
$reversed=serialize($store);verify(ml_reverse($store,$source['key'],'TTI',$user,'Retry','UNUSED','2026-10-17',$date)['duplicate']&&serialize($store)===$reversed,'Reversal retry duplicated');
$labour=['id'=>'L-1','entity'=>'TTI','millId'=>1,'millName'=>'TTI Rice Mills','date'=>'2026-10-17','from'=>'2026-10-01','till'=>'2026-10-15','ref'=>'L1','party'=>'Labour Co','commodity'=>'Rice','amount'=>500,'lines'=>[['qty'=>10000,'rate'=>5,'basis'=>'per 100 kg']]];
$values['tt38labourbills']=json_encode([$labour]);$ls=ml_source($values,$masters,'LABOUR','L-1','TTI');verify($ls['payableAccount']==='2190','Labour payable not separate');
$labour['lines'][0]['rate']=1.235;$labour['amount']=123.5;$values['tt38labourbills']=json_encode([$labour]);verify(ml_source($values,$masters,'LABOUR','L-1','TTI')['amount']===123.5,'Unit rate precision was rounded before calculating bill');
$labour['commodity']='Maize';$values['tt38labourbills']=json_encode([$labour]);denied(fn()=>ml_source($values,$masters,'LABOUR','L-1','TTI'),'Deferred Maize accepted');
$masters['mills'][]=['id'=>'outside-1','values'=>['Outside Rice Mill','','Reprocessing Mill','','','Active','','']];
$masters['business_parties'][]=['id'=>'OUTSIDE-PARTY','values'=>['Outside Rice Mill','','Service Provider','','','','','','','','Active']];
$rp=['id'=>'RP1','entity'=>'TTI','millId'=>77,'millName'=>'Outside Rice Mill','mill'=>'Outside Rice Mill','date'=>'2026-10-17','from'=>'2026-10-01','till'=>'2026-10-15','billNo'=>'RP-1','arrivalKg'=>100000,'rawRemovedKg'=>1000,'billableKg'=>99000,'rate'=>1.235,'loadingExtra'=>200,'other'=>50,'amount'=>122515,'paid'=>0,'payments'=>[]];
$values['tt38reprocessbills']=json_encode([$rp]);verify(ml_source($values,$masters,'REPROCESSING','RP1','TTI')['amount']===122515.0,'Reprocessing gross did not retain actual billable weight/rate/extras');
$rp['rawRemovedKg']=2000;$values['tt38reprocessbills']=json_encode([$rp]);denied(fn()=>ml_source($values,$masters,'REPROCESSING','RP1','TTI'),'Inconsistent reprocessing quantity accepted');
$rp['rawRemovedKg']=1000;$rp['paid']=1000;$rp['payments']=[['amount'=>1000,'reference'=>'Legacy reference']];$values['tt38reprocessbills']=json_encode([$rp]);denied(fn()=>ml_source($values,$masters,'REPROCESSING','RP1','TTI'),'Unreconciled reported reprocessing payment imported');
echo "PASS Mill liability source/master/date validation, zero-write review, correct accrual without cash, idempotent post/reversal, settled correction refusal, immutable original and deferred Maize guard\n";

$processing=['id'=>'EX1','entity'=>'TTI','millId'=>1,'millName'=>'TTI Rice Mills','date'=>'2026-10-17','from'=>'2026-10-01','till'=>'2026-10-15','ref'=>'PLANT-OCT','party'=>'K-Electric','amount'=>700,'type'=>'Plant Expense','settlementMode'=>'CANONICAL_PAYABLE'];
$ops=['tt37processingexpenses'=>json_encode([$processing])];$ps=ml_source($ops,$masters,'PROCESSING','EX1','TTI');verify($ps['amount']===700.0&&$ps['expenseAccount']==='5200','Processing bill source/treatment lost');
$legacy=$processing;$legacy['payment']='Petty Cash';$oldOps=['tt37processingexpenses'=>json_encode([$legacy])];denied(fn()=>ml_source($oldOps,$masters,'PROCESSING','EX1','TTI'),'Reported paid expense became a duplicate liability');
$store=['journals'=>[],'supplierBills'=>[],'generalExpenses'=>[['entity'=>'TTI','payee'=>'K-Electric','reference'=>'PLANT-OCT','status'=>'Posted']]];
denied(function()use(&$store,$ps,$user,$date){ml_post($store,$ps,$user,$ps['fingerprint'],'PLANT1',$date);},'Already paid direct expense reference was posted again');verify(!$store['journals'],'Duplicate bill wrote cash/expense');
unset($store['generalExpenses']);$posted=ml_post($store,$ps,$user,$ps['fingerprint'],'PLANT1',$date);verify(array_column($store['journals']['PLANT1']['lines'],'account')===['5200','2140'],'Processing accrual spent money');
echo "PASS actual processing bills: service provider, explicit unpaid source, historical paid hold, duplicate canonical payment protection and production-cost liability\n";

require dirname(__DIR__,2).'/api/mill_labour_posting.php';
define('TT_DATA_DIR',sys_get_temp_dir().'/tt-labour-posting-'.bin2hex(random_bytes(8)));
function tt_ensure_data_dir():void {if(!is_dir(TT_DATA_DIR))mkdir(TT_DATA_DIR,0700);}
function tt_list_masters():array {return $GLOBALS['masters'];}
function tt_user_can_open_module(array $u,string $module):bool {return $module===($u['module']??'');}
function tt_user_can_module_action(array $u,string $module,string $area,string $action):bool {
    return tt_user_can_open_module($u,$module)&&$area===($module==='Mill'?'labour':'expenses')&&in_array($action,$u['actions'],true);
}
function tt_user_can_access_entity(array $u,string $entity,string $action):bool {return $entity===($u['entity']??'');}
function tt_next_post_id(array $journals,mixed ...$args):string {return 'POST-'.(count($journals)+1);}
$labour['commodity']='Rice';
$new=array_replace($labour,['id'=>'L-2','ref'=>'L2']);
$new['lines'][]=['qty'=>1,'rate'=>0,'basis'=>'Per bag'];
$invalid=array_replace($new,['id'=>'L-3','ref'=>'L3','amount'=>999.0]);
$before=json_encode([$labour]);
$after=json_encode([$labour,$new,$invalid]);
$actor=['id'=>21,'full_name'=>'Mill clerk','location'=>'TTI Rice Mills','module'=>'Mill','actions'=>['Create']];
$reviewer=['id'=>22,'full_name'=>'Accounts editor','location'=>'TTI Rice Mills','module'=>'Accounts','actions'=>['View','Edit'],'entity'=>'TTI'];
tt_ensure_data_dir();
try {
    file_put_contents(TT_DATA_DIR.'/operations.json',json_encode(['values'=>['tt38labourbills'=>$after]]));
    verify(ml_post_appended_labour($before,$before,$actor)===[]&&!is_file(TT_DATA_DIR.'/accounts.json'),'Historical labour was posted on an unchanged save');
    $result=ml_post_appended_labour($before,$after,$actor);
    verify($result['L-2']===['status'=>'Posted','postId'=>'POST-1']&&$result['L-3']['status']==='Needs Accounts review','New committed labour bill or invalid total handled incorrectly');
    $books=ml_read_accounts();
    $journal=$books['journals']['POST-1'];
    verify(count($books['journals'])===1&&$journal['reference']==='L2'&&$journal['userId']===21&&$journal['totalCredit']===123.5&&array_column($journal['lines'],'account')===['5200','2190'],'Auto-post did not create exactly one no-cash liability under the Mill actor');
    verify(ml_post_appended_labour($before,$after,$actor)===$result&&ml_read_accounts()===$books,'Retry rewrote Accounts or posted twice');
    verify(ml_post_appended_labour($before,$after,$reviewer)['L-2']['status']==='Needs Accounts review','Accounts edit permission substituted for Mill create');
    msa_scope($reviewer,$new,'LABOUR','Edit');
    denied(fn()=>msa_scope(array_replace($reviewer,['entity'=>'BRM']),$new,'LABOUR','Edit'),'Another company could amend labour');
    denied(fn()=>msa_scope($reviewer,$new,'ELECTRICITY','Edit'),'Accounts-only access could amend an unrelated Mill source');
    $canonical=reset($books['supplierBills']);
    verify(!ml_bill_uses_party_ledger($books,'TTI',$canonical),'An unconfigured labour party lost bill payments');
    $books['paymentPayees'][]=['id'=>'PARTY|CONTRACTOR','name'=>'Labour Co','entity'=>'TTI','kind'=>'BUSINESS','accountCode'=>'2190','active'=>true];
    verify(ml_bill_uses_party_ledger($books,'TTI',$canonical)&&!ml_bill_uses_party_ledger($books,'BRM',$canonical),'Party-ledger treatment crossed company or missed a configured contractor');
} finally {
    foreach(glob(TT_DATA_DIR.'/*') as $path)unlink($path);
    rmdir(TT_DATA_DIR);
}
echo "PASS future labour liability posting, replay, Accounts edit scope and configured party-ledger treatment\n";
