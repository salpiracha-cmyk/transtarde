<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
require_once __DIR__.'/other_exporter_bags_core.php';
require_once __DIR__.'/bag_transfers_store.php';
require_once __DIR__.'/accounts_shipment_source.php';
require_once dirname(__DIR__).'/inventory_reconciliation.php';
require_once __DIR__.'/operations_scope.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
class OebDenied extends RuntimeException {}
function oeb_out(array $data,int $status=200): never {http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit;}
function oeb_access(array $u,array $scope,string $right,bool $production=false): void {
    if(!in_array($scope['entity'],['TTI','BRM'],true)||!tt_user_can_open_module($u,'Mill')||!tt_user_can_module_action($u,'Mill',$production?'production':'newbags',$right))throw new OebDenied('Export Bags '.$right.' permission is required.');
    $assigned=bti_norm($u['location']??'');if(($u['role']??'')!=='Super Admin'&&!in_array($assigned,['','all locations','all authorized locations'],true)&&$assigned!==bti_norm($scope['millName']))throw new OebDenied('Select your assigned physical mill.');
    foreach(['entity-tti','entity-brm','entity-tg'] as $key)if(array_key_exists($key,(array)($u['permissions']['Accounts']??[]))){if(!tt_user_can_access_entity($u,$scope['entity'],$right))throw new OebDenied('Company permission is required.');break;}
}
try {
    $u=tt_require_login();$method=$_SERVER['REQUEST_METHOD']??'GET';if(!in_array($method,['GET','POST'],true))oeb_out(['ok'=>false,'error'=>'Method not allowed.'],405);
    $write=$method==='POST';$raw=$write?((function_exists('tt_accounts_input')?tt_accounts_input():file_get_contents('php://input'))?:''):'';if(strlen($raw)>16000)throw new DomainException('Receipt request too large.');$b=$write?json_decode($raw,true,64,JSON_THROW_ON_ERROR):$_GET;if(!is_array($b))throw new DomainException('Invalid receipt request.');
    if($write&&!tt_verify_csrf((string)($b['csrf']??'')))oeb_out(['ok'=>false,'error'=>'Refresh the session before saving.'],419);
    $masters=tt_list_masters();$location=bti_location($masters,'',(string)($b['locationId']??''));if(!$location||$location['status']!=='Active')throw new DomainException('Select an active physical mill.');
    $scope=['entity'=>(string)($b['entity']??''),'locationId'=>$location['locationId'],'millName'=>$location['millName']];$production=!$write&&($b['purpose']??'')==='production';oeb_access($u,$scope,$write?'Create':'View',$production);if(!$write&&function_exists('tt_release_read_session'))tt_release_read_session();
    $path=TT_DATA_DIR.'/accounts.json';$h=fopen($path,$write?'r+':'rb');if(!$h||!flock($h,$write?LOCK_EX:LOCK_SH))throw new RuntimeException('SODA source unavailable.');
    try {
        $books=json_decode((string)stream_get_contents($h),true,512,JSON_THROW_ON_ERROR);if(!is_array($books))throw new RuntimeException('Invalid SODA source.');
        $now=gmdate('c');$today=(new DateTimeImmutable($now))->setTimezone(new DateTimeZone('Asia/Karachi'))->format('Y-m-d');
        $authorize=static function($entity,$name,$right,$id)use($u,$scope){if($entity!==$scope['entity']||$id!==$scope['locationId'])throw new OebDenied('Receipt source changed.');oeb_access($u,$scope,$right);};
        if(!$write){
            $date=bt_date((string)($b['asOf']??$today),$now);
            $result=bts_with_sources(false,$u,static function($values,$state,$now)use($masters,$books,$scope,$date,$today){
                $inventory=bti_project($values,$masters,$scope,$date,$today,$state);$exports=json_decode($values['transtrade_export_v3_operational']??'{}',true,512,JSON_THROW_ON_ERROR);$deals=[];
                foreach((array)($books['exporterDeals']??[]) as $d)if(($d['date']??'')<=$date&&oeb_active($d,(array)$exports)){try{oeb_deal($books,$scope,(string)$d['id']);}catch(DomainException){continue;}$deals[]=array_intersect_key($d,array_flip(['id','party','partyId','brand','packing','reference','version','date','productionBagTareGrams']))+['bagResponsibility'=>ba_physical_responsibility($d)];}
                $ids=[];foreach($inventory['lots'] as $lot)if(($lot['sourceKind']??'')==='OTHER_EXPORTER')$ids[(string)$lot['originBagId']]=true;
                $history=[];foreach(bti_rows($values,'tt33bagreceipts') as $r)if(isset($ids[(string)($r['bagId']??'')])&&($r['date']??'')<=$date)$history[]=array_intersect_key($r,array_flip(['id','bagId','date','qty','brand','inspectionStatus','sourceSnapshot']))+br_position($r,(array)($state['receiptDispositions']??[]),$date);
                return ['deals'=>$deals,'inventory'=>$inventory,'receiptHistory'=>$history]+oeb_choices($values,$inventory,$books,$scope);
            });
        }elseif(($b['action']??'')==='fill_brand'){
            $d=oeb_deal($books,$scope,(string)($b['sodaId']??''));if(!oeb_active($d,tt_accounts_exports_root()))throw new DomainException('This SODA or linked contract is closed.');
            $brand=trim((string)($b['brand']??''));if($brand===''||mb_strlen($brand)>200||preg_match('/[\x00-\x1f]/',$brand))throw new DomainException('Enter the actual bag brand, up to 200 characters.');
            $rid=(int)$u['id'].'|'.(string)($b['requestKey']??'');if(!preg_match('/^[A-Za-z0-9_-]{16,120}$/D',(string)($b['requestKey']??'')))throw new DomainException('A stable save identity is required.');
            $fingerprint=bt_hash(array_diff_key($b,['csrf'=>true]));$prior=$books['otherExporterBagBrands'][$rid]??null;
            if($prior){if(!hash_equals($prior['fingerprint'],$fingerprint))throw new DomainException('This brand save identity belongs to different facts.');$result=['deal'=>array_intersect_key($d,array_flip(['id','brand','version'])),'replayed'=>true];}
            else{
                if((int)($b['version']??-1)!==(int)$d['version'])throw new DomainException('The SODA changed. Refresh it before saving the brand.');
                if(trim((string)($d['brand']??''))!=='')throw new DomainException('The saved SODA already has a brand. Its original marking is preserved.');
                // A blank brand cannot be filled after physical receipt/dispatch has established another identity.
                $result=bts_with_sources(true,$u,static function($values)use(&$books,&$d,$u,$brand,$rid,$fingerprint,$now,$h){foreach(bti_rows($values,'tt30bags') as $r)if(($r['exporterDealId']??'')===$d['id']&&!empty($r['received']))throw new DomainException('Received bags already establish the original brand. Reconcile their source first.');
                require_once __DIR__.'/directors_trading_links.php';foreach(dt_read_native_sales() as $sale)if(($sale['exporterDealId']??'')===$d['id']&&!empty($sale['containers']))throw new DomainException('Dispatched goods retain their original brand.');
                $before=array_diff_key($d,['history'=>true]);$d['brand']=$brand;$d['version']++;$d['history'][]=['action'=>'mill_bag_brand','before'=>$before,'by'=>$u['full_name']??$u['username'],'at'=>$now];$books['exporterDeals'][$d['id']]=$d;
                $books['otherExporterBagBrands'][$rid]=['fingerprint'=>$fingerprint,'sodaId'=>$d['id'],'brand'=>$brand,'at'=>$now]+bt_actor($u);$books['revision']=(int)($books['revision']??0)+1;
                $encoded=json_encode($books,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);rewind($h);if(!ftruncate($h,0)||fwrite($h,$encoded)!==strlen($encoded)||!fflush($h))throw new RuntimeException('Brand save could not be confirmed.');return ['deal'=>array_intersect_key($d,array_flip(['id','brand','version'])),'replayed'=>false];
                });
            }
        }elseif(($b['action']??'')==='receive'){
            $result=bts_with_sources(true,$u,static function(array &$values,array &$state,$now)use($masters,$books,$scope,$b,$u,$authorize){$saved=oeb_receive($values,$state,$masters,$books,$scope,$b,$u,$now,$authorize);$saved['visibleSources']=os_project($u,$values)['values'];return $saved;});
        }else throw new DomainException('Select a supported bag action.');
    }finally{flock($h,LOCK_UN);fclose($h);}
    if(isset($result['sourceValues']))foreach($result['sourceValues'] as $key=>&$row){if(isset($result['visibleSources'][$key]))$row['value']=$result['visibleSources'][$key];else unset($result['sourceValues'][$key]);}unset($row,$result['visibleSources']);
    oeb_out(['ok'=>true,'scope'=>$scope]+$result);
}catch(OebDenied $e){oeb_out(['ok'=>false,'error'=>$e->getMessage()],403);}
catch(DomainException|InvalidArgumentException $e){oeb_out(['ok'=>false,'error'=>$e->getMessage()],422);}
catch(Throwable $e){error_log('Other exporter bags: '.$e->getMessage());oeb_out(['ok'=>false,'error'=>'Bag action could not be confirmed. Your entry is retained; retry the same action.'],500);}
