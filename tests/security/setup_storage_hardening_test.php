<?php
declare(strict_types=1);

$root=sys_get_temp_dir().'/tti-security-'.bin2hex(random_bytes(8));
$app=$root.'/app';
if(!mkdir($app,0700,true))throw new RuntimeException('Could not create the disposable test directory.');
foreach(['auth_store.php','master_store.php','product_stage.php','offline_idempotency.php','session_store.php'] as $file){
    if(!copy(__DIR__.'/../../'.$file,$app.'/'.$file))throw new RuntimeException('Could not prepare '.$file);
}

function expect(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
function remove_tree(string $path): void {
    if(!is_dir($path))return;
    foreach(scandir($path)?:[] as $entry){if($entry==='.'||$entry==='..')continue;$target=$path.'/'.$entry;is_dir($target)?remove_tree($target):unlink($target);}
    rmdir($path);
}

try{
    require $app.'/auth_store.php';
    expect(tt_read_store()['users']===[],'A genuinely new installation must start empty.');
    expect(!tt_setup_token_configured(),'Setup must remain disabled without a private token.');
    putenv('TT_SETUP_TOKEN=disposable-test-token-123456789');
    expect(tt_setup_token_configured(),'A sufficiently strong private setup token must be recognized.');
    expect(!tt_setup_token_valid('wrong-token'),'An invalid setup token must be rejected.');
    expect(tt_setup_token_valid('disposable-test-token-123456789'),'The configured setup token must be accepted.');

    tt_mutate_store(static function(array &$data): void {$data['users'][]=['id'=>1,'username'=>'security-test','role'=>'Tester'];});
    $saved=tt_read_store();
    expect(($saved['users'][0]['username']??'')==='security-test','An atomic mutation must remain readable.');
    expect(count(glob(TT_DATA_DIR.'/.auth.*.tmp')?:[])===0,'Atomic writes must not leave temporary files behind.');

    $valid=file_get_contents(TT_STORE_FILE);
    file_put_contents(TT_STORE_FILE,'{"users":');
    $readFailed=false;
    try{tt_read_store();}catch(RuntimeException $e){$readFailed=str_contains($e->getMessage(),'damaged');}
    expect($readFailed,'Corrupt existing storage must fail closed on read.');
    $writeFailed=false;
    try{tt_mutate_store(static function(array &$data): void {$data['users']=[];});}catch(RuntimeException $e){$writeFailed=str_contains($e->getMessage(),'damaged');}
    expect($writeFailed,'Corrupt existing storage must fail closed on mutation.');
    expect(file_get_contents(TT_STORE_FILE)==='{"users":','A rejected mutation must not overwrite the corrupt evidence.');

    file_put_contents(TT_STORE_FILE,$valid);
    tt_lock_setup();
    expect(is_file(TT_SETUP_LOCK_FILE),'Completing setup must create a persistent lock.');
    expect(tt_setup_locked(),'The persistent setup lock must prevent setup from reopening.');
    echo "PASS setup and secure-store hardening\n";
}finally{
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    putenv('TT_SETUP_TOKEN');
    remove_tree($root);
}
