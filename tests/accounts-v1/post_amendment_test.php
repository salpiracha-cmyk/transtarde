<?php
declare(strict_types=1);
function tt_list_masters():array{return ['banks'=>[['id'=>'TTI-1','values'=>['Company Account','TTI','','Meezan TTI','Meezan','','','PKR']],['id'=>'TG-1','values'=>['Company Account','TG','','TG USD','Bank','','','USD']]]];}
require dirname(__DIR__,2).'/api/accounts_post_amend_core.php';
function check(bool $valid,string $message):void{if(!$valid)throw new RuntimeException($message);}
function source(string $entity,string $id):array{return ['id'=>$id,'entity'=>$entity,'date'=>'2026-09-25','sourceType'=>'ANY_ACCOUNTS_WORKFLOW','reference'=>'REF','narration'=>'Original posting','lines'=>[['account'=>'1110','accountName'=>'Bank','bankAccountId'=>$entity==='TG'?'TG-1':'TTI-1','currency'=>$entity==='TG'?'USD':'PKR','bankDebit'=>100,'bankCredit'=>0,'debit'=>100,'credit'=>0],['account'=>'2130','accountName'=>'Payable','debit'=>0,'credit'=>100]],'totalDebit'=>100,'totalCredit'=>100,'status'=>'Posted','meta'=>['billId'=>'BILL-1'],'reversalOf'=>null];}
$user=['id'=>7,'username'=>'accountant'];
$store=['journals'=>['AUTO-1'=>source('TTI','AUTO-1')],'supplierBills'=>['BILL-1'=>['journalId'=>'AUTO-1','postingJournalIds'=>['AUTO-1']]]];
$lines=[['account'=>'1110','bankAccountId'=>'TTI-1','nativeAmount'=>100.99,'debit'=>100.99,'credit'=>0],['account'=>'2130','debit'=>0,'credit'=>100]];
$payload=['reason'=>'Bank advice correction','date'=>'2026-09-25','reference'=>'REF-2','narration'=>'Updated bank credit','lines'=>$lines];
$result=apa_correct($store,$user,'AUTO-1',$payload);
check($result['originalPostId']==='AUTO-1','Original posting must be identifiable.');
check($store['supplierBills']['BILL-1']['journalId']==='AUTO-1','Source bill must retain its historical journal link.');
check($store['journals'][$result['replacementPostId']]['sourceType']==='POST_AMENDMENT_CORRECTION','Corrected journal must not duplicate a source event.');
check(count($store['journals'][$result['replacementPostId']]['lines'])===3,'PKR 0.99 must be booked as rounding.');
$sum=[];foreach($store['journals'] as $journal)foreach($journal['lines'] as $line){$key=$journal['entity'].'|'.$line['account'];$sum[$key]=round(($sum[$key]??0)+(float)$line['debit']-(float)$line['credit'],2);}
check($sum['TTI|1110']===100.99,'Bank ledger must show corrected amount.');
check($sum['TTI|7195']===-0.99,'Rounding account must show the difference.');
try{apa_correct($store,$user,'AUTO-1',$payload);throw new RuntimeException('Double amendment accepted.');}catch(DomainException $expected){}
$bad=$payload;$bad['lines'][0]['debit']=101.00;$bad['lines'][0]['nativeAmount']=101.00;
try{apa_correct($store,$user,$result['replacementPostId'],$bad);throw new RuntimeException('PKR 1.00 imbalance accepted.');}catch(DomainException $expected){}
$tg=['journals'=>['AUTO-TG'=>source('TG','AUTO-TG')]];$tgInput=$payload;$tgInput['lines'][0]['bankAccountId']='TG-1';$tgInput['lines'][0]['nativeAmount']=100.01;$tgInput['lines'][0]['debit']=100.01;
try{apa_correct($tg,$user,'AUTO-TG',$tgInput);throw new RuntimeException('TG 0.01 imbalance accepted.');}catch(DomainException $expected){}
$tgInput['lines'][1]['credit']=100.01;$done=apa_correct($tg,$user,'AUTO-TG',$tgInput);
check($tg['journals'][$done['replacementPostId']]['totalDebit']===100.01,'Balanced TG correction must post.');
echo "Universal post amendment and currency tolerance checks passed.\n";
