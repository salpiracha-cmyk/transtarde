<?php
declare(strict_types=1);
require __DIR__ . '/auth_store.php';
$reason=(string)($_GET['reason']??'');
$user = tt_current_user();
if ($user) tt_audit((int)$user['id'], $user['username'], 'Signed out');
tt_destroy_session_state();
header('Location: login.php'.($reason==='inactive'?'?expired=inactive':''));
