<?php
declare(strict_types=1);
require __DIR__ . '/auth_store.php';
$user = tt_require_login();
$modules = [
    'milling' => __DIR__ . '/milling/Transtrade_Master_Milling_V3_3_5_WORKING.html',
    'exports' => __DIR__ . '/exports/Transtrade_Exports_Master_Prototype_V2_6_Final_Stabilized.html',
];
$id = strtolower((string)($_GET['id'] ?? ''));
if (!isset($modules[$id]) || !is_file($modules[$id])) { http_response_code(404); exit('Module not found.'); }
$permissionName = $id === 'milling' ? 'Mill' : 'Exports';
if (!tt_user_can_open_module($user, $permissionName)) { http_response_code(403); exit('You do not have permission to open this module.'); }
header('Content-Type: text/html; charset=UTF-8');
readfile($modules[$id]);
