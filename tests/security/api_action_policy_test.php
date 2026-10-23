<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/auth_store.php';
$count=0;
function action_assert(bool $condition,string $message):void {global $count;$count++;if(!$condition)throw new RuntimeException($message);}
function action_user(string $module,string $icon,array $iconRights,array $entityRights):array {
    $permissions=[$module=>[$icon=>array_merge(['View'],$iconRights)]];
    if($module==='Accounts')$permissions['Accounts']['entity-tti']=array_merge(['View'],$entityRights);
    return ['role'=>'Staff','id'=>1,'permissions'=>$permissions,'master_access'=>true,'master_permissions'=>['salary_staff'=>['Use','View','Create','Edit','Deactivate']]];
}
$cases=[
    ['donations.php',['action'=>'record_donation'],'expenses',['Create']],
    ['commodity_bills.php',['action'=>'verify_bill'],'purchases',['Create']],
    ['export_receipts.php',['action'=>'post_receipt'],'customer',['Create']],
    ['export_receipts.php',['action'=>'post_receipt','amendmentOf'=>'R1'],'customer',['Edit']],
    ['supplier_settlements.php',['action'=>'post_bill_payment'],'supplier',['Create']],
    ['supplier_settlements.php',['action'=>'amend_bill_payment'],'supplier',['Edit']],
    ['production_costing.php',['action'=>'approve_rate'],'purchases',['Approve']],
    ['production_costing.php',['action'=>'save_byproduct_value'],'purchases',['Create']],
    ['production_inventory_transfer.php',['action'=>'post_transfer'],'purchases',['Create']],
    ['production_fixed_overhead.php',['action'=>'apply_to_run'],'purchases',['Edit']],
    ['bank_accounts.php',['action'=>'save_settings'],'masters',['Edit']],
    ['bank_accounts.php',['action'=>'save_deposit_type'],'masters',['Edit']],
    ['bank_accounts.php',['action'=>'request_delete'],'masters',['Create']],
    ['bank_entries.php',['action'=>'post','operation'=>'edit'],'cashbank',['Create']],
    ['bank_entries.php',['action'=>'facility','operation'=>'edit'],'cashbank',['Edit']],
    ['bank_entries.php',['action'=>'facility','operation'=>'delete'],'cashbank',['Delete']],
    ['accounts_subaccounts.php',['operation'=>'add'],'masters',['Create']],
    ['accounts_subaccounts.php',['operation'=>'move_head'],'masters',['Edit']],
    ['accounts_subaccounts.php',['operation'=>'delete'],'masters',['Delete']],
    ['bank_direct_entries.php',['type'=>'SAVING_PROFIT'],'cashbank',['Create']],
    ['bank_direct_entries.php',['type'=>'SAVING_PROFIT'],'reconciliation',['Create']],
    ['rent_salary_v2.php',['action'=>'salary_payment'],'expenses',['Create']],
    ['rent_salary.php',['action'=>'salary_payment'],'expenses',['Create']],
    ['rent_salary_v2.php',['action'=>'post_salary_group'],'expenses',['Create']],
    ['rent_salary_v2.php',['action'=>'close_salary_month'],'expenses',['Edit']],
    ['rent_salary_v2.php',['action'=>'amend_salary_allocated_payment'],'expenses',['Edit']],
    ['rent_salary_v2.php',['action'=>'save_salary_master'],'expenses',['Create']],
    ['rent_salary_v2.php',['action'=>'update_salary_master'],'expenses',['Edit']],
    ['expenses_v1.php',['action'=>'pay_expense'],'expenses',['Create']],
    ['expenses_v1.php',['action'=>'amend_expense'],'expenses',['Edit']],
    ['expenses_v1.php',['action'=>'cancel_expense_invoice'],'expenses',['Edit']],
    ['payment_plans.php',['action'=>'post'],'supplier',['Create']],
    ['payment_plans.php',['action'=>'close'],'supplier',['Edit']],
    ['local_sales_control.php',['action'=>'approve_candidate'],'customer',['Edit']],
    ['local_sales_payments.php',['action'=>'approve_payment'],'customer',['Edit']],
    ['tg_bank_transactions.php',['action'=>'post_payment'],'cashbank',['Create']],
    ['tg_remittances.php',['action'=>'reopen'],'tg',['Edit']],
    ['accounts.php',['action'=>'save_reminder'],'expenses',['Create']],
];
foreach($cases as [$endpoint,$body,$icon,$expected]){
    $body['entity']='TTI';
    foreach(['Create','Edit','Approve','Delete'] as $right){
        $user=action_user('Accounts',$icon,[$right],[$right]);
        action_assert(tt_api_icon_write_allowed($user,$endpoint,$body)===in_array($right,$expected,true),$endpoint.' '.json_encode($body).' '.$right);
        foreach(['Create','Edit','Approve','Delete'] as $other)if($other!==$right){
            $user=action_user('Accounts',$icon,[$right],[$other]);
            action_assert(!tt_api_icon_write_allowed($user,$endpoint,$body),$endpoint.' borrowed entity '.$other.' for icon '.$right);
        }
    }
    foreach(['Super Admin','Director'] as $role)action_assert(tt_api_icon_write_allowed(['role'=>$role],$endpoint,$body),$role.' lost authorized business action '.$endpoint);
}
foreach([
    ['export_tax_certificates.php',['action'=>'save_certificate'],'reports'],
    ['accounts_workflows_v1.php',['action'=>'save_soda'],'purchases'],
    ['accounts_workflows_v1.php',['action'=>'register_loading_program'],'transport'],
    ['bank_reconciliation.php',[],'reconciliation'],
    ['expenses_v1.php',['action'=>'save_card_statement'],'expenses'],
    ['rent_salary_v2.php',['action'=>'save_salary_transactions'],'expenses'],
] as [$endpoint,$body,$icon]){
    $body['entity']='TTI';
    foreach([false,true] as $exists)foreach(['Create','Edit','Approve'] as $right){
        $user=action_user('Accounts',$icon,[$right],[$right]);
        action_assert(tt_api_upsert_allowed($user,$endpoint,$body,$exists,'TTI')===($right===($exists?'Edit':'Create')),$endpoint.' target lifecycle '.$right);
        action_assert(!tt_api_upsert_allowed($user,$endpoint,$body,$exists,'BRM'),$endpoint.' accepted conflicting target company');
    }
}
foreach([['Exports','loading','register_loading_program'],['Directors','freight','save_freight_agreement']] as [$module,$icon,$action]){
    foreach(['Create','Edit'] as $right){$user=action_user($module,$icon,[$right],[]);action_assert(tt_api_upsert_allowed($user,'accounts_workflows_v1.php',['action'=>$action,'entity'=>'TTI'],$right==='Edit','TTI'),$module.' source workflow was forced through unrelated Accounts grants');}
}
foreach(['Create','Edit','Approve'] as $right){
    $user=['role'=>'Staff','permissions'=>['Accounts'=>['View',$right]]];
    action_assert(tt_api_icon_write_allowed($user,'donations.php',['entity'=>'TTI'])===($right==='Create'),'Legacy flat '.$right.' lost its exact action');
}
$savedGet=$_GET;$_GET=['area'=>'costing'];
action_assert(tt_api_write_grants('directors_trading.php',['action'=>'preview_costing'])===[['Directors','costing','View']],'Automatic costing preview must use View rather than a posting grant');
action_assert(tt_api_write_grants('directors_trading.php',['action'=>'save_costing'])===[['Directors','costing','Create']],'Save remains an explicit Create action');
$_GET=$savedGet;
echo 'PASS action policy: '.$count." exact action, company pairing, upsert, alternative-owner and owner-role checks; no storage writes.\n";
