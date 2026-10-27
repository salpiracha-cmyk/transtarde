<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
require_once __DIR__.'/mill_recovery_core.php';
require_once __DIR__.'/mill_gate_pass_core.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
function mgp_out(array $data,int $status=200):never{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit;}
try{
    $user=tt_require_login();if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){header('Allow: POST');mgp_out(['ok'=>false,'error'=>'Use Print Gate Pass on a saved loading row.'],405);}
    $raw=file_get_contents('php://input')?:'';if(strlen($raw)>4096)mgp_out(['ok'=>false,'error'=>'Invalid gate pass request.'],422);$body=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
    if(!is_array($body)||!is_string($body['csrf']??null)||!tt_verify_csrf($body['csrf']))mgp_out(['ok'=>false,'error'=>'Refresh your session before printing.'],419);
    foreach(['key','recordId','loadId'] as $field)if(!is_string($body[$field]??null)||strlen($body[$field])>300||$body[$field]==='')mgp_out(['ok'=>false,'error'=>'Reopen the saved loading row.'],422);
    tt_release_read_session();$source=mr_read_operations_source(TT_DATA_DIR.'/operations.json',['tt30ship','tt35localsales','tt35exportersale','transtrade_export_v3_operational']);
    $document=mgp_document($source['values']??[],$user,$body['key'],$body['recordId'],$body['loadId']);tt_ensure_data_dir();$document=mgp_issue(TT_DATA_DIR.'/gate-pass-register.json',$document,$user);mgp_out(['ok'=>true,'document'=>$document]);
}catch(DomainException $e){mgp_out(['ok'=>false,'error'=>$e->getMessage()],403);}catch(InvalidArgumentException|JsonException $e){mgp_out(['ok'=>false,'error'=>'Invalid gate pass source or request. Reopen and review the saved loading.'],422);}catch(Throwable $e){error_log('Gate pass: '.$e->getMessage());mgp_out(['ok'=>false,'error'=>'Gate pass could not be issued. Retry the same saved loading; no duplicate number will be created.'],500);}
