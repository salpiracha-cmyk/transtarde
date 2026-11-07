<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
require_once dirname(__DIR__).'/inventory_reconciliation.php';
require_once __DIR__.'/operations_policy.php';
require_once __DIR__.'/operations_scope.php';
require_once __DIR__.'/bag_transfers_store.php';
require_once __DIR__.'/mill_record_delete_store.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
function mrd_out(array $value,int $status=200): never {http_response_code($status);echo tt_inv_json($value);exit;}
try{
    $user=tt_require_login();
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')mrd_out(['ok'=>false,'error'=>'Method not allowed.'],405);
    $raw=(function_exists('tt_accounts_input')?tt_accounts_input():file_get_contents('php://input'))?:'';
    if(strlen($raw)>1024*1024)mrd_out(['ok'=>false,'error'=>'The saved entry is too large to review for deletion.'],413);
    $body=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    if(!is_array($body)||!tt_verify_csrf((string)($body['csrf']??'')))mrd_out(['ok'=>false,'error'=>'Session expired. Reload before deleting.'],419);
    tt_release_read_session();
    $result=mrd_write($user,$body);$keys=array_fill_keys([(string)$body['collection'],'tt30bags','tt33bagreceipts','tt36bagissues','tt30prodaudit','tt32labourfeed'],true);
    $visible=os_project($user,array_intersect_key($result['values'],$keys),array_intersect_key($result['meta'],$keys));
    $receipt=$result['receipt'];unset($receipt['before'],$receipt['authorization']);
    mrd_out(['ok'=>true,'receipt'=>$receipt]+$visible);
}catch(DomainException|InvalidArgumentException $e){mrd_out(['ok'=>false,'error'=>$e->getMessage()],422);}
catch(Throwable $e){error_log('Mill entry deletion: '.$e->getMessage());mrd_out(['ok'=>false,'error'=>'Deletion could not be confirmed. Keep this screen open and retry the same deletion.'],500);}
