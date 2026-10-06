<?php
declare(strict_types=1);
require __DIR__.'/auth_store.php';
require __DIR__.'/exports/runtime_assets.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){header('Allow: GET');http_response_code(405);exit;}
$user=tt_require_login();
if(!tt_user_can_open_module($user,'Exports')){
    http_response_code(403);exit('Exports permission required.');
}
tt_release_read_session();
$types=['app.js'=>'application/javascript','app.css'=>'text/css','TTI_header.png'=>'image/png','TTI_sign.png'=>'image/png','BRM_header.png'=>'image/png','BRM_sign.png'=>'image/png','TG_header.png'=>'image/png','TG_footer.png'=>'image/png','TG_sign.png'=>'image/png','KCCI_COO_letterpad.jpg'=>'image/jpeg'];
$name=(string)($_GET['name']??'');
if(!isset($types[$name])){http_response_code(404);exit('Asset not found.');}
try{
    if($name==='app.js')$body=tt_export_runtime_script();
    else{
        $path=__DIR__.'/exports/'.($name==='app.css'?'':'assets/').$name;
        if(!is_file($path)||($body=file_get_contents($path))===false)throw new RuntimeException('Asset unavailable.');
    }
}catch(RuntimeException $e){http_response_code(503);exit('Export module assets are unavailable.');}
$etag='"'.hash('sha256',$body).'"';
header('Content-Type: '.$types[$name].(str_starts_with($types[$name],'image/')?'':'; charset=UTF-8'));
header('Cache-Control: private, no-cache, must-revalidate');
header('ETag: '.$etag);
header('X-Content-Type-Options: nosniff');
if(trim((string)($_SERVER['HTTP_IF_NONE_MATCH']??''))===$etag){http_response_code(304);exit;}
echo $body;
