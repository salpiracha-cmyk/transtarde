<?php
declare(strict_types=1);
require __DIR__.'/../../api/accounts_subaccounts_core.php';
$journal=static fn($id,$date,$lines,$extra=[])=>['id'=>$id,'entity'=>'TTI','status'=>'Posted','date'=>$date,'lines'=>$lines]+$extra;
$tax=static fn($debit,$credit,$category='EXPORT')=>['account'=>'1260','debit'=>$debit,'credit'=>$credit,'taxCategory'=>$category];
$state=['journals'=>[
 'OLD'=>$journal('OLD','2025-12-31',[$tax(500,0)]),
 'A'=>$journal('A','2026-01-01',[$tax(100,0),['account'=>'1110','debit'=>0,'credit'=>100]],['reference'=>'ADVICE1']),
 'R'=>$journal('R','2026-10-18',[$tax(0,20)],['reversalOf'=>'OLD']),
 'PAYABLE'=>$journal('PAYABLE','2026-10-18',[['account'=>'2300','debit'=>0,'credit'=>30,'taxCategory'=>'SERVICES']]),
 'FUTURE'=>$journal('FUTURE','2026-10-19',[$tax(600,0)]),
 'INVALID'=>$journal('INVALID','2026-02-30',[$tax(700,0)]),
 'UNDATED'=>$journal('UNDATED','',[$tax(800,0)]),
 'DRAFT'=>array_replace($journal('DRAFT','2026-10-18',[$tax(900,0)]),['status'=>'Draft']),
 'TG'=>array_replace($journal('TG','2026-10-18',[$tax(45,0)]),['entity'=>'TG'])
]];
$before=serialize($state);$rows=sac_tax_entries($state,'TTI','2026-01-01','2026-10-18');
if(count($rows)!==3)throw new RuntimeException('Cutoff/status/company filtering failed');
$p=sac_payload($state,'TTI','2026-01-01','2026-10-18');$by=array_column($p['taxReport'],null,'category');
if($by['EXPORT']['debit']!==100.||$by['EXPORT']['credit']!==20.||$by['SERVICES']['credit']!==30.)throw new RuntimeException('Summary does not reconcile to actual tax lines');
if($p['taxEntries']!==$rows||$p['taxFilters']!==['from'=>'2026-01-01','to'=>'2026-10-18']||$p['currency']!=='PKR')throw new RuntimeException('Export snapshot missing');
if(sac_tax_entries($state,'TG','2026-01-01','2026-10-18')[0]['currency']!=='AED')throw new RuntimeException('TG book currency lost');
if($rows[0]['reference']!=='ADVICE1'||$before!==serialize($state))throw new RuntimeException('Source identity lost or report mutated books');
echo "PASS WHT source transactions reconcile to summary; actual dates, inclusive cutoffs, currency, companies, reversals, source references and read-only books\n";
