<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/document_filenames.php';
require dirname(__DIR__).'/auth_store.php';
require dirname(__DIR__).'/upload_validation.php';
require __DIR__.'/accounts_document_access.php';
const TT_BAG_UPLOAD_DIR = TT_DATA_DIR.'/bag-bill-uploads';
function bf_out(array $d,int $s=200):never{http_response_code($s);header('Content-Type: application/json; charset=UTF-8');echo json_encode($d,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
try{
 $u=tt_require_login();if(!tt_user_can_open_module($u,'Accounts'))bf_out(['ok'=>false,'error'=>'Accounts permission required.'],403);
 if($_SERVER['REQUEST_METHOD']==='GET'){
   if(!tt_accounts_document_view($u,['purchases']))bf_out(['ok'=>false,'error'=>'Bag purchases View permission required.'],403);
   $token=(string)($_GET['file']??'');if($token==='')bf_out(['ok'=>false,'error'=>'File reference required.'],422);
   $owner=tt_accounts_document_owner($u,$token,'bagSupplierBills','attachmentToken','',$documentRecord);
   $path=TT_BAG_UPLOAD_DIR.'/'.$token;if(!is_file($path)){http_response_code(404);exit('File not found.');}
   $ext=strtolower(pathinfo($path,PATHINFO_EXTENSION));$types=['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png'];header('Content-Type: '.($types[$ext]??'application/octet-stream'));tt_document_disposition(tt_document_name(['party'=>$documentRecord['supplier']??$documentRecord['seller']??'','company'=>$owner,'title'=>'Bag Supplier Invoice','reference'=>$documentRecord['sellerInvoice']??$documentRecord['sellerInvoiceNo']??$documentRecord['invoiceNo']??$documentRecord['id']??'','date'=>$documentRecord['sellerInvoiceDate']??'','extension'=>$ext]));header('Content-Length: '.filesize($path));tt_uploaded_document_headers();readfile($path);exit;
 }
 if($_SERVER['REQUEST_METHOD']!=='POST')bf_out(['ok'=>false,'error'=>'Method not allowed.'],405);
 $entity=strtoupper(trim((string)($_POST['entity']??'')));if(!in_array($entity,['TTI','BRM'],true)||!tt_api_record_entity_allowed($u,['entity'=>$entity],$entity))bf_out(['ok'=>false,'error'=>'Select an authorized company for the bill upload.'],403);
 if(!tt_verify_csrf((string)($_POST['csrf']??'')))bf_out(['ok'=>false,'error'=>'Your session expired. Refresh and try again.'],419);
 if(!isset($_FILES['billFile'])||!is_array($_FILES['billFile']))bf_out(['ok'=>false,'error'=>'Select the supplier bill file.'],422);$f=$_FILES['billFile'];if((int)($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)bf_out(['ok'=>false,'error'=>'Supplier bill upload failed.'],422);$size=(int)($f['size']??0);if($size<=0||$size>15*1024*1024)bf_out(['ok'=>false,'error'=>'Supplier bill must be smaller than 15 MB.'],422);
 $orig=tt_upload_original_name((string)($f['name']??''),'bill');$ext=strtolower(pathinfo($orig,PATHINFO_EXTENSION));if(!in_array($ext,['pdf','jpg','jpeg','png'],true))bf_out(['ok'=>false,'error'=>'Upload PDF, JPG or PNG only.'],422);$valid=tt_validate_document_upload($f,15*1024*1024,['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png']);
 if(!is_dir(TT_BAG_UPLOAD_DIR)&&!mkdir(TT_BAG_UPLOAD_DIR,0700,true)&&!is_dir(TT_BAG_UPLOAD_DIR))throw new RuntimeException('Upload storage unavailable.');$token='bagbill-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(5)).'.'.$valid['extension'];$dest=TT_BAG_UPLOAD_DIR.'/'.$token;if(!move_uploaded_file($valid['path'],$dest))throw new RuntimeException('Could not save supplier bill.');bf_out(['ok'=>true,'token'=>$token,'originalName'=>$orig,'size'=>$size]);
}catch(InvalidArgumentException $e){bf_out(['ok'=>false,'error'=>$e->getMessage()],422);}catch(Throwable $e){bf_out(['ok'=>false,'error'=>'The supplier bill file could not be saved.'],500);}
