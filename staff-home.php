<?php
declare(strict_types=1);
require __DIR__ . '/auth_store.php';
$user=tt_require_login();
if (($user['role'] ?? '')==='Super Admin') { header('Location: index.php'); exit; }
if (!empty($user['must_change_password'])) { header('Location: change-password.php'); exit; }
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
</style>
</head>
<body>
<header class="tt-staff-header"><strong>TRANSTRADE</strong><div class="spacer"></div><span class="tt-staff-user"><?=htmlspecialchars((string)$user['full_name'],ENT_QUOTES,'UTF-8')?> · <?=htmlspecialchars((string)$user['role'],ENT_QUOTES,'UTF-8')?></span><a class="tt-staff-power" href="logout.php" title="Log out" aria-label="Log out">⏻</a></header>
<main class="auth-card"><div class="brand">TT</div><p class="eyebrow">TRANSTRADE INTERNATIONAL</p><h1>Module access</h1><p class="intro">Your assigned module is not live yet. Please contact Salman.</p></main>
</body>
</html>