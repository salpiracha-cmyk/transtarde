<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/api/mill_source_amend_core.php';
function tt_user_can_open_module($u,$m){return !empty($u['allow']);}
function tt_user_can_module_action($u,$m,$icon,$action){return in_array($action,$u['actions']??[],true);}
function tt_user_can_access_entity($u,$entity,$action){return $entity==='TTI';}
function check_msa($yes,$message){if(!$yes)throw new RuntimeException($message);}
function reject_msa(callable $fn,string $message){try{$fn();}catch(DomainException $e){return;}throw new RuntimeException($message);}
$u=['id'=>7,'full_name'=>'Actual reviewer','role'=>'Staff','location'=>'TTI Rice Mills','allow'=>true,'actions'=>['View','Create','Edit']];
$masters=['mills'=>[['id'=>'mills-1','values'=>['TTI Rice Mills','','Own Mill','','','Active','','TTI']],['id'=>'ext-1','values'=>['External Mill','','Reprocessing Mill','','','Active']]],'business_parties'=>[['id'=>'KE','values'=>['K-Electric','','Service Provider','','','','','','','','Active']],['id'=>'LC','values'=>['Contractor','','Labour Contractor','','','','','','','','Active']],['id'=>'EXT','values'=>['External Mill','','Service Provider','','','','','','','','Active']]]];
$r=['id'=>'KE1','entity'=>'TTI','millId'=>1,'millName'=>'TTI Rice Mills','party'=>'K-Electric','date'=>'2026-10-17','readFrom'=>'2026-09-01','readTo'=>'2026-09-30','amount'=>1000,'ref'=>'KE-SEP'];$values=['tt38kebills'=>json_encode([$r])];
$b=['collection'=>'tt38kebills','entity'=>'TTI','sourceId'=>'KE1','fingerprint'=>ml_fingerprint($r),'requestKey'=>'fixture-amend-source-001','reason'=>'Correct original bill amount','changes'=>['amount'=>1200]];
$out=msa_amend($values,$masters,[],$u,$b,'2026-10-17T12:00:00Z');$saved=$out['record'];check_msa($saved['amount']===1200.0,'Actual corrected amount lost');check_msa($saved['_sourceAmendments'][0]['before']===$r,'Original source changed');check_msa($saved['_sourceAmendments'][0]['userId']===7,'Actual actor missing');
$next=['tt38kebills'=>$out['value']];$retry=msa_amend($next,$masters,[],$u,$b,'later');check_msa($retry['replayed']&&$retry['value']===$out['value'],'Lost acknowledgement duplicated source history');
reject_msa(fn()=>msa_amend($next,$masters,[],$u,array_replace($b,['changes'=>['amount'=>1400]]),'now'),'Request identity reused for new amount');
reject_msa(fn()=>msa_amend($next,$masters,[],$u,array_replace($b,['requestKey'=>'fixture-amend-source-002']),'now'),'Stale reviewed source overwritten');
reject_msa(fn()=>msa_amend($values,$masters,[],$u,array_replace($b,['entity'=>'BRM']),'now'),'Cross-company amendment accepted');
reject_msa(fn()=>msa_amend($values,$masters,[],array_replace($u,['location'=>'Another mill']),$b,'now'),'Location restriction bypassed');
reject_msa(fn()=>msa_amend($values,$masters,[],array_replace($u,['actions'=>['View','Create']]),$b,'now'),'Create grant amended a saved source');
$key='MILL|TTI|1|tt38kebills|KE1';$books=['millLiabilities'=>[$key=>['billId'=>'MB1','journalId'=>'POST1']],'supplierSettlements'=>[['entity'=>'TTI','status'=>'Posted','allocations'=>[['billId'=>'MB1','amount'=>200]]]]];
reject_msa(fn()=>msa_amend($values,$masters,$books,$u,$b,'now'),'Paid source amended');
$books=['productionCostRuns'=>[['entity'=>'TTI','calculation'=>['conversionSources'=>[['id'=>'KE|KE1']]]]]];reject_msa(fn()=>msa_amend($values,$masters,$books,$u,$b,'now'),'Approved cost source amended');

reject_msa(fn()=>msa_guard_append($values,'tt38kebills',json_encode([$r,$r]),$u),'Duplicate bill identity accepted');
reject_msa(fn()=>msa_guard_append($values,'tt38kebills','[]',$u),'Collection deletion bypassed source audit');
reject_msa(fn()=>msa_guard_append($values,'tt38kebills',json_encode([array_replace($r,['amount'=>1200])]),$u),'Collection mutation bypassed source audit');
$r2=array_replace($r,['id'=>'KE2','ref'=>'KE-OCT']);check_msa(msa_guard_append($values,'tt38kebills',json_encode([$r,$r2]),$u)===json_encode([$r,$r2]),'Legitimate scoped append blocked');
$lab=['id'=>'L1','entity'=>'TTI','millId'=>1,'millName'=>'TTI Rice Mills','party'=>'Contractor','contractor'=>'Contractor','date'=>'2026-10-17','from'=>'2026-09-01','till'=>'2026-09-30','commodity'=>'Rice','billNo'=>'LB-1','ref'=>'LB-1','lines'=>[['label'=>'Work','qty'=>100,'rate'=>1,'basis'=>'100 kg']],'amount'=>1];
$body=['collection'=>'tt38labourbills','entity'=>'TTI','sourceId'=>'L1','fingerprint'=>ml_fingerprint($lab),'requestKey'=>'fixture-lab-amend-001','reason'=>'Correct agreed unit rate','changes'=>['lines'=>[['label'=>'Work','qty'=>100,'rate'=>1.235,'basis'=>'100 kg']]]];
$amended=msa_amend(['tt38labourbills'=>json_encode([$lab])],$masters,[],$u,$body,'now');check_msa($amended['record']['amount']===1.24&&$amended['record']['lines'][0]['rate']===1.235,'Unit rate prematurely rounded');
$rp=['id'=>'RP1','entity'=>'TTI','millId'=>2,'millName'=>'External Mill','mill'=>'External Mill','party'=>'External Mill','date'=>'2026-10-17','from'=>'2026-09-01','till'=>'2026-09-30','billNo'=>'RPB-1','ref'=>'RPB-1','arrivalKg'=>1000,'rawRemovedKg'=>0,'billableKg'=>1000,'rate'=>2,'loadingExtra'=>0,'other'=>0,'amount'=>2000,'paid'=>0,'payments'=>[]];
$ops=['tt38reprocessbills'=>json_encode([$rp]),'tt30slips'=>json_encode([['id'=>'PH1','entity'=>'TTI','millId'=>2,'millName'=>'External Mill','unloadingDate'=>'2026-09-10','payableWeight'=>1000],['id'=>'OTHER','entity'=>'BRM','millId'=>2,'millName'=>'External Mill','unloadingDate'=>'2026-09-10','payableWeight'=>9000]])];
$review=msa_reprocess_arrivals($ops,$rp,$rp['from'],$rp['till']);check_msa($review['arrivalKg']===1000.0,'Reprocessing preview mixed companies');
$body=['collection'=>'tt38reprocessbills','entity'=>'TTI','sourceId'=>'RP1','fingerprint'=>ml_fingerprint($rp),'requestKey'=>'fixture-rp-amend-001','reason'=>'Correct agreed rate','arrivalFingerprint'=>$review['fingerprint'],'changes'=>['rate'=>2.5]];
$rpUser=array_replace($u,['location'=>'External Mill']);$out=msa_amend($ops,$masters,[],$rpUser,$body,'now');check_msa($out['record']['amount']===2500.0,'Actual reprocessing quantity/rate not recalculated');
reject_msa(fn()=>msa_amend($ops,$masters,[],$rpUser,array_replace($body,['arrivalFingerprint'=>'stale']),'now'),'Unreviewed arrivals accepted');
echo "PASS audited source amendment, actor/original preservation, replay, scope and paid/cost holds, shared-writer bypass prevention, exact labour rate and company-scoped reprocessing arrival review\n";

$processing=['id'=>'EX1','entity'=>'TTI','millId'=>1,'millName'=>'TTI Rice Mills','date'=>'2026-10-17','from'=>'2026-10-01','till'=>'2026-10-15','ref'=>'PLANT-OCT','party'=>'K-Electric','amount'=>700,'type'=>'Plant Expense','settlementMode'=>'CANONICAL_PAYABLE'];
$body=['collection'=>'tt37processingexpenses','entity'=>'TTI','sourceId'=>'EX1','fingerprint'=>ml_fingerprint($processing),'requestKey'=>'fixture-exp-amend-001','reason'=>'Correct actual provider bill','changes'=>['amount'=>800]];
$ops=['tt37processingexpenses'=>json_encode([$processing])];$out=msa_amend($ops,$masters,[],$u,$body,'2026-10-18T12:00:00Z');check_msa($out['record']['amount']===800.0&&$out['record']['_sourceAmendments'][0]['before']===$processing,'Processing amendment lost original source');
$costs=['productionCostRuns'=>[['entity'=>'TTI','calculation'=>['conversionSources'=>[['id'=>'EXP|EX1']]]]]];reject_msa(fn()=>msa_amend($ops,$masters,$costs,$u,$body,'now'),'Approved processing cost source was amended');
reject_msa(fn()=>msa_guard_append($ops,'tt37processingexpenses','[]',$u),'Processing source deleted through collection save');
echo "PASS processing source audit, explicit append and approved-cost dependency lock\n";
