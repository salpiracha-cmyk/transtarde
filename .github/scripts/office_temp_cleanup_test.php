<?php
declare(strict_types=1);
require __DIR__.'/../../backup_lib.php';require __DIR__.'/office_temp_cleanup.php';
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
$dir=sys_get_temp_dir().'/tti-cleanup-fixture-'.bin2hex(random_bytes(6));mkdir($dir,0700);$uid=posix_geteuid();$now=time()+90000;
try{
 $p=$dir.'/tt-backup-ABC123';$z=new TT_SimpleZipWriter($p);$z->addString('backup-manifest.json',json_encode(['application'=>'Transtrade','type'=>'full_recovery','schema'=>2,'createdAt'=>gmdate('c'),'encryptedEntries'=>['entry'=>['stored'=>'payload']],'recoveryFiles'=>[['path'=>'auth.json']]]));$z->close();
 $c=otc_classify($p,$uid,$now);check($c['reason']==='eligible','recognise known generated archive');
 check(otc_classify($p,$uid,time())['reason']==='recent','hold recent');
 check(otc_classify($p,$uid+1,$now)['reason']==='unknown','hold other owner');
 $link=$dir.'/tt-backup-SYM123';symlink($p,$link);check(otc_classify($link,$uid,$now)['reason']==='unknown','hold symlink');
 $unknown=$dir.'/tt-backup-BAD123';file_put_contents($unknown,'unrecognised');check(otc_classify($unknown,$uid,$now)['reason']==='unrecognised','hold unreadable');
 check(!otc_remove($p,$c,$uid,$now,fn()=>false)&&is_file($p),'require verified survivor');
 $h=fopen($p,'rb');flock($h,LOCK_EX);check(!otc_remove($p,$c,$uid,$now,fn()=>true),'hold locked file');flock($h,LOCK_UN);fclose($h);
 $changed=$c;$changed['identity']['size']++;check(!otc_remove($p,$changed,$uid,$now,fn()=>true),'hold changed identity');
 check(otc_remove($p,$c,$uid,$now,fn()=>true)&&!file_exists($p),'remove identified abandoned archive');
 check(is_file($unknown)&&is_link($link),'unknown files untouched');
 echo "OFFICE_TEMP_CLEANUP_BOUNDARIES_OK\n";
}finally{foreach(glob($dir.'/*')?:[]as$p)unlink($p);rmdir($dir);}
