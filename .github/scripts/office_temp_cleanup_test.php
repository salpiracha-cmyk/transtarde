<?php
declare(strict_types=1);
require __DIR__.'/../../backup_lib.php';require __DIR__.'/office_temp_cleanup.php';
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
$dir=sys_get_temp_dir().'/tti-cleanup-fixture-'.bin2hex(random_bytes(6));mkdir($dir,0700);$uid=posix_geteuid();$now=time()+90000;
try{
 $p=$dir.'/tt-backup-ABC123';$z=new TT_SimpleZipWriter($p);$z->addString('backup-manifest.json',json_encode(['application'=>'Transtrade','type'=>'full_recovery','schema'=>2,'createdAt'=>gmdate('c'),'encryptedEntries'=>['entry'=>['stored'=>'payload']],'recoveryFiles'=>[['path'=>'auth.json']]]));$z->close();
 $c=otc_classify($p,$uid,$now);check($c['reason']==='eligible','recognise known generated archive');
 check(otc_classify($p,$uid,time())['reason']==='recent','hold recent');
 check(is_bool(otc_process_visibility($uid)),'visibility check has explicit result');
 $cut=strtotime('2026-10-20T10:17:16Z');$old=['mtime'=>$cut-3600,'ctime'=>$cut-3600];
 check(otc_legacy_grace($old,$cut+900,2,$cut-3600),'recognise fixed pre-repair legacy backlog');
 check(!otc_legacy_grace($old,$cut+900,3,$cut-3600),'new schema has no age exception');
 check(!otc_legacy_grace(['mtime'=>$cut-60,'ctime'=>$cut-60],$cut+900,2,$cut-60),'hold within grace');
 check(!otc_legacy_grace(['mtime'=>$cut+1,'ctime'=>$cut+1],$cut+86400,2,$cut-3600),'hold changed after repair');
 check(!otc_legacy_grace($old,$cut+900,2,$cut+1),'hold manifest after cutoff');
 $approval=strtotime('2026-10-20T17:45:20Z');$approvedOld=['mtime'=>$approval-4000,'ctime'=>$approval-4000];
 check(otc_owner_grace($approvedOld,$approval+60,3,$approval-4000,$approval),'owner permits the existing completed schema3 cohort');
 check(!otc_owner_grace($approvedOld,$approval+60,3,$approval-4000,0),'no owner exception by default');
 check(!otc_owner_grace(['mtime'=>$approval+1,'ctime'=>$approval-4000],$approval+9000,3,$approval-4000,$approval),'hold files changed after approval');
 check(!otc_owner_grace($approvedOld,$approval+60,3,$approval+1,$approval),'hold manifest newer than approval');
 check(!otc_owner_grace($approvedOld,$approval+60,4,$approval-4000,$approval),'hold unknown schemas');
 check(!otc_owner_grace(['mtime'=>$approval-60,'ctime'=>$approval-60],$approval+60,3,$approval-60,$approval),'hold recently generated files');
 $cohort=array_fill(0,6,['uid'=>$uid,'size'=>100]);$cohort[0]['size']=6856173095;
 check(otc_approved_cohort($cohort,$uid),'recognise only measured six-file cohort');
 $cohort[0]['size']++;check(!otc_approved_cohort($cohort,$uid),'stop on changed cohort bytes');
 array_pop($cohort);check(!otc_approved_cohort($cohort,$uid),'stop on changed cohort count');
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
