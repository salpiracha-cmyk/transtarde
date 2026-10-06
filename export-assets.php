<?php
declare(strict_types=1);
require __DIR__.'/auth_store.php';
require __DIR__.'/exports/runtime_assets.php';
require __DIR__.'/inventory_reconciliation.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){header('Allow: GET');http_response_code(405);exit;}
$user=tt_require_login();
$name=(string)($_GET['name']??'');
if(!tt_user_can_open_module($user,'Exports')&&($name!=='legacy'||!tt_user_can_open_module($user,'Mill'))){
    http_response_code(403);exit('Exports permission required.');
}
tt_release_read_session();
$types=['app.js'=>'application/javascript','app.css'=>'text/css','release-theme.css'=>'text/css','TTI_header.png'=>'image/png','TTI_sign.png'=>'image/png','BRM_header.png'=>'image/png','BRM_sign.png'=>'image/png','TG_header.png'=>'image/png','TG_footer.png'=>'image/png','TG_sign.png'=>'image/png','KCCI_COO_letterpad.jpg'=>'image/jpeg','legacy'=>'application/octet-stream'];
if(!isset($types[$name])){http_response_code(404);exit('Asset not found.');}
try{
    if($name==='legacy'){
        $hash=(string)($_GET['sha256']??'');
        if(!preg_match('/^[a-f0-9]{64}$/',$hash)){http_response_code(404);exit('Asset not found.');}
        $values=tt_inv_public_values(tt_inv_read_values())['values'];
        $index=tt_inv_artwork_index($values);$url=$index[$hash]??'';
        if($url===''){http_response_code(404);exit('Asset not found.');}
        $comma=strpos($url,',');$body=base64_decode(substr($url,$comma+1),true);
        $image=$body===false?false:@getimagesizefromstring($body);
        if(!$image||!in_array($image['mime']??'',['image/png','image/jpeg','image/gif','image/webp'],true)){http_response_code(404);exit('Asset not found.');}
        $types[$name]=$image['mime'];
    }
    elseif($name==='app.js')$body=tt_export_runtime_script();
    else{
        $path=__DIR__.'/exports/'.(in_array($name,['app.css','release-theme.css'],true)?'':'assets/').$name;
        if(!is_file($path)||($body=file_get_contents($path))===false)throw new RuntimeException('Asset unavailable.');
    }
}catch(Throwable $e){http_response_code(503);exit('Export module assets are unavailable.');}
$etag='"'.hash('sha256',$body).'"';
header('Content-Type: '.$types[$name].(str_starts_with($types[$name],'image/')?'':'; charset=UTF-8'));
header('Cache-Control: private, no-cache, must-revalidate');
header('ETag: '.$etag);
header('X-Content-Type-Options: nosniff');
if(trim((string)($_SERVER['HTTP_IF_NONE_MATCH']??''))===$etag){http_response_code(304);exit;}
echo $body;
