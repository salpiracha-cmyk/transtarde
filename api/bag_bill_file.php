<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
const TT_BAG_UPLOAD_DIR = TT_DATA_DIR.'/bag-bill-uploads';
function bf_out(array $d,int $s=200):never{http_response_code($s);header('Content-Type: application/json; charset=UTF-8');echo json_encode($d,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
try{
 $u=tt_require_login();if(!tt_user_can_open_module($u,'Accounts'))bf_out(['ok'=>false,'error'=>'Accounts permission required.'],403);
 if($_SERVER['REQUEST_METHOD']==='GET'){
   $token=preg_replace('/[^A-Za-z0-9._-]/','',(string)($_GET['file']??''));if($token==='')bf_out(['ok'=>false,'error'=>'File reference required.'],422);
   $path=TT_BAG_UPLOAD_DIR.'/'.$token;if(!is_file($path)){http_response_code(404);exit('File not found.');}
   $ext=strtolower(pathinfo($path,PATHINFO_EXTENSION));$types=['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png'];header('Content-Type: '.($types[$ext]??'application/octet-stream'));header('Content-Disposition: inline; filename="'.basename($path).'"');header('Content-Length: '.filesize($path));readfile($path);exit;
 }
 if($_SERVER['REQUEST_METHOD']!=='POST')bf_out(['ok'=>false,'error'=>'Method not allowed.'],405);
 if(!tt_verify_csrf((string)($_POST['csrf']??'')))bf_out(['ok'=>false,'error'=>'Your session expired. Refresh and try again.'],419);
 if(!isset($_FILES['billFile'])||!is_array($_FILES['billFile']))bf_out(['ok'=>false,'error'=>'Select the supplier bill file.'],422);$f=$_FILES['billFile'];if((int)($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)bf_out(['ok'=>false,'error'=>'Supplier bill upload failed.'],422);$size=(int)($f['size']??0);if($size<=0||$size>15*1024*1024)bf_out(['ok'=>false,'error'=>'Supplier bill must be smaller than 15 MB.'],422);
 $orig=(string)($f['name']??'bill');$ext=strtolower(pathinfo($orig,PATHINFO_EXTENSION));if(!in_array($ext,['pdf','jpg','jpeg','png'],true))bf_out(['ok'=>false,'error'=>'Upload PDF, JPG or PNG only.'],422);$fi=new finfo(FILEINFO_MIME_TYPE);$mime=$fi->file((string)$f['tmp_name']);$allowed=['application/pdf','image/jpeg','image/png'];if(!in_array($mime,$allowed,true))bf_out(['ok'=>false,'error'=>'Uploaded file type is not allowed.'],422);
 if(!is_dir(TT_BAG_UPLOAD_DIR)&&!mkdir(TT_BAG_UPLOAD_DIR,0770,true)&&!is_dir(TT_BAG_UPLOAD_DIR))throw new RuntimeException('Upload storage unavailable.');$token='bagbill-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(5)).'.'.$ext;$dest=TT_BAG_UPLOAD_DIR.'/'.$token;if(!move_uploaded_file((string)$f['tmp_name'],$dest))throw new RuntimeException('Could not save supplier bill.');bf_out(['ok'=>true,'token'=>$token,'originalName'=>$orig,'size'=>$size]);
}catch(Throwable $e){bf_out(['ok'=>false,'error'=>'The supplier bill file could not be saved.'],500);}