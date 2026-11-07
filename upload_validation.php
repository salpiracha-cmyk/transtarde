<?php
declare(strict_types=1);

/** Validate uploaded bytes before placing an owner document in private storage. */
function tt_upload_signature_valid(string $path, string $mime): bool {
    $h=fopen($path,'rb');
    if($h===false)return false;
    try {
        $head=fread($h,1024);
        if($head===false)return false;
        return match($mime) {
            'application/pdf'=>str_starts_with($head,'%PDF-') && tt_upload_pdf_ends_cleanly($h),
            'image/png'=>str_starts_with($head,"\x89PNG\r\n\x1a\n") && getimagesize($path)!==false,
            'image/jpeg'=>str_starts_with($head,"\xff\xd8\xff") && getimagesize($path)!==false,
            'image/webp'=>substr($head,0,4)==='RIFF' && substr($head,8,4)==='WEBP' && getimagesize($path)!==false,
            default=>false,
        };
    } finally { fclose($h); }
}
function tt_upload_pdf_ends_cleanly($h): bool {
    $stat=fstat($h);$size=(int)($stat['size']??0);
    if($size<10)return false;
    if(fseek($h,-min($size,2048),SEEK_END)!==0)return false;
    $tail=stream_get_contents($h);
    return $tail!==false && str_contains($tail,'%%EOF');
}
function tt_validate_document_upload(array $file,int $limit,array $allowed): array {
    if((int)($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new InvalidArgumentException('The document upload did not complete.');
    $size=(int)($file['size']??0);$path=(string)($file['tmp_name']??'');
    if($size<1||$size>$limit)throw new InvalidArgumentException('The selected document exceeds the permitted size or is empty.');
    if(!is_uploaded_file($path)||filesize($path)!==$size)throw new InvalidArgumentException('The uploaded document could not be verified.');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($path)?:'';
    if(!isset($allowed[$mime])||!tt_upload_signature_valid($path,$mime))throw new InvalidArgumentException('Upload a valid PDF, JPEG, PNG or WebP document.');
    return ['mime'=>$mime,'extension'=>$allowed[$mime],'size'=>$size,'path'=>$path];
}
function tt_upload_original_name(string $name,string $fallback='document'): string {
    $name=basename(str_replace('\\','/',$name));
    $name=trim(preg_replace('/[\x00-\x1f\x7f<>"\x27]+/u','',$name)??'');
    return substr($name!==''?$name:$fallback,0,255);
}
function tt_uploaded_document_headers(): void {
    header('X-Content-Type-Options: nosniff');
    header('Content-Security-Policy: sandbox');
    header('Cache-Control: private, no-store');
}
