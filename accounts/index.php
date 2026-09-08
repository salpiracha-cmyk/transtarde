<?php
declare(strict_types=1);
require __DIR__ . '/../auth_store.php';
$user = tt_require_login();
if (!tt_user_can_open_module($user, 'Accounts')) {
    http_response_code(403);
    exit('You do not have permission to open this module.');
}
$file = __DIR__ . '/Transtrade_Accounts_Master_V1.html';
if (!is_file($file)) {
    http_response_code(404);
    exit('Accounts module not found.');
}
header('Content-Type: text/html; charset=UTF-8');
$html = (string)file_get_contents($file);
$access = [
    'module' => 'Accounts',
    'user' => (string)($user['full_name'] ?? ''),
    'role' => (string)($user['role'] ?? ''),
    'permissions' => $user['permissions']['Accounts'] ?? [],
    'super' => (($user['role'] ?? '') === 'Super Admin'),
    'csrf' => tt_csrf(),
];
$bootstrap = '<script>window.TT_ACCOUNT_ACCESS=' . json_encode($access, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) . ';</script>';
$html = preg_replace('/<head(\s[^>]*)?>/i', '$0' . $bootstrap, $html, 1) ?? $html;
$html = preg_replace('/<\/body>/i', '<script src="accounts-live.js?v=20260907-3"></script><script src="accounts-enhancements.js?v=20260907-1"></script><script src="export-realization-master-ui.js?v=20260908-1"></script><script src="export-tax-certificates-ui.js?v=20260908-1"></script><script src="export-tax-certificates-fix.js?v=20260908-1"></script><script src="bank-accounts-ui.js?v=20260908-2"></script><script src="bill-smart-ui-v2.js?v=20260908-2"></script><script src="bill-payment-terms.js?v=20260908-1"></script><script src="supplier-payment-planning-ui.js?v=20260908-1"></script><script src="payment-planning-bank-funds.js?v=20260908-1"></script><script src="supplier-settlement-ui.js?v=20260908-3"></script><script src="local-sales-control-ui.js?v=20260908-2"></script><script src="export-recognition-ui.js?v=20260908-1"></script></body>', $html, 1) ?? $html;
echo $html;