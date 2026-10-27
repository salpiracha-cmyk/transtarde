<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/api/mill_recovery_core.php';
function verify(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$run=['id'=>'308','entity'=>'TTI','date'=>'2026-09-21','shift'=>'Day','variety'=>'IRRI-6','recoveryVersion'=>1,'recoveryVarianceReason'=>'Recorded weighbridge excess pending reconciliation','recoveryBrand'=>'EXAMPLE','recoveryContractRef'=>'CONTRACT-1','rawInputs'=>[['lotRef'=>'7511','bags'=>5638,'grossKg'=>475370,'lessKg'=>0]],'rows'=>[['id'=>1,'product'=>'Ready Rice — EXAMPLE','bags'=>8495,'bagWeight'=>40],['id'=>2,'product'=>'Ready Rice — EXAMPLE','bags'=>204,'bagWeight'=>50]]];
foreach(['B2'=>111090,'CSRB2'=>1950,'CSR2'=>8700,'Powder'=>5070,'P.c'=>750,'Sweeping'=>750,'Stone'=>250] as $name=>$kg)$run['rows'][]=['product'=>$name,'systemKg'=>$kg];
mr_validate_inputs($run);$r=mr_report([$run],['entity'=>'TTI','mode'=>'daily']);$g=$r['groups'][0];
verify($g['inputKg']==475370&&$g['readyKg']==350000&&$g['byProductKg']==128560&&$g['outputKg']==478560,'Reference report quantities match the sample independently');
verify($g['differenceKg']==-3190&&abs($g['massBalancePct']-100.6711)<.0001,'Excess output must be visible, never forced to 100 percent');
verify(abs($g['recoveryPct']-73.6269)<.0001,'Finished-rice recovery uses actual raw input');
verify(count($g['raw'])===1&&count($g['finished'])===2&&count($g['byproducts'])===7,'Keep lot, packing and by-product details');
foreach(['brand'=>'EXAMPLE','contract'=>'CONTRACT-1'] as $mode=>$filter){$x=mr_report([$run],['entity'=>'TTI','mode'=>$mode,$mode=>$filter]);verify(count($x['groups'])===1&&$x['groups'][0]['inputKg']==475370,'Explicit brand/contract allocation is filterable');}
verify(mr_report([$run],['entity'=>'BRM'])['groups']===[],'Company books remain separate');verify(mr_report([$run],['entity'=>'TTI','from'=>'2026-09-22'])['groups']===[],'Date filter excludes older production');verify(mr_report([$run],['entity'=>'TTI','shift'=>'Night'])['groups']===[],'Shift filter is exact');
$old=$run;unset($old['rawInputs'],$old['recoveryVersion']);$old['id']='OLD';$r=mr_report([$old],['entity'=>'TTI']);verify($r['groups'][0]['recoveryPct']===null&&$r['totals']['recoveryPct']===null&&$r['missingInputRuns']===1,'Historical output cannot manufacture input or a recovery ratio');
$a=$run;$a['id']='A';$a['rawInputs']=[['grossKg'=>100]];$a['rows']=[['product'=>'Ready Rice — EXAMPLE','systemKg'=>50]];
$b=$a;$b['id']='B';$b['date']='2026-09-22';$b['rawInputs']=[['grossKg'=>1000]];$b['rows'][0]['systemKg']=900;$r=mr_report([$a,$b],['entity'=>'TTI']);verify(abs($r['totals']['recoveryPct']-86.3636)<.0001,'Period recovery is weighted by actual input rather than averaging daily percentages');
$c=$a;$c['id']='C';$c['rawInputs']=[['grossKg'=>100,'brand'=>'OTHER']];$r=mr_report([$a,$c],['entity'=>'TTI','mode'=>'brand','brand'=>'EXAMPLE']);verify($r['groups'][0]['recoveryPct']===null,'An input from another run cannot fill a missing brand allocation');
$a['rows'][]=['product'=>'Hidden adjustment','systemKg'=>999,'systemFixed'=>true,'noStockPost'=>true];verify(mr_report([$a],['entity'=>'TTI'])['totals']['outputKg']==50,'Private reconciliation rows are excluded from operational recovery');
foreach([['grossKg'=>0],['grossKg'=>100,'lessKg'=>100],['grossKg'=>100,'lessKg'=>-1]] as $bad){try{mr_validate_inputs(['rawInputs'=>[$bad]]);throw new RuntimeException('Bad actual input accepted');}catch(DomainException $e){}}
verify(mr_actual_input($old)===null,'Missing actual input is explicit');
echo "PASS recovery sample, independent input/output, weighted ratios, daily/brand/contract filters, missing history and allocation boundaries\n";
$prior=$a;$prior['id']='P';$prior['date']='2026-09-20';$prior['shift']='Night';$prior['start']='20:00';$prior['end']='06:00';$prior['rawInputs']=[['grossKg'=>80000]];
$current=$a;$current['id']='Q';$current['start']='08:00';$current['end']='18:00';$current['rawInputs']=[['grossKg'=>70000]];
$x=mr_report([$prior,$current],['entity'=>'TTI','mode'=>'production','from'=>'2026-09-21']);$speed=$x['performance'][0];verify($speed['tonsPerHour']===7.0&&$speed['previous']['tonsPerHour']===8.0,'Previous shift outside selected date range is retained with actual throughput');
$duplicate=$current;$duplicate['id']='R';$duplicate['rawInputs']=[['grossKg'=>10000]];$x=mr_report([$current,$duplicate],['entity'=>'TTI','mode'=>'production']);verify($x['performance'][0]['hours']===10.0&&$x['performance'][0]['tonsPerHour']===8.0,'Multiple product runs share actual shift hours, not double-counted hours');
unset($current['start']);$x=mr_report([$current],['entity'=>'TTI','mode'=>'production']);verify($x['performance'][0]['tonsPerHour']===null,'Missing shift time never becomes a fabricated 24-hour denominator');
echo "PASS prior shift, overnight hours, shared intervals and missing time\n";
