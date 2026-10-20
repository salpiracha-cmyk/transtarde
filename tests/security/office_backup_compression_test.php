<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/tti-office-compression-'.bin2hex(random_bytes(8));
$app=$root.'/app';mkdir($app,0700,true);
foreach(['auth_store.php','master_store.php','product_stage.php','offline_idempotency.php','session_store.php','backup_lib.php']as$file)copy(__DIR__.'/../../'.$file,$app.'/'.$file);
function office_expect(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function office_remove(string $path):void{if(is_dir($path)){foreach(scandir($path)?:[]as$name)if($name!=='.'&&$name!=='..')office_remove($path.'/'.$name);rmdir($path);}else @unlink($path);}
try{
    require $app.'/auth_store.php';require_once $app.'/backup_lib.php';
    $text=str_repeat('{"historical":"original data retained"}',250000);
    file_put_contents(TT_DATA_DIR.'/history.json',$text);
    mkdir(TT_DATA_DIR.'/documents',0700,true);
    $binary=random_bytes(3*1024*1024);file_put_contents(TT_DATA_DIR.'/documents/fixture.pdf',$binary);
    $password='fixture-compression-password';$built=tt_build_download_backup(true,$password);
    $manifest=tt_backup_verify_full($built['path'],$password);
    office_expect(filesize($built['path'])<strlen($text)/2,'Text must compress before encryption; document contents must not be duplicated.');
    $map=$manifest['encryptedEntries'];
    office_expect($map['Documents/fixture.pdf']===$map['System_Recovery/private/documents/fixture.pdf'],'Readable and recovery document paths must reference the same verified encrypted contents.');
    office_expect(($map['System_Recovery/private/history.json']['chunks'][0]['compression']??'')==='deflate','Text chunks must record their compression format.');
    [$zip,$opened,$key]=tt_backup_open_verified($built['path'],$password);
    foreach(['System_Recovery/private/history.json'=>$text,'Documents/fixture.pdf'=>$binary,'System_Recovery/private/documents/fixture.pdf'=>$binary]as$logical=>$expected){
        $sink=fopen('php://temp','w+');tt_backup_decrypt_entry($zip,$opened['encryptedEntries'][$logical],$key,$sink);rewind($sink);
        office_expect(stream_get_contents($sink)===$expected,'Compressed/aliased recovery contents must remain byte-exact.');fclose($sink);
    }
    $zip->close();
    $wrong=false;try{tt_backup_verify_full($built['path'],'another-fixture-password');}catch(InvalidArgumentException){$wrong=true;}
    office_expect($wrong,'Wrong passwords must still fail.');
    $compressed=tt_backup_encrypt($text,$key,true);$tampered=$compressed;unset($tampered['compression']);
    $rejected=false;try{tt_backup_decrypt($compressed['cipher'],$tampered,$key);}catch(InvalidArgumentException){$rejected=true;}
    office_expect($rejected,'Changing compression metadata must fail AES authentication.');
    // Old stored-encryption archives remain readable after the format upgrade.
    $legacy=$root.'/legacy.zip';$writer=new TT_SimpleZipWriter($legacy);$salt=random_bytes(16);$legacyKey=tt_backup_derive_key($password,$salt,200000);
    $entry=tt_backup_encrypt('{"users":[],"audit":[]}',$legacyKey);$writer->addString('Encrypted/000001.bin',$entry['cipher'],false);unset($entry['cipher']);$entry['stored']='Encrypted/000001.bin';
    $legacyManifest=['schema'=>2,'application'=>'Transtrade','type'=>'full_recovery','encryption'=>['algorithm'=>'AES-256-GCM','salt'=>base64_encode($salt),'iterations'=>200000],'encryptedEntries'=>['System_Recovery/private/auth.json'=>$entry]];
    $writer->addString('backup-manifest.json',json_encode($legacyManifest));$writer->close();
    office_expect(tt_backup_verify_full($legacy,$password)['schema']===2,'Existing uncompressed encrypted backups must remain recoverable.');
    $before=glob(sys_get_temp_dir().'/tt-backup-*')?:[];
    $bad=false;try{tt_build_download_backup(true,'short');}catch(InvalidArgumentException){$bad=true;}
    office_expect($bad&&!(array_diff(glob(sys_get_temp_dir().'/tt-backup-*')?:[],$before)),'Failed generation must not leave new temporary archives.');
    @unlink($built['path']);
    echo "PASS smaller encrypted recovery, exact documents, legacy compatibility, tamper rejection and failed-build cleanup\n";
}finally{if(session_status()===PHP_SESSION_ACTIVE)session_write_close();office_remove($root);}
