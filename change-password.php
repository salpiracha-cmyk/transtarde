<?php
declare(strict_types=1);
require __DIR__.'/auth_store.php';
$user=tt_require_login();
$error='';
header('Cache-Control: no-store, no-cache, must-revalidate, private');

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $current=(string)($_POST['current_password']??'');
    $password=(string)($_POST['password']??'');
    $confirm=(string)($_POST['confirm_password']??'');
    if (!tt_verify_csrf((string)($_POST['csrf']??''))) {
        $error='Your session expired. Please refresh and try again.';
    } elseif (($retry=tt_auth_retry_after('password-change',(string)$user['id'],5,900,true))>0) {
        $error='Too many unsuccessful password checks. Please wait '.max(1,(int)ceil($retry/60)).' minute(s) and try again.';
    } elseif (!password_verify($current,(string)($user['password_hash']??''))) {
        tt_auth_record_failure('password-change',(string)$user['id'],5,900,900,true);
        $error='Your current password is incorrect.';
    } elseif (strlen($password)<10||!preg_match('/[A-Z]/',$password)||!preg_match('/[a-z]/',$password)||!preg_match('/\d/',$password)) {
        $error='Use at least 10 characters with an uppercase letter, lowercase letter and number.';
    } elseif ($password!==$confirm) {
        $error='The two passwords do not match.';
    } elseif (password_verify($password,(string)($user['password_hash']??''))) {
        $error='Choose a new password that is different from your current password.';
    } else {
        tt_auth_clear_failures('password-change',(string)$user['id'],true);
        tt_change_own_password((int)$user['id'],$password);
        $updated=tt_find_user_by_id((int)$user['id']);
        if (!$updated) throw new RuntimeException('User not found.');
        tt_bind_user_session($updated);
        tt_audit((int)$user['id'],(string)$user['username'],'Password changed');
        header('Location: '.tt_user_landing_url($updated));
        exit;
    }
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Change password</title><link rel="stylesheet" href="admin/auth.css?v=20260907-1"></head>
<body><main class="auth-card"><div class="brand">TT</div><p class="eyebrow">PASSWORD SECURITY</p><h1>Change your private password</h1><p class="intro">Welcome, <?=htmlspecialchars((string)$user['full_name'],ENT_QUOTES,'UTF-8')?>. Confirm your current password, then enter a new private password.</p>
<?php if($error):?><div class="error"><?=htmlspecialchars($error,ENT_QUOTES,'UTF-8')?></div><?php endif;?>
<form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars(tt_csrf(),ENT_QUOTES,'UTF-8')?>"><label>Current password<input name="current_password" type="password" required autocomplete="current-password"></label><label>New password<input name="password" type="password" minlength="10" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{10,}" required autocomplete="new-password"></label><label>Confirm password<input name="confirm_password" type="password" minlength="10" required autocomplete="new-password"></label><label class="show"><input type="checkbox" onclick="document.querySelectorAll('input[type=password]').forEach(x=>x.type=this.checked?'text':'password')"> Show password while typing</label><button type="submit">Save password and continue</button></form><p class="note">Use at least 10 characters with uppercase, lowercase and a number.</p></main>
<script>window.TT_SESSION={csrf:<?=json_encode(tt_csrf(),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>};</script><script src="/session-activity.js?v=20260929-1"></script><script src="/global-validation.js?v=20260909-1"></script></body></html>
