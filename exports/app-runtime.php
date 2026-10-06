<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
require __DIR__.'/runtime_assets.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){header('Allow: GET');http_response_code(405);exit;}
$user=tt_require_login();
if(!tt_user_can_open_module($user,'Exports')){
    http_response_code(403);exit('Exports permission required.');
}
tt_release_read_session();
try{$js=tt_export_runtime_script();}
catch(RuntimeException $e){http_response_code(503);exit('Export module assets are unavailable.');}
$etag='"'.hash('sha256',$js).'"';
header('Content-Type: application/javascript; charset=UTF-8');
header('Cache-Control: private, no-cache, must-revalidate');
header('ETag: '.$etag);
header('X-Content-Type-Options: nosniff');
if(trim((string)($_SERVER['HTTP_IF_NONE_MATCH']??''))===$etag){http_response_code(304);exit;}
echo $js;
