<?php
declare(strict_types=1);
// CLI delivery of the already-tested two-file repair. Never loads application code.
function lr_kind(string $message): string {
    if(stripos($message,'quota')!==false)return 'DISK_QUOTA_EXCEEDED';
    if(stripos($message,'No space left')!==false)return 'FILESYSTEM_FULL';
    if(stripos($message,'Permission denied')!==false)return 'PERMISSION_DENIED';
    return 'WRITE_FAILED';
}
function lr_write(string $path,string $bytes,int $mode=0600): void {
    $h=fopen($path,'x+b');if(!$h)throw new RuntimeException('WRITE_FAILED');
    try{
        chmod($path,$mode);$offset=0;
        while($offset<strlen($bytes)){$n=fwrite($h,substr($bytes,$offset));if(!$n)throw new RuntimeException('WRITE_FAILED');$offset+=$n;}
        if(!fflush($h)||(function_exists('fsync')&&!fsync($h)))throw new RuntimeException('WRITE_FAILED');
    }finally{fclose($h);}
}
function lr_probe(string $directory,int $bytes): array {
    if($bytes<4096||$bytes>8388608)throw new RuntimeException('PROBE_BOUND');
    if(!is_dir($directory)||is_link($directory)||realpath($directory)!==$directory)throw new RuntimeException('UNSAFE_PROBE_DIRECTORY');
    $path=$directory.'/.login-capacity-'.bin2hex(random_bytes(12)).'.tmp';
    $result=['ok'=>false,'bytes'=>0,'errorKind'=>null,'removed'=>false];$h=null;$created=false;
    set_error_handler(static function(int $level,string $message)use(&$result):bool{$result['errorKind']??=lr_kind($message);return true;});
    try{
        $h=fopen($path,'x+b');
        if($h!==false){$created=true;chmod($path,0600);
            while($result['bytes']<$bytes){$n=fwrite($h,str_repeat('0',min(65536,$bytes-$result['bytes'])));if(!$n||$result['errorKind'])break;$result['bytes']+=$n;}
            $flushed=fflush($h);$synced=!function_exists('fsync')||fsync($h);
            $result['ok']=$result['bytes']===$bytes&&$flushed&&$synced&&!$result['errorKind'];
        }
    }finally{
        if(is_resource($h))fclose($h);
        $result['removed']=!$created||unlink($path);restore_error_handler();
    }
    return $result;
}
function lr_apply(string $target,array $package,bool $fixture=false): array {
    if(!$fixture&&!preg_match('~^/home/[^/]+/domains/app\.transtradeinternational\.com/public_html$~D',$target))throw new RuntimeException('UNEXPECTED_TARGET');
    if(!is_dir($target)||is_link($target)||realpath($target)!==$target)throw new RuntimeException('UNSAFE_TARGET');
    $root=dirname($target).'/.transtrade-deploy';
    foreach([$root,$root.'/incoming',$root.'/backups',$root.'/staging']as$dir)
        if(!is_dir($dir)||is_link($dir)||realpath($dir)!==$dir)throw new RuntimeException('UNSAFE_DEPLOY_DIRECTORY');
    $sha=(string)($package['releaseSha']??'');if(!preg_match('/^[a-f0-9]{40}$/D',$sha))throw new RuntimeException('INVALID_RELEASE');
    $files=$package['files']??[];if(array_keys($files)!==['session_store.php','auth_store.php'])throw new RuntimeException('UNEXPECTED_PATCH_FILES');
    $old=[];$modes=[];$mismatches=[];
    foreach($package['manifest']??[]as$name=>$hash){
        if(!preg_match('~^[A-Za-z0-9_. /-]+$~D',$name)||in_array('..',explode('/',$name),true)||str_starts_with($name,'/'))throw new RuntimeException('UNSAFE_MANIFEST');
        $path=$target.'/'.$name;
        if(is_link($path)||!is_file($path)||!str_starts_with((string)realpath($path),$target.'/')){$mismatches[]=$name;continue;}
        $actual=hash_file('sha256',$path);
        if(isset($files[$name])){
            if(!in_array($actual,[$hash,$package['oldHashes'][$name]??''],true))$mismatches[]=$name;
        }elseif(!hash_equals($hash,$actual))$mismatches[]=$name;
    }
    if($mismatches)throw new RuntimeException('SOURCE_MISMATCH: '.implode(', ',array_slice($mismatches,0,12)));
    foreach($files as$name=>$encoded){
        $new=base64_decode($encoded,true);if($new===false||!isset($package['manifest'][$name])||!hash_equals($package['manifest'][$name],hash('sha256',$new)))throw new RuntimeException('INVALID_PATCH_HASH');
        $old[$name]=file_get_contents($target.'/'.$name);$modes[$name]=fileperms($target.'/'.$name)&0777;
    }
    $auth=dirname($target).'/transtrade_private/auth.json';$size=is_file($auth)?filesize($auth):0;
    $probe=lr_probe($root.'/incoming',min(8388608,max(4096,2*$size)));
    echo json_encode(['capacityProbe'=>$probe],JSON_THROW_ON_ERROR)."\n";
    if(!$probe['ok']||!$probe['removed'])throw new RuntimeException($probe['errorKind']??'CAPACITY_PROBE_FAILED');
    $backup=$root.'/backups/login-recovery-'.$sha.'-'.bin2hex(random_bytes(6)).'.json.gz';
    lr_write($backup,gzencode(json_encode(['files'=>array_map('base64_encode',$old),'sha256'=>array_map(static fn($s)=>hash('sha256',$s),$old)],JSON_THROW_ON_ERROR),9));
    $stage=$root.'/staging/login-recovery-'.$sha.'-'.bin2hex(random_bytes(6));if(!mkdir($stage,0700))throw new RuntimeException('STAGE_FAILED');
    $written=[];
    try{
        foreach($files as$name=>$encoded)lr_write($stage.'/'.$name,base64_decode($encoded,true),$modes[$name]);
        foreach($files as$name=>$encoded){if(!rename($stage.'/'.$name,$target.'/'.$name))throw new RuntimeException('INSTALL_FAILED');$written[]=$name;}
        foreach($files as$name=>$encoded)if(!hash_equals($package['manifest'][$name],hash_file('sha256',$target.'/'.$name)))throw new RuntimeException('INSTALLED_HASH_MISMATCH');
        lr_write($stage.'/release-marker',$sha."\n",0644);
        if(!rename($stage.'/release-marker',$target.'/.transtrade-release'))throw new RuntimeException('RELEASE_MARKER_FAILED');
        return ['ok'=>true,'releaseSha'=>$sha,'verifiedApplicationFiles'=>count($package['manifest']),'codeFilesReplaced'=>2,'backupCreated'=>true,'businessFilesWritten'=>0,'historicalBackupsRemoved'=>0];
    }catch(Throwable $error){
        foreach(array_reverse($written)as$name){lr_write($stage.'/rollback-'.$name,$old[$name],$modes[$name]);if(!rename($stage.'/rollback-'.$name,$target.'/'.$name))throw new RuntimeException('ROLLBACK_FAILED');}
        throw $error;
    }finally{
        foreach(['auth_store.php','session_store.php','rollback-auth_store.php','rollback-session_store.php','release-marker']as$name)if(is_file($stage.'/'.$name)&&!is_link($stage.'/'.$name))unlink($stage.'/'.$name);
        rmdir($stage);
    }
}
function lr_main(string $target,array $package): void {
    if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
    umask(0077);set_error_handler(static function(int $level,string $message):never{throw new RuntimeException(lr_kind($message));});
    try{echo json_encode(lr_apply($target,$package),JSON_THROW_ON_ERROR)."\n";}
    catch(Throwable $error){echo json_encode(['ok'=>false,'errorKind'=>$error->getMessage()],JSON_THROW_ON_ERROR)."\n";exit(1);}
    finally{restore_error_handler();}
}
