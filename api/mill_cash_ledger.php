<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
require_once __DIR__.'/mill_cash_core.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
function mcl_out(array $value,int $status=200): never {http_response_code($status);echo json_encode($value,JSON_THROW_ON_ERROR);exit;}
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')mcl_out(['ok'=>false,'error'=>'Read only endpoint.'],405);
    $u=tt_require_login();
    if(!tt_user_can_open_module($u,'Mill')||!tt_user_can_module_action($u,'Mill','petty','View'))mcl_out(['ok'=>false,'error'=>'Mill Petty Cash View permission required.'],403);
    $entity=strtoupper((string)($_GET['entity']??'TTI'));
    if(!in_array($entity,['TTI','BRM'],true))mcl_out(['ok'=>false,'error'=>'Select the company mill.'],422);
    $locations=mcl_locations($entity);$locationId=trim((string)($_GET['locationId']??''));$name=(string)($_GET['millName']??'');
    if($locationId==='')foreach($locations as $row)if(tt_location_identity($row['name'])===tt_location_identity($name)){$locationId=$row['id'];break;}
    $location=mcl_location($entity,$locationId);tt_release_read_session();
    $state=[];$path=TT_DATA_DIR.'/accounts.json';
    if(is_file($path)){$h=fopen($path,'r');if(!$h||!flock($h,LOCK_SH))throw new RuntimeException('Accounts unavailable.');try{$raw=stream_get_contents($h);$state=$raw?json_decode($raw,true,512,JSON_THROW_ON_ERROR):[];}finally{flock($h,LOCK_UN);fclose($h);}}
    mcl_out(['ok'=>true,'location'=>$location,'rows'=>mcl_rows((array)$state,$entity,$locationId)]);
}catch(DomainException $e){mcl_out(['ok'=>false,'error'=>$e->getMessage()],422);}catch(Throwable $e){error_log('Mill cash ledger: '.$e->getMessage());mcl_out(['ok'=>false,'error'=>'Mill cash entries could not be loaded.'],500);}
