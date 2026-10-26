<?php
declare(strict_types=1);
// One bounded, private, encrypted transfer cache; never a business-data source.
function tt_office_job_dir():string {
    tt_backup_ensure_dirs();$parent=realpath(tt_backup_dir());if($parent===false||is_link(tt_backup_dir()))throw new RuntimeException('Unsafe backup directory');$dir=$parent.'/.office-transfer';
    if(is_link($dir)||(!is_dir($dir)&&!mkdir($dir,0700))||realpath($dir)!==$dir)throw new RuntimeException('Unsafe office transfer directory');
    chmod($dir,0700);return $dir;
}
function tt_office_job_read():array {
    $p=tt_office_job_dir().'/state.json';if(is_link($p))throw new RuntimeException('Unsafe transfer state');
    $raw=is_file($p)?file_get_contents($p):'{}';$m=json_decode($raw?:'{}',true,512,JSON_THROW_ON_ERROR);
    return is_array($m)?$m:[];
}
function tt_office_job_write(array $m):void {
    $dir=tt_office_job_dir();$p=tempnam($dir,'.state-');if($p===false)throw new RuntimeException('Cannot stage transfer state');
    try{chmod($p,0600);$raw=json_encode($m,JSON_THROW_ON_ERROR);if(file_put_contents($p,$raw)!==strlen($raw)||!rename($p,$dir.'/state.json'))throw new RuntimeException('Cannot commit transfer state');}finally{if(is_file($p))unlink($p);}
}
function tt_office_job_lock(int $mode) {
    $p=tt_office_job_dir().'/job.lock';if(is_link($p))throw new RuntimeException('Unsafe transfer lock');
    $h=fopen($p,'c+');if(!$h)throw new RuntimeException('Cannot open transfer lock');chmod($p,0600);
    if(!flock($h,$mode|LOCK_NB)){fclose($h);return null;}return $h;
}
function tt_office_job_identity(string $p):array {
    clearstatcache(true,$p);if(is_link($p)||!is_file($p))throw new RuntimeException('Transfer unavailable');$s=stat($p);
    if(!$s)throw new RuntimeException('Transfer unavailable');return array_intersect_key($s,array_flip(['dev','ino','size','mtime','ctime']));
}
function tt_office_job_matches(array $m,string $id,string $client):bool {
    return preg_match('/^[a-f0-9]{32}$/D',$id)===1&&hash_equals((string)($m['id']??''),$id)&&hash_equals((string)($m['client']??''),$client);
}
function tt_office_job_public(array $m):array {
    $out=['ok'=>true,'protocol'=>2,'job'=>$m['id'],'state'=>$m['state'],'stage'=>$m['stage']??$m['state']];
    if(($m['stage']??'')==='verifying')$out+=['verifiedFiles'=>(int)($m['verifiedFiles']??0),'totalFiles'=>(int)($m['totalFiles']??0)];
    if($m['state']==='ready')$out+=['length'=>$m['length'],'sha256'=>$m['sha256'],'chunkBytes'=>4194304];
    if($m['state']==='failed')$out['error']='Server backup preparation failed; previous completed backups were preserved. Reference: '.($m['errorCode']??'PREPARATION');
    return $out;
}
function tt_office_job_failure(array $m,Throwable $error):array {
    $m['state']=$m['stage']='failed';$m['errorCode']=get_class($error).':'.basename($error->getFile()).':'.$error->getLine();
    tt_office_job_write($m);return $m;
}
function tt_office_job_shutdown_guard(array $m,bool &$finished,string &$reserve):void {
    register_shutdown_function(static function()use($m,&$finished,&$reserve){
        $e=error_get_last();if($finished||!$e||!in_array($e['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true))return;
        $reserve='';$current=tt_office_job_read();
        if(($current['id']??'')!==$m['id']||($current['state']??'')!=='preparing')return;
        $current['state']=$current['stage']='failed';$current['errorCode']='FATAL:'.basename($e['file']).':'.$e['line'];tt_office_job_write($current);
    });
}
function tt_office_job_generate(array $m,string $password,?callable $build=null):array {
    $path='';$stage=tt_office_job_dir().'/incoming.tmp';$finished=false;$reserve=str_repeat('x',262144);
    tt_office_job_shutdown_guard($m,$finished,$reserve);
    try{
        if(is_link($stage))throw new RuntimeException('Unsafe transfer stage');
        $built=$build?$build($password):tt_build_download_backup(true,$password);$path=$built['path'];
        $candidate=tt_office_job_dir().'/candidate.zip';if(is_link($candidate))throw new RuntimeException('Unsafe candidate path');
        $size=filesize($path);if(!$size)throw new RuntimeException('Invalid prepared archive');
        tt_backup_require_space(tt_office_job_dir(),$size*2);
        if(!copy($path,$stage))throw new RuntimeException('Cannot stage transfer');chmod($stage,0600);
        if(!hash_equals(hash_file('sha256',$path),hash_file('sha256',$stage))||!rename($stage,$candidate))throw new RuntimeException('Cannot stage complete transfer');
        unlink($path);$path='';
        // The next authenticated status requests verify bounded batches. No
        // unverified candidate is exposed as a downloadable transfer.
        $m['stage']='verifying';$m['verificationStartedAt']=time();$m['candidateIdentity']=tt_office_job_identity($candidate);$m['verifiedFiles']=0;
        tt_office_job_write($m);return $m;
    }catch(Throwable $e){return tt_office_job_failure($m,$e);}
    finally{$finished=true;$reserve='';if($path!==''&&is_file($path))unlink($path);if(is_file($stage)&&!is_link($stage))unlink($stage);}
}
function tt_office_job_verify_step(array $m,string $password,int $maxEntries=200):array {
    $finished=false;$reserve=str_repeat('x',262144);tt_office_job_shutdown_guard($m,$finished,$reserve);
    $candidate=tt_office_job_dir().'/candidate.zip';$zip=null;
    try{
        if(($m['state']??'')!=='preparing'||($m['stage']??'')!=='verifying')throw new RuntimeException('Unexpected verification state');
        if(tt_office_job_identity($candidate)!==($m['candidateIdentity']??[]))throw new RuntimeException('Candidate changed');
        [$zip,$manifest,$key]=tt_backup_open_verified($candidate,$password);
        $map=array_values((array)($manifest['encryptedEntries']??[]));$count=count($map);$index=(int)($m['verifiedFiles']??0);$start=microtime(true);$bytes=0;$processed=0;
        if($index<0||$index>$count)throw new RuntimeException('Invalid verification cursor');
        while($index<$count){
            tt_backup_decrypt_entry($zip,(array)$map[$index],$key,null);$bytes+=(int)($map[$index]['size']??0);$index++;$processed++;
            if($processed>=max(1,$maxEntries)||$bytes>=67108864||microtime(true)-$start>=2)break;
        }
        $zip->close();$zip=null;
        if(tt_office_job_identity($candidate)!==$m['candidateIdentity'])throw new RuntimeException('Candidate changed');
        $m['verifiedFiles']=$index;$m['totalFiles']=$count;$m['progressAt']=time();
        if($index===$count){
            $hash=hash_file('sha256',$candidate);$size=filesize($candidate);$target=tt_office_job_dir().'/transfer.zip';
            if(!$hash||!$size||is_link($target)||tt_office_job_identity($candidate)!==$m['candidateIdentity'])throw new RuntimeException('Invalid verified candidate');
            // Atomic promotion on the same filesystem preserves the previous
            // verified transfer on every build, authentication or integrity failure.
            if(!rename($candidate,$target))throw new RuntimeException('Cannot commit verified transfer');chmod($target,0600);
            $m['state']=$m['stage']='ready';$m['readyAt']=time();$m['length']=$size;$m['sha256']=$hash;$m['identity']=tt_office_job_identity($target);unset($m['candidateIdentity']);
        }
        tt_office_job_write($m);return $m;
    }catch(Throwable $e){return tt_office_job_failure($m,$e);}
    finally{if($zip instanceof TT_SimpleZipReader)$zip->close();$finished=true;$reserve='';}
}
function tt_office_job_chunk(array $m,int $offset,int $length):string {
    if($m['state']!=='ready'||$offset<0||$length<1||$length>4194304||$offset>=$m['length']||$length>$m['length']-$offset)throw new InvalidArgumentException('Invalid backup chunk');
    $p=tt_office_job_dir().'/transfer.zip';if(tt_office_job_identity($p)!==$m['identity'])throw new RuntimeException('Transfer changed');
    $h=fopen($p,'rb');if(!$h)throw new RuntimeException('Transfer unavailable');
    try{if(fseek($h,$offset)!==0)throw new RuntimeException('Invalid chunk offset');$data='';while(strlen($data)<$length){$b=fread($h,$length-strlen($data));if($b===false||$b==='')throw new RuntimeException('Transfer incomplete');$data.=$b;}}finally{fclose($h);}
    if(tt_office_job_identity($p)!==$m['identity'])throw new RuntimeException('Transfer changed');return $data;
}
