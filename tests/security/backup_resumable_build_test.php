<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/tt-batch-'.bin2hex(random_bytes(8));mkdir($root,0700);mkdir($root.'/work',0700);define('TT_DATA_DIR',$root);
require __DIR__.'/../../backup_lib.php';
function tt_ensure_data_dir():void{}
function batch_check($ok,$label){if(!$ok)throw new RuntimeException($label);}
function batch_clean($path){if(is_dir($path)){foreach(scandir($path)as$n)if($n!=='.'&&$n!=='..')batch_clean($path.'/'.$n);rmdir($path);}else unlink($path);}
try{
 file_put_contents($root.'/auth.json','{"users":[]}');file_put_contents($root.'/large.pdf',str_repeat('source document ',200000));file_put_contents($root.'/code.php','<?php echo "fixture";');
 $source=fn()=>[['Accounts/report.csv'=>str_repeat("date,amount\n",1000)],[],['auth.json'=>$root.'/auth.json'],['code.php'=>$root.'/code.php'],['Documents/large.pdf'=>$root.'/large.pdf']];
 tt_backup_build_init($root.'/work','full',$source);$password='fixture-batch-password';$steps=0;
 do{$path=tt_backup_build_step($root.'/work',$password,1048576);$steps++;if($steps>30)throw new RuntimeException('Unbounded work');}while($path===null);
 batch_check($steps>2,'Archive must span several requests');$manifest=tt_backup_verify_full($path,$password);batch_check($manifest['encryptedEntries']['Documents/large.pdf']['sha256']===hash_file('sha256',$root.'/large.pdf'),'Full source hash preserved');
 batch_check(count($manifest['encryptedEntries']['Documents/large.pdf']['chunks'])>1,'Large source chunked');batch_check(!str_contains(file_get_contents($root.'/work/build-plan.json'),$password),'No persisted recovery password');
 $lock=tt_backup_work_lock();batch_check(is_resource($lock)&&tt_backup_work_lock()===null,'Cross-scope generation exclusion');flock($lock,LOCK_UN);fclose($lock);
 tt_backup_build_init($root.'/work','full',$source);tt_backup_build_step($root.'/work',$password,1);file_put_contents($root.'/large.pdf','changed');$failed=false;try{tt_backup_build_step($root.'/work',$password);}catch(RuntimeException){$failed=true;}batch_check($failed,'Changing source cannot become verified backup');
 echo "PASS bounded archive continuation, full decrypt/hash, source-change refusal and global generation lock\n";
}finally{batch_clean($root);}
