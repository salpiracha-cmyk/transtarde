<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
require_once dirname(__DIR__).'/inventory_reconciliation.php';
require_once __DIR__.'/mill_recovery_core.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
function mrr_out(array $d,int $code=200):never{http_response_code($code);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit;}
function mrr_read(string $file):array {
    if(!is_file($file))return [];$h=fopen($file,'r');if(!$h||!flock($h,LOCK_SH))throw new RuntimeException('Report source unavailable.');try{$v=json_decode(stream_get_contents($h),true,512,JSON_THROW_ON_ERROR);if(!is_array($v))throw new RuntimeException('Invalid report source.');return $v;}finally{flock($h,LOCK_UN);fclose($h);}
}
try{
    $u=tt_require_login();if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){header('Allow: GET');mrr_out(['ok'=>false,'error'=>'Recovery reports are read-only.'],405);}
    if(isset($_GET['entity'])&&!is_string($_GET['entity']))mrr_out(['ok'=>false,'error'=>'Invalid company filter.'],422);$e=strtoupper(trim((string)($_GET['entity']??'TTI')));if(!in_array($e,['TTI','BRM'],true))mrr_out(['ok'=>false,'error'=>'Choose TTI or BRM production books.'],422);
    $accounts=tt_user_can_open_module($u,'Accounts');$financial=tt_inv_can_report($u,$e);$mill=tt_user_can_open_module($u,'Mill')&&tt_inv_has_action($u,'Mill',['reports','production']);
    if(($accounts&&!tt_user_can_access_entity($u,$e,'View'))||(!$financial&&!$mill))mrr_out(['ok'=>false,'error'=>'Production report access for this company is required.'],403);
    $filters=['entity'=>$e];foreach(['from','to','shift','variety','mill','brand','contract','mode','search'] as $k){if(isset($_GET[$k])&&!is_string($_GET[$k]))mrr_out(['ok'=>false,'error'=>'Invalid report filter.'],422);$filters[$k]=trim((string)($_GET[$k]??''));}
    if($filters['mode']==='')$filters['mode']='daily';if(!in_array($filters['mode'],['daily','production','brand','contract'],true))mrr_out(['ok'=>false,'error'=>'Choose a valid report.'],422);
    foreach(['from','to'] as $k)if($filters[$k]!==''&&!mr_valid_date($filters[$k]))mrr_out(['ok'=>false,'error'=>'Valid report dates are required.'],422);
    if($filters['from']!==''&&$filters['to']!==''&&$filters['from']>$filters['to'])mrr_out(['ok'=>false,'error'=>'From date cannot follow To date.'],422);
    if($filters['shift']!==''&&!in_array($filters['shift'],['Day','Night'],true))mrr_out(['ok'=>false,'error'=>'Choose Day, Night or all shifts.'],422);
    tt_release_read_session();$ops=mr_read_operations_source(TT_DATA_DIR.'/operations.json');$values=$ops['values']??[];if(!is_array($values))throw new RuntimeException('Invalid operations values.');$raw=$values['tt30prod']??'[]';if(!is_string($raw))throw new RuntimeException('Invalid production source.');$rows=json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($rows)||!array_is_list($rows)||count(array_filter($rows,'is_array'))!==count($rows))throw new RuntimeException('Invalid production rows.');
    $location=trim((string)($u['location']??'All authorized locations'));$restricted=($u['role']??'')!=='Super Admin'&&!in_array(strtolower($location),['','all locations','all authorized locations'],true);
    if($restricted)$rows=array_values(array_filter($rows,static fn($r)=>tt_inv_location($r)===tt_inv_norm($location)));
    $report=mr_report($rows,$filters);$report['canViewCosts']=$financial&&!$restricted;
    if($report['canViewCosts']){require_once __DIR__.'/mill_recovery_cost.php';$store=mrr_read(TT_DATA_DIR.'/accounts.json');$report=mr_attach_costs($report,$rows,mr_cost_sources($store,$ops,$e));$report['accountsRevision']=$store['revision']??0;}
    $report['entities']=array_values(array_filter(['TTI','BRM'],static fn($company)=>!$accounts||tt_user_can_access_entity($u,$company,'View')));$report['sourceBackend']=$ops['sourceBackend']??'file';$report['operationsRevision']=$ops['revision']??0;$report['generatedAt']=gmdate('c');mrr_out($report);
}catch(Throwable $e){error_log('Mill recovery report: '.$e->getMessage());mrr_out(['ok'=>false,'error'=>'Recovery source could not be read. No figures have been estimated.'],500);}
