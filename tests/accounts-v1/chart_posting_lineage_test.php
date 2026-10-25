<?php
declare(strict_types=1);
require __DIR__.'/../../api/accounts_subaccounts_core.php';
require __DIR__.'/../../api/accounts_chart_word_core.php';
function expect_coa(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
$line=static fn(string $code,float $dr=0,float $cr=0,array $extra=[]):array=>['account'=>$code,'debit'=>$dr,'credit'=>$cr]+$extra;
$journal=static fn(string $id,array $lines,string $source='FIXTURE',string $status='Posted',string $date='2026-07-10'):array=>['id'=>$id,'entity'=>'TTI','date'=>$date,'status'=>$status,'sourceType'=>$source,'lines'=>$lines];
$s=['revision'=>4,'journals'=>[
 'MILL'=>$journal('MILL',[$line('1310',100),$line('2210',0,100)],'EX_MILL_PURCHASE_LIABILITY','Posted / Bill Pending'),
 'OTHER'=>$journal('OTHER',[$line('1310',999),$line('2210',0,999)],'OTHER','Posted / Bill Pending'),
 'FUTURE'=>$journal('FUTURE',[$line('1310',888),$line('2210',0,888)],'FIXTURE','Posted','2027-01-01'),
 'BAD'=>$journal('BAD',[$line('1310',777),$line('2210',0,777)],'FIXTURE','Posted','2026-02-30'),
 'BILL'=>$journal('BILL',[$line('1310',50),$line('2300',0,50)],'COMMODITY_BILL_VERIFIED')+['meta'=>['brokerageWithholding'=>50]],
 'DEPOSIT'=>$journal('DEPOSIT',[$line('2300',50,0,['subaccountId'=>'TTI-DEFAULT-TAX-BROKERAGE-PAYABLE']),$line('1110',0,50)],'BROKERAGE_WHT_DEPOSIT'),
 'UNPROVEN'=>$journal('UNPROVEN',[$line('6900',5),$line('2300',0,5)],'FIXTURE'),
 'HISTORY'=>$journal('HISTORY',[$line('6900',10,0,['subaccountId'=>'CHILD']),$line('1120',0,10)]),
], 'accountSubaccounts'=>[
 'PARENT'=>['id'=>'PARENT','entity'=>'TTI','name'=>'Fixture group','parentCode'=>'6900','active'=>true,'taxCategory'=>'NONE'],
 'CHILD'=>['id'=>'CHILD','entity'=>'TTI','name'=>'Fixture child','parentId'=>'PARENT','parentCode'=>'6900','active'=>true,'taxCategory'=>'NONE'],
 'UNUSED'=>['id'=>'UNUSED','entity'=>'TTI','name'=>'Unused group','parentCode'=>'6900','active'=>true,'taxCategory'=>'NONE'],
]];
$before=serialize($s);$p=sac_payload($s,'TTI','','2026-10-25');$h=array_column($p['heads'],null,'code');$subs=array_column($p['subaccounts'],null,'id');
expect_coa($h['1310']['directBalance']===150.&&$h['2210']['directBalance']===-100.,'Head balances must include posted/bill-pending Mill receipts only, at valid actual cutoffs.');
expect_coa($h['2300']['directBalance']===-5.&&$subs['TTI-DEFAULT-TAX-BROKERAGE-PAYABLE']['balance']===0.,'Native withholding accrual and its deposit must reconcile in the same named subsidiary.');
expect_coa(sac_line_subaccount_id($s,$s['journals']['UNPROVEN'],$s['journals']['UNPROVEN']['lines'][1])==='','Unproven tax must remain unassigned.');
$foreign=$s['journals']['BILL'];$foreign['entity']='TG';expect_coa(sac_line_subaccount_id($s,$foreign,$foreign['lines'][1])==='','Do not create Pakistan withholding defaults in TG.');
expect_coa(sac_line_subaccount_id($s,$s['journals']['BILL'],$line('2300',0,50,['subaccountId'=>'EXPLICIT']))==='EXPLICIT','An explicit historical subsidiary wins.');
expect_coa(serialize($s)===$before,'All balance/subsidiary projections must leave source books unchanged.');
try{sac_master($s,'TTI',['operation'=>'edit','id'=>'PARENT','name'=>'Fixture group','parentCode'=>'1400'],[]);throw new RuntimeException('Moved posted descendant to another head.');}
catch(DomainException $e){expect_coa(str_contains($e->getMessage(),'posted history'),'Reject the actual lineage violation.');}
expect_coa(serialize($s)===$before,'Rejected subtree movement must be atomic.');
sac_master($s,'TTI',['operation'=>'edit','id'=>'UNUSED','name'=>'Unused group','parentCode'=>'1400'],[]);
expect_coa($s['accountSubaccounts']['UNUSED']['parentCode']==='1400','An unused account can still move.');
$master=json_decode(file_get_contents(__DIR__.'/../../accounts/accounting_master_v1.json'),true);
$doc=acw_document($master,$s,[],['TTI'],'2026-10-25');
expect_coa(str_contains($doc,'150.00')&&str_contains($doc,'100.00')&&!str_contains($doc,'888.00')&&!str_contains($doc,'999.00'),'Word chart must share posted/bill-pending and cutoff treatment.');
$chart=sac_chart();expect_coa($chart['1140']['parent']==='1200'&&$chart['2510']['parent']==='2100'&&$chart['2520']['parent']==='2100','VAT, customer advances and third-party liabilities must have explicit correct heads.');
expect_coa($chart['7200']['name']==='Donations'&&$chart['7300']['class']==='Expense'&&str_contains($chart['2170']['name'],'Indentor')&&str_contains($chart['2520']['name'],'Third Parties'),'Donation/tax and indentor/third-party identities must remain distinct.');
echo "PASS native pending-bill head/Word parity, dated read-only balances, proven withholding subsidiaries, unproven/foreign/explicit identity preservation and immutable used-ledger lineage\n";
