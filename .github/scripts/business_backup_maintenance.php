<?php
// Owner authorised bounded business-backup maintenance. No application includes.
declare(strict_types=1);
$target=getenv('TT_BACKUP_MAINTENANCE_ROOT')?:'';
if(!preg_match('~^/home/[A-Za-z0-9_-]+/domains/app\.transtradeinternational\.com/public_html$~D',$target))throw new RuntimeException('Unexpected target');
$private=dirname($target).'/transtrade_private';$deploy=dirname($target).'/.transtrade-deploy';
foreach([$target,$private,$deploy,$private.'/backups']as$p)if(!is_dir($p)||is_link($p)||realpath($p)!==$p)throw new RuntimeException('Unsafe directory');
$library=base64_decode('__BACKUP_LIBRARY_BASE64__',true);$expected='__OLD_LIBRARY_SHA256__';$newHash='__NEW_LIBRARY_SHA256__';
if(!is_string($library)||!hash_equals($newHash,hash('sha256',$library)))throw new RuntimeException('Package mismatch');
$live=$target.'/backup_lib.php';if(!is_file($live)||is_link($live)||!in_array(hash_file('sha256',$live),[$expected,$newHash],true))throw new RuntimeException('Live library changed; refusing overwrite');
$work=$deploy.'/business-backup-maintenance';if(is_link($work)||(!is_dir($work)&&!mkdir($work,0700)))throw new RuntimeException('Unsafe maintenance directory');
if(realpath($work)!==$work)throw new RuntimeException('Unsafe maintenance path');
$stage=$work.'/backup_lib-'.$newHash.'.php';if(is_link($stage))throw new RuntimeException('Unsafe stage');
if(file_put_contents($stage,$library,LOCK_EX)!==strlen($library))throw new RuntimeException('Cannot stage library');chmod($stage,0600);
define('TT_DATA_DIR',$private);define('TT_STORE_FILE',$private.'/auth.json');
function tt_ensure_data_dir():void{if(!is_dir(TT_DATA_DIR)||is_link(TT_DATA_DIR))throw new RuntimeException('Private directory unavailable');}
function tt_decode_store(string $raw):array{$a=json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($a))throw new RuntimeException('Invalid authentication store');return $a;}
function tt_open_store_lock(int $mode){$path=TT_DATA_DIR.'/auth.lock';if(!is_file($path)||is_link($path))throw new RuntimeException('Authentication lock unavailable');$h=fopen($path,'rb');if(!$h||!flock($h,$mode))throw new RuntimeException('Authentication lock unavailable');return $h;}
require $stage;
function maintenance_usage():array{$out=['files'=>0,'bytes'=>0];foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(tt_backup_dir(),FilesystemIterator::SKIP_DOTS))as$f)if($f->isFile()&&!$f->isLink()){$out['files']++;$out['bytes']+=$f->getSize();}return $out;}
$before=maintenance_usage();$tempsBefore=[];foreach(glob(tt_backup_dir().'/.snapshot-*.tmp')?:[]as$p)if(is_file($p)&&!is_link($p))$tempsBefore[$p]=filesize($p);
// Install only the tested library. Preserve its exact previous code first.
$rollback=$work.'/previous-'.$expected.'.php';if(!file_exists($rollback)&&hash_file('sha256',$live)===$expected){if(!copy($live,$rollback))throw new RuntimeException('Cannot preserve previous library');chmod($rollback,0600);}
$tmp=$target.'/.backup-lib-policy-'.bin2hex(random_bytes(6)).'.tmp';
try{if(file_put_contents($tmp,$library,LOCK_EX)!==strlen($library)||!hash_equals($newHash,hash_file('sha256',$tmp)))throw new RuntimeException('Cannot prepare library');chmod($tmp,0644);if(!rename($tmp,$live))throw new RuntimeException('Cannot install library');}finally{if(is_file($tmp))unlink($tmp);}
echo 'BACKUP_LIBRARY_INSTALLED sha256='.$newHash."\n";
// Snapshot creation takes the normal shared auth/operations locks, verifies
// every recovery payload before committing, then applies bounded retention.
$name=tt_create_server_snapshot('manual');$manifest=tt_backup_verified_server_snapshot($name);
echo 'FRESH_COMPLETE_BACKUP_VERIFIED bytes='.filesize(tt_backup_dir().'/'.$name).' files='.(int)$manifest['recoveryFileCount'].' sha256='.hash_file('sha256',tt_backup_dir().'/'.$name)."\n";
$lock=fopen(tt_backup_job_lock_file(),'c+');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Backup service busy');
$tempsRemoved=0;$tempBytes=0;
try{
 foreach(glob(tt_backup_dir().'/.snapshot-*.tmp')?:[]as$p){
  if(is_link($p)||!is_file($p)||!preg_match('/^\.snapshot-[a-f0-9]{16}\.tmp$/D',basename($p)))continue;
  $st=stat($p);if(!$st||time()-max($st['mtime'],$st['ctime'])<86400)continue;
  $bytes=$st['size'];if(!unlink($p))throw new RuntimeException('Unable to remove stale snapshot temporary file');$tempsRemoved++;$tempBytes+=$bytes;
 }
}finally{flock($lock,LOCK_UN);fclose($lock);}
$tempsRemoved=0;$tempBytes=0;foreach($tempsBefore as$p=>$bytes)if(!file_exists($p)){$tempsRemoved++;$tempBytes+=$bytes;}
$after=maintenance_usage();
echo 'BUSINESS_BACKUP_MAINTENANCE '.json_encode(['before'=>$before,'after'=>$after,'netBytesFreed'=>$before['bytes']-$after['bytes'],'staleTempsRemoved'=>$tempsRemoved,'staleTempBytesRemoved'=>$tempBytes,'currentBusinessFilesDeleted'=>0,'oldRecoveryFilesDeleted'=>0,'uploadedDocumentsDeleted'=>0],JSON_THROW_ON_ERROR)."\n";
