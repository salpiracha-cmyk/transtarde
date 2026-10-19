<?php
declare(strict_types=1);
require __DIR__.'/../../backup_lib.php';
$root=sys_get_temp_dir().'/tt-backup-policy-'.bin2hex(random_bytes(6));mkdir($root,0700);
function check_policy(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
try{
 $payload=str_repeat('Recover every record without loss. ',200000);file_put_contents($root.'/source.json',$payload);
 foreach([true,false]as$compressed){
  $path=$root.'/'.($compressed?'compressed':'legacy').'.zip';$w=new TT_SimpleZipWriter($path);$meta=$w->addFile('operations.json',$root.'/source.json',$compressed);$w->close();
  if($compressed)check_policy(filesize($path)<strlen($payload)/20,'Text backup compression must materially reduce size.');
  $r=new TT_SimpleZipReader($path);$dest=fopen($root.'/restore.json','wb');$digest=$r->storedDigest('operations.json',$dest);fclose($dest);$r->close();
  check_policy($digest===$meta&&file_get_contents($root.'/restore.json')===$payload,'Both old and new ZIP entries must restore exactly.');
 }
 $bad=$root.'/bad.zip';copy($root.'/compressed.zip',$bad);$f=fopen($bad,'r+b');fseek($f,50);$b=fread($f,1);fseek($f,50);fwrite($f,chr(ord($b)^255));fclose($f);
 $rejected=false;try{$r=new TT_SimpleZipReader($bad);$r->storedDigest('operations.json');}catch(Throwable){$rejected=true;}check_policy($rejected,'Damaged compressed payload must fail integrity verification.');
 $now=strtotime('2026-10-19T17:00:00Z');$paths=[];
 for($i=0;$i<110;$i++){$stamp=$now-$i*86400;$path=$root.'/auto-'.gmdate('Ymd-His',$stamp).'.zip';file_put_contents($path,'fixture');touch($path,$stamp);$paths[]=$path;}
 for($i=1;$i<12;$i++){$stamp=$now-$i*3600;$path=$root.'/auto-'.gmdate('Ymd-His',$stamp).'.zip';file_put_contents($path,'fixture');touch($path,$stamp);$paths[]=$path;}
 $keep=tt_backup_retention_keep($paths,$now);check_policy(count($keep)<=19,'Automatic history must stay bounded across hourly/daily/weekly/monthly buckets.');
 rsort($paths,SORT_STRING);foreach(array_slice($paths,0,4)as$p)check_policy(isset($keep[$p]),'Keep the four latest snapshots.');
 check_policy(isset($keep[$root.'/auto-'.gmdate('Ymd-His',$now-6*86400).'.zip']),'Keep a recent daily recovery point.');
 check_policy(!isset($keep[$root.'/auto-'.gmdate('Ymd-His',$now-109*86400).'.zip']),'Expire history outside the configured window.');
 echo "PASS compressed and legacy streaming restores, corrupted-data refusal, bounded recovery history\n";
}finally{foreach(glob($root.'/*')?:[]as$p)unlink($p);rmdir($root);}
