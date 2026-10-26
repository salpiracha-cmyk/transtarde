<?php
declare(strict_types=1);
require __DIR__.'/../../api/group_management_core.php';
function check(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
$j=static fn($id,$entity,$lines,$meta=[])=>['id'=>$id,'entity'=>$entity,'date'=>'2026-01-01','status'=>'Posted','lines'=>$lines,'meta'=>$meta];
$bank=static fn($currency,$native,$book,$id)=>['account'=>'1110','bankAccountId'=>$id,'currency'=>$currency,'bankDebit'=>max(0,$native),'bankCredit'=>max(0,-$native),'debit'=>max(0,$book),'credit'=>max(0,-$book)];
$state=['revision'=>4,'journals'=>[
 'USD-IN'=>$j('USD-IN','TG',[$bank('USD',100,367,'U')]),
 'USD-OUT'=>$j('USD-OUT','TG',[$bank('USD',-20,-73.4,'U')]),
 'AED-IN'=>$j('AED-IN','TG',[$bank('AED',1000,1000,'A')]),
 'PKR-IN'=>$j('PKR-IN','TTI',[$bank('PKR',2000,2000,'P')]),
 'CUSTOMER'=>$j('CUSTOMER','TG',[['account'=>'1210','counterparty'=>'Example Buyer','debit'=>1835,'credit'=>0]],['transactionCurrency'=>'USD','transactionAmount'=>500,'functionalRate'=>3.67]),
 'APPLY'=>$j('APPLY','TG',[['account'=>'1210','counterparty'=>'Example Buyer','currency'=>'USD','nativeDebit'=>0,'nativeCredit'=>100,'debit'=>0,'credit'=>367]]),
 // Pakistani tax recoverables are PKR, even when the advice principal is USD.
 'TAX'=>$j('TAX','TTI',[['account'=>'1260','debit'=>25,'credit'=>0]],['transactionCurrency'=>'USD','transactionAmount'=>100]),
 'KAS-A'=>$j('KAS-A','TTI',[['account'=>'1250','counterparty'=>'Example Supplier','debit'=>500,'credit'=>0]]),
 'KAS-P'=>$j('KAS-P','TTI',[['account'=>'2110','counterparty'=>'Example Supplier','debit'=>0,'credit'=>500]]),
 'NEW-BILL'=>$j('NEW-BILL','TTI',[['account'=>'2210','counterparty'=>'Example Supplier','debit'=>0,'credit'=>60]]),
 'STAFF'=>$j('STAFF','TTI',[['account'=>'1230','counterparty'=>'JAZIB','debit'=>10,'credit'=>0]]),
 'REAL-RECEIVABLE'=>$j('REAL-RECEIVABLE','TTI',[['account'=>'1230','counterparty'=>'NASEEM','debit'=>10,'credit'=>0]])
]];
$before=$state;$book=gm_financial_source($state,'2026-01-02',[]);$cash=gm_cashflow_source($state,'2026-01-02',[]);$active=array_values(array_filter($cash['rows'],static fn($r)=>!$r['excluded']));
$find=static function($code,$currency)use($active){return array_values(array_filter($active,static fn($r)=>$r['account']===$code&&$r['currency']===$currency));};
check($find('1110','USD')[0]['amount']===80.0,'USD bank stays USD80, not AED293.40');
check($find('1110','AED')[0]['amount']===1000.0,'AED bank stays AED');
check($find('1210','USD')[0]['amount']===400.0,'customer advance applied once in native USD');
check(!$find('1260','PKR'),'tax ledger stays outside operational cash-flow receipts');
check(!$find('1250','PKR')&&!$find('2110','PKR'),'fully offset supplier controls have no net cash requirement');
check($find('2210','PKR')[0]['amount']===-60.0,'new uninvoiced liability remains included');
check(count($find('1230','PKR'))===2,'genuine receivables and current staff advances retained');
check($state===$before,'source journals are immutable');
check(count(array_filter($book['rows'],static fn($r)=>$r['account']==='1110'&&$r['currency']==='USD'))===0,'ordinary financial source remains functional currency');
$sum=gm_total($cash['rows'],['USD'=>280,'AED'=>76]);check($sum['totalPKR']===212360.0,'native USD and AED convert to PKR exactly once');
$parties=$state;$parties['journals']['USD-IN']['lines'][0]['counterparty']='Example Buyer';$parties['journals']['USD-OUT']['lines'][0]['counterparty']='Example Supplier';
$grouped=gm_cashflow_source($parties,'2026-01-02',[]);$bankRows=array_values(array_filter($grouped['rows'],static fn($r)=>$r['account']==='1110'&&$r['currency']==='USD'));
check(count($bankRows)===1&&$bankRows[0]['amount']===80.0,'one native bank balance across counterparties, one PKR conversion');
$cross=['journals'=>[
 'CA'=>$j('CA','TG',[['account'=>'1250','counterparty'=>'Cross Currency Supplier','currency'=>'USD','nativeDebit'=>100,'nativeCredit'=>0,'debit'=>367,'credit'=>0]]),
 'CP'=>$j('CP','TG',[['account'=>'2110','counterparty'=>'Cross Currency Supplier','currency'=>'AED','debit'=>0,'credit'=>367]])
]];
$crossRows=gm_cashflow_source($cross,'2026-01-02',[])['rows'];
check(count(array_filter($crossRows,static fn($r)=>!$r['excluded']))===2,'different native currencies cannot be offset just because AED books match');
// A multi-invoice advice must use each saved allocation, not its full receipt
// amount on every customer line. Resolve party names from the source candidate.
$multi=['revision'=>1,'exportCandidates'=>[
 'C1'=>['entity'=>'TTI','transactionCurrency'=>'USD','meta'=>['customer'=>'Buyer One']],
 'C2'=>['entity'=>'TTI','transactionCurrency'=>'USD','meta'=>['customer'=>'Buyer Two']]
],'journals'=>[
 'I1'=>$j('I1','TTI',[['account'=>'1210','counterparty'=>'Buyer One','debit'=>25000,'credit'=>0]],['candidateId'=>'C1','transactionCurrency'=>'USD','transactionAmount'=>100]),
 'I2'=>$j('I2','TTI',[['account'=>'1210','counterparty'=>'Buyer Two','debit'=>50000,'credit'=>0]],['candidateId'=>'C2','transactionCurrency'=>'USD','transactionAmount'=>200]),
 'R'=>$j('R','TTI',[['account'=>'1210','candidateId'=>'C1','debit'=>0,'credit'=>7500],['account'=>'1210','candidateId'=>'C2','debit'=>0,'credit'=>15000]],['transactionCurrency'=>'USD','transactionAmount'=>90,'allocations'=>[
 ['targetType'=>'EXPORT_RECEIVABLE','targetId'=>'C1','foreignAmount'=>30,'carryingPkr'=>7500],['targetType'=>'EXPORT_RECEIVABLE','targetId'=>'C2','foreignAmount'=>60,'carryingPkr'=>15000]
 ]])]];
$m=gm_cashflow_source($multi,'2026-01-02',[]);$amounts=[];foreach($m['rows'] as $r)$amounts[$r['party']]=$r['amount'];
check($amounts===['Buyer One'=>70.0,'Buyer Two'=>140.0],'receipt allocation clears each correct customer once');
$multi['journals']['ADJUST']=$j('ADJUST','TTI',[['account'=>'1210','counterparty'=>'Buyer One','transactionCurrency'=>'USD','transactionAmount'=>5,'debit'=>1250,'credit'=>0]],['transactionCurrency'=>'USD','transactionAmount'=>100]);
$m=gm_cashflow_source($multi,'2026-01-02',[]);$one=array_values(array_filter($m['rows'],static fn($r)=>$r['party']==='Buyer One'));
check($one[0]['amount']===75.0,'invoice adjustment uses its line amount, not full invoice');
// Equal functional balances cannot hide two different native currencies.
$state['journals']['MIXED']=$j('MIXED','TG',[
 ['account'=>'1210','counterparty'=>'Mixed Example','currency'=>'USD','nativeDebit'=>100,'nativeCredit'=>0,'debit'=>367,'credit'=>0],
 ['account'=>'1210','counterparty'=>'Mixed Example','currency'=>'AED','nativeDebit'=>0,'nativeCredit'=>367,'debit'=>0,'credit'=>367]
]);
$cash=gm_cashflow_source($state,'2026-01-02',[]);$mixed=array_values(array_filter($cash['rows'],static fn($r)=>str_contains($r['label'],'Mixed Example')));
check(count($mixed)===2,'zero functional net retains separate native currency balances');
check(array_sum(array_column($mixed,'amount'))===-267.0,'native principals remain separate');
// Missing original currency data must remain a hold, never zero or today's FX.
$state['journals']['UNKNOWN']=$j('UNKNOWN','TG',[['account'=>'1110','currency'=>'USD','bankAccountId'=>'UNKNOWN','debit'=>50,'credit'=>0]]);
$cash=gm_cashflow_source($state,'2026-01-02',[]);check(count(array_filter($cash['rows'],static fn($r)=>$r['amount']===null))===1,'missing native amount stays missing');
check(count(array_filter($cash['warnings'],static fn($r)=>str_contains($r,'UNKNOWN')))===1,'original Post ID identifies missing evidence');
echo "PASS native USD/AED cash-flow source, PKR conversion, matched controls, missing evidence, immutable books\n";

require __DIR__.'/cashflow_party_source_test.php';
