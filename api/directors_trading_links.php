<?php
declare(strict_types=1);
/** Operational reads are explicit; calculation helpers below never write stock or books. */
function dt_read_native_sales():array {
    $key='tt35exportersale';$db=tt_accounts_exports_db();
    if($db){$q=$db->prepare('SELECT payload FROM tt_operation_records WHERE storage_key=? LIMIT 1');$q->execute([$key]);$raw=$q->fetchColumn();}
    else{$p=TT_DATA_DIR.'/operations.json';$v=is_file($p)?json_decode((string)file_get_contents($p),true,512,JSON_THROW_ON_ERROR):[];$raw=$v['values'][$key]??'[]';}
    if($raw===false||$raw===null||$raw==='')return [];$v=is_string($raw)?json_decode($raw,true,512,JSON_THROW_ON_ERROR):$raw;if(!is_array($v)||!array_is_list($v))throw new DomainException('Mill exporter loading source is unavailable.');return $v;
}
function dt_native_parent_matches(array $d,array $r):bool {
    return ($d['status']??'')!=='Deleted'&&($r['entity']??$r['accountEntity']??'')===$d['entity']&&(($r['exporterDealId']??'')===$d['id']||($r['ref']??'')===$d['id']||(!empty($d['millSaleRef'])&&($r['ref']??'')===$d['millSaleRef']));
}
function dt_native_state(array $d,array $sales,string $date):array {
    $loads=[];$issues=[];$seen=[];$parents=[];$mt=0.;
    foreach($sales as $r){if(!is_array($r)||!dt_native_parent_matches($d,$r))continue;$ref=(string)($r['ref']??'');if($ref===''||isset($parents[$ref])){$issues[]='Repeated or missing original Mill sale identity.';continue;}$parents[$ref]=true;
        foreach((array)($r['containers']??[]) as $l){$on=(string)($l['date']??substr((string)($l['gateTime']??''),0,10));$id=(string)($l['id']??$l['bridgeId']??$l['c']??$l['container']??'');$identity=$ref.'|'.$id;$kg=(float)($l['net']??0);
            if($on!==''&&mr_valid_date($on)&&$on>$date)continue;
            if(!mr_valid_date($on)||$on<$d['date']||$id===''||isset($seen[$identity])||$kg<=0||str_contains(strtoupper((string)($l['issue']??'')),'HOLD')){$issues[]='Confirm the original Mill loading identity, date, positive net weight and held disposition.';continue;}$seen[$identity]=true;
            $product=(string)(($r['displayName']??'')?:($r['product']??''));$loads[]=['id'=>$identity,'ref'=>$ref,'date'=>$on,'quantityMT'=>$kg/1000,'product'=>$product,'brand'=>(string)($r['brand']??'')];$mt+=$kg/1000;
        }
    }
    // Retain the actual dispatched quantity, including overdelivery; it completes the quantity commitment.
    return ['loadedMT'=>round($mt,6),'loads'=>$loads,'parentRefs'=>array_keys($parents),'issues'=>array_values(array_unique($issues)),'known'=>!$issues,'fingerprint'=>hash('sha256',json_encode([$loads,$issues],JSON_THROW_ON_ERROR))];
}
/** A Mill record used by a Directors agreement cannot also recognise a local sale. */
function dt_local_sale_owner(array $s,string $entity,string $source):?string {
    if(!str_starts_with($source,'EXPORTER|'))return null;$ref=substr($source,9);
    foreach(array_filter((array)($s['exporterDeals']??[]),static fn($row)=>($row['status']??'')!=='Deleted') as $d)if(($d['entity']??'')===$entity&&($d['direction']??'')==='SALE'&&($ref===($d['id']??'')||str_starts_with($ref,($d['id']??'').'|MILL|')||($ref!==''&&$ref===($d['millSaleRef']??''))))return (string)$d['id'];return null;
}
function dt_sale_cost(array $s,array $d,array $native,float $invoiceMT):array {
    $by=[];$sources=[];$issues=[];$total=(float)$native['loadedMT'];
    if($invoiceMT<=0)return ['byAccount'=>[],'totalPKR'=>0.,'sources'=>[],'issues'=>[]];
    if(!$native['known']||$total+.000001<$invoiceMT||$total<=0)return ['byAccount'=>[],'totalPKR'=>null,'sources'=>[],'issues'=>['Link dated, committed own Mill dispatch for every contributed tonne before posting.']];
    $normal=static fn($v)=>strtoupper(trim((string)preg_replace('/\s+/u',' ',(string)$v)));
    foreach($native['loads'] as $load){$best=null;$rank=0;foreach(['localSalesCostRates'=>1,'inventoryCostRates'=>2] as $collection=>$priority)foreach((array)($s[$collection]??[]) as $r){if(($r['entity']??'')!==$d['entity']||($r['status']??'Active')!=='Active'||($r['effectiveFrom']??'')>$load['date']||$normal($r['product']??'')!==$normal($load['product']))continue;if(!$best||$r['effectiveFrom']>$best['effectiveFrom']||($r['effectiveFrom']===$best['effectiveFrom']&&$priority>$rank)){$best=$r;$rank=$priority;}}
        if(!$best||(float)($best['costPerKg']??0)<=0||!in_array($best['inventoryAccount']??'',['1310','1320','1330'],true)){$issues[]='Approved Accounts stock cost is missing for '.$load['product'].' on '.$load['date'].'.';continue;}
        $amount=round($load['quantityMT']*1000*(float)$best['costPerKg']*$invoiceMT/$total,2);$account=$best['inventoryAccount'];$by[$account]=round(($by[$account]??0)+$amount,2);$sources[]=$load+['costRateId'=>$best['id']??'','costPerKg'=>$best['costPerKg'],'costPKR'=>$amount,'inventoryAccount'=>$account];
    }
    foreach((array)($s['localSalesCandidates']??[]) as $c)if(($c['entity']??'')===$d['entity']&&!empty($c['journalId'])&&dt_local_sale_owner(['exporterDeals'=>[$d]],$d['entity'],(string)($c['sourceKey']??''))){$j=$s['journals'][$c['journalId']]??[];if(($j['status']??'')==='Posted'&&empty($j['reversedByPostId']))$issues[]='This Mill sale already has a local-sale posting. Accounts must reconcile/reverse that original before Directors settlement.';}
    return ['byAccount'=>$by,'totalPKR'=>$issues?null:round(array_sum($by),2),'sources'=>$sources,'issues'=>array_values(array_unique($issues))];
}

function dt_review_context(array $s,array $d,array $nativeSales,string $date):array {
 $n=dt_native_state($d,$nativeSales,$date);$mt=0.;foreach((array)($d['loadings']??[]) as $l)if($l['date']<=$date)$mt+=$l['quantityMT'];return [$n,dt_sale_cost($s,$d,$n,$mt)['sources']];
}

function dt_validate_mill_link(array $s,array $d,array $nativeSales):void {
 if(empty($d['millSaleRef']))return;if($d['direction']!=='SALE')throw new DomainException('Only our sale through another exporter can link our Mill dispatch.');$rows=array_values(array_filter($nativeSales,static fn($r)=>($r['entity']??$r['accountEntity']??'')===$d['entity']&&($r['ref']??'')===$d['millSaleRef']));if(count($rows)===1&&!empty($rows[0]['exporterDealId'])&&$rows[0]['exporterDealId']!==($d['id']??''))throw new DomainException('That Mill dispatch already belongs to a Directors agreement.');if(count($rows)!==1)throw new DomainException('Choose one original Mill exporter loading record in this company.');foreach(array_filter((array)($s['exporterDeals']??[]),static fn($row)=>($row['status']??'')!=='Deleted') as $other)if(($other['id']??'')!==($d['id']??'')&&$other['entity']===$d['entity']&&(($other['millSaleRef']??'')===$d['millSaleRef']||($other['id']??'')===$d['millSaleRef']))throw new DomainException('That Mill loading already belongs to another exporter agreement.');
}

function dt_settlement_deltas(array $s,array $d,array $working):array {
    $previous=[];foreach((array)($d['settlementPosts']??[]) as $p){$j=$s['journals'][$p['postId']]??[];if(($j['status']??'')!=='Posted'||!empty($j['reversedByPostId']))continue;$deltas=$p['settlementByLoadingDelta']??null;
        if($deltas===null){$old=(array)($p['working']['rows']??[]);if(abs(array_sum(array_column($old,'settlementPKR'))-$p['amountPKR'])>.005)throw new DomainException('Reconcile the historical settlement invoice allocation before another adjustment.');$deltas=[];foreach($old as $r)$deltas[$r['loadingId']]=$r['settlementPKR'];}
        foreach($deltas as $id=>$amount)$previous[$id]=($previous[$id]??0)+$amount;
    }
    $out=[];foreach($working['rows'] as $r){$delta=round($r['settlementPKR']-($previous[$r['loadingId']]??0),2);if(abs($delta)>=.005)$out[$r['loadingId']]=$delta;}return $out;
}

function dt_historical_fob(array $s,array $lots,string $date):?float {
 $weight=0.;$cost=0.;foreach($lots as $lot){try{if(empty($lot['completed'])||($lot['invoiceDate']??'')===''||$lot['invoiceDate']>=$date||$lot['quantityMT']<=0)continue;$n=0.;foreach(dt_expenses($s,$lot,$date) as $bill)if(!in_array($bill['category'],['FREIGHT','INSURANCE','BANK'],true)&&empty($bill['invalidAllocation']))$n+=$bill['amountPKR'];if($n<=0)continue;$weighted=0.;$fraction=0.;foreach(dt_advice($s,$lot,$date) as $a){$weighted+=$a['realizationRate']*$a['invoiceFraction'];$fraction+=$a['invoiceFraction'];}if($fraction<.999999||$fraction>1.000001)continue;$fx=$weighted/$fraction;$cost+=$n/$fx;$weight+=$lot['quantityMT'];}catch(DomainException){continue;}}return $weight>0?round($cost/$weight,4):null;
}

function dt_statement_charges(array $rows):array {
 if(!array_is_list($rows)||count($rows)>100)throw new DomainException('Use at most 100 statement charge rows.');$out=[];foreach($rows as $r){$description=gm_text($r['description']??'',300);if($description==='')throw new DomainException('Describe every other-exporter statement charge.');$out[]=['id'=>dt_id([],'CHG'),'category'=>dt_enum($r['category']??'OTHER',['FOB','FREIGHT','INSURANCE','BANK','OTHER'],'charge category'),'description'=>$description,'scope'=>dt_enum($r['scope']??'WHOLE_INVOICE',['WHOLE_INVOICE','OUR_SHARE'],'statement charge allocation'),'amountPKR'=>gm_number($r['amountPKR']??null,'statement charge')];}return $out;
}
