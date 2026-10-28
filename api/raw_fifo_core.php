<?php
declare(strict_types=1);
require_once __DIR__.'/production_costing_core.php';

function rf_type(string $value):string {
    if(preg_match('/parboil|sella/i',$value))return 'PARBOIL';
    if(preg_match('/steam/i',$value))return 'STEAM';
    return 'WHITE';
}

/** Read-only FIFO for management costing. Approved/posted costs remain authoritative. */
function rf_sources(array $books,array $ops,string $entity):array {
    $events=[];
    foreach(pc_receipts($books) as $r)if($r['entity']===$entity&&$r['productStage']==='RAW')$events[]=['date'=>$r['date'],'priority'=>0,'id'=>$r['sourceKey'],'row'=>$r];
    foreach(pc_production($ops,$entity) as $p)$events[]=['date'=>$p['date'],'priority'=>1,'id'=>$p['shift'].'|'.$p['id'],'row'=>$p];
    usort($events,static fn($a,$b)=>strcmp($a['date'],$b['date'])?:($a['priority']<=>$b['priority'])?:strcmp($a['id'],$b['id']));
    $layers=[];$uncertain=[];$out=[];
    foreach($events as $event){
        $r=$event['row'];$mill=pc_norm((string)($r['locationName']??$r['source']['millName']??$r['source']['locationName']??''));$key=$mill.'|'.$r['varietyKey'].'|'.rf_type((string)($r['riceType']??$r['source']['riceType']??$r['variety']));
        if($event['priority']===0){$layers[$key][]=$r;continue;}
        if(!in_array(strtoupper((string)($r['source']['inputStage']??'RAW')),['','RAW'],true)){$out[$r['id']]=['available'=>false,'rawRate'=>0.,'rawValue'=>0.,'provisional'=>false,'layers'=>[],'status'=>'Reprocessing requires the actual input-stock cost'];continue;}
        $need=(float)$r['inputKg'];$value=0.;$remaining=$need;$trace=[];$provisional=false;$issue='';
        if($mill==='')$issue='FIFO receipt / production mill is missing';
        elseif(empty($r['inputComplete']))$issue='Actual raw input is missing for FIFO';
        elseif(!empty($uncertain[$key]))$issue='Earlier raw consumption is incomplete in this mill / variety';
        foreach($layers[$key]??[] as $index=>$layer){
            if($remaining<=.001)break;$kg=min($remaining,(float)$layer['kg']);if($kg<=0)continue;
            $rate=$layer['value']/max(.001,$layer['kg']);$part=$kg*$rate;$value+=$part;$remaining-=$kg;
            $layers[$key][$index]['kg']-=$kg;$layers[$key][$index]['value']-=$part;
            $provisional=$provisional||!$layer['final'];if($rate<=0)$issue='FIFO receipt cost is missing';
            $trace[]=['sourceKey'=>$layer['sourceKey'],'date'=>$layer['date'],'kg'=>round($kg,3),'ratePerKg'=>round($rate,6),'valuePKR'=>round($part,2),'final'=>$layer['final']];
        }
        if($remaining>.001)$issue='FIFO raw stock is short in this mill / variety';
        if($issue!=='')$uncertain[$key]=true;
        $out[$r['id']]=['available'=>$issue===''&&$need>0,'rawRate'=>$need>0?$value/$need:0.,'rawValue'=>round($value,2),'provisional'=>$provisional,'layers'=>$trace,'status'=>$issue?:($provisional?'FIFO estimate — final purchase bill pending':'FIFO finalized purchase receipts')];
    }
    return $out;
}
