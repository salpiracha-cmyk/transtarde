<?php
declare(strict_types=1);
// Owner-only, one bounded upload. No password or derived key is persisted.
const TT_RESTORE_CHUNK = 1048576;
const TT_RESTORE_MAX_EXPANDED = 17179869184; // 16 GiB, streamed files; individual decoder limits still apply.
function ttr_root(): string {
    tt_backup_ensure_dirs();$p=tt_backup_dir().'/.website-restore';
    if(is_link($p)||(!is_dir($p)&&!mkdir($p,0700)))throw new RuntimeException('Recovery workspace unavailable.');
    return $p;
}
function ttr_write(string $p,array $v): void {
    $raw=json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);$tmp=$p.'.restore-journal';
    if(is_link($tmp)||is_link($p))throw new RuntimeException('Unsafe recovery journal.');
    $h=fopen($tmp,'w+b');if(!$h)throw new RuntimeException('Recovery journal unavailable.');
    try{chmod($tmp,0600);if(fwrite($h,$raw)!==strlen($raw)||!fflush($h)||(function_exists('fsync')&&!fsync($h)))throw new RuntimeException('Recovery journal could not be saved.');}finally{fclose($h);}
    if(!rename($tmp,$p))throw new RuntimeException('Recovery journal could not be committed.');
}
function ttr_read(): array { $p=ttr_root().'/state.json';return is_file($p)?json_decode((string)file_get_contents($p),true,512,JSON_THROW_ON_ERROR):[]; }
function ttr_save(array $s): array {ttr_write(ttr_root().'/state.json',$s);return $s;}
function ttr_lock(string $path) {
    if(is_link($path))throw new RuntimeException('Unsafe recovery lock.');
    $h=fopen($path,'c+');if(!$h||!flock($h,LOCK_EX|LOCK_NB)){if(is_resource($h))fclose($h);throw new RuntimeException('Another request is finishing. Wait a moment and continue.');}
    @chmod($path,0600);return $h;
}
function ttr_public(array $s): array {
    return array_intersect_key($s,array_flip(['id','state','name','size','received','verified','total','createdAt','backupRevision','installedRevision','scope','restoredFiles','safetySnapshot','progress','totalWork','error']))+['ok'=>true,'chunkBytes'=>TT_RESTORE_CHUNK];
}
function ttr_owner(array $s,array $actor,string $id): void {
    if(!$s||!hash_equals((string)$s['id'],$id)||(string)$s['owner']!==(string)$actor['id'])throw new InvalidArgumentException('This recovery belongs to another session owner or is no longer available.');
}
function ttr_safe_path(string $base,string $rel): string {
    if($rel===''||str_contains($rel,'..')||str_contains($rel,'\\')||str_contains($rel,':')||str_contains($rel,"\0")||str_starts_with($rel,'/')||str_contains($rel,'//'))throw new InvalidArgumentException('Unsafe recovery path.');
    $p=$base;foreach(explode('/',$rel)as$part){$p.='/'.$part;if(is_link($p))throw new RuntimeException('Recovery cannot follow linked files.');}return $p;
}
function ttr_business(string $rel): bool {
    if(!tt_backup_restore_allowed($rel))return false;
    return !in_array($rel,['admin-recovery.hash','office-backup-token.sha256','office-backup-policy.json','gemini.key','auth-rate.json','offline_transactions.json'],true)&&!str_starts_with($rel,'office_agent_jobs/');
}
function ttr_digest(string $p): array {
    clearstatcache(true,$p);if(is_link($p)||!is_file($p))throw new RuntimeException('Recovery file unavailable.');
    $hash=hash_file('sha256',$p);if($hash===false)throw new RuntimeException('Recovery file could not be verified.');return ['size'=>filesize($p),'sha256'=>$hash];
}
function ttr_copy(string $from,string $to,array $expected): void {
    if(ttr_digest($from)!==$expected)throw new RuntimeException('Recovery file changed.');
    if(!is_dir(dirname($to))&&!mkdir(dirname($to),0700,true))throw new RuntimeException('Recovery directory unavailable.');
    $tmp=$to.'.restore-copy';if(is_link($tmp)||is_link($to))throw new RuntimeException('Unsafe recovery destination.');
    if(!copy($from,$tmp))throw new RuntimeException('Recovery copy failed.');chmod($tmp,0600);
    if(ttr_digest($tmp)!==$expected||!rename($tmp,$to))throw new RuntimeException('Recovery copy verification failed.');
}
function ttr_delete_work(string $p): void {
    if(is_link($p))throw new RuntimeException('Unsafe recovery workspace.');
    if(is_dir($p)){foreach(scandir($p)as$n)if($n!=='.'&&$n!=='..')ttr_delete_work($p.'/'.$n);if(!rmdir($p))throw new RuntimeException('Cannot clear recovery workspace.');}
    elseif(is_file($p)&&!unlink($p))throw new RuntimeException('Cannot clear recovery workspace.');
}
function ttr_start(array $b,array $actor): array {
    $size=$b['size']??0;$hashes=$b['chunks']??[];
    if(!is_int($size)||$size<1||$size>TT_BACKUP_MAX_ARCHIVE_BYTES||!is_array($hashes)||!array_is_list($hashes)||count($hashes)!==(int)ceil($size/TT_RESTORE_CHUNK))throw new InvalidArgumentException('Select a Transtrade ZIP smaller than 4 GB.');
    foreach($hashes as$hash)if(!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash))throw new InvalidArgumentException('Invalid file fingerprint.');
    $fingerprint=hash('sha256',$size.':'.implode('',$hashes));$s=ttr_read();
    if($s){if((string)$s['owner']===(string)$actor['id']&&hash_equals($s['fingerprint'],$fingerprint))return $s;throw new InvalidArgumentException('A different recovery is saved. Review it and use Clear prepared upload before selecting another file.');}
    tt_backup_require_space(ttr_root(),$size*2);
    $p=ttr_root().'/upload.zip';if(is_file($p))throw new RuntimeException('An unrecognised upload is held for review.');
    $h=fopen($p,'x+b');if(!$h)throw new RuntimeException('Upload could not be created.');fclose($h);chmod($p,0600);
    return ttr_save(['id'=>bin2hex(random_bytes(16)),'owner'=>(string)$actor['id'],'name'=>substr(basename((string)($b['name']??'Backup.zip')),0,200),'size'=>$size,'fingerprint'=>$fingerprint,'chunks'=>$hashes,'state'=>'uploading','received'=>0,'verified'=>0,'staged'=>[]]);
}
function ttr_chunk(array $s,int $offset,string $data): array {
    if($s['state']!=='uploading'||$offset<0||$offset%TT_RESTORE_CHUNK!==0||$offset>=$s['size'])throw new InvalidArgumentException('Invalid upload position.');
    $length=min(TT_RESTORE_CHUNK,$s['size']-$offset);$hash=$s['chunks'][intdiv($offset,TT_RESTORE_CHUNK)];
    if(strlen($data)!==$length||!hash_equals($hash,hash('sha256',$data)))throw new InvalidArgumentException('Upload piece did not match the selected file. Select the same ZIP and continue.');
    if($offset<$s['received'])return $s; // A lost acknowledgement can resend the same verified piece.
    if($offset!==$s['received'])throw new InvalidArgumentException('Upload pieces must arrive in order.');
    $h=fopen(ttr_root().'/upload.zip','c+b');if(!$h)throw new RuntimeException('Upload unavailable.');
    try{if(!ftruncate($h,$offset)||fseek($h,$offset)!==0||fwrite($h,$data)!==$length||!fflush($h))throw new RuntimeException('Upload could not be saved.');if(function_exists('fsync'))fsync($h);}finally{fclose($h);}
    $s['received']+=$length;return ttr_save($s);
}
function ttr_db(): ?PDO {
    $get=static fn($k)=>defined('TT_'.$k)?(string)constant('TT_'.$k):(string)(getenv('TT_'.$k)?:'');
    $host=$get('DB_HOST');$name=$get('DB_NAME');$user=$get('DB_USER');if($host===''&&$name===''&&$user==='')return null;
    if($host===''||$name===''||$user==='')throw new InvalidArgumentException('The hosting database configuration is incomplete.');
    $db=new PDO('mysql:host='.$host.';dbname='.$name.';charset=utf8mb4',$user,$get('DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    foreach(['tt_operation_records','tt_operation_history','tt_export_documents']as$table){$q=$db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');$q->execute([$name,$table]);if(strtoupper((string)$q->fetchColumn())!=='INNODB')throw new InvalidArgumentException('All three business database tables must use InnoDB before recovery.');}
    return $db;
}
function ttr_db_rows(PDO $db): array {
    $out=[];$db->beginTransaction();try{foreach(['tt_operation_records','tt_operation_history','tt_export_documents']as$t)$out[$t]=$db->query('SELECT * FROM '.$t)->fetchAll();$db->commit();return $out;}catch(Throwable $e){$db->rollBack();throw $e;}
}
function ttr_db_validate(PDO $db,array $rows): void {
    foreach(['tt_operation_records','tt_operation_history','tt_export_documents']as$t){
        if(!isset($rows[$t])||!is_array($rows[$t])||!array_is_list($rows[$t]))throw new InvalidArgumentException('The backup has no complete database snapshot. Use a backup from the same storage configuration.');
        $cols=array_column($db->query('SHOW COLUMNS FROM '.$t)->fetchAll(),'Field');sort($cols);
        foreach($rows[$t]as$row){if(!is_array($row))throw new InvalidArgumentException('Invalid database row.');$keys=array_keys($row);sort($keys);if($keys!==$cols)throw new InvalidArgumentException('The backup database schema differs from this hosting. Install compatible software first.');}
    }
}
function ttr_db_apply(PDO $db,array $rows): void {
    ttr_db_validate($db,$rows);$db->beginTransaction();
    try{foreach(['tt_operation_records','tt_operation_history','tt_export_documents']as$t){$db->exec('DELETE FROM '.$t);$stmt=null;$columns=[];foreach($rows[$t]as$row){if(!$stmt){$columns=array_keys($row);$stmt=$db->prepare('INSERT INTO '.$t.' (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')');}$stmt->execute(array_map(static fn($c)=>$row[$c],$columns));}if(ttr_db_signature($db->query('SELECT * FROM '.$t)->fetchAll())!==ttr_db_signature($rows[$t]))throw new RuntimeException('Database recovery verification mismatch.');}$db->commit();}
    catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function ttr_db_signature(array $rows): string {
    $items=[];foreach($rows as$row){ksort($row);foreach($row as&$v)if($v!==null)$v=(string)$v;unset($v);$items[]=json_encode($row,JSON_THROW_ON_ERROR);}sort($items,SORT_STRING);return hash('sha256',implode("\n",$items));
}
function ttr_verify(array $s,string $password): array {
    if(!in_array($s['state'],['uploading','verifying','verified'],true))return $s;
    if($s['received']!==$s['size'])throw new InvalidArgumentException('Upload the remaining pieces first.');
    if($s['state']==='verified')return $s;
    $preflight=new TT_SimpleZipReader(ttr_root().'/upload.zip',TT_BACKUP_MAX_ARCHIVE_BYTES);
    try{$header=json_decode((string)$preflight->get('backup-manifest.json',16777216),true,512,JSON_THROW_ON_ERROR);$iterations=$header['encryption']['iterations']??200000;if(!is_int($iterations)||$iterations<1||$iterations>1000000||count($header['encryptedEntries']??[])>100000)throw new InvalidArgumentException('Unsupported recovery manifest limits.');}finally{$preflight->close();}
    [$zip,$m,$key]=tt_backup_open_verified(ttr_root().'/upload.zip',$password);
    try{
        if(!in_array($m['scope']??'full',['full','data'],true))throw new InvalidArgumentException('Select a Data or older Complete backup. Software-only files cannot restore business data.');
        $map=(array)$m['encryptedEntries'];$names=array_keys($map);$budget=0;$start=microtime(true);$totalBytes=0;
        foreach($map as$meta){$n=$meta['size']??-1;if(!is_int($n)||$n<0)throw new InvalidArgumentException('Invalid recovery inventory.');$totalBytes+=$n;}
        if($totalBytes>TT_RESTORE_MAX_EXPANDED)throw new InvalidArgumentException('The expanded backup exceeds the recovery size limit.');
        if($s['state']==='uploading'){tt_backup_require_space(ttr_root(),$totalBytes*2);$s['archiveDigest']=ttr_digest(ttr_root().'/upload.zip');$s['state']='verifying';}
        while($s['verified']<count($names)){
            $logical=$names[$s['verified']];$meta=$map[$logical];$rel=str_starts_with($logical,'System_Recovery/private/')?substr($logical,24):'';
            if($rel!==''&&ttr_business($rel)){
                $p=ttr_safe_path(ttr_root().'/staged',$rel);if(!is_dir(dirname($p)))mkdir(dirname($p),0700,true);$h=fopen($p,'w+b');if(!$h)throw new RuntimeException('Recovery staging failed.');
                try{tt_backup_decrypt_entry($zip,$meta,$key,$h);fflush($h);}finally{fclose($h);}chmod($p,0600);
                if(str_ends_with(strtolower($rel),'.json'))json_decode((string)file_get_contents($p),true,512,JSON_THROW_ON_ERROR);
                $s['staged'][$rel]=ttr_digest($p);
            }else tt_backup_decrypt_entry($zip,$meta,$key,null);
            $s['verified']++;$budget+=(int)$meta['size'];
            if($budget>=33554432||microtime(true)-$start>2)break;
        }
        $s['total']=count($names);
        if($s['verified']===count($names)){
            if(!isset($s['staged']['auth.json']))throw new InvalidArgumentException('The recovery has no master records.');
            if(ttr_digest(ttr_root().'/upload.zip')!==$s['archiveDigest'])throw new RuntimeException('The uploaded ZIP changed during verification.');
            $db=ttr_db();if($db){$p=ttr_root().'/staged/database-business-snapshot.json';if(!is_file($p))throw new InvalidArgumentException('This hosting uses MySQL but this backup has no database snapshot. Use a backup from the same storage configuration.');ttr_db_validate($db,json_decode((string)file_get_contents($p),true,512,JSON_THROW_ON_ERROR));}
            $s['usesDatabase']=$db!==null;$s['state']='verified';$s['createdAt']=$m['createdAt']??null;$s['scope']=$m['scope']??'full';$s['backupRevision']=$m['softwareRevision']??'Legacy backup';$s['installedRevision']=trim((string)@file_get_contents(__DIR__.'/.transtrade-release'))?:'Current installation';
        }
        return ttr_save($s);
    }finally{$zip->close();}
}
function ttr_pause(array $s): void {
    $p=TT_DATA_DIR.'/restore-maintenance.lock';if(is_link($p))throw new RuntimeException('Unsafe recovery marker.');
    if(is_file($p)&&trim((string)file_get_contents($p))!==$s['id'])throw new RuntimeException('Another recovery is active.');
    if(!is_file($p))tt_backup_atomic_write($p,$s['id']);
}
function ttr_unpause(array $s): void {
    $p=TT_DATA_DIR.'/restore-maintenance.lock';if(is_file($p)){if(trim((string)file_get_contents($p))!==$s['id']||!unlink($p))throw new RuntimeException('Recovery remains paused; continue to finish.');}
}
function ttr_confirm(array $s,string $confirmation): array {
    if($s['state']!=='verified')return $s;
    if($confirmation!=='RESTORE')throw new InvalidArgumentException('Type RESTORE and click Confirm Restore.');
    if(ttr_digest(ttr_root().'/upload.zip')!==$s['archiveDigest'])throw new RuntimeException('The verified ZIP changed.');
    if(count(glob(tt_backup_dir().'/restore-safety-*')?:[])>=7)throw new InvalidArgumentException('Seven pre-restore safety copies are retained. Have an administrator review them before another restore; no safety copy was deleted.');
    ttr_pause($s);
    $paths=array_keys($s['staged']);foreach(tt_backup_private_files()as$rel=>$path)if(ttr_business($rel))$paths[]=$rel;
    $paths=array_values(array_unique($paths));sort($paths);$paths=array_values(array_diff($paths,['auth.json']));$paths[]='auth.json';
    $s['paths']=$paths;$s['progress']=0;$s['totalWork']=count($paths);$s['state']='safety';$s['before']=[];$s['safetySnapshot']='restore-safety-'.$s['id'];
    $safety=tt_backup_dir().'/'.$s['safetySnapshot'];if(is_link($safety)||(!is_dir($safety)&&!mkdir($safety,0700)))throw new RuntimeException('Safety copy unavailable.');
    return ttr_save($s);
}
function ttr_step(array $s): array {
    if(in_array($s['state'],['done','rolled_back','failed'],true)){ttr_unpause($s);return $s;}
    if(!in_array($s['state'],['safety','applying','database','finishing','rollback','rollback_database'],true))return $s;
    ttr_pause($s);$start=microtime(true);$bytes=0;$safety=tt_backup_dir().'/'.$s['safetySnapshot'];
    try{
        if(in_array($s['state'],['safety','applying','rollback'],true)){
            while($s['progress']<count($s['paths'])){
                $rel=$s['paths'][$s['progress']];$live=ttr_safe_path(TT_DATA_DIR,$rel);$old=ttr_safe_path($safety.'/private',$rel);$new=ttr_safe_path(ttr_root().'/staged',$rel);
                if($s['state']==='safety'){
                    $meta=is_file($live)?ttr_digest($live):null;if($meta)ttr_copy($live,$old,$meta);$s['before'][$rel]=$meta;$bytes+=$meta['size']??0;
                }else{
                    // Authentication is installed last; preserve current identities,
                    // credentials and roles instead of reviving historical passwords.
                    if($rel==='auth.json'&&$s['state']==='applying'){ $s['progress']++;continue; }
                    $meta=$s['state']==='rollback'?($s['before'][$rel]??null):($s['staged'][$rel]??null);
                    if($meta)ttr_copy($s['state']==='rollback'?$old:$new,$live,$meta);
                    elseif(is_file($live)&&!unlink($live))throw new RuntimeException('Recovery file replacement failed.');
                    $bytes+=$meta['size']??0;
                }
                $s['progress']++;$s=ttr_save($s);
                if($bytes>=33554432||microtime(true)-$start>2)return $s;
            }
            if($s['state']==='safety'){
                $db=ttr_db();if(($db!==null)!==$s['usesDatabase'])throw new RuntimeException('Database configuration changed.');
                if($db){ttr_write($safety.'/database.json',ttr_db_rows($db));$s['databaseBefore']=ttr_digest($safety.'/database.json');}
                ttr_write($safety.'/manifest.json',['application'=>'Transtrade','kind'=>'pre-restore','createdAt'=>gmdate('c'),'files'=>$s['before'],'database'=>$db!==null]);
                // Verify the entire safety inventory before any live mutation.
                foreach($s['before']as$rel=>$meta)if($meta&&ttr_digest(ttr_safe_path($safety.'/private',$rel))!==$meta)throw new RuntimeException('Safety copy verification failed.');
                $s['state']='applying';$s['progress']=0;
            }else{$s['state']=$s['state']==='rollback'?'rollback_database':'database';}
            return ttr_save($s);
        }
        if(in_array($s['state'],['database','rollback_database'],true)){
            $db=ttr_db();if(($db!==null)!==$s['usesDatabase'])throw new RuntimeException('Database configuration changed.');
            if($db){$p=$s['state']==='database'?ttr_root().'/staged/database-business-snapshot.json':$safety.'/database.json';$meta=$s['state']==='database'?$s['staged']['database-business-snapshot.json']:$s['databaseBefore'];if(ttr_digest($p)!==$meta)throw new RuntimeException('Database snapshot changed.');ttr_db_apply($db,json_decode((string)file_get_contents($p),true,512,JSON_THROW_ON_ERROR));}
            if($s['state']==='rollback_database'){$s['state']='rolled_back';$s=ttr_save($s);ttr_unpause($s);return $s;}
            $s['state']='finishing';$s['progress']=0;return ttr_save($s);
        }
        if($s['state']==='finishing'){
            while($s['progress']<count($s['paths'])){
                $rel=$s['paths'][$s['progress']];$meta=$s['staged'][$rel]??null;$p=ttr_safe_path(TT_DATA_DIR,$rel);
                if($rel!=='auth.json'&&($meta?ttr_digest($p)!==$meta:is_file($p)))throw new RuntimeException('Restored file verification failed.');
                $s['progress']++;$bytes+=$meta['size']??0;
                if($bytes>=33554432||microtime(true)-$start>2)return ttr_save($s);
            }
            $current=json_decode((string)file_get_contents(TT_STORE_FILE),true,512,JSON_THROW_ON_ERROR);
            $auth=json_decode((string)file_get_contents(ttr_root().'/staged/auth.json'),true,512,JSON_THROW_ON_ERROR);
            $auth['users']=$current['users'];foreach($auth['users']as&$u)$u['session_version']=max(1,(int)($u['session_version']??1))+1;unset($u);
            $auth['audit']=$current['audit']??[];$auth['audit'][]=['id'=>'RESTORE-'.$s['id'],'at'=>gmdate('c'),'user_id'=>$s['owner'],'action'=>'Confirmed website data restore','backupCreatedAt'=>$s['createdAt']];
            tt_backup_atomic_write(TT_STORE_FILE,json_encode($auth,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            $s['restoredFiles']=count($s['staged']);$s['state']='done';$s=ttr_save($s);ttr_unpause($s);return $s;
        }
    }catch(Throwable $e){
        if($s['state']==='done')throw $e;
        $s['error']='Recovery stopped at '.$s['state'].'. '.(in_array($s['state'],['safety'],true)?'Current business data was not replaced.':'Use Continue recovery to finish reverting to the verified safety copy.');
        if($s['state']==='safety'){$s['state']='failed';$s=ttr_save($s);ttr_unpause($s);}
        elseif(!in_array($s['state'],['rollback','rollback_database'],true)){$s['state']='rollback';$s['progress']=0;$s=ttr_save($s);}
        else throw new RuntimeException('Rollback is paused. Keep the site in recovery mode and continue after resolving storage availability.');
        return $s;
    }
    return $s;
}
