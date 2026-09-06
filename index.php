<?php
declare(strict_types=1);
require __DIR__ . '/auth_store.php';
if (!tt_has_admin()) {
    header('Location: setup.php');
    exit;
}
tt_require_login();
header('Content-Type: text/html; charset=UTF-8');
readfile(__DIR__ . '/index.html');
