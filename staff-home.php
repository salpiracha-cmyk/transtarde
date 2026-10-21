<?php
declare(strict_types=1);
require __DIR__ . '/auth_store.php';
$user=tt_require_login();
if (($user['role'] ?? '')==='Super Admin') { header('Location: index.php'); exit; }
if (!empty($user['must_change_password'])) { header('Location: change-password.php'); exit; }
require_once __DIR__.'/runtime_html.php';
require_once __DIR__.'/jv_notice_ui.php';
$workspaces=tt_user_workspaces($user);
if(($_SERVER['REQUEST_METHOD']??'GET')==='GET')tt_release_read_session();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Transtrade</title>
<link rel="stylesheet" href="admin/auth.css?v=20260907-2">
<style>
.tt-staff-header{height:64px;background:#102a46;color:#fff;display:flex;align-items:center;padding:0 22px;gap:16px;box-shadow:0 2px 10px #0002}
.tt-staff-header strong{font-size:15px;letter-spacing:.03em}.tt-staff-header .spacer{flex:1}.tt-staff-user{font-size:12px;font-weight:700;white-space:nowrap}.tt-staff-power{width:32px;height:32px;display:grid;place-items:center;border-left:1px solid rgba(255,255,255,.24);padding-left:10px;color:#fff;text-decoration:none;font-size:18px;font-weight:900;line-height:1}.tt-staff-power:hover{color:#ffd7d2}
body{display:block;padding:0;background:#eef3f8}.tt-staff-header{width:100%;position:sticky;top:0;z-index:30}.auth-card{margin:40px auto}
.tt-workspace-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-top:22px}.tt-workspace{display:block;padding:22px 16px;border:1px solid #d0ded8;border-radius:12px;background:#eaf5ef;color:#14583e;font-weight:800;text-decoration:none}.tt-workspace:hover{background:#dcefe3}.auth-card{width:min(680px,calc(100% - 32px));max-width:680px}@media(max-width:560px){.tt-staff-header{padding:0 14px;gap:10px}.tt-staff-header strong{font-size:14px}.tt-staff-user{font-size:11px;min-width:0;overflow:hidden;text-overflow:ellipsis}.tt-staff-power{flex:0 0 32px}}@media(max-width:440px){.tt-workspace-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<header class="tt-staff-header"><strong>TRANSTRADE</strong><div class="spacer"></div><span class="tt-staff-user"><?=htmlspecialchars((string)$user['full_name'],ENT_QUOTES,'UTF-8')?> · <?=htmlspecialchars((string)$user['role'],ENT_QUOTES,'UTF-8')?></span><a class="tt-staff-power" href="logout.php" title="Log out" aria-label="Log out">⏻</a></header>
<main class="auth-card" id="staffHome"><div class="brand">TT</div><h1>Home</h1><nav class="tt-workspace-grid" aria-label="Your authorised workspaces"><?php foreach($workspaces as $workspace): ?><a class="tt-workspace" href="<?=htmlspecialchars($workspace['url'],ENT_QUOTES,'UTF-8')?>"><?=htmlspecialchars($workspace['label'],ENT_QUOTES,'UTF-8')?></a><?php endforeach; ?></nav><?php if(tt_user_can_access_masters($user)): ?><p><a class="tt-workspace" href="/index.php?view=masters">Master Records</a></p><?php endif; ?><?php if(!$workspaces): ?><p class="intro">No module has been assigned. Please contact Salman.</p><?php endif; ?></main>
<script>window.TT_SESSION={csrf:<?=json_encode(tt_csrf(),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>};</script><script src="/session-activity.js?v=20260929-1"></script>
<?php echo tt_version_local_assets(tt_jv_notice_ui($user, '#staffHome')); ?>
</body>
</html>
