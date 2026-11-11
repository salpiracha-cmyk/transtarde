<?php
declare(strict_types=1);

require_once __DIR__.'/bag_inventory_core.php';

/** Match a completed single-source shipment to the exact original bag PO line. */
function bsu_candidates(array $values, array $transfer): array {
    $raw=$values['transtrade_export_v3_operational']??'{}';
    $exports=is_string($raw)?json_decode($raw,true,512,JSON_THROW_ON_ERROR):$raw;
    $matches=[];
    $totals=bt_totals($transfer);
    foreach($transfer['lines'] as $line){
        $source=$line['sourceSnapshot'];
        $contract=(string)($source['contractRef']??'');
        if($contract===''){
            foreach(bti_rows($values,'tt30bags') as $order){
                if((string)($order['id']??'')===(string)$line['originBagId'])$contract=(string)($order['contractRef']??'');
            }
        }
        if($contract===''||empty($source['poNo'])||empty($source['brand'])||($source['sourceKind']??'')==='OTHER_EXPORTER'||empty($totals[$line['lineId']]['received']))continue;
        $destination=bti_norm($transfer['destination']['name']);
        $brand=bti_norm($source['brand']);
        $contracts=array_values(array_filter($exports['contracts']??[],static fn($c)=>($c['ref']??'')===$contract));
        if(count($contracts)!==1)continue;
        foreach($exports['shipments']??[] as $lot){
            if(($lot['kind']??'')!=='lot'||empty($lot['completed'])||!empty($lot['cancelled'])||($lot['contractRef']??'')!==$contract||empty($lot['id'])||empty($lot['lotId']))continue;
            $allocations=array_values($lot['loadingPlan']['allocations']??[]);
            if(count($allocations)!==1||bti_norm($allocations[0]['name']??'')!==$destination)continue;
            $packing=$contracts[0]['packings'][$allocations[0]['packIndex']??0]??[];
            if(bti_norm($packing['brand']??'')!==$brand)continue;
            $kg=bti_packing_kg((string)($source['packingSize']??''));
            $unit=strtoupper((string)($contracts[0]['packingUnit']??'KG'));
            $packKg=(float)($packing['size']??0)*(str_starts_with($unit,'LB')?.45359237:1);
            if($kg===null||abs($kg-$packKg)>.00001)continue;
            $actuals=$lot['millActuals']??[];
            $expected=bti_count($lot['containers']??null);
            if(!$expected||count($actuals)!==$expected)continue;
            $packed=0;$loadedDate='';$seen=[];
            foreach($actuals as $actual){
                $number=strtoupper(preg_replace('/\s+/','',(string)($actual['number']??$actual['container']??$actual['containerNo']??'')));
                $date=bti_date($actual['loadedDate']??$actual['loadingDate']??$actual['date']??'');
                $count=bti_count($actual['bags']??null);
                if(!preg_match('/^[A-Z]{4}[0-9]{7}$/D',$number)||isset($seen[$number])||!$count||$date===''||bti_norm($actual['location']??'')!==$destination||bti_norm($actual['brand']??'')!==$brand)continue 2;
                $seen[$number]=true;$packed+=$count;$loadedDate=max($loadedDate,$date);
            }
            $receiptDate='';
            foreach(['receipts','quarantineAcceptances'] as $collection)foreach($transfer[$collection]??[] as $event){
                if(in_array($line['lineId'],array_column($event['lines'],'lineId'),true))$receiptDate=max($receiptDate,$event['date']);
            }
            $matches[]=[
                'shipmentId'=>(string)$lot['id'],'lotRef'=>(string)$lot['lotId'],'contractRef'=>$contract,
                'lineId'=>$line['lineId'],'poNo'=>$source['poNo'],'brand'=>$source['brand'],
                'packedQty'=>$packed,'plannedEmptyQty'=>bti_count($allocations[0]['emptyBags']??null),
                'loadedDate'=>$loadedDate,'date'=>max($loadedDate,$receiptDate),
            ];
        }
    }
    return $matches;
}

function bsu_record(array $values,array &$state,array $masters,array $body,array $user,string $now,callable $authorize): array {
    $action='SHIPMENT_BAG_USAGE';
    $request=bt_request($state,$body,$user,$action);
    $record=$state['transfers'][(string)($body['transferId']??'')]??null;
    if(!$record||($record['entity']??'')!==($body['entity']??''))throw new DomainException('Select a transfer for the current stock owner.');
    $location=$record['destination'];
    $authorize($record['entity'],$location['name'],'Create',$location['id']);
    if($replay=bt_replay($state,$request))return $replay;
    $matches=array_values(array_filter(bsu_candidates($values,$record),static fn($row)=>
        $row['shipmentId']===($body['shipmentId']??null)&&$row['lineId']===($body['lineId']??null)
    ));
    if(count($matches)!==1)throw new DomainException('The completed shipment and accepted source line could not be matched uniquely.');
    $usage=$matches[0];
    foreach($state['transfers'] as $transfer)foreach($transfer['shipmentBagUsage']??[] as $prior){
        if($prior['shipmentId']===$usage['shipmentId'])throw new DomainException('Bag usage is already recorded for this shipment.');
    }
    if(($body['fingerprint']??'')!==bt_hash($usage))throw new DomainException('Shipment or receipt details changed. Refresh the transfer and review the quantities.');
    $empty=bti_count($body['emptyQty']??null);
    if($empty===null)throw new DomainException('Enter the actual number of empty bags loaded, including zero if none.');
    bt_date($usage['date'],$now);
    $today=(new DateTimeImmutable($now))->setTimezone(new DateTimeZone('Asia/Karachi'))->format('Y-m-d');
    $scope=['entity'=>$record['entity'],'locationId'=>$location['id'],'millName'=>$location['name'],'status'=>'Active'];
    $inventory=bti_project($values,$masters,$scope,$usage['date'],$today,$state);
    $lotId='BT|'.$record['id'].'|'.$usage['lineId'];
    $lot=$inventory['lots'][$lotId]??[];
    foreach($inventory['movements'] as $movement){
        $source=$inventory['lots'][$movement['lotId']]??[];
        if($movement['type']==='PRODUCTION_CONSUMPTION'&&bti_norm($source['brand']??'')===bti_norm($usage['brand'])){
            throw new DomainException('Production has already recorded bags of this brand at the destination. Reconcile that usage before posting shipment bags.');
        }
    }
    foreach($inventory['unallocatedConsumption'] as $unallocated){
        if(bti_norm($unallocated['brand']??'')===bti_norm($usage['brand']))throw new DomainException('Resolve unallocated production bags of this brand before posting shipment usage.');
    }
    if(!$lot||!empty($lot['hold'])||$usage['packedQty']+$empty>($lot['committableQty']??0)){
        throw new DomainException('Accept enough bags against this exact transfer line before recording packed and empty bags.');
    }
    $usage['emptyQty']=$empty;
    $usage['id']='BSU-'.substr(hash('sha256',$request['id']),0,24);
    $record['shipmentBagUsage'][]=$usage+['at'=>$now]+$request['actor'];
    $state['transfers'][$record['id']]=$record;
    bt_commit_request($state,$request,$record,$action,$now);
    return ['record'=>$record,'status'=>bt_status($record),'replayed'=>false];
}
