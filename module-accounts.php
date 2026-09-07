<?php
declare(strict_types=1);

ob_start();
require __DIR__ . '/module.php';
$html = (string)ob_get_clean();
$id = strtolower((string)($_GET['id'] ?? ''));
if ($id === 'milling' || $id === 'exports') {
    $bridge = '<script src="accounts/source-bridge.js?v=20260907-1"></script>';
    $pos = strripos($html, '</body>');
    if ($pos !== false) $html = substr_replace($html, $bridge, $pos, 0); else $html .= $bridge;
}
echo $html;
