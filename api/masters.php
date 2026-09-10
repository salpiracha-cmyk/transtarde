<?php
declare(strict_types=1);
require dirname(__DIR__) . '/auth_store.php';
header('Content-Type: application/json; charset=UTF-8');
function master_respond(array $data,int $status=200): never { http_response_code($status); echo json_encode($data,JSON_UNESCAPED_SLASHES); exit; }
function master_find_row(string $type,string $id): ?array {
    $masters=tt_list_masters();
    foreach ((array)($masters[$type] ?? []) as $row) if ((string)($row['id'] ?? '')===$id) return $row;
    return null;
}
function master_is_export_buyer(?array $row): bool {
    $values=is_array($row['values'] ?? null)?$row['values']:[];
    return (bool)preg_match('/\bbuyer\b/i',(string)($values[2] ?? ''));
}
try {
    $admin=tt_require_login();
    if (($admin['role'] ?? '')!=='Super Admin') master_respond(['ok'=>false,'error'=>'Super Admin access required.'],403);
    if ($_SERVER['REQUEST_METHOD']==='GET') master_respond(['ok'=>true,'masters'=>tt_list_masters(),'options'=>tt_master_options()]);
    if ($_SERVER['REQUEST_METHOD']!=='POST') master_respond(['ok'=>false,'error'=>'Method not allowed.'],405);
    $body=json_decode(file_get_contents('php://input') ?: '{}',true);
    if (!is_array($body) || !tt_verify_csrf((string)($body['csrf'] ?? ''))) master_respond(['ok'=>false,'error'=>'Your session expired. Refresh and try again.'],419);

    $schemas=[
        'companies'=>7,'commodities'=>8,'products'=>21,'purchase_kat'=>10,
        'parties'=>4,'mills'=>4,'banks'=>14,'export_documents'=>5,'export_terms'=>3,
    ];
    $type=(string)($body['type'] ?? '');
    if (!isset($schemas[$type])) throw new InvalidArgumentException('Select a valid master section.');
    $action=(string)($body['action'] ?? ''); $id=trim((string)($body['id'] ?? ''));
    if ($action==='add-party-role') {
        $role=tt_add_party_role_option((string)($body['role'] ?? ''));
        tt_audit((int)$admin['id'],$admin['username'],'Added Party Role '.$role);
        master_respond(['ok'=>true,'masters'=>tt_list_masters(),'options'=>tt_master_options(),'role'=>$role]);
    }
    if ($action==='delete') {
        if ($id==='') throw new InvalidArgumentException('Select a master record.');
        if ($type==='parties' && master_is_export_buyer(master_find_row($type,$id))) {
            throw new InvalidArgumentException('Export Buyer records are controlled by Customer Management so shipment history can be protected. Use CUSTOMER MANAGEMENT to amend, archive or delete this customer.');
        }
        tt_delete_master($type,$id); tt_audit((int)$admin['id'],$admin['username'],'Deleted '.$type.' master '.$id);
        master_respond(['ok'=>true,'masters'=>tt_list_masters(),'options'=>tt_master_options()]);
    }

    $raw=$body['values'] ?? null;
    if (!is_array($raw)) throw new InvalidArgumentException('Enter the master record details.');
    $values=[];
    foreach (array_slice($raw,0,$schemas[$type]) as $value) {
        if (is_array($value) || is_object($value)) throw new InvalidArgumentException('Master fields must contain text values.');
        $value=trim((string)$value);
        if (strlen($value)>1200) throw new InvalidArgumentException('One of the master fields is too long.');
        $values[]=$value;
    }
    while (count($values)<$schemas[$type]) $values[]='';
    if (($values[0] ?? '')==='') throw new InvalidArgumentException('Enter the main record name / commodity / product.');
    if (in_array($type,['companies','commodities'],true) && ($values[1] ?? '')==='') throw new InvalidArgumentException('Enter the short code.');

    if ($type==='parties') {
        $existing=$id!==''?master_find_row($type,$id):null;
        if (master_is_export_buyer($existing) || preg_match('/\bbuyer\b/i',(string)($values[2] ?? ''))) {
            throw new InvalidArgumentException('Export Buyer records are controlled by Customer Management. Use CUSTOMER MANAGEMENT so address, contacts, notify parties and shipment history remain synchronized with Exports.');
        }
    }

    $reference=strtoupper(trim((string)($values[1] ?? ''))) ?: strtoupper($type);
    if ($action==='create') {
        $id=tt_create_master($type,$values); tt_audit((int)$admin['id'],$admin['username'],'Created '.$type.' master '.$reference);
        master_respond(['ok'=>true,'masters'=>tt_list_masters(),'options'=>tt_master_options()]);
    }
    if ($action==='update') {
        if ($id==='') throw new InvalidArgumentException('Select a master record.');
        tt_update_master($type,$id,$values); tt_audit((int)$admin['id'],$admin['username'],'Updated '.$type.' master '.$reference);
        master_respond(['ok'=>true,'masters'=>tt_list_masters(),'options'=>tt_master_options()]);
    }
    master_respond(['ok'=>false,'error'=>'Unknown action.'],400);
} catch (InvalidArgumentException $e) { master_respond(['ok'=>false,'error'=>$e->getMessage()],422); }
catch (Throwable $e) { master_respond(['ok'=>false,'error'=>'The master-record action could not be completed.'],500); }
