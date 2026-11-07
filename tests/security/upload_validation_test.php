<?php
declare(strict_types=1);
require __DIR__.'/../../upload_validation.php';

function expect_upload(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$tmp=tempnam(sys_get_temp_dir(),'tti-upload-');
if($tmp===false)throw new RuntimeException('No temporary upload fixture path.');
try{
    file_put_contents($tmp,'<script>alert(1)</script>');
    expect_upload(!tt_upload_signature_valid($tmp,'application/pdf'),'HTML disguised as PDF must be rejected.');
    file_put_contents($tmp,"%PDF-1.4\n1 0 obj <<>> endobj\n%%EOF\n");
    expect_upload(tt_upload_signature_valid($tmp,'application/pdf'),'PDF signature and terminator must be recognized.');
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Bxx4AAAAASUVORK5CYII=',true);
    file_put_contents($tmp,$png);
    expect_upload(tt_upload_signature_valid($tmp,'image/png'),'A valid PNG must remain accepted.');
    expect_upload(!tt_upload_signature_valid($tmp,'image/webp'),'Mismatched image formats must be rejected.');
    expect_upload(!str_contains(tt_upload_original_name("../<script>alert(1)</script>.pdf"),'<'),'Stored names must not contain markup.');
    expect_upload(str_contains(file_get_contents(__DIR__.'/../../.htaccess'),'upload_validation'), 'Upload helper must be blocked from direct requests.');
    echo "PASS server upload signatures and safe document metadata\n";
}finally{@unlink($tmp);}
