<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
require_once __DIR__.'/accounts_shipment_source.php';
require_once __DIR__.'/operations_scope.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store');
try {
    $user=tt_require_login();
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){http_response_code(405);echo json_encode(['ok'=>false,'error'=>'Read only.']);exit;}
    if(!tt_user_can_open_module($user,'Mill')){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Milling access required.']);exit;}
    tt_release_read_session();$db=tt_accounts_exports_db();$versions=[];
    if($db){foreach($db->query('SELECT storage_key,version FROM tt_operation_records')->fetchAll() as $row)$versions[(string)$row['storage_key']]=(int)$row['version'];}
    else {
        $path=TT_DATA_DIR.'/operations.json';$meta=[];
        if(is_file($path)){$h=fopen($path,'rb');if(!$h||!flock($h,LOCK_SH))throw new RuntimeException('Source unavailable.');try{$state=json_decode((string)stream_get_contents($h),true,512,JSON_THROW_ON_ERROR);$meta=$state['meta']??[];}finally{flock($h,LOCK_UN);fclose($h);}}
        foreach($meta as $key=>$row)$versions[$key]=(int)($row['version']??0);
    }
    // Same collection visibility as operational reads, without records or actor metadata.
    $versions=array_filter($versions,static fn($key)=>isset(os_key_grants()[$key])&&os_key_visible($user,$key),ARRAY_FILTER_USE_KEY);
    echo json_encode(['ok'=>true,'versions'=>(object)$versions],JSON_THROW_ON_ERROR);
} catch(Throwable $e){error_log('Mill versions: '.$e->getMessage());http_response_code(503);echo json_encode(['ok'=>false,'error'=>'Live updates could not be checked. Your entry is retained.']);}
