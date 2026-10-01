<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
require_once __DIR__.'/fi_credit_advice_link.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
try{
    $user=tt_require_login();
    if(!tt_user_can_open_module($user,'Exports')){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Exports access required.']);exit;}
    if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);echo json_encode(['ok'=>false,'error'=>'Method not allowed.']);exit;}
    $root=tt_fi_advice_root();$store=tt_fi_advice_read_json(TT_DATA_DIR.'/accounts.json');
    $links=tt_fi_advice_matches($root,$store);$allowed=[];
    // Exports-only staff (including Jazib) do not need Accounts module permission.
    // Respect an Accounts entity restriction when the user also has Accounts access.
    foreach((array)($root['fi']??[]) as $fi){
        if(!is_array($fi)||!in_array((string)($fi['exporter']??''),['TTI','BRM'],true))continue;
        if(tt_user_can_open_module($user,'Accounts')&&!tt_user_can_access_entity($user,(string)$fi['exporter'],'View'))continue;
        if(isset($links[(string)($fi['id']??'')]))$allowed[(string)$fi['id']]=$links[(string)$fi['id']];
    }
    echo json_encode(['ok'=>true,'links'=>$allowed],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){error_log('FI tagging: '.$e->getMessage());http_response_code(503);echo json_encode(['ok'=>false,'error'=>'Automatic credit advice tagging is temporarily unavailable.']);}
