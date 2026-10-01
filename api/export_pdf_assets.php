<?php
declare(strict_types=1);
// The Exports directory is private. Serve only the pinned PDF libraries.
require dirname(__DIR__) . '/auth_store.php';
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
$user = tt_current_user();
if (!$user || !tt_user_can_open_module($user, 'Exports')) {
    http_response_code($user ? 403 : 401);
    exit('Exports access is required.');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    exit('Method not allowed.');
}
$assets = [
    'html2canvas' => 'html2canvas-1.4.1.min.js',
    'jspdf' => 'jspdf-4.2.1.umd.min.js',
    'pdf-lib' => 'pdf-lib-1.17.1.min.js',
];
$asset = $assets[(string)($_GET['asset'] ?? '')] ?? null;
if ($asset === null) { http_response_code(404); exit('Unknown PDF library.'); }
$path = dirname(__DIR__) . '/exports/vendor/' . $asset;
if (!is_file($path)) { http_response_code(503); exit('PDF generation is temporarily unavailable.'); }
header('Content-Type: application/javascript; charset=UTF-8');
header('Content-Length: ' . filesize($path));
readfile($path);
