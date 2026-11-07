<?php
declare(strict_types=1);
require_once __DIR__.'/monthly_tax_source.php';
$u=tt_require_login();mt_access($u,strtoupper((string)($_GET['entity']??'')));if(($_SERVER['REQUEST_METHOD']??'')!=='GET'){http_response_code(405);exit;}
$assets=['shipment-files.js'=>['exports/shipment-files.js','application/javascript'],'app.js'=>['exports/app.js','application/javascript'],'app.css'=>['exports/app.css','text/css'],'html2canvas'=>['exports/vendor/html2canvas-1.4.1.min.js','application/javascript'],'jspdf'=>['exports/vendor/jspdf-4.2.1.umd.min.js','application/javascript'],'pdf-lib'=>['exports/vendor/pdf-lib-1.17.1.min.js','application/javascript']];
foreach(['TTI_header.png','TTI_sign.png','BRM_header.png','BRM_sign.png'] as $name)$assets[$name]=['exports/assets/'.$name,'image/png'];
$a=$assets[(string)($_GET['asset']??'')]??null;if(!$a||!is_file(dirname(__DIR__).'/'.$a[0])){http_response_code(404);exit('Asset unavailable.');}tt_release_read_session();header('Content-Type: '.$a[1]);header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');readfile(dirname(__DIR__).'/'.$a[0]);
