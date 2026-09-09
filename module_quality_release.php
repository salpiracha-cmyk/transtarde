<?php
declare(strict_types=1);
/* Milling quality-identity release layer. Keeps module.php and module_release.php unchanged. */
ob_start();
require __DIR__ . '/module_release.php';
$html = (string)ob_get_clean();
$id = strtolower((string)($_GET['id'] ?? ''));
if ($id === 'milling') {
    $script = '<script src="/milling-quality-identity.js?v=20260909-1"></script>';
    if (str_contains($html, '</body>')) $html = str_replace('</body>', $script . '</body>', $html);
    else $html .= $script;
}
echo $html;
