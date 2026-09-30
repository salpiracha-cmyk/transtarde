<?php
declare(strict_types=1);
function tt_accounts_exports_root():array{
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
function tt_accounts_shipment_rows(array $root,string $entity):array{
    $contracts=[];$customers=[];$rows=[];
    foreach((array)($root['customers']??[]) as $customer)if(is_array($customer))$customers[(string)($customer['id']??'')]=(string)($customer['name']??'');
    foreach((array)($root['contracts']??[]) as $contract)if(is_array($contract))$contracts[(string)($contract['ref']??'')]=$contract;
    foreach((array)($root['shipments']??[]) as $shipment){
        if(!is_array($shipment)||($shipment['kind']??'')!=='lot'||!empty($shipment['cancelled']))continue;
        $ref=(string)($shipment['contractRef']??'');$contract=$contracts[$ref]??[];
        $seller=strtoupper((string)($shipment['seller']??$contract['seller']??''));
        $exporter=$seller==='TG'?strtoupper(trim((string)($shipment['customs']['exporter']??$contract['customsExporter']??''))):$seller;
        if($seller!=='TG'&&$exporter!==$entity||$seller==='TG'&&$exporter!==''&&$exporter!==$entity)continue;
        $process=[];foreach((array)($root['shipments']??[]) as $candidate)if(is_array($candidate)&&($candidate['kind']??'')!=='lot'&&($candidate['contractRef']??'')===$ref){$process=$candidate;break;}
        $loading=(array)($shipment['loadingPlan']??$shipment['loading']??$process['loading']['draft']??[]);
        $numbers=[];foreach((array)($shipment['millActuals']??[]) as $actual)if(is_array($actual))$numbers[]=(string)($actual['number']??$actual['containerNo']??'');
        $po=array_values(array_filter(array_map(static fn($row)=>is_array($row)?(string)($row['poNo']??''):'',(array)($process['bagOrders']??[]))));
        $programme='';foreach([$shipment['loadingProgrammeNo']??null,$loading['loadingProgrammeNo']??null,$shipment['bl']['bookingNumber']??null,$loading['bookingNumber']??null,$loading['bookingNo']??null] as $candidate){$programme=trim((string)($candidate??''));if($programme!=='')break;}
        $row=['id'=>(string)($shipment['id']??''),'kind'=>'lot','seller'=>$seller,'pakistanExporter'=>$exporter,'lot'=>(string)($shipment['lotId']??''),'contract'=>$ref,'customer'=>(string)($customers[(string)($contract['customerId']??'')]??$contract['customer']??$shipment['buyer']??''),'commercialInvoice'=>(string)($shipment['commercial']['invoiceNo']??''),'customsInvoice'=>(string)($shipment['customs']['invoiceNo']??''),'bl'=>(string)($shipment['bl']['blNo']??''),'containers'=>array_values(array_unique(array_filter(array_map('trim',$numbers)))),'loadingProgramme'=>$programme,'shippingLine'=>(string)($loading['shippingLine']??''),'vessel'=>(string)($shipment['bl']['vessel']??$loading['intendedVessel']??''),'voyage'=>(string)($shipment['bl']['voyage']??$loading['voyage']??''),'portOfLoading'=>(string)($loading['portOfLoading']??$contract['pol']??''),'portOfDischarge'=>(string)($contract['podPort']??''),'brand'=>implode(', ',array_values(array_filter(array_map(static fn($pack)=>is_array($pack)?(string)($pack['brand']??''):'',(array)($contract['packings']??[]))))),'po'=>implode(', ',$po),'gd'=>implode(', ',array_map(static fn($gd)=>is_array($gd)?(string)($gd['number']??''):(string)$gd,(array)($shipment['customs']['gdRefs']??[]))),'fi'=>implode(', ',array_map(static fn($fi)=>is_array($fi)?(string)($fi['number']??''):(string)$fi,(array)($shipment['customs']['fiAllocations']??[])))];
        $rows[]=$row;
    }
    return $rows;
}
