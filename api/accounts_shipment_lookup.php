<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
function asl_out(array $body,int $status=200):never{http_response_code($status);echo json_encode($body,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
$user=tt_require_login();
if(!tt_user_can_open_module($user,'Accounts'))asl_out(['ok'=>false,'error'=>'Accounts permission required.'],403);
$entity=strtoupper(trim((string)($_GET['entity']??'')));
if(!in_array($entity,['TTI','BRM'],true)||!tt_user_can_access_entity($user,$entity,'View'))asl_out(['ok'=>false,'error'=>'Select accessible Pakistan company books.'],403);
$term=trim((string)($_GET['q']??''));
if(strlen($term)<2)asl_out(['ok'=>true,'rows'=>[]]);
function asl_root():array{
    $key='transtrade_export_v3_operational';
    $config=static fn($name)=>(string)(defined('TT_'.$name)?constant('TT_'.$name):(getenv('TT_'.$name)?:''));
    if($config('DB_HOST')!==''&&$config('DB_NAME')!==''&&$config('DB_USER')!==''){
        $db=new PDO('mysql:host='.$config('DB_HOST').';dbname='.$config('DB_NAME').';charset=utf8mb4',$config('DB_USER'),$config('DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $stmt=$db->prepare('SELECT payload FROM tt_operation_records WHERE storage_key=? LIMIT 1');$stmt->execute([$key]);$raw=$stmt->fetchColumn();
    }else{
        $file=TT_DATA_DIR.'/operations.json';$data=is_file($file)?json_decode((string)file_get_contents($file),true):[];$raw=$data['values'][$key]??'';
    }
    $value=is_string($raw)?json_decode($raw,true):null;return is_array($value)?$value:[];
}
try{
    $root=asl_root();$contracts=[];$customers=[];
    foreach((array)($root['customers']??[]) as $customer)if(is_array($customer))$customers[(string)($customer['id']??'')]=(string)($customer['name']??'');
    foreach((array)($root['contracts']??[]) as $contract)if(is_array($contract))$contracts[(string)($contract['ref']??'')]=$contract;
    $rows=[];
    foreach((array)($root['shipments']??[]) as $shipment){
        if(!is_array($shipment)||($shipment['kind']??'')!=='lot'||!empty($shipment['cancelled']))continue;
        $ref=(string)($shipment['contractRef']??'');$contract=$contracts[$ref]??[];
        $exporter=strtoupper((string)($shipment['seller']??$contract['seller']??''));
        if($exporter==='TG')$exporter=strtoupper((string)($shipment['customs']['exporter']??'TTI'));
        if($exporter!==$entity)continue;
        $process=[];foreach((array)($root['shipments']??[]) as $candidate)if(is_array($candidate)&&($candidate['kind']??'')!=='lot'&&($candidate['contractRef']??'')===$ref){$process=$candidate;break;}
        $loading=(array)($shipment['loading']??$process['loading']['draft']??[]);
        $numbers=[];foreach((array)($shipment['millActuals']??[]) as $actual)if(is_array($actual))$numbers[]=(string)($actual['number']??$actual['containerNo']??'');
        $po=array_values(array_filter(array_map(static fn($row)=>is_array($row)?(string)($row['poNo']??''):'',(array)($process['bagOrders']??[]))));
        $row=['id'=>(string)($shipment['id']??''),'lot'=>(string)($shipment['lotId']??''),'contract'=>$ref,'customer'=>(string)($customers[(string)($contract['customerId']??'')]??$contract['customer']??$shipment['buyer']??''),'commercialInvoice'=>(string)($shipment['commercial']['invoiceNo']??''),'customsInvoice'=>(string)($shipment['customs']['invoiceNo']??''),'bl'=>(string)($shipment['bl']['blNo']??''),'containers'=>array_values(array_filter($numbers)),'loadingProgramme'=>(string)($loading['loadingProgrammeNo']??''),'shippingLine'=>(string)($loading['shippingLine']??''),'vessel'=>(string)($shipment['bl']['vessel']??$loading['intendedVessel']??''),'voyage'=>(string)($shipment['bl']['voyage']??$loading['voyage']??''),'booking'=>(string)($loading['bookingNo']??''),'portOfLoading'=>(string)($loading['portOfLoading']??$contract['pol']??''),'portOfDischarge'=>(string)($contract['podPort']??''),'brand'=>implode(', ',array_values(array_filter(array_map(static fn($pack)=>is_array($pack)?(string)($pack['brand']??''):'',(array)($contract['packings']??[]))))),'po'=>implode(', ',$po),'gd'=>implode(', ',array_map(static fn($gd)=>is_array($gd)?(string)($gd['number']??''):(string)$gd,(array)($shipment['customs']['gdRefs']??[]))),'fi'=>implode(', ',array_map(static fn($fi)=>is_array($fi)?(string)($fi['number']??''):(string)$fi,(array)($shipment['customs']['fiAllocations']??[])))];
        $haystack=strtolower(implode(' ',array_map(static fn($item)=>is_array($item)?implode(' ',$item):(string)$item,$row)));
        if(str_contains($haystack,strtolower($term)))$rows[]=$row;
        if(count($rows)>=50)break;
    }
    asl_out(['ok'=>true,'rows'=>$rows]);
}catch(Throwable $error){error_log('Accounts shipment search: '.$error->getMessage());asl_out(['ok'=>false,'error'=>'Shipment search is temporarily unavailable.'],503);}
