<?php
declare(strict_types=1);
require dirname(__DIR__) . '/auth_store.php';
require __DIR__ . '/salary_master_store.php';
header('Content-Type: application/json; charset=UTF-8');
function master_respond(array $data,int $status=200): never { http_response_code($status); echo json_encode($data,JSON_UNESCAPED_SLASHES); exit; }
function master_find_row(string $type,string $id): ?array {
    $masters=tt_list_masters();
    foreach ((array)($masters[$type] ?? []) as $row) if ((string)($row['id'] ?? '')===$id) return $row;
    return null;
}
function master_all(): array {
    $masters=tt_list_masters();
    $masters['salary_staff']=sm_master_rows();
    return $masters;
}
function master_options_for_console(): array {
    $options=tt_master_options();
    $data=tt_read_store();
    $approvedFinishes=[
        'Well milled, silky polished and well sortexed',
        'Well milled, double polished and well sortexed',
        'Reasonably well milled',
        'Colour sortexed',
    ];
    $stored=is_array($data['master_options']['product_finishes'] ?? null)
        ? $data['master_options']['product_finishes'] : [];
    $disabled=array_map(
        static fn($v): string => strtolower(trim((string)$v)),
        (array)($data['master_options_disabled']['product_finishes'] ?? [])
    );
    $clean=[];$seen=[];
    foreach (array_merge($approvedFinishes,$stored) as $finish) {
        $finish=trim(preg_replace('/\s+/',' ',(string)$finish) ?? '');
        if ($finish==='') continue;
        $key=strtolower($finish);
        if (isset($seen[$key]) || in_array($key,$disabled,true)) continue;
        $seen[$key]=true;$clean[]=$finish;
    }
    // Historical/reference Product records retain their saved Finish wording,
    // but they must not automatically become reusable choices for new Products.
    $options['product_finishes']=$clean;
    return $options;
}
try {
    $admin=tt_require_login();
    if (($admin['role'] ?? '')!=='Super Admin') master_respond(['ok'=>false,'error'=>'Super Admin access required.'],403);
    if ($_SERVER['REQUEST_METHOD']==='GET') master_respond(['ok'=>true,'masters'=>master_all(),'options'=>master_options_for_console()]);
    if ($_SERVER['REQUEST_METHOD']!=='POST') master_respond(['ok'=>false,'error'=>'Method not allowed.'],405);
    $body=json_decode(file_get_contents('php://input') ?: '{}',true);
    if (!is_array($body) || !tt_verify_csrf((string)($body['csrf'] ?? ''))) master_respond(['ok'=>false,'error'=>'Your session expired. Refresh and try again.'],419);

    $type=(string)($body['type'] ?? '');
    $action=(string)($body['action'] ?? ''); $id=trim((string)($body['id'] ?? ''));
    if ($action==='manage-option') {
        $optionAction=(string)($body['optionAction'] ?? '');
        $optionKey=(string)($body['optionKey'] ?? '');
        $value=tt_manage_master_option($optionKey,$optionAction,(string)($body['value'] ?? ''),(string)($body['old'] ?? ''));
        tt_audit((int)$admin['id'],$admin['username'],ucfirst($optionAction).' '.$optionKey.' option '.$value);
        master_respond(['ok'=>true,'masters'=>master_all(),'options'=>master_options_for_console(),'value'=>$value]);
    }
    if ($type==='salary_staff') {
        if ($action==='delete') {
            if ($id==='') throw new InvalidArgumentException('Select a staff record.');
            sm_deactivate_master($id,$admin);
            tt_audit((int)$admin['id'],$admin['username'],'Removed staff from active Salary Master '.$id);
            master_respond(['ok'=>true,'masters'=>master_all(),'options'=>master_options_for_console()]);
        }
        $values=$body['values']??null;
        if (!is_array($values)) throw new InvalidArgumentException('Enter the Salary Master details.');
        if ($action==='create') $id=sm_save_master($values,$admin);
        elseif ($action==='update') {
            if ($id==='') throw new InvalidArgumentException('Select a staff record.');
            sm_save_master($values,$admin,$id);
        } else master_respond(['ok'=>false,'error'=>'Unknown Salary Master action.'],400);
        tt_audit((int)$admin['id'],$admin['username'],($action==='create'?'Created ':'Updated ').'Salary Master '.$id);
        master_respond(['ok'=>true,'masters'=>master_all(),'options'=>master_options_for_console()]);
    }

    $schemas=[
        'companies'=>7,'commodities'=>8,'product_settings'=>1,'products'=>22,'purchase_kat'=>10,
        'parties'=>4,'mills'=>4,'banks'=>14,'export_documents'=>5,'export_terms'=>3,
    ];
    if (!isset($schemas[$type])) throw new InvalidArgumentException('Select a valid master section.');
    if ($action==='add-party-role') {
        $role=tt_add_party_role_option((string)($body['role'] ?? ''));
        tt_audit((int)$admin['id'],$admin['username'],'Added Party Role '.$role);
        master_respond(['ok'=>true,'masters'=>master_all(),'options'=>master_options_for_console(),'role'=>$role]);
    }

    // Owner rule: the Super Admin has full lifecycle control over Master
    // Console records. Module lock status never blocks master add/edit/delete.
    if ($action==='delete') {
        if ($id==='') throw new InvalidArgumentException('Select a master record.');
        tt_delete_master($type,$id); tt_audit((int)$admin['id'],$admin['username'],'Deleted '.$type.' master '.$id);
        master_respond(['ok'=>true,'masters'=>master_all(),'options'=>master_options_for_console()]);
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
    if ($type==='product_settings' && !preg_match('/^\d{4}\/\d{4}$/',(string)$values[0])) {
        throw new InvalidArgumentException('Enter Crop Year as YYYY/YYYY, for example 2025/2026.');
    }
    if ($type==='product_settings') {
        [$cropStart,$cropEnd]=array_map('intval',explode('/',(string)$values[0]));
        if ($cropEnd!==$cropStart+1) throw new InvalidArgumentException('Crop Year must contain consecutive years, for example 2025/2026.');
        $existingSettings=(array)(master_all()['product_settings']??[]);
        if ($action==='create' && count($existingSettings)>0) throw new InvalidArgumentException('Current Crop Year already exists. Update the existing value.');
    }
    if ($type==='products') {
        foreach ([1=>'Variety',2=>'Rice type',7=>'Broken',17=>'Finish'] as $field=>$label) {
            if (($values[$field]??'')==='') throw new InvalidArgumentException('Enter '.$label.' for the complete Product Identity.');
        }
        if (preg_match('/\d+(?:\.\d+)?\s*%\s*(?:MAX\s*)?BROKEN/i',(string)$values[2])) {
            throw new InvalidArgumentException('Keep Rice type separate from Broken. For example, use Rice type “White Rice” and Broken “10%”.');
        }
        if (!preg_match('/\d+(?:\.\d+)?\s*%/',(string)$values[7])) {
            throw new InvalidArgumentException('Enter Broken as a percentage, for example 10%.');
        }
        $identity=static function(array $row): string {
            $parts=[(string)($row[1]??''),(string)($row[2]??''),(string)($row[7]??''),(string)($row[17]??'')];
            return strtolower((string)preg_replace('/[^a-z0-9]+/i','',implode('|',$parts)));
        };
        $candidate=$identity($values);
        foreach ((array)(master_all()['products']??[]) as $row) {
            if ($id!=='' && (string)($row['id']??'')===$id) continue;
            if ($candidate!=='' && $identity((array)($row['values']??[]))===$candidate) {
                throw new InvalidArgumentException('This Product Identity already exists. Edit the existing record instead.');
            }
        }
    }

    $reference=strtoupper(trim((string)($values[1] ?? ''))) ?: strtoupper($type);
    if ($action==='create') {
        $id=tt_create_master($type,$values); tt_audit((int)$admin['id'],$admin['username'],'Created '.$type.' master '.$reference);
        master_respond(['ok'=>true,'masters'=>master_all(),'options'=>master_options_for_console()]);
    }
    if ($action==='update') {
        if ($id==='') throw new InvalidArgumentException('Select a master record.');
        tt_update_master($type,$id,$values); tt_audit((int)$admin['id'],$admin['username'],'Updated '.$type.' master '.$reference);
        master_respond(['ok'=>true,'masters'=>master_all(),'options'=>master_options_for_console()]);
    }
    master_respond(['ok'=>false,'error'=>'Unknown action.'],400);
} catch (InvalidArgumentException $e) { master_respond(['ok'=>false,'error'=>$e->getMessage()],422); }
catch (Throwable $e) { master_respond(['ok'=>false,'error'=>'The master-record action could not be completed.'],500); }
