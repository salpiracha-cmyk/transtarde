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
unset($store['supplierSettlements']);$original=$store['journals']['POST-1'];ml_reverse($store,$source['key'],'TTI',$user,'Wrong reading period','REV-1','2026-10-17',$date);
verify($store['journals']['POST-1']===$original,'Original journal was rewritten');verify($store['supplierBills'][$id]['supplierPayableTotal']===0,'Cancelled bill remains payable');
verify($store['journals']['REV-1']['lines'][0]['credit']===1000.0,'Reversal does not offset expense');
$reversed=serialize($store);verify(ml_reverse($store,$source['key'],'TTI',$user,'Retry','UNUSED','2026-10-17',$date)['duplicate']&&serialize($store)===$reversed,'Reversal retry duplicated');
$labour=['id'=>'L-1','entity'=>'TTI','millId'=>1,'millName'=>'TTI Rice Mills','date'=>'2026-10-17','from'=>'2026-10-01','till'=>'2026-10-15','ref'=>'L1','party'=>'Labour Co','commodity'=>'Rice','amount'=>500,'lines'=>[['qty'=>10000,'rate'=>5,'basis'=>'per 100 kg']]];
$values['tt38labourbills']=json_encode([$labour]);$ls=ml_source($values,$masters,'LABOUR','L-1','TTI');verify($ls['payableAccount']==='2190','Labour payable not separate');
$labour['commodity']='Maize';$values['tt38labourbills']=json_encode([$labour]);denied(fn()=>ml_source($values,$masters,'LABOUR','L-1','TTI'),'Deferred Maize accepted');
echo "PASS Mill liability source/master/date validation, zero-write review, correct accrual without cash, idempotent post/reversal, settled correction refusal, immutable original and deferred Maize guard\n";
