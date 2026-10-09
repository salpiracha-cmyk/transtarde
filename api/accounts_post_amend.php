<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
require __DIR__.'/accounts_post_amend_core.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
function apa_out(array $body,int $status=200):never{http_response_code($status);echo json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
try{
    $user=tt_require_login();
    if(!tt_user_can_open_module($user,'Accounts'))apa_out(['ok'=>false,'error'=>'Accounts permission required.'],403);
    if($_SERVER['REQUEST_METHOD']!=='POST')apa_out(['ok'=>false,'error'=>'Method not allowed.'],405);
    $body=json_decode((function_exists('tt_accounts_input')?tt_accounts_input():file_get_contents('php://input'))?:'{}',true);
    if(!is_array($body)||!tt_verify_csrf((string)($body['csrf']??'')))apa_out(['ok'=>false,'error'=>'Your session expired. Refresh and try again.'],419);
    $postId=trim((string)($body['postId']??''));
    if($postId==='')apa_out(['ok'=>false,'error'=>'Select a Post ID.'],422);
    tt_ensure_data_dir();$handle=fopen(TT_DATA_DIR.'/accounts.json','c+');
    if($handle===false||!flock($handle,LOCK_EX))throw new RuntimeException('Accounts storage unavailable.');
    try{
        rewind($handle);$raw=stream_get_contents($handle);$store=$raw?json_decode($raw,true):null;
        if(!is_array($store))throw new RuntimeException('Accounts storage unavailable.');
        $entity=(string)($store['journals'][$postId]['entity']??'');
        $original=$store['journals'][$postId]??null;if(is_array($original)&&!tt_post_correction_allowed($user,$original))apa_out(['ok'=>false,'error'=>'Edit permission for the original workflow, or Journal Voucher approval for a JV, is required.'],403);
        if(is_array($original)&&in_array((string)($original['meta']['originalSourceType']??$original['sourceType']??''),['JV','JV_REVERSAL'],true)&&!tt_user_can_access_entity($user,$entity,'Approve'))apa_out(['ok'=>false,'error'=>'Company approval permission is required for a Journal Voucher correction.'],403);
        if(!tt_user_can_access_entity($user,$entity,'Edit'))apa_out(['ok'=>false,'error'=>'Accounts Edit permission for this company is required.'],403);
        if(is_array($original)&&(($original['sourceType']??'')==='OPENING_BALANCE_BF'||!empty($original['meta']['openingBalance']))&&(!job_authorized($user)||!in_array($entity,job_entities($user),true)||!tt_user_can_access_entity($user,$entity,'Approve')))apa_out(['ok'=>false,'error'=>'Opening balances require management and company approval permission.'],403);
        if(($body['action']??'')==='delete_post'){require_once __DIR__.'/accounts_post_delete_core.php';$result=apd_delete($store,$user,$postId,$body);}else $result=apa_correct($store,$user,$postId,$body);
        $store['revision']=(int)($store['revision']??0)+1;
        $encoded=json_encode($store,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        rewind($handle);if(!ftruncate($handle,0)||fwrite($handle,$encoded)===false)throw new RuntimeException('Accounts storage could not be updated.');fflush($handle);
    }finally{flock($handle,LOCK_UN);fclose($handle);}
    apa_out(['ok'=>true,'result'=>$result]);
}catch(DomainException $error){apa_out(['ok'=>false,'error'=>$error->getMessage()],422);}
catch(Throwable $error){error_log('Post amendment: '.$error->getMessage());apa_out(['ok'=>false,'error'=>'The amendment could not be saved. No correction was posted.'],500);}
