<?php
declare(strict_types=1);
require __DIR__ . '/auth_store.php';
header('Cache-Control: no-store, no-cache, must-revalidate, private');
if (!tt_has_admin()) { header('Location: setup.php'); exit; }
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $code=(string)($_POST['recovery_code'] ?? '');
    $password=(string)($_POST['password'] ?? '');
    $confirm=(string)($_POST['confirm_password'] ?? '');
    if (!tt_verify_csrf((string)($_POST['csrf'] ?? ''))) $error='Your recovery session expired. Refresh and try again.';
    elseif (!tt_admin_recovery_configured()) $error='Offline recovery is not configured. Sign in normally and create a new code from Recovery Security.';
    elseif (($retry=max(tt_auth_retry_after('admin-recovery-address','super-admin',4,1800,true),tt_auth_retry_after('admin-recovery-emergency','super-admin',20,1800,false)))>0) $error='Too many unsuccessful recovery attempts. Please wait '.max(1,(int)ceil($retry/60)).' minute(s) and try again.';
    elseif (!tt_recovery_code_valid($code)) { tt_auth_record_failure('admin-recovery-address','super-admin',4,1800,1800,true);tt_auth_record_failure('admin-recovery-emergency','super-admin',20,1800,3600,false);usleep(500000);$error='The recovery code is incorrect.'; }
    elseif (strlen($password)<10 || !preg_match('/[A-Z]/',$password) || !preg_match('/[a-z]/',$password) || !preg_match('/\d/',$password)) $error='Use at least 10 characters with an uppercase letter, lowercase letter and number.';
    elseif ($password!==$confirm) $error='The two passwords do not match.';
    else {
        tt_auth_clear_failures('admin-recovery-address','super-admin',true);
        $id=tt_reset_admin_with_recovery($password);
        $admin=tt_find_user_by_id($id); if(!$admin)throw new RuntimeException('Super Admin account not found.');
        tt_bind_user_session($admin); tt_audit($id,(string)($admin['username'] ?? 'salman'),'Super Admin password recovered with offline code');
        header('Location: index.php'); exit;
    }
}
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Super Admin recovery</title><link rel="stylesheet" href="admin/auth.css?v=20260907-2"></head><body><main class="auth-card"><div class="brand">TT</div><p class="eyebrow">OFFLINE RECOVERY</p><h1>Reset Super Admin password</h1><p class="intro">Enter Salman’s saved offline recovery code and create a new private password.</p><?php if($error):?><div class="error"><?=htmlspecialchars($error,ENT_QUOTES,'UTF-8')?></div><?php endif;?><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars(tt_csrf(),ENT_QUOTES,'UTF-8')?>"><label>Offline recovery code<input name="recovery_code" required autocomplete="off" autocapitalize="characters"></label><label>New password<input name="password" type="password" minlength="10" required autocomplete="new-password"></label><label>Confirm new password<input name="confirm_password" type="password" minlength="10" required autocomplete="new-password"></label><label class="show"><input type="checkbox" onclick="document.querySelectorAll('input[type=password]').forEach(x=>x.type=this.checked?'text':'password')"> Show password while typing</label><button type="submit">Reset password and enter Control Centre</button></form><p class="note"><a href="login.php">Return to sign in</a></p></main></body></html>
