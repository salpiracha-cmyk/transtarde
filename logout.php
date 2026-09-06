<?php
declare(strict_types=1);
require __DIR__ . '/auth_store.php';
$user = tt_current_user();
if ($user) tt_audit((int)$user['id'], $user['username'], 'Signed out');
$_SESSION = [];
if (ini_get('session.use_cookies')) { $p = session_get_cookie_params(); setcookie(session_name(), '', time()-42000, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']); }
session_destroy();
header('Location: login.php');
