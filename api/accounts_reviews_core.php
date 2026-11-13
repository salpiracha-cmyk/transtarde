<?php
declare(strict_types=1);
require_once __DIR__.'/tg_remittance_core.php';
function ar_late_pohanch_can_approve(array $user,string $entity):bool {
    return tt_user_can_access_entity($user,$entity,'View') &&
        ((tt_user_can_access_entity($user,$entity,'Edit')&&tt_user_can_module_action($user,'Accounts','purchases','Edit')) ||
        (tt_user_can_access_entity($user,$entity,'Approve')&&tt_user_can_module_action($user,'Accounts','purchases','Approve')));
}

function ar_late_pohanch_fingerprint(array $event,array $journal,string $due):string {
    $meta=$journal['meta']??[];
    if($due===''||($journal['date']??'')<=$due||!str_starts_with((string)($event['sourceKey']??''),'POHANCH|') || trim((string)($meta['pohanch']??''))==='' ||
       ($event['status']??'')==='Reversed'||in_array($journal['status']??'',['Reversed','Cancelled'],true)||
       !empty($journal['meta']['deletedFromBooks'])||!empty($journal['reversedByPostId']))return '';
    return hash('sha256',json_encode([$event['sourceKey'],$event['journalId']??'',$journal['date']??'',$due,
        $journal['totalDebit']??null,$meta],JSON_THROW_ON_ERROR));
}

/** Read saved Mill Pohanch documents, including those awaiting an Accounts handoff. */
function ar_issued_pohanches(string $entity): array {
    if (!in_array($entity,['TTI','BRM'],true)) return [];
    require_once __DIR__.'/mill_recovery_core.php';
    $ops=mr_read_operations_source(TT_DATA_DIR.'/operations.json',['tt30slips']);
    $rows=json_decode((string)($ops['values']['tt30slips']??'[]'),true,512,JSON_THROW_ON_ERROR);
    if (!is_array($rows)) throw new RuntimeException('Mill Pohanch source unavailable.');
    return array_values(array_filter($rows,static fn($row)=>is_array($row)&&($row['entity']??'')===$entity));
}

/** Review decisions apply only to the displayed version of the source. */
function ar_items(array $s,string $entity,array $pohanches=[],?array &$statements=null):array {
 $items=[];$sodas=[];$coveredHolds=[];$today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Karachi')))->format('Y-m-d');
 $add=static function(string $kind,string $id,string $type,string $reference,string $message,array $target,array $details=[],array $approval=[],array $statement=[])use(&$items,$s,$entity):void{
  $key=$entity.'|'.$kind.'|'.$id;$row=['id'=>$key,'kind'=>$kind,'type'=>$type,'reference'=>$reference,'message'=>$message,'target'=>$target];
  $row['fingerprint']=hash('sha256',json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
  if(($s['accountsReviewDismissals'][$key]['fingerprint']??'')===$row['fingerprint'])return;
  // Preserve the fingerprint of previously dismissed notices when changing their display copy.
  $row['message']=preg_replace('/\. Approve to .+$/','.', $message);
  $row['details']=$details;
  if($approval){
   $row['approval']=$approval;
   $row['fingerprint']=hash('sha256',json_encode([$row['fingerprint'],$approval],JSON_THROW_ON_ERROR));
   if(($s['accountsReviewDismissals'][$key]['fingerprint']??'')===$row['fingerprint']||
      ($s['accountsReviewApprovals'][$approval['key']]['receiptFingerprint']??'')===$approval['receiptFingerprint'])return;
  }
  if($statement)$row['statement']=$statement;
  $items[]=$row;
 };
 foreach(['purchaseSodas','purchaseSodasV2']as$collection)foreach((array)($s[$collection]??[])as$id=>$x){if(!is_array($x)||($x['entity']??'')!==$entity)continue;$no=(string)($x['sodaNo']??'');if($no===''||isset($sodas[$no]))continue;$x['id']=(string)($x['id']??$id);$sodas[$no]=$x;}
 $bySoda=[];
 if($pohanches)require_once __DIR__.'/arrival_withdrawal_core.php';
 foreach($pohanches as $slip){
  if(($slip['entity']??'')!==$entity||empty($slip['slip'])||!empty($slip['deleted'])||!empty($slip['cancelled'])||!empty($slip['reversed'])||
     in_array(strtolower((string)($slip['status']??'')),['cancelled','deleted','reversed'],true)||tt_is_withdrawn_arrival($slip))continue;
  $no=(string)($slip['soda']??'');
  if(isset($sodas[$no]))$bySoda[$no][]=$slip;
 }
 foreach($sodas as$sodaNumber=>$x){
  $no=(string)$sodaNumber;
  $status=(string)($x['manualStatus']??$x['status']??'');
  if($status==='Cancelled')continue;
  $closed=in_array($status,['Completed','Short Closed','Manual Closed'],true);
  $received=0.0;$countedSources=[];$countedSlips=[];$deliveries=[];
  foreach((array)($s['events']??[]) as $ev){
   if(!is_array($ev)||($ev['entity']??'')!==$entity||($ev['eventType']??'')!=='COMMODITY_RECEIPT_ACCEPTED'||($ev['status']??'')==='Reversed')continue;
   $j=$s['journals'][$ev['journalId']??'']??[];
   if(($j['meta']['soda']??'')!==$no||in_array((string)($j['status']??''),['Reversed','Cancelled'],true)||!empty($j['meta']['deletedFromBooks'])||!empty($j['reversedByPostId']))continue;
   $receiptSource=(string)($ev['sourceKey']??'');
   $receiptSlip=(string)($j['meta']['pohanch']??'');
   $receiptTruck=(string)($j['meta']['truck']??'');
   $legacy=$receiptSlip!==''?$receiptSlip.'|'.strtoupper(trim($receiptTruck)):'';
   $kg=(float)($j['meta']['payableWeightKg']??0);
   if($kg>0&&is_finite($kg)&&!isset($countedSources[$receiptSource])&&($legacy===''||!isset($countedSlips[$legacy]))){
    $received+=$kg;
    $deliveries[]=['pohanch'=>$receiptSlip?:($j['reference']??''),'truck'=>$receiptTruck,'warehouse'=>(string)($j['meta']['millName']??$x['locationName']??$x['location']??''),'date'=>(string)($j['date']??''),'kg'=>$kg];
   }
   if($receiptSource!=='')$countedSources[$receiptSource]=true;
   if($legacy!=='')$countedSlips[$legacy]=true;

   $date=(string)($j['date']??'');
   $due=(string)($x['arrivalDueDate']??'');
   $source=(string)($ev['sourceKey']??'');
   if($date===''||$due===''||$date<=$due)continue;
   $key=$entity.'|'.$source;
   $hold=$s['payableHolds'][$key]??[];
   $receiptFingerprint=ar_late_pohanch_fingerprint($ev,$j,$due);
   $systemHold=($hold['systemRule']??'')==='LATE_DELIVERY';
   if($closed&&(!$systemHold||empty($hold['active'])))continue;
   if(!empty($hold['active'])&&(!$systemHold||$receiptFingerprint===''))continue;
   $approval=$receiptFingerprint!==''?['key'=>$key,'receiptFingerprint'=>$receiptFingerprint,'releaseHold'=>!empty($hold['active'])]:[];
   $pohanch=(string)($j['meta']['pohanch']??'');
   $details=['Pohanch'=>$pohanch,'SODA'=>$no,'Truck'=>(string)($j['meta']['truck']??''),'Supplier'=>(string)($j['meta']['party']??$x['party']??''),'Arrival date'=>$date,'SODA due date'=>$due,'Payable weight'=>isset($j['meta']['payableWeightKg'])?number_format((float)$j['meta']['payableWeightKg'],3).' kg':''];
   $before=count($items);
   $add('LATE',(string)($ev['id']??$ev['journalId']),'Late arrival',(string)($j['meta']['pohanch']??$no),'Delivery arrived on '.$date.', after the Soda due date '.$due.'. Approve to open the Soda for correction.',['sodaNo'=>$no],$details,$approval);
   if($systemHold&&count($items)>$before)$coveredHolds[$key]=true;
  }

  foreach($bySoda[$no]??[] as $slip){
   if(!empty($slip['sourceSodaId'])&&(string)$slip['sourceSodaId']!==$x['id'])continue;
   $weight=(float)($slip['payableWeight']??0);
   if(!is_finite($weight)||$weight<=0)continue;
   $legacy=(string)$slip['slip'].'|'.strtoupper(trim((string)($slip['truck']??'')));
   $source=!empty($slip['bridgeId'])?'POHANCH|'.($slip['millId']??'MILL').'|'.$slip['bridgeId']:'';
   if(isset($countedSlips[$legacy])||($source!==''&&isset($countedSources[$source])))continue;
   $countedSlips[$legacy]=true;
   if($source!=='')$countedSources[$source]=true;
   $received+=$weight;
   $deliveries[]=['pohanch'=>(string)$slip['slip'],'truck'=>(string)($slip['truck']??''),'warehouse'=>(string)($slip['millName']??$slip['locationName']??$x['locationName']??$x['location']??''),'date'=>(string)($slip['unloadingDate']??$slip['arrivalDate']??''),'kg'=>$weight];
  }
  $min=(float)($x['qtyFromKg']??0);$max=(float)($x['qtyToKg']??0);
  $readyRice=strtoupper((string)($x['commodity']??''))==='RICE'&&strtoupper((string)($x['productStage']??''))==='READY';
  $exMill=($x['readyRoute']??'')==='EX_MILL';
  $min=$readyRice&&$max>0?$max*.95:($exMill?$min*.95:$min);
  $max=$max>0?($exMill||$readyRice?$max*1.05:$max+950):0;
  $trips=count(array_filter($deliveries,static fn($receipt)=>trim($receipt['truck'])!==''));
  $tolerance=$exMill?0:$trips*1000;
  $short=max(0,$min-$received);
  $due=(string)($x['arrivalDueDate']??'');
  $within=$received>0&&$min>0&&$short<=$tolerance+.001&&($max<=0||$received<=$max+.001);
  $statement=['sodaNo'=>$no,'supplier'=>$x['party']??'','broker'=>$x['broker']??'','product'=>$x['displayName']??$x['variety']??$x['commodity']??'',
   'sodaDate'=>$x['sodaDate']??'','dueDate'=>$due,'warehouse'=>$x['locationName']??$x['location']??'',
   'orderedMinKg'=>(float)($x['qtyFromKg']??0),'orderedMaxKg'=>(float)($x['qtyToKg']??0),
   'minKg'=>$min,'maxKg'=>$max,'receivedKg'=>$received,'shortKg'=>$short,'truckTrips'=>$trips,'shortToleranceKg'=>$tolerance,
   'canClose'=>!$closed&&$within&&!$exMill,'deliveries'=>$deliveries];
  if($statements!==null)$statements[$x['id']]=$statement;
  if(!$closed&&$min>0&&$short>$tolerance+.001&&$due!==''&&$due<$today)$add('SODA',$x['id'],'Overdue Soda',$no,'Delivery was due on '.$due.'. '.number_format($short/1000,3).' MT is still outstanding.',['sodaNo'=>$no],[],[],$statement);
 }
 foreach((array)($s['payableHolds']??[])as$id=>$x){if(!is_array($x)||($x['entity']??'')!==$entity||empty($x['active'])||isset($coveredHolds[$id]))continue;$no=(string)($x['sodaNo']??'');$add('HOLD',(string)$id,'Held payment',(string)($x['pohanch']??$x['sourceKey']??''),'This payment is on hold: '.(string)($x['reason']??'Accounts review required').'. Approve to open the related entry for correction.',['sodaNo'=>$no,'sourceKey'=>(string)($x['sourceKey']??''),'reason'=>(string)($x['reason']??'')]);}
 foreach((array)($s['freightBillsV1']??[])as$id=>$x){if(!is_array($x)||($x['entity']??'')!==$entity)continue;$open=round((float)($x['disputedTotal']??0)-(float)($x['disputeSettledTotal']??0),2);if($open>.009)$add('FREIGHT',(string)($x['id']??$id),'Freight dispute',(string)($x['invoiceNo']??$id),'PKR '.number_format($open,2).' of this freight bill is disputed. Approve to open the bill for review.',['billId'=>(string)($x['id']??$id)]);}
 foreach((array)($s['localSalesCandidates']??[])as$id=>$x)if(is_array($x)&&($x['entity']??'')===$entity&&($x['status']??'')==='Pending Accounts Approval')$add('SALE',(string)($x['id']??$id),'Local sale',(string)($x['gatePass']??$x['soda']??''),'Mill submitted a local sale for '.(string)($x['party']??$x['customer']??'customer').' of PKR '.number_format((float)($x['amount']??0),2).'. Approve to confirm the sale in Accounts.',['candidateId'=>(string)($x['id']??$id)]);
 foreach((array)($s['localSalesPaymentCandidates']??[])as$id=>$x)if(is_array($x)&&($x['entity']??'')===$entity&&in_array((string)($x['status']??''),['Pending Accounts Approval','Needs Settlement Details'],true))$add('PAYMENT',(string)($x['id']??$id),'Local sale payment',(string)($x['soda']??''),'Mill recorded PKR '.number_format((float)($x['amount']??0),2).' from '.(string)($x['party']??'customer').' by '.(string)($x['paymentType']??'payment').'. Approve to check the details and post the receipt.',['paymentId'=>(string)($x['id']??$id)],['Payment type'=>(string)($x['paymentType']??''),'Third-party name'=>($x['paymentType']??'')==='Third-Party Account'?(string)($x['reference']??''):'']);
 if($entity==='TG')foreach(tgr_items($s) as $x)$items[]=['id'=>'TG|REMITTANCE|'.$x['id'],'kind'=>'REMITTANCE','type'=>'TG remittance','reference'=>implode(', ',$x['bankAdviceRefs']),'message'=>$x['currency'].' '.number_format($x['amountNative'],2).' to '.$x['counterparty'].'. '.(!empty($x['legacy'])?'Already deducted; review charges/VAT or combine the split advices.':'Pending deduction; confirm the remittance and enter charges/VAT.'),'target'=>['remittanceId'=>$x['id']],'fingerprint'=>tgr_fingerprint($x)];
 $priority=static fn(array $item):int=>in_array($item['kind'],['SALE','PAYMENT'],true)?0:1;
 usort($items,static fn(array $a,array $b):int=>$priority($a)<=>$priority($b));
 return$items;
}
