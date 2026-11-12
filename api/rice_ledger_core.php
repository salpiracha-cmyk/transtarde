<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/inventory_reconciliation.php';

/** Operational stock order, shared by the screen, print and CSV. */
function rl_product_order(string $product): int {
    if(preg_match('/\bRaw Rice$/i',$product))return 0;
    if(preg_match('/(?:^| — )(?:B2(?: CSR| \/ BROKEN| — .*)?|BROKEN|B3|CSR|PADDY|POWDER|SWEEPING|STONE \/ SUTLI \/ DUST)$/iu',$product))return 2;
    if(preg_match('/(?:Rice — |\bReady Rice$|^Ready Rice — )/iu',$product))return 1;
    return 2;
}

/** Read-only projection of canonical stock movements. Never recalculate stock independently. */
function rl_report(array $values,array $scope,array $filters):array {
    $sources=array_slice(TT_INV_SOURCE_KEYS,0,6);
    if(!empty($scope['arrivalEstimates']))$sources[]='tt30queue';
    foreach($sources as $key){
        $raw=$values[$key]??'[]';if(!is_string($raw))throw new RuntimeException('Invalid stock source.');
        $rows=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($rows)||!array_is_list($rows)||count(array_filter($rows,'is_array'))!==count($rows))throw new RuntimeException('Invalid stock rows.');
    }
    $moves=[];$stock=tt_inv_stock($values,$scope,static function(array $row)use(&$moves):void{
        $parts=$row['parts']??[];unset($row['parts']);if(!$parts){if($row['kg']!=0)$moves[]=$row;return;}
        $remaining=$row['kg'];$last=count($parts)-1;
        foreach($parts as $i=>$part){$kg=$i===$last?$remaining:round($part['kg'],3);$remaining=round($remaining-$kg,3);if($kg==0)continue;$copy=$row;$copy['kg']=$kg;$copy['date']=mr_valid_date($part['date'])?$part['date']:'';$copy['part']=$i+1;$moves[]=$copy;}
    });
    foreach($moves as $i=>&$row)$row['order']=$i;unset($row);
    usort($moves,static fn($a,$b)=>strcmp($a['date'],$b['date'])?:strcmp($a['sourceId'],$b['sourceId'])?:($a['order']<=>$b['order']));
    $products=array_keys($stock);usort($products,static fn($a,$b)=>(rl_product_order($a)<=>rl_product_order($b))?:strnatcasecmp($a,$b));$selected=(string)($filters['product']??'');
    $from=(string)($filters['from']??'');$to=(string)($filters['to']??'');$movement=(string)($filters['movement']??'All');
    $balances=[];$summaries=[];$rows=[];$undated=[];
    foreach($products as $product){if($selected!==''&&$product!==$selected)continue;$balances[$product]=0.;$summaries[$product]=['product'=>$product,'openingKg'=>0.,'inwardKg'=>0.,'outwardKg'=>0.,'closingKg'=>0.,'undatedKg'=>0.,'undatedCount'=>0,'scopeUnverifiedCount'=>0,'provisional'=>false,'arrivalEstimate'=>false];}
    foreach($moves as $row){$p=$row['product'];if(!isset($summaries[$p]))continue;$s=&$summaries[$p];
        if(($row['scopeVerified']??true)===false&&($row['date']===''||$to===''||$row['date']<=$to))$s['scopeUnverifiedCount']++;
        if($row['date']===''){$s['undatedKg']=round($s['undatedKg']+$row['kg'],3);$s['undatedCount']++;$undated[]=$row;unset($s);continue;}
        if($to!==''&&$row['date']>$to){unset($s);continue;}
        if(!empty($row['arrivalEstimate']))$s['arrivalEstimate']=true;
        if(str_starts_with($row['basis'],'Provisional'))$s['provisional']=true;
        $balances[$p]=round($balances[$p]+$row['kg'],3);$row['balanceKg']=$balances[$p];
        if($from!==''&&$row['date']<$from){$s['openingKg']=$balances[$p];unset($s);continue;}
        if($row['kg']>0)$s['inwardKg']=round($s['inwardKg']+$row['kg'],3);else $s['outwardKg']=round($s['outwardKg']-$row['kg'],3);
        if($movement==='All'||($movement==='Inward'&&$row['kg']>0)||($movement==='Outward'&&$row['kg']<0))$rows[]=$row;unset($s);
    }
    foreach($summaries as $p=>&$s){$s['closingKg']=$balances[$p];$issues=[];if($s['undatedCount'])$issues[]='Unverified — undated movements excluded';if($s['scopeUnverifiedCount'])$issues[]='Unverified — source company/mill not recorded';if($s['provisional'])$issues[]='Provisional — historical input inferred';$s['status']=$issues?implode('; ',$issues):'Recorded quantities';}unset($s);
    foreach($rows as &$row)$row['status']=!empty($row['arrivalEstimate'])?TT_INV_POHANCH_ESTIMATE:$summaries[$row['product']]['status'];unset($row);
    foreach($summaries as &$summary){
        if($summary['arrivalEstimate'])$summary['status']=TT_INV_POHANCH_ESTIMATE.($summary['status']==='Recorded quantities'?'':'; '.$summary['status']);
    }unset($summary);
    return ['ok'=>true,'scope'=>$scope,'filters'=>$filters,'products'=>$products,'summaries'=>array_values($summaries),'rows'=>$rows,'undated'=>$undated,'note'=>(!empty($scope['physicalPool'])?'Physical stock pools TTI and BRM at this mill. By-products combine only within the same rice variety and product; unknown historical varieties remain unassigned. Original company/source references are retained. ':'').'Stock arrivals and dispatches use saved Karachi cargo weighbridge weight without deducting bag tare. Historical rows without a saved gross weight retain their recorded quantity. Internal production and transfers retain their recorded output quantities. Balances are separate for each product and include all dated movements, regardless of the Movement display filter. Undated history is excluded from dated balances. Inferred production consumption remains provisional. Legacy movements without recorded company/mill retain the existing quantity projection but their ownership is unverified. Source references identify operational records, not accounting Post IDs.'];
}
