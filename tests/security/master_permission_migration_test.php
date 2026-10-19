<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/auth_store.php';

function mp_check(bool $condition,string $message): void { if (!$condition) throw new RuntimeException($message); }

$defaults=tt_default_masters();
$defaults['companies'][0]['values'][0]='Test mutation';
mp_check(tt_default_masters()['companies'][0]['values'][0]==='Transtrade International','Cached Master defaults must be isolated from caller mutations.');
$options=tt_default_master_options();
$options['currencies'][0]='Test mutation';
mp_check(tt_default_master_options()['currencies'][0]==='USD','Cached Master options must be isolated from caller mutations.');

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

$director=['id'=>44,'role'=>'Director','permissions'=>['Accounts'=>['entity-tti'=>['View']]],'location'=>'Karachi Office','master_access'=>true,'master_permissions'=>['companies'=>[]]];
$original=$director;$effective=tt_user_business_access($director);
mp_check($director===$original && $effective['role']==='Director','Effective role access must not mutate stored identity or become Super Admin.');
mp_check($effective['permissions']===array_fill_keys(['Mill','Exports','Accounts','Directors'],'all') && $effective['location']==='All authorized locations','Director must cover every business workspace and location.');
foreach(['Mill','Exports','Accounts','Directors'] as $module)foreach(['View','Create','Edit','Approve','Reports','Delete'] as $action)mp_check(tt_user_can_module_action($director,$module,'future-business-screen',$action),'Director role must supply '.$module.'/'.$action);
foreach(['TTI','BRM','TG'] as $entity)foreach(['View','Create','Edit','Approve','Reports','Delete'] as $action)mp_check(tt_user_can_access_entity($director,$entity,$action),'Director company action missing.');
foreach(tt_director_master_permissions() as $type=>$actions)foreach($actions as $action)mp_check(tt_user_can_master($director,$type,$action),'Director Master action missing: '.$type.'/'.$action);
foreach(['salary_staff','reference_lists','companies','banks','purchase_kat','business_parties','export_realization_charges'] as $type)mp_check(tt_user_can_master($director,$type,'Edit'),'Director virtual/operational Master missing: '.$type);
mp_check(!tt_user_can_open_module($director,'Security') && !tt_user_can_module_action($director,'Accounts','cashbank','Manage Users') && !tt_user_can_access_entity($director,'INVALID'),'Role must not invent owner/system access or invalid books.');
$data=['users'=>[$director]];mp_check(tt_migrate_legacy_master_permissions($data)===[] && $data['users'][0]===$original,'Migration must not materialise role rights.');
$demoted=$director;$demoted['role']='Accounts';mp_check(!tt_user_can_open_module($demoted,'Exports') && !tt_user_can_access_entity($demoted,'BRM') && !tt_user_can_module_action($demoted,'Accounts','cashbank','Delete'),'Demotion must remove automatic rights.');
mp_check(tt_user_business_access($explicit)===$explicit,'Ordinary staff effective access must remain unchanged.');

echo "PASS Master permission migration preserves existing access and hardens future fallback\n";
