<?php
declare(strict_types=1);
/** A delivery claim is evidence only when every expected file matches once. */
function office_delivery_receipt_matches(array $job,array $reported): bool {
    $expected=$job['files']??[];
    if(!is_array($expected)||!array_is_list($expected)||!array_is_list($reported)||!$expected||count($expected)>80||count($expected)!==count($reported))return false;
    $map=[];
    foreach($expected as $file){
        if(!is_array($file)||!is_string($file['name']??null)||!is_string($file['folder']??'')||!is_int($file['size']??null)||$file['size']<1||!preg_match('/^[a-f0-9]{64}$/D',(string)($file['sha256']??'')))return false;
        $key=($file['folder']??'').'/'.$file['name'];if(isset($map[$key]))return false;$map[$key]=$file;
    }
    foreach($reported as $file){
        if(!is_array($file)||!is_string($file['name']??null)||!is_string($file['folder']??'')||!is_int($file['size']??null))return false;
        $key=($file['folder']??'').'/'.$file['name'];$want=$map[$key]??null;
        if(!$want||$file['size']!==$want['size']||!is_string($file['sha256']??null)||!hash_equals($want['sha256'],strtolower($file['sha256'])))return false;
        unset($map[$key]);
    }
    return !$map;
}
function office_delivery_public_status(array $job): array {
    $reported=is_array($job['verifiedFiles']??null)?$job['verifiedFiles']:[];
    $verified=($job['status']??'')==='VERIFIED'&&office_delivery_receipt_matches($job,$reported);
    $status=(string)($job['status']??'PENDING');if($status==='VERIFIED'&&!$verified)$status='UNVERIFIED';
    return ['layoutVersion'=>(int)($job['layoutVersion']??1),'id'=>(string)($job['id']??''),'status'=>$status,'createdAt'=>(string)($job['createdAt']??''),'updatedAt'=>(string)($job['updatedAt']??''),'fileCount'=>count((array)($job['files']??[])),'verifiedFileCount'=>$verified?count($reported):0,'receiptVerified'=>$verified,'lastError'=>$status==='ERROR'?'The office agent could not complete this copy. Check the configured destination on the office computer.':''];
}
