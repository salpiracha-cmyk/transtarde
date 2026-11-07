<?php
declare(strict_types=1);
require dirname(__DIR__).'/document_filenames.php';
function filename_check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
filename_check(tt_document_name(['party'=>'Shams Broker','title'=>'SODA','reference'=>'SODA/2026/001','extension'=>'pdf'])==='Shams Broker - SODA - SODA-2026-001.pdf','SODA party/ref');
filename_check(tt_document_name(['party'=>'Meezan Bank','title'=>'Ledger','from'=>'2026-07-01','to'=>'2026-10-22','extension'=>'xlsx'])==='Meezan Bank - Ledger - 2026-07-01 to 2026-10-22.xlsx','Ledger period');
filename_check(tt_document_name(['party'=>"../../\r\n\"<>|?*\u{202e}evil",'title'=>'Invoice','reference'=>'A/B','extension'=>'PDF'])==='evil - Invoice - A-B.pdf','Header/path safety');
filename_check(tt_document_name(['party'=>'///','title'=>'***','extension'=>'pdf'])==='Transtrade - Document.pdf','Empty safe fallback');
filename_check(strlen(tt_document_name(['party'=>str_repeat('نام',80),'title'=>'Invoice','reference'=>'INV123','extension'=>'pdf']))<=224,'Unicode length');
$root=['customers'=>[['id'=>'BUY','name'=>'Current buyer']],'contracts'=>[['ref'=>'TTI/ASAS/63','seller'=>'TTI','customerId'=>'BUY']],'shipments'=>[['id'=>'LOT','lotId'=>'L01','contractRef'=>'TTI/ASAS/63','buyer'=>'ASAS','documentParties'=>['buyer'=>['name'=>'Issued ASAS']],'commercial'=>['invoiceNo'=>'INV/63']]]];
$name=tt_export_document_name($root,'TTI/ASAS/63','L01','Commercial Invoice','pdf');
filename_check($name==='Issued ASAS - Commercial Invoice - INV-63 - L01 - TTI.pdf','Use original issued party, correct invoice and lot');
filename_check(tt_document_extension('scan','application/pdf')==='pdf','Keep verified file type even without an uploaded extension');
require dirname(__DIR__).'/runtime_html.php';
$html=tt_version_local_assets('<html><head><title>Accounts</title></head><body></body></html>');
filename_check(str_contains($html,'/document-filenames.js?v='),'Versioned helper must reach every live module');
filename_check(substr_count(tt_version_local_assets($html),'/document-filenames.js?v=')===1,'Do not duplicate the shared helper');
echo "PASS server filenames: party/reference/period, preserved issued parties, Unicode and safe header names\n";
