<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
require_once __DIR__.'/third_party_register_core.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');
function tpr_out(array $value,int $status=200):never {http_response_code($status);echo json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit;}
try{
    $user=tt_require_login();$method=$_SERVER['REQUEST_METHOD']??'GET';if(!in_array($method,['GET','POST'],true))tpr_out(['ok'=>false,'error'=>'Method not allowed.'],405);
    $write=$method==='POST';$raw=$write?(function_exists('tt_accounts_input')?tt_accounts_input():file_get_contents('php://input')):'{}';if(strlen((string)$raw)>16000)throw new InvalidArgumentException('Register request is too large.');$body=json_decode($raw?:'{}',true,32,JSON_THROW_ON_ERROR);if(!is_array($body))throw new InvalidArgumentException('Invalid register request.');
    $entity=$body['entity']??$_GET['entity']??'';$right=$write?(($body['action']??'')==='create'?'Create':'Edit'):'View';
    if(!is_string($entity)||!in_array($entity,['TTI','BRM','TG'],true)||!tpr_permission($user,'View')||!tt_user_can_access_entity($user,$entity,'View')||!tpr_permission($user,$right)||!tt_user_can_access_entity($user,$entity,$right))tpr_out(['ok'=>false,'error'=>'Company and supplier-register permission required.'],403);
    if($write&&!tt_verify_csrf((string)($body['csrf']??'')))tpr_out(['ok'=>false,'error'=>'Session expired. Refresh and try again.'],419);tt_release_read_session();
    $path=TT_DATA_DIR.'/third-party-payments-register.json';$store=['revision'=>0,'rows'=>[]];$record=null;$duplicate=false;
    if(!$write&&!is_file($path))tpr_out(['ok'=>true,'rows'=>[],'revision'=>0,'canCreate'=>tpr_permission($user,'Create')&&tt_user_can_access_entity($user,$entity,'Create'),'canEdit'=>tpr_permission($user,'Edit')&&tt_user_can_access_entity($user,$entity,'Edit')]);
    if($write)tt_ensure_data_dir();$lock=fopen($write?$path.'.lock':$path,$write?'c':'rb');if(!$lock||!flock($lock,$write?LOCK_EX:LOCK_SH))throw new RuntimeException('Register is busy.');$temporary=null;
    try{
        if(is_file($path)){$contents=$write?file_get_contents($path):stream_get_contents($lock);$store=json_decode((string)$contents,true,512,JSON_THROW_ON_ERROR);if(!is_array($store['rows']??null))throw new RuntimeException('Register source is invalid.');}
        if($write){$result=tpr_change($store,$body,$user,$entity);$record=$result['record'];$duplicate=$result['duplicate'];if(!$duplicate){$store=$result['store'];$encoded=json_encode($store,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$temporary=tempnam(TT_DATA_DIR,'.tmp-third-party-');if(!$temporary||file_put_contents($temporary,$encoded)!==strlen($encoded)||!rename($temporary,$path))throw new RuntimeException('Register could not be saved.');$temporary=null;}}
    }finally{if($temporary&&is_file($temporary))unlink($temporary);flock($lock,LOCK_UN);fclose($lock);}
    tpr_out(['ok'=>true,'rows'=>tpr_public($store,$entity),'revision'=>(int)$store['revision'],'record'=>$record?array_diff_key($record,array_flip(['requestKey','requestFingerprint','history'])):null,'duplicate'=>$duplicate,'canCreate'=>tpr_permission($user,'Create')&&tt_user_can_access_entity($user,$entity,'Create'),'canEdit'=>tpr_permission($user,'Edit')&&tt_user_can_access_entity($user,$entity,'Edit')]);
}catch(InvalidArgumentException|JsonException $e){tpr_out(['ok'=>false,'error'=>$e->getMessage()],422);}catch(Throwable $e){error_log('Third-party register: '.$e->getMessage());tpr_out(['ok'=>false,'error'=>'Register could not be read or saved. Your accounting entries are unaffected.'],500);}
