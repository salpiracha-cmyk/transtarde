<?php
declare(strict_types=1);
require __DIR__ . '/auth_store.php';
if (tt_has_admin()) { header('Location: login.php'); exit; }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = strtolower(trim($_POST['username'] ?? 'salman'));
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    if (!tt_verify_csrf((string)($_POST['csrf'] ?? ''))) $error = 'Your setup session expired. Please refresh and try again.';
    elseif (!preg_match('/^[a-z0-9._-]{3,40}$/', $username)) $error = 'Use 3–40 letters, numbers, dots, dashes or underscores for the username.';
    elseif (strlen($password) < 10 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/\d/', $password)) $error = 'Use at least 10 characters with an uppercase letter, lowercase letter and number.';
    elseif ($password !== $confirm) $error = 'The two passwords do not match.';
    else {
        try {
            $id = tt_create_admin($username, $password);
            session_regenerate_id(true);
            $_SESSION['user_id'] = $id;
            tt_audit($id, $username, 'Super Admin account created');
            header('Location: index.php'); exit;
        } catch (Throwable $e) { $error = 'Setup could not be completed. Please contact the developer before trying again.'; }
    }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Set up Transtrade</title><link rel="stylesheet" href="admin/auth.css?v=20260907-1"></head><body>
<main class="auth-card"><div class="brand">TT</div><p class="eyebrow">TRANSTRADE INTERNATIONAL</p><h1>Create Super Admin password</h1><p class="intro">This one-time screen creates Salman’s private Super Admin login. The password is encrypted and is never shown to the developer.</p>
<?php if ($error): ?><div class="error"><?=htmlspecialchars($error, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?=htmlspecialchars(tt_csrf(), ENT_QUOTES, 'UTF-8')?>"><label>Username<input name="username" value="salman" required pattern="[a-zA-Z0-9._-]{3,40}" autocomplete="username"></label><label>New password<input id="password" name="password" type="password" required minlength="10" autocomplete="new-password"></label><label>Confirm password<input name="confirm_password" type="password" required minlength="10" autocomplete="new-password"></label><label class="show"><input type="checkbox" onclick="document.querySelectorAll('input[type=password]').forEach(x=>x.type=this.checked?'text':'password')"> Show password while typing</label><button type="submit">Create secure account</button></form><p class="note">Use at least 10 characters with an uppercase letter, lowercase letter and number.</p></main></body></html>
