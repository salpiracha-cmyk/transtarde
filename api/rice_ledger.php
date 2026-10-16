<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
require_once __DIR__.'/rice_ledger_core.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
function rl_out(array $data,int $code=200):never{http_response_code($code);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit;}
try{
    $u=tt_require_login();if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){header('Allow: GET');rl_out(['ok'=>false,'error'=>'Rice Ledger is read-only.'],405);}
    $f=[];foreach(['entity','mill','from','to','product','movement'] as $k){if(isset($_GET[$k])&&!is_string($_GET[$k]))rl_out(['ok'=>false,'error'=>'Invalid report filter.'],422);$f[$k]=trim((string)($_GET[$k]??''));}
    $e=strtoupper($f['entity']);if(!in_array($e,['TTI','BRM'],true)||$f['mill']==='')rl_out(['ok'=>false,'error'=>'Choose a company and mill.'],422);
    $accounts=tt_user_can_open_module($u,'Accounts');$financial=tt_inv_can_report($u,$e);$mill=tt_user_can_open_module($u,'Mill')&&tt_inv_has_action($u,'Mill',['reports']);
    if(($accounts&&!tt_user_can_access_entity($u,$e,'View'))||(!$financial&&!$mill))rl_out(['ok'=>false,'error'=>'Rice Ledger access for this company is required.'],403);
    $location=trim((string)($u['location']??''));if(($u['role']??'')!=='Super Admin'&&!in_array(strtolower($location),['','all locations','all authorized locations'],true)&&tt_inv_norm($location)!==tt_inv_norm($f['mill']))rl_out(['ok'=>false,'error'=>'This mill is outside your assigned location.'],403);
    foreach(['from','to'] as $k)if($f[$k]!==''&&!mr_valid_date($f[$k]))rl_out(['ok'=>false,'error'=>'Valid report dates are required.'],422);
    if($f['from']!==''&&$f['to']!==''&&$f['from']>$f['to'])rl_out(['ok'=>false,'error'=>'From date cannot follow To date.'],422);
    if(!in_array($f['movement'],['All','Inward','Outward'],true))rl_out(['ok'=>false,'error'=>'Invalid movement filter.'],422);
    tt_release_read_session();$values=tt_inv_read_values();rl_out(rl_report($values,['entity'=>$e,'millName'=>$f['mill']],$f)+['generatedAt'=>gmdate('c')]);
}catch(Throwable $ex){error_log('Rice Ledger: '.$ex->getMessage());rl_out(['ok'=>false,'error'=>'Stock source could not be read. No balance has been estimated.'],500);}
