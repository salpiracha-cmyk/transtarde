<?php
declare(strict_types=1);
require_once __DIR__.'/bag_receipts_core.php';
require_once __DIR__.'/bag_arrangement_core.php';

/** External ownership is a physical custody dimension, never a TTI bag purchase. */
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
        if(!$reasons&&$lot)$choices[]=['brand'=>$lot['brand'],'packingKg'=>$lot['packingKg'],'lotId'=>$lot['id'],'available'=>$lot['committableQty'],'exporterDealId'=>$order['exporterDealId']??'','owner'=>$order['externalOwnerName']??$scope['entity']];
        // Return diagnostics only for the requested company/location; never leak other books.
        if($lot)$diagnostics[]=['brand'=>$lot['brand'],'sourceId'=>$id,'reference'=>$order['sodaReference']??$order['poNo']??'','owner'=>$order['externalOwnerName']??$scope['entity'],'received'=>$lot['receivedQty'],'used'=>$lot['issuedQty'],'remaining'=>$lot['balanceBeforeUnallocated'],'reason'=>implode('. ',$reasons)?:'Available for production'];
    }
    return ['choices'=>$choices,'diagnostics'=>$diagnostics];
}
