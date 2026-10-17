<?php
declare(strict_types=1);
require_once __DIR__.'/bag_transfer_core.php';
require_once __DIR__.'/bag_acceptance_core.php';

function bti_rows(array $values,string $key): array {
    $v=$values[$key]??'[]';if(is_string($v))$v=json_decode($v,true,512,JSON_THROW_ON_ERROR);
    if(!is_array($v)||!array_is_list($v))throw new DomainException('Invalid saved bag source collection: '.$key);return $v;
}
function bti_norm(mixed $v): string {return strtolower(trim((string)preg_replace('/\s+/',' ',(string)$v)));}
function bti_date(mixed $v): string {
    $v=(string)$v;$d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);$errors=DateTimeImmutable::getLastErrors();
    return $d&&(!$errors||(!$errors['warning_count']&&!$errors['error_count']))&&$d->format('Y-m-d')===$v?$v:'';
}
function bti_count(mixed $v): ?int {
    if(!is_numeric($v)||is_bool($v))return null;$n=(float)$v;return is_finite($n)&&$n>=0&&$n<=9007199254740991&&floor($n)===$n?(int)$n:null;
}
function bti_location(array $masters,string $name,string $id=''): ?array {
    $matches=[];foreach((array)($masters['mills']??[]) as $r){$v=array_values((array)($r['values']??[]));
        if($id!==''?(string)($r['id']??'')!==$id:bti_norm($v[0]??'')!==bti_norm($name))continue;
        $matches[]=['locationId'=>(string)$r['id'],'millName'=>(string)$v[0],'status'=>(string)($v[5]??'')];}
    return count($matches)===1?$matches[0]:null;
}
/** Link an original issued PO line. Missing owner/material is held, never defaulted to TTI or PP. */
function bti_po_sources(array $exports): array {
    $contracts=[];foreach((array)($exports['contracts']??[]) as $c)if(is_array($c)&&isset($c['ref']))$contracts[(string)$c['ref']]=$c;
    $map=[];foreach((array)($exports['shipments']??[]) as $s){if(!is_array($s))continue;$c=$contracts[(string)($s['contractRef']??'')]??[];$seller=strtoupper((string)($s['seller']??$c['seller']??''));
        $owner=$seller==='TG'?strtoupper((string)($s['customs']['exporter']??$c['customsExporter']??'')):$seller;
        foreach((array)($s['bagOrders']??[]) as $po){if(!is_array($po)||empty($po['poNo']))continue;
            $lines=(array)($po['lines']??[]);if(!$lines)$lines=[['brand'=>$po['brand']??'','type'=>$po['type']??'','size'=>$po['packSize']??'','unit'=>$po['unit']??'','masterBag'=>$po['masterBag']??[]]];
            foreach(array_values($lines) as $i=>$line){if(!is_array($line))continue;$base=['entity'=>$owner,'supplier'=>(string)($po['supplier']??''),'brand'=>(string)($line['brand']??''),'bagType'=>(string)($line['type']??''),'packingSize'=>trim((string)($line['size']??'').' '.(string)($line['unit']??''))];
                $key=(string)$po['poNo'].'|L'.($i+1);$map[$key][bt_hash($base)]=$base;
                if(!empty($line['masterBag']['enabled'])){$master=$base;$master['brand'].=' — MASTER';$master['bagType']='PP Master Bag';$master['packingSize']=(string)($line['masterBag']['size']??'');$map[$key.'-MASTER'][bt_hash($master)]=$master;}
            }
        }
    }return $map;
}
function bti_run_location(array $masters,array $run): ?array {
    $saved=!empty($run['bagAllocationVersion'])?(array)($run['bagAllocationScope']??[]):[];
    return bti_location($masters,(string)($run['millName']??$run['mill']??$saved['millName']??''),(string)(($run['locationId']??'')?:($saved['locationId']??'')));
}
function bti_packing_kg(string $text): ?float {
    if(!preg_match('/^\s*([0-9]+(?:\.[0-9]+)?)\s*(kg|kgs|kilograms?|lb|lbs|pounds?)\s*$/i',$text,$m))return null;
    $n=(float)$m[1];if($n<=0)return null;return round($n*(preg_match('/^(lb|pound)/i',$m[2])?.45359237:1),8);
}
function bti_usage(array $production): array {
    $out=[];foreach((array)($production['rows']??[]) as $r){if(!is_array($r)||!empty($r['noStockPost'])||!empty($r['managedReconciliation']))continue;
        if(!preg_match('/^(?:Ready Rice|B2)\s*—\s*(.+)$/u',(string)($r['product']??''),$m))continue;$brand=trim($m[1]);if(in_array(strtoupper($brand),['LOCAL','LOCAL BAGS'],true))continue;
        $q=bti_count($r['actualBagCount']??$r['bags']??0);if($q===0)continue;if($q===null)throw new DomainException('Saved production bags are not a whole quantity.');
        $kg=is_numeric($r['bagWeight']??null)&&(float)$r['bagWeight']>0?round((float)$r['bagWeight'],8):null;
        $key=bti_norm($brand).'|'.($kg===null?'UNKNOWN':(string)$kg);$out[$key]=['brand'=>$brand,'packingKg'=>$kg,'qty'=>($out[$key]['qty']??0)+$q];
    }return $out;
}
/** A later receipt can never become the source of older production. */
function bti_capacity_at(array $lot,string $date,array $movements): ?int {
    if($date===''||($lot['undatedReceivedQty']??0)>0)return null;$qty=0;
    foreach($movements as $m){if($m['lotId']!==$lot['id'])continue;if($m['date']==='')return null;if($m['date']<=$date)$qty+=(int)$m['qty'];}
    return $qty<0?null:$qty;
}
/** A saved allocation must equal actual recorded packing quantities, with the same owner/location. */
function bti_validate_allocations(array $run,array $allocations,array $lots,array $masters,bool $preservedSnapshot=false): void {
    $location=$preservedSnapshot?bti_run_location($masters,$run):bti_location($masters,(string)($run['millName']??$run['mill']??''),(string)($run['locationId']??''));
    if(!$location||!in_array($run['entity']??'',['TTI','BRM'],true))throw new DomainException('Production allocation company/location is unverified.');
    $required=bti_usage($run);$actual=[];$seen=[];
    foreach($allocations as $a){$id=(string)($a['lotId']??'');$lot=$lots[$id]??null;$q=bti_count($a['qty']??null);
        if(!$lot||$q===null||$q===0||isset($seen[$id])||$lot['entity']!==$run['entity']||$lot['locationId']!==$location['locationId'])throw new DomainException('Saved production allocation does not match one actual company/location source lot.');$seen[$id]=true;
        $proof=$preservedSnapshot?(array)($a['sourceSnapshot']??$lot):$lot;$kg=bti_packing_kg((string)($proof['packingSize']??''));if($kg===null)throw new DomainException('Saved production allocation packing weight is unverified.');
        $key=bti_norm($proof['brand']??'').'|'.(string)$kg;$actual[$key]=($actual[$key]??0)+$q;
    }
    $expected=[];foreach($required as $key=>$row)$expected[$key]=$row['qty'];ksort($expected);ksort($actual);
    if($expected!==$actual)throw new DomainException('Actual production bags and original-source allocations disagree.');
}
function bti_lot_id(string $bagId,string $location): string {return 'PO|'.$bagId.'|'.$location;}
function bti_hold(array &$lot,string $why): void {if(!in_array($why,$lot['holds'],true))$lot['holds'][]=$why;}
/** Read-only projection of actual source counters, dated receipts, production and acknowledged transfers. */
function bti_project(array $values,array $masters,array $scope,string $asOf,string $today,array $state=[],?string $excludeProductionId=null): array {
    if(!bti_date($asOf)||!bti_date($today))throw new DomainException('Choose a valid bag stock review date.');
    $raw=$values['transtrade_export_v3_operational']??'{}';$exports=is_string($raw)?json_decode($raw,true,512,JSON_THROW_ON_ERROR):$raw;$poSources=bti_po_sources((array)$exports);
    $orders=bti_rows($values,'tt30bags');$receipts=bti_rows($values,'tt33bagreceipts');$production=bti_rows($values,'tt30prod');$issues=bti_rows($values,'tt36bagissues');
    $lots=[];$warnings=[];$unassigned=[];$unallocated=[];$movements=[];$orderIds=[];$receiptIds=[];$index=[];
    foreach($orders as $r)if(is_array($r))$orderIds[(string)($r['id']??'')]=($orderIds[(string)($r['id']??'')]??0)+1;
    foreach($receipts as $r)if(is_array($r))$index[(string)($r['bagId']??'')][]=$r;
    $blank=static fn()=>['receivedQty'=>0,'issuedQty'=>0,'transferredOutQty'=>0,'returnedQty'=>0,'undatedReceivedQty'=>0,'quarantinedQty'=>0,'holds'=>[],'warnings'=>[]];
    foreach($orders as $order){
        if(!is_array($order))continue;$bagId=(string)($order['id']??'');$name=(string)($order['millName']??$order['mill']??$order['locationName']??'');$location=bti_location($masters,$name,(string)($order['locationId']??''));
        if(!$location){$unassigned[]=['entity'=>(string)($order['entity']??''),'locationId'=>'','brand'=>(string)($order['brand']??'')];if(bti_norm($name)===bti_norm($scope['millName']??''))$warnings[]='A saved order location is missing or ambiguous in Mills & Locations.';continue;}
        $sourceKey=(string)($order['poNo']??'').'|'.bqa_mill_line_key($order);$candidates=array_values((array)($poSources[$sourceKey]??[]));$supplier=bti_norm($order['supplier']??'');
        if($supplier!=='')$candidates=array_values(array_filter($candidates,static fn($c)=>bti_norm($c['supplier'])===$supplier));$po=count($candidates)===1?$candidates[0]:[];
        $entity=(string)($order['entity']??$po['entity']??'');
        if(!in_array($entity,['TTI','BRM'],true)){$unassigned[]=['entity'=>'','locationId'=>$location['locationId'],'brand'=>(string)($order['brand']??'')];if($location['locationId']===($scope['locationId']??''))$warnings[]='A bag source has no unambiguous actual stock owner; assign its original PO before moving it.';continue;}
        $id=bti_lot_id($bagId,$location['locationId']);$brand=(string)($order['brand']??'');$packing=(string)($order['size']??$po['packingSize']??'');$type=trim((string)($order['bagType']??''))!==''?(string)$order['bagType']:(string)($po['bagType']??'');$kg=bti_packing_kg($packing);
        $lot=$blank()+['id'=>$id,'originBagId'=>$bagId,'poNo'=>(string)($order['poNo']??''),'poLineKey'=>bqa_mill_line_key($order),'brand'=>$brand,'packingSize'=>$packing,'packingKg'=>$kg,'bagType'=>$type,'entity'=>$entity,'locationId'=>$location['locationId'],'millName'=>$location['millName'],'isTransferStock'=>false,'skuKey'=>bt_hash(['brand'=>bti_norm($brand),'packing'=>bti_norm($packing),'type'=>bti_norm($type)])];
        if($bagId===''||($orderIds[$bagId]??0)!==1)bti_hold($lot,'Original bag order identity is missing or duplicated.');
        if($po&&($po['entity']!==$entity||bti_norm($po['brand'])!==bti_norm($brand)))bti_hold($lot,'Original PO owner or brand disagrees with the saved Mill source.');
        if($brand===''||$packing===''||$type==='')bti_hold($lot,'Original bag brand, packing or material is unverified.');
        if($location['status']!=='Active')bti_hold($lot,'The source location is not active.');
        foreach(['printingMismatch','materialMismatch','qualityMismatch','tareMismatch'] as $flag)if(!empty($order[$flag]))bti_hold($lot,'Saved receipt specifications require review.');
        $counter=bti_count($order['received']??null);$totalEvents=0;$datedThrough=0;$undated=0;
        foreach($index[$bagId]??[] as $r){$rid=(string)($r['id']??'');$qty=bti_count($r['qty']??null);$key=$bagId.'|'.$rid;
            if($rid===''||$qty===null){bti_hold($lot,'A source receipt identity or whole quantity is unverified.');continue;}
            if(isset($receiptIds[$key])){bti_hold($lot,'A repeated source receipt identity requires reconciliation.');continue;}$receiptIds[$key]=true;
            if(!empty($r['entity'])&&$r['entity']!==$entity){bti_hold($lot,'A source receipt company disagrees with its original PO.');continue;}
            $receiptName=(string)($r['millName']??$r['mill']??'');if($receiptName!==''&&bti_norm($receiptName)!==bti_norm($location['millName'])){bti_hold($lot,'Receipt location differs from its saved PO location; review the actual physical lot.');continue;}
            $totalEvents+=$qty;$physical=$qty;$date=bti_date($r['date']??'');
            if(!empty($r['bagReceiptVersion'])){
                $accepted=bti_count($r['acceptedQty']??null);$quarantined=bti_count($r['quarantinedQty']??null);$snapshot=(array)($r['sourceSnapshot']??[]);
                if($accepted===null||$quarantined===null||$accepted+$quarantined!==$physical||($snapshot['entity']??'')!==$entity||($snapshot['locationId']??'')!==$location['locationId']||bti_norm($snapshot['brand']??'')!==bti_norm($brand)||bti_packing_kg((string)($snapshot['packingSize']??''))!==$kg||bti_norm($snapshot['bagType']??'')!==bti_norm($type)){bti_hold($lot,'The immutable receipt source or accepted/quarantined counts disagree with this physical lot.');$qty=0;}else{$qty=$accepted;if($date!==''&&$date<=$asOf)$lot['quarantinedQty']+=$quarantined;}
            }
            if($date==='')$undated+=$qty;elseif($date<=$asOf)$datedThrough+=$qty;
            $movements[]=['date'=>$date,'lotId'=>$id,'entity'=>$entity,'locationId'=>$location['locationId'],'qty'=>$qty,'type'=>'SUPPLIER_RECEIPT','reference'=>$rid];
        }
        if($counter===null){bti_hold($lot,'The received source quantity is unverified.');$counter=$totalEvents;}
        if($totalEvents>$counter)bti_hold($lot,'Dated receipt quantities exceed the saved source counter; reconcile the original receipts.');
        $prior=max(0,$counter-$totalEvents);$undated+=$prior;$lot['undatedReceivedQty']=$undated;
        if($prior)$movements[]=['date'=>'','lotId'=>$id,'entity'=>$entity,'locationId'=>$location['locationId'],'qty'=>$prior,'type'=>'UNDATED_PRIOR_RECEIPT','reference'=>$bagId];
        $lot['receivedQty']=$datedThrough+($asOf===$today?$undated:0);
        if($undated){$lot['warnings'][]='Prior receipt quantity has no verified date.';if($asOf!==$today)bti_hold($lot,'Undated receipt stock cannot be assigned to this historical review date.');}
        foreach((array)($order['transfers']??[]) as $i=>$t){$qty=bti_count($t['qty']??null);if($qty===null){bti_hold($lot,'A historical transfer quantity requires review.');continue;}$date=bti_date($t['date']??'');
            if($date!==''&&$date<=$asOf||$date===''&&$asOf===$today)$lot['transferredOutQty']+=$qty;
            if($date===''){bti_hold($lot,'A historical dispatch date is unverified.');}
            $movements[]=['date'=>$date,'lotId'=>$id,'entity'=>$entity,'locationId'=>$location['locationId'],'qty'=>-$qty,'type'=>'LEGACY_DISPATCH_UNRECEIVED','reference'=>$bagId.'|'.$i,'destination'=>(string)($t['to']??'')];
        }
        if(isset($lots[$id])){bti_hold($lot,'Repeated original bag lot requires review.');} $lots[$id]=$lot;
    }
    // Destination lots retain the original PO reference. They are never new purchase orders.
    foreach((array)($state['transfers']??[]) as $transfer){bt_totals($transfer);foreach((array)($transfer['lines']??[]) as $line){$id='BT|'.$transfer['id'].'|'.$line['lineId'];$snap=(array)$line['sourceSnapshot'];
        $lots[$id]=$blank()+$snap+['id'=>$id,'originBagId'=>(string)$line['originBagId'],'entity'=>(string)$transfer['entity'],'locationId'=>(string)$transfer['destination']['id'],'millName'=>(string)$transfer['destination']['name'],'isTransferStock'=>true,'packingKg'=>bti_packing_kg((string)($snap['packingSize']??''))];
        // Snapshot scope describes the source; physical destination scope is explicit.
        $lots[$id]['entity']=$transfer['entity'];$lots[$id]['locationId']=$transfer['destination']['id'];$lots[$id]['millName']=$transfer['destination']['name'];
    }}
    foreach((array)($state['transfers']??[]) as $transfer){$byLine=[];foreach($transfer['lines'] as $line){$byLine[$line['lineId']]=$line;$sourceId=$line['sourceLotId'];
        if(!isset($lots[$sourceId]))continue;
        if($transfer['date']<=$asOf)$lots[$sourceId]['transferredOutQty']+=(int)$line['qty'];
        $movements[]=['date'=>$transfer['date'],'lotId'=>$sourceId,'entity'=>$transfer['entity'],'locationId'=>$transfer['source']['locationId'],'qty'=>-(int)$line['qty'],'type'=>'TRANSFER_DISPATCH','reference'=>$transfer['id']];
    }
    foreach(['receipts'=>'TRANSFER_RECEIPT','returns'=>'TRANSFER_RETURN'] as $collection=>$type)foreach((array)($transfer[$collection]??[]) as $event)foreach($event['lines'] as $ack){$line=$byLine[$ack['lineId']]??null;if(!$line)throw new DomainException('Transfer receipt lost its original source line.');
        $id=$collection==='receipts'?'BT|'.$transfer['id'].'|'.$ack['lineId']:$line['sourceLotId'];if(!isset($lots[$id]))continue;$qty=(int)$ack['qty'];
        if($event['date']<=$asOf)$lots[$id][$collection==='receipts'?'receivedQty':'returnedQty']+=$qty;
        $movements[]=['date'=>$event['date'],'lotId'=>$id,'entity'=>$transfer['entity'],'locationId'=>$lots[$id]['locationId'],'qty'=>$qty,'type'=>$type,'reference'=>$event['id']];
    }}
    $runs=[];foreach($production as $r)if(is_array($r))$runs[(string)($r['id']??'')][]=$r;
    $issueCount=[];foreach($issues as $i)if(is_array($i))$issueCount[(string)($i['productionId']??'')]=($issueCount[(string)($i['productionId']??'')]??0)+1;
    $consume=static function(array $r,array $allocs,string $reference)use(&$lots,&$movements,$asOf,$today,$masters){$location=bti_run_location($masters,$r);$date=bti_date($r['date']??'');foreach($allocs as $a){$id=(string)($a['lotId']??'');$qty=bti_count($a['qty']??null);if(!isset($lots[$id])||$qty===null||!$location||$lots[$id]['entity']!==($r['entity']??'')||$lots[$id]['locationId']!==$location['locationId'])throw new DomainException('Saved production allocation lost its actual company/location source lot.');
        if($date!==''&&$date<=$asOf||$date===''&&$asOf===$today)$lots[$id]['issuedQty']+=$qty;
        if($date==='')bti_hold($lots[$id],'Production consumption date is unverified.');
        $movements[]=['date'=>$date,'lotId'=>$id,'entity'=>$lots[$id]['entity'],'locationId'=>$lots[$id]['locationId'],'qty'=>-$qty,'type'=>'PRODUCTION_CONSUMPTION','reference'=>$reference];}};
    // Missing or conflicting historical issue facts stay held; never infer a PO or round a partial bag.
    $issueGroups=[];foreach($issues as $i)if(is_array($i))$issueGroups[(string)($i['productionId']??'')][]=$i;
    $blockedLegacy=[];
    foreach($production as $run){if(!is_array($run)||!empty($run['bagAllocationVersion']))continue;$pid=(string)($run['id']??'');if($excludeProductionId!==null&&$pid===$excludeProductionId)continue;
        $expected=[];$invalid=false;try{foreach(bti_usage($run) as $part){$key=bti_norm($part['brand']);$expected[$key]=['brand'=>$part['brand'],'qty'=>($expected[$key]['qty']??0)+$part['qty']];}}catch(DomainException){$invalid=true;foreach((array)($run['rows']??[]) as $row)if(is_array($row)&&preg_match('/^(?:Ready Rice|B2)\s*—\s*(.+)$/u',(string)($row['product']??''),$m)&&!in_array(strtoupper(trim($m[1])),['LOCAL','LOCAL BAGS'],true))$expected[bti_norm($m[1])]=['brand'=>trim($m[1]),'qty'=>null];}
        if(!$expected)continue;$group=$issueGroups[$pid]??[];$matches=!$invalid&&count($runs[$pid]??[])===1&&count($group)===1;
        foreach($expected as $key=>$part){$saved=null;if(count($group)===1)foreach((array)($group[0]['usage']??[]) as $brand=>$qty)if(bti_norm($brand)===$key)$saved=bti_count($qty);if($saved!==$part['qty'])$matches=false;}
        if($matches)continue;$blockedLegacy[$pid]=true;$location=bti_run_location($masters,$run);$entity=(string)($run['entity']??'');
        foreach($expected as $part){foreach($lots as $id=>$lot)if(bti_norm($lot['brand'])===bti_norm($part['brand'])&&(!$location||$lot['locationId']===$location['locationId'])&&(!in_array($entity,['TTI','BRM'],true)||$lot['entity']===$entity))bti_hold($lots[$id],'Historical packing has no consistent actual whole-bag issue record; confirm its source allocation.');
            if($location&&in_array($entity,['TTI','BRM'],true))$unallocated[]=['reference'=>'LEGACY-PROD|'.$pid.'|CONFIRM','entity'=>$entity,'locationId'=>$location['locationId'],'brand'=>$part['brand'],'qty'=>null,'expectedQty'=>$part['qty'],'date'=>bti_date($run['date']??''),'sourceFingerprint'=>bt_hash(['production'=>$run,'issues'=>$group])];}
    }
    foreach($production as $run){if(!is_array($run)||empty($run['bagAllocationVersion'])||($excludeProductionId!==null&&(string)($run['id']??'')===$excludeProductionId))continue;
        bti_validate_allocations($run,(array)($run['bagAllocations']??[]),$lots,$masters,true);$consume($run,(array)($run['bagAllocations']??[]),'PROD|'.($run['id']??''));}
    usort($issues,static fn($a,$b)=>strcmp((string)($a['date']??''),(string)($b['date']??''))?:strcmp((string)($a['productionId']??''),(string)($b['productionId']??'')));
    foreach($issues as $issue){if(!is_array($issue))continue;$pid=(string)($issue['productionId']??'');if(isset($blockedLegacy[$pid]))continue;if($excludeProductionId!==null&&$pid===$excludeProductionId)continue;$parents=$runs[$pid]??[];$parent=count($parents)===1?$parents[0]:null;
        if($parent&&!empty($parent['bagAllocationVersion']))continue;$actual=$parent??$issue;$entity=(string)($actual['entity']??'');$location=bti_location($masters,(string)($actual['millName']??$actual['mill']??''),(string)($actual['locationId']??''));
        if(!$location||!in_array($entity,['TTI','BRM'],true)){
            foreach((array)($issue['usage']??[]) as $brand=>$q)if(bti_count($q)!==0)foreach($lots as $id=>$lot)if(bti_norm($lot['brand'])===bti_norm($brand))bti_hold($lots[$id],'Unassigned historical production consumption requires source company/location review.');
            $warnings[]='Historical production bag consumption has no verified company/location.';continue;}
        foreach((array)($issue['usage']??[]) as $brand=>$rawQty){$qty=bti_count($rawQty);if($qty===0)continue;$candidates=array_filter($lots,static fn($l)=>$l['entity']===$entity&&$l['locationId']===$location['locationId']&&bti_norm($l['brand'])===bti_norm($brand));
            $date=bti_date($issue['date']??$parent['date']??'');if($date!==''&&$date>$asOf)continue;
            $parts=[];if($parent)foreach(bti_usage($parent) as $usage)if(bti_norm($usage['brand'])===bti_norm($brand))$parts[]=$usage;
            $allocs=[];$possibleUnassigned=array_filter($unassigned,static fn($u)=>($u['entity']===''||$u['entity']===$entity)&&($u['locationId']===''||$u['locationId']===$location['locationId'])&&bti_norm($u['brand'])===bti_norm($brand));$resolved=!$possibleUnassigned&&$qty!==null&&count($parents)===1&&($issueCount[$pid]??0)===1&&array_sum(array_column($parts,'qty'))===$qty;
            foreach($parts as $part){$eligible=array_filter($candidates,static function($l)use($part,$date,$movements){if($part['packingKg']!==null&&$l['packingKg']!==null&&abs($l['packingKg']-$part['packingKg'])>=.0000001)return false;$capacity=bti_capacity_at($l,$date,$movements);return $capacity===null||$capacity>0;});
                if(count($eligible)!==1){$resolved=false;break;}$selected=reset($eligible);$capacity=bti_capacity_at($selected,$date,$movements);if($capacity!==null&&$capacity<$part['qty']){$resolved=false;break;}$allocs[]=['lotId'=>array_key_first($eligible),'qty'=>$part['qty']];}
            if($resolved&&$allocs){$consume(array_replace($actual,['date'=>$date]),$allocs,'LEGACY-PROD|'.$pid);continue;}
            $unallocated[]=['reference'=>'LEGACY-PROD|'.$pid,'entity'=>$entity,'locationId'=>$location['locationId'],'brand'=>(string)$brand,'qty'=>$qty,'date'=>$date,'sourceFingerprint'=>bt_hash(['issue'=>$issue,'parent'=>$parent])];
            foreach($candidates as $id=>$lot)bti_hold($lots[$id],'Historical production consumption requires an explicit original-PO allocation.');
        }
    }
    $visible=[];foreach($lots as $id=>$lot){if($lot['entity']!==($scope['entity']??'')||$lot['locationId']!==($scope['locationId']??''))continue;
        $lot['balanceBeforeUnallocated']=$lot['receivedQty']+$lot['returnedQty']-$lot['issuedQty']-$lot['transferredOutQty'];
        if($lot['balanceBeforeUnallocated']<0)bti_hold($lot,'Recorded physical movements exceed this source lot; negative balance retained.');
        $lot['hold']=implode(' ',$lot['holds']);$lot['availableQty']=$lot['hold']===''?$lot['balanceBeforeUnallocated']:null;
        // A backdated entry must also leave later committed physical movements funded.
        $future=[];foreach($movements as $movement)if($movement['lotId']===$id&&$movement['date']>$asOf)$future[$movement['date']]=($future[$movement['date']]??0)+(int)$movement['qty'];ksort($future);
        $running=$lot['balanceBeforeUnallocated'];$minimum=$running;foreach($future as $delta){$running+=$delta;$minimum=min($minimum,$running);}
        $lot['committableQty']=$lot['hold']===''?max(0,$minimum):null;$visible[$id]=$lot;}
    ksort($visible);$unallocated=array_values(array_filter($unallocated,static fn($x)=>$x['entity']===($scope['entity']??'')&&$x['locationId']===($scope['locationId']??'')));
    $movements=array_values(array_filter($movements,static fn($x)=>$x['entity']===($scope['entity']??'')&&$x['locationId']===($scope['locationId']??'')));
    $view=['scope'=>$scope,'asOfDate'=>$asOf,'lots'=>$visible,'unallocatedConsumption'=>$unallocated,'movements'=>$movements,'warnings'=>array_values(array_unique($warnings))];$view['fingerprint']=bt_hash($view);return $view;
}

function bti_production_access(array $user,string $entity,array $location,string $action): void {
    if(!in_array($entity,['TTI','BRM'],true)||!tt_user_can_open_module($user,'Mill')||!tt_user_can_module_action($user,'Mill','production',$action))throw new DomainException('Production '.$action.' permission is required for bag allocation.');
    $assigned=bti_norm($user['location']??'');if(($user['role']??'')!=='Super Admin'&&!in_array($assigned,['','all locations','all authorized locations'],true)&&$assigned!==bti_norm($location['millName']))throw new DomainException('Production bags belong to another assigned location.');
    $grants=(array)($user['permissions']['Accounts']??[]);foreach(['entity-tti','entity-brm','entity-tg'] as $key)if(array_key_exists($key,$grants)){if(!tt_user_can_access_entity($user,$entity,$action))throw new DomainException('Production stock owner permission is required.');break;}
}
function bti_bag_facts(array $run): array {
    $allocs=[];foreach((array)($run['bagAllocations']??[]) as $a)$allocs[]=['lotId'=>(string)($a['lotId']??''),'qty'=>$a['qty']??null];usort($allocs,static fn($a,$b)=>strcmp($a['lotId'],$b['lotId']));
    return ['entity'=>$run['entity']??'','millName'=>$run['millName']??$run['mill']??'','locationId'=>$run['locationId']??'','date'=>$run['date']??'','usage'=>bti_usage($run),'allocations'=>$allocs];
}
/** Validate only explicitly versioned allocations; preserve legacy facts for separate review. */
function bti_prepare_production(array $values,string $json,array $masters,array $user,string $now): string {
    $rows=json_decode($json,true,512,JSON_THROW_ON_ERROR);if(!is_array($rows)||!array_is_list($rows))throw new DomainException('Production records must be a list.');
    $old=[];foreach(bti_rows($values,'tt30prod') as $r)if(is_array($r))$old[(string)($r['id']??'')]=$r;
    $stateRaw=$values['tt41bagtransfers']??'{}';$state=is_string($stateRaw)?json_decode($stateRaw,true,512,JSON_THROW_ON_ERROR):$stateRaw;if(!is_array($state))throw new DomainException('Invalid bag movement history.');
    $today=(new DateTimeImmutable($now))->setTimezone(new DateTimeZone('Asia/Karachi'))->format('Y-m-d');
    $changed=0;foreach($rows as $candidate){if(!is_array($candidate)||empty($candidate['bagAllocationVersion']))continue;$prior=$old[(string)($candidate['id']??'')]??null;if(!$prior||bt_hash(bti_bag_facts($candidate))!==bt_hash(bti_bag_facts($prior)))$changed++;}if($changed>1)throw new DomainException('Save one reviewed production bag allocation at a time.');
    $serverFields=['bagAllocationVersion','bagAllocations','bagStockFingerprint','bagAllocationActor','bagAllocationScope'];
    foreach($rows as &$r){if(!is_array($r))continue;$id=(string)($r['id']??'');$before=$old[$id]??null;
        if(empty($r['bagAllocationVersion'])&&empty($before['bagAllocationVersion']))continue;
        if($before&&bt_hash($before)===bt_hash($r))continue;
        if($before&&!empty($before['bagAllocationVersion'])&&empty($r['bagAllocationVersion'])){
            $left=bti_bag_facts($r);$right=bti_bag_facts($before);unset($left['allocations'],$right['allocations']);
            if(bt_hash($left)!==bt_hash($right))throw new DomainException('Review actual source bag allocations before changing this production packing or scope.');
            foreach($serverFields as $key)if(array_key_exists($key,$before))$r[$key]=$before[$key];
        }
        if(($r['bagAllocationVersion']??null)!==1||!is_array($r['bagAllocations']??null))throw new DomainException('Use the supported explicit production bag allocation workflow.');
        $location=bti_location($masters,(string)($r['millName']??$r['mill']??''),(string)($r['locationId']??''));if(!$location)throw new DomainException('Resolve the actual production mill in Mills & Locations.');
        $unchanged=$before&&!empty($before['bagAllocationVersion'])&&bt_hash(bti_bag_facts($r))===bt_hash(bti_bag_facts($before));
        if($unchanged){
            $actual=$r;$prior=$before;foreach($serverFields as $key){unset($actual[$key],$prior[$key]);}
            if(bt_hash($actual)!==bt_hash($prior))bti_production_access($user,(string)($r['entity']??''),$location,'Edit');
            foreach($serverFields as $key)if(array_key_exists($key,$before))$r[$key]=$before[$key];continue;
        }
        bti_production_access($user,(string)($r['entity']??''),$location,$before?'Edit':'Create');
        $scope=['entity'=>(string)$r['entity'],'locationId'=>$location['locationId'],'millName'=>$location['millName'],'status'=>$location['status']];
        $actualDate=bt_date((string)($r['date']??''),$now);
        $review=bti_project($values,$masters,$scope,$actualDate,$today,$state,$id);
        if(!preg_match('/^[a-f0-9]{64}$/D',(string)($r['bagStockFingerprint']??''))||!hash_equals($review['fingerprint'],$r['bagStockFingerprint']))throw new DomainException('The reviewed bag stock changed. Retain production entry and review the actual source lots again.');
        bti_validate_allocations($r,$r['bagAllocations'],$review['lots'],$masters);
        $canonical=[];foreach($r['bagAllocations'] as $a){$lot=$review['lots'][(string)$a['lotId']];$qty=bt_qty($a['qty']);if(!is_int($lot['committableQty'])||$lot['committableQty']<$qty)throw new DomainException('The selected source bag lot is held or has insufficient available stock.');
            $canonical[]=['lotId'=>(string)$a['lotId'],'qty'=>$qty,'sourceSnapshot'=>array_intersect_key($lot,array_flip(['originBagId','poNo','poLineKey','brand','packingSize','bagType','skuKey','entity','locationId','millName']))];}
        $r['bagAllocations']=$canonical;$r['bagAllocationScope']=$scope;$r['bagAllocationActor']=['userId'=>(int)$user['id'],'by'=>(string)($user['full_name']??$user['username']??''),'at'=>$now];
    }unset($r);
    return json_encode($rows,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}
