<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
require_once __DIR__.'/directors_trading_core.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store');
function fem_out(array $body,int $status=200):never {
    http_response_code($status);
    echo json_encode($body,JSON_THROW_ON_ERROR);
    exit;
}
try {
    $user=tt_require_login();
    if ($_SERVER['REQUEST_METHOD']!=='GET') {
        header('Allow: GET');
        fem_out(['ok'=>false,'error'=>'Manage fellow exporters in Master Records → Business Parties.'],405);
    }
    $entity=dt_entity($_GET['entity']??'');
    if (!tt_user_can_master($user,'business_parties','View') || !tt_user_can_access_entity($user,$entity,'View')) {
        fem_out(['ok'=>false,'error'=>'Matching Parties master and company permission required.'],403);
    }
    $path=TT_DATA_DIR.'/accounts.json';
    $store=[];
    if (is_file($path)) {
        $handle=fopen($path,'rb');
        if (!$handle || !flock($handle,LOCK_SH)) throw new RuntimeException('Accounts storage unavailable.');
        try {$store=json_decode((string)stream_get_contents($handle),true,512,JSON_THROW_ON_ERROR);}
        finally {flock($handle,LOCK_UN);fclose($handle);}
    }
    fem_out(['ok'=>true,'entity'=>$entity,'parties'=>fe_parties($store,$entity),'access'=>['canCreate'=>false,'canEdit'=>false,'canDelete'=>false],'result'=>[]]);
} catch (DomainException $error) {
    fem_out(['ok'=>false,'error'=>$error->getMessage()],422);
} catch (Throwable $error) {
    error_log('Fellow exporter list: '.$error->getMessage());
    fem_out(['ok'=>false,'error'=>'Fellow exporters could not be loaded.'],500);
}
