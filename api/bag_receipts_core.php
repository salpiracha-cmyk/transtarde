<?php
declare(strict_types=1);
require_once __DIR__.'/bag_inventory_core.php';
require_once __DIR__.'/bag_receipt_quantities.php';

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
    foreach($receipts as $receipt)if(bti_receipt_scope_conflict($receipt,$spec))throw new DomainException('Saved physical receipt owner, location or specification conflicts with its current PO. Reconcile the original receipt before another receipt.');
    foreach($receipts as $receipt)if(!empty($receipt['serverManaged'])&&($receipt['sourceSnapshot']??null)!=$spec)throw new DomainException('The PO owner, location or specification changed after receiving. Reconcile the original physical lot before another receipt.');
    $rawState=$values['tt41bagtransfers']??'{}';$state=is_string($rawState)?json_decode($rawState,true,512,JSON_THROW_ON_ERROR):$rawState;$events=array_values(array_filter((array)($state['receiptDispositions']??[]),static fn($r)=>in_array($r['receiptId']??'',array_column($receipts,'id'),true)));return ['order'=>$order,'scope'=>$spec,'receipts'=>$receipts,'dispositions'=>$events,'positions'=>array_map(static fn($r)=>['receiptId'=>$r['id']]+br_position($r,$events),$receipts),'fingerprint'=>bt_hash([$order,$receipts,$events])];
}
function br_flags(array $body): array {
    $flags=[];foreach(['printingMismatch','materialMismatch','qualityMismatch','tareMismatch'] as $key){if(!isset($body[$key])||!is_bool($body[$key]))throw new DomainException('Confirm each actual receipt specification check.');$flags[$key]=$body[$key];}return $flags;
}
function br_commit(array &$values,array &$state,array $masters,array $body,array $user,string $now,callable $authorize): array {
    $action=(string)($body['action']??'');if(!in_array($action,['receive_supplier','receipt_status','accept_corrected','return_supplier'],true))throw new DomainException('Choose an explicit supplier receipt action.');
    $request=bt_request($state,$body,$user,'SUPPLIER_'.$action);
    if($request['prior']){
        $prior=$request['prior'];if(($prior['kind']??'')!=='supplierReceipt')throw new DomainException('This request identity belongs to another movement.');$scope=$prior['scope'];
        $authorize($scope['entity'],$scope['millName'],$action==='receive_supplier'?'Create':'Edit',$scope['locationId']);
        $orders=bti_rows($values,'tt30bags');$matching=array_values(array_filter($orders,static fn($r)=>(string)($r['id']??'')===(string)$body['bagId']));
        return ['receipt'=>$prior['receipt'],'disposition'=>$prior['disposition']??null,'order'=>count($matching)===1?$matching[0]:$prior['order'],'replayed'=>true];
    }
    $source=br_source($values,$masters,(string)($body['bagId']??''));$scope=$source['scope'];
    if(($body['entity']??'')!==$scope['entity']||($body['locationId']??'')!==$scope['locationId'])throw new DomainException('The original receipt belongs to another company or physical location.');
    $authorize($scope['entity'],$scope['millName'],$action==='receive_supplier'?'Create':'Edit',$scope['locationId']);
    if(!hash_equals($source['fingerprint'],(string)($body['sourceFingerprint']??'')))throw new DomainException('The order or receipts changed. Review the original order again; your entry is retained.');
    $dispositionAction=in_array($action,['accept_corrected','return_supplier'],true);if($dispositionAction)$body['keepReceiptOpen']=!empty($source['order']['keepReceiptOpen']);
    if(!isset($body['keepReceiptOpen'])||!is_bool($body['keepReceiptOpen']))throw new DomainException('Confirm whether to keep the PO receipt open.');
    $orders=bti_rows($values,'tt30bags');$receipts=bti_rows($values,'tt33bagreceipts');$receipt=null;$disposition=null;
    if($action==='receive_supplier'){
        $qty=bt_qty($body['qty']??null);$date=bt_date((string)($body['date']??''),$now);$flags=br_flags($body);$quarantined=in_array(true,$flags,true);
        if(($body['inspected']??null)!==true)throw new DomainException('Confirm these bags were physically counted and inspected.');
        $actual=$body['actualTareGrams']??null;if($actual!==null&&(!is_numeric($actual)||is_bool($actual)||!is_finite((float)$actual)||(float)$actual<=0))throw new DomainException('Enter the actual positive measured empty-bag weight.');
        if($flags['tareMismatch']&&$actual===null)throw new DomainException('Record the actual measured empty-bag weight for a tare discrepancy.');
        $receipt=['id'=>'BR-'.substr(hash('sha256',$request['id']),0,24),'bagId'=>$source['order']['id'],'entity'=>$scope['entity'],'locationId'=>$scope['locationId'],'millName'=>$scope['millName'],'brand'=>$scope['brand'],'date'=>$date,'qty'=>$qty,'actualTareGrams'=>$actual===null?null:(float)$actual,'serverManaged'=>true,'bagReceiptVersion'=>1,'sourceSnapshot'=>$scope,'inspectionStatus'=>$quarantined?'Quarantined':'Accepted','acceptedQty'=>$quarantined?0:$qty,'quarantinedQty'=>$quarantined?$qty:0,'inspected'=>true,'at'=>$now]+$flags+bt_actor($user);
        $receipts[]=$receipt;
    }
    if($dispositionAction){
        $found=array_values(array_filter($source['receipts'],static fn($r)=>(string)($r['id']??'')===(string)($body['receiptId']??'')));if(count($found)!==1||empty($found[0]['bagReceiptVersion']))throw new DomainException('Select one acknowledged quarantined supplier receipt.');$receipt=$found[0];
        $position=br_position($receipt,(array)($state['receiptDispositions']??[]));$qty=bt_qty($body['qty']??null);if($qty>$position['quarantinedQty'])throw new DomainException('The disposition exceeds this receipt quantity still in quarantine.');
        $date=bt_date((string)($body['date']??''),$now);if($date<$receipt['date'])throw new DomainException('Physical correction or return cannot precede the original receipt.');$reason=trim((string)($body['reason']??''));if(strlen($reason)<10||strlen($reason)>1000)throw new DomainException('Record the actual physical correction or supplier return evidence (10–1000 characters).');
        $measured=null;
        if($action==='accept_corrected'){
            if(($body['inspected']??null)!==true||($body['matchesOriginalSpecification']??null)!==true)throw new DomainException('Only physically corrected, reinspected bags matching the original specification may become available. Unchanged discrepancies remain quarantined.');
            if(!empty($receipt['tareMismatch'])){$agreed=preg_match('/^\s*([0-9]+(?:\.[0-9]+)?)\s*(?:g|grams?)\s*$/i',(string)($receipt['sourceSnapshot']['tare']??''),$m)?(float)$m[1]:0;$actual=$body['actualTareGrams']??null;if(!is_numeric($actual)||is_bool($actual)||$agreed<=0||!is_finite((float)$actual)||abs((float)$actual-$agreed)>.000001)throw new DomainException('Record the corrected measured tare matching the original agreed tare; an unchanged tare discrepancy cannot be released.');$measured=(float)$actual;}
        }elseif(($body['physicallyReturned']??null)!==true)throw new DomainException('Confirm these bags were physically returned to the supplier.');
        $disposition=['id'=>'BRD-'.substr(hash('sha256',$request['id']),0,24),'receiptId'=>$receipt['id'],'bagId'=>$source['order']['id'],'entity'=>$scope['entity'],'locationId'=>$scope['locationId'],'date'=>$date,'action'=>$action,'qty'=>$qty,'reason'=>$reason,'actualTareGrams'=>$measured,'at'=>$now]+$request['actor'];$state['receiptDispositions'][]=$disposition;
    }
    foreach($orders as &$order){if((string)($order['id']??'')===(string)$source['order']['id']){
        $counter=bti_count($order['received']??0);if($counter===null)throw new DomainException('Reconcile the actual received counter before recording another receipt.');
        $order['received']=$counter+($action==='receive_supplier'?($receipt['qty']??0):0);if($order['received']>9007199254740991)throw new DomainException('Received bag quantity exceeds the supported whole count.');
        $order['bagReceiptVersion']=1;$order['bagReceiptScope']=$scope;$order['entity']=$scope['entity'];$order['locationId']=$scope['locationId'];
        $order['keepReceiptOpen']=$body['keepReceiptOpen'];$saved=$order;
    }}unset($order);
    // One PO's administrative keep-open choice applies across its lines at this authorised location only.
    foreach($orders as &$order)if(($order['poNo']??'')===$scope['poNo']&&(string)($order['id']??'')!==(string)$source['order']['id']){
        try{$other=br_source($values,$masters,(string)($order['id']??''));if($other['scope']['entity']===$scope['entity']&&$other['scope']['locationId']===$scope['locationId'])$order['keepReceiptOpen']=$body['keepReceiptOpen'];}catch(DomainException){/* Unverified other sources remain unchanged. */}
    }unset($order);
    $values['tt30bags']=json_encode($orders,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$values['tt33bagreceipts']=json_encode($receipts,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $state['requests'][$request['id']]=['fingerprint'=>$request['fingerprint'],'kind'=>'supplierReceipt','receipt'=>$receipt,'disposition'=>$disposition,'order'=>$saved,'scope'=>$scope,'action'=>$action,'at'=>$now]+$request['actor'];$state['audit'][]=['bagId'=>$source['order']['id'],'receiptId'=>$receipt['id']??null,'action'=>$action,'at'=>$now]+$request['actor'];
    return ['receipt'=>$receipt,'disposition'=>$disposition,'order'=>$saved,'replayed'=>false];
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
            $physical=$prior&&(!empty($prior['received'])||array_filter(bti_rows($values,'tt33bagreceipts'),static fn($receipt)=>(string)($receipt['bagId']??'')===$id));
            if($physical)foreach(['entity','locationId','mill','millName','brand','size','bagType','poNo','poLineKey','supplier','tare'] as $field)if(($prior[$field]??null)!=($row[$field]??null))throw new DomainException('Existing physical receipt sources require explicit reconciliation before changing owner, location or specification.');
            if(!empty($prior['bagReceiptVersion']))foreach(['bagReceiptVersion','keepReceiptOpen','bagReceiptScope','entity','locationId','mill','millName','brand','size','bagType','poNo','poLineKey','supplier','tare'] as $field)if(($prior[$field]??null)!=($row[$field]??null))throw new DomainException('An acknowledged physical lot cannot be moved or relabelled by changing its PO. Reconcile the original lot.');
        }
    }
    foreach($old as $row)if(!isset($seen[(string)($row['id']??'')])&&($key==='tt33bagreceipts'||!empty($row['bagReceiptVersion'])||!empty($row['received'])||array_filter(bti_rows($values,'tt33bagreceipts'),static fn($receipt)=>(string)($receipt['bagId']??'')===(string)($row['id']??''))))throw new DomainException('Original physical receipt history cannot be deleted.');
    return $json;
}
