<?php
declare(strict_types=1);
/** Read committed costing decisions only; no operational reads or writes on reports. */
function sc_pending_warnings(array $s,string $date,?string $entity=null):array {
    $out=[];
    foreach((array)($s['exporterDeals']??[]) as $d){
        if(($d['arrangement']??'')!=='EX_MILL'||($entity!==null&&($d['entity']??'')!==$entity))continue;
        $pending=false;$lastId=null;
        foreach((array)($d['settlementPosts']??[]) as $p){
            $j=$s['journals'][$p['postId']??'']??[];
            if(($p['date']??'')>$date||($j['status']??'')!=='Posted'||!empty($j['reversalOf']))continue;
            $rev=$s['journals'][$j['reversedByPostId']??'']??[];
            if(($rev['status']??'')==='Posted'&&($rev['date']??'')<=$date)continue;
            $pending=!empty($p['costPending']);$lastId=$p['postId'];
        }
        foreach((array)($d['costReviews']??[]) as $review)if(($review['date']??'')<=$date&&($review['settlementPostId']??'')===$lastId)$pending=false;
        if($pending)$out[]=($d['party']??$d['id']).' — Profit provisional — rice cost pending. Accounts must post the approved cost for the billed deliveries.';
    }
    return $out;
}
/** Reversals reduce receipts; refunds are shown separately, never counted as new receipts. */
function sc_payment_summary(array $s,string $id,string $date):array {
    $receipts=0.;$refunds=0.;
    foreach((array)($s['journals']??[]) as $j){
        if(($j['status']??'')!=='Posted'||($j['date']??'')>$date)continue;
        $original=$s['journals'][$j['reversalOf']??$j['meta']['reversalOf']??'']??$j;
        if(!in_array($original['sourceType']??'',['FELLOW_EXPORTER_ADVANCE','FELLOW_EXPORTER_PAYMENT'],true)&&($original['meta']['receiptKind']??'')!=='FELLOW_EXPORTER')continue;
        $incoming=0.;foreach((array)($original['lines']??[]) as $l)if(($l['exporterDealId']??'')===$id&&($l['account']??'')==='1230')$incoming+=(float)($l['credit']??0)-(float)($l['debit']??0);
        foreach((array)($j['lines']??[]) as $l)if(($l['exporterDealId']??'')===$id&&($l['account']??'')==='1230'){
            $n=(float)($l['credit']??0)-(float)($l['debit']??0);if($incoming>0)$receipts+=$n;else $refunds-=$n;
        }
    }
    return ['receivedPKR'=>round($receipts,2),'refundedPKR'=>round($refunds,2),'netReceivedPKR'=>round($receipts-$refunds,2)];
}
