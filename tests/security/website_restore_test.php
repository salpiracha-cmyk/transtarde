<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/tti-restore-'.bin2hex(random_bytes(8));mkdir($root.'/private',0700,true);
define('TT_DATA_DIR',$root.'/private');define('TT_STORE_FILE',TT_DATA_DIR.'/auth.json');
function tt_ensure_data_dir():void{}
require __DIR__.'/../../backup_lib.php';require __DIR__.'/../../restore_jobs.php';
function check_restore(bool $ok,string $msg):void{if(!$ok)throw new RuntimeException($msg);}
function refuses_restore(callable $fn,string $msg):void{$failed=false;try{$fn();}catch(Throwable){$failed=true;}check_restore($failed,$msg);}
function fixture_restore(string $path,bool $tamper=false,string $scope='data'):void{
    $password='Fixture-password-123';$salt=random_bytes(16);$key=tt_backup_derive_key($password,$salt,200000);$zip=new TT_SimpleZipWriter($path);$map=[];
    $files=['System_Recovery/private/auth.json'=>json_encode(['users'=>[['id'=>9,'password_hash'=>'OLD-DO-NOT-RESTORE']],'masters'=>['fixture'=>[['id'=>'restored-master']]]]),'System_Recovery/private/accounts.json'=>'{"journals":{"OLD":{"amount":42}}}','System_Recovery/private/operations.json'=>'{"values":{"tt01test":"[]"},"meta":{}}','System_Recovery/private/documents/example.pdf'=>str_repeat('old-document',100000),'System_Recovery/private/office-backup-token.sha256'=>'OLD-TOKEN','System_Recovery/application/index.php'=>'NEVER-EXECUTE'];
    foreach($files as$name=>$plain){$e=tt_backup_encrypt($plain,$key,true);$cipher=$e['cipher'];unset($e['cipher']);if($tamper&&str_ends_with($name,'example.pdf'))$cipher[0]=chr(ord($cipher[0])^1);$e['stored']='Encrypted/'.count($map).'.bin';$zip->addString($e['stored'],$cipher,false);$map[$name]=$e;}
    $zip->addString('backup-manifest.json',json_encode(['application'=>'Transtrade','type'=>'full_recovery','schema'=>3,'scope'=>$scope,'createdAt'=>'2026-10-26T10:00:00Z','softwareRevision'=>'fixture-revision','encryption'=>['algorithm'=>'AES-256-GCM','salt'=>base64_encode($salt),'iterations'=>200000],'encryptedEntries'=>$map]));$zip->close();
}
function prepare_restore(string $path):array{
    $hashes=[];$h=fopen($path,'rb');while(!feof($h)){$b=fread($h,TT_RESTORE_CHUNK);if($b!=='')$hashes[]=hash('sha256',$b);}fclose($h);
    $s=ttr_start(['size'=>filesize($path),'chunks'=>$hashes,'name'=>basename($path)],['id'=>1]);
    $h=fopen($path,'rb');while($s['received']<$s['size']){$offset=$s['received'];$bytes=fread($h,TT_RESTORE_CHUNK);$s=ttr_chunk($s,$offset,$bytes);check_restore(ttr_chunk($s,$offset,$bytes)['received']===$s['received'],'duplicate piece is idempotent');}fclose($h);return $s;
}
function clear_restore_work():void{foreach(['state.json','staged','upload.zip']as$n)ttr_delete_work(ttr_root().'/'.$n);}
try{
    file_put_contents(TT_STORE_FILE,json_encode(['users'=>[['id'=>1,'username'=>'owner','password_hash'=>'CURRENT','session_version'=>3]],'masters'=>['fixture'=>[['id'=>'current-master']]],'audit'=>[]]));
    file_put_contents(TT_DATA_DIR.'/accounts.json','{"journals":{"NEW":{"amount":99}}}');file_put_contents(TT_DATA_DIR.'/newer-only.json','{"present":true}');file_put_contents(TT_DATA_DIR.'/office-backup-token.sha256','CURRENT-TOKEN');
    fixture_restore($root.'/backup.zip');$s=prepare_restore($root.'/backup.zip');
    refuses_restore(fn()=>ttr_owner($s,['id'=>2],$s['id']),'another owner cannot use a job');
    refuses_restore(fn()=>ttr_chunk($s,0,'tampered'),'wrong upload bytes rejected');
    refuses_restore(fn()=>ttr_verify($s,'Wrong-password-123'),'wrong password refused');
    check_restore(!is_file(TT_DATA_DIR.'/restore-maintenance.lock'),'verification does not pause business');
    while($s['state']!=='verified')$s=ttr_verify(ttr_read(),'Fixture-password-123');
    check_restore(str_contains(file_get_contents(TT_DATA_DIR.'/accounts.json'),'NEW'),'verification cannot modify live entries');
    check_restore(!isset($s['staged']['office-backup-token.sha256']),'old connection token excluded');
    check_restore(!is_file($root.'/index.php'),'application code never executed or installed');
    refuses_restore(fn()=>ttr_confirm($s,''),'confirmation required');$s=ttr_confirm($s,'RESTORE');
    check_restore(is_file(TT_DATA_DIR.'/restore-maintenance.lock'),'site stays paused between steps');
    while($s['state']!=='done'){$s=ttr_step(ttr_read());check_restore(!in_array($s['state'],['failed','rolled_back'],true),'healthy restore completes');}
    check_restore(!is_file(TT_DATA_DIR.'/restore-maintenance.lock'),'site reopens only after completion');
    check_restore(str_contains(file_get_contents(TT_DATA_DIR.'/accounts.json'),'OLD'),'old journal restored');
    check_restore(!is_file(TT_DATA_DIR.'/newer-only.json'),'files absent from snapshot retained only in safety copy');
    $auth=json_decode(file_get_contents(TT_STORE_FILE),true);check_restore($auth['users'][0]['password_hash']==='CURRENT'&&$auth['users'][0]['session_version']===4,'current login retained and old sessions invalidated');
    check_restore(file_get_contents(TT_DATA_DIR.'/office-backup-token.sha256')==='CURRENT-TOKEN','current connection remains');
    check_restore(str_contains(file_get_contents(tt_backup_dir().'/'.$s['safetySnapshot'].'/private/accounts.json'),'NEW'),'independently readable old data safety copy');
    check_restore(ttr_step($s)['state']==='done'&&ttr_confirm($s,'RESTORE')['state']==='done','lost final response does not rerun restore');
    clear_restore_work();fixture_restore($root.'/bad.zip',true);$s=prepare_restore($root.'/bad.zip');refuses_restore(fn()=>ttr_verify($s,'Fixture-password-123'),'late tamper rejected before confirmation');clear_restore_work();
    fixture_restore($root.'/software.zip',false,'software');$s=prepare_restore($root.'/software.zip');refuses_restore(fn()=>ttr_verify($s,'Fixture-password-123'),'software-only rejected');clear_restore_work();
    $before=ttr_digest(TT_DATA_DIR.'/accounts.json');$s=prepare_restore($root.'/backup.zip');while($s['state']!=='verified')$s=ttr_verify(ttr_read(),'Fixture-password-123');$s=ttr_confirm($s,'RESTORE');while($s['state']==='safety')$s=ttr_step(ttr_read());
    // Emulate a process loss after a live replacement but before its cursor commit.
    file_put_contents(TT_DATA_DIR.'/accounts.json','partial-write-sentinel');
    unlink(ttr_root().'/staged/documents/example.pdf'); // Forces guarded rollback after other files were already applied.
    for($n=0;$n<30&&$s['state']!=='rolled_back';$n++)$s=ttr_step(ttr_read());
    check_restore($s['state']==='rolled_back'&&ttr_digest(TT_DATA_DIR.'/accounts.json')===$before,'mid-commit failure returns exact original data');
    check_restore(!is_file(TT_DATA_DIR.'/restore-maintenance.lock'),'rollback completes before reopening');
    refuses_restore(fn()=>ttr_safe_path(TT_DATA_DIR,'../escape.json'),'traversal blocked');
    symlink($root,TT_DATA_DIR.'/linked');refuses_restore(fn()=>ttr_safe_path(TT_DATA_DIR,'linked/escape.json'),'symlinks blocked');unlink(TT_DATA_DIR.'/linked');
    echo "WEBSITE_RESTORE_RESUME_CONFIRM_SAFETY_ROLLBACK_OK\n";
}finally{ttr_delete_work($root);}
