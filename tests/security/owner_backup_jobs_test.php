<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/tti-owner-jobs-'.bin2hex(random_bytes(8));$app=$root.'/app';mkdir($app,0700,true);
foreach(['auth_store.php','session_store.php','master_store.php','product_stage.php','offline_idempotency.php','backup_lib.php','pending_uploads.php','backup_report_exports.php','owner_backup_jobs.php']as$f)copy(__DIR__.'/../../'.$f,$app.'/'.$f);
function owner_expect(bool $value,string $why):void{if(!$value)throw new RuntimeException($why);}
function owner_clean(string $path):void{if(is_dir($path)&&!is_link($path)){foreach(scandir($path)as$n)if($n!=='.'&&$n!=='..')owner_clean($path.'/'.$n);rmdir($path);}elseif(file_exists($path))unlink($path);}
try{
 require $app.'/auth_store.php';require $app.'/backup_lib.php';require $app.'/owner_backup_jobs.php';
 tt_mutate_store(static function(array &$s){$s['settings']['fixture_owner_jobs']=true;});
 $password='fixture-owner-backup-password';$client=str_repeat('a',64);
 foreach(['business','full']as$kind){
  $m=['id'=>bin2hex(random_bytes(16)),'client'=>$client,'state'=>'preparing','stage'=>'building','createdAt'=>time()];
  $lock=tt_owner_job_lock($kind,LOCK_EX);owner_expect(is_resource($lock),'Job lock unavailable.');
  owner_expect(tt_owner_job_lock($kind,LOCK_EX)===null,'Concurrent writer entered.');
  $done=tt_owner_job_generate($kind,$m,$password);
  owner_expect($done['state']==='ready','Actual encrypted backup generation failed: '.($done['errorCode']??''));
  $path=tt_owner_job_dir($kind).'/transfer.zip';$before=file_get_contents($path);
  owner_expect(hash_file('sha256',$path)===$done['sha256']&&filesize($path)===$done['length'],'Cache integrity failed.');
  owner_expect(tt_owner_job_matches($done,$m['id'],$client)&&!tt_owner_job_matches($done,str_repeat('0',32),$client)&&!tt_owner_job_matches($done,$m['id'],str_repeat('b',64)),'Session/job boundary failed.');
  $public=tt_owner_job_public($done);owner_expect(!isset($public['client'],$public['identity'],$public['filename']),'Private job metadata exposed.');
  if($kind==='business'){$zip=new ZipArchive();owner_expect($zip->open($path)===true,'Native business ZIP failed.');owner_expect(@$zip->getFromName('Export_Index.csv')===false,'Business ZIP lost encryption.');$zip->setPassword($password);owner_expect(is_string($zip->getFromName('Export_Index.csv')),'Business ZIP password rejected.');$zip->close();}
  else tt_backup_verify_full($path,$password);
  $failed=tt_owner_job_generate($kind,$m,$password,static function(){throw new RuntimeException('fixture-private-financial-value');});
  owner_expect($failed['state']==='failed'&&file_get_contents($path)===$before,'Failed build displaced verified backup.');
  owner_expect(!str_contains(json_encode(tt_owner_job_public($failed)),'fixture-private-financial-value'),'Failure exposed private exception text.');
  flock($lock,LOCK_UN);fclose($lock);
 }
 owner_expect(tt_owner_job_dir('business')!==tt_owner_job_dir('full'),'Business/full jobs collided.');
 owner_expect(!array_filter(array_keys(tt_backup_private_files()),static fn($p)=>str_contains($p,'.owner-transfer')),'Transfer cache entered a recovery backup.');
 echo "PASS background owner Business/Full encrypted caches, session binding, lock, private error references and verified-survivor preservation\n";
}finally{if(session_status()===PHP_SESSION_ACTIVE)session_write_close();owner_clean($root);}
