<?php
declare(strict_types=1);
require __DIR__ . '/../auth_store.php';
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
];
$bootstrap = '<script>window.TT_ACCOUNT_ACCESS=' . json_encode($access, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) . ';</script>';
$html = preg_replace('/<head(\s[^>]*)?>/i', '$0' . $bootstrap, $html, 1) ?? $html;
$html = preg_replace('/<\/body>/i', '<script src="app-bundle.php?v=20260912-7"></script></body>', $html, 1) ?? $html;
echo $html;

