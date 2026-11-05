<?php
declare(strict_types=1);
require_once __DIR__.'/bag_receipts_core.php';
require_once __DIR__.'/bag_arrangement_core.php';

/** External ownership is a physical custody dimension, never a TTI bag purchase. */
function oeb_instruction_projection(array $deal): array {
    // Operational instructions remain visible before a physical bag receipt.
    // Commercial rates, settlements and internal agreement history stay private.
    return array_intersect_key($deal,array_flip(['id','entity','party','partyId','brand','packing','reference','version','date','quantityMT','commodity','product','productionReady','productionMillId','productionMillName','productionStockName','specificationMode','fullSpecification','productionNotes','productionBagTareGrams']))+['bagResponsibility'=>ba_physical_responsibility($deal)];
}
function oeb_active(array $deal,array $exports=[]): bool {
    if(($deal['direction']??'')!=='SALE'||preg_match('/^(deleted|closed|completed|cancelled)$/i',(string)($deal['status']??'')))return false;
    $ref=(string)($deal['contractRef']??'');
    foreach((array)($exports['contracts']??[]) as $contract)if($ref!==''&&($contract['ref']??'')===$ref&&(!empty($contract['cancelled'])||preg_match('/^(closed|completed|cancelled)$/i',(string)($contract['status']??''))))return false;
    return true;
}
function oeb_deal(array $books,array $scope,string $id): array {
    $d=$books['exporterDeals'][$id]??null;
    if(!$d||($d['entity']??'')!==$scope['entity']||empty($d['productionReady'])||bti_norm($d['productionMillName']??'')!==bti_norm($scope['millName']))throw new DomainException('Select an instructed SODA for this company and physical mill.');
    return $d;
}
function oeb_packing(array $d): array {
    preg_match_all('/\b([0-9]+(?:\.[0-9]+)?)\s*(?:kgs?|kilograms?)\b/i',(string)($d['packing']??''),$m);
    return array_values(array_unique(array_filter(array_map('floatval',$m[1]),static fn($n)=>$n>0)));
}
function oeb_spec(array $d,array $scope,array $body): array {
    $terms=ba_projection($d);if(($terms['status']??'')==='CONFIRMED'&&($terms['owner']??'')!=='FELLOW')throw new DomainException('This SODA does not assign physical bag ownership to the fellow exporter. Use the original company-owned bag purchase or confirm the correct owner.');
    $brand=trim((string)($d['brand']??''));if($brand==='')throw new DomainException('Save the missing brand to this SODA before receiving bags.');
    $size=$body['packingKg']??null;if(!is_numeric($size)||is_bool($size)||!is_finite((float)$size)||(float)$size<=0||(float)$size>2000)throw new DomainException('Enter the actual bag packing weight in kg.');
    $choices=oeb_packing($d);if($choices&&!in_array((float)$size,$choices,true))throw new DomainException('Select the packing weight instructed on this SODA.');
    $type=trim((string)($body['bagType']??''));if(!in_array($type,['PP Woven','BOPP','Non-woven','Jute','Other'],true))throw new DomainException('Select the actual bag material.');
    return ['sourceKind'=>'OTHER_EXPORTER','exporterDealId'=>$d['id'],'externalOwnerId'=>$d['partyId'],'externalOwnerName'=>$d['party'],'stockOwnerType'=>'OTHER_EXPORTER','entity'=>$scope['entity'],'locationId'=>$scope['locationId'],'millName'=>$scope['millName'],'brand'=>$brand,'size'=>(string)(float)$size.' kg','bagType'=>$type,'tare'=>(string)($d['productionBagTareGrams']??0).' g','poNo'=>'OTHER|'.$d['id'],'poLineKey'=>'L1','supplier'=>$d['party'],'sodaReference'=>$d['reference']??$d['id']];
}
/** Reuse the canonical receipt transaction, inspection and allocation ledger. */
function oeb_receive(array &$values,array &$state,array $masters,array $books,array $scope,array $body,array $user,string $now,callable $authorize): array {
    $request=bt_request($state,$body,$user,'OTHER_EXPORTER_RECEIPT');
    if($request['prior']){
        $p=$request['prior'];if(($p['kind']??'')!=='otherExporterReceipt'||($p['scope']??null)!=$scope)throw new DomainException('Receipt retry belongs to another source.');
        $authorize($scope['entity'],$scope['millName'],'Create',$scope['locationId']);return $p['result']+['replayed'=>true];
    }
    $authorize($scope['entity'],$scope['millName'],'Create',$scope['locationId']);
    $d=oeb_deal($books,$scope,(string)($body['sodaId']??''));$exports=json_decode($values['transtrade_export_v3_operational']??'{}',true,512,JSON_THROW_ON_ERROR);
    if(!oeb_active($d,(array)$exports))throw new DomainException('This SODA or linked contract is closed. Its bag history is retained.');
    if((int)($body['version']??-1)!==(int)$d['version'])throw new DomainException('The SODA changed. Refresh the SODA choices before receiving.');
    $date=bt_date((string)($body['date']??''),$now);if($date<($d['date']??''))throw new DomainException('Bag receipt cannot precede its SODA.');
    $spec=oeb_spec($d,$scope,$body);$orders=bti_rows($values,'tt30bags');$order=null;
    foreach($orders as $candidate)if(($candidate['sourceKind']??'')==='OTHER_EXPORTER'&&($candidate['exporterDealId']??'')===$d['id']&&($candidate['locationId']??'')===$scope['locationId']&&($candidate['size']??'')===$spec['size']&&($candidate['bagType']??'')===$spec['bagType']){
        if($order)throw new DomainException('Duplicate physical bag sources require reconciliation.');$order=$candidate;
        foreach($spec as $key=>$value)if(($order[$key]??null)!=$value)throw new DomainException('The instructed source changed after receiving. Preserve the original physical bags.');
    }
    if(!$order){$ids=array_column($orders,'id');$id=(int)floor(microtime(true)*1000);while(in_array($id,$ids))$id++;$order=['id'=>$id,'received'=>0,'ordered'=>0,'mill'=>$scope['millName'],'ref'=>$d['reference']??$d['id']]+$spec;$orders[]=$order;$values['tt30bags']=json_encode($orders,JSON_THROW_ON_ERROR);}
    $source=br_source($values,$masters,(string)$order['id']);
    $receiptBody=['action'=>'receive_supplier','requestKey'=>'OEB_'.substr(hash('sha256',$request['id']),0,40),'bagId'=>(string)$order['id'],'entity'=>$scope['entity'],'locationId'=>$scope['locationId'],'sourceFingerprint'=>$source['fingerprint'],'keepReceiptOpen'=>true,'date'=>$date,'qty'=>$body['qty']??null,'inspected'=>$body['inspected']??false,'printingMismatch'=>$body['printingMismatch']??false,'materialMismatch'=>$body['materialMismatch']??false,'qualityMismatch'=>$body['qualityMismatch']??false,'tareMismatch'=>$body['tareMismatch']??false,'actualTareGrams'=>$body['actualTareGrams']??null];
    $result=br_commit($values,$state,$masters,$receiptBody,$user,$now,$authorize,true);unset($result['replayed']);
    $state['requests'][$request['id']]=['fingerprint'=>$request['fingerprint'],'kind'=>'otherExporterReceipt','scope'=>$scope,'result'=>$result,'at'=>$now]+bt_actor($user);
    return $result+['replayed'=>false];
}
/** Explain every original brand; closed history remains stock history, never new demand. */
function oeb_choices(array $values,array $inventory,array $books,array $scope): array {
    $exports=json_decode($values['transtrade_export_v3_operational']??'{}',true,512,JSON_THROW_ON_ERROR);$orders=bti_rows($values,'tt30bags');$choices=[];$diagnostics=[];
    foreach($orders as $order){
        $reasons=[];$id=(string)($order['id']??'');
        // The inventory has already enforced canonical company/location identity.
        $lot=null;foreach($inventory['lots'] as $candidate)if(($candidate['originBagId']??'')===$id){$lot=$candidate;break;}
        if(!$lot)$reasons[]='Outside this company/mill or unverified source';
        $closed=false;
        if(($order['sourceKind']??'')==='OTHER_EXPORTER'){$d=$books['exporterDeals'][$order['exporterDealId']??'']??[];$closed=!$d||!oeb_active($d,(array)$exports);}
        else{
            $contractRef=(string)($order['contractRef']??'');if($contractRef==='')foreach((array)($exports['shipments']??[]) as $s)foreach((array)($s['bagOrders']??[]) as $po)if(($order['poNo']??'')!==''&&($po['poNo']??'')===$order['poNo'])$contractRef=(string)($s['contractRef']??'');
            $matched=false;foreach((array)($exports['contracts']??[]) as $c)if($contractRef!==''&&($c['ref']??'')===$contractRef){$matched=true;$closed=!empty($c['cancelled'])||!empty($c['completed'])||(bool)preg_match('/^(closed|completed|cancelled)$/i',(string)($c['status']??''));}
            if(!$matched)$reasons[]='No verified current export contract — review historical source';
            foreach((array)($exports['shipments']??[]) as $s)if(($order['ref']??'')!==''&&in_array($order['ref'],[$s['id']??null,$s['ref']??null],true))$closed=$closed||!empty($s['completed'])||(bool)preg_match('/^(closed|completed|cancelled)$/i',(string)($s['status']??''));
        }
        if($closed)$reasons[]='Completed/closed order — historical stock only';
        if($lot&&(!is_int($lot['committableQty'])||$lot['committableQty']<=0))$reasons[]=$lot['hold']?:'No available received bags';
        if(!$reasons&&$lot)$choices[]=['brand'=>$lot['brand'],'packingKg'=>$lot['packingKg'],'lotId'=>$lot['id'],'available'=>$lot['committableQty'],'exporterDealId'=>$order['exporterDealId']??'','contractRef'=>$order['contractRef']??'','owner'=>$order['externalOwnerName']??$scope['entity']];
        // Return diagnostics only for the requested company/location; never leak other books.
        if($lot)$diagnostics[]=['brand'=>$lot['brand'],'sourceId'=>$id,'reference'=>($order['sourceKind']??'')==='OTHER_EXPORTER'?($order['externalOwnerName']??'Fellow exporter'):(($order['poNo']??'')?:'Bag receipt'),'owner'=>$order['externalOwnerName']??$scope['entity'],'received'=>$lot['receivedQty'],'used'=>$lot['issuedQty'],'remaining'=>$lot['balanceBeforeUnallocated'],'reason'=>implode('. ',$reasons)?:'Available for production'];
    }
    return ['choices'=>$choices,'diagnostics'=>$diagnostics];
}

/** Read-only running order identities; labels never substitute for immutable source IDs. */
function oeb_production_orders(array $values,array $books,array $scope,string $date,array $inventory=[]): array {
    $root=json_decode($values['transtrade_export_v3_operational']??'{}',true,512,JSON_THROW_ON_ERROR);$rows=[];
    $closed=static fn($r)=>!empty($r['completed'])||!empty($r['closed'])||(bool)preg_match('/^(closed|completed)$/i',(string)($r['status']??''));
    $active=static fn($r)=>empty($r['cancelled'])&&!preg_match('/^(cancelled|deleted)$/i',(string)($r['status']??''))&&!$closed($r)&&($r['date']??'')<=$date;
    foreach((array)($books['exporterDeals']??[]) as $d){if(($d['direction']??'')!=='SALE'||($d['entity']??'')!==$scope['entity']||!oeb_active($d,(array)$root)||!$active($d)||($d['date']??'')>$date||(($d['commodity']??'RICE')!=='RICE'))continue;
        if(!empty($d['productionMillName'])&&bti_norm($d['productionMillName'])!==bti_norm($scope['millName']))continue;
        $rows[]=['orderId'=>'FELLOW|'.$d['id'],'sourceKind'=>'FELLOW','historicalClosed'=>$closed($d),'sourceId'=>$d['id'],'entity'=>$scope['entity'],'party'=>(string)($d['party']??''),'brand'=>(string)($d['brand']??''),'quantityMT'=>$d['quantityMT']??null,'product'=>(string)($d['product']??''),'packing'=>(string)($d['packing']??''),'contractRef'=>'','bagResponsibility'=>ba_physical_responsibility($d),'ready'=>!empty($d['productionReady']),'version'=>(int)($d['version']??0)];
    }
    $customers=[];foreach((array)($root['customers']??[]) as $c)$customers[(string)($c['id']??'')]=(string)($c['name']??'');
    foreach((array)($root['contracts']??[]) as $c){if(!$active($c)||(!empty($c['commodity'])&&!in_array(strtoupper((string)$c['commodity']),['RICE'],true))||preg_match('/\b(maize|corn|wheat|sesame)\b/i',(string)($c['product']??'')))continue;$entity=strtoupper((string)($c['seller']??'TTI'));if($entity==='TG'){$entity=strtoupper((string)($c['customsExporter']??''));foreach((array)($root['shipments']??[]) as $lot)if(($lot['contractRef']??'')===($c['ref']??'')&&strtoupper((string)($lot['documentParties']['exporterKey']??$lot['customs']['exporter']??''))===$scope['entity'])$entity=$scope['entity'];}if($entity!==$scope['entity'])continue;
        $ref=(string)($c['ref']??'');if($ref==='')continue;$packs=(array)($c['packings']??[]);if(!$packs)$packs=[[]];
        foreach($packs as $i=>$pack)$rows[]=['orderId'=>'EXPORT|'.$ref.'|'.$i,'sourceKind'=>'EXPORT','historicalClosed'=>$closed($c),'sourceId'=>$ref,'entity'=>$entity,'party'=>$customers[(string)($c['customerId']??'')]??(string)($c['customer']??''),'brand'=>(string)($pack['brand']??''),'quantityMT'=>$c['qty']??null,'product'=>(string)($c['product']??''),'packing'=>trim((string)($pack['size']??'').' '.(string)($pack['unit']??'')),'contractRef'=>$ref,'ready'=>true,'version'=>hash('sha256',json_encode([$c['packings']??[],$c['qty']??null,$c['status']??'']))];
    }
    foreach(bti_rows($values,'tt35localsales') as $r){if(($r['entity']??'TTI')!==$scope['entity']||!$active($r)||bti_norm($r['millName']??$r['mill']??'TTI Rice Mills')!==bti_norm($scope['millName']))continue;
        $qty=(float)($r['qty']??0);$loaded=array_sum(array_column((array)($r['loads']??[]),'kg'));if($qty>0&&$loaded>=$qty)continue;
        $id=(string)($r['ref']??$r['id']??'');if($id==='')continue;$rows[]=['orderId'=>'LOCAL|'.$id,'sourceKind'=>'LOCAL','historicalClosed'=>$closed($r)||($qty>0&&$loaded>=$qty),'sourceId'=>$id,'entity'=>$scope['entity'],'party'=>(string)($r['buyer']??$r['party']??'Local sale'),'brand'=>(string)($r['brand']??'Local Bags'),'quantityMT'=>$qty/1000,'product'=>(string)($r['product']??''),'packing'=>'','contractRef'=>'','ready'=>true,'version'=>hash('sha256',json_encode([$r['brand']??'',$r['qty']??0,$r['status']??'']))];
    }
    foreach((array)($inventory['lots']??[]) as $lot){
        $original=null;foreach(bti_rows($values,'tt30bags') as $bag)if((string)($bag['id']??'')===(string)($lot['originBagId']??''))$original=$bag;if(!$original)continue;
        if(($lot['committableQty']??0)<=0)continue;
        $dealId=(string)($original['exporterDealId']??'');if($dealId!==''&&(!isset($books['exporterDeals'][$dealId])||!oeb_active($books['exporterDeals'][$dealId],(array)$root)))continue;
        $ref=(string)($original['contractRef']??'');
        if($ref==='')foreach((array)($root['shipments']??[]) as $shipment)foreach((array)($shipment['bagOrders']??[]) as $po)if(!empty($original['poNo'])&&($po['poNo']??'')===$original['poNo'])$ref=(string)($shipment['contractRef']??'');
        $closed=false;foreach((array)($root['contracts']??[]) as $c)if($ref!==''&&($c['ref']??'')===$ref&&!$active($c))$closed=true;if($closed)continue;
        $represented=false;foreach($rows as $order)if(bti_norm($order['brand'])===bti_norm($lot['brand']??'')&&(($dealId!==''&&$order['sourceKind']==='FELLOW'&&$order['sourceId']===$dealId)||($ref!==''&&$order['contractRef']===$ref)))$represented=true;if($represented)continue;
        $rows[]=['orderId'=>'BAG|'.$lot['id'],'sourceKind'=>'BAG','sourceId'=>$lot['id'],'entity'=>$scope['entity'],'party'=>(string)($lot['externalOwnerName']??$scope['entity']),'brand'=>(string)($lot['brand']??''),'quantityMT'=>null,'product'=>'Received / ordered bag marking — SODA link not verified','packing'=>(string)($lot['packingSize']??''),'contractRef'=>'','bagSourceLotId'=>$lot['id'],'exporterDealId'=>$dealId,'ready'=>($lot['committableQty']??0)>0,'version'=>hash('sha256',json_encode($original))];
    }
    $parents=array_column($rows,null,'orderId');foreach(bti_rows($values,'tt35brandmeta') as $r){if(($r['kind']??'')!=='PRODUCTION_BRAND'||($r['entity']??'')!==$scope['entity']||($r['locationId']??'')!==$scope['locationId']||!isset($parents[$r['parentOrderId']??'']))continue;$parent=$parents[$r['parentOrderId']];$rows[]=array_replace($parent,['orderId'=>$r['id'],'parentOrderId'=>$r['parentOrderId'],'brand'=>$r['brand']]);}
    $originals=array_column(bti_rows($values,'tt30bags'),null,'id');
    foreach($rows as &$order){$order['bagsAvailable']=0;$order['bagsQuarantined']=0;$order['bagSourceKnown']=false;foreach((array)($inventory['lots']??[]) as $lot){if(bti_norm($lot['brand']??'')!==bti_norm($order['brand']))continue;$original=$originals[$lot['originBagId']??'']??[];$linked=($order['sourceKind']==='FELLOW'&&($lot['exporterDealId']??'')===$order['sourceId'])||($order['sourceKind']==='EXPORT'&&($original['contractRef']??'')===$order['sourceId'])||($order['sourceKind']==='BAG'&&$lot['id']===$order['sourceId']);if(!$linked)continue;$order['bagSourceKnown']=true;$order['bagsAvailable']+=is_int($lot['committableQty']??null)?max(0,$lot['committableQty']):0;$order['bagsQuarantined']+=(int)($lot['quarantinedQty']??0);}}unset($order);
    return $rows;
}
function oeb_register_production_brand(array &$values,array &$state,array $books,array $scope,array $body,array $user,string $now): array {
    $request=bt_request($state,$body,$user,'PRODUCTION_BRAND');if($request['prior'])return array_replace($request['prior']['result'],['replayed'=>true]);
    $orders=oeb_production_orders($values,$books,$scope,bt_date((string)($body['asOf']??(new DateTimeImmutable($now))->setTimezone(new DateTimeZone('Asia/Karachi'))->format('Y-m-d')),$now));$order=null;foreach($orders as $r)if($r['orderId']===($body['orderId']??''))$order=$r;
    if(!$order||isset($order['parentOrderId']))throw new DomainException('Choose the original running SODA in this company.');
    if((string)($body['version']??'')!==(string)$order['version'])throw new DomainException('The SODA changed. Refresh before registering its brand.');
    $brand=is_string($body['brand']??null)?trim($body['brand']):'';if($brand===''||mb_strlen($brand)>200||preg_match('/[\x00-\x1f]/',$brand))throw new DomainException('Enter the actual brand, up to 200 characters.');
    foreach($orders as $r)if(($r['parentOrderId']??$r['orderId'])===$order['orderId']&&bti_norm($r['brand'])===bti_norm($brand))return ['brand'=>$r,'replayed'=>true];
    $rows=bti_rows($values,'tt35brandmeta');$id='PB-'.substr(hash('sha256',$scope['entity'].'|'.$scope['locationId'].'|'.$order['orderId'].'|'.bti_norm($brand)),0,24);
    $record=['id'=>$id,'kind'=>'PRODUCTION_BRAND','entity'=>$scope['entity'],'locationId'=>$scope['locationId'],'millName'=>$scope['millName'],'parentOrderId'=>$order['orderId'],'asOfDate'=>(string)($body['asOf']??''),'sourceId'=>$order['sourceId'],'sourceKind'=>$order['sourceKind'],'brand'=>$brand,'createdAt'=>$now,'userId'=>(int)$user['id']];$rows[]=$record;$values['tt35brandmeta']=json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $result=['brand'=>array_replace($order,['orderId'=>$id,'parentOrderId'=>$order['orderId'],'brand'=>$brand]),'replayed'=>false];$state['requests'][$request['id']]=['fingerprint'=>$request['fingerprint'],'kind'=>'productionBrand','scope'=>$scope,'result'=>$result,'at'=>$now]+bt_actor($user);return $result;
}
