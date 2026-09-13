<?php
declare(strict_types=1);
require __DIR__ . '/auth_store.php';
require_once __DIR__ . '/qa_account.php';
if (!tt_has_admin()) { header('Location: setup.php'); exit; }
tt_ensure_qa_account();
if ($current=tt_current_user()) { header('Location: ' . tt_user_landing_url($current)); exit; }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = strtolower(trim($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if (!tt_verify_csrf((string)($_POST['csrf'] ?? ''))) $error = 'Your login session expired. Please refresh and try again.';
    else {
        $user = tt_find_user_by_username($username);
        if ($user && !empty($user['active']) && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true); $_SESSION['user_id'] = (int)$user['id'];
            tt_set_last_login((int)$user['id']);
            tt_audit((int)$user['id'], $user['username'], 'Signed in');
            header('Location: ' . (!empty($user['must_change_password']) ? 'change-password.php' : tt_user_landing_url($user))); exit;
        }
        tt_audit($user ? (int)$user['id'] : null, $username ?: 'unknown', 'Failed sign-in');
        usleep(350000); $error = 'Incorrect username or password.';
    }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Transtrade Login</title><link rel="stylesheet" href="admin/auth.css?v=20260907-1"><link rel="stylesheet" href="/brand-theme.css?v=20260913-2"></head><body>
<main class="auth-card"><div class="authBrandLockup"><img class="authBrandLogo" src="/exports/assets/TTI_header.png" alt="TransTrade International"></div><p class="eyebrow">TRANSTRADE INTERNATIONAL</p><h1>Sign in</h1><p class="intro">Enter your Transtrade username and password.</p><?php if ($error): ?><div class="error"><?=htmlspecialchars($error, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars(tt_csrf(), ENT_QUOTES, 'UTF-8')?>"><label>Username<input name="username" required autocomplete="username" autofocus></label><label>Password<input name="password" type="password" required autocomplete="current-password"></label><button type="submit">Sign in</button></form><p class="note"><a href="recover-admin.php">Super Admin password recovery</a></p></main><script src="/global-validation.js?v=20260909-1"></script></body></html>
