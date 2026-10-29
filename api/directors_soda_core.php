<?php
declare(strict_types=1);
/** Additional arrangements retain the existing agreement, journal and stock identities. */
function dt_validate_arrangement(array &$d,array $b):void {
    $local=$d['arrangement']==='EX_MILL';$partnership=$d['arrangement']==='PARTNERSHIP';
    if($local&&($d['direction']!=='SALE'||$d['currency']!=='PKR'||$d['incoterm']!=='EX_MILL'))throw new DomainException('Local ex-mill requires a PKR sale on an ex-mill basis.');
    if(!$local&&$d['incoterm']==='EX_MILL')throw new DomainException('Ex-mill is available for local ex-mill sales only.');
    if(($partnership||$d['arrangement']==='MARGIN')&&trim((string)($b['contractRef']??''))==='')throw new DomainException('Link the partnership to our existing sales contract.');
    if($d['arrangement']==='MARGIN'&&$d['direction']!=='PURCHASE')throw new DomainException('Export agreement with margin records the agreed payable to the fellow exporter.');
    $unit=dt_enum($b['priceUnit']??'MT',['MT','KG'],'price unit');
    // price remains per MT in the canonical store. UI converts once, before saving.
    $d['priceUnit']=$unit;
    $d['bagRatePKRPerKg']=$local?gm_number($b['bagRatePKRPerKg']??0,'bag deduction estimate per kg'):0.;
    $actual=$b['bagDeductionPKR']??null;$d['bagDeductionPKR']=$local&&$actual!==null&&$actual!==''?gm_number($actual,'confirmed total bags deduction for loaded goods'):null;
    $yield=$b['recoveryPct']??null;$d['recoveryPct']=$local&&$yield!==null&&$yield!==''?dt_positive($yield,'expected final-rice recovery percentage'):null;
    if(($d['recoveryPct']??0)>100)throw new DomainException('Final-rice recovery cannot exceed 100%.');
    $d['goodsEstimatePKRPerMT']=$partnership?gm_number($b['goodsEstimatePKRPerMT']??0,'supplier goods cost estimate per MT'):0.;
    $share=$b['partnerSharePct']??null;$d['partnerSharePct']=$partnership?($share===null||$share===''?null:gm_number($share,'fellow exporter share')):0.;
    if($d['partnerSharePct']>100)throw new DomainException('Partner share cannot exceed 100%.');
}
function dt_local_review_hash(array $s,array $d,array $native):string {
    return hash('sha256',json_encode([$native,dt_sale_cost($s,$d,$native,$native['loadedMT']),array_intersect_key($d,array_flip(['price','bagDeductionPKR','bagRatePKRPerKg','bagDeductionLoadedMT','quantityMT','terms','packing']))],JSON_THROW_ON_ERROR));
}
function dt_local_loadings(array $d,array $w):array {
    return array_map(static fn($r)=>['id'=>$r['loadingId'],'shipmentId'=>$d['id'],'invoiceRef'=>$r['invoice'],'quantityMT'=>$r['quantityMT'],'sourceSnapshot'=>['lot'=>$r['invoice'],'contract'=>$d['reference']?:$d['id']]],$w['rows']);
}
function dt_local_working(array $s,array $d,string $date,array $nativeSales):array {
    $native=dt_native_state($d,$nativeSales,$date);$loaded=$native['loadedMT'];$own=dt_sale_cost($s,$d,$native,$loaded);
    $bags=isset($d['bagDeductionPKR'])?$d['bagDeductionPKR']+max(0,$loaded-($d['bagDeductionLoadedMT']??$loaded))*1000*($d['bagRatePKRPerKg']??0):$loaded*1000*($d['bagRatePKRPerKg']??0);
    $reviewed=$loaded>0&&isset($d['bagDeductionPKR'],$d['localReviewHash'])&&hash_equals($d['localReviewHash'],dt_local_review_hash($s,$d,$native));
    $rows=[];$gross=0.;$bagAllocated=0.;$last=count($native['loads'])-1;
    foreach($native['loads'] as $i=>$load){$value=round($load['quantityMT']*$d['price'],2);$deduction=$i===$last?round($bags-$bagAllocated,2):round($bags*$load['quantityMT']/$loaded,2);$bagAllocated+=$deduction;$gross+=$value;
        $rows[]=['loadingId'=>$load['id'],'sourceReady'=>$native['known'],'invoice'=>$load['ref'],'quantityMT'=>$load['quantityMT'],'invoiceQuantityMT'=>$load['quantityMT'],'sharePct'=>100.,'grossPKR'=>$value,'expensesPKR'=>$deduction,'adjustmentsPKR'=>0.,'settlementPKR'=>round($value-$deduction,2),'actualFOBUSDPerMT'=>0.,'actualBills'=>[],'creditAdvices'=>[],'expensesComplete'=>$reviewed,'creditAdviceCoveragePct'=>100.,'settlementFX'=>1.];
    }
    $remaining=dc_remaining((float)$d['quantityMT'],(float)$loaded,$native['known'],in_array(strtolower((string)($d['status']??'')),['closed','completed','cancelled'],true))??max(0,$d['quantityMT']-$loaded);$warnings=array_merge($native['issues'],$own['issues']);
    if(!$reviewed&&$loaded>0)$warnings[]='Review the actual bags deduction and current dispatch/stock costs before posting.';
    if($bags>$gross+.005)$warnings[]='Bags deduction exceeds the loaded sale value.';
    $yield=$d['recoveryPct']??null;
    return ['ownDispatch'=>$native,'ownStockCost'=>$own,'rows'=>$rows,'loadedMT'=>$loaded,'remainingMT'=>$remaining,'grossPKR'=>round($gross,2),'bagsDeductionPKR'=>round($bags,2),'settlementPKR'=>round($gross-$bags,2),'unloadedForecastPKR'=>round($remaining*($d['price']-1000*($d['bagRatePKRPerKg']??0)),2),'rawRequiredBeforeStockMT'=>$yield?round($remaining/($yield/100),6):null,'warnings'=>array_values(array_unique($warnings)),'direction'=>'RECEIVABLE'];
}
/** Receipt before delivery is a credit in this customer's control, never sales income. */
function dt_advance(array &$s,array &$d,array $b,array $u,string $date):array {
    if($d['direction']!=='SALE')throw new DomainException('Customer advances apply only to sales.');
    $amount=dt_positive($b['amountPKR']??null,'advance actually received PKR');$ref=gm_text($b['reference']??'',180);if($ref==='')throw new DomainException('Enter the bank receipt reference.');
    $id=gm_text($b['bankId']??'',120);$bank=$id==='CASH|'.$d['entity']?['id'=>$id,'currency'=>'PKR','kind'=>'CASH']:ben_bank($s,$d['entity'],$id);
    $tracking=($bank['kind']??'')==='CASH'?[]:ben_tracking($s,$d['entity'],$bank,$b+['bankPaymentMethod'=>'ONLINE_BANKING','date'=>$date,'bankReference'=>$ref]);
    $meta=$tracking+['exporterDealId'=>$d['id'],'counterparty'=>$d['party'],'partyId'=>$d['partyId'],'stockEffect'=>'NONE_EXTERNAL','customerAdvance'=>true];
    $j=ben_journal($s,$d['entity'],$date,$ref,'Customer advance received — '.$d['party'],dt_payment_lines($d,$bank,$b,$amount,true,$meta),$u,$meta);$s['journals'][$j['id']]['sourceType']='FELLOW_EXPORTER_ADVANCE';
    $d['payments'][]=['postId'=>$j['id'],'date'=>$date,'amountPKR'=>$amount,'incoming'=>true,'advance'=>true];return ['postId'=>$j['id']];
}
/** The account includes every posting, reversal and settlement against this agreement. */
function dt_soda_account(array $s,array $d,string $date):array {
    $out=[];foreach((array)($s['journals']??[]) as $j){if(($j['entity']??'')!==$d['entity']||($j['status']??'')!=='Posted'||($j['date']??'')>$date)continue;
        foreach((array)($j['lines']??[]) as $l)if(($l['exporterDealId']??'')===$d['id']&&in_array($l['account']??'',['1230','2140'],true))$out[]=['date'=>$j['date'],'postId'=>$j['id'],'reference'=>$j['reference']??'','narration'=>$j['narration']??'','debit'=>(float)($l['debit']??0),'credit'=>(float)($l['credit']??0)];
    }usort($out,static fn($a,$b)=>strcmp($a['date'],$b['date'])?:strcmp($a['postId'],$b['postId']));return $out;
}
/** Use the shared coverage plan so two SODAs cannot each claim the same stock. */
function dt_raw_cover(array $s,string $date):array {
    if(!defined('TT_DATA_DIR'))return [];$p=TT_DATA_DIR.'/group-management.json';if(!is_file($p))return [];
    require_once __DIR__.'/group_daily_core.php';$store=json_decode((string)file_get_contents($p),true,512,JSON_THROW_ON_ERROR);$best=null;
    foreach((array)($store['groupManagementRecords']??[]) as $r)if(($r['kind']??'')==='DAILY'&&($r['date']??'')<=$date&&isset($r['sections']['daily']['data']['config'])&&(!$best||$r['date']>$best['date']))$best=$r;
    if(!$best)return [];$config=$best['sections']['daily']['data']['config'];$out=[];
    try{$source=gd_sources($s,gd_read_operations(TT_DATA_DIR.'/operations.json'),$date);$positions=gd_positions($source,$config);foreach($positions['positions'] as $position)foreach(array_filter((array)($s['exporterDeals']??[]),static fn($row)=>($row['status']??'')!=='Deleted') as $d)if(in_array('FELLOW-SALE|'.$d['entity'].'|'.$d['id'],$position['sourceIds'],true))$out[$d['id']]=$position;}
    catch(DomainException $e){foreach(array_filter((array)($s['exporterDeals']??[]),static fn($row)=>($row['status']??'')!=='Deleted') as $d)$out[$d['id']]=['error'=>'Stock coverage needs review: '.$e->getMessage()];}return $out;
}
/** Add only loaded, unposted estimates; book receipts/payables already belong to the source. */
function dt_loaded_cashflow(array $source,array $s,array $root,array $values,string $date):array {
    $native=se_rows($values,'tt35exportersale');
    foreach(['TTI','BRM'] as $entity){$lots=dt_lots($root,$entity);foreach(dt_cashflow($s,$lots,$entity,$date,$native) as $f){$d=$s['exporterDeals'][$f['id']]??null;if(!$d||!in_array($d['arrangement'],['EX_MILL','PARTNERSHIP','MARGIN'],true))continue;
        $n=$f['loadedForecastOnlyPKR'];$missing=empty($f['estimateComplete']);if(abs($n)<.005&&!$missing)continue;$row=se_row('SODA|'.$d['id'],$entity,$d['party'],$d['party'].' — SODA '.($d['reference']?:$d['id']).' · ESTIMATE · '.($f['remainingMT']>0?'partial loading ':'loaded ').$f['loadedMT'].' / '.$d['quantityMT'].' MT','PKR',abs($n),$date,'Loaded quantity estimate less posted settlement; receipts and payments remain in the original party balance.',['account'=>$d['direction']==='SALE'?'1230':'2140','exporterDealId'=>$d['id'],'estimateStatus'=>'ESTIMATE','narration'=>'Estimated net '.($d['direction']==='SALE'?'receipt':'payment').' for saved loading; posted settlements replace this estimate.']);$row['amount']=$row['originalAmount']=$missing?null:$n;$source['rows'][]=$row;
        foreach($f['warnings'] as $warning)$source['warnings'][]=$d['party'].' — '.$warning;
    }}return $source;
}

require_once __DIR__.'/directors_soda_production.php';
