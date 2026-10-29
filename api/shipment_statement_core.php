<?php
declare(strict_types=1);
require_once __DIR__.'/business_reports_core.php';
require_once __DIR__.'/shipment_profitability_core.php';
require_once __DIR__.'/accounts_ledger_visibility.php';
require_once __DIR__.'/mill_recovery_cost.php';
require_once __DIR__.'/soda_buying_for_core.php';

function ss_lots(array $root):array {
    $out=[];foreach(['TTI','BRM'] as $e)foreach(dt_lots($root,$e) as $id=>$r){if(($r['pakistanExporter']??'')!==$e)continue;$out[$id]=$r+['costEntity'=>$e];}return $out;
}
function ss_link(array $r,array $lot,array $candidateIds=[]):bool {
    foreach(['shipmentId','operationalShipmentId'] as $f)if(!empty($r[$f]))return $r[$f]===$lot['id'];
    foreach(['candidateId','exportCandidateId','sourceCandidateId','sourceLiabilityId','pakistanCandidateId'] as $f)if(!empty($r[$f])&&in_array($r[$f],$candidateIds,true))return true;
    if(($r['contractRef']??'')===$lot['contract']&&in_array((string)($r['lotRef']??''),[$lot['lot'],$lot['id']],true))return true;
    foreach(['invoiceRef','commercialInvoiceNo','actualBlNo','loadingProgrammeNo'] as $f){$expected=match($f){'invoiceRef','commercialInvoiceNo'=>$lot['invoice']??'','actualBlNo'=>$lot['bl']??'',default=>$lot['loadingProgramme']??''};if($expected!==''&&($r[$f]??'')===$expected&&(!isset($r['contractRef'])||$r['contractRef']===$lot['contract']))return true;}return false;
}
function ss_line(string $label,?float $usd,?float $pkr,float $mt,string $source,string $basis='Actual'):array {return ['label'=>$label,'usd'=>$usd===null?null:round($usd,2),'pkr'=>$pkr===null?null:round($pkr,2),'perMT'=>$pkr===null||$mt<=0?null:round($pkr/$mt,2),'source'=>$source,'basis'=>$basis];}
function ss_expense_share(array $meta,array $lot,array $ids):float {
    if(!empty($meta['liabilityAllocations'])){$all=0.;$matched=0.;foreach($meta['liabilityAllocations'] as $a){$amount=(float)($a['amountNative']??0);$all+=$amount;if(in_array($a['id']??$a['sourceLiabilityId']??'',$ids,true))$matched+=$amount;}return $all>0?$matched/$all:0.;}
    return ss_link($meta,$lot,$ids)?1.:0.;
}
function ss_live_post(array $s,string $id,string $entity,string $date):array {$j=sp_posted_journal($s,$id,$entity,alv_hidden($s),sp_active_reversals($s,alv_hidden($s)));if(($j['date']??'')===''||$j['date']>$date)throw new DomainException('Post '.$id.' is outside the report date.');return $j;}
/** Only original, allocated credit-advice slices determine realised FX and deductions. */
function ss_realization(array $s,array $root,array $lot,array $ship,array $candidates,string $date,array $rates):array {
    $mt=(float)$lot['quantityMT'];$price=$lot['contractPrice'];$cur=(string)$lot['currency'];$tg=$lot['seller']==='TG';$holds=[];$notes=[];$slices=[];$matched=0.;$basePKR=0.;$marginPKR=0.;$charges=0.;$extraCharges=0.;$correspondent=0.;
    $price=is_numeric($price)&&$price>0?(float)$price:null;$pkPrice=$tg?(float)($ship['tgdocs']['rate']??$ship['customs']['rate']??0):$price;
    if(!$price||$mt<=0)$holds[]='Actual loaded quantity and exact contract packing price are required.';
    if($tg&&(!$pkPrice||empty($ship['tgdocs']['saved'])))$holds[]='Saved Pakistan → TG price is required.';
    if($cur!=='USD'&&$cur!=='PKR')$holds[]='Shipment currency '.$cur.' requires an explicit posted PKR conversion.';
    $links=tt_receipt_invoice_links($s,$root,tt_fi_advice_matches($root,$s));$ids=array_keys($candidates);$expectedRef=$tg?($ship['tgdocs']['customsInvoiceNo']??$ship['customs']['invoiceNo']??''):($lot['invoice']??'');
    foreach((array)($s['exportReceipts']??[]) as $r){
        if(($r['entity']??'')!==$lot['costEntity']||($r['status']??'')!=='Accounts Approved / Posted'||($r['date']??'')>$date)continue;
        $allocated=0.;$realized=0.;$explicit=false;foreach((array)($r['allocations']??[]) as $a){$id=(string)($a['targetId']??'');$match=in_array($id,$ids,true)||$id==='PACK|'.$lot['id']||(($a['contractRef']??'')===$lot['contract']&&$expectedRef!==''&&($a['invoiceRef']??'')===$expectedRef);if($match){$allocated+=(float)($a['foreignAmount']??0);$realized+=(float)($a['realizedPkr']??((float)($a['foreignAmount']??0)*(float)($r['realizationRate']??0)));$explicit=true;}}
        if(!$explicit)foreach($links[$r['id']]??[] as $a)if(($a['lotId']??'')===$lot['id'])$allocated+=(float)$a['amount'];
        if($allocated<=0)continue;
        try{$j=ss_live_post($s,(string)($r['journalId']??''),$lot['costEntity'],$date);if(($j['meta']['receiptId']??'')!==$r['id'])throw new DomainException('Credit advice does not own its posting.');}catch(DomainException $e){$holds[]=$e->getMessage();continue;}
        $total=array_sum(array_column((array)$r['allocations'],'foreignAmount'));$fx=(float)($r['realizationRate']??0);
        if($total<=0||$allocated>$total+.01||$fx<=0||($r['transactionCurrency']??'')!==$cur){$holds[]='Credit advice '.$r['id'].' needs currency / allocation reconciliation.';continue;}
        $portion=$allocated/$total;$base=$pkPrice>0?$allocated/$pkPrice:0.;$matched+=$base;
        $totalRealized=0.;foreach($r['allocations'] as $a)$totalRealized+=(float)($a['realizedPkr']??((float)$a['foreignAmount']*$fx));
        if(isset($r['grossPkrEquivalent'])&&abs($totalRealized-(float)$r['grossPkrEquivalent']-(float)($r['correspondentPkrAmount']??0))>.02)$holds[]='Credit advice '.$r['id'].' does not reconcile to its saved gross realization.';
        if(isset($j['meta']['allocations'])&&$j['meta']['allocations']!==$r['allocations'])$holds[]='Credit advice '.$r['id'].' allocations differ from its original posting.';
        $baseValue=$explicit?$realized:$totalRealized*$portion;$effectiveFX=$baseValue/$allocated;$delta=$price!==null&&$pkPrice>0?($price-$pkPrice)*$base*$effectiveFX:0.;$deduct=0.;
        foreach((array)($r['deductions']??[]) as $d){if(($d['mode']??'')==='SEPARATE_DEBIT'){try{$separate=ss_live_post($s,(string)($r['separateChargeJournalId']??''),$lot['costEntity'],$date);if(($separate['meta']['receiptId']??'')!==$r['id'])throw new DomainException('Separate bank charges do not belong to this advice.');}catch(DomainException $e){$holds[]=$e->getMessage();continue;}}$deduct+=(float)($d['amount']??0)*$portion;}
        $corr=(float)($r['correspondentPkrAmount']??0)*$portion;$rate=$baseValue>0?($deduct+$corr)/$baseValue:0.;$deltaCharge=$tg?$delta*$rate:0.;
        $basePKR+=$baseValue;$marginPKR+=$delta;$charges+=$deduct;$correspondent+=$corr;$extraCharges+=$deltaCharge;
        $slices[]=['receiptId'=>$r['id'],'postId'=>$r['journalId'],'date'=>$r['date'],'usd'=>$cur==='USD'?$allocated:null,'matchedMT'=>round($base,6),'fx'=>$effectiveFX,'grossPKR'=>round($baseValue,2),'deductionsPKR'=>round($deduct+$corr,2),'deductionPct'=>round($rate*100,6),'tgDifferencePKR'=>round($delta,2),'tgDifferenceDeductionPKR'=>round($deltaCharge,2)];
    }
    if($matched>$mt+.001)$holds[]='Allocated receipts exceed the loaded quantity; review invoice allocations.';
    $remaining=max(0,$mt-$matched);$fx=$cur==='PKR'?1:($rates[$cur]??null);
    if($remaining>.000001){if(!$fx)$holds[]='Unrealised quantity needs a saved dated '.$cur.'/PKR rate.';else{$basePKR+=$remaining*($pkPrice??0)*$fx;$marginPKR+=$tg?$remaining*(($price??0)-($pkPrice??0))*$fx:0.;}$notes[]='Unrealised '.round($remaining,6).' MT valued at the displayed saved rate; future bank deductions are not yet actual.';}
    $unknown=(bool)$holds;$rows=[ss_line($tg?'Pakistan contract value':'Contract sales value',$cur==='USD'&&$pkPrice!==null?$mt*$pkPrice:null,$unknown?null:$basePKR,$mt,$lot['contract'],$remaining>.000001?'Actual contract / partly provisional FX':'Contract / original advice rates')];
    if($tg)$rows[]=ss_line('TG price difference',$cur==='USD'&&$price!==null?$mt*($price-$pkPrice):null,$unknown?null:$marginPKR,$mt,$lot['contract'].' + saved TG pack','TG buyer price less Pakistan price');
    $rows[]=ss_line('Credit-advice WHT / bank deductions',null,-$charges,$mt,implode(', ',array_column($slices,'postId')));
    $rows[]=ss_line('Correspondent bank charges',null,-$correspondent,$mt,implode(', ',array_column($slices,'postId')));
    if($tg)$rows[]=ss_line('Deductions on TG price difference',null,-$extraCharges,$mt,implode(', ',array_column($slices,'postId')),'Same deduction proportion as each original credit advice; management allocation');
    return ['rows'=>$rows,'netPKR'=>$unknown?null:round($basePKR+$marginPKR-$charges-$correspondent-$extraCharges,2),'grossPKR'=>$unknown?null:round($basePKR+$marginPKR,2),'holds'=>$holds,'notes'=>$notes,'slices'=>$slices,'unrealisedMT'=>round($remaining,6),'unrealisedFX'=>$fx];
}
function ss_expenses(array $s,array $lot,array $candidates,string $date,array $rates):array {
    $rows=[];$holds=[];$used=[];$mt=(float)$lot['quantityMT'];$hidden=alv_hidden($s);$ids=array_keys($candidates);
    foreach((array)($s['supplierBills']??[]) as $b){if(!in_array($b['entity']??'',[ $lot['costEntity'],$lot['seller']],true)||alv_record_hidden($b,$hidden)||($b['billDate']??'')>$date)continue;$record=(array)($b['sourceRecord']??[]);$parts=(array)($record['shipmentSections']??$record['lines']??[]);$amount=0.;$matched=false;
        if($parts){foreach($parts as $part)if(ss_link($part,$lot,$ids)){$amount+=(float)($part['amount']??0);$matched=true;}}elseif(ss_link($record,$lot,$ids)||ss_link($b,$lot,$ids)){$amount=(float)($b['supplierPayableTotal']??0);$matched=true;}
        if(!$matched)continue;$posts=(array)($b['postingJournalIds']??(!empty($b['journalId'])?[$b['journalId']]:[]));
        try{sp_bill_evidence($s,$b,$b['entity']);foreach($posts as $pid){$j=$s['journals'][$pid]??[];if(empty($b['billDate'])||empty($j['date'])||$j['date']>$date)throw new DomainException('Bill '.$b['id'].' has an undated or later posting; historical allocation requires review.');$used[$pid]=true;}}catch(DomainException $e){$holds[]=$e->getMessage();continue;}
        if($parts&&count($parts)===1)$amount=(float)$b['supplierPayableTotal'];elseif($parts&&abs(array_sum(array_column($parts,'amount'))-(float)$b['supplierPayableTotal'])>.005)$holds[]='Bill '.$b['id'].' has an unallocated invoice adjustment.';
        $cur=$b['entity']==='TG'?'AED':'PKR';$fx=$cur==='PKR'?1:($rates[$cur]??null);if(!$fx)$holds[]='TG expense '.$b['id'].' needs AED/PKR conversion.';
        $rows[]=ss_line(ucfirst(strtolower($b['category']??'Shipment expense')).' · '.($b['vendor']??$b['supplier']??$b['id']),null,$fx?-$amount*$fx:null,$mt,implode(', ',$posts),$cur==='PKR'?'Posted bill':'Posted AED bill / saved report FX');
    }
    $shared=sp_fellow_shared_expenses($s,$lot['costEntity'],$hidden,sp_active_reversals($s,$hidden));
    foreach((array)($s['exporterExpenseAllocations']??[]) as $a){if(($a['entity']??'')!==$lot['costEntity']||!($a['active']??true)||($a['shipmentId']??'')!==$lot['id'])continue;
        if(($a['date']??'')>$date||($s['journals'][$a['postId']]['date']??'')>$date)continue;
        if($shared['holds']){$holds=array_merge($holds,$shared['holds']);continue;}
        $used[$a['postId']]=true;$rows[]=ss_line('Allocated '.strtolower($a['category']).' expense',null,-(float)$a['amountPKR'],$mt,$a['sourceId'].' · '.$a['postId'],'Original posted shared cost / explicit allocation');
    }
    foreach((array)($s['journals']??[]) as $id=>$j){$share=ss_expense_share((array)($j['meta']??[]),$lot,$ids);if(isset($used[$id])||!in_array($j['entity']??'',[$lot['costEntity'],$lot['seller']],true)||($j['date']??'')>$date||isset($hidden[$id])||$share<=0)continue;
        // Credit-advice charges and goods costs have their own workings above/below.
        if(in_array($j['sourceType']??'',['EXPORT_FOREIGN_RECEIPT','EXPORT_REALIZATION_BANK_DEBITS','EXPORT_COGS','TG_INTERCOMPANY_PAYABLE_RECOGNIZED','EXPORT_SALE_RECOGNIZED','TG_INTERCOMPANY_PAKISTAN'],true))continue;
        $cost=0.;foreach((array)($j['lines']??[]) as $l)if(preg_match('/^[567]/',(string)($l['account']??''))&&!in_array(($l['account']??''),['5100','7100'],true))$cost+=(float)($l['debit']??0)-(float)($l['credit']??0);if(abs($cost)<.005)continue;
        try{ss_live_post($s,(string)$id,$j['entity'],$date);}catch(DomainException $e){$holds[]=$e->getMessage();continue;}
        $cur=$j['entity']==='TG'?'AED':'PKR';$fx=$cur==='PKR'?1:($rates[$cur]??null);$native=(string)($j['meta']['nativeCurrency']??'');$usd=$native==='USD'&&isset($j['meta']['nativeAmount'])?(float)$j['meta']['nativeAmount']:null;
        $rows[]=ss_line((string)($j['narration']??'Linked shipment expense'),$usd===null?null:-$usd*$share,$fx?-$cost*$fx*$share:null,$mt,(string)$id,($cur==='PKR'?'Posted':'Posted AED / saved report FX').($share<1?' / proportional invoice allocation':''));if(!$fx)$holds[]='Post '.$id.' needs AED/PKR conversion.';
    }
    return ['rows'=>$rows,'totalPKR'=>br_sum($rows,'pkr'),'holds'=>$holds];
}
/** One selected saved recipe; never silently substitute another shipment's recovery. */
function ss_recipe_goods(array $saved,float $mt):array {
    $i=$saved['input'];$qty=$mt*1000;$finished=0.;$sum=0.;$by=0.;$details=[];
    foreach((array)$i['outputs'] as $o){$yield=gm_number($o['recoveryPct']??null,'saved recovery');$sum+=$yield;if(($o['kind']??'')==='finished')$finished+=$yield/100;}
    if(abs($sum-100)>.00001||$finished<=0)throw new DomainException('Saved recovery must total 100%.');$raw=$qty/$finished;$factor=match($i['purchaseUnit']??'KG'){'KG'=>1.,'MAUND'=>40.,'MT'=>1000.,default=>throw new DomainException('Saved purchase unit is invalid.')};$purchase=gm_number($i['cashPrice']??null,'saved purchase rate')/$factor;
    foreach($i['outputs'] as $o)if(($o['kind']??'')==='byproduct'&&(float)$o['recoveryPct']>0){$kg=$raw*(float)$o['recoveryPct']/100;$rate=gm_number($o['ratePKRPerKg']??null,'saved by-product rate');$by+=$kg*$rate;$details[]=['product'=>$o['product'],'kg'=>$kg,'rate'=>$rate,'valuePKR'=>round($kg*$rate,2)];}
    $mill=dt_milling_rate($i);$processing=gm_number($i['processingPKRPerKg']??0,'processing rate');$cost=$raw*($purchase+$mill+$processing)-$by;if($cost<0)throw new DomainException('By-product credit exceeds raw and production costs.');
    return ['amountPKR'=>round($cost,2),'basis'=>'Provisional · saved costing assumptions','source'=>$saved['id'].' · '.$saved['date'].' · '.$saved['name'],'rawKg'=>$raw,'finishedKg'=>$qty,'recoveryPct'=>$finished*100,'rawRate'=>$purchase,'rawValue'=>$raw*$purchase,'millingRate'=>$mill,'millingValue'=>$raw*$mill,'processingRate'=>$processing,'processingValue'=>$raw*$processing,'byproductValue'=>$by,'byproducts'=>$details,'costPerKg'=>$qty>0?$cost/$qty:null,'costPerMT'=>$mt>0?$cost/$mt:null];
}
/** Contract production must cover all own cargo, after external supplier loadings. */
function ss_contract_own_kg(array $books,array $ops,string $entity,string $contract,string $date):float {
    if(!isset($ops['values']['transtrade_export_v3_operational']))return 0.;$kg=0.;
    foreach(ss_lots(br_root($ops)) as $lot){
        if($lot['costEntity']!==$entity||$lot['contract']!==$contract||($lot['invoiceDate']??'')>$date)continue;
        $external=0.;foreach(array_filter((array)($books['exporterDeals']??[]),static fn($row)=>($row['status']??'')!=='Deleted') as $d){if(($d['entity']??'')!==$entity||($d['direction']??'')!=='PURCHASE')continue;
            foreach((array)($d['loadings']??[]) as $l)if(($l['shipmentId']??'')===$lot['id']&&($l['date']??'')<=$date)$external+=(float)($l['quantityMT']??0)*1000;
        }
        $kg+=max(0,$lot['quantityMT']*1000-$external);
    }
    return $kg;
}
function ss_contract_goods(array $s,array $ops,array $lot,string $date,float $mt):array {
    $runs=pc_op($ops,'tt30prod');$report=mr_attach_costs(mr_report($runs,['entity'=>$lot['costEntity'],'mode'=>'contract','contract'=>$lot['contract'],'to'=>$date]),$runs,mr_cost_sources($s,$ops,$lot['costEntity'],true));
    $group=null;foreach($report['groups'] as $g)if($g['key']===$lot['contract'])$group=$g;
    $ready=sb_ready_pool($s,$lot['costEntity'],'EXPORT|'.$lot['contract'],$date);
    if($ready['kg']>0){
        $produced=$group?(float)$group['readyKg']:0.;
        if($produced>0&&empty($group['cost']['available']))throw new DomainException($group['cost']['status']);
        $kg=$ready['kg']+$produced;$value=$ready['value']+($group['cost']['readyRiceValue']??0);$needed=$mt*1000;
        $shipped=ss_contract_own_kg($s,$ops,$lot['costEntity'],$lot['contract'],$date);
        if(max($shipped,$needed)>$kg+.001)throw new DomainException('Contract shipments exceed received allocated purchases and finished production. Complete the SODA allocation / receipt.');
        $amount=round($needed*$value/$kg,2);
        return ['amountPKR'=>$amount,'source'=>implode(', ',$ready['sources']).($produced>0?'; contract production':''),'basis'=>'Management estimate · allocated accepted ready-rice receipts'.($ready['provisional']?' (final purchase bill pending)':' (finalized purchase bills)').($produced>0?'; FIFO production cost':''),'finishedKg'=>$needed,'costPerKg'=>$value/$kg,'costPerMT'=>$value/$kg*1000,'purchaseAllocatedKg'=>$ready['kg']];
    }
    if(!$group||empty($group['cost']['available']))throw new DomainException($group['cost']['status']??'Record actual production for this contract or link a received ready-rice SODA through Buying for.');
    $qty=$mt*1000;$shipped=ss_contract_own_kg($s,$ops,$lot['costEntity'],$lot['contract'],$date);if($group['readyKg']+.001<max($qty,$shipped))throw new DomainException('Shipment quantity exceeds the contract-linked finished production available to cost.');
    $scale=$qty/$group['readyKg'];$cost=$group['cost'];$amount=round($cost['readyRiceValue']*$scale,2);$by=[];foreach($group['byproducts'] as $r)$by[]=['product'=>$r['product'],'kg'=>$r['kg']*$scale,'rate'=>$r['costPerKg'],'valuePKR'=>round($r['costValue']*$scale,2)];
    return ['amountPKR'=>$amount,'source'=>implode(', ',array_column($group['runs'],'id')),'basis'=>$cost['status'],'finishedKg'=>$qty,'fifoReceipts'=>array_merge(...array_map(static fn($r)=>$r['fifoLayers']??[],$group['raw'])),'rawKg'=>$group['inputKg']*$scale,'recoveryPct'=>$group['readyKg']/$group['inputKg']*100,'rawRate'=>$cost['rawAverageCostPerKg'],'rawValue'=>$cost['rawValue']*$scale,'millingValue'=>$cost['fixedOverheadValue']*$scale,'processingValue'=>$cost['conversionValue']*$scale,'byproductValue'=>$cost['byProductValue']*$scale,'byproducts'=>$by,'costPerKg'=>$qty>0?$amount/$qty:null,'costPerMT'=>$mt>0?$amount/$mt:null,'millingLabel'=>'Allocated fixed mill overhead','processingLabel'=>'Recorded conversion costs / labelled management fallback'];
}
function ss_goods(array $s,array $ops,array $lot,array $candidates,string $date,string $costingId):array {
    $mt=(float)$lot['quantityMT'];$externalMT=0.;foreach(array_filter((array)($s['exporterDeals']??[]),static fn($row)=>($row['status']??'')!=='Deleted') as $d){
        if(($d['entity']??'')!==$lot['costEntity']||($d['direction']??'')!=='PURCHASE')continue;$relevant=false;
        foreach((array)($d['loadings']??[]) as $l)if(($l['shipmentId']??'')===$lot['id']){if(empty($l['date'])||$l['date']>$date)throw new DomainException('External loading requires a verified date within this report.');$externalMT+=(float)$l['quantityMT'];$relevant=true;}
        if($relevant)foreach((array)($d['settlementPosts']??[]) as $p)if(empty($p['date'])||$p['date']>$date)throw new DomainException('External settlement changed after this date; historical cost requires review.');
    }
    if($externalMT>$mt+.000001)throw new DomainException('External loading exceeds shipment quantity.');
    $externalCost=0.;if($externalMT>0){$fellow=sp_fellow_costs($s,$lot['costEntity'],alv_hidden($s),sp_active_reversals($s,alv_hidden($s)));if($fellow['holds'])throw new DomainException(implode(' ', $fellow['holds']));foreach($fellow['rows'] as $r)if($r['external']&&$r['contractRef']===$lot['contract']&&$r['lotRef']===$lot['lot'])$externalCost+=$r['stockCost'];if($externalCost<=0)throw new DomainException('Posted external supplier cost needs the exact invoice allocation.');}
    $ownMT=max(0,$mt-$externalMT);$out=null;$cost=0.;$posts=[];
    if($costingId!==''){$saved=$s['directorCostings'][$costingId]??null;if(!$saved||$saved['entity']!==$lot['costEntity']||$saved['date']>$date||strtoupper($saved['input']['commodity']??'RICE')!==strtoupper($lot['commodity']??'RICE'))throw new DomainException('Choose a saved costing for this commodity and company on or before the report date.');$out=ss_recipe_goods($saved,$ownMT);}
    elseif($ownMT>0){
        foreach($candidates as $c){if(($c['entity']??'')!==$lot['costEntity']||empty($c['journalId']))continue;$evidence=sp_sale_evidence($s,$c,$c['entity'],alv_hidden($s),sp_active_reversals($s,alv_hidden($s)));if(!$evidence||isset($evidence['historicalOpening'])||$evidence['stockCost']===null)continue;ss_live_post($s,$c['journalId'],$c['entity'],$date);if(!empty($c['costJournalId']))ss_live_post($s,$c['costJournalId'],$c['entity'],$date);$post=$c['costJournalId']??'';if($post===''||in_array($post,$posts,true))throw new DomainException('Goods cost needs one unique original posting per allocation.');$cost+=$evidence['stockCost'];$posts[]=$post;}
        if($posts){$out=['amountPKR'=>round($cost,2),'source'=>implode(', ',$posts),'basis'=>'Posted goods cost','finishedKg'=>$ownMT*1000];try{$working=ss_contract_goods($s,$ops,$lot,$date,$ownMT);if(abs($working['amountPKR']-$cost)<.02)$out+=$working;else $out['workingNote']='Current production working differs from posted goods cost; original posted cost retained.';}catch(DomainException $e){$out['workingNote']=$e->getMessage();}}
        else $out=ss_contract_goods($s,$ops,$lot,$date,$ownMT);
    }
    $out??=['amountPKR'=>0.,'source'=>'External supplier settlement','basis'=>'Posted external goods cost','finishedKg'=>0.];
    $out['externalMT']=$externalMT;$out['externalCostPKR']=$externalCost;$out['amountPKR']=round($out['amountPKR']+$externalCost,2);$out['finishedKg']=$mt*1000;$out['costPerKg']=$mt>0?$out['amountPKR']/($mt*1000):null;$out['costPerMT']=$mt>0?$out['amountPKR']/$mt:null;return $out;
}

function ss_report(array $s,array $ops,array $root,string $date,string $id,bool $includeGoods,string $costingId,array $rates):array {
    $lots=array_filter(ss_lots($root),static fn($r)=>($r['invoiceDate']??'')<=$date);$choices=array_values(array_map(static fn($r)=>array_intersect_key($r,array_flip(['id','contract','lot','customer','seller','costEntity','quantityMT','invoice'])), $lots));
    if($id==='')return ['shipments'=>$choices,'statement'=>null];if(!isset($lots[$id]))throw new DomainException('Shipment not found in accessible group sources.');$lot=$lots[$id];$ship=array_column($root['shipments'],null,'id')[$id];$candidates=[];
    foreach((array)($s['exportCandidates']??[]) as $cid=>$c)if(($c['sourceKey']??'')==='EXPORT|'.$id||ss_link((array)($c['meta']??[]),$lot))$candidates[$cid]=$c;
    if(($lot['invoiceDate']??'')>$date)throw new DomainException('This shipment invoice is later than the selected report date.');
    $real=ss_realization($s,$root,$lot,$ship,$candidates,$date,$rates);$expenses=ss_expenses($s,$lot,$candidates,$date,$rates);$holds=array_merge($real['holds'],$expenses['holds']);$rows=array_merge($real['rows'],$expenses['rows']);$goods=null;
    if($includeGoods){try{$goods=ss_goods($s,$ops,$lot,$candidates,$date,$costingId);$rows[]=ss_line('Cost of goods',null,-$goods['amountPKR'],$lot['quantityMT'],$goods['source'],$goods['basis']);}catch(DomainException $e){$holds[]=$e->getMessage();$rows[]=ss_line('Cost of goods',null,null,$lot['quantityMT'],'','Source required');}}
    $net=$holds||$real['netPKR']===null||$expenses['totalPKR']===null?null:round($real['netPKR']+$expenses['totalPKR']-($includeGoods?($goods['amountPKR']??0):0),2);$label=$includeGoods?'Shipment profit / (loss)':'Balance before cost of goods';
    $rows[]=ss_line($label,null,$net,$lot['quantityMT'],'',$holds?'Source details required':($includeGoods?($goods['basis']??'Calculated group result'):'Calculated group balance'));
    $costings=[];foreach((array)($s['directorCostings']??[]) as $r)if($r['entity']===$lot['costEntity']&&$r['date']<=$date&&strtoupper($r['input']['commodity']??'RICE')===strtoupper($lot['commodity']??'RICE'))$costings[]=['id'=>$r['id'],'name'=>$r['name'],'date'=>$r['date']];
    return ['shipments'=>$choices,'statement'=>['shipment'=>$lot,'date'=>$date,'includeGoods'=>$includeGoods,'costingId'=>$costingId,'costings'=>$costings,'rows'=>$rows,'goods'=>$goods,'receiptSlices'=>$real['slices'],'rates'=>$rates,'unrealisedFX'=>$real['unrealisedFX'],'unrealisedMT'=>$real['unrealisedMT'],'resultPKR'=>$net,'marginPct'=>$includeGoods&&$net!==null&&$real['grossPKR']>0?round($net/$real['grossPKR']*100,2):null,'holds'=>array_values(array_unique($holds)),'notes'=>array_merge($real['notes'],['Goods cost uses original posted costs where available; otherwise it shows labelled accepted-purchase / FIFO production estimates. Unlinked expenses still require allocation.','This management report does not post or change company books. USD is shown only for original USD components; PKR-only lines are not back-converted.'])]];
}
