<?php
declare(strict_types=1);
require dirname(__DIR__) . '/auth_store.php';
header('Content-Type: application/json; charset=UTF-8');
function location_respond(array $data,int $status=200): never { http_response_code($status); echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); exit; }
function location_can_write(array $user): bool {
    if (($user['role'] ?? '')==='Super Admin') return true;
    foreach (['Mill','Exports','Accounts'] as $module) {
        $p=$user['permissions'][$module] ?? null;
        if ($p==='all') return true;
        if (is_array($p) && (in_array('Create',$p,true) || in_array('Edit',$p,true))) return true;
        foreach ((array)$p as $actions) if (is_array($actions) && (in_array('Create',$actions,true) || in_array('Edit',$actions,true))) return true;
    }
    return false;
}
try {
    $user=tt_require_login();
    if ($_SERVER['REQUEST_METHOD']==='GET') location_respond(['ok'=>true,'locations'=>tt_active_location_masters()]);
    if ($_SERVER['REQUEST_METHOD']!=='POST') location_respond(['ok'=>false,'error'=>'Method not allowed.'],405);
    if (!location_can_write($user)) location_respond(['ok'=>false,'error'=>'Create or Edit permission is required.'],403);
    $body=json_decode(file_get_contents('php://input') ?: '{}',true);
    if (!is_array($body) || !tt_verify_csrf((string)($body['csrf'] ?? ''))) location_respond(['ok'=>false,'error'=>'Your session expired. Refresh and try again.'],419);
    $action=strtolower(trim((string)($body['action']??'add')));
    if($action==='deactivate'){
        $row=tt_deactivate_location_master(trim((string)($body['id']??'')));
        $name=(string)(($row['values']??[])[0]??'Location');
        tt_audit((int)($user['id']??0),(string)($user['username']??''),'Deactivated Location Master '.$name.' from future selection');
        location_respond(['ok'=>true,'location'=>$row,'locations'=>tt_active_location_masters()]);
    }
    if($action!=='add')throw new InvalidArgumentException('Select add or deactivate.');
    $name=trim((string)($body['name'] ?? ''));$type=tt_normalize_location_type(trim((string)($body['type'] ?? '')));
    if(!in_array($type,['Own Mill','Reprocessing Mill','External Mill','Warehouse','Office','Stock Location','Other'],true))throw new InvalidArgumentException('Select a valid Location Type.');
    $source=trim((string)($body['source'] ?? 'Operational workflow'));
    $row=tt_upsert_location_master($name,$type,$source);
    $values=array_values((array)($row['values']??[]));while(count($values)<7)$values[]='';
    $address=trim((string)($body['address']??''));$contact=trim((string)($body['contact']??''));
    if($address!==''||$contact!==''){$values[3]=$address?:$values[3];$values[4]=$contact?:$values[4];tt_update_master('mills',(string)$row['id'],$values);$row['values']=$values;}
    tt_audit((int)($user['id'] ?? 0),(string)($user['username'] ?? ''),'Location master linked '.$name.' from '.$source);
    location_respond(['ok'=>true,'location'=>$row,'locations'=>tt_active_location_masters()]);
} catch (InvalidArgumentException $e) { location_respond(['ok'=>false,'error'=>$e->getMessage()],422); }
catch (Throwable $e) { location_respond(['ok'=>false,'error'=>'The location master could not be updated.'],500); }
