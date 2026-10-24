<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';require_once __DIR__.'/directors_cost_defaults_core.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');
function dcd_out(array $data,int $status=200):never{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit;}
try{
 $u=tt_require_login();$write=($_SERVER['REQUEST_METHOD']??'GET')==='POST';if(!$write&&($_SERVER['REQUEST_METHOD']??'GET')!=='GET')dcd_out(['ok'=>false,'error'=>'Method not allowed.'],405);
 $view=false;foreach(['Directors','Accounts','Mill','Exports'] as $module)if(tt_user_can_open_module($u,$module))$view=true;if(!$view)dcd_out(['ok'=>false,'error'=>'Module access required.'],403);
 $canWrite=dt_cost_permission($u,'Edit')||dt_cost_permission($u,'Create');if($write&&!$canWrite)dcd_out(['ok'=>false,'error'=>'Costing Create or Edit permission is required.'],403);
 $b=$write?json_decode((function_exists('tt_accounts_input')?tt_accounts_input():file_get_contents('php://input'))?:'{}',true,64,JSON_THROW_ON_ERROR):[];if(!is_array($b))throw new DomainException('Invalid request.');if($write&&!tt_verify_csrf((string)($b['csrf']??'')))dcd_out(['ok'=>false,'error'=>'Session expired. Reopen the form.'],419);
 $today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Karachi')))->format('Y-m-d');$date=gm_date($b['date']??$_GET['date']??$today);$csrf=tt_csrf();if(!$write)tt_release_read_session();tt_ensure_data_dir();$path=TT_DATA_DIR.'/accounts.json';$h=fopen($path,$write?'r+':'rb');if(!$h||!flock($h,$write?LOCK_EX:LOCK_SH))throw new RuntimeException('Storage unavailable.');
 try{$s=json_decode((string)stream_get_contents($h),true,512,JSON_THROW_ON_ERROR);$result=null;$replay=false;
  if($write){$key=gm_text($b['requestKey']??'',128);if(!preg_match('/^[A-Za-z0-9._:-]{8,128}$/D',$key))throw new DomainException('Reopen the form before saving.');$id=($u['id']??'').'|'.$key;$fp=hash('sha256',json_encode($b,JSON_THROW_ON_ERROR));$prior=$s['directorDefaultRequests'][$id]??null;
   if($prior){if(!hash_equals($prior['fingerprint'],$fp))dcd_out(['ok'=>false,'error'=>'Save key already used. Reopen the form.'],409);$result=$prior['result'];$replay=true;}
   else{$action=$b['action']??'';if($action==='save_rates')$result=dcd_save_rates($s,$b,$u,$today);elseif($action==='save_packing')$result=dcd_save_packing($s,$b,$u,$today);else throw new DomainException('Select a valid defaults action.');$s['directorDefaultRequests'][$id]=['fingerprint'=>$fp,'result'=>$result];$s['revision']=(int)($s['revision']??0)+1;$encoded=json_encode($s,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);rewind($h);if(!ftruncate($h,0)||fwrite($h,$encoded)!==strlen($encoded)||!fflush($h))throw new RuntimeException('Save failed.');}
  }
  $out=['ok'=>true,'date'=>$date,'rates'=>dcd_rates($s,$date),'packings'=>dcd_packings($s,$date),'canWrite'=>$canWrite,'csrf'=>$csrf,'result'=>$result,'replay'=>$replay];
 }finally{flock($h,LOCK_UN);fclose($h);}dcd_out($out);
}catch(DomainException|JsonException $e){dcd_out(['ok'=>false,'error'=>$e->getMessage()],$e->getCode()===409?409:422);}catch(Throwable $e){error_log('Director defaults: '.$e->getMessage());dcd_out(['ok'=>false,'error'=>'Rates/defaults could not be loaded or saved. No save is confirmed.'],500);}
