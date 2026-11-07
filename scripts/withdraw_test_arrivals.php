<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require __DIR__.'/arrival_withdrawal_plan.php';

$options=getopt('',['root:','apply','restore','expect-operations:','expect-accounts:']);
$root=realpath((string)($options['root']??(getenv('TT_DIAGNOSTIC_ROOT')?:dirname(__DIR__))));
if (!$root||!is_file($root.'/auth_store.php')) {fwrite(STDERR,"Application root required.\n");exit(1);}
$private=dirname($root).'/transtrade_private';$op=null;$ac=null;
try {
    foreach (['HOST','NAME','USER'] as $part) if (getenv('TT_DB_'.$part)) throw new RuntimeException('File-store withdrawal cannot run when MySQL is configured.');
    $apply=isset($options['apply']);$restore=isset($options['restore']);
    if ($apply&&$restore) throw new RuntimeException('Choose apply or restore.');
    $op=fopen($private.'/operations.json',($apply||$restore)?'r+':'r');
    if (!$op||!flock($op,($apply||$restore)?LOCK_EX:LOCK_SH)) throw new RuntimeException('Operations lock unavailable.');
    $ac=fopen($private.'/accounts.json','r');if (!$ac||!flock($ac,LOCK_SH)) throw new RuntimeException('Accounts read lock unavailable.');
    $before=stream_get_contents($op);$accountsRaw=stream_get_contents($ac);$accountsHash=hash('sha256',$accountsRaw);
    $archiveDir=$private.'/arrival-recovery';$archivePath=$archiveDir.'/withdrawal-20261017.json';
    $archive=is_file($archivePath)?json_decode((string)file_get_contents($archivePath),true,512,JSON_THROW_ON_ERROR):null;
    $write=static function($handle,string $raw):void {
        rewind($handle);if (!ftruncate($handle,0)||fwrite($handle,$raw)!==strlen($raw)||!fflush($handle)) throw new RuntimeException('Operations write failed; protected before-image retained.');
        if (function_exists('fsync')) fsync($handle);
    };
    if ($restore) {
        if (!is_array($archive)||!hash_equals($archive['afterSha256'],hash('sha256',$before))) throw new DomainException('Restore requires the unchanged post-withdrawal store. Later work must be reconciled separately.');
        if (!hash_equals($archive['beforeSha256'],hash('sha256',$archive['beforeRaw']))) throw new RuntimeException('Recovery before-image hash mismatch.');
        $write($op,$archive['beforeRaw']);echo json_encode(['ok'=>true,'restored'=>true,'accountsUnchanged'=>true]).PHP_EOL;exit;
    }
    $decodedCurrent=json_decode($before,true,512,JSON_THROW_ON_ERROR);
    $hasWithdrawn=false;foreach (['tt30slips','tt30queue','tt39accountsoutbox'] as $key) if (tt_is_withdrawn_arrival(json_decode((string)($decodedCurrent['values'][$key]??'[]'),true))) $hasWithdrawn=true;
    if ($archive&&!$hasWithdrawn&&!empty($decodedCurrent['arrivalWithdrawalAudit'])) {echo json_encode(['ok'=>true,'alreadyApplied'=>true,'accountsUnchanged'=>true]).PHP_EOL;exit;}
    $plan=arrival_withdrawal_plan(json_decode($before,true,512,JSON_THROW_ON_ERROR),json_decode($accountsRaw,true,512,JSON_THROW_ON_ERROR),gmdate('c'));
    if (!$apply) {echo json_encode(['ok'=>true,'dryRun'=>true,'counts'=>array_map('count',$plan['removed']),'accountsUnchanged'=>true]).PHP_EOL;exit;}
    foreach (['operations'=>hash('sha256',$before),'accounts'=>$accountsHash] as $name=>$hash) {
        if (!hash_equals($hash,(string)($options['expect-'.$name]??''))) throw new DomainException('Reviewed '.$name.' snapshot changed. Run a new dependency audit.');
    }
    $after=json_encode($plan['operations'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    if ($archive) throw new RuntimeException('Recovery archive already exists for a different store.');
    if (!is_dir($archiveDir)&&!mkdir($archiveDir,0700)) throw new RuntimeException('Recovery folder unavailable.');
    $record=['at'=>gmdate('c'),'beforeSha256'=>hash('sha256',$before),'afterSha256'=>hash('sha256',$after),'accountsSha256'=>$accountsHash,'beforeRaw'=>$before,'removed'=>$plan['removed']];
    $encoded=json_encode($record,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$backup=fopen($archivePath,'x');
    if (!$backup) throw new RuntimeException('Exclusive recovery archive unavailable.');
    chmod($archivePath,0600);
    try {if (fwrite($backup,$encoded)!==strlen($encoded)||!fflush($backup)) throw new RuntimeException('Recovery archive write failed.');if (function_exists('fsync')) fsync($backup);} finally {fclose($backup);}
    if (!hash_equals(hash('sha256',$encoded),hash_file('sha256',$archivePath))) throw new RuntimeException('Recovery archive verification failed.');
    $write($op,$after);
    echo json_encode(['ok'=>true,'applied'=>true,'counts'=>array_map('count',$plan['removed']),'removedKg'=>119500,'removedBags'=>1300,'accountsUnchanged'=>hash_equals($accountsHash,hash_file('sha256',$private.'/accounts.json')),'recovery'=>'arrival-recovery/withdrawal-20261017.json','afterSha256'=>hash('sha256',$after)]).PHP_EOL;
} catch (Throwable $e) {fwrite(STDERR,'Withdrawal blocked: '.$e->getMessage().PHP_EOL);exit(1);}
finally {foreach ([$ac,$op] as $h) if (is_resource($h)) {flock($h,LOCK_UN);fclose($h);}}
