<?php
declare(strict_types=1);
require __DIR__.'/../../api/accounts_ledger_visibility.php';
function check(bool $test,string $message):void {if(!$test)throw new RuntimeException($message);}
function journal(string $id,string $date,array $lines,?string $reversal=null,string $entity='TTI'):array {return ['id'=>$id,'date'=>$date,'entity'=>$entity,'status'=>'Posted','lines'=>$lines,'reversalOf'=>$reversal];}
$lines=[['account'=>'2110','supplier'=>'MAPCO','debit'=>100,'credit'=>0],['account'=>'1110','bankAccountId'=>'BANK1','debit'=>0,'credit'=>100]];
$reverse=array_map(static fn($l)=>array_replace($l,['debit'=>$l['credit'],'credit'=>$l['debit']]),$lines);
$s=['journals'=>['A'=>journal('A','2026-09-01',$lines),'R'=>journal('R','2026-10-10',$reverse,'A'),'B'=>journal('B','2026-10-11',$lines)]];
$before=$s;
check(alv_hidden($s,'2026-10-09')===[],'Earlier historical position must retain the original');
$hidden=alv_hidden($s,'2026-10-11');check(isset($hidden['A'],$hidden['R'])&&!isset($hidden['B']),'Complete reversal hides both entries, preserving replacement');
check($s===$before,'Projection must never mutate financial or audit data');
check(alv_record_hidden(['journalId'=>'A'],$hidden),'Earlier source register entry must also disappear');
check(!alv_record_hidden(['postingJournalIds'=>['A','B']],$hidden),'Partly active source must remain');
$s['journals']['R']['lines'][0]['credit']=40;check(alv_hidden($s)===[],'Partial reversal must not suppress financial value');
$s=$before;$s['journals']['R']['entity']='TG';check(alv_hidden($s)===[],'Cross-company reference cannot hide a journal');
$s=$before;$s['journals']['R']['lines'][0]['supplier']='Other';check(alv_hidden($s)===[],'A different party must not disappear as if cancelled');
$s=$before;$s['journals']['R']['meta']['reversalOf']='A';unset($s['journals']['R']['reversalOf']);check(isset(alv_hidden($s)['A']),'Legacy metadata reversal links must work');
$s=$before;$s['journals']['RR']=journal('RR','2026-10-12',$lines,'R');$chain=alv_hidden($s);check(isset($chain['R'],$chain['RR'])&&!isset($chain['A']),'Reversing a reversal restores the original; never suppress an unbalanced chain');
$catalog=['2110'=>['name'=>'Supplier Payable','class'=>'Liability'],'1110'=>['name'=>'Bank','class'=>'Asset']];
$trial=alv_party_trial([$before['journals']['B']],$catalog);
check(count($trial)===2,'Party view includes unassigned contra balances');
check(array_sum(array_column($trial,'debit'))===array_sum(array_column($trial,'credit')),'Party trial balance remains balanced');
check(in_array('MAPCO',array_column($trial,'party'),true),'Party is read from its own leg');
check(in_array('Unassigned / company',array_column($trial,'party'),true),'Bank is not incorrectly attributed to MAPCO');
echo "Cancellation pairs, earlier as-of dates, incomplete reversals, immutable audit and party trial balance passed\n";
