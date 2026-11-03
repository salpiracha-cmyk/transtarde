<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/api/journal_activity_core.php';
function assert_event(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$j=['id'=>'ORIGINAL-POST','date'=>'2026-10-31','sourceType'=>'SALARY_MONTH_COMPLETED','narration'=>'Salary completed — 2026-10','meta'=>['month'=>'2026-10','paymentPostIds'=>['SEPARATE-PAYMENT']],'lines'=>[['account'=>'2230','debit'=>100,'credit'=>0],['account'=>'1230','debit'=>0,'credit'=>100]]];
$original=serialize($j);$event=jac_event($j);assert_event(!$event['recentVoucher']&&!$event['cashMovement']&&$event['kind']==='PAYROLL_NONCASH_ADJUSTMENT','Advance offset presented as a new salary payment');assert_event(serialize($j)===$original,'Read-only event classification changed the original journal');
$family=$j;$family['lines']=[['account'=>'6910','debit'=>200,'credit'=>0],['account'=>'3200','debit'=>0,'credit'=>200]];assert_event(!jac_event($family)['recentVoucher'],'Noncash family allocation presented as payment');
$accrual=$j;$accrual['sourceType']='SALARY_MONTHLY_ACCRUAL';$accrual['lines']=[['account'=>'6210','debit'=>300,'credit'=>0],['account'=>'2230','debit'=>0,'credit'=>300]];assert_event(jac_event($accrual)['kind']==='SALARY_ACCRUAL','Salary payable accrual not classified correctly');
$payment=$j;$payment['sourceType']='SALARY_ALLOCATED_PAYMENT';$payment['lines']=[['account'=>'2230','debit'=>300,'credit'=>0],['account'=>'1110','debit'=>0,'credit'=>300]];assert_event(jac_event($payment)['recentVoucher']&&jac_event($payment)['cashMovement'],'Actual bank salary payment disappeared');
$rent=['date'=>'2026-10-31','sourceType'=>'JV','narration'=>'October release prepaid rent; no new payment','lines'=>[['account'=>'6300','debit'=>500,'credit'=>0],['account'=>'1400','debit'=>0,'credit'=>500]]];assert_event(jac_event($rent)['kind']==='PREPAID_RENT_RELEASE'&&!jac_event($rent)['recentVoucher'],'Prepaid release presented as a second rent payment');
$actualRent=$rent;$actualRent['lines'][1]['account']='1120';assert_event(jac_event($actualRent)['recentVoucher'],'Actual rent cash payment disappeared');
$manual=['sourceType'=>'JV','narration'=>'Owner review adjustment','lines'=>[['account'=>'1230','debit'=>50,'credit'=>0],['account'=>'3200','debit'=>0,'credit'=>50]]];assert_event(jac_event($manual)['recentVoucher'],'Unrelated manually posted journal was hidden');
$opening=$j;$opening['sourceType']='SALARY_ADVANCE_OPENING';assert_event(jac_event($opening)['recentVoucher'],'Manual opening journal was treated as a monthly generated event');
assert_event(jac_salary_payment_state(300,0,0,true)['code']==='ACCRUED_PREPARED','Unpaid entitlement marked complete');
assert_event(jac_salary_payment_state(300,100,0,true)['code']==='PARTIALLY_PAID','Partial salary status incorrect');
assert_event(jac_salary_payment_state(300,300,0,true)['code']==='FULLY_PAID','Actual complete salary payment missing');
assert_event(jac_salary_payment_state(300,0,300,true)['code']==='SETTLED_BY_DEDUCTIONS','Advance offset labelled as new payment');
echo "PASS journal activity: source-based salary accrual/offset/family/payment distinction, genuine prepaid-rent release, retained original IDs and entries, unrelated vouchers preserved, and separate payment states\n";

$rent+=['id'=>'RENT-POST','entity'=>'TTI','status'=>'Posted'];$rows=jac_rent_releases(['journals'=>['RENT-POST'=>$rent,'OTHER-COMPANY'=>array_replace($rent,['entity'=>'BRM']),'CANCELLED'=>array_replace($rent,['reversedByPostId'=>'REV'])]],'TTI','2026-10');assert_event(count($rows)===1&&$rows[0]['postId']==='RENT-POST'&&$rows[0]['amount']===500.,'Existing manual prepaid release omitted or cancelled/other-company journals included');
assert_event(jac_rent_releases(['journals'=>[$rent]],'TTI','2026-11')===[],'Wrong-month rent release included');
echo "PASS rent release history retains original Post IDs and excludes other companies, months and cancelled entries\n";
