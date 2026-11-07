<?php
declare(strict_types=1);
$reason = ($_GET['reason'] ?? '') === 'inactive' ? '?expired=inactive' : '';

// Signing out must work even if the account store or audit trail is temporarily
// unavailable. The audit is best effort; clearing the browser session is not.
try {
    require __DIR__ . '/auth_store.php';
    $user = tt_current_user();
    if ($user) tt_audit((int)$user['id'], (string)$user['username'], 'Signed out');
} catch (Throwable $error) {
    error_log('Transtrade sign-out audit unavailable: ' . get_class($error));
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('TRANSTRADE_SESSION');
    session_start();
}
$_SESSION = [];
setcookie('TRANSTRADE_SESSION', '', time() - 42000, '/', '',
    !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off', true);
session_destroy();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Location: /login.php' . $reason, true, 303);
exit;
