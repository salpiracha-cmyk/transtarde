<?php
declare(strict_types=1);
require __DIR__.'/auth_store.php';
require_once __DIR__.'/document_filenames.php';
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
$token = $_GET['file'] ?? '';
if (!is_string($token) || !preg_match('/^[0-9a-f]{48}$/D',$token)) { http_response_code(404); exit; }
$path = TT_DATA_DIR.'/telegram-reports/'.$token;
try {
    $meta = is_file($path.'.json') ? json_decode((string)file_get_contents($path.'.json'),true,32,JSON_THROW_ON_ERROR) : null;
    if (!$meta || ($meta['expires']??0)<time() || !is_file($path.'.pdf')
        || !hash_equals((string)$meta['sha256'],hash_file('sha256',$path.'.pdf'))) {
        http_response_code(410); exit('This report link has expired. Request a fresh report in Transtrade.');
    }
    header('Content-Type: application/pdf');
    header('Content-Length: '.filesize($path.'.pdf'));
    tt_document_disposition((string)$meta['name'],true);
    readfile($path.'.pdf');
} catch (Throwable) { http_response_code(503); echo 'The report is temporarily unavailable.'; }
