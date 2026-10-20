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
    if($m['state']==='ready')$out+=['length'=>$m['length'],'sha256'=>$m['sha256'],'chunkBytes'=>4194304];
    if($m['state']==='failed')$out['error']='Server backup preparation failed; previous completed backups were preserved.';
    return $out;
}
function tt_office_job_generate(array $m,string $password,?callable $build=null,?callable $verify=null):array {
    $path='';$stage=tt_office_job_dir().'/incoming.tmp';
    try{
        if(is_link($stage))throw new RuntimeException('Unsafe transfer stage');
        $built=$build?$build($password):tt_build_download_backup(true,$password);$path=$built['path'];
        $m['stage']='verifying';tt_office_job_write($m);
        if($verify)$verify($path,$password);else tt_backup_verify_full($path,$password);
        $hash=hash_file('sha256',$path);$size=filesize($path);if(!$hash||!$size)throw new RuntimeException('Invalid prepared archive');
        $target=tt_office_job_dir().'/transfer.zip';if(is_link($target))throw new RuntimeException('Unsafe transfer path');
        // Only a fully verified replacement may displace the previous cache file.
        tt_backup_require_space(tt_office_job_dir(),$size*2);
        if(!copy($path,$stage))throw new RuntimeException('Cannot stage transfer');chmod($stage,0600);
        if(!hash_equals($hash,hash_file('sha256',$stage))||!rename($stage,$target))throw new RuntimeException('Cannot commit verified transfer');
        unlink($path);$path='';chmod($target,0600);
        $m['state']='ready';$m['stage']='ready';$m['readyAt']=time();$m['length']=$size;$m['sha256']=$hash;$m['identity']=tt_office_job_identity($target);tt_office_job_write($m);return $m;
    }catch(Throwable $e){$m['state']='failed';$m['stage']='failed';tt_office_job_write($m);error_log('Transtrade background office backup failed: '.get_class($e));return $m;}
    finally{if($path!==''&&is_file($path))unlink($path);if(is_file($stage)&&!is_link($stage))unlink($stage);}
}
function tt_office_job_chunk(array $m,int $offset,int $length):string {
    if($m['state']!=='ready'||$offset<0||$length<1||$length>4194304||$offset>=$m['length']||$length>$m['length']-$offset)throw new InvalidArgumentException('Invalid backup chunk');
    $p=tt_office_job_dir().'/transfer.zip';if(tt_office_job_identity($p)!==$m['identity'])throw new RuntimeException('Transfer changed');
    $h=fopen($p,'rb');if(!$h)throw new RuntimeException('Transfer unavailable');
    try{if(fseek($h,$offset)!==0)throw new RuntimeException('Invalid chunk offset');$data='';while(strlen($data)<$length){$b=fread($h,$length-strlen($data));if($b===false||$b==='')throw new RuntimeException('Transfer incomplete');$data.=$b;}}finally{fclose($h);}
    if(tt_office_job_identity($p)!==$m['identity'])throw new RuntimeException('Transfer changed');return $data;
}
