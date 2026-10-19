<?php
declare(strict_types=1);
require_once __DIR__.'/group_daily_core.php';

/** Original operational commitments only. Financial B/F is never a quantity source. */
function cq_report(array $books,array $ops,string $entity,string $from,string $to):array {
    gm_date($from);gm_date($to);if($from>$to||!in_array($entity,['TTI','BRM','TG'],true))throw new DomainException('Select a valid company and period.');
    $scoped=['revision'=>(int)($books['revision']??0)];
    foreach(['purchaseSodas','purchaseSodasV2','purchaseSodaLiftings'] as $key)$scoped[$key]=array_filter((array)($books[$key]??[]),static fn($r)=>is_array($r)&&($r['entity']??'')===$entity);
    $values=(array)($ops['values']??[]);$holds=[];
    $raw=$values['transtrade_export_v3_operational']??null;
    if($raw===null)throw new DomainException('The saved Export source is unavailable.');
    $root=is_string($raw)?json_decode($raw,true,512,JSON_THROW_ON_ERROR):$raw;
    if(!is_array($root)||!is_array($root['contracts']??null)||!is_array($root['shipments']??null))throw new DomainException('The saved Export source needs reconciliation.');
    $contracts=array_values(array_filter($root['contracts'],static fn($r)=>is_array($r)&&($r['seller']??'')===$entity));$refs=array_fill_keys(array_column($contracts,'ref'),true);
    $safe=['transtrade_export_v3_operational'=>json_encode(['contracts'=>$contracts,'shipments'=>array_values(array_filter($root['shipments'],static fn($r)=>is_array($r)&&isset($refs[$r['contractRef']??''])))],JSON_THROW_ON_ERROR)];
    foreach(['tt30slips','tt30ship','tt35exload','tt35exmill'] as $key){
        $safeRows=[];foreach(gd_rows($values,$key) as $row){
            $owner=(string)($row['entity']??'');
            // A TG sale can be loaded at a Pakistan mill. Only the selected contract's
            // native loading participates; no other company's books or stock are read.
            $saleLoad=in_array($key,['tt30ship','tt35exload'],true)&&isset($refs[$row['contractRef']??'']);
            if($owner===$entity||$saleLoad)$safeRows[]=$row;
            elseif($owner==='')$holds['UNASSIGNED|'.$key]=['sourceId'=>$key,'message'=>'Some operational records have no confirmed company. They are excluded until their original ownership is reconciled.'];
        }$safe[$key]=json_encode($safeRows,JSON_THROW_ON_ERROR);
    }
    $snapshot=['values'=>$safe,'revision'=>$ops['revision']??0,'sourceBackend'=>$ops['sourceBackend']??'supplied'];
    $current=gd_sources($scoped,$snapshot,$to,false);
    $before=(new DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d');$prior=gd_sources($scoped,$snapshot,$before,false);
    $previous=[];foreach(array_merge($prior['purchaseOrders'],$prior['salesContracts']) as $r)$previous[$r['id']]=$r;
    $details=[];foreach(['purchaseSodas','purchaseSodasV2'] as $collection)foreach($scoped[$collection] as $id=>$r)$details['PURCHASE|'.$entity.'|'.($r['id']??$id)]=$r;
    foreach($contracts as $r)$details['SALE|'.$entity.'|'.$r['ref']]=$r;
    $missing=[];
    foreach(['tt30slips','tt30ship','tt35exload','tt35localsales','tt35exportersale'] as $key)if(!array_key_exists($key,$values)){
        $missing[$key]=true;$holds['MISSING|'.$key]=['sourceId'=>$key,'message'=>'The native source has not been initialized. Its absence has not been certified as zero movement.'];
    }
    $rows=[];
    foreach(array_merge($current['purchaseOrders'],$current['salesContracts']) as $r){
        $purchase=isset($r['receivedMT']);$original=$details[$r['id']]??[];$old=$previous[$r['id']]??[];
        $known=$purchase?($r['receivedKnown']&&!isset($missing['tt30slips'])):($r['loadedKnown']&&!isset($missing['tt30ship'])&&!isset($missing['tt35exload']));$amount=$purchase?$r['receivedMT']:$r['loadedMT'];
        $oldKnown=$purchase?($old['receivedKnown']??true):($old['loadedKnown']??true);$oldAmount=$purchase?($old['receivedMT']??0):($old['loadedMT']??0);
        $rows[]=['type'=>$purchase?'Purchase':'Export sale','sourceId'=>$r['id'],'date'=>$r['date'],'reference'=>$r['number']??$r['ref'],'party'=>(string)($original['party']??$original['buyer']??$original['customer']??''),'commodity'=>$r['commodity'],'product'=>$r['product'],'brand'=>(string)($original['brand']??$original['brandName']??''),'orderedMinMT'=>$purchase?($r['quantityKnown']?$r['contractedMinMT']:null):($r['contractedMT']>0?$r['contractedMT']:null),'orderedMaxMT'=>$purchase?($r['quantityKnown']?$r['contractedMaxMT']:null):($r['contractedMT']>0?$r['contractedMT']:null),'movedBeforeMT'=>$known&&$oldKnown?$oldAmount:null,'movedPeriodMT'=>$known&&$oldKnown?round($amount-$oldAmount,6):null,'movedToDateMT'=>$known?$amount:null,'outstandingMinMT'=>$r['closed']?0.:($known?($r['undeliveredMinMT']??($purchase?null:$r['outstandingMT'])):null),'outstandingMaxMT'=>$r['closed']?0.:($known?($r['undeliveredMaxMT']??($purchase?null:$r['outstandingMT'])):null),'status'=>$r['closed']?'Closed':($r['status']??'Open'),'movementSourceIds'=>$r['receiptIds']??$r['loadingIds'],'sourceFingerprint'=>$r['sourceFingerprint']];
    }
    foreach(['tt35localsales'=>['Local sale','tons','loads','kg'],'tt35exportersale'=>['Sale to exporter','qty','containers','net']] as $key=>$schema){
        $seen=[];foreach(gd_rows($values,$key) as $r){
            if(($r['entity']??'')!==$entity){if(empty($r['entity']))$holds['UNASSIGNED|'.$key]=['sourceId'=>$key,'message'=>'Some sale references have no confirmed company. Their quantities have not been assigned.'];continue;}
            $id=(string)($r['id']??$r['soda']??$r['ref']??'');if($id===''||isset($seen[$id]))throw new DomainException('A local sale has a duplicate or missing original identity.');$seen[$id]=true;
            $on=gd_date($r['date']??'');if($on===''||$on>$to){if($on==='')$holds[$key.'|'.$id]=['sourceId'=>$key.'|'.$id,'message'=>'Confirm the original local sale date.'];continue;}
            $ordered=gm_number($r[$schema[1]]??0,'sale tonnes');$known=$ordered>0;$moved=0.;$priorKg=0.;$ids=[];$loadIds=[];
            if(!is_array($r[$schema[2]]??null)||!array_is_list($r[$schema[2]]))throw new DomainException('A local sale loading list is invalid.');
            foreach($r[$schema[2]] as $load){
                if(!is_array($load))throw new DomainException('A local sale loading is invalid.');
                $lid=(string)($load['bridgeId']??$load['id']??$load['container']??$load['gatePassRef']??$load['gatePass']??'');$date=gd_date($load['date']??$load['gateTime']??'');$kg=gm_number($load[$schema[3]]??null,'original loaded kg');
                if($date!==''&&$date>$to)continue;
                if($lid===''||isset($loadIds[$lid])){$known=false;$holds[$key.'|'.$id.'|IDENTITY']=['sourceId'=>$key.'|'.$id,'message'=>'Reconcile missing or repeated original loading identities.'];continue;}$loadIds[$lid]=true;
                if($date===''||$date<$on||str_contains(strtoupper((string)($load['issue']??'')),'HOLD')){$known=false;$holds[$key.'|'.$id.'|DATE']=['sourceId'=>$key.'|'.$id,'message'=>'Reconcile the original loading date and held disposition.'];continue;}
                $moved+=$kg;if($date<$from)$priorKg+=$kg;$ids[]=$key.'|'.$id.'|'.$lid;
            }
            if($ordered<=0)$holds[$key.'|'.$id.'|QTY']=['sourceId'=>$key.'|'.$id,'message'=>'The original sale has no verified tonne commitment.'];
            $closed=!empty($r['closed'])||in_array(strtolower((string)($r['status']??'')),['completed','closed','cancelled'],true);
            $rows[]=['type'=>$schema[0],'sourceId'=>$key.'|'.$id,'date'=>$on,'reference'=>$id,'party'=>(string)($r['party']??$r['buyer']??''),'commodity'=>gd_commodity($r['commodity']??(preg_match('/\b(?:MAKAI|MAIZE|CORN)\b/i',(string)($r['displayName']??$r['product']??''))?'CORN':'RICE')),'product'=>(string)($r['displayName']??$r['product']??''),'brand'=>(string)($r['brand']??''),'orderedMinMT'=>$ordered>0?$ordered:null,'orderedMaxMT'=>$ordered>0?$ordered:null,'movedBeforeMT'=>$known?$priorKg/1000:null,'movedPeriodMT'=>$known?($moved-$priorKg)/1000:null,'movedToDateMT'=>$known?$moved/1000:null,'outstandingMinMT'=>$closed?0.:($known?max(0,$ordered-$moved/1000):null),'outstandingMaxMT'=>$closed?0.:($known?max(0,$ordered-$moved/1000):null),'status'=>$closed?'Closed':'Open','movementSourceIds'=>$ids,'sourceFingerprint'=>hash('sha256',json_encode($r,JSON_THROW_ON_ERROR))];
        }
    }
    foreach($current['holds'] as $hold)$holds[$hold['sourceId'].'|'.$hold['message']]=$hold;
    usort($rows,static fn($a,$b)=>strcmp($a['date'],$b['date'])?:strcmp($a['sourceId'],$b['sourceId']));
    return ['rows'=>$rows,'sourceHolds'=>array_values($holds),'basis'=>'MT (1,000 kg). Current saved commitments through the as-of date; movements are split before/within the selected period. Closed contracts have no new requirement. Financial B/F creates no physical rice or bag quantity. Historical amendments and missing source ownership require review.','accountsRevision'=>(int)($books['revision']??0),'operationsRevision'=>(int)($ops['revision']??0)];
}
