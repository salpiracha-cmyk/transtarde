<?php
declare(strict_types=1);
require __DIR__ . '/auth_store.php';
$user=tt_require_login(); $error='';
function tt_after_password_landing(array $user): string {
    if (($user['role'] ?? '')==='Super Admin') return 'index.php';
    if (tt_user_can_open_module($user,'Directors')) return 'directors/index.php';
    return tt_user_landing_url($user);
}
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $password=(string)($_POST['password'] ?? ''); $confirm=(string)($_POST['confirm_password'] ?? '');
    if (!tt_verify_csrf((string)($_POST['csrf'] ?? ''))) $error='Your session expired. Please refresh and try again.';
    elseif (strlen($password)<10 || !preg_match('/[A-Z]/',$password) || !preg_match('/[a-z]/',$password) || !preg_match('/\d/',$password)) $error='Use at least 10 characters with an uppercase letter, lowercase letter and number.';
    elseif ($password!==$confirm) $error='The two passwords do not match.';
    else { tt_change_own_password((int)$user['id'],$password); tt_audit((int)$user['id'],$user['username'],'Password changed'); header('Location: '.tt_after_password_landing($user)); exit; }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Change password</title><link rel="stylesheet" href="admin/auth.css?v=20260907-1"></head><body><main class="auth-card"><div class="brand">TT</div><p class="eyebrow">PASSWORD SECURITY</p><h1>Create your private password</h1><p class="intro">Welcome, <?=htmlspecialchars((string)$user['full_name'],ENT_QUOTES,'UTF-8')?>. Enter a new private password for your Transtrade login.</p><?php if($error):?><div class="error"><?=htmlspecialchars($error,ENT_QUOTES,'UTF-8')?></div><?php endif;?><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars(tt_csrf(),ENT_QUOTES,'UTF-8')?>"><label>New password<input name="password" type="password" minlength="10" required autocomplete="new-password"></label><label>Confirm password<input name="confirm_password" type="password" minlength="10" required autocomplete="new-password"></label><label class="show"><input type="checkbox" onclick="document.querySelectorAll('input[type=password]').forEach(x=>x.type=this.checked?'text':'password')"> Show password while typing</label><button type="submit">Save password and continue</button></form><p class="note">Use at least 10 characters with uppercase, lowercase and a number.</p></main></body></html>
