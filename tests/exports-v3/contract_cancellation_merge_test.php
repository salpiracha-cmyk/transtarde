<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/auth_store.php';
require dirname(__DIR__,2).'/api/operations_policy.php';
$source=file_get_contents(dirname(__DIR__,2).'/api/operations.mysql.php');
$start=strpos($source,'function operations_list_by_identity(');$end=strpos($source,'function operations_file_fallback(');eval(substr($source,$start,$end-$start));
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function merge($old,$next,$module='Exports'){return json_decode(operations_merge_export(json_encode($old),json_encode($next),$module),true);}
function denied($old,$next,$message){try{merge($old,$next);}catch(DomainException $e){return;}throw new RuntimeException('Accepted '.$message);}
$old=['contracts'=>[['id'=>'C1','ref'=>'TTI/QA/01','seller'=>'TTI'],['id'=>'C2','ref'=>'KEEP','seller'=>'TTI']], 'shipments'=>[['id'=>'P1','contractRef'=>'TTI/QA/01'],['id'=>'P2','contractRef'=>'KEEP']], 'fi'=>[], 'accountsReceipts'=>[], 'millSync'=>[], 'audits'=>[]];
$next=$old;$next['contracts']=[$old['contracts'][1]];$next['shipments']=[$old['shipments'][1]];
$next['cancelledContracts']=[['id'=>'CC1','contractId'=>'C1','contractRef'=>'TTI/QA/01','seller'=>'TTI','reason'=>'Incorrect draft','cancelledAt'=>'2026-10-27T12:00:00Z','cancelledBy'=>'Fixture','processIds'=>['P1']]];
$saved=merge($old,$next);check(count($saved['contracts'])===1&&$saved['contracts'][0]['id']==='C2','Cancellation must survive canonical merge');check(count($saved['shipments'])===1&&$saved['shipments'][0]['id']==='P2','Only matching empty process is removed');
$stale=merge($saved,$old);check($stale['contracts']===$saved['contracts']&&$stale['shipments']===$saved['shipments'],'Old browser cannot resurrect cancelled identities');check($stale['cancelledContracts']===$saved['cancelledContracts'],'Omission preserves cancellation history');
$reused=$saved;$reused['contracts'][]=['id'=>'C3','ref'=>'TTI/QA/01','seller'=>'TTI'];$reused['shipments'][]=['id'=>'P3','contractRef'=>'TTI/QA/01'];$r=merge($saved,$reused);check(count($r['contracts'])===2&&count($r['shipments'])===2,'Reference reuse with new identities remains valid');
$changed=$next;$changed['cancelledContracts'][0]['reason']='Hide original';denied($saved,$changed,'history rewrite');
$changed=$next;$changed['cancelledContracts'][0]['processIds'][]='P2';denied($old,$changed,'unrelated process deletion');
$changed=$next;$changed['cancelledContracts'][0]['reason']='';denied($old,$changed,'missing reason');
$changed=$next;$changed['cancelledContracts'][0]['contractRef']='KEEP';denied($old,$changed,'mismatched reference');
foreach(['lot','bags','production','loading','actuals','receipt','fi'] as $kind){
 $busy=$old;
 if($kind==='lot')$busy['shipments'][]=['id'=>'L1','kind'=>'lot','parentProcessId'=>'P1','contractRef'=>'TTI/QA/01'];
 if($kind==='bags')$busy['shipments'][0]['bagOrders']=[['poNo'=>'PO1']];
 if($kind==='production')$busy['shipments'][0]['production']=['sentToMill'=>true];
 if($kind==='loading')$busy['shipments'][0]['loading']=['lots'=>[['lotId'=>'L1']]];
 if($kind==='actuals')$busy['shipments'][0]['millActuals']=[['number'=>'MSCU1234567']];
 if($kind==='receipt')$busy['accountsReceipts']=[['contractRef'=>'TTI/QA/01','amount'=>1]];
 if($kind==='fi')$busy['fi']=[['allocations'=>[['contractRef'=>'TTI/QA/01','amount'=>1]]]];
 denied($busy,$next,'downstream '.$kind);
}
$bridge=merge($old,['contracts'=>$old['contracts'],'shipments'=>$old['shipments'],'cancelledContracts'=>$next['cancelledContracts']],'Mill');check(count($bridge['contracts'])===2,'Mill cannot cancel a contract');
echo "PASS contract cancellation: reload persistence, stale browser, reference reuse, immutable history, foreign identity, required reason, seven downstream guards and Mill ownership\n";
