<?php
declare(strict_types=1);
require dirname(__DIR__) . '/auth_store.php';
header('Content-Type: application/json; charset=UTF-8');
function location_respond(array $data,int $status=200): never { http_response_code($status); echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); exit; }
function location_can_write(array $user): bool {
    if (($user['role'] ?? '')==='Super Admin') return true;
    foreach (['Mill','Exports'] as $module) {
        $p=$user['permissions'][$module] ?? null;
        if ($p==='all') return true;
        foreach ((array)$p as $actions) if (is_array($actions) && (in_array('Create',$actions,true) || in_array('Edit',$actions,true))) return true;
    }
    return false;
}
try {
    $user=tt_require_login();
    if ($_SERVER['REQUEST_METHOD']!=='POST') location_respond(['ok'=>false,'error'=>'Method not allowed.'],405);
    if (!location_can_write($user)) location_respond(['ok'=>false,'error'=>'Create or Edit permission is required.'],403);
    $body=json_decode(file_get_contents('php://input') ?: '{}',true);
    if (!is_array($body) || !tt_verify_csrf((string)($body['csrf'] ?? ''))) location_respond(['ok'=>false,'error'=>'Your session expired. Refresh and try again.'],419);
    $name=trim((string)($body['name'] ?? ''));
    $type=trim((string)($body['type'] ?? ''));
    $source=trim((string)($body['source'] ?? 'Operational workflow'));
    $row=tt_upsert_location_master($name,$type,$source);
    tt_audit((int)($user['id'] ?? 0),(string)($user['username'] ?? ''),'Location master linked '.$name.' from '.$source);
    location_respond(['ok'=>true,'location'=>$row]);
} catch (InvalidArgumentException $e) { location_respond(['ok'=>false,'error'=>$e->getMessage()],422); }
catch (Throwable $e) { location_respond(['ok'=>false,'error'=>'The location master could not be updated.'],500); }
