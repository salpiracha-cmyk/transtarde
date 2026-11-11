<?php
declare(strict_types=1);
require __DIR__.'/../../api/accounts_ledger_pagination.php';
$source=file_get_contents(__DIR__.'/../../api/accounts_dashboard.php');
$start=strpos($source,'function ad_paid_by_bill(');$end=strpos($source,'function ad_rows(', $start);
eval(substr($source,$start,$end-$start));
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$settlements=[];
for($i=0;$i<1000;$i++)$settlements[]=['status'=>['Posted','Approved / Posted','Approved','Reversed','Draft'][$i%5],'allocations'=>[['billId'=>(string)($i%71),'amount'=>($i%13)/1000],['billId'=>'B'.($i%19),'amount'=>-$i/100]]];
$indexed=ad_paid_by_bill(['supplierSettlements'=>$settlements]);
foreach(array_keys($indexed) as $id){$expected=0.0;foreach($settlements as $s)if(in_array($s['status'],['Posted','Approved / Posted','Approved'],true))foreach($s['allocations'] as $a)if($a['billId']===(string)$id)$expected+=$a['amount'];check($indexed[$id]===round($expected,2),'Settlement total or rounding changed');}
$rows=[];for($i=1;$i<=121;$i++)foreach([0,1] as $line)$rows[]=['voucher'=>'POST-2026-'.str_pad((string)$i,5,'0',STR_PAD_LEFT),'date'=>'2026-11-11','entity'=>'TTI','bookCurrency'=>'PKR','debit'=>10.25,'credit'=>2.25,'balance'=>$i*16+$line,'statementLine'=>(string)$line];
$seen=[];for($page=1;$page<=3;$page++){$result=alb_page($rows,$page,50);check(count($result['rows'])===($page<3?100:42),'Page split a posting');check($result['periodTotals']===['debit'=>2480.5,'credit'=>544.5],'Page totals must cover all rows');check($result['registerTotals'][0]['debit']===2480.5,'Register totals truncated');foreach($result['rows'] as $r){$key=$r['voucher'].'|'.$r['statementLine'];check(!isset($seen[$key]),'Repeated line');$seen[$key]=$r;}}
check(count($seen)===count($rows),'Missing lines');foreach($rows as $r)check($seen[$r['voucher'].'|'.$r['statementLine']]===$r,'Running balance or content changed');
check(alb_page($rows,1,50)['rows'][0]['voucher']==='POST-2026-00121','Newest posting first');check(alb_page($rows,999,50)['pagination']['page']===3,'Clamp out-of-range page');check(alb_page([],0,50)['pagination']['pages']===1,'Empty ledger');
echo "PASS settlement parity and rounding; 50 whole postings/page; full totals and unchanged running balances\n";
