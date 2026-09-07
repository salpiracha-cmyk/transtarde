<?php
declare(strict_types=1);
require dirname(__DIR__) . '/auth_store.php';
header('Content-Type: application/json; charset=UTF-8');
function master_respond(array $data,int $status=200): never { http_response_code($status); echo json_encode($data,JSON_UNESCAPED_SLASHES); exit; }
try {
    $admin=tt_require_login();
    if (($admin['role'] ?? '')!=='Super Admin') master_respond(['ok'=>false,'error'=>'Super Admin access required.'],403);
    if ($_SERVER['REQUEST_METHOD']==='GET') master_respond(['ok'=>true,'masters'=>tt_list_masters()]);
    if ($_SERVER['REQUEST_METHOD']!=='POST') master_respond(['ok'=>false,'error'=>'Method not allowed.'],405);
    $body=json_decode(file_get_contents('php://input') ?: '{}',true);
    if (!is_array($body) || !tt_verify_csrf((string)($body['csrf'] ?? ''))) master_respond(['ok'=>false,'error'=>'Your session expired. Refresh and try again.'],419);
    $types=['companies','parties','products','mills','banks','bags','ports'];
    $type=(string)($body['type'] ?? ''); if (!in_array($type,$types,true)) throw new InvalidArgumentException('Select a valid master section.');
    $action=(string)($body['action'] ?? ''); $id=trim((string)($body['id'] ?? ''));
    if ($action==='delete') { if ($id==='') throw new InvalidArgumentException('Select a master record.'); tt_delete_master($type,$id); tt_audit((int)$admin['id'],$admin['username'],'Deleted '.$type.' master '.$id); master_respond(['ok'=>true,'masters'=>tt_list_masters()]); }
    $name=trim((string)($body['name'] ?? '')); $code=strtoupper(trim((string)($body['code'] ?? ''))); $notes=trim((string)($body['notes'] ?? 'General')) ?: 'General';
    if ($name==='' || strlen($name)>150) throw new InvalidArgumentException('Enter a valid record name.');
    if ($code==='' || strlen($code)>50) throw new InvalidArgumentException('Enter a valid code or reference.');
    $values=[$name,$code,$notes];
    if ($action==='create') { $id=tt_create_master($type,$values); tt_audit((int)$admin['id'],$admin['username'],'Created '.$type.' master '.$code); master_respond(['ok'=>true,'masters'=>tt_list_masters()]); }
    if ($action==='update') { if ($id==='') throw new InvalidArgumentException('Select a master record.'); tt_update_master($type,$id,$values); tt_audit((int)$admin['id'],$admin['username'],'Updated '.$type.' master '.$code); master_respond(['ok'=>true,'masters'=>tt_list_masters()]); }
    master_respond(['ok'=>false,'error'=>'Unknown action.'],400);
} catch (InvalidArgumentException $e) { master_respond(['ok'=>false,'error'=>$e->getMessage()],422); }
catch (Throwable $e) { master_respond(['ok'=>false,'error'=>'The master-record action could not be completed.'],500); }
