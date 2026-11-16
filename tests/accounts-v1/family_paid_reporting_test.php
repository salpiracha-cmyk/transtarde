<?php
declare(strict_types=1);
require __DIR__.'/../../api/accounts_subaccounts_core.php';
function fp_assert(bool $ok,string $msg):void{if(!$ok)throw new RuntimeException($msg);}
$s=['journals'=>[],'salaryMasters'=>[]];
foreach(['SALMAN'=>'MRS SRP','TALHA'=>'MRS TALHA','TAYYAB'=>'MRS TAYYAB'] as $owner=>$name){
 $id='M-'.$owner;$s['salaryMasters'][$id]=['id'=>$id,'entity'=>'TTI','name'=>$name,'category'=>'HOME_MONTHLY_GIVE'];
 $m=['salaryMasterId'=>$id,'person'=>$name,'category'=>'HOME_MONTHLY_GIVE'];
 $line=static fn($code,$dr,$cr)=>['account'=>$code,'debit'=>$dr,'credit'=>$cr]+$m;
 $put=static function($suffix,$source,$date,$lines)use(&$s,$owner){$id=$owner.'-'.$suffix;$s['journals'][$id]=['id'=>$id,'entity'=>'TTI','status'=>'Posted','date'=>$date,'sourceType'=>$source,'lines'=>$lines];return $id;};
 $put('ADV','SALARY_ADVANCE','2026-07-01',[$line('1230',20,0),['account'=>'1120','debit'=>0,'credit'=>20]]);
 $put('ACCRUAL','SALARY_MONTHLY_ACCRUAL','2026-07-31',[$line('3200',100,0),$line('1230',0,20),$line('2230',0,80)]);
 $put('PAY','SALARY_ALLOCATED_PAYMENT','2026-08-01',[$line('2230',30,0),['account'=>'1430','debit'=>500,'credit'=>0,'companyOwned'=>true],['account'=>'1110','debit'=>0,'credit'=>530]]);
 $put('DIRECT','EXPENSE','2026-08-02',[['account'=>'6910','debit'=>10,'credit'=>0,'expenseFor'=>'FAM-'.$owner],['account'=>'1120','debit'=>0,'credit'=>10]]);
 $put('ADJUST','SALARY_MONTH_COMPLETED','2026-08-03',[$line('2230',5,0),$line('1230',0,5)]);
 $before=serialize($s);$r=sac_family_statement($s,'TTI','FAMILY|'.$owner.'|HOME','2026-07-01','2026-08-31');
 fp_assert($r['closing']===60.0,$owner.' must count 20 advance + 30 paid + 10 expense, not 100 entitlement or 500 investment');
 fp_assert($r['unpaidHomeEntitlement']===45.0,'Unpaid entitlement must deduct payment and internal adjustment');
 fp_assert(serialize($s)===$before,'Reporting changed books');
 $r=sac_family_statement($s,'TTI','FAMILY|'.$owner.'|HOME','2026-08-01','2026-08-31');
 fp_assert($r['opening']===20.0&&$r['closing']===60.0,'Opening must use actual advance date');
 $orig=$s['journals'][$owner.'-PAY'];$reverse=$orig;$reverse['id']=$owner.'-REV';$reverse['date']='2026-09-01';$reverse['sourceType']='SALARY_ALLOCATED_PAYMENT_REVERSAL';$reverse['reversalOf']=$orig['id'];
 foreach($reverse['lines'] as &$l)[$l['debit'],$l['credit']]=[$l['credit'],$l['debit']];unset($l);$s['journals'][$reverse['id']]=$reverse;
 $r=sac_family_statement($s,'TTI','FAMILY|'.$owner.'|HOME','2026-07-01','2026-09-30');
 fp_assert($r['closing']===30.0&&$r['unpaidHomeEntitlement']===75.0,'Cancelled payment must not survive or consume entitlement');
 $r=sac_family_statement($s,'TTI','FAMILY|'.$owner.'|HOME','2026-07-01','2026-08-31');fp_assert($r['closing']===60.0,'Future reversal changed earlier report');
}
echo "PASS three families: entitlement separate, actual payments/advances once, company investment excluded, internal recovery excluded, dates and reversals, immutable books\n";
