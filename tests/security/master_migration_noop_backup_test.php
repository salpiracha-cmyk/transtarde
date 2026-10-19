<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/tt-noop-migration-'.bin2hex(random_bytes(6));$app=$root.'/app';mkdir($app.'/scripts',0700,true);
function rm_noop_fixture(string $p):void{foreach(scandir($p)?:[]as$n){if($n==='.'||$n==='..')continue;$f=$p.'/'.$n;is_dir($f)?rm_noop_fixture($f):unlink($f);}rmdir($p);}
try{
 foreach(['auth_store.php','master_store.php','product_stage.php','offline_idempotency.php','session_store.php','backup_lib.php']as$n)copy(__DIR__.'/../../'.$n,$app.'/'.$n);
 copy(__DIR__.'/../../scripts/migrate_master_permissions.php',$app.'/scripts/migrate_master_permissions.php');
 require $app.'/auth_store.php';
 tt_mutate_store(static function(&$data):void{$data['settings']['master_permission_schema']=2;},false);
 $before=file_get_contents(TT_STORE_FILE);
 for($i=0;$i<2;$i++){
  $pipes=[];$proc=proc_open([PHP_BINARY,$app.'/scripts/migrate_master_permissions.php','--apply'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
  $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($proc);
  $result=json_decode($out,true);
  if($code!==0||!is_array($result)||($result['changedUserCount']??null)!==0)throw new RuntimeException('Repeated migration failed: '.$err);
 }
 if(is_dir(TT_DATA_DIR.'/security-migrations'))throw new RuntimeException('Completed migration created redundant full backups.');
 if(file_get_contents(TT_STORE_FILE)!==$before)throw new RuntimeException('Completed migration rewrote live store bytes.');
 echo "PASS repeated completed migration creates no backup and does not rewrite security store\n";
}finally{if(session_status()===PHP_SESSION_ACTIVE)session_write_close();rm_noop_fixture($root);}
