<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
require_once __DIR__.'/bag_shipment_usage_core.php';
require_once __DIR__.'/bag_transfers_store.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
class BagTransferAccessDenied extends RuntimeException {}
function bgt_out(array $data,int $status=200): never {http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit;}
function bgt_master(array $masters,string $id): array {
    $matches=[];foreach((array)($masters['mills']??[]) as $r)if((string)($r['id']??'')===$id){$v=array_values((array)($r['values']??[]));$matches[]=['id'=>$id,'name'=>(string)($v[0]??''),'type'=>(string)($v[2]??''),'status'=>(string)($v[5]??'')];}
    if(count($matches)!==1||$matches[0]['name']==='')throw new DomainException('Select one actual Mills & Locations master.');return $matches[0];
}
function bgt_access(array $user,string $entity,array $location,string $action): void {
    if(!in_array($entity,['TTI','BRM'],true)||!tt_user_can_open_module($user,'Mill')||!tt_user_can_module_action($user,'Mill','newbags',$action))throw new BagTransferAccessDenied('New Export Bags '.$action.' permission is required.');
    $scope=bti_norm($user['location']??'');if(($user['role']??'')!=='Super Admin'&&!in_array($scope,['','all locations','all authorized locations'],true)&&$scope!==bti_norm($location['name']))throw new BagTransferAccessDenied('This action belongs to another assigned physical mill.');
    $grants=(array)($user['permissions']['Accounts']??[]);foreach(['entity-tti','entity-brm','entity-tg'] as $key)if(array_key_exists($key,$grants)){if(!tt_user_can_access_entity($user,$entity,$action))throw new BagTransferAccessDenied('The original stock owner is outside this action company permission.');break;}
}
function bgt_can(array $u,string $entity,array $location,string $action): bool {try{bgt_access($u,$entity,$location,$action);return true;}catch(BagTransferAccessDenied){return false;}}
function bgt_exmill_dispatch(array $masters,array $record): bool {
    $source=bgt_master($masters,$record['source']['locationId']);
    $destination=bgt_master($masters,$record['destination']['id']);
    return strcasecmp(trim($source['type']),'Own Mill')===0&&strcasecmp(trim($destination['type']),'External Mill')===0&&$destination['status']==='Active';
}
function bgt_view(array $values,array $state,array $masters,array $scope,string $date,string $today,array $user): array {
    $inventory=bti_project($values,$masters,$scope,$date,$today,$state);$transfers=[];
    $recorded=[];
    foreach($state['transfers'] as $transfer)foreach($transfer['shipmentBagUsage']??[] as $usage)$recorded[$usage['shipmentId']]=true;
    foreach($state['transfers'] as $r){
        if(($r['entity']??'')!==$scope['entity']||!in_array($scope['locationId'],[$r['source']['locationId'],$r['destination']['id']],true))continue;
        $candidates=[];
        $canReceive=bgt_can($user,$r['entity'],$r['destination'],'Create');
        if($canReceive){
            foreach(bsu_candidates($values,$r) as $row){
                if(empty($recorded[$row['shipmentId']]))$candidates[]=$row+['fingerprint'=>bt_hash($row)];
            }
        }
        $status=bt_status($r);
        $canComplete=false;
        if($status==='In transit'&&$r['source']['locationId']===$scope['locationId']){
            try{$canComplete=bgt_can($user,$r['entity'],bgt_master($masters,$scope['locationId']),'Create')&&bgt_exmill_dispatch($masters,$r);}
            catch(DomainException){}
        }
        $transfers[]=$r+['status'=>$status,'canCompleteExMillDispatch'=>$canComplete,'quantities'=>bt_totals($r),'shipmentCandidates'=>$candidates,'canReceiveAtDestination'=>$canReceive];
    }
    return ['inventory'=>$inventory,'transfers'=>$transfers];
}
try{
    $user=tt_require_login();$method=$_SERVER['REQUEST_METHOD']??'GET';$masters=tt_list_masters();
    if($method==='GET'){
        $entity=(string)($_GET['entity']??'');$location=bgt_master($masters,(string)($_GET['locationId']??''));$productionView=($_GET['purpose']??'')==='production';
        if($productionView)bti_production_access($user,$entity,['locationId'=>$location['id'],'millName'=>$location['name'],'status'=>$location['status']],'View');else bgt_access($user,$entity,$location,'View');$today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Karachi')))->format('Y-m-d');$date=(string)($_GET['asOf']??$today);
        $scope=['entity'=>$entity,'locationId'=>$location['id'],'millName'=>$location['name'],'status'=>$location['status']];if(function_exists('tt_release_read_session'))tt_release_read_session();
        $exclude=$productionView&&isset($_GET['excludeProductionId'])?(string)$_GET['excludeProductionId']:null;
        $result=bts_with_sources(false,$user,static fn($values,$state,$now)=>$productionView?['inventory'=>bti_project($values,$masters,$scope,$date,$today,$state,$exclude)]:bgt_view($values,$state,$masters,$scope,$date,$today,$user));
        $destinations=[];foreach((array)($masters['mills']??[]) as $r){$v=array_values((array)($r['values']??[]));if(($v[5]??'')==='Active'&&(string)$r['id']!==$location['id'])$destinations[]=['id'=>(string)$r['id'],'name'=>(string)($v[0]??'')];}
        bgt_out(['ok'=>true,'entity'=>$entity,'locationId'=>$location['id'],'canCreate'=>bgt_can($user,$entity,$location,'Create'),'destinations'=>$productionView?[]:$destinations]+$result);
    }
    if($method!=='POST')bgt_out(['ok'=>false,'error'=>'Method not allowed.'],405);
    $raw=(function_exists('tt_accounts_input')?tt_accounts_input():file_get_contents('php://input'))?:'';if(strlen($raw)>65536)bgt_out(['ok'=>false,'error'=>'Bag movement request too large.'],413);
    $body=json_decode($raw,true);if(!is_array($body)||!tt_verify_csrf((string)($body['csrf']??'')))bgt_out(['ok'=>false,'error'=>'Refresh the session before saving the movement.'],419);
    $action=(string)($body['action']??'');if(!in_array($action,['dispatch','receive','return_to_source','quarantine','accept_quarantine','return_quarantine','shipment_usage','complete_exmill_dispatch'],true))bgt_out(['ok'=>false,'error'=>'Choose an explicit supported bag movement action.'],422);
    $result=bts_with_sources(true,$user,static function($values,array &$state,$now)use($body,$action,$user,$masters){
        $authorize=static function($entity,$historicalName,$right,$locationId)use($user,$masters){bgt_access($user,$entity,bgt_master($masters,$locationId),$right);};
        if($action==='complete_exmill_dispatch'){
            $record=$state['transfers'][(string)($body['transferId']??'')]??null;
            if(!$record||$record['entity']!==($body['entity']??'')||!bgt_exmill_dispatch($masters,$record))throw new DomainException('Select the original dispatch from our mill to an Ex-Mill.');
            $authorize($record['entity'],$record['source']['millName'],'Create',$record['source']['locationId']);
            return ['movement'=>bt_complete_exmill_dispatch($state,$record,$body,$user,$now)];
        }
        if($action==='shipment_usage')return ['movement'=>bsu_record($values,$state,$masters,$body,$user,$now,$authorize)];
        if($action==='dispatch'){
            $source=bgt_master($masters,(string)($body['sourceLocationId']??''));$entity=(string)($body['entity']??'');bgt_access($user,$entity,$source,'Create');
            $destination=bgt_master($masters,(string)($body['destinationId']??''));$scope=['entity'=>$entity,'locationId'=>$source['id'],'millName'=>$source['name'],'status'=>$source['status']];$today=(new DateTimeImmutable($now))->setTimezone(new DateTimeZone('Asia/Karachi'))->format('Y-m-d');
            $inventory=bti_project($values,$masters,$scope,(string)($body['date']??''),$today,$state);$id='BT-'.substr(hash('sha256',(int)$user['id'].'|'.(string)($body['requestKey']??'')),0,24);
            $inventory['scope']['type']=$source['type'];
            return ['movement'=>bt_dispatch($state,$inventory,$destination,$body,$user,$now,$id,$authorize)];
        }
        return ['movement'=>in_array($action,['quarantine','accept_quarantine','return_quarantine'],true)?bt_quarantine($state,$body,$user,$now,$authorize):bt_receive($state,$body,$user,$now,$authorize,$action==='return_to_source')];
    });bgt_out(['ok'=>true]+$result);
}catch(BagTransferAccessDenied $e){bgt_out(['ok'=>false,'error'=>$e->getMessage()],403);}
catch(DomainException|InvalidArgumentException $e){bgt_out(['ok'=>false,'error'=>$e->getMessage()],422);}
catch(Throwable $e){error_log('Bag transfer: '.$e->getMessage());bgt_out(['ok'=>false,'error'=>'Bag stock could not be confirmed. Your entry is retained; retry the same action.'],500);}
