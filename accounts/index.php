<?php
declare(strict_types=1);
require __DIR__ . '/../auth_store.php';
require_once __DIR__.'/../inventory_reconciliation.php';
require_once __DIR__ . '/../runtime_html.php';
$user = tt_require_login();
if (!tt_user_can_open_module($user, 'Accounts')) {
    http_response_code(403);
    exit('You do not have permission to open this module.');
}
$allowedEntities = tt_user_accounts_entities($user);
if (!$allowedEntities) {
    http_response_code(403);
    exit('No Accounts legal entity has been assigned to this user.');
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
    'entities' => $allowedEntities,
    'canInventoryReconciliation'=>tt_inv_can_report($user), 'masterAccess' => tt_user_can_access_masters($user),
    'masterPermissions' => $user['master_permissions'] ?? [],
    'masters' => tt_list_masters(),
];
$bootstrap = '<style id="tt-accounts-boot-style">html.tt-accounts-boot .topbar,html.tt-accounts-boot .shell{visibility:hidden}html.tt-accounts-boot body:before{content:"Loading Accounts…";position:fixed;inset:0;display:grid;place-items:center;background:#eef2f6;color:#102a46;font:700 15px Arial;z-index:99999}</style><script>document.documentElement.classList.add("tt-accounts-boot");window.TT_ACCOUNT_ACCESS=' . json_encode($access, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) . ';</script>';
$html = tt_replace_html_once(
    '/<head(\s[^>]*)?>/i',
    static fn(array $match): string => $match[0] . $bootstrap . '<script src="/offline-outbox.js?v=20260924-explicit-actions-1"></script><link rel="stylesheet" href="/brand-theme.css?v=20260913-2">',
    $html
);
$html = tt_replace_html_once(
    '/<\/body>/i',
    static fn(): string => '<script src="app-bundle.php?v=current"></script><script src="/brand-theme.js?v=20260913-2"></script></body>',
    $html
);
echo $html;
