<?php
declare(strict_types=1);
/** Read-only forecasts from committed operational facts. No journal is created here. */
function se_rows(array $values,string $key):array {
 $raw=$values[$key]??[];if(is_string($raw))$raw=json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($raw))throw new RuntimeException('Invalid estimate source: '.$key);return $raw;
}
function se_day(mixed $v):string {
 $v=preg_replace('/[\x{00A0}\x{202F}]/u',' ',trim((string)$v))??'';$v=preg_replace('/^(\d{2}) Sept (\d{4},)/','$1 Sep $2',$v)??'';$zone=new DateTimeZone('Asia/Karachi');
 if(preg_match('/^\d{2} [A-Z][a-z]{2} \d{4}, \d{2}:\d{2}:\d{2} (am|pm) PKT$/D',$v)){
  $d=DateTimeImmutable::createFromFormat('!d M Y, h:i:s a \\P\\K\\T',$v,$zone);$errors=DateTimeImmutable::getLastErrors();
  return $d&&(!$errors||!($errors['warning_count']||$errors['error_count']))&&$d->format('d M Y, h:i:s a').' PKT'===$v?$d->format('Y-m-d'):'';
 }
 if(!preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})?)?$/D',$v))return '';
 try{$d=new DateTimeImmutable($v,$zone);$errors=DateTimeImmutable::getLastErrors();if($errors&&($errors['warning_count']||$errors['error_count']))return '';return $d->setTimezone($zone)->format('Y-m-d');}catch(Throwable){return '';}
}
function se_active(array $r):bool{return !in_array(strtolower((string)($r['status']??'')),['cancelled','deleted','reversed'],true)&&empty($r['reversed'])&&empty($r['cancelled']);}
function se_key(string $s):string{return strtolower(preg_replace('/[^\p{L}\p{N}]/u','',$s)??'');}
function se_row(string $id,string $entity,string $party,string $label,string $currency,?float $gross,string $date,string $basis,array $extra=[]):array {
 if($gross!==null&&(!is_finite($gross)||$gross<0||$gross>100000000000))$gross=null;
 $label=mb_strcut($label,0,280,'UTF-8');
 $r=$extra+['id'=>'AUTOEST-'.substr(hash('sha256',$id),0,24),'sourceKey'=>$id,'type'=>'FINANCIAL','entity'=>$entity,'account'=>'2210','party'=>$party,'label'=>$label,'currency'=>$currency,'originalCurrency'=>$currency,'amount'=>$gross===null?null:-round($gross,2),'originalAmount'=>$gross===null?null:-round($gross,2),'cashflowSection'=>'estimates','source'=>'Automatic shipment estimate','sourceDate'=>$date,'postIds'=>[],'excluded'=>false,'reason'=>'','basis'=>$basis];$r['sourceFingerprint']=hash('sha256',json_encode($r,JSON_THROW_ON_ERROR));return $r;
}
function se_read_values():array {
 require_once __DIR__.'/accounts_shipment_source.php';$db=tt_accounts_exports_db();$keys=['tt30slips','tt30ship','tt40exinstructions','tt35exload','tt30bags','tt33bagreceipts','tt41bagtransfers','transtrade_export_v3_operational'];
 if($db){$q=$db->prepare('SELECT storage_key,payload FROM tt_operation_records WHERE storage_key IN ('.implode(',',array_fill(0,count($keys),'?')).')');$q->execute($keys);return $q->fetchAll(PDO::FETCH_KEY_PAIR);}
 if(!defined('TT_DATA_DIR')||!is_file(TT_DATA_DIR.'/operations.json'))return [];$h=fopen(TT_DATA_DIR.'/operations.json','rb');if(!$h||!flock($h,LOCK_SH))throw new RuntimeException('Shipment estimate sources unavailable.');try{$s=json_decode((string)stream_get_contents($h),true,512,JSON_THROW_ON_ERROR);return array_intersect_key((array)($s['values']??[]),array_flip($keys));}finally{flock($h,LOCK_UN);fclose($h);}
}
function se_posted(array $j,string $entity,string $date):bool {return ($j['entity']??'')===$entity&&in_array($j['status']??'',['Posted','Posted / Bill Pending'],true)&&se_day($j['date']??'')!==''&&se_day($j['date'])<=$date&&empty($j['amendedByPostId'])&&empty($j['reversedByPostId'])&&empty($j['reversalOf'])&&empty($j['meta']['reversalOf'])&&empty($j['meta']['deletedFromBooks']);}
/** Pohanch normally already posts GRNI. Only an issued source still awaiting that bridge is projected. */
function se_pohanch(array $state,array $values,string $date):array {
 $rows=[];$warnings=[];$events=[];$seen=[];foreach((array)($state['events']??[]) as $e)if(($e['eventType']??'')==='COMMODITY_RECEIPT_ACCEPTED'&&se_active($e)&&se_posted((array)($state['journals'][$e['journalId']??'']??[]),(string)($e['entity']??''),$date))$events[($e['entity']??'').'|'.($e['sourceKey']??'')]=true;
 $sodas=[];foreach(['purchaseSodas','purchaseSodasV2'] as $collection)foreach((array)($state[$collection]??[]) as $id=>$s)$sodas[($s['entity']??'').'|'.($s['id']??$id)]=$s;
 foreach(se_rows($values,'tt30slips') as $r){if(!is_array($r)||!se_active($r)||empty($r['slip']))continue;$entity=(string)($r['entity']??'');if(!in_array($entity,['TTI','BRM'],true))continue;$on=se_day($r['unloadingDate']??$r['arrivalDate']??'');if($on===''||$on>$date)continue;
  $bridge=(string)($r['bridgeId']??'');$key='POHANCH|'.($r['millId']??'MILL').'|'.$bridge;if($bridge===''){$warnings[]='Pohanch '.$r['slip'].' lacks its stable source identity.';continue;}if(isset($seen[$entity.'|'.$key]))continue;$seen[$entity.'|'.$key]=true;if(isset($events[$entity.'|'.$key]))continue;
  // Older imports used printed numbers; retain their posted source rather than project a duplicate.
  $already=false;foreach((array)($state['journals']??[]) as $j)if(se_posted($j,$entity,$date)&&in_array($j['sourceType']??'',['COMMODITY_RECEIPT_ACCEPTED','EX_MILL_PURCHASE_LIABILITY'],true)&&($j['meta']['pohanch']??'')===$r['slip']&&($j['meta']['truck']??'')===($r['truck']??''))$already=true;if($already)continue;
  $soda=$sodas[$entity.'|'.($r['sourceSodaId']??'')]??null;$rate=(float)($r['purchaseRate']??0);if($rate<=0&&$soda)$rate=(float)($soda['ratePerKg']??$soda['rate']??0);$kg=(float)($r['payableWeight']??0);$party=(string)($r['party']??$soda['party']??$r['broker']??'');
  $gross=$rate>0&&$kg>0?round($kg*$rate,2):null;$basis='Issued Pohanch: payable kg × saved SODA rate; Accounts bridge pending.';if($gross===null||$party==='')$warnings[]='Pohanch '.$r['slip'].' needs supplier, payable weight and SODA rate review.';
  $rows[]=se_row($entity.'|'.$key,$entity,$party,($party?:'Supplier required').' — SODA '.($r['soda']??'').' · Pohanch '.$r['slip'].' · '.($r['commodity']??$soda['commodity']??''),'PKR',$gross,$on,$basis,['quantity'=>$kg,'unitRate'=>$rate,'sourceKind'=>'COMMODITY','expenseKind'=>'COMMODITY','pohanch'=>$r['slip'],'truck'=>$r['truck']??'']);
 }$sourceWarnings=[];foreach($rows as $row)foreach($warnings as $warning)if(str_starts_with($warning,'Pohanch '.$row['pohanch'].' '))$sourceWarnings[$row['entity'].'|'.$row['sourceKey']][]=$warning;return compact('rows','warnings','sourceWarnings');
}
/** Freight starts only after every native loading allocation for a lot is completed. */
function se_freight(array $state,array $root,array $values,string $date):array {
 require_once __DIR__.'/accounts_shipment_source.php';require_once __DIR__.'/accounts_entry_eligibility.php';
 $rows=[];$warnings=[];$loads=[];$rawShipments=[];$contracts=[];foreach((array)($root['contracts']??[]) as $r)$contracts[(string)($r['ref']??'')]=$r;foreach((array)($root['shipments']??[]) as $r)$rawShipments[(string)($r['id']??'')]=$r;
 foreach(['tt30ship','tt40exinstructions'] as $key)foreach(se_rows($values,$key) as $r){if(!is_array($r)||!se_active($r))continue;$sid=(string)($r['_ttShipmentId']??$r['shipmentId']??'');if($sid!=='')$loads[$sid][]=[$key,$r];}
 if(!$loads)return ['rows'=>[],'warnings'=>[],'sourceWarnings'=>[]];
 $sourceState=$state;unset($sourceState['carryForwardShipments']);
 $agreements=(array)($state['freightAgreements']??[]);$capacity=[];foreach($agreements as $id=>$a)$capacity[(string)$id]=max(0,(int)($a['containerCount']??0));
 $ships=[];foreach(['TTI','BRM'] as $e)foreach(tt_accounts_shipment_rows($root,$e,$sourceState) as $ship){if(($ship['pakistanExporter']??'')!==$e)continue;$ship['estimateEntity']=$e;$ships[]=$ship;}usort($ships,static fn($a,$b)=>strcmp($a['contract'].'|'.$a['id'],$b['contract'].'|'.$b['id']));
 foreach($ships as $ship){$sid=$ship['id'];$native=$loads[$sid]??[];if(!$native)continue;$complete=true;$on='';$numbers=[];
  foreach($native as [$key,$r]){if(($key==='tt30ship'?($r['status']??'')!=='Completed':empty($r['loadingComplete']))){$complete=false;break;}$day=se_day($r['completedAt']??'');if($day===''){$warnings[]=$sid.' — loading is marked complete but its completion date needs review.';$complete=false;break;}if($day>$date){$complete=false;break;}$on=max($on,$day);
   $containers=$key==='tt30ship'?(array)($r['containers']??[]):array_values(array_filter(se_rows($values,'tt35exload'),static fn($c)=>(string)($c['instructionId']??'')===(string)($r['id']??'')&&se_active($c)));
   if(count($containers)!==(int)($r['totalContainers']??0)||!$containers){$warnings[]=$sid.' — completed loading container quantities need reconciliation.';$complete=false;break;}foreach($containers as $c){$n=strtoupper(preg_replace('/[^a-z0-9]/i','',(string)($c['container']??'')));if($n===''){$warnings[]=$sid.' — completed loading has a missing container identity.';$complete=false;break;}$numbers[$n]=true;}
  }if(!$complete)continue;
  $expected=(array)($rawShipments[$sid]['loadingPlan']['allocations']??[]);if($expected&&count($native)!==count($expected)){$warnings[]=$sid.' — loading allocations do not all have completion records.';continue;}
  $entity=$ship['estimateEntity'];$count=count($numbers);$matches=[];foreach($agreements as $aid=>$a){if(!se_active($a)||(($a['entity']??'')!==$entity&&!(($ship['seller']??'')==='TG'&&($a['entity']??'')==='TG'))||($a['dateAgreed']??'')>$date)continue;$exact=($a['shipmentId']??'')===$sid;$contract=empty($a['shipmentId'])&&($a['contractRef']??'')===$ship['contract'];$programme=empty($a['shipmentId'])&&empty($a['contractRef'])&&($a['loadingProgrammeNo']??'')!==''&&($a['loadingProgrammeNo']??'')===($ship['loadingProgramme']??'');if($exact||$contract||$programme)$matches[(string)$aid]=$a;}
  $specific=array_filter($matches,static fn($a)=>($a['shipmentId']??'')===$sid);if($specific)$matches=$specific;
  $bills=tt_accounts_source_bills($state,$entity,'FREIGHT',$sid);$billed=0;$unknown=false;foreach($bills as $b){$canonical=$state['supplierBills'][$b['id']]??[];$billDate=(string)($canonical['billDate']??$state['freightBillsV1'][$b['id']]['billDate']??'');if($billDate===''||$billDate>$date)continue;$posts=(array)($canonical['postingJournalIds']??[]);if(!empty($canonical['journalId']))$posts[]=$canonical['journalId'];$posted=false;foreach($posts as $pid){$j=$state['journals'][$pid]??[];if(($j['status']??'')==='Posted'&&($j['date']??'')<=$date&&empty($j['amendedByPostId'])&&empty($j['reversalOf'])&&empty($j['meta']['reversedByPostId'])&&empty($j['reversedByPostId'])&&empty($j['meta']['deletedFromBooks']))$posted=true;}if(!$posted)continue;if($b['containerCount']===null)$unknown=true;else $billed+=$b['containerCount'];}
  if(!$matches&&!$unknown&&$billed>=$count)continue;
  if(!$matches){$warnings[]=$sid.' — loading complete; agreed freight is missing. Add the freight agreement.';$rows[]=se_row('FREIGHT|'.$entity.'|'.$sid,$entity,$ship['forwarder']?:$ship['shippingLine'],$ship['contract'].' / '.$ship['lot'].' — Freight agreement required','USD',null,$on,'Loading complete; no saved agreement.',['shipmentId'=>$sid,'expenseKind'=>'FREIGHT','sourceKind'=>'FREIGHT']);continue;}

  // Split differing agreed rates only when the allocation is unique; never guess a lot's rate.
  $signatures=[];foreach($matches as $a)$signatures[implode('|',[$a['currency']??'',$a['ratePerContainer']??'',se_key((string)(($a['forwarder']??'')?:($a['shippingLine']??'')))])]=true;
  if(count($signatures)>1){$warnings[]=$sid.' — multiple freight rates require an agreement linked to this shipment.';$rows[]=se_row('FREIGHT|'.$entity.'|'.$sid,$entity,$ship['forwarder']?:$ship['shippingLine'],$ship['contract'].' / '.$ship['lot'].' — Freight rate allocation required','USD',null,$on,end($warnings),['shipmentId'=>$sid,'expenseKind'=>'FREIGHT','sourceKind'=>'FREIGHT']);continue;}
  $a=reset($matches);$available=array_sum(array_map(static fn($id)=>$capacity[$id],array_keys($matches)));$allocated=min($count,$available);$consume=$allocated;foreach(array_keys($matches) as $aid){$used=min($consume,$capacity[$aid]);$capacity[$aid]-=$used;$consume-=$used;}
  if($allocated<$count){$warnings[]=$sid.' — agreed freight covers '.$allocated.' of '.$count.' completed containers.';}
  if($unknown){$warnings[]=$sid.' — posted freight bill has no container allocation; reconcile before adding an estimate.';$rows[]=se_row('FREIGHT|'.$entity.'|'.$sid,$entity,(string)(($a['forwarder']??'')?:($a['shippingLine']??'')),$ship['contract'].' / '.$ship['lot'].' — Actual freight allocation required',(string)($a['currency']??'USD'),null,$on,end($warnings),['shipmentId'=>$sid,'expenseKind'=>'FREIGHT','sourceKind'=>'FREIGHT']);continue;}
  if($allocated<$count&&$billed<$count){$rows[]=se_row('FREIGHT|'.$entity.'|'.$sid.'|UNCOVERED',$entity,(string)(($a['forwarder']??'')?:($a['shippingLine']??'')),$ship['contract'].' / '.$ship['lot'].' — Freight agreement quantity incomplete',(string)($a['currency']??'USD'),null,$on,'Saved agreement does not cover all completed containers.',['sourceKey'=>'FREIGHT|'.$entity.'|'.$sid,'shipmentId'=>$sid,'expenseKind'=>'FREIGHT','sourceKind'=>'FREIGHT']);}
  $remaining=max(0,$allocated-$billed);if($remaining===0)continue;$rate=(float)($a['ratePerContainer']??0);$party=(string)(trim((string)($a['forwarder']??''))?:($a['shippingLine']??''));
  $rows[]=se_row('FREIGHT|'.$entity.'|'.$sid,$entity,$party,$party.' — '.$ship['contract'].' / '.$ship['lot'].' · Freight · '.$remaining.' containers',(string)($a['currency']??'USD'),$rate>0?round($remaining*$rate,2):null,$on,'Completed containers × saved agreed freight, less containers covered by posted actual bills.',['shipmentId'=>$sid,'expenseKind'=>'FREIGHT','sourceKind'=>'FREIGHT','quantity'=>$remaining,'unitRate'=>$rate,'agreementIds'=>array_keys($matches)]);
 }$sourceWarnings=[];foreach($ships as $ship)foreach($warnings as $warning)if(str_starts_with($warning,$ship['id'].' — '))$sourceWarnings[$ship['estimateEntity'].'|FREIGHT|'.$ship['estimateEntity'].'|'.$ship['id']][]=$warning;return compact('rows','warnings','sourceWarnings');
}
function shipment_estimates_source(array $source,array $state,array $root,array $values,string $date):array {
 require_once __DIR__.'/bag_estimates_core.php';
 // A saved estimate explicitly linked to the same operational identity owns that
 // source, including after replacement. Unlinked forecasts do not hide sources.
 $manual=[];foreach((array)($state['estimatedExpenseBills']??[]) as $r){
  if(($r['sourceKey']??'')===''||($r['date']??'')>$date||(($r['status']??'')==='Cancelled'&&($r['cancelDate']??'')<=$date))continue;
  $manual[($r['entity']??'').'|'.$r['sourceKey']]=true;
 }
 $values['transtrade_export_v3_operational']=$root;
 foreach([se_pohanch($state,$values,$date),se_freight($state,$root,$values,$date),bag_estimates_rows($state,$date,$values)] as $part){
  foreach($part['rows'] as $row){
   if(isset($manual[$row['entity'].'|'.$row['sourceKey']]))continue;
   // Native GRNI remains authoritative. An unmatched forecast for its supplier
   // is visible for reconciliation rather than charged a second time.
   foreach($source['rows'] as $native)if(!empty($native['postIds'])&&empty($native['excluded'])&&($native['account']??'')==='2210'&&$native['entity']===$row['entity']&&$native['currency']===$row['currency']&&se_key((string)($native['party']??''))===se_key((string)$row['party'])&&($native['amount']??0)<0){
    $row['excluded']=true;$row['reason']='Existing accrued / unbilled liability for this supplier; reconcile source coverage.';
    $part['warnings'][]=$row['label'].' — '.$row['reason'];break;
   }
   $row['sourceFingerprint']=hash('sha256',json_encode($row,JSON_THROW_ON_ERROR));$source['rows'][]=$row;
  }
  // Drop only warnings whose complete set of source owners is overridden.
  $warningOwners=[];foreach((array)($part['sourceWarnings']??[]) as $owner=>$items)foreach($items as $warning)$warningOwners[$warning][$owner]=true;
  foreach($part['warnings'] as $warning)if(!isset($warningOwners[$warning])||array_diff_key($warningOwners[$warning],$manual))$source['warnings'][]=$warning;
 }
 $source['warnings']=array_values(array_unique($source['warnings']??[]));return $source;
}
