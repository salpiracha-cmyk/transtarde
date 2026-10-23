<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
require_once __DIR__.'/accounts_subaccounts_core.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
try{
 $u=tt_require_login();$entity=strtoupper((string)($_GET['entity']??''));
 if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'||!tt_user_can_open_module($u,'Accounts')||!in_array($entity,['TTI','BRM','TG'],true)||!tt_user_can_access_entity($u,$entity,'View')){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Accounts and company View permission required.']);exit;}
 tt_release_read_session();$h=fopen(TT_DATA_DIR.'/accounts.json','r');if(!$h||!flock($h,LOCK_SH))throw new RuntimeException('Accounts choices unavailable.');
 try{$s=json_decode((string)stream_get_contents($h),true,512,JSON_THROW_ON_ERROR);if(!is_array($s))throw new RuntimeException('Invalid accounts.');}finally{flock($h,LOCK_UN);fclose($h);}
 $used=sac_party_company_names($s,$entity);$ids=[];
 foreach((array)(tt_user_visible_masters($u)['business_parties']??[]) as $row)if(strcasecmp((string)($row['values'][10]??'Active'),'Inactive')!==0&&sac_party_company_allowed($row,$entity,$used))$ids[]=(string)$row['id'];
 echo json_encode(['ok'=>true,'entity'=>$entity,'businessPartyIds'=>$ids],JSON_THROW_ON_ERROR);
}catch(Throwable $e){http_response_code(500);echo json_encode(['ok'=>false,'error'=>'Company party choices could not be loaded.']);}
