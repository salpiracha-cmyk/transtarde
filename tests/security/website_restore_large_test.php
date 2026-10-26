<?php
declare(strict_types=1);
// Real >512 MiB encrypted ZIP; no sparse-file or size-only substitute.
$root=sys_get_temp_dir().'/tti-large-restore-'.bin2hex(random_bytes(8));mkdir($root.'/private',0700,true);
define('TT_DATA_DIR',$root.'/private');define('TT_STORE_FILE',TT_DATA_DIR.'/auth.json');function tt_ensure_data_dir():void{}
require __DIR__.'/../../backup_lib.php';require __DIR__.'/../../restore_jobs.php';
try{
 file_put_contents(TT_STORE_FILE,'{"users":[],"masters":[],"audit":[]}');
 $password='Large-fixture-password';$salt=random_bytes(16);$key=tt_backup_derive_key($password,$salt,200000);$z=new TT_SimpleZipWriter($root.'/large.zip');$map=[];
 $auth=tt_backup_encrypt('{"users":[],"masters":[]}', $key);$z->addString('Encrypted/auth.bin',$auth['cipher'],false);unset($auth['cipher']);$auth['stored']='Encrypted/auth.bin';$map['System_Recovery/private/auth.json']=$auth;
 $plain=random_bytes(1048576);$e=tt_backup_encrypt($plain,$key);$cipher=$e['cipher'];unset($e['cipher']);$chunks=[];$hash=hash_init('sha256');
 for($i=0;$i<520;$i++){$stored='Encrypted/'.$i.'.bin';$z->addString($stored,$cipher,false);$chunks[]=$e+['stored'=>$stored];hash_update($hash,$plain);}
 $digest=hash_final($hash);$map['System_Recovery/private/documents/large-fixture.pdf']=['chunks'=>$chunks,'sha256'=>$digest,'size'=>520*1048576];
 $z->addString('backup-manifest.json',json_encode(['application'=>'Transtrade','type'=>'full_recovery','schema'=>3,'scope'=>'data','encryption'=>['algorithm'=>'AES-256-GCM','salt'=>base64_encode($salt),'iterations'=>200000],'encryptedEntries'=>$map]));$z->close();unset($plain,$cipher,$chunks,$map);
 $file=$root.'/large.zip';$hashes=[];$h=fopen($file,'rb');while(!feof($h)){$b=fread($h,TT_RESTORE_CHUNK);if($b!=='')$hashes[]=hash('sha256',$b);}rewind($h);
 if(filesize($file)<=536870912)throw new RuntimeException('Large-file fixture is too small.');
 $s=ttr_start(['size'=>filesize($file),'name'=>'large.zip','chunks'=>$hashes],['id'=>1]);
 while($s['received']<$s['size'])$s=ttr_chunk($s,$s['received'],fread($h,TT_RESTORE_CHUNK));fclose($h);
 while($s['state']!=='verified')$s=ttr_verify(ttr_read(),$password);
 $s=ttr_confirm($s,'RESTORE');while($s['state']!=='done'){$s=ttr_step(ttr_read());if(in_array($s['state'],['failed','rolled_back'],true))throw new RuntimeException('Large restore failed.');}
 if(hash_file('sha256',TT_DATA_DIR.'/documents/large-fixture.pdf')!==$digest)throw new RuntimeException('Large restore differs from original.');
 echo 'WEBSITE_RESTORE_REAL_LARGE_ZIP_OK bytes='.filesize($file)."\n";
}finally{ttr_delete_work($root);}
