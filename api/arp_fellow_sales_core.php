<?php
declare(strict_types=1);
/** Commercial quantity view only: original accounting direction and money stay intact. */
function br_fellow_sales(array $books,array $source,string $date):array {
    $sales=$source['salesContracts'];$rows=[];$byDeal=[];$byContract=[];
    foreach($sales as$r){if(isset($r['exporterDealId']))$byDeal[$r['exporterDealId']]=$r;else $byContract[$r['ref']]=$r;}
    foreach((array)($books['exporterDeals']??[])as$id=>$d){
        if(($d['status']??'')==='Deleted'||($d['date']??'')>$date)continue;$id=(string)($d['id']??$id);$ref=(string)($d['contractRef']??'');$sale=$byDeal[$id]??null;
        // A procurement/margin SODA belongs to the existing end-buyer sale. Count it once.
        $linked=$sale===null&&isset($byContract[$ref]);$loaded=0.;$known=true;
        if($sale){$loaded=$sale['loadedMT'];$known=$sale['loadedKnown'];}
        else foreach((array)($d['loadings']??[])as$l){$day=gd_date($l['date']??'');if($day===''){$known=false;continue;}if($day<=$date)$loaded+=(float)($l['quantityMT']??0);}
        $documentClosed=$linked?!empty($byContract[$ref]['closed']):in_array(strtolower((string)($d['status']??'')),['closed','completed','cancelled'],true);
        $closed=$sale['closed']??dc_complete((float)$d['quantityMT'],$loaded,$known,$documentClosed);$remaining=$sale['outstandingMT']??dc_remaining((float)$d['quantityMT'],$loaded,$known,$documentClosed);
        $row=['id'=>$id,'entity'=>$d['entity'],'party'=>$d['party'],'reference'=>($d['reference']??'')?:$id,'contractRef'=>$ref,'product'=>$d['product'],'brand'=>$d['brand']??'','quantityMT'=>(float)$d['quantityMT'],'loadedMT'=>$known?round($loaded,6):null,'remainingMT'=>$remaining,'closed'=>$closed,'countedSeparately'=>!$linked,'salesBasis'=>$linked?'Included in export contract '.$ref:'Additional fellow-exporter sale','recoveryPct'=>$d['recoveryPct']??(($d['commodity']??'')==='CORN'?100.:null),'rawRequiredMT'=>($d['direction']??'')==='PURCHASE'?0.:(isset($d['recoveryPct'])&&$d['recoveryPct']>0&&$remaining!==null?round($remaining/($d['recoveryPct']/100),6):((($d['commodity']??'')==='CORN'&&$remaining!==null)?$remaining:null)),'purchases'=>[]];
        if(!$sale&&!$linked)$sales[]=['id'=>'FELLOW-SALE|'.$d['entity'].'|'.$id,'ref'=>$row['reference'],'entity'=>$d['entity'],'exporterDealId'=>$id,'party'=>$d['party'],'product'=>$d['product'],'contractedMT'=>$d['quantityMT'],'loadedMT'=>$loaded,'loadedKnown'=>$known,'outstandingMT'=>$remaining,'closed'=>$closed,'signed'=>true];
        $rows[$id]=$row;
    }
    $purchaseMap=[];foreach($source['purchaseOrders']as$p)$purchaseMap[$p['entity'].'|'.$p['sourceId']]=$p;$seen=[];
    foreach(['purchaseSodas','purchaseSodasV2']as$collection)foreach((array)($books[$collection]??[])as$id=>$p){
        $key=($p['entity']??'').'|'.($p['id']??$id);if(isset($seen[$key])||!isset($purchaseMap[$key]))continue;$seen[$key]=true;$original=$purchaseMap[$key];$offset=0.;
        foreach((array)($p['buyingForAllocations']??[])as$a){$quantity=(float)($a['quantityKg']??0)/1000;$received=$original['receivedKnown']?min($quantity,max(0,$original['receivedMT']-$offset)):null;$offset+=$quantity;
            foreach($rows as&$r){if($r['entity']!==$p['entity'])continue;$target=(string)($a['targetId']??'');$direct=$target==='EXPORTER|'.$r['id'];$contract=$r['contractRef']!==''&&$target==='EXPORT|'.$r['contractRef'];if(!$direct&&!$contract)continue;
                $r['purchases'][]=['sourceId'=>$original['sourceId'],'soda'=>$original['number'],'product'=>$original['product'],'stage'=>$p['productStage']??'','allocationBasis'=>$direct?'This fellow-exporter SODA':'Linked export contract','allocatedMT'=>round($quantity,6),'receivedMT'=>$received===null?null:round($received,6),'remainingMT'=>$original['closed']?0.:($received===null?null:round(max(0,$quantity-$received),6))];
            }unset($r);
        }
    }
    foreach($rows as&$r){$r['allocatedPurchaseMT']=br_sum($r['purchases'],'allocatedMT');$r['receivedPurchaseMT']=br_sum($r['purchases'],'receivedMT');$r['remainingPurchaseMT']=br_sum($r['purchases'],'remainingMT');}unset($r);
    return ['sales'=>array_values($sales),'rows'=>array_values($rows)];
}
