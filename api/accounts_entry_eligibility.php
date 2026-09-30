<?php
declare(strict_types=1);

// Selection rules only: completed records remain in registers, search and ledgers.
function tt_accounts_entry_store():array{
    $file=TT_DATA_DIR.'/accounts.json';if(!is_file($file))return [];
    $h=fopen($file,'r');if(!$h||!flock($h,LOCK_SH))throw new RuntimeException('Accounts selection unavailable.');
    try{$raw=stream_get_contents($h);}finally{flock($h,LOCK_UN);fclose($h);}
    return (array)(json_decode($raw?:'{}',true)?:[]);
}
function tt_accounts_contract_freight(array $root,array $store,string $entity,string $ignore=''):array{
    $customers=[];foreach((array)($root['customers']??[]) as $c)$customers[(string)($c['id']??'')]=(string)($c['name']??'');
    $sources=tt_accounts_shipment_rows($root,$entity);$out=[];
    foreach((array)($root['contracts']??[]) as $c){
        $ref=(string)($c['ref']??'');$seller=strtoupper((string)($c['seller']??''));$assigned=strtoupper((string)($c['customsExporter']??''));
        if(!$ref||$seller!==$entity&&($seller!=='TG'||$assigned!==''&&$assigned!==$entity)||in_array(strtolower((string)($c['status']??'')),['cancelled','deleted'],true))continue;
        $lots=array_values(array_filter($sources,static fn($r)=>$r['contract']===$ref));
        $total=0;foreach((array)($c['packings']??[]) as $p)$total+=(int)($p['containers']??0);
        // Historical contracts without packing counts use their actual loaded containers.
        if($total<=0)foreach($lots as $lot)$total+=count($lot['containers']);
        $agreed=0;foreach((array)($store['freightAgreements']??[]) as $id=>$a){
            if(!is_array($a)||(string)$id===$ignore||in_array(strtolower((string)($a['status']??'')),['cancelled','deleted'],true))continue;
            // A TG agreement is shared planning; Pakistan bills remain in their own books.
            if($seller!=='TG'&&($a['entity']??'')!==$entity)continue;
            $aRef=(string)($a['contractRef']??'');if($aRef==='')foreach($lots as $lot)if($lot['id']===($a['shipmentId']??''))$aRef=$ref;
            if($aRef===$ref)$agreed+=(int)($a['containerCount']??0);
        }
        $sample=$lots[0]??[];$out[]=['id'=>'','kind'=>'contract','contract'=>$ref,'seller'=>$seller,'customer'=>$customers[(string)($c['customerId']??'')]??(string)($c['customer']??''),'containers'=>[],'plannedContainers'=>$total,'agreedContainers'=>$agreed,'remainingContainers'=>max(0,$total-$agreed),'portOfLoading'=>(string)($c['pol']??$sample['portOfLoading']??''),'portOfDischarge'=>(string)($c['podPort']??$sample['portOfDischarge']??''),'loadingProgramme'=>(string)($sample['loadingProgramme']??''),'shippingLine'=>(string)($sample['shippingLine']??''),'forwarder'=>(string)($sample['forwarder']??'')];
    }
    return $out;
}
function tt_accounts_source_billed(array $store,string $entity,string $kind,string $shipmentId):bool{
    foreach(['transportBillsV1','freightBillsV1','exportServiceBillsV1','supplierBills'] as $collection)foreach((array)($store[$collection]??[]) as $b){
        if(!is_array($b)||($b['entity']??'')!==$entity||in_array(strtolower((string)($b['status']??'')),['cancelled','reversed','deleted'],true))continue;
        $category=$collection==='freightBillsV1'?'FREIGHT':($collection==='transportBillsV1'?'TRANSPORT':strtoupper((string)($b['kind']??$b['category']??'')));
        if($category!==$kind)continue;
        $record=(array)($b['sourceRecord']??$b);$ids=(array)($record['shipmentIds']??[]);$ids[]=(string)($record['shipmentId']??'');
        foreach((array)($record['shipmentSections']??$record['lines']??[]) as $line)$ids[]=(string)($line['shipmentId']??'');
        if(in_array($shipmentId,$ids,true))return true;
    }
    return false;
}
function tt_accounts_freight_visible(array $store,array $root,string $entity):array{
    $tg=[];foreach((array)($root['contracts']??[]) as $c)if(($c['seller']??'')==='TG'&&(($c['customsExporter']??'')===''||($c['customsExporter']??'')===$entity))$tg[(string)($c['ref']??'')]=true;
    $out=[];foreach((array)($store['freightAgreements']??[]) as $a){if(!is_array($a))continue;if(($a['entity']??$entity)===$entity||isset($tg[(string)($a['contractRef']??'')]))$out[]=$a;}return $out;
}
