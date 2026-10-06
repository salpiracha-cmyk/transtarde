<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/api/operations_policy.php';
$source=file_get_contents(dirname(__DIR__,2).'/api/operations.mysql.php');
$start=strpos($source,'function operations_list_by_identity(');$end=strpos($source,'function operations_file_fallback(');
eval(substr($source,$start,$end-$start));
$start=strpos($source,'function operations_can_write(');$end=strpos($source,'function operations_env(');
eval(substr($source,$start,$end-$start));
$count=0;
function verify(bool $ok,string $name):void{global $count;$count++;if(!$ok)throw new RuntimeException($name);}
function denied(callable $test,string $name):void{try{$test();}catch(DomainException $e){verify(true,$name);return;}throw new RuntimeException('Accepted: '.$name);}
$staff=['role'=>'Staff','permissions'=>['Accounts'=>['expenses'=>['Create','Edit']],'Exports'=>'all','Mill'=>'all']];
verify(operations_can_write($staff,'Accounts') && preg_match('/^tt[0-9]{2}[a-z0-9_]{2,60}$/','tt39salarymaster')===1,'Original browser-module checks alone admit another module target');
foreach([['Accounts','tt30bags'],['Accounts','tt39salarymaster'],['Exports','tt39salarymaster'],['Mill','tt99fabricated'],['Super Admin','tt30mills']] as [$m,$k])denied(fn()=>operations_validate_key_module($staff,$m,$k),'ownership '.$m.' '.$k);
foreach([['Mill','tt30mills'],['Milling','tt39salarymaster'],['Exports','tt30bags'],['Accounts','transtrade_export_v3_operational']] as [$m,$k]){operations_validate_key_module($staff,$m,$k);verify(true,'legitimate '.$m.' '.$k);}
foreach(['tt34ghati','tt34nilqueue','tt32processingrecon','tt40exportreceipts'] as $k)denied(fn()=>operations_validate_key_module(['role'=>'Super Admin'],'Super Admin',$k),'server-owned '.$k);
foreach(['Accounts','Mill','Milling'] as $m)foreach(['','null','false','{}','[]','{"contracts":null,"shipments":[]}'] as $old)denied(fn()=>operations_merge_export($old,'{"contracts":[{"ref":"FORGED"}],"shipments":[]}',$m),'uninitialized root '.$m.' '.$old);
$root=['contracts'=>[['ref'=>'KEEP']],'shipments'=>[['id'=>'L1','kind'=>'lot','contractRef'=>'KEEP','millActuals'=>[]]],'accountsReceipts'=>[]];
$incoming=['contracts'=>[['ref'=>'FORGED']],'shipments'=>[['id'=>'FORGED']],'accountsReceipts'=>[['id'=>'R1']],'deletedShipments'=>[['contractRef'=>'KEEP']]];
$r=json_decode(operations_merge_export(json_encode($root),json_encode($incoming),'Accounts'),true);
verify($r['contracts']===$root['contracts'] && $r['shipments']===$root['shipments'] && $r['accountsReceipts']===[['id'=>'R1']],'Accounts can add receipt without replacing or deleting Export records');
$r=json_decode(operations_merge_export('',json_encode($root),'Exports'),true);verify($r===$root,'Exports initializes root');
$manual=['id'=>1,'brand'=>'Mill-owned','received'=>12];$bridge=['id'=>2,'_ttBridge'=>'exports','_ttBridgeId'=>'PO-L1','brand'=>'STAR','received'=>20];
$rows=[$manual,$bridge];$amended=$rows;$amended[1]['brand']='REVISED';operations_validate_export_bridge('tt30bags',json_encode($rows),json_encode($amended),'Exports');verify(true,'Exports amends instruction and preserves receipts');
foreach([[$manual,array_replace($bridge,['received'=>999])],[$manual,array_diff_key($bridge,['received'=>true])],[array_replace($manual,['_ttBridge'=>'exports']),$bridge],[$bridge],[$manual],[$manual,$bridge,$bridge]] as $bad)denied(fn()=>operations_validate_export_bridge('tt30bags',json_encode($rows),json_encode($bad),'Exports'),'receipt/manual/identity bypass');
foreach([$bridge,$manual] as $row){$bad=$row;$bad['received']=true;denied(fn()=>operations_validate_export_bridge('tt30bags',json_encode([$row]),json_encode([$bad]),'Exports'),'JSON boolean is not a receipt quantity');}
verify(operations_same_value(['received'=>200,'brand'=>'B'],['brand'=>'B','received'=>200.0]),'numeric equivalence and object key ordering');
$deleted=json_encode(['shipments'=>[],'deletedShipments'=>[['contractRef'=>'KEEP','deletedAt'=>'2026-10-06']]]);
$oldLoad=['id'=>'C1','contractRef'=>'KEEP','kg'=>25000];operations_validate_export_bridge('tt35exload',json_encode([$oldLoad]),'[]','Exports',$deleted);verify(true,'Acknowledged deletion removes linked loading');
denied(fn()=>operations_validate_export_bridge('tt35exload',json_encode([$oldLoad]),'[]','Exports'),'unacknowledged deletion');
$bad=$oldLoad;$bad['kg']=true;denied(fn()=>operations_validate_export_bridge('tt35exload',json_encode([$oldLoad]),json_encode([$bad]),'Exports',$deleted),'loading modification behind deletion');
$active=json_encode(['shipments'=>[['id'=>'LIVE','contractRef'=>'KEEP']],'deletedShipments'=>[['contractRef'=>'KEEP','deletedAt'=>'2026-10-06']]]);denied(fn()=>operations_validate_export_bridge('tt35exload',json_encode([$oldLoad]),'[]','Exports',$active),'live sibling protects loading');
$new=['id'=>3,'_ttBridge'=>'exports','brand'=>'NEW','received'=>0];operations_validate_export_bridge('tt30bags','[]',json_encode([$new]),'Exports');verify(true,'New export bag starts with zero receipts');$new['received']=1;denied(fn()=>operations_validate_export_bridge('tt30bags','[]',json_encode([$new]),'Exports'),'new receipt forgery');
$ship=['id'=>4,'_ttBridge'=>'exports','contractRef'=>'KEEP','containers'=>[['id'=>'C1','weight'=>25000]],'audit'=>[],'status'=>'Open','recovery'=>.6,'byProducts'=>[]];$next=$ship;$next['inspection']='SGS';operations_validate_export_bridge('tt30ship',json_encode([$ship]),json_encode([$next]),'Exports');verify(true,'Instruction amendment preserves saved containers');
foreach([array_replace($next,['containers'=>[]]),array_replace($next,['containers'=>[['id'=>'FORGED','weight'=>99999]]])] as $bad)denied(fn()=>operations_validate_export_bridge('tt30ship',json_encode([$ship]),json_encode([$bad]),'Exports'),'container overwrite');
operations_validate_export_bridge('tt30ship',json_encode([$ship]),'[]','Exports',$deleted);verify(true,'Acknowledged shipment deletion permits linked container removal');
denied(fn()=>operations_validate_export_bridge('tt30ship','[]',json_encode([$ship]),'Exports'),'new container forgery');
$instruction=['id'=>5,'_ttBridge'=>'exports','sourceSodaId'=>'S1','loadingComplete'=>true,'completedAt'=>'2026-10-01'];$bad=$instruction;$bad['sourceSodaId']='S2';denied(fn()=>operations_validate_export_bridge('tt40exinstructions',json_encode([$instruction]),json_encode([$bad]),'Exports'),'allocation overwrite');
$legacy=['id'=>6,'_ttBridge'=>'exports'];operations_validate_export_bridge('tt35exmill',json_encode([$manual,$legacy]),json_encode([$manual]),'Exports');verify(true,'Pseudo-SODA cleanup preserves ordinary SODA');denied(fn()=>operations_validate_export_bridge('tt35exmill','[]',json_encode([$legacy]),'Exports'),'new pseudo-SODA');
echo "PASS operations ownership: $count checks; immutable fixtures, no storage writes.\n";
