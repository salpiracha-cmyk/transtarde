<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/document_filenames.php';
require dirname(__DIR__) . '/auth_store.php';
require dirname(__DIR__) . '/upload_validation.php';
require_once __DIR__.'/export_permission_policy.php';

function export_docs_respond(array $data,int $status=200): never {http_response_code($status);header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
function export_docs_env(string $name): string {$c='TT_'.$name;if(defined($c))return(string)constant($c);$v=getenv($c);return$v===false?'':(string)$v;}
function export_docs_db_configured(): bool {return export_docs_env('DB_HOST')!==''&&export_docs_env('DB_NAME')!==''&&export_docs_env('DB_USER')!=='';}
function export_docs_db(): PDO {return new PDO('mysql:host='.export_docs_env('DB_HOST').';dbname='.export_docs_env('DB_NAME').';charset=utf8mb4',export_docs_env('DB_USER'),export_docs_env('DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);}
function export_docs_base(): string {tt_ensure_data_dir();$base=rtrim((string)TT_DATA_DIR,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'export_documents';if(!is_dir($base)&&!mkdir($base,0700,true)&&!is_dir($base))throw new RuntimeException('Document storage directory is unavailable.');return$base;}
function export_docs_index_path(): string {return export_docs_base().DIRECTORY_SEPARATOR.'index.json';}
/** All fallback index mutations share one stable lock across atomic replacement. */
function export_docs_index_lock(int $mode) {
    $h=fopen(export_docs_index_path().'.lock','c+b');
    if($h===false)throw new RuntimeException('Document metadata lock is unavailable.');
    if(!flock($h,$mode)){fclose($h);throw new RuntimeException('Document metadata is busy.');}
    return $h;
}
function export_docs_index_read_locked(): array {
    $path=export_docs_index_path();if(!is_file($path))return [];
    $raw=file_get_contents($path);
    if($raw===false||trim($raw)==='')throw new RuntimeException('Document metadata is empty or unavailable.');
    $rows=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    if(!is_array($rows)||($rows!==[]&&array_is_list($rows)))throw new RuntimeException('Document metadata has an invalid format.');
    foreach($rows as $row)if(!is_array($row))throw new RuntimeException('Document metadata contains an invalid record.');
    // Reject nonfinite JSON numbers before any destructive follow-up action.
    json_encode($rows,JSON_THROW_ON_ERROR);return $rows;
}
function export_docs_index_commit_locked(array $rows): void {
    $json=json_encode($rows,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $path=export_docs_index_path();$temp=dirname($path).'/.index-'.bin2hex(random_bytes(12)).'.tmp';
    $h=fopen($temp,'x+b');if($h===false)throw new RuntimeException('Document metadata could not be prepared.');
    try{
        @chmod($temp,0600);$written=0;$length=strlen($json);
        while($written<$length){$n=fwrite($h,substr($json,$written));if($n===false||$n===0)throw new RuntimeException('Document metadata could not be written.');$written+=$n;}
        if(!fflush($h)||(function_exists('fsync')&&!fsync($h)))throw new RuntimeException('Document metadata could not be synchronized.');
        fclose($h);$h=null;
        if(!rename($temp,$path))throw new RuntimeException('Document metadata could not be committed.');
    }finally{if(is_resource($h))fclose($h);if(is_file($temp))@unlink($temp);}
}
function export_docs_index_read(): array {
    $lock=export_docs_index_lock(LOCK_SH);
    try{return export_docs_index_read_locked();}finally{flock($lock,LOCK_UN);fclose($lock);}
}
function export_docs_index_mutate(callable $change): mixed {
    $lock=export_docs_index_lock(LOCK_EX);
    try{$rows=export_docs_index_read_locked();$before=$rows;$result=$change($rows);if($rows!==$before)export_docs_index_commit_locked($rows);return $result;}
    finally{flock($lock,LOCK_UN);fclose($lock);}
}
function export_docs_file_find(string $id): ?array {$rows=export_docs_index_read();$r=$rows[$id]??null;return is_array($r)&&empty($r['deleted_at'])?$r:null;}
function export_docs_file_insert(array $row): void {
    export_docs_index_mutate(static function(array &$rows)use($row):void{
        $id=(string)$row['id'];if(isset($rows[$id])&&$rows[$id]!==$row)throw new RuntimeException('Document reference already exists.');$rows[$id]=$row;
    });
}
function export_docs_find(string $id): ?array {
    if(export_docs_db_configured())try{$db=export_docs_db();$q=$db->prepare('SELECT id,contract_ref,lot_ref,category,original_name,stored_name,mime_type,file_size,uploaded_at,uploaded_by,uploaded_by_user,deleted_at FROM tt_export_documents WHERE id=? AND deleted_at IS NULL');$q->execute([$id]);$row=$q->fetch();if($row)return$row;}catch(Throwable $e){error_log('Export document DB lookup fallback: '.$e->getMessage());}
    return export_docs_file_find($id);
}
function export_docs_insert(array $row): void {
    if(export_docs_db_configured())try{$db=export_docs_db();$db->exec("CREATE TABLE IF NOT EXISTS tt_export_documents (id CHAR(32) NOT NULL PRIMARY KEY,contract_ref VARCHAR(100) NOT NULL,lot_ref VARCHAR(100) NOT NULL DEFAULT '',category VARCHAR(64) NOT NULL,original_name VARCHAR(255) NOT NULL,stored_name VARCHAR(80) NOT NULL UNIQUE,mime_type VARCHAR(100) NOT NULL,file_size BIGINT UNSIGNED NOT NULL,uploaded_at DATETIME(6) NOT NULL,uploaded_by VARCHAR(160) NOT NULL,uploaded_by_user BIGINT NULL,deleted_at DATETIME(6) NULL,INDEX idx_export_documents_lot (contract_ref,lot_ref,category),INDEX idx_export_documents_uploaded (uploaded_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");$q=$db->prepare('INSERT INTO tt_export_documents (id,contract_ref,lot_ref,category,original_name,stored_name,mime_type,file_size,uploaded_at,uploaded_by,uploaded_by_user) VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6),?,?)');$q->execute([$row['id'],$row['contract_ref'],$row['lot_ref'],$row['category'],$row['original_name'],$row['stored_name'],$row['mime_type'],$row['file_size'],$row['uploaded_by'],$row['uploaded_by_user']]);return;}catch(Throwable $e){error_log('Export document DB insert fallback: '.$e->getMessage());}
    export_docs_file_insert($row);
}
function export_docs_delete_contract(string $contractRef): int {
    // Validate the fallback source before marking any database/file originals.
    export_docs_index_read();$deleted=0;$base=export_docs_base();$paths=[];
    if(export_docs_db_configured())try{
        $db=export_docs_db();$q=$db->prepare('SELECT id,stored_name,deleted_at FROM tt_export_documents WHERE contract_ref=?');$q->execute([$contractRef]);$found=$q->fetchAll();
        if($found){$u=$db->prepare('UPDATE tt_export_documents SET deleted_at=UTC_TIMESTAMP(6) WHERE contract_ref=? AND deleted_at IS NULL');$u->execute([$contractRef]);$deleted+=$u->rowCount();foreach($found as $row)$paths[]=$base.DIRECTORY_SEPARATOR.basename((string)$row['stored_name']);}
    }catch(Throwable $e){error_log('Export document DB deletion fallback: '.$e->getMessage());}
    $result=export_docs_index_mutate(static function(array &$rows)use($contractRef,$base):array{
        $count=0;$paths=[];
        foreach($rows as &$row){
            if((string)($row['contract_ref']??'')!==$contractRef)continue;
            $paths[]=$base.DIRECTORY_SEPARATOR.basename((string)($row['stored_name']??''));
            if(empty($row['deleted_at'])){$row['deleted_at']=gmdate('c');$count++;}
        }unset($row);return ['count'=>$count,'paths'=>$paths];
    });
    // Commit tombstones before removing bytes. A retry can finish file cleanup
    // even when the earlier metadata change succeeded but deletion did not.
    foreach(array_unique(array_merge($paths,$result['paths'])) as $path)if(is_file($path)&&!@unlink($path))throw new RuntimeException('A protected shipment document could not be removed.');
    return $deleted+$result['count'];
}
function export_docs_upload_error(int $code): string {return match($code){UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE=>'The selected file is larger than the server upload limit.',UPLOAD_ERR_PARTIAL=>'The file upload was interrupted. Please choose the file again.',UPLOAD_ERR_NO_FILE=>'Choose a PDF, JPEG, PNG or WebP file.',UPLOAD_ERR_NO_TMP_DIR,UPLOAD_ERR_CANT_WRITE,UPLOAD_ERR_EXTENSION=>'Document storage is temporarily unavailable. The file was not saved.',default=>'The selected file could not be uploaded.'};}

try{
    // The shared API guard returns JSON on expiry and releases authenticated GET locks.
    $user=tt_require_login();
    if(!tt_user_can_open_module($user,'Exports')&&!($_SERVER['REQUEST_METHOD']==='GET'&&tt_user_can_open_module($user,'Accounts')))export_docs_respond(['ok'=>false,'error'=>'Exports access is required.'],403);
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $id=strtolower(trim((string)($_GET['id']??'')));if(!preg_match('/^[a-f0-9]{32}$/',$id))export_docs_respond(['ok'=>false,'error'=>'Invalid document reference.'],422);$row=export_docs_find($id);if(!$row)export_docs_respond(['ok'=>false,'error'=>'Document not found.'],404);ep_require_document_target($user,(string)$row['contract_ref'],(string)$row['lot_ref'],(string)$row['category'],'View');$path=export_docs_base().DIRECTORY_SEPARATOR.basename((string)$row['stored_name']);if(!is_file($path))export_docs_respond(['ok'=>false,'error'=>'Stored document is unavailable.'],404);header('Content-Type: '.(string)$row['mime_type']);header('Content-Length: '.filesize($path));tt_document_disposition(tt_export_document_name(ep_operations_root(),(string)$row['contract_ref'],(string)$row['lot_ref'],ucwords(str_replace('-',' ',(string)$row['category'])),tt_document_extension((string)$row['original_name'],(string)$row['mime_type'])));tt_uploaded_document_headers();readfile($path);exit;
    }
    if($_SERVER['REQUEST_METHOD']!=='POST')export_docs_respond(['ok'=>false,'error'=>'Method not allowed.'],405);if(!tt_verify_csrf((string)($_POST['csrf']??'')))export_docs_respond(['ok'=>false,'error'=>'Your session expired. Refresh and try again.'],419);
    $action=trim((string)($_POST['action']??''));if($action==='delete-shipment'){$contractRef=trim((string)($_POST['contractRef']??''));if($contractRef===''||strlen($contractRef)>100)export_docs_respond(['ok'=>false,'error'=>'Select a valid shipment before deleting its documents.'],422);ep_require($user,'active','Edit');$root=ep_operations_root();$deletedInRoot=false;foreach((array)($root['deletedShipments']??[]) as $deletedRecord)if(($deletedRecord['contractRef']??'')===$contractRef&&!empty($deletedRecord['deletedAt'])&&empty($deletedRecord['restoredAt']))$deletedInRoot=true;if(!$deletedInRoot)throw new DomainException('Save the shipment deletion before deleting its files.');$scopeContract=null;foreach((array)($root['contracts']??[]) as $row)if(($row['ref']??'')===$contractRef)$scopeContract=$row;if(!$scopeContract||!tt_user_business_entity($user,strtoupper((string)($scopeContract['seller']??'TTI')),'Edit'))throw new DomainException('Company document Edit access is required.');$deleted=export_docs_delete_contract($contractRef);export_docs_respond(['ok'=>true,'deleted'=>$deleted]);}
    if(!isset($_FILES['file'])||!is_array($_FILES['file']))export_docs_respond(['ok'=>false,'error'=>'Choose a PDF, JPEG, PNG or WebP file.'],422);$err=(int)($_FILES['file']['error']??UPLOAD_ERR_NO_FILE);if($err!==UPLOAD_ERR_OK)export_docs_respond(['ok'=>false,'error'=>export_docs_upload_error($err)],$err===UPLOAD_ERR_INI_SIZE||$err===UPLOAD_ERR_FORM_SIZE?413:422);
    $validated=tt_validate_document_upload($_FILES['file'],10*1024*1024,['application/pdf'=>'pdf','image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp']);$size=$validated['size'];$tmp=$validated['path'];$mime=$validated['mime'];
    $category=strtolower(trim((string)($_POST['category']??'')));if(!preg_match('/^[a-z0-9-]{3,64}$/',$category))export_docs_respond(['ok'=>false,'error'=>'Invalid document category.'],422);$contractRef=trim((string)($_POST['contractRef']??''));$lotId=trim((string)($_POST['lotId']??''));if($contractRef===''||strlen($contractRef)>100||strlen($lotId)>100)export_docs_respond(['ok'=>false,'error'=>'Select a valid contract before uploading the file.'],422);
    ep_require_document_target($user,$contractRef,$lotId,$category,'Create');
    $id=bin2hex(random_bytes(16));$stored=$id.'.'.$validated['extension'];$base=export_docs_base();if(!move_uploaded_file($tmp,$base.DIRECTORY_SEPARATOR.$stored))throw new RuntimeException('Uploaded file could not be moved to protected storage.');$name=tt_upload_original_name((string)($_FILES['file']['name']??''),'document.'.$validated['extension']);$row=['id'=>$id,'contract_ref'=>$contractRef,'lot_ref'=>$lotId,'category'=>$category,'original_name'=>$name,'stored_name'=>$stored,'mime_type'=>$mime,'file_size'=>$size,'uploaded_at'=>gmdate('c'),'uploaded_by'=>(string)($user['full_name']??$user['username']??'Staff'),'uploaded_by_user'=>isset($user['id'])?(int)$user['id']:null,'deleted_at'=>null];
    try{export_docs_insert($row);}catch(Throwable $e){@unlink($base.DIRECTORY_SEPARATOR.$stored);throw$e;}
    export_docs_respond(['ok'=>true,'document'=>['id'=>$id,'name'=>$name,'mime'=>$mime,'size'=>$size,'category'=>$category,'uploadedAt'=>gmdate('c'),'downloadUrl'=>'/api/export_documents.php?id='.$id]]);
}catch(DomainException $e){export_docs_respond(['ok'=>false,'error'=>$e->getMessage()],403);}catch(InvalidArgumentException $e){export_docs_respond(['ok'=>false,'error'=>$e->getMessage()],422);}catch(Throwable $e){error_log('Transtrade export document API: '.$e->getMessage());export_docs_respond(['ok'=>false,'error'=>'Document storage is temporarily unavailable. The file was not saved. Please retry.'],500);}
