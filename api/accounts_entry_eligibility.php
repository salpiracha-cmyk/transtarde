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
    $sources=tt_accounts_shipment_rows($root,$entity,$store);$out=[];
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
/** Original supplier bills are deduplicated against their workflow copy. */
function tt_accounts_source_bills(array $store,string $entity,string $kind,string $shipmentId,string $ignore=''):array{
    $out=[];
    foreach(['freightBillsV1','transportBillsV1','exportServiceBillsV1','supplierBills'] as $collection)foreach((array)($store[$collection]??[]) as $key=>$bill){
        if(!is_array($bill)||($bill['entity']??'')!==$entity)continue;
        $id=(string)($bill['id']??$key);$canonical=(array)($store['supplierBills'][$id]??$bill);
        if($id===$ignore||in_array(strtolower((string)($bill['status']??'')),['cancelled','reversed','deleted'],true)||in_array(strtolower((string)($canonical['status']??'')),['cancelled','reversed','deleted'],true))continue;
        $category=match($collection){'freightBillsV1'=>'FREIGHT','transportBillsV1'=>'TRANSPORT',default=>strtoupper((string)($bill['kind']??$bill['category']??''))};
        if($category!==$kind||isset($out[$id]))continue;
        $record=(array)($bill['sourceRecord']??$bill);$ids=(array)($record['shipmentIds']??[]);$ids[]=(string)($record['shipmentId']??'');
        $count=0;$known=false;foreach((array)($record['shipmentSections']??$record['lines']??[]) as $line)if(($line['shipmentId']??'')===$shipmentId){$ids[]=$shipmentId;if(isset($line['containerCount'])||isset($line['containers'])){$quantity=$line['containerCount']??$line['containers'];$count+=is_array($quantity)?count($quantity):(int)$quantity;$known=true;}}
        if(!in_array($shipmentId,$ids,true))continue;
        if(!$known&&($record['shipmentId']??'')===$shipmentId&&count(array_unique(array_filter($ids)))===1&&isset($record['containerCount'])){$count=(int)$record['containerCount'];$known=true;}
        $out[$id]=['id'=>$id,'vendor'=>$bill['vendor']??$canonical['vendor']??'','invoiceNo'=>$bill['invoiceNo']??$canonical['billNo']??'','containerCount'=>$known?$count:null,'postId'=>array_values((array)($canonical['postingJournalIds']??$bill['postingJournalIds']??[]))[0]??''];
    }
    return array_values($out);
}
function tt_accounts_shipment_billing(array $store,string $entity,string $kind,array $shipment,string $ignore=''):array{
    $bills=tt_accounts_source_bills($store,$entity,$kind,(string)$shipment['id'],$ignore);$total=(int)($shipment['containerCount']??count($shipment['containers']??[]));
    $used=0;$unknown=false;foreach($bills as $bill){if($bill['containerCount']===null)$unknown=true;else $used+=$bill['containerCount'];}
    $full=(bool)$bills&&($kind!=='FREIGHT'||$unknown||$total<=0||$used>=$total);
    return ['fullyBilled'=>$full,'totalContainers'=>$total,'billedContainers'=>$unknown?null:$used,'remainingContainers'=>$full?0:max(0,$total-$used),'bills'=>$bills];
}
function tt_accounts_source_billed(array $store,string $entity,string $kind,string $shipmentId):bool{
    return (bool)tt_accounts_source_bills($store,$entity,$kind,$shipmentId);
}

function tt_accounts_freight_visible(array $store,array $root,string $entity):array{
    $tg=[];foreach((array)($root['contracts']??[]) as $c)if(($c['seller']??'')==='TG'&&(($c['customsExporter']??'')===''||($c['customsExporter']??'')===$entity))$tg[(string)($c['ref']??'')]=true;
    $out=[];foreach((array)($store['freightAgreements']??[]) as $a){if(!is_array($a))continue;if(($a['entity']??$entity)===$entity||isset($tg[(string)($a['contractRef']??'')]))$out[]=$a;}return $out;
}
