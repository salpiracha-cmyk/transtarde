<?php
declare(strict_types=1);
require_once __DIR__.'/production_costing_core.php';
require_once __DIR__.'/management_costing_core.php';

/** Reuse the costing engines; report reads never save rates or post journals. */
function mr_cost_sources(array $accounts,array $ops,string $entity):array {
    $management=[];foreach(mc_build($accounts,$ops,$entity) as $sheet)$management[$sheet['date'].'|'.$sheet['varietyKey']]=$sheet;
    $map=[];
    foreach(pc_calculate($accounts,$ops,$entity)['groups'] as $g){
        $saved=$g['approvedSnapshot']??null;$available=false;$basis='';$status=$g['status'];$raw=0.;$conversion=0.;$by=[];
        if($saved&&$g['status']==='Approved'&&($saved['calculation']['quantityBasis']??'')==='ACTUAL_INPUT'){
            $c=$saved['calculation'];$available=true;$basis='Approved Accounts cost';$status='Approved';$raw=(float)($c['rawCost']??0)/max(.001,(float)$c['inputKg']);$conversion=(float)($c['conversionCost']??0)/max(.001,(float)$c['inputKg']);$by=(array)($c['byProducts']??[]);
        }elseif(!$saved&&!empty($g['readyToApprove'])){
            $available=true;$basis='Calculated — awaiting Accounts approval';$raw=(float)$g['rawCost']/max(.001,(float)$g['inputKg']);$conversion=(float)$g['conversionCost']/max(.001,(float)$g['inputKg']);$by=$g['byProducts'];
        }elseif(!$saved){
            $m=$management[$g['date'].'|'.$g['varietyKey']]??null;
            if($m&&!empty($m['ready'])){$available=true;$basis='Management estimate — '.$m['rawRiceRateSource'].'; processing: '.$m['millingRateSource'].'; final Accounts cost pending';$status='Estimate';$raw=(float)$m['rawRiceRate'];$conversion=(float)$m['millingRatePerKg'];$by=array_map(static fn($b)=>['product'=>$b['product'],'costPerKg'=>$b['rate'],'value'=>$b['value'],'kg'=>$b['kg']],$m['byProducts']);}
        }
        $rates=[];foreach($by as $b){$rate=$b['costPerKg']??null;if($rate!==null&&($b['kg']??0)>0&&isset($b['value']))$rate=(float)$b['value']/(float)$b['kg'];$rates[(string)($b['product']??'')]=$rate;}
        foreach($g['runIds'] as $id)$map[(string)$id]=['available'=>$available,'basis'=>$basis,'status'=>$status,'groupId'=>$g['id'],'rawRate'=>$raw,'conversionRate'=>$conversion,'byProductRates'=>$rates,'rateId'=>$saved['rateId']??null];
    }
    return $map;
}
function mr_allocation_complete(array $run,array $dimensions):bool {
    foreach($dimensions as $dimension){$inputs=[];foreach(mr_input_rows($run) as $r){if($r[$dimension]==='')return false;$inputs[$r[$dimension]]=true;}foreach(mr_output_rows($run) as $r)if($r[$dimension]===''||!isset($inputs[$r[$dimension]]))return false;}
    return true;
}
function mr_attach_costs(array $report,array $runs,array $sources):array {
    $runMap=[];foreach($runs as $r)$runMap[(string)($r['id']??'')]=$r;
    $dimensions=[];if($report['mode']==='brand'||!empty($report['filters']['brand']))$dimensions[]='brand';if($report['mode']==='contract'||!empty($report['filters']['contract']))$dimensions[]='contractRef';
    $total=['available'=>true,'rawValue'=>0.,'conversionValue'=>0.,'byProductValue'=>0.,'readyRiceValue'=>0.,'basis'=>[]];
    foreach($report['groups'] as &$g){
        $cost=['available'=>$g['inputComplete']&&$g['readyKg']>0,'rawValue'=>0.,'conversionValue'=>0.,'byProductValue'=>0.,'readyRiceValue'=>null,'costPerKg'=>null,'rawAverageCostPerKg'=>null,'basis'=>[],'status'=>''];$issues=[];
        foreach($g['runs'] as $run){$s=$sources[$run['id']]??null;if(!$s||!$s['available']){$cost['available']=false;$issues[]=$s['status']??'Costing source is unavailable';}if(!mr_allocation_complete($runMap[$run['id']]??[],$dimensions)){$cost['available']=false;$issues[]='Actual brand / contract allocation required';}}
        if(!$g['inputComplete'])$issues[]='Actual raw input is missing';
        if($cost['available']){
            foreach($g['raw'] as &$row){$s=$sources[$row['sourceRunId']];$cost['rawValue']+=$row['kg']*$s['rawRate'];$cost['conversionValue']+=$row['kg']*$s['conversionRate'];$cost['basis'][$s['basis']]=true;$row['costPerKg']=$s['rawRate'];$row['costValue']=round($row['kg']*$s['rawRate'],2);}unset($row);
            foreach($g['byproducts'] as &$row){$s=$sources[$row['sourceRunId']];$rate=$s['byProductRates'][$row['product']]??null;if($rate===null){$cost['available']=false;$issues[]='By-product valuation is missing';continue;}$row['costPerKg']=$rate;$row['costValue']=round($row['kg']*$rate,2);$cost['byProductValue']+=$row['kg']*$rate;}unset($row);
            if($cost['available']){$cost['readyRiceValue']=round($cost['rawValue']+$cost['conversionValue']-$cost['byProductValue'],2);if($cost['readyRiceValue']<=0){$cost['available']=false;$issues[]='By-product value exceeds joint production cost';}else{$cost['costPerKg']=round($cost['readyRiceValue']/$g['readyKg'],6);$cost['rawAverageCostPerKg']=round($cost['rawValue']/$g['inputKg'],6);foreach($g['finished'] as &$row){$row['costPerKg']=$cost['costPerKg'];$row['costValue']=round($row['kg']*$cost['costPerKg'],2);}unset($row);}}
        }
        foreach(['rawValue','conversionValue','byProductValue'] as $field)$cost[$field]=$cost['available']?round($cost[$field],2):null;
        $cost['basis']=array_keys($cost['basis']);$cost['status']=$cost['available']?implode(' / ',$cost['basis']):implode('; ',array_unique($issues?:['Complete actual input and Ready Rice output are required']));if(!$cost['available']){$cost['readyRiceValue']=null;$cost['costPerKg']=null;foreach(['raw','finished','byproducts'] as $section)foreach($g[$section] as &$row){unset($row['costPerKg'],$row['costValue']);}unset($row);}
        $g['cost']=$cost;$total['available']=$total['available']&&$cost['available'];if($cost['available'])foreach(['rawValue','conversionValue','byProductValue','readyRiceValue'] as $field)$total[$field]+=$cost[$field];foreach($cost['basis'] as $basis)$total['basis'][$basis]=true;
    }unset($g);
    foreach(['rawValue','conversionValue','byProductValue','readyRiceValue'] as $field)$total[$field]=$total['available']?round($total[$field],2):null;$total['basis']=array_keys($total['basis']);$total['costPerKg']=$total['available']&&$report['totals']['readyKg']>0?round($total['readyRiceValue']/$report['totals']['readyKg'],6):null;$report['costTotals']=$total;return $report;
}
