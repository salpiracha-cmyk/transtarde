<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/api/tg_customer_shortfall_core.php';
function check(bool $v,string $message):void{if(!$v)throw new RuntimeException($message);}
function reject(callable $f):void{try{$f();}catch(DomainException $e){return;}throw new RuntimeException('Invalid input accepted');}
$s=['journals'=>['seed'=>['entity'=>'TG','status'=>'Posted','date'=>'2026-01-01','lines'=>[['account'=>'1210','counterparty'=>'Buyer A','currency'=>'USD','nativeDebit'=>120,'nativeCredit'=>0,'debit'=>440.4,'credit'=>0],['account'=>'1210','counterparty'=>'Buyer B','currency'=>'USD','nativeDebit'=>30,'nativeCredit'=>0,'debit'=>110.1,'credit'=>0]]]]];
$p=['date'=>'2026-06-30','reference'=>'TEST-CUSTOMER-SHORTFALL-2026','narration'=>'Historical short payments approved as correspondent bank charges.','rows'=>[['party'=>'Buyer A','amount'=>'120.00'],['party'=>'Buyer B','amount'=>'30.00']]];$u=['role'=>'Super Admin','full_name'=>'Test'];
$r=tgcs_prepare($s,$p,$u,3.67);check(!$r['replayed'],'New entry');$j=$r['journal'];check(count($j['lines'])===4&&$j['totalDebit']==550.5&&$j['totalCredit']==550.5,'Balanced native and book amounts');
check(array_unique(array_column($j['lines'],'currency'))===['USD'],'Native USD');foreach($j['lines'] as $l)check(in_array($l['account'],['6810','1210'],true),'No bank or VAT');
$s['journals']['posted']=$j;check(tgcs_balances($s,'2026-06-30')===['Buyer A'=>0,'Buyer B'=>0],'Both customer ledgers cleared');check(tgcs_prepare($s,$p,$u,3.67)['replayed'],'Retry returns original without duplicate');
$changed=$p;$changed['rows'][0]['amount']='119.00';reject(fn()=>tgcs_prepare($s,$changed,$u,3.67));reject(fn()=>tgcs_prepare($s,$p,['role'=>'Director'],3.67));
$fresh=$s;unset($fresh['journals']['posted']);$future=$fresh;$future['journals']['future']=['entity'=>'TG','status'=>'Posted','date'=>'2026-08-01','lines'=>[['account'=>'1210','counterparty'=>'Buyer A','currency'=>'USD','nativeDebit'=>0,'nativeCredit'=>1,'debit'=>0,'credit'=>3.67]]];reject(fn()=>tgcs_prepare($future,$p,$u,3.67));
$other=$fresh;$other['journals']['other']=['entity'=>'TTI','status'=>'Posted','date'=>'2026-01-01','lines'=>$j['lines']];check(tgcs_balances($other,'2026-06-30')===['Buyer A'=>12000,'Buyer B'=>3000],'Other entities isolated');
$bad=$p;$bad['rows'][]=$bad['rows'][0];reject(fn()=>tgcs_prepare($fresh,$bad,$u,3.67));echo "TG customer shortfalls: PASS\n";
