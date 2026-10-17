<?php
declare(strict_types=1);
require_once __DIR__.'/bag_inventory_core.php';

/** Receipt facts retain their original owner, physical location and issued specification. */
function br_source(array $values,array $masters,string $bagId): array {
    $found=array_values(array_filter(bti_rows($values,'tt30bags'),static fn($r)=>is_array($r)&&(string)($r['id']??'')===$bagId));
    if(count($found)!==1||!preg_match('/^[1-9][0-9]*$/D',$bagId))throw new DomainException('Select one original bag order with an unambiguous identity.');
    $order=$found[0];$location=bti_location($masters,(string)($order['millName']??$order['mill']??''),(string)($order['locationId']??$order['bagReceiptScope']['locationId']??''));
    if(!$location||$location['status']!=='Active')throw new DomainException('Resolve the actual active physical receipt location.');
    $raw=$values['transtrade_export_v3_operational']??'{}';$exports=is_string($raw)?json_decode($raw,true,512,JSON_THROW_ON_ERROR):$raw;
    $candidates=array_values(bti_po_sources((array)$exports)[(string)($order['poNo']??'').'|'.bqa_mill_line_key($order)]??[]);
    $supplier=bti_norm($order['supplier']??'');if($supplier!=='')$candidates=array_values(array_filter($candidates,static fn($r)=>bti_norm($r['supplier'])===$supplier));
    $po=count($candidates)===1?$candidates[0]:[];$entity=(string)($order['entity']??$po['entity']??'');
    $spec=['originBagId'=>$bagId,'entity'=>$entity]+$location+['poNo'=>(string)($order['poNo']??''),'poLineKey'=>bqa_mill_line_key($order),'brand'=>(string)($order['brand']??''),'packingSize'=>(string)($order['size']??$po['packingSize']??''),'bagType'=>(string)($order['bagType']??$po['bagType']??''),'supplier'=>(string)($order['supplier']??$po['supplier']??''),'tare'=>(string)($order['tare']??'')];
    if(!in_array($entity,['TTI','BRM'],true)||$spec['poNo']===''||$spec['poLineKey']===''||$spec['brand']===''||$spec['bagType']===''||bti_packing_kg($spec['packingSize'])===null)throw new DomainException('Original PO, stock owner, brand, packing and material must be verified before receiving.');
    if($po&&($po['entity']!==$entity||bti_norm($po['brand'])!==bti_norm($spec['brand'])||bti_norm($po['bagType'])!==bti_norm($spec['bagType'])||bti_packing_kg($po['packingSize'])!==bti_packing_kg($spec['packingSize'])))throw new DomainException('The Mill receipt source disagrees with its issued PO. Resolve the original source first.');
    $receipts=array_values(array_filter(bti_rows($values,'tt33bagreceipts'),static fn($r)=>(string)($r['bagId']??'')===$bagId));
    foreach($receipts as $receipt)if(!empty($receipt['serverManaged'])&&($receipt['sourceSnapshot']??null)!=$spec)throw new DomainException('The PO owner, location or specification changed after receiving. Reconcile the original physical lot before another receipt.');
    return ['order'=>$order,'scope'=>$spec,'receipts'=>$receipts,'fingerprint'=>bt_hash([$order,$receipts])];
}
function br_flags(array $body): array {
    $flags=[];foreach(['printingMismatch','materialMismatch','qualityMismatch','tareMismatch'] as $key){if(!isset($body[$key])||!is_bool($body[$key]))throw new DomainException('Confirm each actual receipt specification check.');$flags[$key]=$body[$key];}return $flags;
}
function br_commit(array &$values,array &$state,array $masters,array $body,array $user,string $now,callable $authorize): array {
    $action=(string)($body['action']??'');if(!in_array($action,['receive_supplier','receipt_status'],true))throw new DomainException('Choose an explicit supplier receipt action.');
    $request=bt_request($state,$body,$user,'SUPPLIER_'.$action);
    if($request['prior']){
        $prior=$request['prior'];if(($prior['kind']??'')!=='supplierReceipt')throw new DomainException('This request identity belongs to another movement.');$scope=$prior['scope'];
        $authorize($scope['entity'],$scope['millName'],$action==='receipt_status'?'Edit':'Create',$scope['locationId']);
        $orders=bti_rows($values,'tt30bags');$matching=array_values(array_filter($orders,static fn($r)=>(string)($r['id']??'')===(string)$body['bagId']));
        return ['receipt'=>$prior['receipt'],'order'=>count($matching)===1?$matching[0]:$prior['order'],'replayed'=>true];
    }
    $source=br_source($values,$masters,(string)($body['bagId']??''));$scope=$source['scope'];
    if(($body['entity']??'')!==$scope['entity']||($body['locationId']??'')!==$scope['locationId'])throw new DomainException('The original receipt belongs to another company or physical location.');
    $authorize($scope['entity'],$scope['millName'],$action==='receipt_status'?'Edit':'Create',$scope['locationId']);
    if(!hash_equals($source['fingerprint'],(string)($body['sourceFingerprint']??'')))throw new DomainException('The order or receipts changed. Review the original order again; your entry is retained.');
    if(!isset($body['keepReceiptOpen'])||!is_bool($body['keepReceiptOpen']))throw new DomainException('Confirm whether to keep the PO receipt open.');
    $orders=bti_rows($values,'tt30bags');$receipts=bti_rows($values,'tt33bagreceipts');$receipt=null;
    if($action==='receive_supplier'){
        $qty=bt_qty($body['qty']??null);$date=bt_date((string)($body['date']??''),$now);$flags=br_flags($body);$quarantined=in_array(true,$flags,true);
        if(($body['inspected']??null)!==true)throw new DomainException('Confirm these bags were physically counted and inspected.');
        $actual=$body['actualTareGrams']??null;if($actual!==null&&(!is_numeric($actual)||is_bool($actual)||!is_finite((float)$actual)||(float)$actual<=0))throw new DomainException('Enter the actual positive measured empty-bag weight.');
        if($flags['tareMismatch']&&$actual===null)throw new DomainException('Record the actual measured empty-bag weight for a tare discrepancy.');
        $receipt=['id'=>'BR-'.substr(hash('sha256',$request['id']),0,24),'bagId'=>$source['order']['id'],'entity'=>$scope['entity'],'locationId'=>$scope['locationId'],'millName'=>$scope['millName'],'brand'=>$scope['brand'],'date'=>$date,'qty'=>$qty,'actualTareGrams'=>$actual===null?null:(float)$actual,'serverManaged'=>true,'bagReceiptVersion'=>1,'sourceSnapshot'=>$scope,'inspectionStatus'=>$quarantined?'Quarantined':'Accepted','acceptedQty'=>$quarantined?0:$qty,'quarantinedQty'=>$quarantined?$qty:0,'inspected'=>true,'at'=>$now]+$flags+bt_actor($user);
        $receipts[]=$receipt;
    }
    foreach($orders as &$order){if((string)($order['id']??'')===(string)$source['order']['id']){
        $counter=bti_count($order['received']??0);if($counter===null)throw new DomainException('Reconcile the actual received counter before recording another receipt.');
        $order['received']=$counter+($receipt['qty']??0);if($order['received']>9007199254740991)throw new DomainException('Received bag quantity exceeds the supported whole count.');
        $order['bagReceiptVersion']=1;$order['bagReceiptScope']=$scope;$order['entity']=$scope['entity'];$order['locationId']=$scope['locationId'];
        $order['keepReceiptOpen']=$body['keepReceiptOpen'];$saved=$order;
    }}unset($order);
    // One PO's administrative keep-open choice applies across its lines at this authorised location only.
    foreach($orders as &$order)if(($order['poNo']??'')===$scope['poNo']&&(string)($order['id']??'')!==(string)$source['order']['id']){
        try{$other=br_source($values,$masters,(string)($order['id']??''));if($other['scope']['entity']===$scope['entity']&&$other['scope']['locationId']===$scope['locationId'])$order['keepReceiptOpen']=$body['keepReceiptOpen'];}catch(DomainException){/* Unverified other sources remain unchanged. */}
    }unset($order);
    $values['tt30bags']=json_encode($orders,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$values['tt33bagreceipts']=json_encode($receipts,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $state['requests'][$request['id']]=['fingerprint'=>$request['fingerprint'],'kind'=>'supplierReceipt','receipt'=>$receipt,'order'=>$saved,'scope'=>$scope,'action'=>$action,'at'=>$now]+$request['actor'];$state['audit'][]=['bagId'=>$source['order']['id'],'receiptId'=>$receipt['id']??null,'action'=>$action,'at'=>$now]+$request['actor'];
    return ['receipt'=>$receipt,'order'=>$saved,'replayed'=>false];
}
/** Generic collection writers must not erase acknowledged physical facts or attribute new ones. */
function br_guard_write(array $values,string $key,string $json): string {
    $incoming=json_decode($json,true,512,JSON_THROW_ON_ERROR);if(!is_array($incoming)||!array_is_list($incoming))throw new DomainException('Bag source records must be a list.');
    $old=bti_rows($values,$key);$index=[];foreach($old as $row)$index[(string)($row['id']??'')][]=$row;$seen=[];
    foreach($incoming as $row){if(!is_array($row))throw new DomainException('Bag source records require original identities.');$id=(string)($row['id']??'');$prior=count($index[$id]??[])===1?$index[$id][0]:null;if($id===''||isset($seen[$id]))throw new DomainException('Each bag source needs one original unique identity.');$seen[$id]=true;
        if($key==='tt33bagreceipts'){
            if(!$prior||$prior!=$row)throw new DomainException('Actual bag receipts are saved through the acknowledged receipt workflow. Original receipt facts cannot be rewritten.');
        }else{
            if(!$prior&&(!empty($row['received'])||!empty($row['bagReceiptVersion'])))throw new DomainException('A new bag order cannot invent received stock. Use the actual receipt workflow.');
            if($prior&&($prior['received']??0)!=($row['received']??0))throw new DomainException('Received bags are maintained by the atomic receipt workflow.');
            if(!empty($prior['bagReceiptVersion']))foreach(['bagReceiptVersion','bagReceiptScope','entity','locationId','mill','millName','brand','size','bagType','poNo','poLineKey','supplier','tare'] as $field)if(($prior[$field]??null)!=($row[$field]??null))throw new DomainException('An acknowledged physical lot cannot be moved or relabelled by changing its PO. Reconcile the original lot.');
        }
    }
    foreach($old as $row)if(!isset($seen[(string)($row['id']??'')])&&($key==='tt33bagreceipts'||!empty($row['bagReceiptVersion'])))throw new DomainException('Original physical receipt history cannot be deleted.');
    return $json;
}
