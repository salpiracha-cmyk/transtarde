<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/tti-office-'.bin2hex(random_bytes(8));
$app=$root.'/app';mkdir($app,0700,true);
foreach(['auth_store.php','master_store.php','product_stage.php','offline_idempotency.php','session_store.php','office_backup_auth.php'] as $file)copy(__DIR__.'/../../'.$file,$app.'/'.$file);
try {
    require $app.'/auth_store.php';require $app.'/office_backup_auth.php';
    if(tt_office_backup_enabled())throw new RuntimeException('Credential must be absent before setup.');
    $first=tt_office_backup_issue_token();
    if(!tt_office_backup_enabled()||!tt_office_backup_token_valid($first)||tt_office_backup_token_valid(str_repeat('0',64)))throw new RuntimeException('Backup credential validation failed.');
    if((fileperms(tt_office_backup_token_file())&0777)!==0600)throw new RuntimeException('Credential hash permissions are too broad.');
    $second=tt_office_backup_issue_token();
    if(tt_office_backup_token_valid($first)||!tt_office_backup_token_valid($second))throw new RuntimeException('Rotating a backup credential did not revoke the old one.');
    tt_office_backup_revoke_token();
    if(tt_office_backup_enabled()||tt_office_backup_token_valid($second))throw new RuntimeException('Revocation failed.');
    echo "PASS isolated office backup credential lifecycle\n";
} finally {
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    foreach(glob($root.'/transtrade_private/*')?:[] as $path)@unlink($path);
    @rmdir($root.'/transtrade_private');
    foreach(glob($app.'/*')?:[] as $path)@unlink($path);
    @rmdir($app);@rmdir($root);
}
