<?php
declare(strict_types=1);
require_once __DIR__.'/accounts_ledger_visibility.php';

/** Bank receipts share the original sale's control account; never recognize a sale again. */
function bsr_permission(array $u,string $kind,string $action='View'):bool {
    $icon=$kind==='LOCAL_SALE'?'customer':'group-reports';
    return tt_user_can_module_action($u,'Accounts',$icon,$action);
}
function bsr_balance(array $s,string $e,string $id,string $account,string $date,bool $fellow=false):float {
    $s=alv_amendment_books($s);
    $balance=0.;
    foreach((array)($s['journals']??[]) as $j){
        if(($j['entity']??'')!==$e||($j['status']??'')!=='Posted'||($j['date']??'')>$date)continue;
        $m=(array)($j['meta']??[]);
        foreach((array)($j['lines']??[]) as $l){
            $link=$fellow?($l['exporterDealId']??''):($l['soda']??$m['soda']??$m['targetSoda']??$m['advanceSoda']??'');
            if((string)($l['account']??'')===$account&&(string)$link===$id)$balance+=(float)($l['debit']??0)-(float)($l['credit']??0);
        }
    }return round($balance,2);
}
function bsr_sources(array $s,string $e,string $kind,array $u,string $date='9999-12-31'):array {
    if(!in_array($e,['TTI','BRM'],true)||!in_array($kind,['LOCAL_SALE','FELLOW_EXPORTER'],true))throw new DomainException('Choose Local Sales Receipt or Fellow Exporter Receipt in Pakistan company books.');
    if(!bsr_permission($u,$kind)||!tt_user_can_access_entity($u,$e,'View'))throw new DomainException('View permission for the selected sales account is required.');
    $rows=[];
    if($kind==='FELLOW_EXPORTER'){
        foreach(array_filter((array)($s['exporterDeals']??[]),static fn($row)=>($row['status']??'')!=='Deleted') as $d){
            if(($d['entity']??'')!==$e||($d['date']??'')>$date||in_array($d['status']??'',['Cancelled','Voided'],true))continue;
            $balance=bsr_balance($s,$e,(string)$d['id'],($d['direction']??'SALE')==='SALE'?'1230':'2140',$date,true);
            if(($d['direction']??'SALE')!=='SALE'&&$balance<=0)continue;
            $rows[]=['id'=>$d['id'],'partyId'=>$d['partyId'],'party'=>$d['party'],'soda'=>$d['reference']?:$d['id'],'product'=>$d['product']??'','date'=>$d['date'],'openReceivable'=>max(0,$balance),'customerAdvance'=>max(0,-$balance)];
        }
    }else{
        require_once __DIR__.'/local_sales_scope.php';
        $values=tt_inv_read_values();$candidates=[];$blocked=[];
        foreach(tt_inv_rows($values,'tt35localsales') as $sale){
            if(tt_inv_entity($sale)!==$e||($sale['date']??'')>$date)continue;
            if(!empty($sale['cancelled'])||(!empty($sale['closed'])&&bsr_balance($s,$e,(string)($sale['soda']??''),'1220',$date)<=0)){$blocked[strtoupper((string)($sale['soda']??''))]=true;continue;}
            $sale['entity']=$e;$sale['stockLocation']=$sale['millName']??$sale['locationName']??$sale['mill']??'TTI Rice Mills';
            $candidates[]=$sale+['saleDate'=>$sale['date']??''];
        }
        foreach((array)($s['localSalesCandidates']??[]) as $sale)if(($sale['entity']??'')===$e&&!in_array($sale['status']??'',['Cancelled','Returned to Mill','Voided'],true))$candidates[]=$sale;
        // Historical receivables remain collectable even if their original Mill SODA predates the current register.
        foreach((array)($s['journals']??[]) as $j)if(($j['entity']??'')===$e&&($j['status']??'')==='Posted'&&($j['date']??'')<=$date)foreach((array)($j['lines']??[]) as $l)if(in_array($l['account']??'',['1220','2510'],true)){
            $m=(array)($j['meta']??[]);$soda=(string)($l['soda']??$m['soda']??'');if($soda!=='')$candidates[]=['entity'=>$e,'soda'=>$soda,'party'=>$l['party']??$m['party']??'','product'=>$m['product']??'','saleDate'=>$j['date'],'stockLocation'=>$l['stockLocation']??$m['stockLocation']??''];
        }
        foreach($candidates as $sale){
            $id=trim((string)($sale['soda']??''));$party=trim((string)($sale['party']??''));if($id===''||$party===''||($sale['saleDate']??'')>$date||!lss_allowed($u,$sale,$s,$values))continue;
            $key=strtoupper($id);if(isset($blocked[$key]))continue;if(isset($rows[$key])&&strcasecmp($rows[$key]['party'],$party)!==0)throw new DomainException('Local SODA '.$id.' has conflicting parties. Review its source before receiving money.');
            $open=max(0,bsr_balance($s,$e,$id,'1220',$date));$advance=max(0,-bsr_balance($s,$e,$id,'2510',$date));
            if(!empty($sale['closed'])&&$open<=0)continue;
            if(!isset($rows[$key]))$rows[$key]=['id'=>$id,'soda'=>$id,'partyId'=>$party,'party'=>$party,'product'=>$sale['displayName']??$sale['product']??'','date'=>$sale['saleDate']??'','stockLocation'=>lss_location($sale,$s,$values),'openReceivable'=>$open,'customerAdvance'=>$advance];
        }
        $rows=array_values($rows);
    }
    usort($rows,static fn($a,$b)=>strcasecmp($a['party'],$b['party'])?:strnatcasecmp($a['soda'],$b['soda']));return $rows;
}
/** Cancelled receipt pairs do not block their own dated replacement.
 * Active later settlements/payments still require correction in chronological order.
 */
function bsr_has_later_financial(array $s,array $d,string $date):bool {
    foreach(array_merge((array)($d['settlementPosts']??[]),(array)($d['payments']??[])) as $prior){
        if(($prior['date']??'')<=$date)continue;
        $journal=$s['journals'][$prior['postId']??'']??[];
        if(!empty($journal['reversedByPostId'])||!empty($journal['reversalOf']))continue;
        return true;
    }
    return false;
}
function bsr_post(array &$s,string $e,array $b,array $u,array $bank,string $date,int $amount,float $rate,string $ref,string $note,array $meta):array {
    $kind=(string)$b['receiptKind'];$right=$kind==='FELLOW_EXPORTER'?'Approve':'Create';
    if(!bsr_permission($u,$kind,$right))throw new DomainException($right.' permission for the selected sales account is required.');
    if($bank['currency']!=='PKR'||$rate!==1.0)throw new DomainException('Select a PKR bank for this local or fellow-exporter receipt.');
    $today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Karachi')))->format('Y-m-d');if($date>$today)throw new DomainException('Receipt date cannot be in the future.');
    $id=trim((string)($b['receiptSodaId']??''));$partyId=trim((string)($b['receiptPartyId']??''));$sale=null;
    foreach(bsr_sources($s,$e,$kind,$u,$date) as $row)if($row['id']===$id&&$row['partyId']===$partyId){$sale=$row;break;}
    if(!$sale)throw new DomainException('Choose the party and its saved SODA in these company books.');
    $applied=min($amount,(int)round($sale['openReceivable']*100));$advance=$amount-$applied;
    $meta+=['receiptKind'=>$kind,'payee'=>$sale['party'],'counterparty'=>$sale['party'],'receivableApplied'=>$applied/100,'customerAdvancePKR'=>$advance/100];
    if($kind==='FELLOW_EXPORTER'){
        $d=$s['exporterDeals'][$id];$before=$d;$direction=$d['direction']??'SALE';
        if(bsr_has_later_financial($s,$d,$date))throw new DomainException('A later SODA financial entry exists. Use a current receipt date.');
        if($direction!=='SALE'&&$advance>0)throw new DomainException('A supplier refund cannot exceed the posted debit balance.');
        $extra=['exporterDealId'=>$id,'partyId'=>$d['partyId'],'counterparty'=>$d['party'],'stockEffect'=>'NONE_EXTERNAL'];
        $meta+=$extra+['customerAdvance'=>$advance>0];$lines=[ben_line($direction==='SALE'?'1230':'2140',0,$amount,$extra),ben_bank_line($bank,$amount,1,true,$extra)];
    }else{
        $extra=['soda'=>$sale['soda'],'party'=>$sale['party'],'counterparty'=>$sale['party'],'stockLocation'=>$sale['stockLocation']??''];
        $meta+=$extra+['product'=>$sale['product'],'receiptType'=>'BANK','customerAdvance'=>$advance/100];$lines=[ben_bank_line($bank,$amount,1,true)];
        if($applied>0)$lines[]=ben_line('1220',0,$applied,$extra);
        if($advance>0)$lines[]=ben_line('2510',0,$advance,$extra+['advanceMove'=>'To']);
    }
    $narration=($kind==='LOCAL_SALE'?'Local sales receipt':'Fellow exporter receipt').' — '.$sale['party'].' — SODA '.$sale['soda'].($advance>0?' · Includes advance PKR '.number_format($advance/100,2,'.',''):'').($note!==''?' — '.$note:'');
    $j=ben_journal($s,$e,$date,$ref,$narration,$lines,$u,$meta);
    if($kind==='FELLOW_EXPORTER'){
        $d['payments'][]=['postId'=>$j['id'],'date'=>$date,'amountPKR'=>$amount/100,'incoming'=>true,'advance'=>$advance>0,'bankCurrency'=>'PKR','nativeAmount'=>$amount/100,'exchangeRate'=>1];
        $d['version']=(int)($d['version']??0)+1;$d['history'][]=['action'=>'bank_receipt','before'=>array_diff_key($before,['history'=>true]),'by'=>$u['full_name']??$u['username']??'Accounts','at'=>gmdate('c')];$s['exporterDeals'][$id]=$d;
    }
    return ['journalId'=>$j['id'],'journal'=>$j];
}
function bsr_before_reverse(array $s,string $e,array $j,array $u,string $date):void {
    $kind=$j['meta']['receiptKind'];
    if(!bsr_permission($u,$kind,$kind==='FELLOW_EXPORTER'?'Approve':'Edit'))throw new DomainException('Sales account correction permission is required to reverse this receipt.');
    $today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Karachi')))->format('Y-m-d');if($date>$today)throw new DomainException('Reversal date cannot be in the future.');
    if($kind==='LOCAL_SALE')foreach((array)$j['lines'] as $l)if(($l['account']??'')==='2510'){
        $available=-bsr_balance($s,$e,(string)$l['soda'],'2510',$date);
        if($available+.005<(float)$l['credit'])throw new DomainException('This advance has already been applied. Reverse its later allocation before reversing the receipt.');
    }
    if($kind==='FELLOW_EXPORTER'){
        $d=$s['exporterDeals'][$j['meta']['exporterDealId']]??null;if(!$d||$d['entity']!==$e)throw new DomainException('Original SODA could not be found.');
        if(bsr_has_later_financial($s,$d,$date))throw new DomainException('A later SODA financial entry exists. Use a current reversal date.');
    }
}
function bsr_reversed(array &$s,array $j,array $reversal,array $u):void {
    $id=$j['meta']['exporterDealId'];$d=$s['exporterDeals'][$id];$before=$d;
    $d['payments'][]=['postId'=>$reversal['id'],'date'=>$reversal['date'],'amountPKR'=>-(float)$j['meta']['amountNative'],'incoming'=>true,'reversalOf'=>$j['id']];
    $d['version']=(int)($d['version']??0)+1;$d['history'][]=['action'=>'bank_receipt_reversal','before'=>array_diff_key($before,['history'=>true]),'postId'=>$reversal['id'],'by'=>$u['full_name']??$u['username']??'Accounts','at'=>gmdate('c')];$s['exporterDeals'][$id]=$d;
}
