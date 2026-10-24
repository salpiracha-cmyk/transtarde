<?php
declare(strict_types=1);
require __DIR__.'/../../api/group_management_core.php';
$rows=[];foreach([['1240','Intercompany receivable'],['2500','Intercompany payable'],['1250','Advance — TTI'],['2110','Payable — TRANSTRADE INTERNATIONAL'],['2510','Customer advance — TG'],['1120','Petty cash'],['2230','Salary control'],['1230','TG OWN HABIB AED TO USD'],['2140','TALHA'],['2130','Paklink Shipping Services'],['2140','CRYSTAL POLY'],['1230','NASEEM former guard'],['1230','ZAFAR PARACHA'],['1110','TG bank']] as $i=>$r)$rows[]=['id'=>(string)$i,'account'=>$r[0],'label'=>$r[1],'amount'=>100,'excluded'=>false];
$source=['rows'=>$rows,'basis'=>'Posted books'];$scoped=gm_cashflow_scope($source);
foreach($scoped['rows'] as $i=>$r){if($r['excluded']!==($i<9))throw new RuntimeException('Wrong cash-flow inclusion: '.$r['label']);if($r['amount']!==100)throw new RuntimeException('Amount changed');}
if($source['rows']!==$rows)throw new RuntimeException('Underlying balances mutated');echo "PASS cash flow excludes internal balances, petty cash and remuneration controls, preserving real external receivables and payables and all book values\n";
