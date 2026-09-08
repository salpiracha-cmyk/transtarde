<?php
declare(strict_types=1);
require __DIR__ . '/auth_store.php';
if (!tt_has_admin()) {
    header('Location: setup.php');
    exit;
}
$user = tt_require_login();
if (!empty($user['must_change_password'])) { header('Location: change-password.php'); exit; }
if (($user['role'] ?? '') !== 'Super Admin') {
    header('Location: '.(tt_user_can_open_module($user,'Directors') ? 'directors/index.php' : tt_user_landing_url($user)));
    exit;
}
header('Content-Type: text/html; charset=UTF-8');
$html = file_get_contents(__DIR__ . '/index.html');
$session = [
    'id'=>(string)$user['id'], 'name'=>(string)$user['full_name'],
    'username'=>(string)$user['username'], 'role'=>(string)$user['role'],
    'permissions'=>$user['permissions'] ?? [], 'csrf'=>tt_csrf(),
];
$bootstrap = '<script>window.TT_SESSION=' . json_encode($session, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) . ';</script>';
$replace = $bootstrap . '<script src="admin/app.js?v=20260907-3"></script><script src="admin/accounts-override.js?v=20260907-1"></script><script src="admin/directors-permissions-override.js?v=20260908-1"></script><script src="admin/export-realization-master-override.js?v=20260908-2"></script><script src="admin/tg-currency-master-override.js?v=20260908-2"></script>';
echo str_replace('<script src="admin/app.js?v=20260907-3"></script>', $replace, (string)$html);