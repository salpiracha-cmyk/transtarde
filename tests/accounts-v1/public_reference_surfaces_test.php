<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/api/bank_reconciliation_core.php';
require dirname(__DIR__,2).'/api/accounts_subaccounts_core.php';
function expect(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$old='POST-2026-00311';$corrected='POST-2026-00355';
$entry=static fn(string $id,float $amount,array $meta=[]):array=>['id'=>$id,'entity'=>'TTI','date'=>'2026-09-16','status'=>'Posted','sourceType'=>'GENERAL_EXPENSE_PAYMENT','reference'=>'PAYMENT','narration'=>'Office expense','meta'=>$meta+['payee'=>'Fixture vendor'],'lines'=>[['account'=>'6900','debit'=>$amount,'credit'=>0,'counterparty'=>'Fixture vendor'],['account'=>'1110','debit'=>0,'credit'=>$amount,'bankAccountId'=>'BANK']]];
$s=['journals'=>[$old=>$entry($old,100),$corrected=>$entry($corrected,95,['amendmentOfPostId'=>$old,'publicPostId'=>$old])]];
$s['journals'][$old]['amendedByPostId']=$corrected;
$reverse=$entry('REV',0);$reverse['reversalOf']=$old;$reverse['lines']=[['account'=>'6900','debit'=>0,'credit'=>100,'counterparty'=>'Fixture vendor'],['account'=>'1110','debit'=>100,'credit'=>0,'bankAccountId'=>'BANK']];$s['journals']['REV']=$reverse;
$s['journals']['JV-2026-000032']=['id'=>'JV-2026-000032','entity'=>'TTI','date'=>'2026-09-17','status'=>'Posted','sourceType'=>'JV','meta'=>[],'lines'=>[['account'=>'1110','debit'=>10,'credit'=>0,'bankAccountId'=>'BANK'],['account'=>'2140','debit'=>0,'credit'=>10]]];
$before=json_encode($s);
$bank=br_txns($s,'TTI','BANK','2026-10-15','PKR');
$row=array_values(array_filter($bank['rows'],static fn($r)=>$r['journalId']==='POST-2026-00355'))[0];
expect($row['publicPostId']===$old,'Reconciliation must display the same original identity as the ledger');expect($row['key']===$corrected.'|1','Stored reconciliation key must remain the internal correction line');
$jv=array_values(array_filter($bank['rows'],static fn($r)=>$r['journalId']==='JV-2026-000032'))[0];expect($jv['publicPostId']==='POST-2026-000032-JV','Historical JV suffix must match the register');
$activity=sac_expense_activity($s,'TTI','2026-07-01','2026-10-15');$expense=array_values(array_filter($activity,static fn($r)=>$r['journalId']==='POST-2026-00355'))[0];expect($expense['publicPostId']===$old,'Expense report must retain the original Post ID');expect($expense['voucher']===$corrected,'Edit/drilldown must retain the actual correction ID');expect($expense['amount']===95.0,'Displayed identity must not alter the amount');expect(json_encode($s)===$before,'Reference projections must never rewrite journals or keys');
echo "PASS public identities across expense/reconciliation, JV suffix, immutable correction keys and zero writes\n";
