<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
function admin_audit_out(array $data,int $status=200): never {
    http_response_code($status);
    echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}
try {
    $user=tt_require_login();
    if(($user['role']??'')!=='Super Admin')admin_audit_out(['ok'=>false,'error'=>'Super Admin access required.'],403);
    if(($_SERVER['REQUEST_METHOD']??'')!=='GET'){
        header('Allow: GET');
        admin_audit_out(['ok'=>false,'error'=>'Method not allowed.'],405);
    }
    tt_release_read_session();
    $store=tt_read_store();$rows=[];
    foreach((array)($store['audit']??[]) as $entry){
        if(!is_array($entry))continue;
        // Deliberately expose only audit display fields, never the auth store,
        // addresses, password hashes, recovery credentials or master payloads.
        $detail=(string)($entry['action']??'');
        $area=preg_match('/permission/i',$detail)?'Permission':(preg_match('/master|location|company bank/i',$detail)?'Master':(preg_match('/user|password|signed in|signed out|recovery/i',$detail)?'User':'Server'));
        preg_match('/POST-\d{4}-\d{5}/i',$detail,$reference);
        $rows[]=['date'=>(string)($entry['created_at']??''),'user'=>(string)($entry['username']??''),'area'=>$area,'action'=>'Recorded','detail'=>$detail,'ref'=>$reference[0]??'','source'=>'Server audit'];
    }
    usort($rows,static fn($a,$b)=>strcmp($b['date'],$a['date']));
    admin_audit_out(['ok'=>true,'entries'=>$rows,'retainedCount'=>count($rows)]);
}catch(Throwable $error){
    admin_audit_out(['ok'=>false,'error'=>'Server audit could not be loaded. Refresh to try again.'],500);
}
