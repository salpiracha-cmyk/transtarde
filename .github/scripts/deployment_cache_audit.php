<?php
declare(strict_types=1);
// CLI-only operational cache maintenance. Never opens the application's private stores.
function dc_error_kind(string $message):string {
    if(stripos($message,'quota')!==false)return 'DISK_QUOTA_EXCEEDED';
    if(stripos($message,'No space left')!==false)return 'FILESYSTEM_FULL';
    if(stripos($message,'Permission denied')!==false)return 'PERMISSION_DENIED';
    if(stripos($message,'Read-only file system')!==false)return 'READ_ONLY_FILESYSTEM';
    return 'OTHER_WRITE_ERROR';
}
function dc_directory(string $path):void {
    if(!is_dir($path)||is_link($path)||realpath($path)!==$path)throw new RuntimeException('Unsafe or unavailable generated cache directory.');
}
function dc_inventory(string $path):array {
    $out=['files'=>0,'directories'=>0,'bytes'=>0,'links'=>0,'complete'=>true];
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
    foreach($it as $item){
        if($item->isLink())$out['links']++;elseif($item->isDir())$out['directories']++;elseif($item->isFile()){$out['files']++;$out['bytes']+=$item->getSize();}
        if($out['files']+$out['directories']+$out['links']>=1000000){$out['complete']=false;break;}
    }
    return $out;
}
function dc_probe(string $incoming):array {
    dc_directory($incoming);$path=$incoming.'/.storage-probe-'.bin2hex(random_bytes(12)).'.tmp';$created=false;$h=null;
    $out=['ok'=>false,'bytesWritten'=>0,'errorKind'=>null,'removed'=>false];
    set_error_handler(static function(int $level,string $message)use(&$out):bool{$out['errorKind']??=dc_error_kind($message);return true;});
    try{
        $h=fopen($path,'x+b');
        if($h!==false){
            $created=true;$written=fwrite($h,str_repeat('0',4096));$out['bytesWritten']=$written===false?0:$written;
            $flushed=fflush($h);$synced=function_exists('fsync')?fsync($h):true;
            $out['ok']=$written===4096&&$flushed&&$synced&&$out['errorKind']===null;
        }
    }finally{
        if(is_resource($h))fclose($h);
        if($created)$out['removed']=unlink($path);else $out['removed']=true;
        restore_error_handler();
    }
    return $out;
}
function dc_remove_stage(string $stage):void {
    dc_directory($stage);
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $item){$p=$item->getPathname();$ok=$item->isLink()||!$item->isDir()?unlink($p):rmdir($p);if(!$ok)throw new RuntimeException('Generated cache cleanup incomplete.');}
    if(!rmdir($stage))throw new RuntimeException('Generated stage cleanup incomplete.');
}
function dc_clean(string $root):array {
    dc_directory($root);if(basename($root)!=='.transtrade-deploy')throw new RuntimeException('Unexpected deployment cache root.');
    // Validate both writable families before any deletion. Backups are never visited.
    foreach(['incoming','staging'] as $kind)dc_directory($root.'/'.$kind);
    $out=['incomingFilesRemoved'=>0,'stageDirectoriesRemoved'=>0,'bytesRemoved'=>0,'unknownOrUnsafeEntriesRetained'=>0,'businessFilesTouched'=>0,'backupFilesTouched'=>0];
    foreach(new DirectoryIterator($root.'/incoming') as $item){
        if($item->isDot())continue;
        if($item->isLink()||!$item->isFile()||!preg_match('/^release-[a-f0-9]{40}\.tgz$/D',$item->getFilename())){$out['unknownOrUnsafeEntriesRetained']++;continue;}
        $bytes=$item->getSize();if(!unlink($item->getPathname()))throw new RuntimeException('Generated incoming archive cleanup incomplete.');$out['incomingFilesRemoved']++;$out['bytesRemoved']+=$bytes;
    }
    foreach(new DirectoryIterator($root.'/staging') as $item){
        if($item->isDot())continue;$path=$item->getPathname();
        if($item->isLink()||!$item->isDir()||!preg_match('/^[a-f0-9]{40}$/D',$item->getFilename())){$out['unknownOrUnsafeEntriesRetained']++;continue;}
        $safe=true;foreach(['login.php','module.php','exports/app.js'] as $marker)if(is_link($path.'/'.$marker)||!is_file($path.'/'.$marker))$safe=false;
        foreach(['data','uploads','storage','cache','logs','transtrade_private'] as $runtime){
            if(is_link($path.'/'.$runtime)){$safe=false;continue;}
            if(is_dir($path.'/'.$runtime)&&(new FilesystemIterator($path.'/'.$runtime,FilesystemIterator::SKIP_DOTS))->valid())$safe=false;
        }
        if(!$safe){$out['unknownOrUnsafeEntriesRetained']++;continue;}
        $inventory=dc_inventory($path);if(!$inventory['complete']||$inventory['links']){$out['unknownOrUnsafeEntriesRetained']++;continue;}
        dc_remove_stage($path);$out['stageDirectoriesRemoved']++;$out['bytesRemoved']+=$inventory['bytes'];
    }
    return $out;
}
if(defined('TT_DEPLOY_CACHE_FUNCTIONS_ONLY'))return;
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
umask(0077);
try{
    $target=rtrim((string)($argv[1]??''),'/');
    if(!preg_match('~^/home/[^/]+/domains/app\.transtradeinternational\.com/public_html$~D',$target)||realpath($target)!==$target||is_link($target))throw new RuntimeException('Unexpected production target.');
    $root=dirname($target).'/.transtrade-deploy';dc_directory($root);dc_directory($root.'/incoming');
    $release=trim((string)file_get_contents($target.'/.transtrade-release'));if(!preg_match('/^[a-f0-9]{40}$/D',$release))throw new RuntimeException('Invalid deployed source identity.');
    $out=['deployedSha'=>$release,'probeBefore'=>dc_probe($root.'/incoming'),'cleanup'=>null];
    if(in_array('--cleanup',$argv,true))$out['cleanup']=dc_clean($root);
    $out['probeAfter']=dc_probe($root.'/incoming');$out['ok']=$out['probeAfter']['ok']&&$out['probeBefore']['removed']&&$out['probeAfter']['removed'];
    echo json_encode($out,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";exit($out['ok']?0:1);
}catch(Throwable){echo json_encode(['ok'=>false,'errorKind'=>'DEPLOYMENT_CACHE_AUDIT_STOPPED'])."\n";exit(1);}
