<?php
// Narrow, owner-authorized cleanup; never bootstrap the application or restore data.
declare(strict_types=1);
function otc_identity(string $path): ?array {
    clearstatcache(true,$path);$s=@lstat($path);
    if(!$s||($s['mode']&0170000)!==0100000||is_link($path))return null;
    return array_intersect_key($s,array_flip(['dev','ino','mode','uid','nlink','size','mtime','ctime']));
}
function otc_legacy_grace(array $s,int $now,int $schema,int $created):bool {
    $cutoff=strtotime('2026-10-20T10:17:16Z');
    return $schema<=2&&$schema>=1&&$created<$cutoff&&$s['mtime']<$cutoff&&$s['ctime']<$cutoff&&$now-max($s['mtime'],$s['ctime'],$created)>=1800;
}
// Owner approval at 22:45:20 Pakistan time covers only the measured six-file cohort.
function otc_approved_cohort(array $identities,int $uid):bool {
    $owned=array_filter($identities,static fn($s)=>is_array($s)&&$s['uid']===$uid);
    return (count($owned)===6&&array_sum(array_column($owned,'size'))===6856173595)
        ||(count($owned)===4&&array_sum(array_column($owned,'size'))===3841208033);
}
function otc_owner_grace(array $s,int $now,int $schema,int $created,int $approvedBefore):bool {
    return $approvedBefore>0&&$schema>=1&&$schema<=3&&$created<$approvedBefore
        &&$s['mtime']<$approvedBefore&&$s['ctime']<$approvedBefore
        &&$now-max($s['mtime'],$s['ctime'],$created)>=1800;
}
/** Identify an interrupted export from eight sequential, CRC-checked local entries.
 * No ciphertext is printed, restored, decrypted, or executed. */
function otc_partial_export(string $path):?string {
    $h=@fopen($path,'rb');if(!$h)return null;$fingerprint=hash_init('sha256');
    try{
        for($i=1;$i<=8;$i++){
            $header=fread($h,30);if(strlen($header)!==30||substr($header,0,4)!=="PK\x03\x04")return null;
            $e=unpack('vversion/vflags/vmethod/vtime/vdate/Vcrc/Vpacked/Vplain/vname/vextra',substr($header,4));
            if($e['version']!==20||$e['flags']!==0x0800||$e['method']!==0||$e['packed']!==$e['plain']||$e['packed']>67108864||$e['extra']!==0)return null;
            $expected='Encrypted/'.sprintf('%06d',$i).'.bin';
            if($e['name']!==strlen($expected))return null;$name=fread($h,$e['name']);if($name!==$expected)return null;
            hash_update($fingerprint,$header.$name);$crc=hash_init('crc32b');$left=$e['packed'];
            while($left>0){$chunk=fread($h,min(1048576,$left));if($chunk===false||$chunk==='')return null;$left-=strlen($chunk);hash_update($crc,$chunk);hash_update($fingerprint,$chunk);}
            if(hash_final($crc)!==sprintf('%08x',$e['crc']))return null;
        }
        return hash_final($fingerprint);
    }finally{fclose($h);}
}
function otc_classify(string $path,int $uid,int $now,int $approvedBefore=0):array {
    $s=otc_identity($path);
    if(!$s||$s['uid']!==$uid||$s['nlink']!==1||!preg_match('/^tt-backup-[A-Za-z0-9]{6}$/D',basename($path)))return ['reason'=>'unknown'];
    $base=['identity'=>$s,'bytes'=>$s['size']];
    $recent=$now-max($s['mtime'],$s['ctime'])<86400;
    $legacyCutoff=strtotime('2026-10-20T10:17:16Z');
    $legacyPossible=$s['mtime']<$legacyCutoff&&$s['ctime']<$legacyCutoff&&$now-max($s['mtime'],$s['ctime'])>=1800;
    $ownerPossible=$approvedBefore>0&&$s['mtime']<$approvedBefore&&$s['ctime']<$approvedBefore&&$now-max($s['mtime'],$s['ctime'])>=1800;
    if($recent&&!$legacyPossible&&!$ownerPossible)return $base+['reason'=>'recent'];
    $reader=null;
    try{
        $reader=new TT_SimpleZipReader($path,0xffffffff);
        $raw=$reader->get('backup-manifest.json');
        if($raw===false||strlen($raw)>16777216)throw new RuntimeException('No bounded manifest');
        $m=json_decode($raw,true,512,JSON_THROW_ON_ERROR);$created=strtotime((string)($m['createdAt']??''));
        if(($m['application']??'')!=='Transtrade'||($m['type']??'')!=='full_recovery'||!in_array((int)($m['schema']??0),[1,2,3],true)||empty($m['encryptedEntries'])||empty($m['recoveryFiles'])||!$created||$created>$now-1800)throw new RuntimeException('Unrecognised archive');
        if($recent&&!otc_legacy_grace($s,$now,(int)$m['schema'],$created)&&!otc_owner_grace($s,$now,(int)$m['schema'],$created,$approvedBefore))return $base+['reason'=>'recent'];
        return $base+['reason'=>'eligible','legacyRecent'=>$recent,'created'=>$created,'date'=>gmdate('Y-m-d',$created),'schema'=>(int)$m['schema'],'manifestSha256'=>hash('sha256',$raw)];
    }catch(Throwable){
        // Only the fixed owner-approved cohort may use structural proof when a
        // failed export never reached its final manifest/central directory.
        if($reader){$reader->close();$reader=null;}
        if($ownerPossible&&($prefix=otc_partial_export($path))!==null){
            $created=max($s['mtime'],$s['ctime']);
            return $base+['reason'=>'eligible','legacyRecent'=>$recent,'created'=>$created,'date'=>gmdate('Y-m-d',$created),'archiveState'=>'interrupted_encrypted_export','prefixSha256'=>$prefix];
        }
        return $base+['reason'=>'unrecognised'];
    }
    finally{if($reader)$reader->close();}
}
function otc_process_visibility(int $uid):bool {
    if(!is_dir('/proc/'.getmypid().'/fd'))return false;
    foreach(glob('/proc/[0-9]*',GLOB_ONLYDIR)?:[]as$process){
        if(@fileowner($process)!==$uid)continue;
        if(!is_readable($process.'/fd')||!is_executable($process.'/fd'))return false;
        foreach(glob($process.'/fd/*')?:[]as$fd)if(@readlink($fd)===false&&file_exists($fd))return false;
    }
    return true;
}
function otc_remove(string $path,array $planned,int $uid,int $now,callable $survivor,int $approvedBefore=0):bool {
    if(($planned['reason']??'')!=='eligible'||!$survivor())return false;
    if(!empty($planned['legacyRecent'])&&!otc_process_visibility($uid))return false;
    $handle=@fopen($path,'rb');if(!$handle)return false;
    try{
        if(!flock($handle,LOCK_EX|LOCK_NB))return false;
        $fresh=otc_classify($path,$uid,$now,$approvedBefore);
        if($fresh!==$planned||otc_identity($path)!==$planned['identity'])return false;
        $fd=fstat($handle);if(!$fd||$fd['ino']!==$planned['identity']['ino']||$fd['dev']!==$planned['identity']['dev'])return false;
        // Never remove a file still open by another observable process.
        foreach(glob('/proc/[0-9]*/fd/*')?:[]as$fdPath){
            if(str_starts_with($fdPath,'/proc/'.getmypid().'/'))continue;
            $target=@readlink($fdPath);if($target===$path)return false;
        }
        if(!$survivor()||otc_identity($path)!==$planned['identity'])return false;
        return unlink($path);
    }finally{flock($handle,LOCK_UN);fclose($handle);}
}
function otc_main():void {
    $root=getenv('TT_OFFICE_TEMP_ROOT')?:'';
    if(!preg_match('~^/home/[A-Za-z0-9_-]+/domains/app\.transtradeinternational\.com/public_html$~D',$root))throw new RuntimeException('Unexpected cleanup target');
    $private=dirname($root).'/transtrade_private';
    foreach([$root,$private,$private.'/backups']as$p)if(!is_dir($p)||is_link($p)||realpath($p)!==$p)throw new RuntimeException('Unsafe directory');
    $library=$root.'/backup_lib.php';
    if(is_link($library)||!hash_equals('__LIBRARY_SHA256__',hash_file('sha256',$library)))throw new RuntimeException('Installed backup library changed');
    define('TT_DATA_DIR',$private);define('TT_STORE_FILE',$private.'/auth.json');require $library;
    if(!function_exists('posix_geteuid'))throw new RuntimeException('Ownership unavailable');
    $uid=posix_geteuid();$now=time();$plan=[];$summary=[];$dates=[];
    $candidates=glob(sys_get_temp_dir().'/tt-backup-*')?:[];
    $identities=array_map('otc_identity',$candidates);
    if(!otc_approved_cohort($identities,$uid))throw new RuntimeException('The approved temporary archive cohort changed; no files removed.');
    $approvedBefore=strtotime('2026-10-20T17:45:20Z');
    foreach($candidates as$p){
        $c=otc_classify($p,$uid,$now,$approvedBefore);$plan[$p]=$c;$r=$c['reason'];
        $summary[$r]['files']=($summary[$r]['files']??0)+1;$summary[$r]['bytes']=($summary[$r]['bytes']??0)+($c['bytes']??0);
        if($r==='eligible'){$d=$c['date'];$dates[$d]['files']=($dates[$d]['files']??0)+1;$dates[$d]['bytes']=($dates[$d]['bytes']??0)+$c['bytes'];}
    }
    ksort($dates);echo 'TEMP_INVENTORY '.json_encode(['groups'=>$summary,'eligibleDates'=>$dates],JSON_THROW_ON_ERROR)."\n";flush();
    $name=tt_create_server_snapshot('manual');$m=tt_backup_verified_server_snapshot($name);$survivor=tt_backup_dir().'/'.$name;
    tt_backup_note_verified($survivor);$survivorIdentity=otc_identity($survivor);$survivorSha=hash_file('sha256',$survivor);$survivorCreated=strtotime($m['createdAt']);
    echo 'PERSISTENT_RECOVERY_VERIFIED '.json_encode(['bytes'=>filesize($survivor),'recoveryFiles'=>$m['recoveryFileCount'],'sha256'=>$survivorSha,'restoreExecuted'=>false],JSON_THROW_ON_ERROR)."\n";flush();
    $lock=fopen(tt_backup_job_lock_file(),'c+');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Backup service busy');
    $removed=0;$bytes=0;$held=0;
    try{
        if(!tt_backup_has_verified_receipt($survivor))throw new RuntimeException('Survivor changed');
        foreach($plan as$p=>$c){
            if(($c['reason']??'')!=='eligible'||$c['created']>=$survivorCreated)continue;
            if($removed%20===0&&!tt_backup_has_verified_receipt($survivor))throw new RuntimeException('Survivor changed');
            if(otc_remove($p,$c,$uid,$now,static fn():bool=>otc_identity($survivor)===$survivorIdentity,$approvedBefore)){$removed++;$bytes+=$c['bytes'];}else$held++;
        }
        if(!tt_backup_has_verified_receipt($survivor))throw new RuntimeException('Survivor changed');
    }finally{flock($lock,LOCK_UN);fclose($lock);}
    $remaining=['files'=>0,'bytes'=>0];foreach(glob(sys_get_temp_dir().'/tt-backup-*')?:[]as$p){$s=otc_identity($p);if($s&&$s['uid']===$uid){$remaining['files']++;$remaining['bytes']+=$s['size'];}}
    echo 'TEMP_CLEANUP_COMPLETE '.json_encode(['removedFiles'=>$removed,'freedBytes'=>$bytes,'changedOrBusyHeld'=>$held,'remaining'=>$remaining,'liveBusinessFilesDeleted'=>0,'documentsDeleted'=>0,'restores'=>0],JSON_THROW_ON_ERROR)."\n";
}
function tt_ensure_data_dir():void {if(!is_dir(TT_DATA_DIR)||is_link(TT_DATA_DIR))throw new RuntimeException('Private directory unavailable');}
function tt_decode_store(string $raw):array {$a=json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($a))throw new RuntimeException('Invalid store');return $a;}
function tt_open_store_lock(int $mode){$p=TT_DATA_DIR.'/auth.lock';if(!is_file($p)||is_link($p))throw new RuntimeException('Store lock unavailable');$h=fopen($p,'rb');if(!$h||!flock($h,$mode))throw new RuntimeException('Cannot lock store');return $h;}
