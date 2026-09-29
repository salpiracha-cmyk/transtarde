<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/auth_store.php';

function mp_check(bool $condition,string $message): void { if (!$condition) throw new RuntimeException($message); }

$legacy=[
    'id'=>11,'username'=>'legacy.exports','role'=>'Exports','permissions'=>['Exports'=>['contracts'=>['View']]],
    'master_access'=>false,'master_permissions'=>[],
];
$historicalActions=['Use','View','Create','Edit','Deactivate','View Documents','Download Documents'];
$data=['users'=>[$legacy,['id'=>1,'username'=>'salman','role'=>'Super Admin','permissions'=>[]]]];
$changed=tt_migrate_legacy_master_permissions($data);
mp_check(count($changed)===1,'One legacy staff account must be migrated.');
$migrated=$data['users'][0];
mp_check($migrated['master_access']===true,'Migrated access must become explicit.');
foreach ($historicalActions as $action) mp_check(tt_user_can_master($migrated,'export_customers',$action)===true,'Migration did not preserve historical '.$action.' access.');
mp_check(tt_user_can_master($migrated,'companies','View')===false,'Migration must not broaden module scope.');
mp_check(tt_user_can_master($data['users'][1],'companies','Deactivate')===true,'Super Admin must remain unrestricted.');
mp_check(tt_migrate_legacy_master_permissions($data)===[],'Migration must be idempotent.');

$newIncomplete=['role'=>'Exports','permissions'=>['Exports'=>['contracts'=>['View']]],'master_access'=>false,'master_permissions'=>[]];
mp_check(tt_user_can_master($newIncomplete,'export_customers','Use')===true,'Incomplete account must retain operational use.');
mp_check(tt_user_can_master($newIncomplete,'export_customers','View')===true,'Incomplete account must retain scoped view.');
foreach (['Create','Edit','Deactivate','View Documents','Download Documents'] as $action) {
    mp_check(tt_user_can_master($newIncomplete,'export_customers',$action)===false,'Incomplete account must not inherit '.$action.'.');
}
mp_check(tt_user_can_master($newIncomplete,'companies','View')===false,'Incomplete account must remain module-scoped.');

$explicit=['role'=>'Mill Staff','permissions'=>['Mill'=>['stock'=>['View']]],'master_access'=>true,'master_permissions'=>['purchase_products'=>['View']]];
mp_check(tt_user_can_master($explicit,'purchase_kat','View')===true,'KAT must follow explicit purchase product permission.');
mp_check(tt_user_can_master($explicit,'purchase_kat','Edit')===false,'Explicit view-only KAT access must remain view-only.');

echo "PASS Master permission migration preserves existing access and hardens future fallback\n";
