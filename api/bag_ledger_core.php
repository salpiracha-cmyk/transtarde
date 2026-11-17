<?php
declare(strict_types=1);
require_once __DIR__.'/bag_inventory_core.php';

function blg_report(array $values,array $masters,array $scope,string $date,string $today,array $state):array {
    $inventory=bti_project($values,$masters,$scope,$date,$today,$state,null,true);
    $rows=[];$warnings=$inventory['warnings'];$runs=[];
    foreach (bti_rows($values,'tt30prod') as $run) {
        if (is_array($run)&&($run['entity']??'')===$scope['entity']
            &&(bti_run_location($masters,$run)['locationId']??null)===$scope['locationId']) {
            $runs[(string)($run['id']??'')]=$run;
        }
    }
    foreach ($inventory['movements'] as $movement) {
        if ($movement['date']>$date||(!$movement['qty']&&empty($movement['physicalQty']))) continue;
        $lot=$inventory['lots'][$movement['lotId']];
        $type=$movement['type'];
        $kind=match($type) {
            'PRODUCTION_CONSUMPTION'=>'packing',
            'SHIPMENT_PACKED_BAGS'=>'directPacked',
            'SHIPMENT_EMPTY_BAGS'=>'emptyDispatch',
            default=>'stock'
        };
        $reference=(string)$movement['reference'];
        if (!empty($movement['shipment'])) $reference=$movement['shipment'].' · '.$reference;
        $runId=substr($reference,strrpos($reference,'|')===false?0:strrpos($reference,'|')+1);
        $rows[]=['date'=>$movement['date'],'brand'=>$lot['brand'],'size'=>$lot['packingKg'],'qty'=>$movement['qty'],
            'physicalQty'=>$kind==='packing'?0:($movement['physicalQty']??$movement['qty']),
            'kind'=>$kind,'type'=>$type,'reference'=>$reference,'shift'=>$kind==='packing'?($runs[$runId]['shift']??''):'',
            'hold'=>$lot['hold']!==''];
    }

    // External shipment usage is recorded against transfer lots. Exclude its
    // shipment IDs from the loading projection so there is one physical out.
    $external=[];
    foreach ($state['transfers']??[] as $transfer) {
        if (($transfer['entity']??'')!==$scope['entity']||($transfer['destination']['id']??'')!==$scope['locationId']) continue;
        foreach ($transfer['shipmentBagUsage']??[] as $usage) $external[(string)$usage['shipmentId']]=true;
    }
    $seen=[];
    foreach (['tt30ship'=>'containers','tt35exportersale'=>'containers','tt35localsales'=>'loads'] as $key=>$collection) {
        foreach (bti_rows($values,$key) as $parent) {
            if (!is_array($parent)||!empty($parent['cancelled'])||!empty($parent['void'])
                ||($parent['accountEntity']??$parent['entity']??'')!==$scope['entity']) continue;
            $location=bti_location($masters,(string)($parent['millName']??$parent['mill']??$parent['stockLocation']??''),(string)($parent['locationId']??''));
            if (($location['locationId']??null)!==$scope['locationId']) continue;
            $shipmentId=(string)($parent['_ttShipmentId']??$parent['shipmentId']??'');
            if ($shipmentId!==''&&isset($external[$shipmentId])) continue;
            $brand=trim((string)($parent['brand']??''));
            if ($key==='tt35localsales'&&$brand===''&&preg_match('/^(?:Ready Rice|B2)\s*—\s*(.+)$/u',(string)($parent['product']??''),$m)) $brand=trim($m[1]);
            if (in_array(strtoupper($brand),['LOCAL','LOCAL BAGS'],true)) continue;
            foreach ($parent[$collection]??[] as $load) {
                if (!is_array($load)||!empty($load['cancelled'])||!empty($load['void'])||($load['loadingComplete']??null)===false) continue;
                if (!empty($load['entity'])&&$load['entity']!==$scope['entity']) continue;
                $gate=(string)($load['gatePassRef']??$load['gate']??'');
                $day=bti_date($load['date']??'');
                if ($day>$date||($gate===''&&$key==='tt30ship')) continue;
                $loadId=(string)($load['bridgeId']??$load['id']??'');
                $identity=$loadId===''?'':$key.'|'.($parent['id']??$parent['ref']??$parent['soda']??'').'|'.$loadId;
                if ($identity!==''&&isset($seen[$identity])) continue;
                if ($identity!=='') $seen[$identity]=true;
                $loadBrand=trim((string)($load['brand']??''))?:$brand;
                if ($loadBrand===''||in_array(strtoupper($loadBrand),['LOCAL','LOCAL BAGS'],true)) continue;
                $size=$load['packingKg']??$load['bagSize']??$parent['bagSize']??null;
                $size=is_numeric($size)&&(float)$size>0?(float)$size:bti_packing_kg((string)$size);
                if ($size===null) {
                    $sizes=[];
                    foreach ($inventory['lots'] as $lot) {
                        if (bti_norm($lot['brand'])===bti_norm($loadBrand)&&$lot['packingKg']!==null) $sizes[(string)$lot['packingKg']]=$lot['packingKg'];
                    }
                    // A brand with multiple pack sizes needs an explicit loading size.
                    if (count($sizes)===1) $size=reset($sizes);
                }
                $ref=implode(' · ',array_filter([$gate,$load['container']??'',$load['truck']??'',$parent['ref']??$parent['soda']??'']));
                foreach (['bags'=>'packedDispatch','empty'=>'emptyDispatch'] as $field=>$kind) {
                    $quantity=bti_count($load[$field]??0);
                    if ($quantity===null) $warnings[]='A loading record has an invalid bag count. Review '.$ref.'.';
                    if (!$quantity) continue;
                    $rows[]=['date'=>$day,'brand'=>$loadBrand,'size'=>$size,'qty'=>-$quantity,'kind'=>$kind,
                        'type'=>$kind==='packedDispatch'?'PACKED_DISPATCH':'EMPTY_DISPATCH','reference'=>$ref,'shift'=>'','hold'=>$size===null||$day===''];
                }
            }
        }
    }
    $unverifiedBrands=array_values(array_unique(array_map(static fn($row)=>bti_norm($row['brand']),$inventory['unallocatedConsumption'])));
    if ($inventory['unallocatedConsumption']) $warnings[]='Some historical packing has no verified bag allocation. Balances for affected bags need review.';
    $held=array_filter($inventory['lots'],static fn($lot)=>$lot['hold']!==''||!empty($lot['quarantinedQty']));
    if ($held) $warnings[]='Some bag lots are on hold or quarantined. Review their receipt records before using the balance.';
    usort($rows,static fn($a,$b)=>strcmp($a['date'],$b['date']));
    return ['rows'=>$rows,'unverifiedPackingBrands'=>$unverifiedBrands,'warnings'=>array_values(array_unique($warnings))];
}
