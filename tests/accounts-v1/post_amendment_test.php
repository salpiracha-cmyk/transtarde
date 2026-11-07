<?php
declare(strict_types=1);
function tt_next_post_id(array $existing, string $module = 'Accounts', string $area = 'Journal', ?string $date = null): string {
    $year=substr($date ?: date('Y-m-d'),0,4);
    $n=count($existing)+1;
    do {$id='POST-'.$year.'-'.str_pad((string)$n,5,'0',STR_PAD_LEFT);$n++;} while (isset($existing[$id]));
    return $id;
}
function tt_list_masters():array{return ['business_parties'=>[['id'=>'BAG-FIXTURE','values'=>['Current Bag Party','','Bag Supplier']]],'banks'=>[['id'=>'TTI-1','values'=>['Company Account','TTI','','Meezan TTI','Meezan','','','PKR']],['id'=>'TG-1','values'=>['Company Account','TG','','TG USD','Bank','','','USD']]]];}
function tt_business_party_categories($v):array{return [$v];}
function tt_user_can_open_module(array $u,string $m):bool{return false;}
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
$legacyPay=$pay;$legacyPay['id']='LEGACY-CHEQUE';$legacyPay['lines'][1]['bankPaymentMethod']='CHEQUE';$legacyPay['lines'][1]['chequeDate']='2026-09-25';
$legacy=['journals'=>['LEGACY-CHEQUE'=>$legacyPay]];
$legacyInput=['reason'=>'Narration correction only','date'=>'2026-09-25','narration'=>'Corrected narration','lines'=>[['account'=>'6210','debit'=>100,'credit'=>0],['account'=>'1110','bankAccountId'=>'TTI-1','nativeAmount'=>100,'debit'=>0,'credit'=>100,'bankPaymentMethod'=>'CHEQUE','bankReference'=>'','chequeDate'=>'2026-09-25','paymentNarration'=>'']]];
$legacyDone=apa_correct($legacy,$user,'LEGACY-CHEQUE',$legacyInput);
check($legacy['journals'][$legacyDone['replacementPostId']]['narration']==='Corrected narration','Narration-only amendment must preserve unchanged legacy cheque tracking without demanding a missing historic number.');
$numbered=$legacyPay;$numbered['id']='NUMBERED-CHEQUE';$numbered['lines'][1]['chequeNo']='12345';$numbered['lines'][1]['bankReference']='12345';$numberedBook=['journals'=>['NUMBERED-CHEQUE'=>$numbered]];$removeNumber=$legacyInput;$removeNumber['reason']='Attempt to remove cheque number';
try{apa_correct($numberedBook,$user,'NUMBERED-CHEQUE',$removeNumber);throw new RuntimeException('Existing cheque number removal accepted.');}catch(DomainException){}
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

$dated=source('TTI','DATE-FIXTURE');$dated['date']='2026-10-07';$dates=['journals'=>['DATE-FIXTURE'=>$dated]];
$change=['date'=>'2026-08-02','reason'=>'Correct posting date','lines'=>[['account'=>'1110','bankAccountId'=>'TTI-1','debit'=>100,'credit'=>0],['account'=>'2130','debit'=>0,'credit'=>100]]];
$done=apa_correct($dates,$user,'DATE-FIXTURE',$change);
check($dates['journals'][$done['replacementPostId']]['date']==='2026-08-02','Use corrected posting date, not amendment time.');
check($dates['journals'][$done['reversalPostId']]['date']==='2026-10-07','Reverse the old date so backdating does not cancel a corrected entry before the original date.');
check($dates['journals'][$done['replacementPostId']]['meta']['publicPostId']==='DATE-FIXTURE','Keep public posting identity.');
echo "Corrected posting date, original-date reversal and stable register identity passed\n";

$old=$opening;$old['id']='OPEN-OLD-NAME';$old['lines'][0]=['account'=>'2140','accountName'=>'Service Payable','subledger'=>'Old Bag Name','counterparty'=>'Old Bag Name','debit'=>100,'credit'=>0];$old['lines'][1]['debit']=0;$old['lines'][1]['credit']=100;
$base=['journals'=>[$old['id']=>$old]];
$fix=['reason'=>'Correct opening direction and party','openingPartyTargetKey'=>'business_parties:BAG-FIXTURE:2140','lines'=>[['account'=>'2140','subledger'=>'Current Bag Party','debit'=>0,'credit'=>100],['account'=>'3400','subledger'=>'','debit'=>100,'credit'=>0]]];
foreach(['staff','wrong-target','linked-bill','paid'] as $case){
 $copy=$base;$bad=$fix;$actor=['role'=>'Super Admin'];
 if($case==='staff')$actor=['role'=>'Accounts'];
 if($case==='wrong-target')$bad['openingPartyTargetKey']='business_parties:unknown:2140';
 if($case==='linked-bill')$copy['carryForwardOpeningLinks']=[['journalId'=>$old['id']]];
 if($case==='paid')$copy['supplierSettlements']=[['status'=>'Posted','allocations'=>[['billId'=>'BF:'.$old['id'].':0']]]];
 $before=$copy;try{apa_correct($copy,$actor,$old['id'],$bad);throw new RuntimeException('Unsafe opening correction accepted: '.$case);}catch(DomainException){}
 check($copy===$before,'Rejected opening repair must leave all data unchanged: '.$case);
}
$book=$base;$done=apa_correct($book,['role'=>'Super Admin'],$old['id'],$fix);$new=$book['journals'][$done['replacementPostId']];
check($book['journals'][$old['id']]['lines']===$old['lines'],'Original opening lines remain immutable.');
check($new['lines'][0]['subledger']==='Current Bag Party'&&$new['lines'][0]['counterparty']==='Current Bag Party','All opening party references must agree.');
check($new['meta']['openingParty']==='Current Bag Party','Opening metadata must carry corrected party.');
$payables=array_values(sop_openings($book,'TTI'));check(count($payables)===1&&$payables[0]['party']==='Current Bag Party'&&$payables[0]['amount']===100.0,'Corrected creditor opening must appear once as payable.');
check($done['publicPostId']===$old['id'],'Opening review retains the public Post ID.');
echo "Opening party correction: creditor projection, audit, management access and linked-payment guards passed.\n";

$bank=source('TTI','BANK-DATE-FIXTURE');$bank['sourceType']='BANK_ENTRY';$bank['meta']=['bankEntryType'=>'PAYMENT','bankAccountId'=>'TTI-1','payee'=>'Fixture Party'];$bank['lines']=[['account'=>'2130','debit'=>100,'credit'=>0,'counterparty'=>'Fixture Party'],['account'=>'1110','bankAccountId'=>'TTI-1','currency'=>'PKR','debit'=>0,'credit'=>100,'bankDebit'=>0,'bankCredit'=>100,'bankPaymentMethod'=>'ONLINE_BANKING','bankPaymentDate'=>'2026-09-25','bankReference'=>'FIXTURE']];
$bank['meta']['sourceInput']=['type'=>'PAYMENT','date'=>'2026-09-25','bankId'=>'TTI-1','amount'=>100,'bankPaymentMethod'=>'ONLINE_BANKING','bankPaymentDate'=>'2026-09-25','debitRows'=>[['payeeId'=>'FIXTURE','amount'=>100,'bankPaymentMethod'=>'ONLINE_BANKING','bankPaymentDate'=>'2026-09-25']]];
$bank['meta']['debitRows']=$bank['meta']['sourceInput']['debitRows'];
$book=['journals'=>[$bank['id']=>$bank]];$input=['datesOnly'=>true,'date'=>'2026-08-05','reason'=>'Correct Bank Entry dates','lines'=>[['account'=>'WRONG','debit'=>999],['bankPaymentDate'=>'2026-08-04','credit'=>999]]];
$done=apa_correct($book,$user,$bank['id'],$input);$new=$book['journals'][$done['replacementPostId']];
check($new['sourceType']==='BANK_ENTRY'&&$new['date']==='2026-08-05','Bank dates correction must retain its source workflow and chosen posting date.');
check($new['lines'][0]['account']==='2130'&&$new['lines'][0]['debit']===100,'Date correction cannot change accounts or financial figures.');
check($new['lines'][1]['bankCredit']===100&&$new['lines'][1]['bankPaymentDate']==='2026-08-04','Bank amount is immutable; Online Banking date is separately saved.');
check($book['journals'][$done['reversalPostId']]['date']===$bank['date']&&$book['journals'][$bank['id']]['lines']===$bank['lines'],'Original-date reversal and immutable original lines are required.');
check($done['publicPostId']===$bank['id'],'Bank date correction must keep the public Post ID.');
$reopened=ben_edit_input($new);
check($reopened['date']==='2026-08-05'&&$reopened['bankPaymentDate']==='2026-08-04','Reopening the native bank form must retain corrected posting and Online Banking dates.');
check($reopened['debitRows'][0]['bankPaymentDate']==='2026-08-04','Reopening row-based payments must retain corrected per-row dates.');
check($new['meta']['reconciliationPostId']===$bank['id'],'A date-only correction must retain the original bank reconciliation identity.');
$bad=['journals'=>[$bank['id']=>$bank]];$before=$bad;$input['lines'][1]['bankPaymentDate']='2026-02-30';try{apa_correct($bad,$user,$bank['id'],$input);throw new RuntimeException('Invalid Online Banking date accepted.');}catch(DomainException){}check($bad===$before,'Rejected bank date correction must not mutate any journal.');
$draw=$bank;$draw['id']='DRAW';$draw['meta']=['bankEntryType'=>'FINANCE_DRAW','bankFacilityId'=>'FAC'];$later=$bank;$later['id']='LATER';$later['date']='2026-10-01';$later['meta']=['bankEntryType'=>'FINANCE_REPAY','bankFacilityId'=>'FAC'];$bad=['journals'=>['DRAW'=>$draw,'LATER'=>$later]];$before=$bad;$input['lines'][1]['bankPaymentDate']='2026-08-04';try{apa_correct($bad,$user,'DRAW',$input);throw new RuntimeException('Linked finance date changed before later movements.');}catch(DomainException){}check($bad===$before,'Finance date guard must retain the original books.');
echo "Bank Post ID date corrections: exact source, immutable amounts, separate Online Banking date, audit identity and finance guards passed.\n";

require_once dirname(__DIR__,2).'/api/tg_liability_projection.php';
$tgBills=['tgLiabilities'=>['USD-BILL'=>['id'=>'USD-BILL','currency'=>'USD','nativeAmount'=>100,'recognitionRate'=>3.67],'AED-BILL'=>['id'=>'AED-BILL','currency'=>'AED','nativeAmount'=>50,'recognitionRate'=>1]],'tgBankTransactions'=>[['kind'=>'Payment','liabilityAllocations'=>[['id'=>'USD-BILL','amountNative'=>30]]]]];
$before=$tgBills;$projected=tgl_supplier_rows($tgBills);check($projected[0]['outstandingNative']===70.0&&$projected[0]['outstandingAed']===256.9,'TG supplier balances must deduct linked payments and retain native currency.');check($projected[1]['outstandingNative']===50.0&&$tgBills===$before,'TG projection must preserve books and separate currencies.');
echo "Shared TG supplier dashboard projection: payments, invoice currencies and read-only storage passed.\n";

$old=source('TTI','POST-2026-000032-JV');$old['sourceType']='JV';$old['meta']=[];
$book=['journals'=>[$old['id']=>$old],'jvDrafts'=>['LEGACY'=>['id'=>'LEGACY','journalId'=>$old['id'],'status'=>'Posted']]];
$update=['reason'=>'Correct bank closure amount','date'=>'2026-09-16','reference'=>'ACCOUNT CLOSED','narration'=>'Account closure','lines'=>[['account'=>'1110','bankAccountId'=>'TTI-1','debit'=>80,'credit'=>0],['account'=>'2130','debit'=>0,'credit'=>80]]];
$corrected=apa_correct($book,$owner,$old['id'],$update);$current=$book['journals'][$corrected['replacementPostId']];
check(apd_supported($book,$current),'Amended JV must remain cancellable.');check(apd_delete_reason($book,$current,$owner)==='','Amended JV must not report a generic originating-workflow error.');
$done=apd_delete($book,$owner,$old['id'],['reason'=>'Owner requested cancellation of amended JV']);
check($done['publicPostId']===$old['id']&&$book['jvDrafts']['LEGACY']['status']==='Reversed','Follow correction chain and update original JV source status.');
check($done['cancelledPostIds']===[$current['id']],'Cancel only the active replacement, never repeat the old reversal.');
$undo=$book['journals'][$done['reversalPostIds'][0]];check(abs((float)$undo['totalDebit']-80)<.005&&$undo['date']===$current['date'],'Reverse current amount at its effective posting date.');
check($book['journals'][$old['id']]['lines']===$old['lines'],'Retain original JV history.');
echo "PASS amended JV cancellation: current replacement, stable public ID, correct amount, source status and immutable original history.\n";
