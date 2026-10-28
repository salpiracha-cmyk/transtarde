<?php
declare(strict_types=1);
require_once __DIR__.'/business_reports_core.php';
require_once __DIR__.'/production_costing_core.php';

/** Internal references only. Supplier documents never consume this collection. */
function sb_targets(array $ops,array $books,string $entity):array {
    $root=br_root($ops);$out=[];
    foreach(dt_contracts($root,dt_lots($root,$entity),$entity) as $contract){
        $ref=(string)$contract['ref'];$source=null;foreach($root['contracts'] as $c)if(($c['ref']??'')===$ref)$source=$c;
        if(!$source||!empty($source['cancelled'])||($source['status']??'')==='Cancelled')continue;
        $out['EXPORT|'.$ref]=['id'=>'EXPORT|'.$ref,'kind'=>'EXPORT','reference'=>$ref,'customer'=>is_scalar($source['customer']??$source['buyer']??'')?(string)($source['customer']??$source['buyer']??''):'','product'=>(string)($source['product']??''),'commodity'=>(string)($source['commodity']??'RICE'),'quantityKg'=>((float)($contract['quantityMT']??0)>0?(float)$contract['quantityMT']:(float)($source['containers']??0)*(float)($source['weightPer']??0))*1000];
    }
    foreach((array)($books['exporterDeals']??[]) as $id=>$d){
        if(($d['entity']??'')!==$entity||($d['direction']??'')!=='SALE'||($d['status']??'')==='Cancelled')continue;
        $id=(string)($d['id']??$id);$out['EXPORTER|'.$id]=['id'=>'EXPORTER|'.$id,'kind'=>'EXPORTER','reference'=>(string)(($d['reference']??'')?:$id),'customer'=>(string)($d['party']??''),'product'=>(string)($d['product']??''),'commodity'=>(string)($d['commodity']??'RICE'),'quantityKg'=>(float)($d['quantityMT']??0)*1000];
    }
    uasort($out,static fn($a,$b)=>strcmp($a['reference'],$b['reference']));return array_values($out);
}
function sb_validate(mixed $input,array $product,float $capacity,array $targets,array $books=[],string $entity='',string $excludeId=''):array {
    if(!is_array($input)||!array_is_list($input))throw new InvalidArgumentException('Buying for must be a list of contract allocations.');
    if(($product['productStage']??'')==='RAW'&&strtoupper((string)($product['commodity']??''))==='RICE'){
        if($input)throw new InvalidArgumentException('Raw rice uses automatic FIFO; do not select a buying contract.');return [];
    }
    $out=[];$sum=0.;$targetMap=array_column($targets,null,'id');
    foreach($input as $a){if(!is_array($a))throw new InvalidArgumentException('Invalid buying allocation.');$id=trim((string)($a['targetId']??''));$kg=$a['quantityKg']??null;
        if(!isset($targetMap[$id]))throw new InvalidArgumentException('Select an available sales contract in these company books.');
        if(!is_numeric($kg)||!is_finite((float)$kg)||(float)$kg<=0)throw new InvalidArgumentException('Enter a positive quantity for each buying allocation.');
        if(isset($out[$id]))throw new InvalidArgumentException('Combine duplicate contract allocations.');
        if(strtoupper($targetMap[$id]['commodity'])!==strtoupper((string)$product['commodity']))throw new InvalidArgumentException('Buying contract commodity must match the purchase.');
        $purchaseVariety=pc_variety_key((string)($product['baseVariety']??$product['variety']??''));$saleVariety=pc_variety_key((string)$targetMap[$id]['product']);
        foreach(['IRRI-6','1121','PK-386','SUPER KERNEL','D-98','C-9'] as $known)if(str_starts_with($saleVariety,$known)&&!str_starts_with($purchaseVariety,$known))throw new InvalidArgumentException('Buying contract variety must match the purchase.');
        $kg=round((float)$kg,3);if($kg<=0)throw new InvalidArgumentException('Allocation quantity is too small.');$reserved=0.;foreach((array)($books['purchaseSodas']??[]) as $sid=>$soda){
            if((string)$sid===$excludeId||($soda['entity']??'')!==$entity||($soda['status']??'')==='Cancelled')continue;
            foreach((array)($soda['buyingForAllocations']??[]) as $allocation)if(($allocation['targetId']??'')===$id)$reserved+=(float)($allocation['quantityKg']??0);
        }
        if($reserved+$kg>(float)$targetMap[$id]['quantityKg']+.001)throw new InvalidArgumentException('Buying allocations exceed the sales contract quantity, including other SODAs.');
        $sum+=$kg;$out[$id]=['targetId'=>$id,'quantityKg'=>$kg];
    }
    if($sum>$capacity+.001)throw new InvalidArgumentException('Buying allocations exceed this SODA quantity.');return array_values($out);
}

/** Cost only accepted physical receipts. A commitment alone never creates inventory or profit. */
function sb_ready_pool(array $books,string $entity,string $target,string $date):array {
    $pool=['kg'=>0.,'value'=>0.,'provisional'=>false,'sources'=>[]];$receipts=pc_receipts($books);
    foreach((array)($books['purchaseSodas']??[]) as $id=>$soda){
        if(($soda['entity']??'')!==$entity||($soda['productStage']??'')!=='READY'||($soda['status']??'')==='Cancelled')continue;
        $reserved=0.;$priorKg=0.;foreach((array)($soda['buyingForAllocations']??[]) as $a){if(($a['targetId']??'')===$target){$reserved=(float)($a['quantityKg']??0);break;}$priorKg+=(float)($a['quantityKg']??0);}
        if($reserved<=0)continue;$kg=0.;$value=0.;$provisional=false;$sources=[];
        foreach($receipts as $r){if($r['entity']!==$entity||$r['date']>$date)continue;
            if($r['sourceSodaId']!==(string)($soda['id']??$id)&&!($r['sourceSodaId']===''&&$r['soda']!==''&&$r['soda']===(string)($soda['sodaNo']??'')))continue;
            $kg+=$r['kg'];$value+=$r['value'];$provisional=$provisional||!$r['final'];$sources[]=$r['sourceKey'];
        }
        if($kg<=0)continue;$share=min($reserved,max(0.,$kg-$priorKg))/$kg;
        $pool['kg']+=$kg*$share;$pool['value']+=$value*$share;$pool['provisional']=$pool['provisional']||$provisional;
        $pool['sources'][]=(string)($soda['sodaNo']??$id).' ['.implode(', ',$sources).']';
    }
    return $pool;
}
