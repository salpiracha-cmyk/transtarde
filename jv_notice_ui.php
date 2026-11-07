<?php
declare(strict_types=1);
/** Attach the same private notification feed to an authorised user's home. */
function tt_jv_notice_ui(array $user,string $homeSelector):string {
    if(!tt_user_can_open_module($user,'Accounts')||!tt_user_can_module_action($user,'Accounts','jv','View'))return '';
    $access=['permissions'=>$user['permissions']['Accounts']??[],'super'=>($user['role']??'')==='Super Admin','csrf'=>tt_csrf(),'entities'=>tt_user_accounts_entities($user),'homeSelector'=>$homeSelector];
    return '<script>window.TT_JV_NOTICE_ACCESS='.json_encode($access,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';</script><script src="/accounts/jv-notices-ui.js"></script>';
}
