<?php
declare(strict_types=1);
require dirname(__DIR__) . '/auth_store.php';
require dirname(__DIR__) . '/backup_lib.php';
require dirname(__DIR__) . '/office_backup_auth.php';

function backup_json(array $data,int $status=200): never {http_response_code($status);header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
function backup_stream(string $path,string $filename): never {try{tt_backup_stream_file($path,$filename);}finally{@unlink($path);}exit;}

try {
    $admin=tt_require_login();if(($admin['role']??'')!=='Super Admin')backup_json(['ok'=>false,'error'=>'Super Admin access required.'],403);
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $action=(string)($_GET['action']??'status');if($action!=='status')backup_json(['ok'=>false,'error'=>'Unknown backup action.'],400);
        tt_release_read_session();
        backup_json(['ok'=>true,'officeBackupEnabled'=>tt_office_backup_enabled(),'officeSecurity'=>tt_office_policy_status()]+tt_backup_status());
    }
    if($_SERVER['REQUEST_METHOD']!=='POST')backup_json(['ok'=>false,'error'=>'Method not allowed.'],405);
    $multipart=str_contains(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'multipart/form-data');
    if($multipart){$action=(string)($_POST['action']??'');$csrf=(string)($_POST['csrf']??'');$password=(string)($_POST['password']??'');}
    else{$body=json_decode(file_get_contents('php://input')?:'{}',true);if(!is_array($body))$body=[];$action=(string)($body['action']??'');$csrf=(string)($body['csrf']??'');$password=(string)($body['password']??'');}
    if(!tt_verify_csrf($csrf))backup_json(['ok'=>false,'error'=>'Your session expired. Refresh and try again.'],419);

    if($action==='set-backup-password'){
        if(!hash_equals($password,(string)($body['confirmationPassword']??'')))throw new InvalidArgumentException('The backup passwords do not match.');
        tt_backup_password_set($password);tt_audit((int)$admin['id'],$admin['username'],'Updated owner-managed backup ZIP password');
        backup_json(['ok'=>true]);
    }
    if($action==='download-office-setup'){
        $path=tt_office_setup_archive();tt_audit((int)$admin['id'],$admin['username'],'Prepared one-use Office PC connection setup');
        backup_stream($path,'TRANSTRADE_CONNECT_OFFICE_PC.zip');
    }
    if($action==='issue-office-token'){
        $token=tt_office_backup_issue_token();tt_audit((int)$admin['id'],$admin['username'],'Rotated office backup-only credential');
        backup_json(['ok'=>true,'token'=>$token]);
    }
    if($action==='revoke-office-token'){
        tt_office_backup_revoke_token();tt_audit((int)$admin['id'],$admin['username'],'Revoked office backup-only credential');
        backup_json(['ok'=>true]);
    }

    if($action==='pin-office-recovery'){
        if(!hash_equals($password,(string)($body['confirmationPassword']??'')))throw new InvalidArgumentException('The two existing Office PC passwords must match.');
        tt_office_policy_pin($password);tt_audit((int)$admin['id'],$admin['username'],'Pinned existing Office PC recovery password verifier');
        backup_json(['ok'=>true,'officeSecurity'=>tt_office_policy_status()]);
    }
    if($action==='require-office-delivery-proof'){
        tt_office_policy_require_delivery();tt_audit((int)$admin['id'],$admin['username'],'Required independent password proof for Office delivery');
        backup_json(['ok'=>true,'officeSecurity'=>tt_office_policy_status()]);
    }

    if($action==='business-download'){
        $savedPassword=tt_backup_password_get();if($savedPassword===null)throw new InvalidArgumentException('Set your backup protection password in Passwords & Security first.');
        $built=tt_build_download_backup(false,$savedPassword);tt_backup_write_state(['last_owner_download_at'=>gmdate('c'),'last_owner_download_type'=>'business']);tt_audit((int)$admin['id'],$admin['username'],'Downloaded readable business-data backup');
        backup_stream($built['path'],'TRANSTRADE_BUSINESS_DATA_'.gmdate('Y-m-d_His').'.zip');
    }
    if($action==='full-download'){
        $built=tt_build_download_backup(true,tt_backup_password_get()??$password);tt_backup_write_state(['last_owner_download_at'=>gmdate('c'),'last_owner_download_type'=>'full']);tt_audit((int)$admin['id'],$admin['username'],'Downloaded complete encrypted recovery backup');
        backup_stream($built['path'],'TRANSTRADE_FULL_BACKUP_'.gmdate('Y-m-d_His').'.zip');
    }
    if($action==='snapshot'){
        $name=tt_create_server_snapshot('manual');tt_audit((int)$admin['id'],$admin['username'],'Created manual server recovery snapshot '.$name);backup_json(['ok'=>true,'snapshot'=>$name]+tt_backup_status());
    }
    if(in_array($action,['verify-server-snapshot','restore-server-snapshot'],true)){
        $name=(string)($body['snapshot']??'');
        $manifest=tt_backup_verified_server_snapshot($name);
        if($action==='verify-server-snapshot')backup_json(['ok'=>true,'createdAt'=>$manifest['createdAt']??null,'recoveryFileCount'=>(int)($manifest['recoveryFileCount']??0)]);
        if(strtoupper(trim((string)($body['confirmation']??'')))!=='RESTORE')throw new InvalidArgumentException('Type RESTORE to confirm recovery.');
        $result=tt_restore_server_snapshot($name);tt_audit((int)$admin['id'],$admin['username'],'Restored verified server snapshot '.$name);
        backup_json(['ok'=>true]+$result);
    }
    if(in_array($action,['verify','restore'],true))throw new InvalidArgumentException('Open Backup & Recovery → Restore website data to upload, verify and confirm this backup.');
    backup_json(['ok'=>false,'error'=>'Unknown backup action.'],400);
} catch(InvalidArgumentException $e){backup_json(['ok'=>false,'error'=>$e->getMessage()],422);}catch(Throwable $e){error_log('Transtrade backup action failed: '.$e->getMessage());backup_json(['ok'=>false,'error'=>'The backup action could not be completed safely. Check the server backup status or try again.'],500);}
?>
