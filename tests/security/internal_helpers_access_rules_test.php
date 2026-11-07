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
         'bag_arrangement_core.php','journal_activity_core.php','owner_backup_jobs.php','office_backup_jobs.php','restore_jobs.php','office_backup_auth.php',
         'office_pc_pairing.php','index.html','salary_master_seed_v1.json','accounting_master_v1.json','costing-profiles.json','settlement_policy_v1.json','export_realization_policy_v1.json','local_sales_policy_v1.json','revenue_policy_v1.json','tg_currency_policy_v1.json'] as $helper){
    if(preg_match($deny,$helper)!==1)throw new RuntimeException('Direct HTTP access is not denied for '.$helper);
}
foreach(['setup.php','recover-admin.php','restore.php','connect-backup-pc.php'] as $ownerRoute){
    if(preg_match($deny,$ownerRoute)===1)throw new RuntimeException('Owner recovery/login route unexpectedly blocked: '.$ownerRoute);
}
echo "PASS internal PHP helpers are denied directly without blocking authenticated recovery routes\n";

require_once $root.'/api/salary_master_store.php';
$seed=sm_seed_masters();if(!$seed||sm_default_store()['salaryMasters']!==$seed)throw new RuntimeException('Protected payroll seed must remain readable by Salary Master initialization.');
echo 'PASS payroll seed is denied directly while filesystem initialization and protected accounting configuration remain readable by PHP'.PHP_EOL;

foreach(array_merge(glob($root.'/accounts/*_policy_v1.json'),[$root.'/accounts/accounting_master_v1.json',$root.'/directors/costing-profiles.json']) as $file){$decoded=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);if(!is_array($decoded)||!$decoded)throw new RuntimeException('Protected configuration unreadable: '.basename($file));}
echo "PASS all protected accounting policies, master and costing profiles remain readable through the filesystem\n";
