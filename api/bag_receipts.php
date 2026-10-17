<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
require_once __DIR__.'/bag_receipts_core.php';
require_once __DIR__.'/bag_transfers_store.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
class BagReceiptDenied extends RuntimeException {}
function br_out(array $value,int $status=200): never {http_response_code($status);echo json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
function br_access(array $user,array $masters,string $entity,string $locationId,string $right): void {
    $location=bti_location($masters,'',$locationId);if(!$location)throw new DomainException('Select an actual physical receipt location.');
    if(!tt_user_can_open_module($user,'Mill')||!tt_user_can_module_action($user,'Mill','newbags',$right))throw new BagReceiptDenied('New Export Bags '.$right.' permission is required.');
    $assigned=bti_norm($user['location']??'');if(($user['role']??'')!=='Super Admin'&&!in_array($assigned,['','all locations','all authorized locations'],true)&&$assigned!==bti_norm($location['millName']))throw new BagReceiptDenied('This receipt belongs to another assigned physical mill.');
    $grants=(array)($user['permissions']['Accounts']??[]);foreach(['entity-tti','entity-brm','entity-tg'] as $key)if(array_key_exists($key,$grants)){if(!tt_user_can_access_entity($user,$entity,$right))throw new BagReceiptDenied('This receipt belongs to another stock owner.');break;}
}
try {
    $user=tt_require_login();$masters=tt_list_masters();$method=$_SERVER['REQUEST_METHOD']??'GET';
    if($method==='GET'){
        $bagId=(string)($_GET['bagId']??'');$entity=(string)($_GET['entity']??'');$locationId=(string)($_GET['locationId']??'');br_access($user,$masters,$entity,$locationId,'View');if(function_exists('tt_release_read_session'))tt_release_read_session();
        $result=bts_with_sources(false,$user,static function($values,$state,$now)use($masters,$bagId,$entity,$locationId){$source=br_source($values,$masters,$bagId);if($source['scope']['entity']!==$entity||$source['scope']['locationId']!==$locationId)throw new BagReceiptDenied('Select a receipt order for this stock owner and physical location.');return ['source'=>$source];});$canEdit=true;try{br_access($user,$masters,$entity,$locationId,'Edit');}catch(BagReceiptDenied){$canEdit=false;}br_out(['ok'=>true,'canEdit'=>$canEdit]+$result);
    }
    if($method!=='POST')br_out(['ok'=>false,'error'=>'Method not allowed.'],405);
    $raw=(function_exists('tt_accounts_input')?tt_accounts_input():file_get_contents('php://input'))?:'';if(strlen($raw)>32768)br_out(['ok'=>false,'error'=>'Receipt request too large.'],413);$body=json_decode($raw,true);
    if(!is_array($body)||!tt_verify_csrf((string)($body['csrf']??'')))br_out(['ok'=>false,'error'=>'Refresh the session before saving this receipt.'],419);
    $result=bts_with_sources(true,$user,static function(array &$values,array &$state,$now)use($masters,$body,$user){$authorize=static fn($entity,$name,$right,$id)=>br_access($user,$masters,$entity,$id,$right);return br_commit($values,$state,$masters,$body,$user,$now,$authorize);});br_out(['ok'=>true]+$result);
}catch(BagReceiptDenied $e){br_out(['ok'=>false,'error'=>$e->getMessage()],403);}
catch(DomainException|InvalidArgumentException $e){br_out(['ok'=>false,'error'=>$e->getMessage()],422);}
catch(Throwable $e){error_log('Bag receipt: '.$e->getMessage());br_out(['ok'=>false,'error'=>'Receipt saving could not be confirmed. Your entry is retained; confirm the same request.'],500);}
