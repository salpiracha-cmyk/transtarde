<?php
declare(strict_types=1);

/** Physical bag movements only. Purchase orders and financial books stay independent. */
function bt_hash(array $value): string {
    $sort=static function($v)use(&$sort){if(!is_array($v))return $v;if(!array_is_list($v))ksort($v);foreach($v as &$x)$x=$sort($x);unset($x);return $v;};
    return hash('sha256',json_encode($sort($value),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}
function bt_qty(mixed $value): int {
    if(!is_int($value)&&!is_float($value)&&!(is_string($value)&&ctype_digit($value)))throw new DomainException('Enter a positive whole number of bags.');
    $n=(float)$value;if(!is_finite($n)||$n<=0||$n>9007199254740991||floor($n)!==$n)throw new DomainException('Enter a positive whole number of bags.');return (int)$n;
}
function bt_date(string $date,string $now): string {
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);$errors=DateTimeImmutable::getLastErrors();
    if(!$d||($errors&&($errors['warning_count']||$errors['error_count']))||$d->format('Y-m-d')!==$date)throw new DomainException('Enter the actual movement date.');
    if($date>(new DateTimeImmutable($now))->setTimezone(new DateTimeZone('Asia/Karachi'))->format('Y-m-d'))throw new DomainException('A physical stock movement cannot be dated in the future.');return $date;
}
function bt_actor(array $user): array {
    if((int)($user['id']??0)<=0)throw new DomainException('The actual authorised actor is required.');
    return ['userId'=>(int)$user['id'],'by'=>(string)($user['full_name']??$user['username']??'')];
}
function bt_request(array $state,array $body,array $user,string $action): array {
    $actor=bt_actor($user);$key=(string)($body['requestKey']??'');if(!preg_match('/^[A-Za-z0-9_-]{16,120}$/D',$key))throw new DomainException('A stable movement request identity is required.');
    unset($body['csrf']);$hash=bt_hash(['action'=>$action,'body'=>$body]);$requestId=$actor['userId'].'|'.$key;$prior=$state['requests'][$requestId]??null;
    if(is_array($prior)&&!hash_equals((string)$prior['fingerprint'],$hash))throw new DomainException('This movement request identity was already used for different facts.');
    return ['id'=>$requestId,'fingerprint'=>$hash,'prior'=>$prior,'actor'=>$actor];
}
function bt_totals(array $transfer): array {
    $totals=[];foreach($transfer['lines'] as $line)$totals[$line['lineId']]=['dispatched'=>(int)$line['qty'],'received'=>0,'returned'=>0];
    foreach(['receipts'=>'received','returns'=>'returned'] as $collection=>$field)foreach((array)($transfer[$collection]??[]) as $event)foreach($event['lines'] as $line){
        if(!isset($totals[$line['lineId']]))throw new DomainException('Movement history contains an unknown source line.');$totals[$line['lineId']][$field]+=(int)$line['qty'];
    }
    foreach($totals as &$line){$line['inTransit']=$line['dispatched']-$line['received']-$line['returned'];if($line['inTransit']<0)throw new DomainException('Movement history exceeds its dispatched quantity.');}unset($line);
    return $totals;
}
function bt_status(array $transfer): string {
    $rows=bt_totals($transfer);$pending=array_sum(array_column($rows,'inTransit'));$received=array_sum(array_column($rows,'received'));$returned=array_sum(array_column($rows,'returned'));
    return $pending>0?($received||$returned?'Partly received / returned — balance in transit':'In transit'):($returned?($received?'Closed — received and returned':'Returned to source'):'Received');
}
function bt_replay(array $state,array $request): ?array {
    if(!$request['prior'])return null;$id=(string)$request['prior']['transferId'];$record=$state['transfers'][$id]??null;
    if(!is_array($record))throw new DomainException('The saved movement request has no linked transfer. Reconcile its original record.');
    return ['record'=>$record,'status'=>bt_status($record),'replayed'=>true];
}
function bt_commit_request(array &$state,array $request,array $record,string $action,string $now): void {
    $state['requests'][$request['id']]=['fingerprint'=>$request['fingerprint'],'transferId'=>$record['id'],'action'=>$action,'at'=>$now]+$request['actor'];
    $state['audit'][]=['transferId'=>$record['id'],'action'=>$action,'at'=>$now]+$request['actor'];
}
/** Inventory is recomputed by the caller under its operations write lock, never supplied by a client. */
function bt_dispatch(array &$state,array $inventory,array $destination,array $body,array $user,string $now,string $id,callable $authorize): array {
    $request=bt_request($state,$body,$user,'DISPATCH');$source=(array)($inventory['scope']??[]);$entity=(string)($source['entity']??'');
    if(!in_array($entity,['TTI','BRM'],true)||$entity!==($body['entity']??''))throw new DomainException('Select the actual source stock owner.');
    if(empty($source['locationId'])||(string)($body['sourceLocationId']??'')!==(string)$source['locationId'])throw new DomainException('The reviewed source location identity changed.');
    $authorize($entity,(string)($source['millName']??''),'Create');
    if($replay=bt_replay($state,$request))return $replay;
    if($id===''||isset($state['transfers'][$id]))throw new DomainException('A unique transfer identity is required.');
    $date=bt_date((string)($body['date']??''),$now);
    if(($inventory['asOfDate']??'')!==$date||!preg_match('/^[a-f0-9]{64}$/D',(string)($inventory['fingerprint']??''))||!hash_equals((string)($inventory['fingerprint']??''),(string)($body['sourceFingerprint']??'')))throw new DomainException('Source stock changed or was not reviewed for the dispatch date. Review it again.');
    if(empty($destination['id'])||empty($destination['name'])||($destination['status']??'')!=='Active'||(string)$destination['id']===(string)($source['locationId']??''))throw new DomainException('Select another active destination mill from the location master.');
    if((string)($body['destinationId']??'')!==(string)$destination['id'])throw new DomainException('The reviewed destination identity changed.');
    $allocations=$body['allocations']??null;if(!is_array($allocations)||!array_is_list($allocations)||!$allocations)throw new DomainException('Select actual source bag lots and quantities.');
    $lines=[];$seen=[];
    foreach($allocations as $a){
        $lotId=(string)($a['lotId']??'');if($lotId===''||isset($seen[$lotId]))throw new DomainException('Select each actual source lot once.');$seen[$lotId]=true;
        $lot=$inventory['lots'][$lotId]??null;if(!is_array($lot)||($lot['entity']??'')!==$entity||($lot['locationId']??'')!==($source['locationId']??''))throw new DomainException('The source lot does not belong to this company and mill.');
        if(!empty($lot['hold'])||empty($lot['originBagId'])||empty($lot['skuKey']))throw new DomainException('Resolve the source lot identity, specifications or unallocated consumption before dispatch.');
        $qty=bt_qty($a['qty']??null);if(!isset($lot['availableQty'])||!is_int($lot['availableQty'])||$lot['availableQty']<$qty)throw new DomainException('Insufficient verified source bags in the selected lot.');
        $lineId='BTL-'.substr(hash('sha256',$id.'|'.$lotId),0,24);
        $lines[]=['lineId'=>$lineId,'sourceLotId'=>$lotId,'originBagId'=>(string)$lot['originBagId'],'qty'=>$qty,'sourceSnapshot'=>array_intersect_key($lot,array_flip(['originBagId','poNo','poLineKey','brand','packingSize','bagType','skuKey','entity','locationId','millName']))];
    }
    $record=['id'=>$id,'entity'=>$entity,'date'=>$date,'source'=>$source,'destination'=>array_intersect_key($destination,array_flip(['id','name'])),'sourceFingerprint'=>$inventory['fingerprint'],'lines'=>$lines,'receipts'=>[],'returns'=>[],'createdAt'=>$now,'createdBy'=>$request['actor']];
    $state['transfers'][$id]=$record;bt_commit_request($state,$request,$record,'DISPATCH',$now);
    return ['record'=>$record,'status'=>bt_status($record),'replayed'=>false];
}
/** Destination receipt and physical return are new dated facts, never edits to dispatch or a PO receipt. */
function bt_receive(array &$state,array $body,array $user,string $now,callable $authorize,bool $returnToSource=false): array {
    $action=$returnToSource?'RETURN_TO_SOURCE':'RECEIVE';$request=bt_request($state,$body,$user,$action);$id=(string)($body['transferId']??'');$record=$state['transfers'][$id]??null;
    if(!is_array($record)||($record['entity']??'')!==($body['entity']??''))throw new DomainException('The transfer was not found in the selected stock owner.');
    $mill=$returnToSource?(string)$record['source']['millName']:(string)$record['destination']['name'];$authorize($record['entity'],$mill,'Create');
    if($replay=bt_replay($state,$request))return $replay;
    $date=bt_date((string)($body['date']??''),$now);if($date<$record['date'])throw new DomainException('A receipt or return cannot precede dispatch.');
    $reason=trim((string)($body['reason']??''));if($returnToSource&&strlen($reason)<3)throw new DomainException('Record why the bags were physically returned and received at the source.');
    if(strlen($reason)>500)throw new DomainException('Keep the movement reason within 500 characters.');
    $allocations=$body['allocations']??null;if(!is_array($allocations)||!array_is_list($allocations)||!$allocations)throw new DomainException('Enter the actual quantity received for each source line.');
    $totals=bt_totals($record);$seen=[];$lines=[];
    foreach($allocations as $a){$lineId=(string)($a['lineId']??'');if(!isset($totals[$lineId])||isset($seen[$lineId]))throw new DomainException('Select each exact transfer source line once.');$seen[$lineId]=true;$qty=bt_qty($a['qty']??null);
        if($qty>$totals[$lineId]['inTransit'])throw new DomainException('The received quantity exceeds this line remaining in transit. Refresh the transfer.');$lines[]=['lineId'=>$lineId,'qty'=>$qty];}
    $event=['id'=>'BTE-'.substr(hash('sha256',$request['id']),0,24),'date'=>$date,'lines'=>$lines,'reason'=>$reason,'at'=>$now]+$request['actor'];
    $collection=$returnToSource?'returns':'receipts';$record[$collection][]=$event;$state['transfers'][$id]=$record;bt_commit_request($state,$request,$record,$action,$now);
    return ['record'=>$record,'status'=>bt_status($record),'replayed'=>false];
}
