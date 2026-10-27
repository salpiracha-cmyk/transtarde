<?php
declare(strict_types=1);
require_once __DIR__.'/group_daily_core.php';
require_once __DIR__.'/customer_receivables_core.php';
require_once __DIR__.'/directors_cost_defaults_core.php';
require_once __DIR__.'/fi_credit_advice_link.php';

/** Strict shared snapshot: financial/operational reads never create sources. */
function br_operations():array {
    $keys=array_values(array_unique(array_merge(TT_INV_SOURCE_KEYS,['transtrade_export_v3_operational','tt35exload','tt35exmill','tt40exinstructions','tt30bags','tt33bagreceipts','tt41bagtransfers','tt38kebills','tt38labourbills','tt38reprocessbills','tt37processingexpenses','tt35localsales','tt35exportersale'])));
    $db=tt_accounts_exports_db();
    if($db){$db->beginTransaction();try{$q=$db->prepare('SELECT storage_key,payload,version FROM tt_operation_records WHERE storage_key IN ('.implode(',',array_fill(0,count($keys),'?')).')');$q->execute($keys);$values=[];$versions=[];foreach($q->fetchAll() as $r){$values[$r['storage_key']]=$r['payload'];$versions[$r['storage_key']]=(int)$r['version'];}$db->commit();return ['values'=>$values,'versions'=>$versions,'sourceBackend'=>'mysql'];}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}}
    $path=TT_DATA_DIR.'/operations.json';if(!is_file($path))throw new RuntimeException('Operational source unavailable.');$h=fopen($path,'rb');if(!$h||!flock($h,LOCK_SH))throw new RuntimeException('Operational source unavailable.');try{$s=mr_decode_store((string)stream_get_contents($h));if(!is_array($s['values']??null))throw new RuntimeException('Invalid operational source.');return $s;}finally{flock($h,LOCK_UN);fclose($h);}
}
function br_root(array $ops):array {
    $raw=$ops['values']['transtrade_export_v3_operational']??null;if($raw===null)throw new RuntimeException('Exports source unavailable.');$root=is_string($raw)?json_decode($raw,true,512,JSON_THROW_ON_ERROR):$raw;
    if(!is_array($root)||!is_array($root['contracts']??null)||!is_array($root['shipments']??null))throw new RuntimeException('Invalid Exports source.');return $root;
}
function br_sum(array $rows,string $field):?float {$total=0.;foreach($rows as $r){if(!isset($r[$field])||!is_numeric($r[$field]))return null;$total+=(float)$r[$field];}return round($total,6);}
function br_currency_totals(array $rows,string $field='amount'):array {$out=[];foreach($rows as $r){$c=(string)$r['currency'];$out[$c]=array_key_exists($c,$out)&&$out[$c]===null?null:(!isset($r[$field])?null:round(($out[$c]??0)+(float)$r[$field],2));}return $out;}
function br_pkr_total(array $rows,array $rates,string $field='amount'):?float {$total=0.;foreach($rows as $r){$rate=$r['currency']==='PKR'?1:($rates[$r['currency']]??null);if(!$rate||!isset($r[$field]))return null;$total+=round((float)$r[$field]*$rate,2);}return round($total,2);}
function br_internal_party(string $party):bool {return preg_match('/^(?:TTI|BRM|TG|TRANSTRADE INTERNATIONAL|TRANS GRAINS(?: DMCC| FOODSTUFF TRADING(?: L\.?L\.?C\.?)?)?|BUKSH RICE(?: MILLS?)?)$/i',trim($party))===1;}
function br_arp(array $books,array $ops,array $root,string $date,array $rates):array {
    $source=gd_sources($books,$ops,$date);$sourceBooks=tt_fi_advice_project($books,$root);
    $receivables=tt_customer_receivables($sourceBooks,$root,['TTI','BRM','TG'],$date)['rows'];
    $receivables=array_values(array_filter($receivables,static fn($r)=>!br_internal_party((string)$r['label'])));
    $bankGroups=[];foreach($source['financials']['rows'] as $r){if(!empty($r['excluded'])||($r['cashflowSection']??'')!=='banks')continue;$key=json_encode([$r['entity'],$r['currency'],$r['account'],$r['bankAccountId']?:$r['id']]);if(!isset($bankGroups[$key])){$bankGroups[$key]=$r;$bankGroups[$key]['label']=$r['bankLabel']?:$r['label'];$bankGroups[$key]['amount']=0.;}$bankGroups[$key]['amount']=$bankGroups[$key]['amount']===null||$r['amount']===null?null:round($bankGroups[$key]['amount']+$r['amount'],2);}$banks=array_values($bankGroups);
    $purchases=$source['purchaseOrders'];
    foreach($purchases as &$p){if(!$p['receivedKnown'])$p['receivedMT']=null;if(!$p['quantityKnown']){$p['contractedMinMT']=null;$p['contractedMaxMT']=null;}}unset($p);
    $sales=array_values(array_filter($source['salesContracts'],static fn($r)=>!isset($r['exporterDealId'])));
    foreach($sales as &$sale)if(!$sale['loadedKnown'])$sale['loadedMT']=null;unset($sale);
    $loading=[];$lotMap=array_column($root['shipments'],null,'id');$contracts=array_column($root['contracts'],null,'ref');
    foreach(['tt30ship','tt40exinstructions'] as $key)foreach(gd_rows($ops['values'],$key) as $r){
        if(!se_active($r))continue;$sid=(string)($r['_ttShipmentId']??$r['shipmentId']??'');$lot=$lotMap[$sid]??[];$contract=$contracts[$lot['contractRef']??$r['contractRef']??'']??[];
        $on=se_day($r['date']??$r['createdAt']??$lot['loadingPlan']['date']??'');if($on!==''&&$on>$date)continue;
        $completed=$key==='tt30ship'?($r['status']??'')==='Completed':!empty($r['loadingComplete']);$completion=se_day($r['completedAt']??'');if($completed&&($completion===''||$completion<=$date))continue;
        $containers=$key==='tt30ship'?(array)($r['containers']??[]):array_values(array_filter(gd_rows($ops['values'],'tt35exload'),static fn($x)=>($x['instructionId']??'')===($r['id']??'')&&se_active($x)));
        $kg=0.;$count=0;$unknown=false;foreach($containers as $c){$day=se_day($c['date']??$c['loadingAt']??$c['gateTime']??'');if($day===''){$unknown=true;continue;}if($day>$date)continue;$weight=(float)($key==='tt30ship'?($c['weight']??0):($c['kg']??0));if($weight>0){$kg+=$weight;$count++;}}
        $loading[]=['id'=>$key.'|'.($r['id']??''),'shipmentId'=>$sid,'entity'=>$lot['seller']??$contract['seller']??$r['entity']??'','contractRef'=>$lot['contractRef']??$r['contractRef']??'','lot'=>$lot['lotId']??'','location'=>$r['millName']??$r['locationName']??$r['mill']??'','plannedContainers'=>$r['totalContainers']??null,'loadedContainers'=>$unknown?null:$count,'loadedMT'=>$unknown?null:round($kg/1000,6),'status'=>$on===''||$unknown?'Date requires review':'Loading in progress'];
    }
    $breakdown=[];foreach(['TTI','BRM','TG'] as $e){$own=static fn($items)=>array_values(array_filter($items,static fn($r)=>$r['entity']===$e));$breakdown[]=['entity'=>$e,'banks'=>br_currency_totals($own($banks)),'bankPKR'=>br_pkr_total($own($banks),$rates),'receivables'=>br_currency_totals($own($receivables)),'receivablePKR'=>br_pkr_total($own($receivables),$rates),'purchaseCount'=>count($own($purchases)),'salesCount'=>count($own($sales))];}
    return ['date'=>$date,'rates'=>$rates,'totals'=>['banks'=>br_currency_totals($banks),'bankPKR'=>br_pkr_total($banks,$rates),'receivables'=>br_currency_totals($receivables),'receivablePKR'=>br_pkr_total($receivables,$rates),'purchaseCount'=>count($purchases),'openPurchaseCount'=>count(array_filter($purchases,static fn($r)=>!$r['closed'])),'purchasedMinMT'=>br_sum($purchases,'contractedMinMT'),'purchasedMaxMT'=>br_sum($purchases,'contractedMaxMT'),'receivedMT'=>br_sum($purchases,'receivedMT'),'purchaseBalanceMinMT'=>br_sum($purchases,'undeliveredMinMT'),'purchaseBalanceMaxMT'=>br_sum($purchases,'undeliveredMaxMT'),'salesCount'=>count($sales),'openSalesCount'=>count(array_filter($sales,static fn($r)=>!$r['closed'])),'contractedMT'=>br_sum($sales,'contractedMT'),'loadedMT'=>br_sum($sales,'loadedMT'),'pendingMT'=>br_sum($sales,'outstandingMT'),'loadingCount'=>count($loading)],'companies'=>$breakdown,'banks'=>$banks,'purchases'=>$purchases,'sales'=>$sales,'loading'=>$loading,'receivables'=>$receivables,'holds'=>array_merge($source['holds'],array_map(static fn($s)=>['message'=>$s],$source['financials']['warnings'])),'basis'=>'Group overview through the selected Karachi date. Current saved contract versions; quantities are MT. Issued final buyer invoices less allocated receipts/advances. Company detail is a breakdown of group totals, not additional balances.'];
}
