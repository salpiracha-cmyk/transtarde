<?php
declare(strict_types=1);
/** Read-only review queue. Dismissals hide a specific issue version, never its transaction. */
function ar_items(array $s,string $entity):array {
 $items=[];$sodas=[];$today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Karachi')))->format('Y-m-d');
 $add=static function(string $kind,string $id,string $type,string $reference,string $message,array $target)use(&$items,$s,$entity):void{
  $key=$entity.'|'.$kind.'|'.$id;$row=['id'=>$key,'kind'=>$kind,'type'=>$type,'reference'=>$reference,'message'=>$message,'target'=>$target];
  $row['fingerprint']=hash('sha256',json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
  if(($s['accountsReviewDismissals'][$key]['fingerprint']??'')!==$row['fingerprint'])$items[]=$row;
 };
 foreach(['purchaseSodas','purchaseSodasV2']as$collection)foreach((array)($s[$collection]??[])as$id=>$x){if(!is_array($x)||($x['entity']??'')!==$entity)continue;$no=(string)($x['sodaNo']??'');if($no===''||isset($sodas[$no]))continue;$x['id']=(string)($x['id']??$id);$sodas[$no]=$x;}
 foreach($sodas as$sodaNumber=>$x){$no=(string)$sodaNumber;if(in_array((string)($x['manualStatus']??$x['status']??''),['Cancelled','Completed','Short Closed','Manual Closed'],true))continue;$received=0.0;
  foreach((array)($s['events']??[])as$ev){if(!is_array($ev)||($ev['entity']??'')!==$entity||($ev['eventType']??'')!=='COMMODITY_RECEIPT_ACCEPTED')continue;$j=$s['journals'][$ev['journalId']??'']??[];if(($j['meta']['soda']??'')!==$no||in_array((string)($j['status']??''),['Reversed','Cancelled'],true))continue;$received+=(float)($j['meta']['payableWeightKg']??0);$date=(string)($j['date']??'');$due=(string)($x['arrivalDueDate']??'');$source=(string)($ev['sourceKey']??'');if($date!==''&&$due!==''&&$date>$due&&empty($s['payableHolds'][$entity.'|'.$source]['active']))$add('LATE',(string)($ev['id']??$ev['journalId']),'Late arrival',(string)($j['meta']['pohanch']??$no),'Delivery arrived on '.$date.', after the Soda due date '.$due.'. Approve to open the Soda for correction.',['sodaNo'=>$no]);}

  $min=(float)($x['qtyFromKg']??0);if(strtoupper((string)($x['commodity']??''))==='RICE'&&strtoupper((string)($x['productStage']??''))==='READY')$min=(float)($x['qtyToKg']??$min)*.95;
  $due=(string)($x['arrivalDueDate']??'');if($min>0&&$received+.001<$min&&$due!==''&&$due<$today)$add('SODA',$x['id'],'Overdue Soda',$no,'Delivery was due on '.$due.'. '.number_format(max(0,$min-$received)/1000,3).' MT is still outstanding. Approve to open this Soda and update its date or quantity.',['sodaNo'=>$no]);
 }
 foreach((array)($s['payableHolds']??[])as$id=>$x){if(!is_array($x)||($x['entity']??'')!==$entity||empty($x['active']))continue;$no=(string)($x['sodaNo']??'');$add('HOLD',(string)$id,'Held payment',(string)($x['pohanch']??$x['sourceKey']??''),'This payment is on hold: '.(string)($x['reason']??'Accounts review required').'. Approve to open the related entry for correction.',['sodaNo'=>$no,'sourceKey'=>(string)($x['sourceKey']??''),'reason'=>(string)($x['reason']??'')]);}
 foreach((array)($s['freightBillsV1']??[])as$id=>$x){if(!is_array($x)||($x['entity']??'')!==$entity)continue;$open=round((float)($x['disputedTotal']??0)-(float)($x['disputeSettledTotal']??0),2);if($open>.009)$add('FREIGHT',(string)($x['id']??$id),'Freight dispute',(string)($x['invoiceNo']??$id),'PKR '.number_format($open,2).' of this freight bill is disputed. Approve to open the bill for review.',['billId'=>(string)($x['id']??$id)]);}
 foreach((array)($s['localSalesCandidates']??[])as$id=>$x)if(is_array($x)&&($x['entity']??'')===$entity&&($x['status']??'')==='Pending Accounts Approval')$add('SALE',(string)($x['id']??$id),'Local sale',(string)($x['gatePass']??$x['soda']??''),'Mill submitted a local sale for '.(string)($x['party']??$x['customer']??'customer').' of PKR '.number_format((float)($x['amount']??0),2).'. Approve to confirm the sale in Accounts.',['candidateId'=>(string)($x['id']??$id)]);
 foreach((array)($s['localSalesPaymentCandidates']??[])as$id=>$x)if(is_array($x)&&($x['entity']??'')===$entity&&in_array((string)($x['status']??''),['Pending Accounts Approval','Needs Settlement Details'],true))$add('PAYMENT',(string)($x['id']??$id),'Local sale payment',(string)($x['soda']??''),'Mill recorded PKR '.number_format((float)($x['amount']??0),2).' from '.(string)($x['party']??'customer').' by '.(string)($x['paymentType']??'payment').'. Approve to check the details and post the receipt.',['paymentId'=>(string)($x['id']??$id)]);
 return$items;
}
