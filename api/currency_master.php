<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
$user=tt_require_login();
if(!tt_user_can_open_module($user,'Accounts')&&!tt_user_can_open_module($user,'Exports')&&!tt_user_can_access_masters($user)){
    http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Currency Master access denied.']);exit;
}
if($_SERVER['REQUEST_METHOD']!=='GET'){
    http_response_code(405);echo json_encode(['ok'=>false,'error'=>'Method not allowed.']);exit;
}
$options=tt_master_options();$names=(array)($options['currency_names']??[]);
$rows=[];foreach((array)($options['currencies']??[]) as $code){$code=strtoupper((string)$code);if(preg_match('/^[A-Z]{3}$/',$code))$rows[]=['code'=>$code,'name'=>(string)($names[$code]??$code)];}
echo json_encode(['ok'=>true,'currencies'=>$rows],JSON_UNESCAPED_UNICODE);
