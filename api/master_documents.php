<?php
declare(strict_types=1);
require dirname(__DIR__) . '/auth_store.php';

function md_reply(array $data,int $status=200): never { http_response_code($status);header('Content-Type: application/json; charset=UTF-8');echo json_encode($data,JSON_UNESCAPED_SLASHES);exit; }
function md_company(string $id): ?array { foreach ((array)(tt_list_masters()['companies']??[]) as $row) if ((string)($row['id']??'')===$id)return $row;return null; }
function md_documents(array $company): array { return tt_master_json_array($company['values'][14]??''); }

try {
    $user=tt_require_login();
    $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
    $companyId=trim((string)($_GET['companyId']??$_POST['companyId']??''));
    if ($companyId==='' || !($company=md_company($companyId))) throw new InvalidArgumentException('Select a valid company.');
    if ($method==='GET' && isset($_GET['documentId'])) {
        $download=!empty($_GET['download']);
        if (!tt_user_can_master($user,'companies',$download?'Download Documents':'View Documents')) md_reply(['ok'=>false,'error'=>'Document permission required.'],403);
        $documentId=trim((string)$_GET['documentId']);$document=null;
        foreach (md_documents($company) as $row) if ((string)($row['id']??'')===$documentId){$document=$row;break;}
        if (!$document || empty($document['storageName'])) md_reply(['ok'=>false,'error'=>'Document file not found.'],404);
        $path=TT_DATA_DIR.'/master-documents/'.basename((string)$document['storageName']);
        if (!is_file($path)) md_reply(['ok'=>false,'error'=>'Document file not found.'],404);
        header('Content-Type: '.((string)($document['mime']??'application/octet-stream')));
        header('Content-Disposition: '.($download?'attachment':'inline').'; filename="'.str_replace('"','',basename((string)($document['originalName']??'document'))).'"');
        header('X-Content-Type-Options: nosniff');readfile($path);exit;
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
    $values=array_values((array)$company['values']);while(count($values)<15)$values[]='';$documents=md_documents($company);
    if ($action==='upload') {
        $file=$_FILES['file']??null;if(!is_array($file)||(int)($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new InvalidArgumentException('Choose a document file.');
        if ((int)($file['size']??0)>8*1024*1024)throw new InvalidArgumentException('Document files must be 8 MB or smaller.');
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name'])?:'';$allowed=['image/png','image/jpeg','image/webp','application/pdf'];
        if(!in_array($mime,$allowed,true))throw new InvalidArgumentException('Use PNG, JPG, WebP or PDF.');
        $type=trim((string)($_POST['type']??'Other'));$allowedTypes=['Header','Footer','Signature','Stamp','Letterhead','Other'];if(!in_array($type,$allowedTypes,true))$type='Other';
        $label=trim((string)($_POST['label']??''));if($label==='')throw new InvalidArgumentException('Enter a document label.');
        $dir=TT_DATA_DIR.'/master-documents';if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Secure document storage is unavailable.');
        $id='document-'.bin2hex(random_bytes(8));$extension=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp','application/pdf'=>'pdf'][$mime];$storage=$id.'.'.$extension;
        if(!move_uploaded_file((string)$file['tmp_name'],$dir.'/'.$storage))throw new RuntimeException('Document upload could not be saved.');
        $version=1;foreach($documents as $row)if(strcasecmp((string)($row['type']??''),$type)===0&&strcasecmp((string)($row['label']??''),$label)===0)$version=max($version,(int)($row['version']??0)+1);
        $isDefault=!empty($_POST['isDefault']);if($isDefault)foreach($documents as &$row)if((string)($row['type']??'')===$type)$row['isDefault']=false;unset($row);
        $documents[]=['id'=>$id,'type'=>$type,'label'=>$label,'version'=>$version,'status'=>'Active','isDefault'=>$isDefault,'storageName'=>$storage,'originalName'=>basename((string)$file['name']),'mime'=>$mime,'uploadedAt'=>gmdate('c'),'uploadedBy'=>(string)$user['username']];
    } elseif (in_array($action,['deactivate','set-default'],true)) {
        $documentId=trim((string)($_POST['documentId']??''));$found=false;$selectedType='';
        foreach($documents as &$row)if((string)($row['id']??'')===$documentId){$found=true;$selectedType=(string)($row['type']??'');if($action==='deactivate'){$row['status']='Inactive';$row['isDefault']=false;}else{$row['status']='Active';$row['isDefault']=true;}break;}unset($row);
        if(!$found)throw new InvalidArgumentException('Document identity not found.');
        if($action==='set-default')foreach($documents as &$row)if((string)($row['id']??'')!==$documentId&&(string)($row['type']??'')===$selectedType)$row['isDefault']=false;unset($row);
    } else throw new InvalidArgumentException('Unknown document action.');
    $values[14]=json_encode($documents,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);tt_update_master('companies',$companyId,$values);
    tt_audit((int)$user['id'],(string)$user['username'],ucfirst($action).' company document identity '.$companyId);
    md_reply(['ok'=>true,'documents'=>array_map(static function(array $row): array {unset($row['storageName']);return $row;},$documents)]);
} catch (InvalidArgumentException $e) { md_reply(['ok'=>false,'error'=>$e->getMessage()],422); }
catch (Throwable $e) { error_log('Master documents: '.$e->getMessage());md_reply(['ok'=>false,'error'=>'The company document action could not be completed.'],500); }
