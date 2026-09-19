<?php
declare(strict_types=1);
require dirname(__DIR__) . '/auth_store.php';
header('Content-Type: application/json; charset=UTF-8');

function respond(array $data, int $status=200): never { http_response_code($status); echo json_encode($data, JSON_UNESCAPED_SLASHES); exit; }
function clean_input(array $body): array {
    $name=trim((string)($body['name'] ?? '')); $username=strtolower(trim((string)($body['username'] ?? '')));
    $role=trim((string)($body['role'] ?? '')); $location=trim((string)($body['location'] ?? 'All authorized locations'));
    $allowedRoles=['Director','Mill Manager','Mill Staff','Accounts','Exports','Auditor / Reports'];
    if ($name==='' || strlen($name)>100) throw new InvalidArgumentException('Enter the staff member’s full name.');
    if (!preg_match('/^[a-z0-9._-]{3,40}$/',$username)) throw new InvalidArgumentException('Username must contain 3–40 letters, numbers, dots, dashes or underscores.');
    if (!in_array($role,$allowedRoles,true)) throw new InvalidArgumentException('Select a valid staff role.');
    $allowedActions=['View','Create','Edit','Approve','Reports']; $allowedModules=['Mill','Exports','Accounts','Directors']; $permissions=[];
    foreach ((array)($body['permissions'] ?? []) as $module=>$icons) {
        if (!in_array($module,$allowedModules,true)) continue;
        foreach ((array)$icons as $icon=>$actions) {
            if (!preg_match('/^[a-z0-9_-]{1,60}$/',(string)$icon)) continue;
            $clean=array_values(array_unique(array_intersect($allowedActions,(array)$actions)));
            if ($clean && !in_array('View',$clean,true)) array_unshift($clean,'View');
            if ($clean) $permissions[$module][(string)$icon]=$clean;
        }
    }
    $masterAccess=!empty($body['masterAccess']);
    $allowedMasterActions=['Use','View','Create','Edit','Deactivate','View Documents','Download Documents'];
    $allowedMasterTypes=['companies','export_customers','business_parties','products','purchase_products','purchase_kat','commodities','product_settings','mills','export_documents','export_terms','salary_staff'];
    $masterPermissions=[];
    foreach ((array)($body['masterPermissions'] ?? []) as $type=>$actions) {
        if (!in_array($type,$allowedMasterTypes,true)) continue;
        $clean=array_values(array_unique(array_intersect($allowedMasterActions,(array)$actions)));
        if (array_intersect(['Create','Edit','Deactivate','View Documents','Download Documents'],$clean) && !in_array('View',$clean,true)) array_unshift($clean,'View');
        if ($clean) $masterPermissions[$type]=$clean;
    }
    if (!$permissions && !$masterAccess) throw new InvalidArgumentException('Allow at least one module or Master Records permission.');
    if ($masterAccess && !$masterPermissions) throw new InvalidArgumentException('Select at least one permitted Master Record.');
    return ['name'=>$name,'username'=>$username,'role'=>$role,'location'=>$location ?: 'All authorized locations','permissions'=>$permissions,'masterAccess'=>$masterAccess,'masterPermissions'=>$masterPermissions,'active'=>!empty($body['active'])];
}

try {
    $admin=tt_require_login();
    if (($admin['role'] ?? '')!=='Super Admin') respond(['ok'=>false,'error'=>'Super Admin access required.'],403);
    if ($_SERVER['REQUEST_METHOD']==='GET') respond(['ok'=>true,'users'=>tt_list_public_users()]);
    if ($_SERVER['REQUEST_METHOD']!=='POST') respond(['ok'=>false,'error'=>'Method not allowed.'],405);
    $body=json_decode(file_get_contents('php://input') ?: '{}',true);
    if (!is_array($body) || !tt_verify_csrf((string)($body['csrf'] ?? ''))) respond(['ok'=>false,'error'=>'Your session expired. Refresh and try again.'],419);
    $action=(string)($body['action'] ?? '');
    if ($action==='create') {
        $input=clean_input($body); $created=tt_create_staff_user($input);
        tt_audit((int)$admin['id'],$admin['username'],'Created user '.$input['username']);
        respond(['ok'=>true,'username'=>$input['username'],'temporaryPassword'=>$created['temporaryPassword'],'users'=>tt_list_public_users()]);
    }
    $id=(int)($body['id'] ?? 0); if ($id<=0) throw new InvalidArgumentException('Select a valid user.');
    if ($action==='update') { $input=clean_input($body); tt_update_staff_user($id,$input); tt_audit((int)$admin['id'],$admin['username'],'Updated user '.$input['username']); respond(['ok'=>true,'users'=>tt_list_public_users()]); }
    if ($action==='delete') { tt_delete_staff_user($id); tt_audit((int)$admin['id'],$admin['username'],'Deleted staff user '.$id); respond(['ok'=>true,'users'=>tt_list_public_users()]); }
    if ($action==='reset-password') { $user=tt_find_user_by_id($id); $temporary=tt_reset_staff_password($id); tt_audit((int)$admin['id'],$admin['username'],'Reset password for '.($user['username'] ?? $id)); respond(['ok'=>true,'username'=>$user['username'] ?? '', 'temporaryPassword'=>$temporary]); }
    respond(['ok'=>false,'error'=>'Unknown action.'],400);
} catch (InvalidArgumentException $e) { respond(['ok'=>false,'error'=>$e->getMessage()],422); }
catch (Throwable $e) { respond(['ok'=>false,'error'=>'The user action could not be completed.'],500); }
