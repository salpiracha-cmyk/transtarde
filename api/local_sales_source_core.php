<?php
declare(strict_types=1);
require_once __DIR__.'/local_sales_scope.php';
require_once __DIR__.'/mill_recovery_core.php';

function lsc_values(): array {
    require_once __DIR__.'/mill_recovery_core.php';
    return mr_read_operations_source(TT_DATA_DIR.'/operations.json',['tt35localsales'])['values'];
}

function lsc_payment_candidates(array $values,array $books): array {
    $out=(array)($books['localSalesPaymentCandidates']??[]);$current=[];
    foreach(tt_inv_rows($values,'tt35localsales') as $sale){
        if(!tt_inv_is_byproduct((string)($sale['product']??''))||!empty($sale['cancelled']))continue;
        $entity=strtoupper((string)($sale['accountEntity']??$sale['entity']??''));$soda=(string)($sale['soda']??'');
        if(!in_array($entity,['TTI','BRM'],true)||$soda===''||trim((string)($sale['party']??''))==='')continue;
        foreach((array)($sale['pay']??[]) as $index=>$p){
            $amount=round((float)($p['amount']??0),2);$type=(string)($p['type']??'');
            if(!mr_valid_date((string)($p['date']??''))||$amount<=0||!in_array($type,['Cash','Transtrade Bank','Third-Party Account','Other Adjustment'],true))continue;
            $sourceKey='LOCALPAY|'.$soda.'|'.($p['bridgeId']??('SAVED-'.($sale['id']??$soda).'-'.$index));
            $id=$entity.'|LOCAL_PAYMENT|'.$sourceKey;
            foreach((array)($books['localSalesPaymentCandidates']??[]) as $oldId=>$old)if(($old['entity']??'')===$entity&&($old['sourceKey']??'')===$sourceKey){$id=(string)$oldId;break;}
            if(isset($current[$id]))throw new DomainException('Duplicate saved Local Sale receipt identity. Review '.$soda.'.');
            $c=['id'=>$id,'entity'=>$entity,'sourceKey'=>$sourceKey,'soda'=>$soda,'party'=>$sale['party']??'',
                'broker'=>$sale['broker']??'','product'=>$sale['product'],'sourceDerived'=>true,'byProductSale'=>true,
                'stockLocation'=>$sale['millName']??$sale['mill']??'TTI Rice Mills','paymentDate'=>$p['date']??'',
                'amount'=>$amount,'paymentType'=>$type,'reference'=>$p['where']??'','bankAccountId'=>$p['bankAccountId']??'',
                'sourceSoda'=>$p['sourceSoda']??'','sourceProduct'=>$p['sourceProduct']??'','sourceParty'=>$p['sourceParty']??'',
                'status'=>$type==='Third-Party Account'?'Needs Settlement Details':'Pending Accounts Approval',
                'journalId'=>null,'reviewNote'=>'','createdAt'=>$p['date']??'','createdBy'=>$sale['doneBy']??'Mill'];
            $c['sourceFingerprint']=hash('sha256',json_encode($c,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
            $current[$id]=$c;$old=$out[$id]??[];
            if(!empty($old['journalId']))continue;
            if(($old['status']??'')==='Returned to Mill'&&(!isset($old['sourceFingerprint'])||$old['sourceFingerprint']===$c['sourceFingerprint']))continue;
            $out[$id]=$c;
        }
    }
    foreach($out as $id=>$p)if(!empty($p['sourceDerived'])&&empty($p['journalId'])&&!isset($current[$id]))unset($out[$id]);
    foreach($out as &$c){
        if(empty($c['sourceDerived'])||!empty($c['journalId']))continue;
        foreach((array)($books['localSalesPaymentCandidates']??[]) as $old){
            if(empty($old['journalId'])||($old['entity']??'')!==$c['entity']||($old['soda']??'')!==$c['soda'])continue;
            $matched=false;foreach($current as $source)if($source['entity']===$old['entity']&&$source['sourceKey']===($old['sourceKey']??'')){$matched=true;break;}
            if(!$matched)$c['reviewHold']='A historical receipt for this Soda has an unmatched source. Reconcile it before approving another receipt.';
        }
    }unset($c);
    return $out;
}

/** Saved dispatches are the durable Accounts handoff. Reads never write books. */
function lsc_source_candidates(array $values,array $books): array {
    $out=[];
    foreach(tt_inv_rows($values,'tt35localsales') as $sale){
        if(!tt_inv_is_byproduct((string)($sale['product']??''))||!empty($sale['cancelled']))continue;
        $entity=strtoupper((string)($sale['accountEntity']??$sale['entity']??''));
        $soda=trim((string)($sale['soda']??''));$sourceId=(string)($sale['id']??$soda);
        if(!in_array($entity,['TTI','BRM'],true)||$soda===''||trim((string)($sale['party']??''))==='')continue;
        foreach((array)($sale['loads']??[]) as $index=>$load){
            $kg=round(tt_inv_cargo_kg($load,'local'),3);$rate=round((float)($sale['rate']??0),4);
            $date=(string)($load['date']??'');
            if($kg<=0||$rate<=0||!mr_valid_date($date))continue;
            $loadId=(string)($load['bridgeId']??'');
            // Old committed dispatches may predate stable loading identities.
            if($loadId==='')$loadId='SAVED-'.$sourceId.'-'.$index;
            $sourceKey='LOCAL|'.$soda.'|'.$loadId;$id=$entity.'|LOCAL_SALE|'.$sourceKey;
            $c=array_intersect_key($sale,array_flip(['party','broker','product','productIdentityCode','baseVariety','riceType','productStage','displayName']));
            $c=array_replace($c,['id'=>$id,'entity'=>$entity,'sourceKey'=>$sourceKey,'soda'=>$soda,
                'sourceId'=>$sourceId,'sourceLoadId'=>$loadId,'byProductSale'=>true,'sourceDerived'=>true,
                'saleDate'=>$date,'loadedKg'=>$kg,'ratePerKg'=>$rate,'amount'=>round($kg*$rate,2),
                'bags'=>(float)($load['bags']??0),'truck'=>(string)($load['truck']??''),
                'gatePass'=>(string)($load['gatePassRef']??''),'stockLocation'=>(string)($sale['millName']??$sale['mill']??'TTI Rice Mills'),
                'stockReference'=>(string)($sale['stockSourceName']??$sale['product']),
                'billingWeightBasis'=>(string)($sale['billingWeightBasis']??'GROSS'),
                'weighbridgeGrossKg'=>(float)($load['weighbridgeGrossKg']??$load['kg']??0),
                'emptyTareKg'=>(float)($load['emptyTareKg']??0),'netLoadedKg'=>$kg,
                'quantityBasis'=>!empty($sale['byProductSale'])?'KANTA':'SUBMITTED_WEIGHT',
                'status'=>'Pending Accounts Approval','journalId'=>null,'sellingBrokeryAmount'=>0,
                'createdAt'=>(string)($sale['time']??$date),'createdBy'=>(string)($sale['doneBy']??'Mill'),
                'costStatus'=>'Management costing is reporting only; physical stock follows the saved loading.']);
            // Legacy selling-broker terms must not disappear when a saved source
            // supersedes an older browser handoff.
            foreach((array)($books['localSalesCandidates']??[]) as $old){
                if(($old['entity']??'')!==$entity||($old['sourceKey']??'')!==$sourceKey)continue;
                if(!empty($old['sellingBrokery'])){
                    $c['sellingBrokery']=$old['sellingBrokery'];
                    $c['sellingBrokeryAmount']=tt_brokery_amount($old['sellingBrokery'],$kg,$c['bags']);
                }elseif((float)($old['sellingBrokeryAmount']??0)>0){
                    $c['sellingBrokeryAmount']=(float)$old['sellingBrokeryAmount'];
                    if((float)($old['loadedKg']??0)!==$kg)$c['reviewHold']='Review the original selling-broker terms before approving this amended loading.';
                }
                break;
            }
            $c['sourceFingerprint']=hash('sha256',json_encode($c,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
            if(isset($out[$id]))throw new DomainException('Duplicate saved Local Sale loading identity. Review '.$soda.' before posting.');
            // Preserve the original candidate ID if an older handoff used one.
            foreach((array)($books['localSalesCandidates']??[]) as $oldId=>$old)if(($old['entity']??'')===$entity&&($old['sourceKey']??'')===$sourceKey){$c['id']=(string)$oldId;break;}
            $out[$id]=$c;
        }
    }
    return array_column(array_values($out),null,'id');
}
function lsc_candidates(array $values,array $books): array {
    $out=(array)($books['localSalesCandidates']??[]);
    $current=lsc_source_candidates($values,$books);
    foreach($current as $id=>$c){
        $old=$out[$id]??[];
        if(!empty($old['journalId']))continue;
        if(($old['status']??'')==='Returned / Rejected by Accounts'&&(!isset($old['sourceFingerprint'])||$old['sourceFingerprint']===$c['sourceFingerprint']))continue;
        // Older browser handoffs could assign an ID without saving it back to
        // Mill. Never mistake that same loading for a new sale.
        foreach((array)($books['localSalesCandidates']??[]) as $prior){
            if(empty($prior['journalId'])||($prior['entity']??'')!==$c['entity']||($prior['soda']??'')!==$c['soda'])continue;
            $matched=false;foreach($current as $source)if($source['entity']===$prior['entity']&&$source['sourceKey']===($prior['sourceKey']??'')){$matched=true;break;}
            if(!$matched)$c['reviewHold']='A historical sale for this Soda has an unmatched loading. Reconcile it before approving another sale.';
        }
        // Unknown historical postings require review, never a guessed second sale.
        foreach((array)($books['journals']??[]) as $j){
            if(($j['status']??'')!=='Posted'||($j['entity']??'')!==$c['entity']||($j['sourceType']??'')!=='LOCAL_SALE_RECOGNIZED'||($j['meta']['soda']??'')!==$c['soda'])continue;
            $linked=$books['localSalesCandidates'][$j['meta']['localSaleCandidateId']??'']??null;
            if(!$linked||($linked['journalId']??'')!==($j['id']??''))$c['reviewHold']='A historical sale posting needs reconciliation before this loading can be approved.';
        }
        $out[$id]=$c;
    }
    // A removed source must not leave a stale, approvable derived candidate.
    foreach($out as $id=>$c)if(!empty($c['sourceDerived'])&&empty($c['journalId'])&&!isset($current[$id]))unset($out[$id]);
    return $out;
}
function lsc_revenue_extra(array &$books,array $c,array $user): array {
    if(!tt_inv_is_byproduct((string)($c['product']??'')))return ['account'=>'4200'];
    $entity=(string)$c['entity'];$name=trim((string)$c['product']).' Sales';$matches=[];
    foreach((array)($books['accountSubaccounts']??[]) as $id=>$sub)if(($sub['entity']??'')===$entity&&($sub['parentCode']??'')==='4300'&&strcasecmp(trim((string)($sub['name']??'')),$name)===0)$matches[$id]=$sub;
    if(count($matches)>1)throw new DomainException('Duplicate '.$name.' subaccounts need review.');
    $id=$matches?(string)array_key_first($matches):$entity.'-BYPRODUCT-SALES-'.substr(hash('sha256',strtolower($name)),0,16);
    if($matches&&empty($matches[$id]['active']))throw new DomainException($name.' subaccount is inactive.');
    if(!$matches){
        if(isset($books['accountSubaccounts'][$id]))throw new DomainException('By-product revenue identity needs review.');
        $books['accountSubaccounts'][$id]=['id'=>$id,'entity'=>$entity,'name'=>$name,'parentCode'=>'4300','parentId'=>'','taxCategory'=>'NONE','active'=>true];
        $books['subaccountAudit'][]=['entity'=>$entity,'operation'=>'byproduct_sale_setup','after'=>$books['accountSubaccounts'][$id],'at'=>gmdate('c'),'by'=>$user['username']??''];
    }
    return ['account'=>'4300','subaccountId'=>$id,'subaccountName'=>$name,'subledger'=>$name,'parentAccount'=>'4300','accountClass'=>'Revenue'];
}
function lsc_buyer_advance_extra(array &$books,string $entity,array $user): array {
    $name='By-product Buyer Advances';$id=$entity.'-BYPRODUCT-BUYER-ADVANCES';$matches=[];
    foreach((array)($books['accountSubaccounts']??[]) as $key=>$row)if(($row['entity']??'')===$entity&&($row['parentCode']??'')==='2510'&&strcasecmp((string)($row['name']??''),$name)===0)$matches[$key]=$row;
    if(count($matches)>1)throw new DomainException('Duplicate by-product advance subaccounts need review.');
    if($matches){$id=(string)array_key_first($matches);if(empty($matches[$id]['active']))throw new DomainException('By-product Buyer Advances is inactive.');}
    else{
        if(isset($books['accountSubaccounts'][$id]))throw new DomainException('By-product advance identity needs review.');
        $books['accountSubaccounts'][$id]=['id'=>$id,'entity'=>$entity,'name'=>$name,'parentCode'=>'2510','parentId'=>'','taxCategory'=>'NONE','active'=>true];
        $books['subaccountAudit'][]=['entity'=>$entity,'operation'=>'byproduct_advance_setup','after'=>$books['accountSubaccounts'][$id],'at'=>gmdate('c'),'by'=>$user['username']??''];
    }
    return ['subaccountId'=>$id,'subaccountName'=>$name,'parentAccount'=>'2510','buyerType'=>'BYPRODUCT'];
}
/** Clear the same advance subsidiaries that received the money, including legacy
 * unclassified receipts. Never rewrite an old bank entry to add a label. */
function lsc_advance_allocations(array $books,string $entity,string $soda,float $amount): array {
    $buckets=[];
    foreach((array)($books['journals']??[]) as $j){
        if(($j['entity']??'')!==$entity||($j['status']??'')!=='Posted')continue;
        foreach((array)($j['lines']??[]) as $l){
            if(($l['account']??'')!=='2510'||(string)($l['soda']??$j['meta']['soda']??$j['meta']['targetSoda']??$j['meta']['advanceSoda']??'')!==$soda)continue;
            $key=(string)($l['subaccountId']??'');
            $buckets[$key]??=['balance'=>0.,'extra'=>array_intersect_key($l,array_flip(['subaccountId','subaccountName','parentAccount','buyerType']))];
            $buckets[$key]['balance']+=(float)($l['credit']??0)-(float)($l['debit']??0);
        }
    }
    $out=[];foreach($buckets as $bucket){$take=round(min($amount,max(0,$bucket['balance'])),2);if($take<=0)continue;$out[]=['amount'=>$take,'extra'=>$bucket['extra']];$amount=round($amount-$take,2);if($amount<=.005)break;}
    if($amount>.005)throw new DomainException('The buyer advance allocation changed. Reopen the saved sale.');
    return $out;
}
/** Hold the Accounts read lock until the operational commit/rollback finishes.
 * Nonblocking acquisition avoids reversing the source-before-Accounts lock order.
 */
function lsc_lock_source_amendment(string $old,string $new) {
    if($old===$new)return null;
    $path=TT_DATA_DIR.'/accounts.json';if(!is_file($path))throw new DomainException('Accounts is unavailable; retain the loading and retry.');
    $h=fopen($path,'rb');if(!$h||!flock($h,LOCK_SH|LOCK_NB)){if(is_resource($h))fclose($h);throw new DomainException('Accounts is posting this sale. Retry the saved loading.');}
    try{
        $books=json_decode((string)stream_get_contents($h),true,512,JSON_THROW_ON_ERROR);
        $before=lsc_source_candidates(['tt35localsales'=>$old?:'[]'],$books);
        $after=lsc_source_candidates(['tt35localsales'=>$new],$books);
        foreach($before as $id=>$c)if(!empty($books['localSalesCandidates'][$id]['journalId'])&&($after[$id]['sourceFingerprint']??'')!==$c['sourceFingerprint'])
            throw new DomainException('This loading has an approved Accounts sale. Reverse/review that posting before changing its quantity, rate, buyer or company.');
        $paymentFacts=static function(string $json):array {
            $out=[];foreach(tt_inv_rows(['tt35localsales'=>$json?:'[]'],'tt35localsales') as $sale)foreach((array)($sale['pay']??[]) as $index=>$p){
                $key='LOCALPAY|'.($sale['soda']??'').'|'.($p['bridgeId']??('SAVED-'.($sale['id']??$sale['soda']??'').'-'.$index));
                $out[$key]=['entity'=>$sale['accountEntity']??$sale['entity']??'','party'=>$sale['party']??'','product'=>$sale['product']??'',
                    'payment'=>array_diff_key($p,array_flip(['accounts','accountsNote']))];
            }return $out;
        };
        $oldPay=$paymentFacts($old);$newPay=$paymentFacts($new);
        foreach((array)($books['localSalesPaymentCandidates']??[]) as $p)if(!empty($p['journalId'])&&isset($oldPay[$p['sourceKey']??''])&&($newPay[$p['sourceKey']]??null)!=$oldPay[$p['sourceKey']])
            throw new DomainException('This payment has been posted in Accounts. Review/reverse its posting before changing the saved payment.');
        return $h;
    }catch(Throwable $e){flock($h,LOCK_UN);fclose($h);throw $e;}
}
function lsc_unlock($h): void {if(is_resource($h)){flock($h,LOCK_UN);fclose($h);}}
