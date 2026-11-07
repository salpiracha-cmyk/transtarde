<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
require_once __DIR__.'/commodity_quantity_core.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
function cq_out(array $data,int $status=200):never {http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit;}
try{
    $user=tt_require_login();$entity=strtoupper(trim((string)($_GET['entity']??'')));
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')cq_out(['ok'=>false,'error'=>'This report is read-only.'],405);
    if(!tt_user_can_open_module($user,'Accounts')||!tt_user_can_module_action($user,'Accounts','reports','View')||!in_array($entity,['TTI','BRM','TG'],true)||!tt_user_can_access_entity($user,$entity,'View'))cq_out(['ok'=>false,'error'=>'Company report permission required.'],403);
    if(function_exists('tt_release_read_session'))tt_release_read_session();
    $path=TT_DATA_DIR.'/accounts.json';$h=@fopen($path,'rb');if(!$h)throw new RuntimeException('Accounts source unavailable.');
    try{if(!flock($h,LOCK_SH))throw new RuntimeException('Accounts source busy.');$books=mr_decode_store((string)stream_get_contents($h));}finally{flock($h,LOCK_UN);fclose($h);}
    $ops=gd_read_operations(TT_DATA_DIR.'/operations.json');
    cq_out(['ok'=>true,'entity'=>$entity]+cq_report($books,$ops,$entity,(string)($_GET['from']??''),(string)($_GET['asOf']??'')));
}catch(DomainException|JsonException $e){cq_out(['ok'=>false,'error'=>$e->getMessage()],422);}catch(Throwable $e){error_log('Commodity quantities: '.$e->getMessage());cq_out(['ok'=>false,'error'=>'Commodity sources could not be read. No empty quantity report has been assumed.'],500);}
