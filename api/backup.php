<?php
declare(strict_types=1);
require dirname(__DIR__) . '/auth_store.php';
require dirname(__DIR__) . '/backup_lib.php';

function backup_json(array $data,int $status=200): never {http_response_code($status);header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
function backup_stream(string $path,string $filename): never {header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="'.$filename.'"');header('Content-Length: '.filesize($path));header('Cache-Control: no-store, no-cache, must-revalidate');readfile($path);@unlink($path);exit;}

try {
    $admin=tt_require_login();if(($admin['role']??'')!=='Super Admin')backup_json(['ok'=>false,'error'=>'Super Admin access required.'],403);
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $action=(string)($_GET['action']??'status');if($action!=='status')backup_json(['ok'=>false,'error'=>'Unknown backup action.'],400);
        backup_json(['ok'=>true]+tt_backup_status());
    }
    if($_SERVER['REQUEST_METHOD']!=='POST')backup_json(['ok'=>false,'error'=>'Method not allowed.'],405);
    $multipart=str_contains(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'multipart/form-data');
    if($multipart){$action=(string)($_POST['action']??'');$csrf=(string)($_POST['csrf']??'');$password=(string)($_POST['password']??'');}
    else{$body=json_decode(file_get_contents('php://input')?:'{}',true);if(!is_array($body))$body=[];$action=(string)($body['action']??'');$csrf=(string)($body['csrf']??'');$password=(string)($body['password']??'');}
    if(!tt_verify_csrf($csrf))backup_json(['ok'=>false,'error'=>'Your session expired. Refresh and try again.'],419);

    if($action==='business-download'){
        $built=tt_build_download_backup(false);tt_backup_write_state(['last_owner_download_at'=>gmdate('c'),'last_owner_download_type'=>'business']);tt_audit((int)$admin['id'],$admin['username'],'Downloaded readable business-data backup');
        backup_stream($built['path'],'TRANSTRADE_BUSINESS_DATA_'.gmdate('Y-m-d_His').'.zip');
    }
    if($action==='full-download'){
        $built=tt_build_download_backup(true,$password);tt_backup_write_state(['last_owner_download_at'=>gmdate('c'),'last_owner_download_type'=>'full']);tt_audit((int)$admin['id'],$admin['username'],'Downloaded complete encrypted recovery backup');
        backup_stream($built['path'],'TRANSTRADE_FULL_BACKUP_'.gmdate('Y-m-d_His').'.zip');
    }
    if($action==='snapshot'){
        $name=tt_create_server_snapshot('manual');tt_audit((int)$admin['id'],$admin['username'],'Created manual server recovery snapshot '.$name);backup_json(['ok'=>true,'snapshot'=>$name]+tt_backup_status());
    }
    if(in_array($action,['verify','restore'],true)){
        if(!$multipart||empty($_FILES['backup']['tmp_name'])||!is_uploaded_file($_FILES['backup']['tmp_name']))throw new InvalidArgumentException('Select a Complete Transtrade Backup ZIP.');
        if((int)($_FILES['backup']['size']??0)>512*1024*1024)throw new InvalidArgumentException('The selected backup exceeds the 512 MB restore limit.');
        $tmp=(string)$_FILES['backup']['tmp_name'];[$zip,$manifest]=tt_backup_open_verified($tmp,$password);$zip->close();
        if($action==='verify')backup_json(['ok'=>true,'createdAt'=>$manifest['createdAt']??null,'appVersion'=>$manifest['appVersion']??null,'recoveryFileCount'=>(int)($manifest['recoveryFileCount']??0)]);
        if(strtoupper(trim((string)($_POST['confirmation']??'')))!=='RESTORE')throw new InvalidArgumentException('Type RESTORE to confirm recovery.');
        $result=tt_restore_complete_backup($tmp,$password);tt_audit((int)$admin['id'],$admin['username'],'Restored complete Transtrade backup created '.($manifest['createdAt']??'unknown'));
        backup_json(['ok'=>true,'restoredFiles'=>$result['restoredFiles'],'safetySnapshot'=>$result['safetySnapshot']]);
    }
    backup_json(['ok'=>false,'error'=>'Unknown backup action.'],400);
} catch(InvalidArgumentException $e){backup_json(['ok'=>false,'error'=>$e->getMessage()],422);}catch(Throwable $e){backup_json(['ok'=>false,'error'=>'The backup action could not be completed safely. Check the server backup support or try again.'],500);}
?>
