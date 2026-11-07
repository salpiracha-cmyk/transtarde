<?php
declare(strict_types=1);
// Two bounded private caches, independent of PC transfers and retained snapshots.
function tt_owner_job_dir(string $kind):string {
    if(!in_array($kind,['business','full'],true))throw new InvalidArgumentException('Invalid backup type.');
    tt_backup_ensure_dirs();$parent=realpath(tt_backup_dir());if(!$parent||is_link(tt_backup_dir()))throw new RuntimeException('Unsafe backup directory.');
    $dir=$parent.'/.owner-transfer-'.$kind;
    if(is_link($dir)||(!is_dir($dir)&&!mkdir($dir,0700))||realpath($dir)!==$dir)throw new RuntimeException('Unsafe owner transfer directory.');
    chmod($dir,0700);return $dir;
}
function tt_owner_job_read(string $kind):array {
    $p=tt_owner_job_dir($kind).'/state.json';if(is_link($p))throw new RuntimeException('Unsafe transfer state.');
    $data=is_file($p)?json_decode((string)file_get_contents($p),true,512,JSON_THROW_ON_ERROR):[];return is_array($data)?$data:[];
}
function tt_owner_job_write(string $kind,array $state):void {
    $dir=tt_owner_job_dir($kind);$temp=tempnam($dir,'.state-');if(!$temp)throw new RuntimeException('Cannot stage transfer state.');
    try{chmod($temp,0600);$bytes=json_encode($state,JSON_THROW_ON_ERROR);if(file_put_contents($temp,$bytes)!==strlen($bytes)||!rename($temp,$dir.'/state.json'))throw new RuntimeException('Cannot commit transfer state.');}finally{if(is_file($temp))unlink($temp);}
}
function tt_owner_job_lock(string $kind,int $mode) {
    $path=tt_owner_job_dir($kind).'/job.lock';if(is_link($path))throw new RuntimeException('Unsafe transfer lock.');
    $h=fopen($path,'c+');if(!$h)throw new RuntimeException('Cannot open transfer lock.');chmod($path,0600);
    if(!flock($h,$mode|LOCK_NB)){fclose($h);return null;}return $h;
}
function tt_owner_job_identity(string $path):array {
    clearstatcache(true,$path);if(is_link($path)||!is_file($path))throw new RuntimeException('Prepared backup unavailable.');
    $s=stat($path);if(!$s)throw new RuntimeException('Prepared backup unavailable.');return array_intersect_key($s,array_flip(['dev','ino','size','mtime','ctime']));
}
function tt_owner_job_matches(array $state,string $id,string $client):bool {
    return preg_match('/^[a-f0-9]{32}$/D',$id)===1&&hash_equals((string)($state['id']??''),$id)&&hash_equals((string)($state['client']??''),$client);
}
function tt_owner_job_public(array $state):array {
    $out=['ok'=>true,'job'=>$state['id'],'state'=>$state['state'],'stage'=>$state['stage']??$state['state']];
    if($state['state']==='ready')$out+=['length'=>$state['length'],'sha256'=>$state['sha256']];
    if($state['state']==='failed')$out['error']='Backup preparation failed. Previous completed backups were preserved. Reference: '.($state['errorCode']??'PREPARATION');
    if($state['state']==='preparing'&&time()-(int)($state['createdAt']??0)>1200)$out=['ok'=>true,'job'=>$state['id'],'state'=>'failed','error'=>'Backup preparation timed out. Previous backups were preserved. Try again.'];
    return $out;
}
function tt_owner_job_generate(string $kind,array $state,string $password,?callable $build=null,?callable $verify=null):array {
    $path='';$stage=tt_owner_job_dir($kind).'/incoming.tmp';$reserve=str_repeat('x',262144);$finished=false;
    register_shutdown_function(static function()use($kind,$state,&$reserve,&$finished){$e=error_get_last();if(!$finished&&$e&&in_array($e['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true)){
        $reserve='';$state['state']=$state['stage']='failed';$state['errorCode']='FATAL:'.basename($e['file']).':'.$e['line'];tt_owner_job_write($kind,$state);
    }});
    try{
        if(is_link($stage))throw new RuntimeException('Unsafe transfer stage.');
        $built=$build?$build($password):tt_build_download_backup($kind==='full',$password);$path=$built['path'];
        $state['stage']='verifying';tt_owner_job_write($kind,$state);
        if($verify)$verify($path,$password);elseif($kind==='full')tt_backup_verify_full($path,$password);
        // The Business builder already decrypts and hashes every native AES entry.
        $sha=hash_file('sha256',$path);$size=filesize($path);if(!$sha||!$size)throw new RuntimeException('Empty prepared backup.');
        $target=tt_owner_job_dir($kind).'/transfer.zip';if(is_link($target))throw new RuntimeException('Unsafe transfer path.');
        tt_backup_require_space(tt_owner_job_dir($kind),$size*2);
        if(!copy($path,$stage))throw new RuntimeException('Cannot stage verified backup.');chmod($stage,0600);
        if(!hash_equals($sha,hash_file('sha256',$stage))||!rename($stage,$target))throw new RuntimeException('Cannot commit verified backup.');
        unlink($path);$path='';chmod($target,0600);
        $state+=['filename'=>($kind==='full'?'TRANSTRADE_FULL_BACKUP_':'TRANSTRADE_BUSINESS_DATA_').gmdate('Y-m-d_His').'.zip'];
        $state['state']=$state['stage']='ready';$state['readyAt']=time();$state['length']=$size;$state['sha256']=$sha;$state['identity']=tt_owner_job_identity($target);
        tt_owner_job_write($kind,$state);return $state;
    }catch(Throwable $e){$state['state']=$state['stage']='failed';$state['errorCode']=get_class($e).':'.basename($e->getFile()).':'.$e->getLine();tt_owner_job_write($kind,$state);return $state;}
    finally{$finished=true;$reserve='';if($path!==''&&is_file($path))unlink($path);if(is_file($stage)&&!is_link($stage))unlink($stage);}
}
