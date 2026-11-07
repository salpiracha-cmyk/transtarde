<?php
declare(strict_types=1);

require dirname(__DIR__).'/auth_store.php';
require __DIR__.'/post_id_migration_core.php';

function tt_post_migration_write_locked($handle,string $contents):void {
    rewind($handle);
    if(!ftruncate($handle,0))throw new RuntimeException('Accounts storage could not be prepared for saving.');
    $length=strlen($contents);$written=0;
    while($written<$length){$bytes=fwrite($handle,substr($contents,$written));if($bytes===false||$bytes===0)throw new RuntimeException('Accounts storage could not be saved.');$written+=$bytes;}
    if(!fflush($handle))throw new RuntimeException('Accounts storage could not be flushed.');
    if(function_exists('fsync')&&!fsync($handle))throw new RuntimeException('Accounts storage could not be synchronized.');
}

$apply=in_array('--apply',$argv,true);
$accountsFile=TT_DATA_DIR.'/accounts.json';
if (!is_file($accountsFile)) throw new RuntimeException('Accounts storage was not found.');
$raw=file_get_contents($accountsFile);
if ($raw===false) throw new RuntimeException('Accounts storage could not be read.');
$accounts=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
if (!is_array($accounts)) throw new RuntimeException('Accounts storage is invalid.');
$plan=tt_post_migration_plan($accounts);
$preview=['ok'=>true,'mode'=>$apply?'apply':'preview','journalCount'=>$plan['journalCount'],'changeCount'=>count($plan['map']),'mapping'=>$plan['map'],'maximumByYear'=>$plan['maximumByYear']];
if (!$apply) {echo json_encode($preview,JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL;exit(0);}
if (!$plan['map']) {$preview['mode']='no-op';echo json_encode($preview,JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL;exit(0);}

tt_ensure_data_dir();
$backupDir=TT_DATA_DIR.'/post-id-migrations';
if (!is_dir($backupDir)&&!mkdir($backupDir,0700,true)&&!is_dir($backupDir)) throw new RuntimeException('Post ID migration backup folder could not be created.');
$stamp=gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(4));
$accountsBackup=$backupDir.'/accounts-before-post-id-'.$stamp.'.json';
$authBackup=$backupDir.'/auth-before-post-id-'.$stamp.'.json';
if (!copy($accountsFile,$accountsBackup)||!copy(TT_STORE_FILE,$authBackup)) throw new RuntimeException('Post ID migration backups could not be created.');
@chmod($accountsBackup,0600);@chmod($authBackup,0600);

$handle=fopen($accountsFile,'c+');
if ($handle===false||!flock($handle,LOCK_EX)) throw new RuntimeException('Accounts storage could not be locked.');
$lockedRaw=null;
try {
    rewind($handle);$lockedRaw=stream_get_contents($handle);$lockedAccounts=$lockedRaw?json_decode($lockedRaw,true,512,JSON_THROW_ON_ERROR):null;
    if (!is_array($lockedAccounts)) throw new RuntimeException('Locked Accounts storage is invalid.');
    $lockedPlan=tt_post_migration_plan($lockedAccounts);
    if ($lockedPlan['map']!==$plan['map']) throw new RuntimeException('Accounts changed while the migration was being prepared. Retry the deployment.');
    $migrated=tt_post_migration_apply($lockedAccounts,$lockedPlan);
    tt_post_migration_validate($migrated,$lockedPlan);
    $encoded=json_encode($migrated,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    tt_post_migration_write_locked($handle,$encoded);
    tt_mutate_store(static function (&$auth) use ($lockedPlan): void {
        $auth['post_sequence']=is_array($auth['post_sequence'] ?? null)?$auth['post_sequence']:[];
        $auth['post_sequence']['years']=is_array($auth['post_sequence']['years'] ?? null)?$auth['post_sequence']['years']:[];
        foreach ($lockedPlan['maximumByYear'] as $year=>$maximum) {
            $key=(string)$year;$auth['post_sequence']['years'][$key]=is_array($auth['post_sequence']['years'][$key] ?? null)?$auth['post_sequence']['years'][$key]:[];
            $auth['post_sequence']['years'][$key]['last']=max((int)($auth['post_sequence']['years'][$key]['last'] ?? 0),(int)$maximum);
            $auth['post_sequence']['last']=max((int)($auth['post_sequence']['last'] ?? 0),(int)$maximum);
        }
        $auth['post_sequence']['schema']=2;$auth['post_sequence']['migrated_at']=gmdate('c');
    });
    rewind($handle);$savedRaw=stream_get_contents($handle);
    $saved=is_string($savedRaw)?json_decode($savedRaw,true,512,JSON_THROW_ON_ERROR):null;
    if(!is_array($saved))throw new RuntimeException('Saved Accounts storage could not be verified.');
    tt_post_migration_validate($saved,$lockedPlan);
} catch (Throwable $error) {
    $accountsRestored=false;$authRestored=false;
    if(is_string($lockedRaw)){
        try{tt_post_migration_write_locked($handle,$lockedRaw);$accountsRestored=true;}catch(Throwable $restoreError){$accountsRestored=false;}
    }
    try {
        $authBefore=json_decode((string)file_get_contents($authBackup),true,512,JSON_THROW_ON_ERROR);
        if(is_array($authBefore)){
            tt_mutate_store(static function (&$auth) use ($authBefore):void {$auth=$authBefore;});
            $authRestored=true;
        }
    } catch(Throwable $restoreError){$authRestored=false;}
    if(!$accountsRestored||!$authRestored)throw new RuntimeException('Post ID migration failed and automatic restoration was incomplete. Use the private migration backups.',0,$error);
    throw $error;
} finally {flock($handle,LOCK_UN);fclose($handle);}

$preview['backupDirectory']=$backupDir;
echo json_encode($preview,JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL;
