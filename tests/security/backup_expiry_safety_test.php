<?php
declare(strict_types=1);
require __DIR__.'/../../backup_lib.php';
$root=sys_get_temp_dir().'/tt-expiry-'.bin2hex(random_bytes(6));mkdir($root.'/backups',0700,true);define('TT_DATA_DIR',$root);
function expiry_expect(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function expiry_fixture(string $kind,int $stamp):string{
 $name=$kind.'-'.gmdate('Ymd-His',$stamp).'-'.bin2hex(random_bytes(4)).'.zip';$data='{"users":[],"fixture":"recovery"}';
 $w=new TT_SimpleZipWriter(tt_backup_dir().'/'.$name);$w->addString('System_Recovery/private/auth.json',$data,false);
 $w->addString('backup-manifest.json',json_encode(['application'=>'Transtrade','type'=>'server_snapshot','createdAt'=>gmdate('c',$stamp),'snapshotKind'=>$kind,'recoveryFiles'=>[['path'=>'auth.json','size'=>strlen($data),'sha256'=>hash('sha256',$data)]]],JSON_THROW_ON_ERROR));$w->close();return $name;
}
try{
 $now=time();for($day=1;$day<=5;$day++)expiry_fixture('auto',$now-$day*86400);$old=expiry_fixture('auto',$now-30*86400);$manual=expiry_fixture('manual',$now-16*86400);$pre=expiry_fixture('pre-restore',$now-16*86400);$day10=expiry_fixture('auto',$now-10*86400);
 // Touching an old backup must not renew its retention age.
 touch(tt_backup_dir().'/'.$old,$now);
 tt_backup_rotate();expiry_expect(is_file(tt_backup_dir().'/'.$old),'No verified replacement: retain the last old recovery copy.');
 $fresh=expiry_fixture('auto',$now);$p=tt_backup_dir().'/'.$fresh;
 tt_backup_rotate($fresh);expiry_expect(is_file(tt_backup_dir().'/'.$old),'Readable manifest alone must not authorise deletion.');
 tt_backup_verified_server_snapshot($fresh);$original=file_get_contents($p);$bad=$original;$bad[70]=chr(ord($bad[70])^255);file_put_contents($p,$bad);
 tt_backup_rotate($fresh);expiry_expect(is_file(tt_backup_dir().'/'.$old),'A changed verified survivor must block pruning.');
 file_put_contents($p,$original);tt_backup_verified_server_snapshot($fresh);
 file_put_contents(tt_backup_dir().'/auto-20200101-000000.zip','damaged');file_put_contents(tt_backup_dir().'/unknown-owner-copy.zip','keep');symlink(tt_backup_dir().'/'.$old,tt_backup_dir().'/auto-20200102-000000.zip');
 tt_backup_rotate($fresh);
 foreach([$old,$manual,$pre]as$n)expiry_expect(!file_exists(tt_backup_dir().'/'.$n),'Older points must expire after seven newer distinct dates verify.');
 expiry_expect(is_file(tt_backup_dir().'/'.$day10)&&is_file($p),'Preserve a recent historical point and the verified survivor.');
 expiry_expect(is_file(tt_backup_dir().'/auto-20200101-000000.zip')&&is_file(tt_backup_dir().'/unknown-owner-copy.zip')&&is_link(tt_backup_dir().'/auto-20200102-000000.zip'),'Unknown, damaged and symlink entries must be held.');
 echo "PASS seven-day replacement from creation dates, verified-survivor and tamper guards, manual/pre-restore expiry and protected unknown files\n";
}finally{foreach(glob($root.'/backups/*')?:[]as$p)unlink($p);rmdir($root.'/backups');rmdir($root);}
