<?php
declare(strict_types=1);
function tt_user_can_open_module($u,$m){return $u['allowed']??true;}
function tt_user_can_module_action($u,$m,$i,$a){return $u['print']??true;}
function tt_user_business_entity($u,$e,$a){return ($u['entity']??$e)===$e;}
require dirname(__DIR__,2).'/api/mill_gate_pass_core.php';
function check($b,$m){if(!$b)throw new RuntimeException($m);}
$user=['role'=>'Staff','entity'=>'TTI','location'=>'TTI Rice Mills','username'=>'qa'];
$shipment=['id'=>1,'entity'=>'TTI','millName'=>'TTI Rice Mills','brand'=>'A','containers'=>[['id'=>'C1','date'=>'2026-10-27','container'=>'ABCD1234567','seal'=>'S1','truck'=>'TR-1','bags'=>625,'weight'=>25000,'loadingComplete'=>true]]];
$local=['id'=>2,'entity'=>'TTI','party'=>'Local buyer','soda'=>'LS-1','loads'=>[['bridgeId'=>'L1','gatePassRef'=>'OLD-1','date'=>'2026-10-27','truck'=>'TR-2','kg'=>10000,'bags'=>200,'printed'=>true]]];
$exporter=['id'=>3,'entity'=>'TTI','buyer'=>'Exporter','ref'=>'ES-1','containers'=>[['id'=>'E1','date'=>'2026-10-27','c'=>'BREAK BULK','t'=>'TR-3','b'=>200,'g'=>10020,'tare'=>20,'net'=>10000]]];
$values=['tt30ship'=>json_encode([$shipment]),'tt35localsales'=>json_encode([$local]),'tt35exportersale'=>json_encode([$exporter])];
$dir=sys_get_temp_dir().'/gate-test-'.bin2hex(random_bytes(6));mkdir($dir);$file=$dir.'/register.json';
try{
 $documents=[];foreach([['tt30ship','1','C1'],['tt35localsales','2','L1'],['tt35exportersale','3','E1']] as $i=>$args){$d=mgp_document($values,$user,...$args);$documents[]=mgp_issue($file,$d,$user);check($documents[$i]['serial']==='GP-'.str_pad((string)($i+1),6,'0',STR_PAD_LEFT),'One global sequential number across gate pass types');check(mgp_issue($file,$d,$user)['serial']===$documents[$i]['serial'],'Reprint/retry retains serial');}
 check($documents[2]['title']==='Local Sale to Exporter Gate Pass'&&$documents[2]['deductionKg']===20.0,'Exporter title and physical tare are retained');
 foreach([['print'=>false],['entity'=>'BRM'],['location'=>'Other Mill']] as $denial){try{mgp_document($values,array_replace($user,$denial),'tt30ship','1','C1');throw new RuntimeException('Scope denied expected');}catch(DomainException $e){}}
 $shipment['containers'][0]['loadingComplete']=false;$values['tt30ship']=json_encode([$shipment]);try{mgp_document($values,$user,'tt30ship','1','C1');throw new RuntimeException('Incomplete loading accepted');}catch(DomainException $e){}
 $local['loads'][0]['printed']=false;$values['tt35localsales']=json_encode([$local]);try{mgp_document($values,$user,'tt35localsales','2','L1');throw new RuntimeException('Unissued local loading accepted');}catch(DomainException $e){}
 file_put_contents($file,'{broken');try{mgp_issue($file,['identity'=>'other'],$user);throw new RuntimeException('Corrupt register reset');}catch(JsonException $e){}
 echo "PASS gate pass numbering/reprint, three document types, saved quantities, permission/company/location guards, incomplete load and corrupt-register failures\n";
}finally{foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);}
