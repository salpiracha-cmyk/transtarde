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
$freightScope=(string)($_GET['scope']??'')==='freight';
require_once __DIR__.'/accounts_shipment_source.php';
try{
    $root=tt_accounts_exports_root();$contracts=[];$customers=[];
    foreach((array)($root['customers']??[]) as $customer)if(is_array($customer))$customers[(string)($customer['id']??'')]=(string)($customer['name']??'');
    foreach((array)($root['contracts']??[]) as $contract)if(is_array($contract))$contracts[(string)($contract['ref']??'')]=$contract;
    $rows=[];
    if($freightScope)foreach($contracts as $ref=>$contract){
        $seller=strtoupper((string)($contract['seller']??''));
        $assigned=strtoupper(trim((string)($contract['customsExporter']??'')));
        if($seller!==$entity&&($seller!=='TG'||($assigned!==''&&$assigned!==$entity)))continue;
        $customer=(string)($customers[(string)($contract['customerId']??'')]??$contract['customer']??'');
        $port=(string)($contract['podPort']??'');
        if(!str_contains(strtolower(implode(' ',[$ref,$customer,$port])),strtolower($term)))continue;
        $count=0;foreach((array)($contract['packings']??[]) as $packing)if(is_array($packing))$count+=(int)($packing['containers']??0);
        $rows[]=['id'=>'','kind'=>'contract','seller'=>$seller,'lot'=>'Contract planning','contract'=>$ref,'customer'=>$customer,'containers'=>[],'plannedContainers'=>$count,'portOfLoading'=>(string)($contract['pol']??''),'portOfDischarge'=>$port,'loadingProgramme'=>'','shippingLine'=>''];
    }
    foreach(tt_accounts_shipment_rows($root,$entity) as $row){
        $haystack=strtolower(implode(' ',array_map(static fn($item)=>is_array($item)?implode(' ',$item):(string)$item,$row)));
        if(str_contains($haystack,strtolower($term)))$rows[]=$row;
        if(count($rows)>=50)break;
    }
    asl_out(['ok'=>true,'rows'=>$rows]);
}catch(Throwable $error){error_log('Accounts shipment search: '.$error->getMessage());asl_out(['ok'=>false,'error'=>'Shipment search is temporarily unavailable.'],503);}

