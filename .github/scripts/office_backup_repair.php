<?php
// Main-only, hash-guarded repair. No application bootstrap or restore is run.
declare(strict_types=1);
$target=getenv('TT_OFFICE_BACKUP_REPAIR_ROOT')?:'';
if(!preg_match('~^/home/[A-Za-z0-9_-]+/domains/app\.transtradeinternational\.com/public_html$~D',$target))throw new RuntimeException('Unexpected repair target');
$private=dirname($target).'/transtrade_private';$deploy=dirname($target).'/.transtrade-deploy';
foreach([$target,$private,$deploy]as$dir)if(!is_dir($dir)||is_link($dir)||realpath($dir)!==$dir)throw new RuntimeException('Unsafe repair root');
$package=json_decode(base64_decode('__OFFICE_BACKUP_PACKAGE__',true)?:'',true,512,JSON_THROW_ON_ERROR);
$expected=['backup_lib.php'=>'8ab44ee566b00a6e78439c9657f77b9534b8795f1e5dfbc15d7cc20f2b5a7923','api/backup.php'=>'1e7e08907f2af3419d37a8f0bb3e43ec4d84780c01863497fe47b8d14e1b4233','api/office_backup.php'=>'8f32e9bc75d75261c0e0cc6d9aeb4362df115bba0b60d43a6dbd365300f8b015'];
if(array_keys($package)!==array_keys($expected))throw new RuntimeException('Unexpected repair package');
foreach($package as$rel=>$entry){
    $source=base64_decode($entry['source']??'',true);$live=$target.'/'.$rel;
    if(!is_string($source)||!hash_equals($entry['sha256']??'',hash('sha256',$source)))throw new RuntimeException('Package hash mismatch');
    if(!is_file($live)||is_link($live)||realpath(dirname($live))!==dirname($live)||!in_array(hash_file('sha256',$live),[$expected[$rel],$entry['sha256']],true))throw new RuntimeException('Installed code changed; refusing overwrite: '.$rel);
}
$work=$deploy.'/office-backup-repair';if(is_link($work)||(!is_dir($work)&&!mkdir($work,0700)))throw new RuntimeException('Unsafe repair directory');
if(realpath($work)!==$work)throw new RuntimeException('Unsafe repair path');
$lock=fopen($work.'/job.lock','c+');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Repair is already running');
define('TT_DATA_DIR',$private);define('TT_STORE_FILE',$private.'/auth.json');
function tt_ensure_data_dir():void{if(!is_dir(TT_DATA_DIR)||is_link(TT_DATA_DIR))throw new RuntimeException('Private directory unavailable');}
function tt_read_store():array{$raw=file_get_contents(TT_STORE_FILE);$store=json_decode($raw?:'',true,512,JSON_THROW_ON_ERROR);if(!is_array($store))throw new RuntimeException('Invalid store');return $store;}
$authLock=null;$opsLock=null;$candidate=$work.'/candidate-library.php';$archive='';$installed=[];
try{
    foreach(['auth.lock','operations.json']as$rel){$file=$private.'/'.$rel;if(!is_file($file)||is_link($file))throw new RuntimeException('Storage lock unavailable');$handle=fopen($file,'rb');if(!$handle||!flock($handle,LOCK_SH))throw new RuntimeException('Storage lock unavailable');if($rel==='auth.lock')$authLock=$handle;else$opsLock=$handle;}
    // Bind __DIR__ to the real app root while testing the trusted candidate from
    // a private staging file. No executable candidate is exposed through HTTP.
    $library=str_replace('__DIR__',var_export($target,true),base64_decode($package['backup_lib.php']['source'],true));
    if(is_link($candidate)||file_put_contents($candidate,$library,LOCK_EX)!==strlen($library))throw new RuntimeException('Could not stage candidate');chmod($candidate,0600);
    require $candidate;
    $start=microtime(true);$password=bin2hex(random_bytes(24));
    echo "GENERATING_CANDIDATE_RECOVERY\n";flush();
    $built=tt_build_download_backup(true,$password);$archive=$built['path'];
    $generated=microtime(true);$manifest=tt_backup_verify_full($archive,$password);
    echo 'CANDIDATE_RECOVERY_VERIFIED '.json_encode(['bytes'=>filesize($archive),'generationSeconds'=>round($generated-$start,2),'verificationSeconds'=>round(microtime(true)-$generated,2),'recoveryFiles'=>$manifest['recoveryFileCount'],'schema'=>$manifest['schema'],'sha256'=>hash_file('sha256',$archive),'restoreExecuted'=>false],JSON_THROW_ON_ERROR)."\n";flush();
    // Ownership-qualified metadata only. No abandoned file is removed here.
    $owned=['files'=>0,'bytes'=>0,'olderThan24Hours'=>0];$uid=function_exists('posix_geteuid')?posix_geteuid():null;
    foreach(glob(sys_get_temp_dir().'/tt-backup-*')?:[]as$path)if($uid!==null&&is_file($path)&&!is_link($path)&&fileowner($path)===$uid){$owned['files']++;$owned['bytes']+=filesize($path);if(time()-max(filemtime($path),filectime($path))>86400)$owned['olderThan24Hours']++;}
    echo 'OWNED_TEMPORARY_ARCHIVE_METADATA '.json_encode($owned,JSON_THROW_ON_ERROR)."\n";
    foreach($package as$rel=>$entry){
        $live=$target.'/'.$rel;$source=base64_decode($entry['source'],true);
        if(hash_equals($entry['sha256'],hash_file('sha256',$live)))continue;
        if(!hash_equals($expected[$rel],hash_file('sha256',$live)))throw new RuntimeException('Code changed during validation');
        $rollback=$work.'/previous-'.str_replace('/','-',$rel);
        if(is_link($rollback)||!copy($live,$rollback)||!hash_equals($expected[$rel],hash_file('sha256',$rollback)))throw new RuntimeException('Could not preserve previous code');chmod($rollback,0600);
        $stage=tempnam(dirname($live),'.office-backup-repair-');if($stage===false)throw new RuntimeException('Could not stage repair');
        try{if(file_put_contents($stage,$source)!==strlen($source)||!hash_equals($entry['sha256'],hash_file('sha256',$stage)))throw new RuntimeException('Staged repair differs');chmod($stage,0644);if(!rename($stage,$live))throw new RuntimeException('Could not install repair');$installed[$rel]=$rollback;}finally{if(is_file($stage))@unlink($stage);}
    }
    foreach($package as$rel=>$entry)if(!hash_equals($entry['sha256'],hash_file('sha256',$target.'/'.$rel)))throw new RuntimeException('Installed repair verification failed');
    echo "OFFICE_BACKUP_REPAIR_INSTALLED existing_backups_deleted=0 restores=0\n";
}catch(Throwable $error){
    foreach(array_reverse($installed,true)as$rel=>$rollback){$stage=tempnam(dirname($target.'/'.$rel),'.office-backup-rollback-');if($stage!==false){if(copy($rollback,$stage)){chmod($stage,0644);rename($stage,$target.'/'.$rel);}if(is_file($stage))@unlink($stage);}}
    throw $error;
}finally{
    if($archive!==''&&is_file($archive))@unlink($archive);
    if(is_file($candidate))@unlink($candidate);
    foreach([$opsLock,$authLock]as$handle)if(is_resource($handle)){flock($handle,LOCK_UN);fclose($handle);}
    flock($lock,LOCK_UN);fclose($lock);
}
