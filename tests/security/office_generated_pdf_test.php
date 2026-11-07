<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/api/office_document_policy.php';
$root=dirname(__DIR__,2).'/tmp/qa';$count=0;
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)) as $file){
    if(!in_array(strtolower($file->getExtension()),['pdf','docx'],true))continue;
    office_document_validate($file->getPathname(),$file->getFilename());$count++;
}
if($count<1)throw new RuntimeException('No production-rendered PDF was available for Office validation.');
echo 'PASS Office policy accepts '.$count." actual rendered export PDFs/Word documents\n";
