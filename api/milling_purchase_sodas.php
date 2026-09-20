<?php
declare(strict_types=1);

require dirname(__DIR__) . '/auth_store.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function mps_out(array $payload,int $status=200): never {http_response_code($status);echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}

try {
    $user=tt_require_login();
    if(!tt_user_can_open_module($user,'Mill'))mps_out(['ok'=>false,'error'=>'Mill permission required.'],403);
    if($_SERVER['REQUEST_METHOD']!=='GET')mps_out(['ok'=>false,'error'=>'Method not allowed.'],405);
    $file=TT_DATA_DIR.'/accounts.json';$store=['revision'=>0,'purchaseSodas'=>[],'purchaseSodasV2'=>[]];
    if(is_file($file)){$handle=fopen($file,'r');if($handle===false||!flock($handle,LOCK_SH))throw new RuntimeException('store');try{$raw=stream_get_contents($handle);}finally{flock($handle,LOCK_UN);fclose($handle);}$decoded=$raw?json_decode($raw,true):null;if(is_array($decoded))$store=array_replace_recursive($store,$decoded);}
    $seen=[];$rows=[];
    foreach(['purchaseSodas','purchaseSodasV2']as$collection)foreach((array)($store[$collection]??[])as$id=>$row){if(!is_array($row))continue;$status=(string)($row['status']??$row['manualStatus']??'Open');if(in_array($status,['Cancelled','Completed','Short Closed','Manual Closed'],true))continue;$number=(string)($row['sodaNo']??'');$key=strtoupper((string)($row['entity']??'TTI')).'|'.$number;if($number===''||isset($seen[$key]))continue;$stage=tt_product_stage((string)($row['productStage']??''));if($stage===''){$identity=tt_product_identity((string)($row['commodity']??'RICE'),(string)($row['displayName']??$row['variety']??''));$stage=$identity['productStage'];$row=array_replace($identity,$row);}$route=strtoupper((string)($row['readyRoute']??''));if($stage==='READY'&&!in_array($route,['EX_MILL','DELIVER_TO_STOCK'],true))$route=(string)($row['movementRole']??'')==='LIFT_FROM'?'EX_MILL':'DELIVER_TO_STOCK';if($stage==='RAW')$route='DELIVER_TO_STOCK';$seen[$key]=true;$rows[]=['id'=>(string)($row['id']??$id),'entity'=>(string)($row['entity']??'TTI'),'sodaNo'=>$number,'sodaDate'=>(string)($row['sodaDate']??''),'purchaseProductId'=>(string)($row['purchaseProductId']??''),'commodity'=>(string)($row['commodity']??'RICE'),'baseVariety'=>(string)($row['baseVariety']??$row['variety']??''),'riceType'=>(string)($row['riceType']??''),'productStage'=>$stage,'displayName'=>(string)($row['displayName']??tt_product_display((string)($row['commodity']??'RICE'),(string)($row['baseVariety']??$row['variety']??''),$stage,(string)($row['riceType']??''))),'katProfile'=>(string)($row['katProfile']??''),'broker'=>(string)($row['broker']??''),'supplierId'=>(string)($row['supplierId']??''),'party'=>(string)($row['party']??''),'readyRoute'=>$route,'locationId'=>(string)($row['locationId']??$row['exMillId']??''),'locationName'=>(string)($row['locationName']??$row['location']??''),'locationType'=>(string)($row['locationType']??''),'locationAddress'=>(string)($row['locationAddress']??''),'qtyFromKg'=>(float)($row['qtyFromKg']??0),'qtyToKg'=>(float)($row['qtyToKg']??0),'expectedTrucks'=>(int)($row['expectedTrucks']??0),'ratePerKg'=>(float)($row['ratePerKg']??$row['rate']??0),'arrivalDueDate'=>(string)($row['arrivalDueDate']??''),'remarks'=>(string)($row['remarks']??''),'updatedAt'=>(string)($row['amendedAt']??$row['updatedAt']??$row['createdAt']??'')];}
    mps_out(['ok'=>true,'revision'=>(int)($store['revision']??0),'sodas'=>$rows]);
} catch(Throwable $error){error_log('milling_purchase_sodas: '.$error->getMessage());mps_out(['ok'=>false,'error'=>'Purchase Sodas are temporarily unavailable.'],500);}
