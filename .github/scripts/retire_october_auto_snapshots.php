<?php
declare(strict_types=1);

// Owner approved exactly seven October 2026 automatic snapshots and the measured byte total.
$target=(string)getenv('TT_RETIRE_TARGET');
if(PHP_SAPI!=='cli'||!preg_match('~^/home/[a-zA-Z0-9_-]+/domains/app\.transtradeinternational\.com/public_html$~D',$target)||realpath($target)!==$target)
    throw new RuntimeException('Unexpected production root.');
define('TT_DATA_DIR',dirname($target).'/transtrade_private');
$root=TT_DATA_DIR.'/backups';
if(realpath($root)!==$root||!is_dir($root))throw new RuntimeException('Unexpected backup root.');
require $target.'/backup_lib.php';

function archiveIdentity(string $path): array {
    clearstatcache(true,$path);
    $st=lstat($path);
    if(!$st||($st['mode']&0170000)!==0100000||$st['nlink']!==1||realpath($path)!==$path)
        throw new RuntimeException('Unexpected archive file type or identity.');
    return array_intersect_key($st,array_flip(['dev','ino','size','mtime','ctime']));
}

$lockPath=tt_backup_job_lock_file();
if(is_link($lockPath))throw new RuntimeException('Unexpected lock type.');
$lock=fopen($lockPath,'c+');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Another backup job holds the lock.');
try{
    if(file_exists(TT_DATA_DIR.'/restore-maintenance.lock'))throw new RuntimeException('A restore is in progress.');
    $candidates=[];
    foreach(glob($root.'/auto-202610*.zip')?:[] as $path){
        $identity=archiveIdentity($path);
        $meta=tt_backup_snapshot_metadata($path);
        if(!$meta||gmdate('Y-m',$meta['created'])!=='2026-10')
            throw new RuntimeException('An October candidate has unexpected metadata.');
        $candidates[$path]=$identity;
    }
    if(count($candidates)!==7||array_sum(array_column($candidates,'size'))!==5631969944)
        throw new RuntimeException('The approved seven-file inventory no longer matches.');
    $survivors=[];
    foreach(glob($root.'/manual-20261026-*.zip')?:[] as $path){
        $identity=archiveIdentity($path);
        if($identity['size']===743372740)$survivors[]=$path;
    }
    if(count($survivors)!==1)throw new RuntimeException('Expected October 26 manual survivor not found.');
    $survivor=$survivors[0];
    $manifest=tt_backup_verified_server_snapshot(basename($survivor));
    $survivorCreated=strtotime((string)($manifest['createdAt']??''));
    if(!$survivorCreated||!tt_backup_has_verified_receipt($survivor))
        throw new RuntimeException('Could not confirm the surviving manual backup.');
    foreach($candidates as $path=>$identity){
        $meta=tt_backup_snapshot_metadata($path);
        if(!$meta||$meta['created']>=$survivorCreated||archiveIdentity($path)!==$identity)
            throw new RuntimeException('A candidate changed or is newer than the verified survivor.');
    }
    echo 'VERIFIED_MANUAL_SURVIVOR '.basename($survivor).PHP_EOL;
    $removed=0;
    $bytes=0;
    foreach($candidates as $path=>$identity){
        if(archiveIdentity($path)!==$identity||!unlink($path))
            throw new RuntimeException('Stopped during removal. Review the deletion receipts.');
        $removed++;
        $bytes+=$identity['size'];
        printf("REMOVED %s %d\n",basename($path),$identity['size']);
    }
    echo json_encode(['removed'=>$removed,'bytes'=>$bytes,'manualSurvivor'=>is_file($survivor),'businessFilesTouched'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
}finally{
    flock($lock,LOCK_UN);
    fclose($lock);
}
