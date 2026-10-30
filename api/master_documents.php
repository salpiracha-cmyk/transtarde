<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/document_filenames.php';
require dirname(__DIR__) . '/auth_store.php';
require dirname(__DIR__) . '/upload_validation.php';

function md_reply(array $data,int $status=200): never { http_response_code($status);header('Content-Type: application/json; charset=UTF-8');echo json_encode($data,JSON_UNESCAPED_SLASHES);exit; }
function md_company(string $id): ?array { foreach ((array)(tt_list_masters()['companies']??[]) as $row) if ((string)($row['id']??'')===$id)return $row;return null; }
function md_documents(array $company): array { return tt_master_json_array($company['values'][14]??''); }

try {
    $user=tt_require_login();
    $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
    $companyId=trim((string)($_GET['companyId']??$_POST['companyId']??''));
    if ($companyId==='' || !($company=md_company($companyId))) throw new InvalidArgumentException('Select a valid company.');
    $entityCode=strtoupper(trim((string)($company['values'][1]??'')));
    // Non-book company identities are legitimate Master records, but must not
    // acquire a fictional TTI/BRM/TG business-entity permission. Only owner
    // and full-business Director may manage their document identities.
    $entityAllowed=in_array($entityCode,['TTI','BRM','TG'],true)
        ? tt_user_business_entity($user,$entityCode,$method==='GET'?'View':'Edit')
        : in_array(($user['role']??''),['Super Admin','Director'],true);
    if(!$entityAllowed)md_reply(['ok'=>false,'error'=>'Company document access required.'],403);
    if ($method==='GET' && isset($_GET['documentId'])) {
        $download=!empty($_GET['download']);
        if (!tt_user_can_master($user,'companies',$download?'Download Documents':'View Documents')) md_reply(['ok'=>false,'error'=>'Document permission required.'],403);
        $documentId=trim((string)$_GET['documentId']);$document=null;
        foreach (md_documents($company) as $row) if ((string)($row['id']??'')===$documentId){$document=$row;break;}
        if (!$document || empty($document['storageName'])) md_reply(['ok'=>false,'error'=>'Document file not found.'],404);
        $path=TT_DATA_DIR.'/master-documents/'.basename((string)$document['storageName']);
        if (!is_file($path)) md_reply(['ok'=>false,'error'=>'Document file not found.'],404);
        header('Content-Type: '.((string)($document['mime']??'application/octet-stream')));
        tt_document_disposition(tt_document_name(['party'=>$company['values'][0]??'','title'=>$document['label']??$document['type']??'Company Document','reference'=>$document['version']??'','extension'=>tt_document_extension((string)($document['originalName']??''),(string)($document['mime']??''))]),$download);
        tt_uploaded_document_headers();readfile($path);exit;
    }
    if ($method==='GET') {
        if (!tt_user_can_master($user,'companies','View Documents')) md_reply(['ok'=>false,'error'=>'Document permission required.'],403);
        $rows=array_map(static function(array $row): array { unset($row['storageName']);return $row; },md_documents($company));
        md_reply(['ok'=>true,'documents'=>$rows]);
    }
    if ($method!=='POST') md_reply(['ok'=>false,'error'=>'Method not allowed.'],405);
    if (!tt_verify_csrf((string)($_POST['csrf']??''))) md_reply(['ok'=>false,'error'=>'Your session expired. Refresh and try again.'],419);
    if (!tt_user_can_master($user,'companies','Edit')) md_reply(['ok'=>false,'error'=>'Company Edit permission required.'],403);
    $action=trim((string)($_POST['action']??'upload'));
    $canViewDocuments=tt_user_can_master($user,'companies','View Documents')&&(!in_array($entityCode,['TTI','BRM','TG'],true)||tt_user_business_entity($user,$entityCode,'View'));
    if($action!=='upload'&&!$canViewDocuments)md_reply(['ok'=>false,'error'=>'Document View permission required to amend existing documents.'],403);
    $expectedRevision=$_POST['expectedRevision']??null;
    if($expectedRevision!==null&&(!is_string($expectedRevision)||!preg_match('/^[a-f0-9]{64}$/D',$expectedRevision)))throw new InvalidArgumentException('Invalid company document version. Reopen the company.');
    $newDocument=null;$uploadedFile=null;$documentId='';$requestId='';$metadata=null;
    if ($action==='upload') {
        $requestId=trim((string)($_POST['requestId']??''));
        if($requestId!==''&&!preg_match('/^[a-zA-Z0-9_-]{12,96}$/D',$requestId))throw new InvalidArgumentException('Invalid document upload request. Reopen the form.');
        $file=$_FILES['file']??null;if(!is_array($file)||(int)($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new InvalidArgumentException('Choose a document file.');
        $validated=tt_validate_document_upload($file,8*1024*1024,['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp','application/pdf'=>'pdf']);
        $type=trim((string)($_POST['type']??'Other'));$allowedTypes=['Header','Footer','Signature','Stamp','Letterhead','Other'];if(!in_array($type,$allowedTypes,true))$type='Other';
        $label=trim((string)($_POST['label']??''));if($label===''||strlen($label)>300)throw new InvalidArgumentException('Enter a document label of at most 300 characters.');
        $dir=TT_DATA_DIR.'/master-documents';if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Secure document storage is unavailable.');
        $id='document-'.bin2hex(random_bytes(8));$storage=$id.'.'.$validated['extension'];$uploadedFile=$dir.'/'.$storage;
        if(!move_uploaded_file((string)$file['tmp_name'],$uploadedFile))throw new RuntimeException('Document upload could not be saved.');
        $newDocument=['id'=>$id,'type'=>$type,'label'=>$label,'version'=>1,'status'=>'Active','isDefault'=>!empty($_POST['isDefault']),'storageName'=>$storage,'originalName'=>tt_upload_original_name((string)($file['name']??'')),'mime'=>$validated['mime'],'uploadedAt'=>gmdate('c'),'uploadedBy'=>(string)$user['username']];
        if($requestId!=='')$newDocument['uploadRequestId']=$requestId;
    } elseif (in_array($action,['deactivate','set-default','update'],true)) {
        $documentId=trim((string)($_POST['documentId']??''));
        if($documentId==='')throw new InvalidArgumentException('Select a company document identity.');
        if($action==='update'){
            $type=trim((string)($_POST['type']??''));$label=trim((string)($_POST['label']??''));$status=(string)($_POST['status']??'Active');
            if(!in_array($type,['Header','Footer','Signature','Stamp','Letterhead','Other'],true)||$label===''||strlen($label)>300||!in_array($status,['Active','Inactive'],true))throw new InvalidArgumentException('Enter a valid document type, label and status.');
            $metadata=['type'=>$type,'label'=>$label,'status'=>$status,'isDefault'=>$status==='Active'&&!empty($_POST['isDefault'])];
        }
    } else throw new InvalidArgumentException('Unknown document action.');
    try{
        // Modify only the latest company document list under the store lock.
        // Rewriting the entire company row from an earlier GET can erase
        // concurrent bank, registration and identity changes.
        $result=tt_mutate_store(static function (&$data) use($companyId,$company,$action,$newDocument,$documentId,$requestId,$expectedRevision,$metadata):array {
            if(!isset($data['masters']['companies'])||!is_array($data['masters']['companies']))throw new InvalidArgumentException('Company no longer exists.');
            foreach($data['masters']['companies'] as &$row){
                if((string)($row['id']??'')!==$companyId)continue;
                if((string)($row['values'][1]??'')!==(string)($company['values'][1]??''))throw new InvalidArgumentException('Company identity changed. Reopen the company before uploading documents.');
                $documents=tt_master_json_array($row['values'][14]??'');
                $savedDocument=null;$replayed=false;
                if($action==='upload'){
                    if($requestId!=='')foreach($documents as $existing)if((string)($existing['uploadRequestId']??'')===$requestId){$savedDocument=$existing;$replayed=true;break;}
                    if(!$replayed){
                        if($expectedRevision!==null&&!hash_equals(tt_master_revision((array)$row['values']),$expectedRevision))throw new InvalidArgumentException('Company documents changed in another session. Reopen the company before saving.');
                        $savedDocument=$newDocument;$version=1;
                        foreach($documents as $existing)if(strcasecmp((string)($existing['type']??''),(string)$newDocument['type'])===0&&strcasecmp((string)($existing['label']??''),(string)$newDocument['label'])===0)$version=max($version,(int)($existing['version']??0)+1);
                        $savedDocument['version']=$version;
                        if(!empty($savedDocument['isDefault']))foreach($documents as &$old)if((string)($old['type']??'')===(string)$savedDocument['type'])$old['isDefault']=false;unset($old);
                        $documents[]=$savedDocument;
                    }
                }else{
                    if($expectedRevision!==null&&!hash_equals(tt_master_revision((array)$row['values']),$expectedRevision))throw new InvalidArgumentException('Company documents changed in another session. Reopen the company before saving.');
                    $found=false;$selectedType='';
                    foreach($documents as &$document)if((string)($document['id']??'')===$documentId){
                        $found=true;$selectedType=(string)($document['type']??'');
                        if($action==='deactivate'){$document['status']='Inactive';$document['isDefault']=false;}
                        elseif($action==='update'){$document=array_replace($document,$metadata);$selectedType=$document['type'];}
                        else{$document['status']='Active';$document['isDefault']=true;}
                        $savedDocument=$document;break;
                    }unset($document);
                    if(!$found)throw new InvalidArgumentException('Document identity not found.');
                    if(!empty($savedDocument['isDefault']))foreach($documents as &$document)if((string)($document['id']??'')!==$documentId&&(string)($document['type']??'')===$selectedType)$document['isDefault']=false;unset($document);
                }
                if(!$replayed){
                    $row['values'][14]=json_encode($documents,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                }
                return ['documents'=>$documents,'document'=>$savedDocument,'revision'=>tt_master_revision((array)$row['values']),'replayed'=>$replayed];
            }unset($row);
            throw new InvalidArgumentException('Company no longer exists.');
        });
    }catch(Throwable $e){
        if($uploadedFile!==null&&is_file($uploadedFile))@unlink($uploadedFile);
        throw $e;
    }
    if(!empty($result['replayed'])&&$uploadedFile!==null&&is_file($uploadedFile))@unlink($uploadedFile);
    if(empty($result['replayed']))tt_audit((int)$user['id'],(string)$user['username'],ucfirst($action).' company document identity '.$companyId);
    $clean=static function(array $document):array{unset($document['storageName']);return $document;};
    md_reply(['ok'=>true,'documents'=>$canViewDocuments?array_map($clean,$result['documents']):[],'document'=>$result['document']?($canViewDocuments?$clean($result['document']):['id'=>$result['document']['id']]):null,'revision'=>$result['revision']]);
} catch (InvalidArgumentException $e) { md_reply(['ok'=>false,'error'=>$e->getMessage()],422); }
catch (Throwable $e) { error_log('Master documents: '.$e->getMessage());md_reply(['ok'=>false,'error'=>'The company document action could not be completed.'],500); }
