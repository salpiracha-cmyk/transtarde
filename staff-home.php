<?php
declare(strict_types=1);
require __DIR__ . '/auth_store.php';
$user=tt_require_login();
if (($user['role'] ?? '')==='Super Admin') { header('Location: index.php'); exit; }
if (!empty($user['must_change_password'])) { header('Location: change-password.php'); exit; }
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Transtrade</title><link rel="stylesheet" href="admin/auth.css?v=20260907-2"></head><body><main class="auth-card"><div class="brand">TT</div><p class="eyebrow">TRANSTRADE INTERNATIONAL</p><h1>Module access</h1><p class="intro">Your assigned module is not live yet. Please contact Salman.</p><a class="button" href="logout.php">Sign out</a></main></body></html>
