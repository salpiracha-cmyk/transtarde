<?php
declare(strict_types=1);
// Owner-authorised policy deployment/daily maintenance; no app bootstrap or restore.
$target=getenv('TT_BACKUP_POLICY_ROOT')?:'';$install=getenv('TT_BACKUP_POLICY_INSTALL')==='1';
if(!preg_match('~^/home/[A-Za-z0-9_-]+/domains/app\.transtradeinternational\.com/public_html$~D',$target))throw new RuntimeException('Unexpected target');
$private=dirname($target).'/transtrade_private';$deploy=dirname($target).'/.transtrade-deploy';
foreach([$target,$private,$deploy,$private.'/backups']as$p)if(!is_dir($p)||is_link($p)||realpath($p)!==$p)throw new RuntimeException('Unsafe directory');
$package=json_decode(base64_decode('__POLICY_PACKAGE_BASE64__',true),true,512,JSON_THROW_ON_ERROR);
foreach($package as$rel=>$entry){
 if(!in_array($rel,['backup_lib.php','index.html'],true))throw new RuntimeException('Unexpected package entry');
 $source=base64_decode($entry['source'],true);$path=$target.'/'.$rel;
 if(!is_string($source)||hash('sha256',$source)!==$entry['new']||!is_file($path)||is_link($path))throw new RuntimeException('Invalid policy source');
 $actual=hash_file('sha256',$path);
 if($install?!in_array($actual,[$entry['old'],$entry['new']],true):($rel==='backup_lib.php'&&$actual!==$entry['new']))throw new RuntimeException('Live source changed; maintenance stopped');
}
if($install){
 $work=$deploy.'/business-backup-policy';if(is_link($work)||(!is_dir($work)&&!mkdir($work,0700)))throw new RuntimeException('Unsafe policy staging');
 foreach($package as$rel=>$entry){
  $path=$target.'/'.$rel;$previous=$work.'/previous-'.$entry['old'].'-'.basename($rel);
  if(!file_exists($previous)&&hash_file('sha256',$path)===$entry['old']){if(!copy($path,$previous))throw new RuntimeException('Previous source preservation failed');chmod($previous,0600);}
  $tmp=$target.'/.backup-policy-'.bin2hex(random_bytes(6)).'.tmp';
  try{if(file_put_contents($tmp,base64_decode($entry['source'],true),LOCK_EX)===false||hash_file('sha256',$tmp)!==$entry['new'])throw new RuntimeException('Policy staging failed');chmod($tmp,0644);if(!rename($tmp,$path))throw new RuntimeException('Policy install failed');}finally{if(is_file($tmp))unlink($tmp);}
  echo 'BACKUP_POLICY_SOURCE_INSTALLED file='.$rel.' sha256='.$entry['new']."\n";
 }
}
define('TT_DATA_DIR',$private);define('TT_STORE_FILE',$private.'/auth.json');
function tt_ensure_data_dir():void{if(!is_dir(TT_DATA_DIR)||is_link(TT_DATA_DIR))throw new RuntimeException('Private storage unavailable');}
function tt_decode_store(string $raw):array{$a=json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($a))throw new RuntimeException('Invalid auth store');return $a;}
function tt_open_store_lock(int $mode){$p=TT_DATA_DIR.'/auth.lock';if(!is_file($p)||is_link($p))throw new RuntimeException('Auth lock unavailable');$h=fopen($p,'rb');if(!$h||!flock($h,$mode))throw new RuntimeException('Auth lock unavailable');return $h;}
require $target.'/backup_lib.php';
function policy_usage():array{$out=['files'=>0,'bytes'=>0,'managedOver15Days'=>0];foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(tt_backup_dir(),FilesystemIterator::SKIP_DOTS))as$f){if(!$f->isFile()||$f->isLink())continue;$out['files']++;$out['bytes']+=$f->getSize();$m=tt_backup_snapshot_metadata($f->getPathname());if($m&&time()-$m['created']>15*86400)$out['managedOver15Days']++;}return $out;}
$before=policy_usage();
$name=tt_create_server_snapshot($install?'manual':'auto');
$lock=fopen(tt_backup_job_lock_file(),'c+');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Backup service busy');
try{$manifest=tt_backup_verified_server_snapshot($name);tt_backup_rotate($name);}finally{flock($lock,LOCK_UN);fclose($lock);}
$after=policy_usage();
echo 'BACKUP_POLICY_ACCEPTANCE '.json_encode(['mode'=>$install?'install_and_maintain':'daily_maintenance','maxAgeDays'=>15,'compression'=>'immediate_for_text_and_data','before'=>$before,'after'=>$after,'netBytesFreed'=>$before['bytes']-$after['bytes'],'verifiedBackupBytes'=>filesize(tt_backup_dir().'/'.$name),'verifiedRecoveryFiles'=>(int)$manifest['recoveryFileCount'],'verifiedBackupSha256'=>hash_file('sha256',tt_backup_dir().'/'.$name),'liveRecordsDeleted'=>0,'uploadedDocumentsDeleted'=>0],JSON_THROW_ON_ERROR)."\n";
if($after['managedOver15Days']!==0)throw new RuntimeException('Some expired managed snapshots were held; review required');
