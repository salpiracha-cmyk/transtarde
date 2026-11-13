<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/tt-worker-'.bin2hex(random_bytes(8));mkdir($root,0700);
define('TT_DATA_DIR',$root);function tt_ensure_data_dir():void{}
require __DIR__.'/../../backup_lib.php';
require __DIR__.'/../../office_backup_jobs.php';
require __DIR__.'/../../office_backup_worker.php';
function check_worker(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
function clean_worker(string $p):void{if(is_dir($p)&&!is_link($p)){foreach(scandir($p)as$n)if($n!=='.'&&$n!=='..')clean_worker($p.'/'.$n);rmdir($p);}else unlink($p);}
try{
    $password='worker-fixture-password';
    check_worker(!tt_office_worker_enabled(),'Deploying cannot silently activate replacement');
    tt_office_worker_tick($password,1);
    check_worker(tt_office_worker_control()['heartbeatAt']>=time()-2,'Disabled worker proves scheduler heartbeat');
    tt_office_worker_control(['enabled'=>true,'heartbeatAt'=>time()]);
    $GLOBALS['ttOfficeBackupScope']='data';$dir=tt_office_job_dir();
    file_put_contents($root.'/auth.json','{"users":[]}');
    file_put_contents($root.'/upload.pdf',str_repeat('preserved uploaded document',100000));
    $source=fn()=>[[],[],['auth.json'=>$root.'/auth.json'],[],['Documents/upload.pdf'=>$root.'/upload.pdf']];
    tt_backup_build_init($dir,'data',$source);
    $m=['id'=>str_repeat('a',32),'client'=>str_repeat('b',64),'state'=>'preparing','stage'=>'building','createdAt'=>time(),'worker'=>true,'batchBuild'=>true];
    tt_office_job_write($m);file_put_contents($dir.'/transfer.zip','previous verified archive');
    $before=file_get_contents($dir.'/build-plan.json');
    $lock=tt_backup_work_lock();check_worker(!tt_office_worker_tick($password,1),'Global lock prevents overlap');flock($lock,LOCK_UN);fclose($lock);
    $job=tt_office_job_lock(LOCK_EX);
    for($i=0;$i<100;$i++){
        $status=tt_office_job_public(tt_office_job_read());
        check_worker($status['state']==='preparing'&&$status['pollAfterSeconds']===30,'Cheap status works while worker holds lock');
    }
    check_worker(file_get_contents($dir.'/build-plan.json')===$before,'Status cannot build or advance verification');
    flock($job,LOCK_UN);fclose($job);
    for($i=0;$i<6;$i++){
        tt_office_worker_tick($password,1);
        $m=tt_office_job_read();if($m['state']==='ready')break;
        check_worker($m['state']==='preparing','Worker has no failure');
        check_worker(file_get_contents($dir.'/transfer.zip')==='previous verified archive','Old transfer remains until verification');
    }
    check_worker($m['state']==='ready','Worker completes without any status request doing work');
    $manifest=tt_backup_verify_full($dir.'/transfer.zip',$password);
    check_worker($manifest['scope']==='data'&&$manifest['appFileCount']===0,'Daily copy excludes software');
    check_worker($manifest['encryptedEntries']['Documents/upload.pdf']['sha256']===hash_file('sha256',$root.'/upload.pdf'),'Uploaded documents remain recoverable');
    check_worker(file_get_contents($root.'/auth.json')==='{"users":[]}', 'Live records untouched');
    echo "PASS independent worker, non-overlap, read-only progress, encrypted data recovery\n";
}finally{clean_worker($root);}
