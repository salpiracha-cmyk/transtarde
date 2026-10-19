<?php
declare(strict_types=1);
require_once __DIR__.'/group_management_core.php';
require_once __DIR__.'/rice_ledger_core.php';

/** Daily quantities are read from committed sources; mappings never post stock or money. */
function gd_rows(array $values,string $key):array {
    $raw=$values[$key]??'[]';
    $rows=is_string($raw)?json_decode($raw,true,512,JSON_THROW_ON_ERROR):$raw;
    if(!is_array($rows)||!array_is_list($rows)||count(array_filter($rows,'is_array'))!==count($rows))throw new DomainException('The saved '.$key.' source needs reconciliation.');
    return $rows;
}
function gd_date(mixed $value):string {
    $text=trim((string)$value);if(mr_valid_date($text))return $text;
    if(!preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}):(\d{2})(?::(\d{2})(?:\.\d+)?)?(?:Z|[+-]\d{2}:\d{2})?$/',$text,$m)||!mr_valid_date($m[1])||(int)$m[2]>23||(int)$m[3]>59||(int)($m[4]??0)>59)return '';
    try{return (new DateTimeImmutable($text,new DateTimeZone('Asia/Karachi')))->setTimezone(new DateTimeZone('Asia/Karachi'))->format('Y-m-d');}catch(Throwable){return '';}
}
function gd_commodity(mixed $value):string {
    $value=strtoupper(trim((string)$value));return in_array($value,['CORN','MAIZE','MAKAI'],true)?'CORN':$value;
}
/** Match the native Milling owner/serial identity without rewriting display text. */
function gd_container_identity(mixed $value):string {
    $id=(string)preg_replace('/[^A-Z0-9]/','',strtoupper(trim((string)$value)));
    return preg_match('/^[A-Z]{4}[0-9]{7}$/D',$id)?substr($id,0,10):$id;
}
/** Corn must have an explicit native Corn/Maize/Makai product identity. Rice cannot consume those products. */
function gd_stock_matches_commodity(array $stock,string $commodity):bool {
    $corn=preg_match('/\b(?:CORN|MAIZE|MAKAI)\b/i',(string)($stock['product']??''))===1;
    return $commodity==='CORN'?$corn:!$corn;
}
function gd_hold(array &$holds,string $id,string $message):void {$holds[$id.'|'.$message]=['sourceId'=>$id,'message'=>$message];}
function gd_read_operations(string $path):array {
    $config=static fn($name)=>(string)(defined('TT_DB_'.$name)?constant('TT_DB_'.$name):(getenv('TT_DB_'.$name)?:''));
    $host=$config('HOST');$name=$config('NAME');$user=$config('USER');
    if($host!==''||$name!==''||$user!==''){
        if($host===''||$name===''||$user==='')throw new RuntimeException('The operational database configuration is incomplete.');
        $db=new PDO('mysql:host='.$host.';dbname='.$name.';charset=utf8mb4',$user,$config('PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $keys=array_merge(array_slice(TT_INV_SOURCE_KEYS,0,6),['transtrade_export_v3_operational','tt35exload','tt35exmill']);$db->beginTransaction();
        try{$q=$db->prepare('SELECT storage_key,payload,version FROM tt_operation_records WHERE storage_key IN ('.implode(',',array_fill(0,count($keys),'?')).')');$q->execute($keys);$values=[];$versions=[];$revision=0;foreach($q->fetchAll() as $row){$key=(string)$row['storage_key'];$values[$key]=(string)$row['payload'];$versions[$key]=(int)$row['version'];$revision=max($revision,(int)$row['version']);}$db->commit();return ['values'=>$values,'versions'=>$versions,'revision'=>$revision,'sourceBackend'=>'mysql'];}
        catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
    }
    if(!is_file($path))throw new RuntimeException('The operational source has not been initialized.');
    $h=fopen($path,'rb');if(!$h||!flock($h,LOCK_SH))throw new RuntimeException('The operational source is unavailable.');
    try{$store=mr_decode_store((string)stream_get_contents($h));if(!is_array($store['values']??null))throw new RuntimeException('The operational values are invalid.');$store['sourceBackend']='file';return $store;}finally{flock($h,LOCK_UN);fclose($h);}
}
function gd_clean_config(array $config):array {
    if(strlen(json_encode($config,JSON_THROW_ON_ERROR))>262144)throw new DomainException('The Daily mapping is too large.');
    $buckets=$config['buckets']??[];if(!is_array($buckets)||!array_is_list($buckets)||count($buckets)>100)throw new DomainException('Invalid Daily product groups.');$out=[];
    $ids=static function(mixed $items):array{if(!is_array($items)||!array_is_list($items)||count($items)>2000)throw new DomainException('Invalid Daily source selection.');return array_map(static fn($id)=>gm_text($id,200),$items);};
    foreach($buckets as $row){
        if(!is_array($row))throw new DomainException('Invalid Daily product group.');$sales=$row['sales']??[];if(!is_array($sales)||!array_is_list($sales)||count($sales)>1000)throw new DomainException('Invalid Daily sales selection.');$cleanSales=[];
        foreach($sales as $sale){if(!is_array($sale)||isset($sale['includeUnsigned'])&&!is_bool($sale['includeUnsigned']))throw new DomainException('Choose explicitly whether the unsigned contract is included.');$allocations=$sale['finishedAllocations']??[];if(!is_array($allocations)||!array_is_list($allocations)||count($allocations)>1000)throw new DomainException('Invalid finished stock allocations.');$clean=[];foreach($allocations as $allocation){if(!is_array($allocation))throw new DomainException('Invalid finished stock allocation.');$clean[]=['stockId'=>gm_text($allocation['stockId']??'',200),'quantityMT'=>gm_number($allocation['quantityMT']??null,'finished stock allocation MT')];}$cleanSales[]=['id'=>gm_text($sale['id']??'',200),'recoveryPct'=>gm_number($sale['recoveryPct']??null,'reviewed recovery percentage'),'includeUnsigned'=>$sale['includeUnsigned']??false,'finishedAllocations'=>$clean];}
        $out[]=['id'=>gm_text($row['id']??'',128),'label'=>gm_text($row['label']??'',300),'commodity'=>gd_commodity($row['commodity']??''),'rawStockIds'=>$ids($row['rawStockIds']??[]),'purchaseIds'=>$ids($row['purchaseIds']??[]),'sales'=>$cleanSales];
    }
    return ['buckets'=>$out];
}
function gd_sources(array $books,array $ops,string $date):array {
    gm_date($date);$values=(array)($ops['values']??[]);$holds=[];$purchase=[];$numberIndex=[];
    foreach(['purchaseSodas','purchaseSodasV2'] as $collection)foreach((array)($books[$collection]??[]) as $id=>$soda){
        if(!is_array($soda))throw new DomainException('A saved purchase Soda is invalid.');
        $entity=strtoupper((string)($soda['entity']??''));$sourceId=(string)($soda['id']??$id);$key='PURCHASE|'.$entity.'|'.$sourceId;$on=gd_date($soda['sodaDate']??'');
        if(!in_array($entity,['TTI','BRM'],true)||$sourceId===''||$on===''){gd_hold($holds,$key,'Confirm the original purchase owner, identity and date.');continue;}
        if($on>$date)continue;
        if(isset($purchase[$key])){if($purchase[$key]['sourceFingerprint']!==hash('sha256',json_encode($soda,JSON_THROW_ON_ERROR)))throw new DomainException('Conflicting copies of the same purchase Soda require reconciliation.');continue;}
        $minimum=gm_number($soda['qtyFromKg']??0,'minimum contracted kg');$maximum=gm_number($soda['qtyToKg']??$minimum,'maximum contracted kg');
        $known=$minimum>0&&$maximum>=$minimum;if(!$known)gd_hold($holds,$key,'A truck-only or invalid quantity contract has no verified tonne commitment.');
        $status=(string)(($soda['manualStatus']??'')?:($soda['status']??'Open'));$closed=in_array(strtolower($status),['cancelled','completed','short closed','manual closed'],true);
        $purchase[$key]=['id'=>$key,'sourceId'=>$sourceId,'entity'=>$entity,'number'=>(string)($soda['sodaNo']??''),'date'=>$on,'commodity'=>gd_commodity($soda['commodity']??''),'product'=>(string)($soda['displayName']??$soda['variety']??''),'location'=>(string)($soda['locationName']??$soda['location']??''),'route'=>(string)($soda['readyRoute']??'DELIVER_TO_STOCK'),'contractedMinMT'=>$minimum/1000,'contractedMaxMT'=>$maximum/1000,'receivedMT'=>0.,'receivedKnown'=>true,'quantityKnown'=>$known,'closed'=>$closed,'status'=>$status,'receiptIds'=>[],'sourceFingerprint'=>hash('sha256',json_encode($soda,JSON_THROW_ON_ERROR))];
        $numberIndex[$entity.'|'.(string)($soda['sodaNo']??'')][]=$key;
    }
    $slips=gd_rows($values,'tt30slips');$receiptIds=[];
    foreach($slips as $row){
        $entity=strtoupper((string)($row['entity']??''));$sid=(string)($row['sourceSodaId']??'');$key='PURCHASE|'.$entity.'|'.$sid;$rid=(string)($row['bridgeId']??$row['id']??'');
        if($sid===''){$matches=$numberIndex[$entity.'|'.(string)($row['soda']??'')]??[];if(count($matches)===1)$key=$matches[0];else{gd_hold($holds,'ARRIVAL|'.$rid,'The original purchase link is missing or ambiguous.');continue;}}
        if(!isset($purchase[$key])){gd_hold($holds,'ARRIVAL|'.$rid,'The arrival has no matching purchase commitment in this snapshot.');continue;}
        $on=gd_date($row['unloadingDate']??$row['arrivalDate']??$row['date']??'');
        if($on===''){$purchase[$key]['receivedKnown']=false;gd_hold($holds,'ARRIVAL|'.$rid,'An undated receipt cannot reduce a dated purchase commitment.');continue;}
        if($on>$date)continue;
        if($rid===''||isset($receiptIds[$entity.'|'.$rid]))throw new DomainException('Duplicate or missing physical arrival identity requires reconciliation.');$receiptIds[$entity.'|'.$rid]=true;
        $kg=gm_number($row['payableWeight']??null,'recorded arrival kg');
        $purchase[$key]['receivedMT']+=$kg/1000;$purchase[$key]['receiptIds'][]='tt30slips|'.$rid;
    }
    $outside=gd_rows($values,'tt35exload');$localSodas=[];
    foreach(gd_rows($values,'tt35exmill') as $soda)$localSodas[(string)($soda['id']??'')]=(string)($soda['_ttPurchaseSodaId']??$soda['_ttBridgeId']??'');
    $physicalLiftings=[];foreach((array)($books['purchaseSodaLiftings']??[]) as $id=>$lifting){
        if(!is_array($lifting)||!empty($lifting['reversed']))continue;$entity=(string)($lifting['entity']??'');$key='PURCHASE|'.$entity.'|'.(string)($lifting['sourceSodaId']??'');if(!isset($purchase[$key]))continue;
        $on=gd_date($lifting['date']??'');if($on!==''&&$on>$date)continue;$container=gd_container_identity($lifting['container']??'');$physical=$entity.'|'.(string)($lifting['sourceSodaId']??'').'|'.$container;if($container!==''&&isset($physicalLiftings[$physical]))throw new DomainException('More than one financial lifting refers to the same physical container. Reconcile the original receipt before reporting purchase commitments.');if($container!=='')$physicalLiftings[$physical]=true;
        $matches=array_values(array_filter($outside,static fn($r)=>gd_container_identity($r['container']??'')===gd_container_identity($lifting['container']??'')&&(string)($r['sourceSodaId']??$localSodas[(string)($r['sodaId']??'')]??'')===(string)($lifting['sourceSodaId']??'')));
        if($container===''||$on===''||count($matches)!==1||abs((float)($matches[0]['kg']??0)-(float)($lifting['kg']??0))>.001||gd_date($matches[0]['date']??'')!==$on){$purchase[$key]['receivedKnown']=false;gd_hold($holds,'LIFTING|'.$id,'Reconcile the financial lifting with its original dated physical loading source.');continue;}
        $purchase[$key]['receivedMT']+=gm_number($lifting['kg']??null,'actual lifted kg')/1000;$purchase[$key]['receiptIds'][]='purchaseSodaLiftings|'.$id;
    }
    foreach($purchase as &$row){$row['receivedMT']=round($row['receivedMT'],6);$known=$row['quantityKnown']&&$row['receivedKnown'];$row['undeliveredMinMT']=$known?($row['closed']?0.:max(0,$row['contractedMinMT']-$row['receivedMT'])):null;$row['undeliveredMaxMT']=$known?($row['closed']?0.:max(0,$row['contractedMaxMT']-$row['receivedMT'])):null;}unset($row);

    if(!array_key_exists('transtrade_export_v3_operational',$values))gd_hold($holds,'EXPORT-SOURCE','The Export source has not been initialized; an empty commitment list has not been assumed.');
    $raw=$values['transtrade_export_v3_operational']??'{}';$root=is_string($raw)?json_decode($raw,true,512,JSON_THROW_ON_ERROR):$raw;if(!is_array($root))throw new DomainException('Invalid saved Export source.');$sales=[];$refs=[];
    foreach((array)($root['contracts']??[]) as $contract){
        if(!is_array($contract))throw new DomainException('Invalid saved contract.');$ref=(string)($contract['ref']??'');$seller=strtoupper((string)($contract['seller']??''));$id='SALE|'.$seller.'|'.$ref;$on=gd_date($contract['date']??'');
        if($ref===''||!in_array($seller,['TTI','BRM','TG'],true)||$on===''){gd_hold($holds,$id,'Confirm the original sale identity, seller and contract date.');continue;}if($on>$date)continue;
        if(isset($refs[$ref]))throw new DomainException('A repeated sales-contract reference requires reconciliation.');$refs[$ref]=$id;
        $qty=gm_number($contract['qty']??0,'contract tonnes');if($qty<=0&&strtoupper((string)($contract['shipmentMode']??'FCL'))!=='BREAK_BULK')$qty=gm_number($contract['containers']??0,'contract containers')*gm_number($contract['weightPer']??0,'contract MT per container');
        if($qty<=0)gd_hold($holds,$id,'The original sale has no verified tonne quantity.');
        $status=(string)($contract['status']??'');$closed=!empty($contract['cancelled'])||in_array(strtolower($status),['cancelled','closed','completed'],true);
        $sales[$id]=['id'=>$id,'ref'=>$ref,'entity'=>$seller,'date'=>$on,'product'=>(string)($contract['product']??$contract['displayName']??''),'commodity'=>gd_commodity(($contract['commodity']??'')?: (preg_match('/\b(?:CORN|MAIZE|MAKAI)\b/i',(string)($contract['product']??''))?'CORN':(preg_match('/\bRICE\b/i',(string)($contract['product']??''))?'RICE':''))),'productIdentityCode'=>(string)($contract['productIdentityCode']??''),'broken'=>(string)($contract['brokenText']??$contract['broken']??''),'signed'=>!empty($contract['received']),'closed'=>$closed,'contractedMT'=>$qty,'loadedMT'=>0.,'loadedKnown'=>true,'loadingIds'=>[],'sourceFingerprint'=>hash('sha256',json_encode($contract,JSON_THROW_ON_ERROR))];
    }
    $loadIds=[];$loadedContainers=[];
    $load=static function(array $row,string $ref,string $identity,string $weight)use(&$sales,$refs,&$holds,&$loadIds,&$loadedContainers,$date):void{
        if(!isset($refs[$ref]))return;$id=$refs[$ref];$kg=gm_number($row[$weight]??0,'actual loaded kg');if($kg<=0)return;
        $on=gd_date($row['date']??$row['loadingAt']??$row['gateTime']??'');if($on===''){$sales[$id]['loadedKnown']=false;gd_hold($holds,$identity,'An undated physical load cannot reduce a dated sales commitment.');return;}if($on>$date)return;
        if(isset($loadIds[$identity]))throw new DomainException('A repeated physical loading identity requires reconciliation.');$loadIds[$identity]=true;
        if(str_contains(strtoupper((string)($row['issue']??'')),'HOLD')){$sales[$id]['loadedKnown']=false;gd_hold($holds,$identity,'A held physical loading requires disposition before reducing the sales commitment.');return;}
        $container=gd_container_identity($row['container']??'');$physical=$ref.'|'.$container;if($container!==''&&isset($loadedContainers[$physical]))throw new DomainException('The same physical container appears in more than one native loading record. Reconcile its original source before reporting outstanding sales.');$sales[$id]['loadedMT']+=$kg/1000;$sales[$id]['loadingIds'][]=$identity;if($container!=='')$loadedContainers[$physical]=['kg'=>$kg,'sourceId'=>$identity];
    };
    foreach(gd_rows($values,'tt30ship') as $ship)foreach((array)($ship['containers']??[]) as $row){if(!is_array($row))throw new DomainException('Invalid physical loading row.');$identity='tt30ship|'.(string)($ship['id']??'').'|'.(string)($row['id']??$row['container']??'');$load($row,(string)($ship['contractRef']??''),$identity,'weight');}
    foreach($outside as $row)$load($row,(string)($row['contractRef']??''),'tt35exload|'.(string)($row['id']??''),'kg');
    foreach((array)($root['shipments']??[]) as $shipment){if(!is_array($shipment)||!empty($shipment['cancelled']))continue;$ref=(string)($shipment['contractRef']??'');if(!isset($refs[$ref]))continue;foreach((array)($shipment['millActuals']??[]) as $actual){if((float)($actual['millNetKg']??$actual['netKg']??0)<=0)continue;$key=$ref.'|'.gd_container_identity($actual['number']??$actual['container']??'');$native=$loadedContainers[$key]??null;if(!$native||abs((float)($actual['millNetKg']??$actual['netKg']??0)-$native['kg'])>.001){$sales[$refs[$ref]]['loadedKnown']=false;gd_hold($holds,'EXPORT-ACTUAL|'.(string)($shipment['id']??''),$native?'Export actual weight disagrees with its original native loading source.':'Export actuals have no matching committed native loading source in this snapshot.');}}}
    foreach($sales as &$row){$row['loadedMT']=round($row['loadedMT'],6);$row['outstandingMT']=$row['loadedKnown']&&$row['contractedMT']>0?($row['closed']?0.:max(0,$row['contractedMT']-$row['loadedMT'])):null;}unset($row);

    $stock=[];$scopes=[];$safe=$values;
    foreach(array_slice(TT_INV_SOURCE_KEYS,0,6) as $key){$safeRows=[];foreach(gd_rows($values,$key) as $row){$entity=strtoupper((string)($row['entity']??''));$mill=trim((string)($row['millName']??$row['locationName']??$row['mill']??''));$rid=$key.'|'.(string)($row['id']??'');if(!in_array($entity,['TTI','BRM'],true)||$mill===''){gd_hold($holds,$rid,'Confirm physical stock owner and location; no default company or mill was assumed.');continue;}$safeRows[]=$row;$scopes[$entity.'|'.$mill]=['entity'=>$entity,'millName'=>$mill];}$safe[$key]=json_encode($safeRows,JSON_THROW_ON_ERROR);}
    foreach($scopes as $scope){$ledger=rl_report($safe,$scope,['from'=>'','to'=>$date,'product'=>'','movement'=>'All']);foreach($ledger['summaries'] as $row){$id='STOCK|'.substr(hash('sha256',$scope['entity'].'|'.$scope['millName'].'|'.$row['product']),0,24);$role=in_array(strtoupper(trim($row['product'])),['B2','B3','CSR','B2CSR','POWDER','WASTE','OTHER'],true)?'byproduct':(preg_match('/\bRAW\b|\b(?:CORN|MAIZE|MAKAI)\b/i',$row['product'])?'raw':'finished');$stock[$id]=['id'=>$id]+$scope+['role'=>$role,'product'=>$row['product'],'quantityMT'=>$row['closingKg']/1000,'known'=>$row['undatedCount']===0&&!$row['provisional'],'status'=>$row['status']];if(!$stock[$id]['known'])gd_hold($holds,$id,'Undated or inferred stock cannot become verified usable stock.');}}
    $source=['asOf'=>$date,'purchaseOrders'=>array_values($purchase),'salesContracts'=>array_values($sales),'stocks'=>array_values($stock),'financials'=>gm_financial_source($books,$date),'holds'=>array_values($holds),'operationsRevision'=>(int)($ops['revision']??0),'operationsVersions'=>(array)($ops['versions']??$ops['meta']??[]),'sourceBackend'=>(string)($ops['sourceBackend']??'supplied'),'accountsRevision'=>(int)($books['revision']??0),'basis'=>'Original committed quantities through the selected date, using current saved contract versions. Review historical amendments and every missing source; no opening quantity is inferred.'];
    $source['fingerprint']=hash('sha256',json_encode($source,JSON_THROW_ON_ERROR));return $source;
}

/** Explicit recovery/specification and stock allocation avoid counting a shared lot twice. */
function gd_positions(array $source,array $config):array {
    $config=gd_clean_config($config);
    $purchases=array_column($source['purchaseOrders'],null,'id');$sales=array_column($source['salesContracts'],null,'id');$stocks=array_column($source['stocks'],null,'id');$used=[];$allocated=[];$out=[];$bucketIds=[];
    $claim=static function(string $id)use(&$used):void{if(isset($used[$id]))throw new DomainException('A purchase, sale or raw stock source is assigned more than once.');$used[$id]=true;};
    $buckets=(array)($config['buckets']??[]);if(!array_is_list($buckets)||count($buckets)>100)throw new DomainException('Invalid daily report product groups.');
    foreach($buckets as $bucket){$id=gm_text($bucket['id']??'',128);$label=gm_text($bucket['label']??'',300);if($id===''||$label==='')throw new DomainException('Each daily product group needs its own identity and description.');$commodity=gd_commodity($bucket['commodity']??'');if(!in_array($commodity,['RICE','CORN'],true)||isset($bucketIds[$id]))throw new DomainException('Choose a unique Rice or Corn product group.');$bucketIds[$id]=true;$raw=0.;$min=0.;$max=0.;$required=0.;$reasons=[];$references=[];
        foreach((array)($bucket['rawStockIds']??[]) as $sid){$sid=gm_text($sid,128);$row=$stocks[$sid]??null;if(!$row||$row['role']!=='raw')throw new DomainException('Select an actual raw stock source.');if(!gd_stock_matches_commodity($row,$commodity))throw new DomainException('The raw stock product must match the group commodity.');$claim($sid);$references[]=$sid;if(!$row['known']||$row['quantityMT']<0)$reasons[]='Raw stock needs reconciliation.';else $raw+=$row['quantityMT'];}
        foreach((array)($bucket['purchaseIds']??[]) as $pid){$pid=gm_text($pid,200);$row=$purchases[$pid]??null;if(!$row||$row['commodity']!==$commodity)throw new DomainException('The selected purchase must match this group commodity.');$claim($pid);$references[]=$pid;if($row['undeliveredMinMT']===null||$row['undeliveredMaxMT']===null)$reasons[]='Purchase commitment needs reconciliation.';else{$min+=$row['undeliveredMinMT'];$max+=$row['undeliveredMaxMT'];}}
        foreach((array)($bucket['sales']??[]) as $selection){$sid=gm_text($selection['id']??'',200);$row=$sales[$sid]??null;if(!$row||$row['commodity']!==$commodity)throw new DomainException('The selected sale must match this group commodity.');$claim($sid);$references[]=$sid;if(!$row['signed']&&empty($selection['includeUnsigned'])){$reasons[]='Confirm whether the unsigned sales commitment is included.';continue;}if($row['outstandingMT']===null){$reasons[]='Sales loading source needs reconciliation.';continue;}
            $recovery=gm_number($selection['recoveryPct']??null,'reviewed sale recovery percentage');if($recovery<=0||$recovery>100)throw new DomainException('Recovery must be greater than zero and no more than 100%.');$finished=0.;if($commodity==='CORN'&&abs($recovery-100)>.000001)throw new DomainException('Direct-loading Corn uses 100% quantity conversion, without Rice milling recovery.');
            foreach((array)($selection['finishedAllocations']??[]) as $allocation){$stockId=gm_text($allocation['stockId']??'',128);$lot=$stocks[$stockId]??null;$qty=gm_number($allocation['quantityMT']??null,'finished stock allocation MT');if(!$lot||!$lot['known']||$lot['quantityMT']<0||$lot['role']==='byproduct'||($commodity==='RICE'&&$lot['role']!=='finished'))throw new DomainException('Allocate only verified positive finished stock.');if(!gd_stock_matches_commodity($lot,$commodity))throw new DomainException('The allocated stock product must match the sale commodity.');if(isset($used[$stockId]))throw new DomainException('A raw stock source cannot also be used as finished stock.');$allocated[$stockId]=($allocated[$stockId]??0)+$qty;if($allocated[$stockId]>$lot['quantityMT']+.000001)throw new DomainException('Finished allocations exceed the actual shared stock quantity.');$finished+=$qty;$references[]=$stockId;}
            if($finished>$row['outstandingMT']+.000001)throw new DomainException('Finished stock allocation exceeds the outstanding sale.');$required+=($row['outstandingMT']-$finished)/($recovery/100);
        }
        $low=$raw+$min-$required;$high=$raw+$max-$required;$known=!$reasons;
        $out[]=['id'=>$id,'label'=>$label,'commodity'=>$commodity,'rawOnHandMT'=>round($raw,6),'undeliveredPurchaseMinMT'=>round($min,6),'undeliveredPurchaseMaxMT'=>round($max,6),'rawRequiredMT'=>$known?round($required,6):null,'positionMinMT'=>$known?round($low,6):null,'positionMaxMT'=>$known?round($high,6):null,'status'=>!$known?'SOURCE REVIEW REQUIRED':($high<-.000001?'SHORT':($low>.000001?'LONG':(abs($low)<.000001&&abs($high)<.000001?'BALANCED':'RANGE CROSSES ZERO'))),'reviewReasons'=>array_values(array_unique($reasons)),'sourceIds'=>array_values(array_unique($references))];
    }
    foreach($allocated as $id=>$qty)if(isset($used[$id]))throw new DomainException('A finished stock source cannot also be used as raw stock.');
    $unmapped=[];foreach($purchases as $id=>$row)if(!$row['closed']&&(($row['undeliveredMaxMT']??1)>0)&&!isset($used[$id]))$unmapped[]=$id;foreach($sales as $id=>$row)if(!$row['closed']&&(($row['outstandingMT']??1)>0)&&!isset($used[$id]))$unmapped[]=$id;foreach($stocks as $id=>$row)if($row['role']!=='byproduct'&&abs($row['quantityMT'])>.000001&&!isset($used[$id])&&!isset($allocated[$id]))$unmapped[]=$id;
    return ['positions'=>$out,'unmappedSourceIds'=>$unmapped,'complete'=>!$unmapped&&!$source['holds']&&empty($source['financials']['warnings'])&&!array_filter($out,static fn($r)=>$r['status']==='SOURCE REVIEW REQUIRED'),'sourceFingerprint'=>$source['fingerprint']];
}

/** The headline uses the retained source snapshot, never today's replacement balances. */
function gd_summary(array $record):array {
    $saved=$record['sections']['daily']??null;if(!$saved)throw new DomainException('Save the Daily source and position section to capture a reviewed source snapshot.');
    $source=$saved['data']['source'];$rates=(array)($record['sections']['header']['data']['rates']??[]);
    $rows=array_merge($source['financials']['rows'],(array)($record['sections']['adjustments']['rows']??[]));
    $total=gm_total($rows,$rates);
    return $total+['daily'=>$saved['calculation'],'sourceAsOf'=>$source['asOf'],'sourceFingerprint'=>$source['fingerprint'],'financialWarnings'=>$source['financials']['warnings'],'snapshotSavedAt'=>$saved['savedAt'],'complete'=>!empty($saved['complete'])&&(!isset($record['sections']['adjustments'])||!empty($record['sections']['adjustments']['complete'])),'basis'=>'Saved book balances and separately identified adjustments. Stock quantities are shown separately; unvalued stock is not assigned an invented market value.'];
}
