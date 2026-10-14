<?php
require __DIR__.'/../../api/service_ledger_groups.php';
function check($v,$message){if(!$v)throw new RuntimeException($message);}
$masters=['business_parties'=>[['id'=>'M','values'=>['MAPCO','M','Clearing Agent']],['id'=>'D','values'=>['DITTA','D','Transporter']],['id'=>'P','values'=>['PAKLINK','P','Freight Forwarder']]]];
function tt_business_party_categories($v){return explode('|',$v);}
check(slg_party_kind('MAPCO',$masters)==='CLEARING','MAPCO role');check(slg_party_kind('DITTA',$masters)==='TRANSPORT','local transport role');check(slg_party_kind('PAKLINK',$masters)==='FREIGHT','forwarder role');
$s=['supplierBills'=>['C'=>['id'=>'C','entity'=>'TTI','vendor'=>'MULTI','category'=>'CLEARING'],'T'=>['id'=>'T','entity'=>'TTI','vendor'=>'MULTI','category'=>'TRANSPORT']]];
$j=['id'=>'PAY','lines'=>[['account'=>'2130','debit'=>60,'credit'=>0,'counterparty'=>'MULTI'],['account'=>'1110','debit'=>0,'credit'=>60]],'meta'=>['allocations'=>[['billId'=>'C','category'=>'CLEARING','payableAccount'=>'2130','logisticsAmount'=>40],['billId'=>'T','category'=>'TRANSPORT','payableAccount'=>'2130','logisticsAmount'=>20]]]];
$before=serialize($s);$lines=slg_lines($s,$j,$masters);check(count($lines)===3&&$lines[0]['serviceGroup']==='CLEARING'&&$lines[0]['debit']===40.0&&$lines[1]['serviceGroup']==='TRANSPORT'&&$lines[1]['debit']===20.0,'mixed-service payment splits only control');check(array_sum(array_column($lines,'debit'))===array_sum(array_column($lines,'credit')),'all groups reconcile');check(serialize($s)===$before,'grouping never writes books');
$s['journals']['PAY']=$j;$reversal=['reversalOf'=>'PAY','lines'=>[['account'=>'2130','debit'=>0,'credit'=>60],['account'=>'1110','debit'=>60,'credit'=>0]]];$r=slg_lines($s,$reversal,$masters);check($r[0]['credit']===40.0&&$r[1]['credit']===20.0,'reversal retains original allocation classification');
$s['supplierBills']['WRONG']=['id'=>'WRONG','entity'=>'TTI','vendor'=>'MAPCO','category'=>'FREIGHT','billNo'=>'REVIEW'];check(count(slg_reviews($s,'TTI',$masters))===1,'role mismatch requires review, not silent relabeling');
echo "PASS service groups: provider roles, mixed-service payment/reversal splits, conserved control totals, immutable books and mismatch review\n";
