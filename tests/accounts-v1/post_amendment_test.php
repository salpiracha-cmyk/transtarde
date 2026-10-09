<?php
declare(strict_types=1);
function tt_next_post_id(array $existing, string $module = 'Accounts', string $area = 'Journal', ?string $date = null): string {
    $year=substr($date ?: date('Y-m-d'),0,4);
    $n=count($existing)+1;
    do {$id='POST-'.$year.'-'.str_pad((string)$n,5,'0',STR_PAD_LEFT);$n++;} while (isset($existing[$id]));
    return $id;
}
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

$pay=source('TTI','SALARY');$pay['sourceType']='SALARY_MONTH_COMPLETED';
$pay['lines']=[['account'=>'6210','accountName'=>'Salary','debit'=>100,'credit'=>0],['account'=>'1110','accountName'=>'Bank','bankAccountId'=>'TTI-1','bankCredit'=>100,'debit'=>0,'credit'=>100]];
$salary=['journals'=>['SALARY'=>$pay]];
$input=['reason'=>'Salary payment correction','date'=>'2026-10-01','lines'=>[['account'=>'6210','debit'=>100,'credit'=>0],['account'=>'1110','bankAccountId'=>'TTI-1','nativeAmount'=>100,'debit'=>0,'credit'=>100,'bankPaymentMethod'=>'CHEQUE','bankReference'=>'']]];
try{apa_correct($salary,$user,'SALARY',$input);throw new RuntimeException('Cheque correction without number accepted.');}catch(DomainException){}
$input['lines'][1]['bankPaymentMethod']='ONLINE_BANKING';$done=apa_correct($salary,$user,'SALARY',$input);
check($salary['journals'][$done['replacementPostId']]['lines'][1]['bankPaymentMethod']==='ONLINE_BANKING','Online amendment tracking missing.');
$large=[];for($i=0;$i<61;$i++){ $large[]=['account'=>'6210','debit'=>1,'credit'=>0];$large[]=['account'=>'2140','debit'=>0,'credit'=>1]; }
$input['lines']=$large;$done=apa_correct($salary,$user,$done['replacementPostId'],$input);
check(count($salary['journals'][$done['replacementPostId']]['lines'])===122,'Whole salary month amendment must support all people.');
$done=apa_correct($salary,$user,$done['replacementPostId'],$input);
check($salary['journals'][$done['replacementPostId']]['meta']['originalSourceType']==='SALARY_MONTH_COMPLETED','Salary origin must survive repeated amendments.');
echo "Salary month amendment size, required cheque number and optional online reference passed.\n";

$opening=['id'=>'POST-2026-00075','entity'=>'TTI','date'=>'2026-07-01','sourceType'=>'OPENING_BALANCE_BF','status'=>'Posted','reference'=>'','narration'=>'OPENING BALANCE B/F','meta'=>['openingBalance'=>true],'totalDebit'=>100,'totalCredit'=>100,'lines'=>[['account'=>'2110','accountName'=>'Supplier','subledger'=>'Haji Khushi Muhammad','counterparty'=>'Haji Khushi Muhammad','debit'=>0,'credit'=>100,'nativeDebit'=>0,'nativeCredit'=>100],['account'=>'3400','accountName'=>'Opening Balance Clearing','subledger'=>'','debit'=>100,'credit'=>0,'nativeDebit'=>100,'nativeCredit'=>0]]];
$book=['journals'=>[$opening['id']=>$opening],'jvDrafts'=>['OPEN'=>['journalId'=>$opening['id'],'status'=>'Posted']]];
$input=['reason'=>'Correct opening figure','date'=>'2026-10-09','narration'=>'','lines'=>[['account'=>'2110','subledger'=>'Haji Khushi Muhammad','credit'=>150,'debit'=>0],['account'=>'3400','subledger'=>'','debit'=>150,'credit'=>0]]];
$done=apa_correct($book,['role'=>'Super Admin','username'=>'owner'],$opening['id'],$input);$new=$book['journals'][$done['replacementPostId']];
check($new['date']==='2026-07-01'&&$new['sourceType']==='OPENING_BALANCE_BF','Opening corrections must stay at the opening boundary.');
check($done['publicPostId']===$opening['id']&&$new['lines'][0]['nativeCredit']===150.0,'Opening public identity and native amount must be preserved/corrected.');
check(count($done['changes'])===2&&$done['changes'][0]['oldCredit']===100,'Confirmation must show old and new amounts.');
$balance=0;foreach($book['journals'] as $j)foreach($j['lines'] as $l)if($l['account']==='2110')$balance+=$l['debit']-$l['credit'];check($balance===-150.0,'Authoritative opening ledger must show the corrected balance.');
check($book['jvDrafts']['OPEN']['status']==='Amended','Old opening register must retain history.');
echo "Opening balance amendment: stable Post ID, fixed date, old-to-new confirmation and corrected ledger passed.\n";

foreach(["75","075","00075","2026-00075"] as $query)check(tt_accounts_reference_matches($query,"POST-2026-00075"),"Post suffix/serial failed: ".$query);check(tt_accounts_reference_matches("75","POST-2026-01075"),"Suffix search must allow multiple matches.");check(!tt_accounts_reference_matches("2026","POST-2026-00075"),"A year is not a serial suffix.");

require_once dirname(__DIR__,2).'/api/accounts_post_delete_core.php';
function tt_post_correction_allowed(array $u,array $j):bool{return !empty($u['canEdit']);}
function tt_user_can_access_entity(array $u,string $e,string $action):bool{return !empty($u['canEdit'])&&$e==='TTI';}
$advanceJournal=['id'=>'POST-2026-00115','entity'=>'TTI','date'=>'2026-07-01','sourceType'=>'SALARY_ADVANCE','status'=>'Posted','meta'=>['salaryAdvanceId'=>'SADV-1'],'totalDebit'=>27000,'totalCredit'=>27000,'lines'=>[['account'=>'1230','debit'=>27000,'credit'=>0],['account'=>'1110','bankAccountId'=>'TTI-1','bankDebit'=>0,'bankCredit'=>27000,'debit'=>0,'credit'=>27000]]];
$advances=['journals'=>[$advanceJournal['id']=>$advanceJournal],'salaryAdvances'=>['SADV-1'=>['id'=>'SADV-1','entity'=>'TTI','journalId'=>$advanceJournal['id'],'amount'=>27000,'remaining'=>27000,'monthsRemaining'=>3,'adjustments'=>[]]]];
$owner=['canEdit'=>true,'username'=>'owner'];
check(apd_supported($advances,$advanceJournal),'Salary advance must expose supported cancellation.');
check(apd_delete_reason($advances,$advanceJournal,$owner)==='','Unapplied salary advance must be deletable.');
$blocked=$advances;$blocked['salaryAdvances']['SADV-1']['remaining']=26000;$blocked['salaryAdvances']['SADV-1']['adjustments']=[['month'=>'2026-07','amount'=>1000,'journalId'=>'MONTH']];$before=$blocked;
try{apd_delete($blocked,$owner,$advanceJournal['id'],['reason'=>'Wrong salary advance']);throw new RuntimeException('Applied advance deletion accepted.');}catch(DomainException $expected){}
check($blocked===$before,'Rejected advance cancellation must leave journal and source untouched.');
$denied=$advances;
try{apd_delete($denied,['canEdit'=>false],$advanceJournal['id'],['reason'=>'Wrong salary advance']);throw new RuntimeException('Unauthorised advance deletion accepted.');}catch(DomainException $expected){}
check($denied===$advances,'Permission rejection must not mutate the books.');
$done=apd_delete($advances,$owner,$advanceJournal['id'],['reason'=>'Wrong salary advance']);
check($advances['salaryAdvances']['SADV-1']['remaining']===0&&$advances['salaryAdvances']['SADV-1']['status']==='Deleted','Cancelled advance must leave future salary deductions.');
$reverse=$advances['journals'][$done['reversalPostIds'][0]];
check($reverse['lines'][1]['bankDebit']===27000&&$reverse['lines'][1]['bankCredit']===0,'Cancellation must restore bank-native funds exactly.');
check($advances['postDeletions'][0]['before'][$advanceJournal['id']]===$advanceJournal,'Immutable original journal must remain in deletion audit.');
check(apd_delete_reason($advances,$reverse,$owner)!=='','Cancellation audit rows must explain why they cannot be deleted again.');
try{apd_delete($advances,$owner,$advanceJournal['id'],['reason'=>'Repeated deletion']);throw new RuntimeException('Duplicate advance deletion accepted.');}catch(DomainException $expected){}
echo "Salary advance cancellation: permissions, applied-deduction guard, bank/native balance, source status and audit history passed.\n";
