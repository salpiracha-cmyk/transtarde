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
function master_all(?array $user=null): array {
    $masters=$user ? tt_user_visible_masters($user) : tt_list_masters();
    if (!$user || tt_user_can_master($user,'salary_staff','View')) $masters['salary_staff']=sm_master_rows();
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
    if (!tt_user_can_access_masters($admin)) master_respond(['ok'=>false,'error'=>'Master Records access required.'],403);
    if ($_SERVER['REQUEST_METHOD']==='GET') master_respond(['ok'=>true,'masters'=>master_all($admin),'options'=>master_options_for_console()]);
    if ($_SERVER['REQUEST_METHOD']!=='POST') master_respond(['ok'=>false,'error'=>'Method not allowed.'],405);
    $body=json_decode(file_get_contents('php://input') ?: '{}',true);
    if (!is_array($body) || !tt_verify_csrf((string)($body['csrf'] ?? ''))) master_respond(['ok'=>false,'error'=>'Your session expired. Refresh and try again.'],419);

    $type=(string)($body['type'] ?? '');
    $action=(string)($body['action'] ?? ''); $id=trim((string)($body['id'] ?? ''));
    $requiredAction=$action==='create'?'Create':($action==='update'?'Edit':($action==='delete'?'Deactivate':'Edit'));
    if ($type!=='' && !tt_user_can_master($admin,$type,$requiredAction)) master_respond(['ok'=>false,'error'=>'You do not have '.$requiredAction.' permission for this master.'],403);
    if ($action==='manage-option') {
        $optionAction=(string)($body['optionAction'] ?? '');
        $optionKey=(string)($body['optionKey'] ?? '');
        $optionMaster=$type==='reference_lists'?'reference_lists':($optionKey==='party_roles'?'business_parties':'products');
        if (!tt_user_can_master($admin,$optionMaster,'Edit')) master_respond(['ok'=>false,'error'=>'Edit permission is required for this master option.'],403);
        $value=tt_manage_master_option($optionKey,$optionAction,(string)($body['value'] ?? ''),(string)($body['old'] ?? ''));
        tt_audit((int)$admin['id'],$admin['username'],ucfirst($optionAction).' '.$optionKey.' option '.$value);
        master_respond(['ok'=>true,'masters'=>master_all($admin),'options'=>master_options_for_console(),'value'=>$value]);
    }
    if ($type==='salary_staff') {
        if ($action==='delete') {
            if ($id==='') throw new InvalidArgumentException('Select a staff record.');
            sm_deactivate_master($id,$admin);
            tt_audit((int)$admin['id'],$admin['username'],'Removed staff from active Salary Master '.$id);
            master_respond(['ok'=>true,'masters'=>master_all($admin),'options'=>master_options_for_console()]);
        }
        $values=$body['values']??null;
        if (!is_array($values)) throw new InvalidArgumentException('Enter the Salary Master details.');
        if ($action==='create') $id=sm_save_master($values,$admin);
        elseif ($action==='update') {
            if ($id==='') throw new InvalidArgumentException('Select a staff record.');
            sm_save_master($values,$admin,$id);
        } else master_respond(['ok'=>false,'error'=>'Unknown Salary Master action.'],400);
        tt_audit((int)$admin['id'],$admin['username'],($action==='create'?'Created ':'Updated ').'Salary Master '.$id);
        master_respond(['ok'=>true,'id'=>$id,'masters'=>master_all($admin),'options'=>master_options_for_console()]);
    }

    $schemas=[
        'companies'=>15,'export_customers'=>22,'business_parties'=>12,'commodities'=>8,'product_settings'=>1,'products'=>22,'purchase_products'=>10,'purchase_kat'=>10,
        'mills'=>7,'export_documents'=>5,'export_terms'=>3,
    ];
    if (!isset($schemas[$type])) throw new InvalidArgumentException('Select a valid master section.');
    if ($action==='add-party-role') {
        $role=tt_add_party_role_option((string)($body['role'] ?? ''));
        tt_audit((int)$admin['id'],$admin['username'],'Added Party Role '.$role);
        master_respond(['ok'=>true,'masters'=>master_all($admin),'options'=>master_options_for_console(),'role'=>$role]);
    }

    // Owner rule: the Super Admin has full lifecycle control over Master
    // Console records. Module lock status never blocks master add/edit/delete.
    if ($action==='delete') {
        if ($id==='') throw new InvalidArgumentException('Select a master record.');
            $statusFields=['export_customers'=>10,'business_parties'=>10,'purchase_products'=>8,'purchase_kat'=>7,'mills'=>5,'export_documents'=>4,'export_terms'=>2];
        if (isset($statusFields[$type])) {
            $row=master_find_row($type,$id);if(!$row)throw new InvalidArgumentException('Master record not found.');
            $values=array_values((array)($row['values']??[]));while(count($values)<=$statusFields[$type])$values[]='';$values[$statusFields[$type]]='Inactive';tt_update_master($type,$id,$values);
            tt_audit((int)$admin['id'],$admin['username'],'Deactivated '.$type.' master '.$id);
        } elseif ($type==='products') {
            $row=master_find_row($type,$id);if(!$row)throw new InvalidArgumentException('Master record not found.');$values=array_values((array)($row['values']??[]));while(count($values)<22)$values[]='';$values[5]=trim(preg_replace('/\s*–?\s*inactive$/i','',(string)$values[5]).' – inactive');tt_update_master($type,$id,$values);tt_audit((int)$admin['id'],$admin['username'],'Deactivated product master '.$id);
        } else {
            throw new InvalidArgumentException('This core identity cannot be erased because operational history may reference it. Update the record or mark its usage inactive instead.');
        }
        master_respond(['ok'=>true,'id'=>$id,'masters'=>master_all($admin),'options'=>master_options_for_console()]);
    }

    $raw=$body['values'] ?? null;
    if (!is_array($raw)) throw new InvalidArgumentException('Enter the master record details.');
    $values=[];
    foreach (array_slice($raw,0,$schemas[$type]) as $fieldIndex=>$value) {
        if (is_array($value) || is_object($value)) throw new InvalidArgumentException('Master fields must contain text values.');
        $value=trim((string)$value);
        $nested=($type==='companies'&&in_array((int)$fieldIndex,[13,14],true))||($type==='export_customers'&&in_array((int)$fieldIndex,[9,16,17,18,19],true))||($type==='purchase_kat'&&(int)$fieldIndex===9);
        $limit=$nested?50000:1200;
        if (strlen($value)>$limit) throw new InvalidArgumentException('One of the master fields is too long.');
        $values[]=$value;
    }
    while (count($values)<$schemas[$type]) $values[]='';
    if (($values[0] ?? '')==='') throw new InvalidArgumentException('Enter the main record name / commodity / product.');
    if (in_array($type,['companies','commodities'],true) && ($values[1] ?? '')==='') throw new InvalidArgumentException('Enter the short code.');
    if ($type==='companies') {
        foreach ([13=>'bank accounts',14=>'document identities'] as $field=>$label) {
            $decoded=json_decode((string)($values[$field]??'[]'),true);
            if (!is_array($decoded)) throw new InvalidArgumentException('The '.$label.' could not be read. Reopen the company and try again.');
            if ($field===14) {
                $defaults=[];
                foreach ($decoded as $document) if (is_array($document)&&!empty($document['isDefault'])&&strcasecmp((string)($document['status']??'Active'),'Inactive')!==0) {
                    $documentType=(string)($document['type']??'Other');if(isset($defaults[$documentType]))throw new InvalidArgumentException('Only one active default is allowed for each company document type.');$defaults[$documentType]=true;
                }
            }
        }
    }
    if (in_array($type,['companies','export_customers','business_parties','mills'],true)) {
        $normalise=static fn(string $value): string=>strtolower((string)preg_replace('/[^a-z0-9]+/i','',trim($value)));
        $candidate=$normalise((string)$values[0]);
        foreach ((array)(master_all($admin)[$type]??[]) as $existingRow) {
            if ($id!==''&&(string)($existingRow['id']??'')===$id) continue;
            $existingName=(string)(($existingRow['values']??[])[0]??'');
            if ($candidate!==''&&$normalise($existingName)===$candidate) throw new InvalidArgumentException('A similar record already exists as “'.$existingName.'”. Open that record instead of creating a duplicate.');
        }
    }
    if ($type==='product_settings' && !preg_match('/^\d{4}\/\d{4}$/',(string)$values[0])) {
        throw new InvalidArgumentException('Enter Crop Year as YYYY/YYYY, for example 2025/2026.');
    }
    if ($type==='product_settings') {
        [$cropStart,$cropEnd]=array_map('intval',explode('/',(string)$values[0]));
        if ($cropEnd!==$cropStart+1) throw new InvalidArgumentException('Crop Year must contain consecutive years, for example 2025/2026.');
        $existingSettings=(array)(master_all($admin)['product_settings']??[]);
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
            foreach ((array)(master_all($admin)['products']??[]) as $row) {
            if ($id!=='' && (string)($row['id']??'')===$id) continue;
            if ($candidate!=='' && $identity((array)($row['values']??[]))===$candidate) {
                throw new InvalidArgumentException('This Product Identity already exists. Edit the existing record instead.');
            }
        }
    }
    if ($type==='purchase_products') {
        $values[0]=strtoupper($values[0]);
        $values[1]=tt_product_base($values[1]);
        $values[2]=$values[0]==='RICE'?tt_product_type($values[2]):trim($values[2]);
        $values[3]=tt_product_stage($values[3]);
        if ($values[1]==='' || !in_array($values[3],['RAW','READY'],true)) throw new InvalidArgumentException('Select a base product and choose RAW or READY. FINAL is created only by TTI/reprocessing production.');
        if (!in_array($values[0],['RICE','CORN','SESAME'],true)) throw new InvalidArgumentException('Commodity must be RICE, CORN or SESAME.');
        if ($values[0]==='RICE') {
            if($values[2]==='')throw new InvalidArgumentException('Select the Rice Type, for example White, Parboiled / Sella or Steam.');
            $known=false;
            foreach ((array)(master_all($admin)['products']??[]) as $product) if (strcasecmp(tt_product_base((string)($product['values'][1]??'')),$values[1])===0) {$known=true;break;}
            if (!$known) throw new InvalidArgumentException('Create the Rice base variety in Export Quality & Specs first so both areas share one identity.');
            if(trim((string)$values[5])!==''){$kat=master_find_row('purchase_kat',(string)$values[5]);if(!$kat)throw new InvalidArgumentException('Select a saved KAT Profile from the list.');$kv=(array)($kat['values']??[]);if(strcasecmp((string)($kv[0]??''),$values[0])!==0||strcasecmp(tt_product_base((string)($kv[1]??'')),$values[1])!==0||strcasecmp(tt_product_type((string)($kv[2]??'')),$values[2])!==0||strcasecmp(tt_product_stage((string)($kv[3]??'')),$values[3])!==0)throw new InvalidArgumentException('The selected KAT Profile belongs to a different variety, rice type or purchase classification.');}
        }
        $candidate=strtolower($values[0].'|'.$values[1].'|'.$values[2].'|'.$values[3]);
        foreach ((array)(master_all($admin)['purchase_products']??[]) as $row) {
            if ($id!=='' && (string)($row['id']??'')===$id) continue;
            $v=tt_purchase_product_values((array)($row['values']??[]));
            if (strtolower((string)$v[0].'|'.(string)$v[1].'|'.(string)$v[2].'|'.(string)$v[3])===$candidate) throw new InvalidArgumentException('This variety, rice type and purchase classification already exists. Edit it instead.');
        }
    }
    if($type==='purchase_kat'){
        $values[0]=strtoupper($values[0]);$values[1]=tt_product_base($values[1]);$values[2]=tt_product_type($values[2]);$values[3]=tt_product_stage($values[3]);
        if($values[0]===''||$values[1]===''||($values[0]==='RICE'&&$values[2]==='')||!in_array($values[3],['RAW','READY'],true))throw new InvalidArgumentException('Select the exact commodity, variety, rice type and RAW/READY purchase classification.');
        if(trim((string)$values[4])==='')throw new InvalidArgumentException('Enter a clear KAT profile name.');if($values[5]!==''&&$values[6]!==''&&$values[6]<$values[5])throw new InvalidArgumentException('KAT Effective To cannot be before Effective From.');
        $match=false;foreach((array)(master_all($admin)['purchase_products']??[]) as $product){$p=tt_purchase_product_values((array)($product['values']??[]));if(strcasecmp($p[0],$values[0])===0&&strcasecmp($p[1],$values[1])===0&&strcasecmp($p[2],$values[2])===0&&strcasecmp($p[3],$values[3])===0){$match=true;break;}}
        if(!$match)throw new InvalidArgumentException('Create the matching Purchase Product first, then attach its KAT profile.');
        $parameters=json_decode((string)$values[9],true);if(!is_array($parameters))throw new InvalidArgumentException('The KAT parameters could not be read. Reopen the profile and try again.');if(count($parameters)===0)throw new InvalidArgumentException('Add at least one Quality Parameter to the KAT profile.');
        $seenParameters=[];foreach($parameters as $parameter){if(!is_array($parameter))throw new InvalidArgumentException('A KAT parameter is invalid.');$name=trim((string)($parameter['name']??''));if($name==='')throw new InvalidArgumentException('Enter every Quality Parameter name.');$token=strtolower((string)preg_replace('/[^a-z0-9]+/i','',$name));if(isset($seenParameters[$token]))throw new InvalidArgumentException('Quality Parameter “'.$name.'” appears more than once.');$seenParameters[$token]=true;$previousTo=null;foreach((array)($parameter['ranges']??[]) as $range){$from=trim((string)($range['from']??''));$to=trim((string)($range['to']??''));$value=trim((string)($range['value']??''));if($from===''||!is_numeric($from)||($to!==''&&!is_numeric($to))||$value===''||!is_numeric($value))throw new InvalidArgumentException('Every KAT range requires valid From and Deduction figures; To may be blank only for the final open range.');$fromNumber=(float)$from;$toNumber=$to===''?null:(float)$to;if($toNumber!==null&&$toNumber<=$fromNumber)throw new InvalidArgumentException('A KAT range “To” value must be greater than its “From” value.');if($previousTo===null&&isset($hasOpenRange))throw new InvalidArgumentException('No range can follow an open-ended KAT range.');if($previousTo!==null&&$fromNumber<$previousTo)throw new InvalidArgumentException('KAT ranges cannot overlap.');$previousTo=$toNumber;if($toNumber===null)$hasOpenRange=true;}unset($hasOpenRange);}
        $candidate=strtolower($values[0].'|'.$values[1].'|'.$values[2].'|'.$values[3].'|'.$values[4]);foreach((array)(master_all($admin)['purchase_kat']??[]) as$row){if($id!==''&&(string)($row['id']??'')===$id)continue;$v=array_values((array)($row['values']??[]));while(count($v)<5)$v[]='';if(strtolower($v[0].'|'.$v[1].'|'.$v[2].'|'.$v[3].'|'.$v[4])===$candidate)throw new InvalidArgumentException('This KAT profile already exists. Open and edit it instead.');}
    }

    $reference=strtoupper(trim((string)($values[1] ?? ''))) ?: strtoupper($type);
    if ($action==='create') {
        $id=tt_create_master($type,$values); tt_audit((int)$admin['id'],$admin['username'],'Created '.$type.' master '.$reference);
        master_respond(['ok'=>true,'id'=>$id,'masters'=>master_all($admin),'options'=>master_options_for_console()]);
    }
    if ($action==='update') {
        if ($id==='') throw new InvalidArgumentException('Select a master record.');
        tt_update_master($type,$id,$values); tt_audit((int)$admin['id'],$admin['username'],'Updated '.$type.' master '.$reference);
        master_respond(['ok'=>true,'id'=>$id,'masters'=>master_all($admin),'options'=>master_options_for_console()]);
    }
    master_respond(['ok'=>false,'error'=>'Unknown action.'],400);
} catch (InvalidArgumentException $e) { master_respond(['ok'=>false,'error'=>$e->getMessage()],422); }
catch (Throwable $e) { master_respond(['ok'=>false,'error'=>'The master-record action could not be completed.'],500); }
