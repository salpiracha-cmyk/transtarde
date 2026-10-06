<?php
declare(strict_types=1);
require __DIR__ . '/auth_store.php';
require_once __DIR__ . '/qa_account.php';
header('Cache-Control: no-store, no-cache, must-revalidate, private');
if (!tt_has_admin()) { header('Location: setup.php'); exit; }
tt_ensure_qa_account();
if ($current=tt_current_user()) { header('Location: ' . tt_user_landing_url($current)); exit; }
if (session_status()!==PHP_SESSION_ACTIVE) { session_id(''); session_start(); }
$error = '';
$notice=match((string)($_GET['expired']??'')){
    'inactive'=>'You were signed out after one hour without activity.',
    'credentials'=>'Your login was ended because the account password or access status changed.',
    default=>'',
};
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = strtolower(trim($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $rawIdentity=$username!==''?$username:'unknown';
    $rateIdentity=function_exists('mb_substr')?mb_substr($rawIdentity,0,80):substr($rawIdentity,0,80);
    if (!tt_verify_csrf((string)($_POST['csrf'] ?? ''))) $error = 'Your login session expired. Please refresh and try again.';
    elseif (($retry=max(
        tt_auth_retry_after('login-account',$rateIdentity,10,900,false),
        tt_auth_retry_after('login-address','all-users',20,900,true)
    ))>0) $error='Too many unsuccessful sign-in attempts. Please wait '.max(1,(int)ceil($retry/60)).' minute(s) and try again.';
    else {
        // Shared attack traffic may slow a check, but cannot lock every valid
        // account. Account and address limits above remain hard boundaries.
        if (tt_auth_retry_after('login-emergency','all-users',120,900,false)>0) usleep(350000);
        $user = tt_find_user_by_username($username);
        $passwordValid=password_verify($password,(string)($user['password_hash']??TT_LOGIN_DUMMY_HASH));
        if ($user && !empty($user['active']) && $passwordValid) {
            tt_auth_clear_failures('login-account',$rateIdentity,false);
            tt_bind_user_session($user);
            tt_set_last_login((int)$user['id']);
            tt_audit((int)$user['id'], $user['username'], 'Signed in');
            header('Location: ' . (!empty($user['must_change_password']) ? 'change-password.php' : tt_user_landing_url($user))); exit;
        }
        tt_auth_record_failure('login-account',$rateIdentity,10,900,900,false);
        tt_auth_record_failure('login-address','all-users',20,900,900,true);
        tt_auth_record_failure('login-emergency','all-users',120,900,1800,false);
        tt_audit($user ? (int)$user['id'] : null, $username ?: 'unknown', 'Failed sign-in');
        usleep(350000); $error = 'Incorrect username or password.';
    }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Transtrade Login</title><link rel="stylesheet" href="admin/auth.css?v=20260907-1"><link rel="stylesheet" href="/brand-theme.css?v=20260913-3"></head><body>
<main class="auth-card"><div class="authBrandName">TRANSTRADE INTERNATIONAL</div><h1>Sign in</h1><p class="intro">Enter your Transtrade username and password.</p><?php if ($notice): ?><div class="success"><?=htmlspecialchars($notice, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?><?php if ($error): ?><div class="error"><?=htmlspecialchars($error, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars(tt_csrf(), ENT_QUOTES, 'UTF-8')?>"><label>Username<input name="username" required autocomplete="username" autofocus></label><label>Password<input name="password" type="password" required autocomplete="current-password"></label><button type="submit">Sign in</button></form><?php if(tt_admin_recovery_configured()):?><p class="note"><a href="recover-admin.php">Super Admin password recovery</a></p><?php endif;?></main><script>try{localStorage.removeItem('tt_session_last_activity_v1')}catch(e){}</script><script src="/global-validation.js?v=20260909-1"></script></body></html>
