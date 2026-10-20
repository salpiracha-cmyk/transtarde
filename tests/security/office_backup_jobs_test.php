<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/tti-office-jobs-'.bin2hex(random_bytes(8));mkdir($root.'/app',0700,true);mkdir($root.'/private',0700);
define('TT_DATA_DIR',$root.'/app/../private');define('TT_STORE_FILE',TT_DATA_DIR.'/auth.json');
function tt_ensure_data_dir():void{}
require __DIR__.'/../../backup_lib.php';require __DIR__.'/../../office_backup_jobs.php';
function ensure_job(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
function clean_job(string $p):void{if(is_dir($p)&&!is_link($p)){foreach(scandir($p)as$n)if($n!=='.'&&$n!=='..')clean_job($p.'/'.$n);rmdir($p);}else unlink($p);}
try{
 $lock=tt_office_job_lock(LOCK_EX);ensure_job(is_resource($lock)&&tt_office_job_lock(LOCK_EX)===null,'no duplicate worker');
 $m=['id'=>str_repeat('a',32),'client'=>str_repeat('b',64),'state'=>'preparing','createdAt'=>time()];tt_office_job_write($m);
 ensure_job(tt_office_job_matches($m,$m['id'],$m['client']),'matching identity');ensure_job(!tt_office_job_matches($m,'../escape',$m['client']),'reject traversal');ensure_job(!tt_office_job_matches($m,$m['id'],str_repeat('c',64)),'reject another client');
 $payload=random_bytes(200000);$build=static function($p)use($payload,$root){$f=$root.'/candidate.zip';file_put_contents($f,$payload);return ['path'=>$f];};
 $ready=tt_office_job_generate($m,'test-password-123',$build,static function($p,$pw){});
 ensure_job($ready['state']==='ready'&&$ready['length']===strlen($payload),'commit verified transfer');
 ensure_job(tt_office_job_chunk($ready,0,12345)===substr($payload,0,12345),'exact bounded chunk');
 foreach([[-1,1],[0,4194305],[strlen($payload),1]]as[$off,$len]){$failed=false;try{tt_office_job_chunk($ready,$off,$len);}catch(InvalidArgumentException){$failed=true;}ensure_job($failed,'reject invalid ranges');}
 ensure_job(!array_key_exists('client',tt_office_job_public($ready))&&!array_key_exists('identity',tt_office_job_public($ready)),'public metadata excludes secrets/paths');
 $failed=tt_office_job_generate($m,'test-password-123',$build,static function(){throw new RuntimeException('fixture rejection');});
 ensure_job($failed['state']==='failed'&&file_get_contents(tt_office_job_dir().'/transfer.zip')===$payload,'failed verification preserves previous archive');
 ensure_job(!is_file($root.'/candidate.zip')&&!is_file(tt_office_job_dir().'/incoming.tmp'),'cleanup own failed build');
 file_put_contents(tt_office_job_dir().'/transfer.zip','changed');$failed=false;try{tt_office_job_chunk($ready,0,10);}catch(RuntimeException){$failed=true;}ensure_job($failed,'refuse changed transfer');
 flock($lock,LOCK_UN);fclose($lock);echo "OFFICE_BACKGROUND_JOB_BOUNDARIES_OK\n";
}finally{clean_job($root);}
