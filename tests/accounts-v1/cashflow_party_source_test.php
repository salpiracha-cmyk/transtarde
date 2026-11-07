<?php
declare(strict_types=1);
require_once __DIR__.'/../../api/group_management_core.php';
function assert_party(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$j=static fn($id,$lines,$meta=[])=>['id'=>$id,'entity'=>'TTI','date'=>'2026-01-01','status'=>'Posted','lines'=>$lines,'meta'=>$meta];
$s=['commodityBills'=>['B'=>['relationshipName'=>'Example Rice','broker'=>'Example Broker']],'journals'=>[
 'B'=>$j('B',[['account'=>'2110','credit'=>500,'debit'=>0]],['billId'=>'B','originalSourceType'=>'COMMODITY_BILL_VERIFIED']),
 'P'=>$j('P',[['account'=>'1250','subledger'=>'Example Rice','debit'=>500,'credit'=>0]]),
 'L'=>$j('L',[['account'=>'2130','debit'=>0,'credit'=>70]],['supplier'=>'Example Transport']),
 'LP'=>$j('LP',[['account'=>'2130','subledger'=>'Example Transport','debit'=>70,'credit'=>0]]),
 'O'=>$j('O',[['account'=>'2140','credit'=>90,'debit'=>0]],['supplier'=>'Example Packaging']),
 'A'=>$j('A',[['account'=>'1230','person'=>'Example Staff','debit'=>10,'credit'=>0]]),
 'U'=>$j('U',[['account'=>'2130','debit'=>0,'credit'=>20]]),
 'T'=>$j('T',[['account'=>'1260','debit'=>30,'credit'=>0]]),
 'I'=>$j('I',[['account'=>'1310','debit'=>1000,'credit'=>0]]),
 'E'=>$j('E',[['account'=>'2210','credit'=>40,'debit'=>0]],['supplier'=>'Example Packaging'])
]];
$s['journals']['A']['sourceType']='SALARY_ADVANCE';
$before=$s;$cash=gm_cashflow_source($s,'2026-01-02',[]);$active=array_values(array_filter($cash['rows'],static fn($r)=>!$r['excluded']));
assert_party(count($active)===3,'Only staff, actual packaging payable and separate estimate remain');
assert_party(!array_filter($active,static fn($r)=>in_array($r['party'],['Example Rice','Example Transport'],true)),'Named source bills and subledger payments settle once');
assert_party(gm_total($cash['rows'],[])['totalPKR']===-120.0,'Net cash position excludes tax, inventory and unidentified ledger controls');
assert_party(count($cash['warnings'])>0,'Unidentified party is held visibly for review');
assert_party($s===$before,'No accounting journal, date or allocation is changed by reporting');
$masters=['business_parties'=>[['id'=>'P1','values'=>['Example Rice','ER']]]];
require_once __DIR__.'/../../api/cashflow_party_core.php';$aliases=$s;$aliases['journals']['P']['lines'][0]['subledger']='ER';$normalized=cfp_books($aliases,$masters);
assert_party($normalized['journals']['P']['lines'][0]['counterparty']==='Example Rice','Unique saved master code resolves to canonical party');
echo "PASS party cash flow: supplier/payment identities, settled controls, separate estimates, source review and immutable books\n";

$invoiceState=['exportCandidates'=>['C'=>['id'=>'C','entity'=>'TG','candidateType'=>'CUSTOMER_EXPORT_SALE','transactionCurrency'=>'USD','transactionAmount'=>150,'journalId'=>'POST-INVOICE','meta'=>['commercialInvoiceNo'=>'TEST/3','commercialInvoiceDate'=>'2026-01-01','contractRef'=>'EX3','customer'=>'Example Buyer Two']]],'exportReceipts'=>[]];
$exports=['contracts'=>[['ref'=>'EX1','seller'=>'TTI'],['ref'=>'EX2','seller'=>'TTI']],'shipments'=>[
 ['id'=>'S1','contractRef'=>'EX1','seller'=>'TTI','buyer'=>'Example Buyer One','commercial'=>['saved'=>true,'status'=>'Final','invoiceNo'=>'TEST/1','currency'=>'USD','date'=>'2026-01-01','netAmount'=>100]],
 ['id'=>'S2','contractRef'=>'EX2','seller'=>'TTI','buyer'=>'Example Buyer One','commercial'=>['saved'=>true,'status'=>'Final','invoiceNo'=>'TEST/2','currency'=>'USD','date'=>'2026-01-01','netAmount'=>200]]
]];
$invoiceSource=['rows'=>[['id'=>'OLD','account'=>'1210','entity'=>'TTI','party'=>'Example Buyer One','amount'=>300]],'warnings'=>[]];
$invoices=cfp_invoices($invoiceSource,$invoiceState,'2026-01-02',$exports);
assert_party(count($invoices['rows'])===3&&array_sum(array_column($invoices['rows'],'amount'))===450.0,'Final issued invoices join recognized invoices once, replacing duplicate book receivables');
assert_party(count(array_filter($invoices['rows'],static fn($r)=>!$r['recognized']))===2,'Unrecognized final invoices are explicit forecasts, not invented postings');
echo "PASS final invoice cash flow: two issued invoices plus recognized buyer invoice, no duplicate trade-receivable total\n";
