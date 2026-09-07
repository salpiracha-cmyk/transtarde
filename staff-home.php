<?php
declare(strict_types=1);
require __DIR__ . '/auth_store.php';
$user=tt_require_login();
if (($user['role'] ?? '')==='Super Admin') { header('Location: index.php'); exit; }
if (!empty($user['must_change_password'])) { header('Location: change-password.php'); exit; }
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Transtrade</title><link rel="stylesheet" href="admin/auth.css?v=20260907-2"></head><body><a href="logout.php" title="Log out" aria-label="Log out" style="position:fixed;right:16px;top:16px;width:38px;height:38px;display:grid;place-items:center;border:1px solid #e4c5c2;border-radius:10px;background:#fff;color:#b42318;text-decoration:none;font-size:20px;font-weight:900;line-height:1">⏻</a><main class="auth-card"><div class="brand">TT</div><p class="eyebrow">TRANSTRADE INTERNATIONAL</p><h1>Module access</h1><p class="intro">Your assigned module is not live yet. Please contact Salman.</p></main></body></html>
