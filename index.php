<?php
declare(strict_types=1);
require __DIR__ . '/auth_store.php';
require_once __DIR__ . '/owner_master_cleanup.php';
if (!tt_has_admin()) {
    header('Location: setup.php');
    exit;
}
$user = tt_require_login();
if (!empty($user['must_change_password'])) { header('Location: change-password.php'); exit; }
if (($user['role'] ?? '') !== 'Super Admin' && !tt_user_can_access_masters($user)) { header('Location: '.tt_user_landing_url($user)); exit; }
if (($user['role'] ?? '') !== 'Super Admin' && ($_GET['view'] ?? '') !== 'masters') { header('Location: '.tt_user_landing_url($user)); exit; }
if (($user['role'] ?? '') === 'Super Admin') tt_apply_owner_master_cleanup();
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-LiteSpeed-Cache-Control: no-cache');
$html = file_get_contents(__DIR__ . '/index.html');
$session = [
    'id'=>(string)$user['id'], 'name'=>(string)$user['full_name'],
    'username'=>(string)$user['username'], 'role'=>(string)$user['role'],
    'permissions'=>$user['permissions'] ?? [], 'csrf'=>tt_csrf(),
    'masterAccess'=>tt_user_can_access_masters($user),
    'masterControlled'=>!empty($user['master_access']),
    'masterPermissions'=>$user['master_permissions'] ?? [],
];
$bootstrap = '<script>window.TT_SESSION=' . json_encode($session, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) . ';</script>';
$customerMaster = '<script src="/customer-master.js?v=20260909-1"></script>';
$release = '<script src="/customer-contract-options.js?v=20260909-1"></script><script src="/global-validation.js?v=20260909-1"></script>';
$html = str_replace('</head>', $bootstrap . '</head>', (string)$html);
echo str_replace('</body>', $customerMaster . $release . '</body>', $html);
