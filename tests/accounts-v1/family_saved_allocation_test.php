<?php
declare(strict_types=1);
require __DIR__.'/../../api/accounts_subaccounts_core.php';

// A recipient, purpose, or current master name must not override Allocate To.
$s=['journals'=>[]];
foreach(['FAM-SALMAN','FAM-TALHA','FAM-TAYYAB','SHARED','FAM-ABU'] as $i=>$owner){
 $id='ALLOC-'.$i;
 $s['journals'][$id]=['id'=>$id,'entity'=>'TTI','date'=>'2026-11-01','status'=>'Posted',
  'narration'=>'Private household purpose', 'lines'=>[
   ['account'=>'3200','debit'=>100,'credit'=>0,'category'=>'HOME_MONTHLY_GIVE','person'=>'MRS SRP','expenseFor'=>$owner],
   ['account'=>'2230','debit'=>0,'credit'=>100,'person'=>'MRS SRP'],
  ]];
}
$s['journals']['LEGACY']=['id'=>'LEGACY','entity'=>'TTI','date'=>'2026-11-01','status'=>'Posted','lines'=>[
 ['account'=>'3200','debit'=>50,'credit'=>0,'category'=>'HOME_MONTHLY_GIVE','person'=>'MRS TAYYAB'],
 ['account'=>'2230','debit'=>0,'credit'=>50,'person'=>'MRS TAYYAB'],
]];
$before=serialize($s);
$rows=array_column(sac_expense_activity($s,'TTI','2026-11-01','2026-11-30'),null,'voucher');
foreach(['FAM-SALMAN','FAM-TALHA','FAM-TAYYAB','SHARED','FAM-ABU'] as $i=>$owner){
 if($rows['ALLOC-'.$i]['expenseFor']!==$owner||$rows['ALLOC-'.$i]['amount']!==100.0)
  throw new RuntimeException('Saved allocation was replaced or split: '.$owner);
}
if($rows['ALLOC-4']['expenseForName']!=='ARP')throw new RuntimeException('ARP allocation mislabeled as shared');
if($rows['LEGACY']['expenseFor']!=='FAM-TAYYAB')throw new RuntimeException('Unallocated legacy Mrs identity was lost');
if(serialize($s)!==$before)throw new RuntimeException('A report mutated original journals');
$home=['journals'=>[]];
foreach(['SHARED','FAM-SALMAN','FAM-TALHA','FAM-TAYYAB','FAM-ABU'] as $i=>$owner){
 $id='HOME-'.$i;
 $home['journals'][$id]=['id'=>$id,'entity'=>'TTI','date'=>'2026-11-01','status'=>'Posted','sourceType'=>'EXPENSE','lines'=>[
  ['account'=>'6910','debit'=>100+$i,'credit'=>0,'expenseArea'=>'HOME','expenseFor'=>$owner,'counterparty'=>'ABDUL SAMAD ABDUL AZIZ'],
  ['account'=>'1120','debit'=>0,'credit'=>100+$i],
 ]];
}
foreach(['SALMAN'=>101.0,'TALHA'=>102.0,'TAYYAB'=>103.0] as $owner=>$amount){
 $statement=sac_family_statement($home,'TTI','FAMILY|'.$owner.'|HOME','2026-11-01','2026-11-30');
 if($statement['closing']!==$amount)throw new RuntimeException('Recipient inferred ownership or moved shared costs to '.$owner);
}
$arp=sac_family_statement($home,'TTI','FAMILY|ARP|EXPENSE','2026-11-01','2026-11-30');
if($arp['closing']!==104.0)throw new RuntimeException('Explicit ARP allocation missing or another family expense included');
$s['journals']['META']=['id'=>'META','entity'=>'TTI','date'=>'2026-11-01','status'=>'Posted','meta'=>['expenseFor'=>'FAM-TALHA'],'lines'=>[
 ['account'=>'3200','debit'=>25,'credit'=>0,'category'=>'HOME_MONTHLY_GIVE','person'=>'MRS SRP'],
 ['account'=>'2230','debit'=>0,'credit'=>25],
]];
$rows=array_column(sac_expense_activity($s,'TTI','2026-11-01','2026-11-30'),null,'voucher');
if($rows['META']['expenseFor']!=='FAM-TALHA')throw new RuntimeException('Saved journal allocation ignored');
echo "PASS saved family allocation wins over recipient identity; no splitting, legacy fallback, immutable journals\n";
