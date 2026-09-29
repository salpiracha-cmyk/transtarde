<?php
declare(strict_types=1);
require __DIR__.'/auth_store.php';
$user=tt_require_login();
if(($user['role']??'')!=='Super Admin'){http_response_code(403);exit('Super Admin access required.');}
header('Cache-Control: no-store, no-cache, must-revalidate, private');
$error='';$code='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    $current=(string)($_POST['current_password']??'');
    if(!tt_verify_csrf((string)($_POST['csrf']??'')))$error='Your session expired. Refresh and try again.';
    elseif(($retry=tt_auth_retry_after('recovery-rotation',(string)$user['id'],5,900,true))>0)$error='Too many unsuccessful checks. Please wait '.max(1,(int)ceil($retry/60)).' minute(s) and try again.';
    elseif(!password_verify($current,(string)($user['password_hash']??''))){tt_auth_record_failure('recovery-rotation',(string)$user['id'],5,900,900,true);$error='Your current Super Admin password is incorrect.';}
    else{
        tt_auth_clear_failures('recovery-rotation',(string)$user['id'],true);
        $code=tt_rotate_admin_recovery_code();
        tt_audit((int)$user['id'],(string)$user['username'],'Rotated Super Admin offline recovery code');
    }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Recovery Security</title><link rel="stylesheet" href="admin/auth.css?v=20260907-2"></head><body><main class="auth-card"><div class="brand">TT</div><p class="eyebrow">SUPER ADMIN SECURITY</p><h1>Offline recovery code</h1><?php if($error):?><div class="error"><?=htmlspecialchars($error,ENT_QUOTES,'UTF-8')?></div><?php endif;?><?php if($code):?><div class="success"><strong>New recovery code created.</strong><p>Save this code offline now. It will not be shown again.</p><p style="font-family:monospace;font-size:16px;overflow-wrap:anywhere;user-select:all"><?=htmlspecialchars($code,ENT_QUOTES,'UTF-8')?></p></div><?php else:?><p class="intro">Create a fresh recovery code without changing your normal login password. The previous recovery code will stop working immediately.</p><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars(tt_csrf(),ENT_QUOTES,'UTF-8')?>"><label>Current Super Admin password<input name="current_password" type="password" required autocomplete="current-password"></label><button type="submit">Create and show new recovery code once</button></form><?php endif;?><p class="note"><a href="index.php">Return to Control Centre</a></p></main></body></html>
