<?php
declare(strict_types=1);

// Configuration regression only. Live web-server 403 checks are also required
// after deployment because Nginx does not read Apache/LiteSpeed .htaccess.
$root=dirname(__DIR__,2);
$rules=file_get_contents($root.'/.htaccess');
if($rules===false||!preg_match('/<FilesMatch "([^"]+)">\\s*Require all denied\\s*<\\/FilesMatch>/',$rules,$matches)){
    throw new RuntimeException('Direct helper access-denial rule is missing.');
}
$deny='~'.$matches[1].'~D';
foreach(['auth_store.php','master_store.php','session_store.php','qa_account.php','backup_lib.php',
         'owner_backup_jobs.php','office_backup_jobs.php','restore_jobs.php','office_backup_auth.php',
         'office_pc_pairing.php','index.html'] as $helper){
    if(preg_match($deny,$helper)!==1)throw new RuntimeException('Direct HTTP access is not denied for '.$helper);
}
foreach(['setup.php','recover-admin.php','restore.php','connect-backup-pc.php'] as $ownerRoute){
    if(preg_match($deny,$ownerRoute)===1)throw new RuntimeException('Owner recovery/login route unexpectedly blocked: '.$ownerRoute);
}
echo "PASS internal PHP helpers are denied directly without blocking authenticated recovery routes\\n";
