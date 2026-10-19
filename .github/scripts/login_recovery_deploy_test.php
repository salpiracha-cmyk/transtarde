<?php
declare(strict_types=1);
require __DIR__.'/login_recovery_deploy.php';
function check(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
$base=sys_get_temp_dir().'/tti-login-delivery-'.bin2hex(random_bytes(8));mkdir($base,0700);$target=$base.'/public_html';mkdir($target);
foreach(['incoming','staging','backups']as$name)mkdir($base.'/.transtrade-deploy/'.$name,0700,true);
mkdir($base.'/transtrade_private');file_put_contents($base.'/transtrade_private/auth.json','{"users":["preserved"]}');
$old=['session_store.php'=>'old session','auth_store.php'=>'old auth'];$new=['session_store.php'=>'new session','auth_store.php'=>'new auth'];
foreach($old as$name=>$data)file_put_contents($target.'/'.$name,$data);
file_put_contents($target.'/login.php','unchanged login');file_put_contents($base.'/.transtrade-deploy/backups/historical.tgz','historical backup');
$package=['releaseSha'=>str_repeat('a',40),'files'=>array_map('base64_encode',$new),'oldHashes'=>array_map(static fn($s)=>hash('sha256',$s),$old),'manifest'=>array_map(static fn($s)=>hash('sha256',$s),$new)+['login.php'=>hash('sha256','unchanged login')]];
try{
    $probe=lr_probe($base.'/.transtrade-deploy/incoming',4194304);check($probe['ok']&&$probe['bytes']===4194304&&$probe['removed'],'Capacity probe must write, sync and remove only its own file');
    foreach([0,8388609]as$n){try{lr_probe($base.'/.transtrade-deploy/incoming',$n);throw new Exception('bound accepted');}catch(RuntimeException $e){check($e->getMessage()==='PROBE_BOUND','Strict probe bound');}}
    file_put_contents($target.'/login.php','different source');
    try{lr_apply($target,$package,true);throw new Exception('mismatch accepted');}catch(RuntimeException $e){check(str_starts_with($e->getMessage(),'SOURCE_MISMATCH'),'Unexpected source must stop before writes');}
    check(count(glob($base.'/.transtrade-deploy/backups/*'))===1,'Source mismatch creates no backup or patch');
    file_put_contents($target.'/login.php','unchanged login');
    symlink($base.'/.transtrade-deploy/incoming',$base.'/link');
    try{lr_probe($base.'/link',4096);throw new Exception('symlink accepted');}catch(RuntimeException $e){check($e->getMessage()==='UNSAFE_PROBE_DIRECTORY','No symlink write target');}
    // A real install failure after both replacements must restore both original files.
    mkdir($target.'/.transtrade-release');
    set_error_handler(static function(int $level,string $message):never{throw new RuntimeException(lr_kind($message));});
    try{lr_apply($target,$package,true);throw new Exception('failed marker accepted');}catch(RuntimeException $e){check($e->getMessage()==='WRITE_FAILED','Expected marker install failure');}
    finally{restore_error_handler();}
    foreach($old as$name=>$bytes)check(file_get_contents($target.'/'.$name)===$bytes,'Rollback must restore each original');
    rmdir($target.'/.transtrade-release');$result=lr_apply($target,$package,true);
    check($result['ok']&&$result['businessFilesWritten']===0&&$result['historicalBackupsRemoved']===0,'Bounded successful patch');
    foreach($new as$name=>$bytes)check(file_get_contents($target.'/'.$name)===$bytes,'Expected new source');
    check(file_get_contents($target.'/.transtrade-release')===$package['releaseSha']."\n",'Exact release marker');
    foreach(glob($base.'/.transtrade-deploy/backups/login-*.gz')as$backup){$saved=json_decode(gzdecode(file_get_contents($backup)),true);check(array_map('base64_decode',$saved['files'])===$old,'Recoverable original code pair');}
    check(file_get_contents($base.'/transtrade_private/auth.json')==='{"users":["preserved"]}','Authentication store unchanged');
    check(file_get_contents($base.'/.transtrade-deploy/backups/historical.tgz')==='historical backup','Historical backup unchanged');
    check(glob($base.'/.transtrade-deploy/staging/*')===[]&&glob($base.'/.transtrade-deploy/incoming/*')===[],'Owned stage and probes removed');
    echo "PASS bounded capacity probe, source mismatch refusal, symlink refusal, two-file rollback, recoverable code backup, exact installed hashes, release marker and unchanged private/historical files\n";
}finally{
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as$item){if($item->isLink()||!$item->isDir())unlink($item->getPathname());else rmdir($item->getPathname());}rmdir($base);
}
