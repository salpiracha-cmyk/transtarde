<?php
declare(strict_types=1);
require __DIR__ . '/auth_store.php';
if (!tt_has_admin()) {
    header('Location: setup.php');
    exit;
}
$user = tt_require_login();
if (!empty($user['must_change_password'])) { header('Location: change-password.php'); exit; }
if (($user['role'] ?? '') !== 'Super Admin') { header('Location: '.tt_user_landing_url($user)); exit; }
header('Content-Type: text/html; charset=UTF-8');
$html = file_get_contents(__DIR__ . '/index.html');
$session = [
    'id'=>(string)$user['id'], 'name'=>(string)$user['full_name'],
    'username'=>(string)$user['username'], 'role'=>(string)$user['role'],
    'permissions'=>$user['permissions'] ?? [], 'csrf'=>tt_csrf(),
];
$bootstrap = '<script>window.TT_SESSION=' . json_encode($session, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) . ';</script>';
$adminApp = '<script src="admin/app.js?v=20260913-product-identity-1"></script>';
$customerMaster = '<script src="/customer-master.js?v=20260909-1"></script>';
$release = '<script src="/customer-contract-options.js?v=20260909-1"></script><script src="/global-validation.js?v=20260909-1"></script>';
echo str_replace($adminApp, $bootstrap . $adminApp . $customerMaster . $release, (string)$html);
