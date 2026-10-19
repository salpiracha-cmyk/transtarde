<?php
declare(strict_types=1);
define('TT_DEPLOY_CACHE_FUNCTIONS_ONLY',true);require __DIR__.'/deployment_cache_audit.php';
function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function fixture_file(string $path,string $text):void{if(!is_dir(dirname($path)))mkdir(dirname($path),0700,true);file_put_contents($path,$text);}
function fixture_remove(string $dir):void{if(!is_dir($dir)||is_link($dir)){if(is_link($dir))unlink($dir);return;}foreach(new DirectoryIterator($dir) as $item)if(!$item->isDot()){if($item->isDir()&&!$item->isLink())fixture_remove($item->getPathname());else unlink($item->getPathname());}rmdir($dir);}
$base=sys_get_temp_dir().'/tti-cache-audit-'.bin2hex(random_bytes(8));mkdir($base,0700,true);$root=$base.'/.transtrade-deploy';
try{
 foreach(['incoming','staging','backups'] as $d)mkdir($root.'/'.$d,0700,true);
 fixture_file($base.'/transtrade_private/accounts.json','original financial sentinel');fixture_file($base.'/public_html/login.php','live source sentinel');
 $protected=[$base.'/transtrade_private/accounts.json'=>'original financial sentinel',$base.'/public_html/login.php'=>'live source sentinel',$root.'/backups/before-original.tgz'=>'historical recovery sentinel',$root.'/incoming/owner-original.pdf'=>'owner original',$root.'/staging/custom-name/source.txt'=>'unknown stage'];
 foreach($protected as $p=>$s)fixture_file($p,$s);
 $a=str_repeat('a',40);$b=str_repeat('b',40);$c=str_repeat('c',40);$d=str_repeat('d',40);
 fixture_file($root.'/incoming/release-'.$a.'.tgz','generated archive');symlink($base.'/transtrade_private/accounts.json',$root.'/incoming/release-'.$b.'.tgz');
 foreach([$a,$b,$c,$d] as $sha)foreach(['login.php','module.php','exports/app.js'] as $marker)fixture_file($root.'/staging/'.$sha.'/'.$marker,'generated code');
 fixture_file($root.'/staging/'.$b.'/data/accounts.json','runtime data must remain');$protected[$root.'/staging/'.$b.'/data/accounts.json']='runtime data must remain';
 symlink($base.'/transtrade_private/accounts.json',$root.'/staging/'.$c.'/linked.json');
 unlink($root.'/staging/'.$d.'/module.php');
 $probe=dc_probe($root.'/incoming');check($probe['ok']&&$probe['removed']&&$probe['bytesWritten']===4096,'Disposable write probe failed or remained');check(count(glob($root.'/incoming/.storage-probe-*')?:[])===0,'Probe leak');
 if(function_exists('posix_geteuid')&&posix_geteuid()!==0){chmod($root.'/incoming',0500);try{$denied=dc_probe($root.'/incoming');check(!$denied['ok']&&$denied['removed']&&$denied['errorKind']==='PERMISSION_DENIED','Failed-open probe must report its error without a leaked file');}finally{chmod($root.'/incoming',0700);}}
 $result=dc_clean($root);check($result['incomingFilesRemoved']===1&&$result['stageDirectoriesRemoved']===1,'Only the exact generated archive and safe complete stage may be removed');
 check($result['businessFilesTouched']===0&&$result['backupFilesTouched']===0,'Protected boundary changed');
 foreach($protected as $p=>$s)check(is_file($p)&&file_get_contents($p)===$s,'A financial/live/backup/unknown source was changed');
 check(is_link($root.'/incoming/release-'.$b.'.tgz')&&is_link($root.'/staging/'.$c.'/linked.json'),'Symlinks must be retained, never followed');
 check(is_dir($root.'/staging/'.$d),'Incomplete/unknown stage must remain');
 $before=serialize(dc_inventory($root));$again=dc_clean($root);check($again['incomingFilesRemoved']===0&&$again['stageDirectoriesRemoved']===0&&serialize(dc_inventory($root))===$before,'Retry must be a no-op');
 rename($root.'/staging',$root.'/kept-staging');symlink($base.'/transtrade_private',$root.'/staging');$stopped=false;try{dc_clean($root);}catch(RuntimeException){$stopped=true;}check($stopped&&file_get_contents($base.'/transtrade_private/accounts.json')==='original financial sentinel','Unsafe family must stop before any deletion');
 check(dc_error_kind('fwrite(): Disk quota exceeded')==='DISK_QUOTA_EXCEEDED'&&dc_error_kind('No space left on device')==='FILESYSTEM_FULL','Write errors must distinguish account quota from filesystem capacity');
 echo "PASS deployment cache boundary: generated-only deletion, retained backups/live/private/runtime/unknown/incomplete/symlink records, removed4KBprobe, no-op retry and unsafe-root stop\n";
}finally{fixture_remove($base);}
