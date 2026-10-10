<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/api/supplier_opening_core.php';
function sop_test(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$s=['journals'=>['OPEN'=>['entity'=>'TG','status'=>'Posted','sourceType'=>'OPENING_BALANCE_BF','date'=>'2026-07-01','lines'=>[['account'=>'2140','subledger'=>'SUPPLIER','credit'=>100,'debit'=>0]]]]];
$o=sop_openings($s,'TG');sop_test($o['BF:OPEN:0']['currency']==='AED','TG opening must keep its book currency');sop_test(sop_openings($s,'TTI')===[],'Company books must remain isolated');
sop_assert_unpaid($s,'OPEN');
foreach(['supplierSettlements','tgBankTransactions'] as $collection){$test=$s;$test[$collection]=[['status'=>'Posted','kind'=>'Payment','allocations'=>[['billId'=>'BF:OPEN:0']],'liabilityAllocations'=>[['id'=>'BF:OPEN:0']]]];try{sop_assert_unpaid($test,'OPEN');throw new RuntimeException('Linked opening must be protected');}catch(DomainException $e){}$test[$collection][0]['status']=$collection==='supplierSettlements'?'Cancelled':'Reversed for Amendment';sop_assert_unpaid($test,'OPEN');}
echo "Supplier opening correction guards and TG book currency passed.\n";
