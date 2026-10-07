<?php
declare(strict_types=1);
require dirname(__DIR__) . '/auth_store.php';
require __DIR__ . '/salary_master_store.php';
require_once __DIR__ . '/company_bank_retention.php';
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
    return $options;
}
function master_bank_can_be_default(array $bank): bool {
    return strcasecmp((string)($bank['status']??'Active'),'Inactive')!==0
        &&tt_bank_is_operational_account_type((string)($bank['accountType']??'Company Account'))
        &&(trim((string)($bank['accountNumber']??''))!==''||trim((string)($bank['iban']??''))!=='');
}
function master_sync_accounts_bank_defaults(string $companyId,array $admin): void {
    $company=master_find_row('companies',$companyId);
    if(!$company)return;
    $code=strtoupper(trim((string)($company['values'][1]??'')));
    if(!in_array($code,['TTI','BRM','TG'],true))return;
    $banks=tt_company_bank_legacy_rows([$company]);$defaults=[];
    foreach(tt_master_json_array($company['values'][13]??'') as $item){
        if(!is_array($item)||empty($item['isDefault'])||!master_bank_can_be_default($item))continue;
        $currency=strtoupper(trim((string)($item['currency']??'')));
        if($currency!=='')$defaults[$currency]=(string)($item['id']??'');
    }
    if(!$defaults)return;
    tt_ensure_data_dir();$path=TT_DATA_DIR.'/accounts.json';
    $handle=fopen($path,'c+');if($handle===false||!flock($handle,LOCK_EX))throw new RuntimeException('Accounts storage unavailable.');
    try{
        rewind($handle);$raw=stream_get_contents($handle);$store=$raw?json_decode($raw,true):null;
        if($raw!==''&&!is_array($store))throw new RuntimeException('Accounts storage is invalid.');
        if(!is_array($store))$store=['revision'=>0,'journals'=>[],'bankAccountSettings'=>[]];
        if(!isset($store['bankAccountSettings'])||!is_array($store['bankAccountSettings']))$store['bankAccountSettings']=[];
        foreach($banks as $row){
            $id=(string)($row['id']??'');$currency=strtoupper(trim((string)($row['values'][7]??'')));
            if($id===''||!isset($defaults[$currency]))continue;
            $setting=is_array($store['bankAccountSettings'][$id]??null)?$store['bankAccountSettings'][$id]:[];
            $selected=$defaults[$currency]===$id;
            $setting['defaultReceiptAccount']=$selected;$setting['defaultPaymentAccount']=$selected;
            $setting['updatedAt']=gmdate('c');$setting['updatedBy']=(string)($admin['full_name']??$admin['username']??'Super Admin');
            $store['bankAccountSettings'][$id]=$setting;
        }
        $store['revision']=(int)($store['revision']??0)+1;
        $encoded=json_encode($store,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        rewind($handle);ftruncate($handle,0);
        if(fwrite($handle,$encoded)!==strlen($encoded)||!fflush($handle))throw new RuntimeException('Accounts storage could not be saved.');
    }finally{flock($handle,LOCK_UN);fclose($handle);}
}
try {
    $admin=tt_require_login();
    if (!tt_user_can_access_masters($admin)) master_respond(['ok'=>false,'error'=>'Master Records access required.'],403);
    if ($_SERVER['REQUEST_METHOD']==='GET') { tt_release_read_session(); master_respond(['ok'=>true,'masters'=>master_all($admin),'options'=>master_options_for_console(),'deletionRequests'=>($admin['role']??'')==='Super Admin'?(array)(tt_read_store()['master_deletion_requests']??[]):[],'bankDeletionRequests'=>in_array(($admin['role']??''),['Super Admin','Director'],true)?(array)(tt_read_store()['bank_deletion_requests']??[]):[]]); }
    if ($_SERVER['REQUEST_METHOD']!=='POST') master_respond(['ok'=>false,'error'=>'Method not allowed.'],405);
    $body=json_decode((function_exists('tt_accounts_input')?tt_accounts_input():file_get_contents('php://input')) ?: '{}',true);
    if (!is_array($body) || !tt_verify_csrf((string)($body['csrf'] ?? ''))) master_respond(['ok'=>false,'error'=>'Your session expired. Refresh and try again.'],419);

    $type=(string)($body['type'] ?? '');
    $action=(string)($body['action'] ?? ''); $id=trim((string)($body['id'] ?? ''));
    if ($action==='request-bank-deletion') {
        if (!tt_user_can_master($admin,'companies','Edit')) master_respond(['ok'=>false,'error'=>'Company master edit access required.'],403);
        $bankId=trim((string)($body['bankId']??''));$reason=trim((string)($body['reason']??''));
        if ($id===''||$bankId===''||strlen($reason)<5||strlen($reason)>500) throw new InvalidArgumentException('Select a bank account and explain why it should be deactivated.');
        $request=tt_mutate_store(static function (&$data) use($id,$bankId,$reason,$admin):array {
            $company=null;foreach((array)($data['masters']['companies']??[]) as $row)if((string)($row['id']??'')===$id){$company=$row;break;}
            if (!$company) throw new InvalidArgumentException('Company no longer exists.');
            $banks=json_decode((string)($company['values'][13]??'[]'),true);
            $bank=null;foreach((array)$banks as $item)if((string)($item['id']??'')===$bankId){$bank=$item;break;}
            if (!$bank||strcasecmp((string)($bank['status']??'Active'),'Inactive')===0)throw new InvalidArgumentException('Select an active bank account.');
            $data['bank_deletion_requests']??=[];
            foreach($data['bank_deletion_requests'] as $old)if(($old['companyId']??'')===$id&&($old['bankId']??'')===$bankId&&($old['status']??'')==='Pending')throw new InvalidArgumentException('This account already has a pending Director request.');
            $item=['id'=>bin2hex(random_bytes(12)),'companyId'=>$id,'bankId'=>$bankId,'company'=>(string)($company['values'][0]??''),'bank'=>(string)($bank['bankName']??'').' · '.(string)($bank['accountTitle']??''),'reason'=>$reason,'status'=>'Pending','requestedBy'=>(string)($admin['full_name']??$admin['username']??''),'requestedAt'=>gmdate('c')];
            $data['bank_deletion_requests'][]=$item;return $item;
        });
        tt_audit((int)$admin['id'],$admin['username'],'Requested Director approval to deactivate bank '.$bankId);
        master_respond(['ok'=>true,'request'=>$request]);
    }
    if ($action==='delete-company-bank') {
        if (($admin['role']??'')!=='Super Admin') master_respond(['ok'=>false,'error'=>'Only Super Admin can delete a company bank directly.'],403);
        $bankId=trim((string)($body['bankId']??''));
        $replacementBankId=trim((string)($body['replacementBankId']??''));
        if($id===''||$bankId==='')throw new InvalidArgumentException('Select a company bank account.');
        tt_mutate_store(static function (&$data) use($id,$bankId,$replacementBankId,$admin):void {
            $found=false;
            if(!isset($data['masters']['companies'])||!is_array($data['masters']['companies']))throw new InvalidArgumentException('Company no longer exists.');
            foreach($data['masters']['companies'] as &$company){
                if((string)($company['id']??'')!==$id)continue;
                $banks=json_decode((string)($company['values'][13]??'[]'),true);if(!is_array($banks))$banks=[];
                foreach($banks as &$bank){
                    if((string)($bank['id']??'')!==$bankId)continue;
                    if(strcasecmp((string)($bank['status']??'Active'),'Inactive')===0)throw new InvalidArgumentException('Bank account is already inactive.');
                    if(!empty($bank['isDefault'])){
                        $replaced=false;
                        foreach($banks as $candidateIndex=>$candidate){
                            if((string)($candidate['id']??'')!==$replacementBankId)continue;
                            if($replacementBankId===$bankId||strcasecmp((string)($candidate['currency']??''),(string)($bank['currency']??''))!==0||!master_bank_can_be_default($candidate))break;
                            $banks[$candidateIndex]['isDefault']=true;$replaced=true;break;
                        }
                        if(!$replaced)throw new InvalidArgumentException('Choose an active replacement default in the same currency before deleting this account.');
                    }
                    $bank['isDefault']=false;$bank['status']='Inactive';$bank['deletedAt']=gmdate('c');$bank['deletedBy']=(string)($admin['full_name']??$admin['username']??'Super Admin');$found=true;break;
                }unset($bank);
                if($found)$company['values'][13]=json_encode($banks,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);break;
            }unset($company);
            if(!$found)throw new InvalidArgumentException('Bank account no longer exists.');
            if(isset($data['bank_deletion_requests'])&&is_array($data['bank_deletion_requests'])){
                foreach($data['bank_deletion_requests'] as &$request){if(($request['companyId']??'')===$id&&($request['bankId']??'')===$bankId&&($request['status']??'')==='Pending'){$request['status']='Superseded by Super Admin';$request['reviewedAt']=gmdate('c');$request['reviewedBy']=(string)($admin['full_name']??$admin['username']??'Super Admin');}}unset($request);
            }
        });
        master_sync_accounts_bank_defaults($id,$admin);
        tt_audit((int)$admin['id'],$admin['username'],'Super Admin deleted company bank from future use '.$bankId);
        master_respond(['ok'=>true,'masters'=>master_all($admin),'bankDeletionRequests'=>(array)(tt_read_store()['bank_deletion_requests']??[])]);
    }
    if ($action==='review-bank-deletion') {
        $owner=($admin['role']??'')==='Super Admin';$director=tt_user_can_director_approve($admin);
        if (!$owner&&!$director) master_respond(['ok'=>false,'error'=>'Director or Super Admin approval required.'],403);
        $requestId=trim((string)($body['requestId']??''));$decision=(string)($body['decision']??'');$replacementBankId=trim((string)($body['replacementBankId']??''));
        if (!in_array($decision,['Approve','Reject'],true))throw new InvalidArgumentException('Choose Approve or Reject.');
        $review=tt_mutate_store(static function (&$data) use($requestId,$decision,$replacementBankId,$admin):array {
            if(!isset($data['bank_deletion_requests'])||!is_array($data['bank_deletion_requests']))throw new InvalidArgumentException('Pending Director request not found.');
            foreach($data['bank_deletion_requests'] as &$request){
                if(($request['id']??'')!==$requestId||($request['status']??'')!=='Pending')continue;
                if($decision==='Approve'){
                    $found=false;
                    foreach($data['masters']['companies'] as &$company){
                        if((string)($company['id']??'')!==(string)$request['companyId'])continue;
                        $banks=json_decode((string)($company['values'][13]??'[]'),true);
                        foreach($banks as &$bank)if((string)($bank['id']??'')===(string)$request['bankId']){
                            if(!empty($bank['isDefault'])){
                                $replaced=false;
                                foreach($banks as $candidateIndex=>$candidate){
                                    if((string)($candidate['id']??'')!==$replacementBankId)continue;
                                    if($replacementBankId===(string)$request['bankId']||strcasecmp((string)($candidate['currency']??''),(string)($bank['currency']??''))!==0||!master_bank_can_be_default($candidate))break;
                                    $banks[$candidateIndex]['isDefault']=true;$replaced=true;break;
                                }
                                if(!$replaced)throw new InvalidArgumentException('Choose an active replacement default in the same currency before approving this deletion.');
                            }
                            $bank['isDefault']=false;$bank['status']='Inactive';$found=true;break;
                        }
                        unset($bank);
                        if($found)$company['values'][13]=json_encode($banks,JSON_UNESCAPED_SLASHES);
                        break;
                    }unset($company);
                    if(!$found)throw new InvalidArgumentException('Bank account no longer exists.');
                }
                $request['status']=$decision==='Approve'?'Approved':'Rejected';$request['reviewedBy']=(string)($admin['full_name']??$admin['username']??'');$request['reviewedAt']=gmdate('c');return $request;
            }unset($request);
            throw new InvalidArgumentException('Pending Director request not found.');
        });
        if($decision==='Approve')master_sync_accounts_bank_defaults((string)$review['companyId'],$admin);
        tt_audit((int)$admin['id'],$admin['username'],$decision.' bank account deactivation '.$requestId);
        master_respond(['ok'=>true,'review'=>$review,'masters'=>master_all($admin),'bankDeletionRequests'=>(array)(tt_read_store()['bank_deletion_requests']??[])]);
    }
    if ($action==='request-deletion') {
        if(!in_array($type,['business_parties','export_customers'],true)||!tt_user_can_master($admin,$type,'View'))master_respond(['ok'=>false,'error'=>'Select an accessible customer or business party.'],403);
        $row=master_find_row($type,$id);if(!$row)master_respond(['ok'=>false,'error'=>'Master record not found.'],404);
        $reason=trim((string)($body['reason']??''));if(strlen($reason)<5||strlen($reason)>500)master_respond(['ok'=>false,'error'=>'Explain why this name should be removed.'],422);
        $request=tt_mutate_store(static function (&$data) use($type,$id,$row,$reason,$admin):array {
            if(!isset($data['master_deletion_requests'])||!is_array($data['master_deletion_requests']))$data['master_deletion_requests']=[];
            foreach($data['master_deletion_requests'] as $old)if(($old['type']??'')===$type&&($old['masterId']??'')===$id&&($old['status']??'')==='Pending')throw new InvalidArgumentException('A deletion request for this record is already awaiting Super Admin.');
            $item=['id'=>bin2hex(random_bytes(12)),'type'=>$type,'masterId'=>$id,'name'=>(string)($row['values'][0]??''),'reason'=>$reason,'status'=>'Pending','requestedAt'=>gmdate('c'),'requestedBy'=>(string)($admin['full_name']??$admin['username']??'Accounts')];
            $data['master_deletion_requests'][]=$item;return $item;
        });
        tt_audit((int)$admin['id'],$admin['username'],'Requested Super Admin deletion of '.$type.' '.$id);
        master_respond(['ok'=>true,'request'=>$request]);
    }
    if ($action==='review-deletion') {
        if(($admin['role']??'')!=='Super Admin')master_respond(['ok'=>false,'error'=>'Only Super Admin can review deletion requests.'],403);
        $requestId=trim((string)($body['requestId']??''));$decision=(string)($body['decision']??'');
        if(!in_array($decision,['Approve','Reject'],true))master_respond(['ok'=>false,'error'=>'Select Approve or Reject.'],422);
        $review=tt_mutate_store(static function (&$data) use($requestId,$decision,$admin):array {
            if(!isset($data['master_deletion_requests'])||!is_array($data['master_deletion_requests']))throw new InvalidArgumentException('Pending request not found.');
            foreach($data['master_deletion_requests'] as &$request){
                if(($request['id']??'')!==$requestId||($request['status']??'')!=='Pending')continue;
                if($decision==='Approve'){
                    $type=(string)$request['type'];$rowId=(string)$request['masterId'];$found=false;
                    foreach($data['masters'][$type] as &$row){if(($row['id']??'')!==$rowId)continue;$values=array_values((array)($row['values']??[]));while(count($values)<=10)$values[]='';$values[10]='Inactive';$row['values']=$values;$found=true;break;}unset($row);
                    if(!$found)throw new InvalidArgumentException('Master record no longer exists. Reject the request instead.');
                }
                $request['status']=$decision==='Approve'?'Approved — deactivated':'Rejected';$request['reviewedAt']=gmdate('c');$request['reviewedBy']=(string)($admin['full_name']??$admin['username']??'Super Admin');return $request;
            }unset($request);
            throw new InvalidArgumentException('Pending request not found.');
        });
        tt_audit((int)$admin['id'],$admin['username'],$decision.' master deletion request '.$requestId);
        master_respond(['ok'=>true,'review'=>$review,'masters'=>master_all($admin),'deletionRequests'=>(array)(tt_read_store()['master_deletion_requests']??[])]);
    }
    $requiredAction=$action==='create'?'Create':($action==='update'?'Edit':(in_array($action,['delete','purge'],true)?'Deactivate':'Edit'));
    if ($type!=='' && !tt_user_can_master($admin,$type,$requiredAction)) master_respond(['ok'=>false,'error'=>'You do not have '.$requiredAction.' permission for this master.'],403);
    if ($action==='manage-option') {
        $optionAction=(string)($body['optionAction'] ?? '');
        $optionKey=(string)($body['optionKey'] ?? '');
        $optionMaster=$type==='reference_lists'?'reference_lists':($optionKey==='party_roles'?'business_parties':'products');
        if (!tt_user_can_master($admin,$optionMaster,'Edit')) master_respond(['ok'=>false,'error'=>'Edit permission is required for this master option.'],403);
        $value=tt_manage_master_option($optionKey,$optionAction,(string)($body['value'] ?? ''),(string)($body['old'] ?? ''),(string)($body['fullName']??''));
        tt_audit((int)$admin['id'],$admin['username'],ucfirst($optionAction).' '.$optionKey.' option '.$value);
        master_respond(['ok'=>true,'masters'=>master_all($admin),'options'=>master_options_for_console(),'value'=>$value]);
    }
    if($type==='mills'&&in_array($action,['create','update'],true)){ $locationValues=$body['values']??[];if(!in_array((string)($locationValues[7]??''),['','TTI','BRM','TG'],true))throw new InvalidArgumentException('Select the company responsible for this location bills.'); }
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
        'companies'=>19,'export_customers'=>22,'business_parties'=>13,'commodities'=>8,'product_settings'=>1,'products'=>22,'purchase_products'=>11,'purchase_kat'=>10,
        'mills'=>8,'export_documents'=>5,'export_terms'=>3,
    ];
    if (!isset($schemas[$type])) throw new InvalidArgumentException('Select a valid master section.');
    if ($action==='add-party-role') {
        $role=tt_add_party_role_option((string)($body['role'] ?? ''));
        tt_audit((int)$admin['id'],$admin['username'],'Added Party Role '.$role);
        master_respond(['ok'=>true,'masters'=>master_all($admin),'options'=>master_options_for_console(),'role'=>$role]);
    }

    // Owner rule: the Super Admin has full lifecycle control over Master
    // Console records. Module lock status never blocks master add/edit/delete.
    if ($action==='purge') {
        if (($admin['role']??'')!=='Super Admin') master_respond(['ok'=>false,'error'=>'Only Super Admin can permanently delete a master record.'],403);
        $purgeStatusFields=['export_customers'=>10,'business_parties'=>10,'purchase_products'=>8,'purchase_kat'=>7,'mills'=>5,'export_documents'=>4,'export_terms'=>2];
        if (!isset($purgeStatusFields[$type])) throw new InvalidArgumentException('This core master identity cannot be permanently deleted.');
        if ($id==='') throw new InvalidArgumentException('Select a master record.');
        $row=master_find_row($type,$id);if(!$row)throw new InvalidArgumentException('Master record not found.');
        $statusIndex=$purgeStatusFields[$type];$values=array_values((array)($row['values']??[]));while(count($values)<=$statusIndex)$values[]='';
        $referenced=false;
        foreach(glob(TT_DATA_DIR.'/*.json')?:[] as $file){
            if(realpath($file)===realpath(TT_STORE_FILE))continue;
            $raw=@file_get_contents($file);if($raw!==false&&str_contains($raw,'"'.addcslashes($id,"\\\"").'"')){$referenced=true;break;}
        }
        if($referenced)throw new InvalidArgumentException('This record is already linked to operational history. It must remain deactivated so old records stay traceable.');
        tt_delete_master($type,$id);
        tt_audit((int)$admin['id'],$admin['username'],'Permanently deleted unused '.$type.' Master '.$values[0].' '.$id);
        master_respond(['ok'=>true,'id'=>$id,'masters'=>master_all($admin),'options'=>master_options_for_console()]);
    }
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
        $nested=($type==='companies'&&in_array((int)$fieldIndex,[13,14,15,16],true))||($type==='export_customers'&&in_array((int)$fieldIndex,[9,16,17,18,19],true))||($type==='business_parties'&&(int)$fieldIndex===12)||($type==='purchase_kat'&&(int)$fieldIndex===9);
        $limit=$nested?50000:1200;
        if (strlen($value)>$limit) throw new InvalidArgumentException('One of the master fields is too long.');
        $values[]=$value;
    }
    while (count($values)<$schemas[$type]) $values[]='';
    if($type==='companies'&&count($raw)<17&&$action==='update'){
        $existingCompany=master_find_row('companies',$id);
        if(count($raw)<16)$values[15]=(string)(($existingCompany['values'][15]??'[]'));
        $values[16]=(string)(($existingCompany['values'][16]??'[]'));
    }
    if($type==='companies'&&count($raw)<18&&$action==='update'){$existingCompany=master_find_row('companies',$id);$values[17]=(string)($existingCompany['values'][17]??'');}
    if($type==='companies'&&count($raw)<19&&$action==='update'){$existingCompany=master_find_row('companies',$id);$values[18]=(string)($existingCompany['values'][18]??'5');}
    if($type==='companies'&&$values[15]==='')$values[15]='[]';
    if($type==='companies'&&$values[16]==='')$values[16]='[]';
    if (($values[0] ?? '')==='') throw new InvalidArgumentException('Enter the main record name / commodity / product.');
    if (in_array($type,['companies','commodities'],true) && ($values[1] ?? '')==='') throw new InvalidArgumentException('Enter the short code.');
    if ($type==='companies') {
        $offshore=strcasecmp((string)$values[2],'Pakistan')!==0&&strcasecmp((string)$values[3],'Pakistan')!==0;
        if($offshore){if($values[18]==='')$values[18]='5';$vat=filter_var($values[18],FILTER_VALIDATE_FLOAT);if($vat===false||$vat<0||$vat>100)throw new InvalidArgumentException('VAT rate must be between 0 and 100.');$values[18]=(string)$vat;}else{$values[18]='';}
        foreach ([13=>'bank accounts',14=>'document identities',15=>'exchange rates',16=>'registration details'] as $field=>$label) {
            $decoded=json_decode((string)($values[$field]??'[]'),true);
            if (!is_array($decoded)) throw new InvalidArgumentException('The '.$label.' could not be read. Reopen the company and try again.');
            if ($field===14) {
                $defaults=[];
                foreach ($decoded as $document) if (is_array($document)&&!empty($document['isDefault'])&&strcasecmp((string)($document['status']??'Active'),'Inactive')!==0) {
                    $documentType=(string)($document['type']??'Other');if(isset($defaults[$documentType]))throw new InvalidArgumentException('Only one active default is allowed for each company document type.');$defaults[$documentType]=true;
                }
            }
        }
        if($values[17]!==''){
            $chamber=json_decode($values[17],true);
            if(!is_array($chamber))throw new InvalidArgumentException('The Chamber / COO details could not be read.');
            $clean=[];foreach(['chamberName','membershipNo','signatory','designation'] as $key){if(isset($chamber[$key])&&!is_string($chamber[$key]))throw new InvalidArgumentException('Chamber / COO fields must be text.');$clean[$key]=trim((string)($chamber[$key]??''));}
            $values[17]=json_encode($clean,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            if(strcasecmp($values[2],'Pakistan')===0)$values[8]=$clean['membershipNo'];
        }
        $registrations=json_decode((string)$values[16],true)?:[];
        if(count($registrations)>40)throw new InvalidArgumentException('Too many company registration rows.');
        foreach($registrations as $registration){
            if(!is_array($registration))throw new InvalidArgumentException('A company registration row is invalid.');
            $regType=trim((string)($registration['type']??''));$regNumber=trim((string)($registration['number']??''));
            if(($regType==='' xor $regNumber===''))throw new InvalidArgumentException('Each company registration requires both a type and number.');
            $expiry=(string)($registration['expiryDate']??'');if($expiry!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$expiry))throw new InvalidArgumentException('Registration expiry dates must be valid dates.');
        }
        $previousBanks=$action==='update'?tt_master_json_array(master_find_row('companies',$id)['values'][13]??'[]'):[];
        $banks=tt_company_retention_banks(json_decode((string)$values[13],true)?:[],$values,$previousBanks);
        $values[13]=json_encode($banks,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if($offshore){foreach($banks as &$offshoreBank)if(is_array($offshoreBank))$offshoreBank['retentionAccount']=false;unset($offshoreBank);$values[13]=json_encode($banks,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}$currencies=[];$seenBankIds=[];$bankDefaults=[];
        foreach($banks as $bank)if(is_array($bank)){
            $bankId=trim((string)($bank['id']??''));if($bankId==='')throw new InvalidArgumentException('Every saved bank account requires a stable account ID. Reopen the company and try again.');
            if(isset($seenBankIds[$bankId]))throw new InvalidArgumentException('The same bank account appears more than once.');$seenBankIds[$bankId]=true;
            $depositType=(string)($bank['depositType']??'');if(!in_array($depositType,['','CURRENT','SAVING'],true))throw new InvalidArgumentException('Select Saving or Current account type.');
            $accountType=(string)($bank['accountType']??'Company Account');
            if(!in_array($accountType,['Company Account','Proprietor / Owner Account','Personal Account'],true))throw new InvalidArgumentException('Select a valid bank account ownership type.');
            if(!empty($bank['retentionAccount'])&&$accountType!=='Company Account')throw new InvalidArgumentException('Only a company account can be a retention account.');
            if(!empty($bank['retentionAccount'])&&(!in_array(strtoupper((string)($values[1]??'')),['TTI','BRM'],true)||strtoupper((string)($bank['currency']??'PKR'))==='PKR'))throw new InvalidArgumentException('A retention account must be a foreign-currency TTI or BRM bank account.');
            if(!empty($bank['isDefault'])){
                if(strcasecmp((string)($bank['status']??'Active'),'Inactive')===0)throw new InvalidArgumentException('An inactive bank account cannot be the default.');
                if(!master_bank_can_be_default($bank))throw new InvalidArgumentException('A default bank must be an operational company account with an account number or IBAN.');
                $defaultCurrency=strtoupper(trim((string)($bank['currency']??'')));
                if(isset($bankDefaults[$defaultCurrency]))throw new InvalidArgumentException('Only one default bank account is allowed per company and currency.');
                $bankDefaults[$defaultCurrency]=true;
            }
            if(strcasecmp((string)($bank['status']??'Active'),'Inactive')!==0){$code=strtoupper((string)($bank['currency']??''));if(preg_match('/^[A-Z]{3}$/',$code))$currencies[$code]=true;}
        }
        if(strtoupper(trim((string)($values[1]??'')))==='TG')$currencies['AED']=true;
        $pairs=json_decode((string)$values[15],true)?:[];$seenPairs=[];
        if(count($pairs)>60)throw new InvalidArgumentException('Too many company exchange-rate pairs.');
        foreach($pairs as $pair){
            if(!is_array($pair))throw new InvalidArgumentException('Exchange-rate pair is invalid.');
            $a=strtoupper((string)($pair['currencyA']??''));$b=strtoupper((string)($pair['currencyB']??''));
            if($a===$b||!isset($currencies[$a],$currencies[$b]))throw new InvalidArgumentException('Exchange-rate currencies must have active bank accounts in this company.');
            $key=implode('|',[$a<$b?$a:$b,$a<$b?$b:$a]);
            if(isset($seenPairs[$key]))throw new InvalidArgumentException('Each currency pair may appear only once.');
            $seenPairs[$key]=true;
            foreach(['rateAToB','rateBToA'] as $field){$rate=$pair[$field]??null;if(!is_numeric($rate)||(float)$rate<=0||(float)$rate>100000)throw new InvalidArgumentException('Enter both positive directional exchange rates.');}
        }
        if(strcasecmp((string)($values[3]??''),'Pakistan')===0&&$pairs)throw new InvalidArgumentException('Company exchange rates are for companies outside Pakistan.');
        if ($action==='update') {
            $existing=master_find_row('companies',$id);
            if (!$existing)throw new InvalidArgumentException('Company no longer exists.');
            $previous=json_decode((string)($existing['values'][13]??'[]'),true)?:[];
            $incoming=json_decode((string)$values[13],true)?:[];
            foreach($previous as $oldBank){
                if(empty($oldBank['isDefault'])||strcasecmp((string)($oldBank['status']??'Active'),'Inactive')===0)continue;
                $oldCurrency=strtoupper(trim((string)($oldBank['currency']??'')));
                $oldStillDefault=false;
                foreach($incoming as $newBank)if((string)($newBank['id']??'')===(string)($oldBank['id']??'')&&!empty($newBank['isDefault'])&&strcasecmp((string)($newBank['status']??'Active'),'Inactive')!==0&&strtoupper(trim((string)($newBank['currency']??'')))===$oldCurrency){$oldStillDefault=true;break;}
                if(!$oldStillDefault&&!isset($bankDefaults[$oldCurrency]))throw new InvalidArgumentException('Choose a replacement default for '.$oldCurrency.' before changing this bank account.');
            }
            foreach($previous as $bank){
                $bankId=(string)($bank['id']??'');if($bankId==='')continue;
                $match=null;foreach($incoming as $candidate)if((string)($candidate['id']??'')===$bankId){$match=$candidate;break;}
                if(($admin['role']??'')!=='Super Admin'){
                    if(!$match||strcasecmp((string)($bank['status']??'Active'),'Inactive')!==0&&strcasecmp((string)($match['status']??'Active'),'Inactive')===0)
                        throw new InvalidArgumentException('Bank deletion must be requested from the Accounts module and approved by a Director or Super Admin.');
                    if(strcasecmp((string)($bank['status']??'Active'),'Inactive')===0 && strcasecmp((string)($match['status']??'Active'),'Inactive')!==0)
                        throw new InvalidArgumentException('A deleted bank account cannot be reactivated from this editor.');
                }
            }
        }
    }
    if (in_array($type,['companies','export_customers','business_parties','mills'],true)) {
        $currentRow=$id!==''?master_find_row($type,$id):null;
        $currentName=(string)(($currentRow['values']??[])[0]??'');
        $nameChanged=$id===''||tt_master_name_identity((string)$values[0],$type)!==tt_master_name_identity($currentName,$type);
        foreach ($nameChanged?(array)(master_all($admin)[$type]??[]):[] as $existingRow) {
            if ($id!==''&&(string)($existingRow['id']??'')===$id) continue;
            $existingName=(string)(($existingRow['values']??[])[0]??'');
            if (tt_master_names_conflict((string)$values[0],$existingName,$type)) throw new InvalidArgumentException('A matching or confusingly similar record already exists as “'.$existingName.'”. Open that record instead of creating a duplicate.');
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
        $values[17]=tt_export_finish_normalize((string)$values[17]);
        $activeFinishes=(array)(tt_master_options()['product_finishes']??[]);
        $previousFinish=$action==='update'?(string)((master_find_row('products',$id)['values'][17]??'')):'';
        if (!in_array($values[17],$activeFinishes,true)&&($previousFinish===''||strcasecmp($values[17],$previousFinish)!==0)) throw new InvalidArgumentException('Select an active Finish option.');
        $values[21]=tt_export_hs_code((string)($values[0]??''),(string)($values[7]??''),(string)($values[21]??''));
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
        if(preg_match('/\b\d+(?:\.\d+)?\s*%\s*(?:Max\s*)?Broken\b/i',$values[1]))throw new InvalidArgumentException('Enter only the variety (for example PK-386) in Base Variety, then enter 100% Broken in Broken Grade.');
        $values[2]=$values[0]==='RICE'?tt_product_type($values[2]):trim($values[2]);
        $values[3]=tt_product_stage($values[3]);
        $values[6]='';
        $values[7]='';
        $values[10]=trim($values[10]);
        if($values[10]!==''){
            if(!preg_match('/^(\d+(?:\.\d+)?)\s*%?\s*(?:Broken)?$/i',$values[10],$grade)||!is_finite((float)($grade[1]??0))||(float)$grade[1]<0||(float)$grade[1]>100)throw new InvalidArgumentException('Enter only the broken percentage as a number from 0 to 100.');
            $values[10]=rtrim(rtrim(number_format((float)$grade[1],2,'.',''),'0'),'.').'% Broken';
        }
        if($values[10]!==''&&$values[0]!=='RICE')throw new InvalidArgumentException('Broken grade applies to rice only.');
        if ($values[1]==='' || !in_array($values[3],['RAW','READY'],true)) throw new InvalidArgumentException('Select a base product and choose RAW or READY. FINAL is created only by TTI/reprocessing production.');
        if (!in_array($values[0],['RICE','CORN','SESAME'],true)) throw new InvalidArgumentException('Commodity must be RICE, CORN or SESAME.');
        if ($values[0]==='RICE') {
            if($values[2]==='')throw new InvalidArgumentException('Select the Rice Type, for example White, Parboiled / Sella or Steam.');
            // An Accounts purchase can introduce a variety before its Export quality specifications exist.
            // The base name stays separate from broken grade, so no incomplete Export product is created.
            if(trim((string)$values[5])!==''){$kat=master_find_row('purchase_kat',(string)$values[5]);if(!$kat)throw new InvalidArgumentException('Select a saved KAT Profile from the list.');$kv=(array)($kat['values']??[]);if(strcasecmp((string)($kv[0]??''),$values[0])!==0||strcasecmp(tt_product_base((string)($kv[1]??'')),$values[1])!==0||strcasecmp(tt_product_type((string)($kv[2]??'')),$values[2])!==0||strcasecmp(tt_product_stage((string)($kv[3]??'')),$values[3])!==0)throw new InvalidArgumentException('The selected KAT Profile belongs to a different variety, rice type or purchase classification.');}
        }
        $candidate=strtolower($values[0].'|'.$values[1].'|'.$values[2].'|'.$values[3].'|'.$values[10]);
        foreach ((array)(master_all($admin)['purchase_products']??[]) as $row) {
            if ($id!=='' && (string)($row['id']??'')===$id) continue;
            $v=tt_purchase_product_values((array)($row['values']??[]));
            if (strtolower((string)$v[0].'|'.(string)$v[1].'|'.(string)$v[2].'|'.(string)$v[3].'|'.(string)$v[10])===$candidate) throw new InvalidArgumentException('This variety, rice type, broken grade and purchase classification already exists. Edit it instead.');
        }
    }
    if($type==='business_parties'){
        $categories=tt_business_party_categories($values[2]??'');
        if(!$categories)throw new InvalidArgumentException('Select at least one Business Party category.');
        // Non-broker parties have no Brokery profile. Schema padding leaves this field blank.
        $profileText=trim((string)($values[12]??''));if($profileText===''){$profileText='{}';$values[12]=$profileText;}
        $profile=json_decode($profileText,true);if(!is_array($profile))throw new InvalidArgumentException('The Brokery profile could not be read. Reopen the broker and try again.');
        $allowedBasis=['PER_100_KG','PER_50_KG_BAG','PER_BAG','PER_MAUND','PER_TON'];
        foreach(['buying'=>'Buying Brokery','selling'=>'Selling Brokery']as$kind=>$label){$rows=$profile[$kind]??[];if(!is_array($rows))throw new InvalidArgumentException($label.' must be a valid list.');$seen=[];foreach($rows as$row){if(!is_array($row))throw new InvalidArgumentException($label.' contains an invalid row.');$amount=(float)($row['amount']??0);$basis=(string)($row['basis']??'');$from=(string)($row['effectiveFrom']??'');if($amount<=0)throw new InvalidArgumentException($label.' figure must be greater than zero.');if(!in_array($basis,$allowedBasis,true))throw new InvalidArgumentException('Select a valid '.$label.' calculation basis.');if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from))throw new InvalidArgumentException($label.' Effective From date is required.');if(isset($seen[$from]))throw new InvalidArgumentException($label.' already has a rate starting on '.$from.'.');$seen[$from]=true;}}
        if(!array_filter($categories,static fn($category)=>strcasecmp($category,'Broker')===0)&&(!empty($profile['buying'])||!empty($profile['selling'])))throw new InvalidArgumentException('Select the Broker category before saving Brokery.');
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
        if($type==='companies')master_sync_accounts_bank_defaults($id,$admin);
        master_respond(['ok'=>true,'id'=>$id,'masters'=>master_all($admin),'options'=>master_options_for_console()]);
    }
    if ($action==='update') {
        if ($id==='') throw new InvalidArgumentException('Select a master record.');
        tt_update_master($type,$id,$values); tt_audit((int)$admin['id'],$admin['username'],'Updated '.$type.' master '.$reference);
        if($type==='companies')master_sync_accounts_bank_defaults($id,$admin);
        master_respond(['ok'=>true,'id'=>$id,'masters'=>master_all($admin),'options'=>master_options_for_console()]);
    }
    master_respond(['ok'=>false,'error'=>'Unknown action.'],400);
} catch (InvalidArgumentException $e) { master_respond(['ok'=>false,'error'=>$e->getMessage()],422); }
catch (Throwable $e) { master_respond(['ok'=>false,'error'=>'The master-record action could not be completed.'],500); }

