<?php
declare(strict_types=1);

$root=sys_get_temp_dir().'/tti-backup-'.bin2hex(random_bytes(8));
$app=$root.'/app';
if(!mkdir($app,0700,true))throw new RuntimeException('Could not create the disposable backup test directory.');
foreach(['auth_store.php','master_store.php','product_stage.php','offline_idempotency.php','session_store.php','backup_lib.php'] as $file){
    if(!copy(__DIR__.'/../../'.$file,$app.'/'.$file))throw new RuntimeException('Could not prepare '.$file);
}

function backup_expect(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function backup_remove_tree(string $path):void{
    if(!is_dir($path))return;
    foreach(scandir($path)?:[] as $entry){if($entry==='.'||$entry==='..')continue;$target=$path.'/'.$entry;is_dir($target)?backup_remove_tree($target):unlink($target);}
    rmdir($path);
}

try{
    require $app.'/auth_store.php';
    require_once $app.'/backup_lib.php';
    tt_mutate_store(static function(array &$data):void{$data['users'][]=['id'=>7,'username'=>'backup-test','role'=>'Super Admin'];});
    file_put_contents(TT_DATA_DIR.'/operations.json',json_encode(['revision'=>1,'values'=>['test'=>'[]'],'meta'=>[]],JSON_THROW_ON_ERROR));
    if(!mkdir(TT_DATA_DIR.'/documents',0700,true)&&!is_dir(TT_DATA_DIR.'/documents'))throw new RuntimeException('Could not create document fixture folder.');
    $payload=str_repeat('Transtrade-stream-test-',140000);
    file_put_contents(TT_DATA_DIR.'/documents/large-fixture.txt',$payload);

    $snapshot=tt_create_server_snapshot('manual');
    $path=tt_backup_dir().'/'.$snapshot;
    backup_expect(is_file($path),'A verified manual snapshot must be committed.');
    backup_expect((fileperms($path)&0777)===0600,'Server snapshots must remain owner-readable only.');
    $reader=new TT_SimpleZipReader($path);
    $restored=$reader->get('System_Recovery/private/documents/large-fixture.txt');
    $manifest=json_decode((string)$reader->get('backup-manifest.json'),true);
    $reader->close();
    backup_expect($restored===$payload,'Large files must stream into the snapshot without data loss.');
    backup_expect(is_array($manifest)&&($manifest['recoveryFileCount']??0)>=3,'Snapshot manifest must inventory recovery files.');
    backup_expect(!array_filter((array)$manifest['recoveryFiles'],static fn(array $row):bool=>str_ends_with((string)($row['path']??''),'.lock')),'Runtime lock files must not enter recovery snapshots.');
    backup_expect(tt_backup_master_headers('banks',14)[4]==='Bank name'&&tt_backup_master_headers('banks',14)[7]==='Currency','Bank export headings must match saved column positions.');
    backup_expect(tt_backup_master_headers('export_customers',22)[21]==='Default payment / customer instructions','All known master fields need readable headings.');
    foreach(['companies'=>15,'commodities'=>8,'product_settings'=>1,'products'=>22,'purchase_products'=>11,'purchase_kat'=>10,'export_documents'=>5,'export_terms'=>3,'export_realization_charges'=>15,'export_customers'=>22,'business_parties'=>13,'mills'=>7,'banks'=>14,'salary_staff'=>12,'reference_lists'=>3,'tg_currency_rates'=>12,'tg_compliance_documents'=>13] as $type=>$width){
        $headings=tt_backup_master_headers($type,$width);
        backup_expect(count($headings)===$width&&!array_filter($headings,static fn(string $h):bool=>str_starts_with($h,'Field ')),'Incomplete Master export headings: '.$type);
    }
    $csv=tt_backup_csv(['headers'=>['Name'],'rows'=>[['  =1+1'],['@SUM(A1)'],['Regular company']]]);
    backup_expect(str_contains($csv,"'  =1+1")&&str_contains($csv,"'@SUM(A1)"),'CSV must neutralize spreadsheet formulas.');
    $xlsx=tt_backup_make_xlsx(['Test'=>['headers'=>['Name'],'rows'=>[['=1+1']]]]);
    $xlsxZip=new TT_SimpleZipReader($xlsx);
    backup_expect(str_contains((string)$xlsxZip->get('xl/worksheets/sheet1.xml'),"&apos;=1+1"),'Excel cells must neutralize formulas.');
    $xlsxZip->close();@unlink($xlsx);
    $serverManifest=tt_backup_verified_server_snapshot($snapshot);
    backup_expect((int)$serverManifest['recoveryFileCount']>=3,'Super Admin server snapshot verification must check every entry.');
    file_put_contents(TT_DATA_DIR.'/documents/large-fixture.txt','changed after snapshot');
    $restoredResult=tt_restore_server_snapshot($snapshot);
    backup_expect(file_get_contents(TT_DATA_DIR.'/documents/large-fixture.txt')===$payload&&$restoredResult['restoredFiles']>=3,'Verified server snapshot must restore stored documents.');
    backup_expect(is_file(tt_backup_dir().'/'.$restoredResult['safetySnapshot']),'Restore must preserve a safety snapshot.');
    $full=tt_build_download_backup(true,'test-backup-password-2026');
    $fullResult=tt_restore_complete_backup($full['path'],'test-backup-password-2026');
    backup_expect($fullResult['restoredFiles']>=3&&file_get_contents(TT_DATA_DIR.'/documents/large-fixture.txt')===$payload,'Chunk-encrypted complete backup must restore documents.');
    @unlink($full['path']);

    file_put_contents(TT_DATA_DIR.'/operations.json','{"revision":');
    $failed=false;
    try{tt_create_server_snapshot('manual');}catch(RuntimeException){$failed=true;}
    backup_expect($failed,'Invalid operational storage must fail closed instead of producing a snapshot.');
    backup_expect(count(glob(tt_backup_dir().'/.snapshot-*.tmp')?:[])===0,'Failed snapshots must not leave partial archives.');
    $failedState=tt_backup_read_state();
    backup_expect((int)($failedState['consecutive_failures']??0)>=1&&!empty($failedState['last_error_at']),'Backup failures must be visible in persistent health state.');

    file_put_contents(TT_DATA_DIR.'/operations.json',json_encode(['revision'=>2,'values'=>[],'meta'=>[]],JSON_THROW_ON_ERROR));
    for($i=0;$i<14;$i++)tt_create_server_snapshot('manual');
    backup_expect(count(glob(tt_backup_dir().'/manual-*.zip')?:[])===12,'Manual server snapshots must have a finite retention limit.');
    $healthy=tt_backup_status();
    backup_expect(($healthy['backupHealthy']??false)===true&&(int)($healthy['consecutiveFailures']??-1)===0,'A verified snapshot must clear the prior failure state.');
    backup_expect(count(glob(TT_DATA_DIR.'/backup_state.json.tmp-*')?:[])===0,'Atomic backup state writes must not leave temporary files.');
    echo "PASS verified, bounded and observable server backups\n";
}finally{
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();backup_remove_tree($root);}
