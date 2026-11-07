<?php
declare(strict_types=1);

const MRD_ARCHIVE='tt40milldeletions';
const MRD_KINDS=['tt30queue'=>'queue','tt30prod'=>'production','tt33bagreceipts'=>'newbags','tt35localsales'=>'local','tt35exportersale'=>'local'];

function mrd_id(string $key,array $row): string {
    return (string)($row['id']??($key==='tt35localsales'?($row['soda']??''):($row['ref']??'')));
}
function mrd_authorize(array $user,string $key,array $row,array $values): void {
    $icon=MRD_KINDS[$key]??'';
    if($icon===''||!tt_user_can_open_module($user,'Mill')||!tt_user_can_module_action($user,'Mill',$icon,'Edit'))throw new DomainException('Mill '.$icon.' Edit permission is required to delete this entry.');
    if(!os_scope($user,$row,os_root($values)))throw new DomainException('This entry belongs to another company or mill.');
    foreach(['entity-tti','entity-brm','entity-tg'] as $grant)if(array_key_exists($grant,(array)($user['permissions']['Accounts']??[]))){
        if(!tt_user_can_access_entity($user,(string)($row['entity']??'TTI'),'Edit'))throw new DomainException('Company Edit permission is required.');
        break;
    }
}
/** Collection saves cannot restore a source that was explicitly deleted. */
function mrd_guard_write(array $values,string $key,string $json): void {
    if($key===MRD_ARCHIVE)throw new DomainException('Deletion history is maintained by the server.');
    if(!isset(MRD_KINDS[$key]))return;
    $deleted=[];
    foreach(tt_inv_rows($values,MRD_ARCHIVE) as $receipt)if(($receipt['collection']??'')===$key)$deleted[(string)$receipt['sourceId']]=true;
    foreach(tt_inv_rows([$key=>$json],$key) as $row)if(isset($deleted[mrd_id($key,$row)]))throw new DomainException('This entry was deleted. Reload the saved entries before saving again.');
}
function mrd_dependencies(array $values,array $books,string $key,array $row): void {
    $id=mrd_id($key,$row);
    if($key==='tt30queue')foreach(tt_inv_rows($values,'tt30slips') as $slip)if((string)($slip['queueId']??'')===$id)throw new DomainException('This arrival already has a Pohanch. Review its Pohanch before deleting the arrival.');
    if($key==='tt30prod'){
        foreach(tt_inv_rows($values,'tt34nilqueue') as $reference)if((string)($reference['productionId']??'')===$id)throw new DomainException('This production has a stock reconciliation. Review it in Accounts before deletion.');
        foreach((array)($row['rows']??[]) as $output)if(tt_inv_is_private_row($output))throw new DomainException('This production has a fixed stock reconciliation. Review it in Accounts before deletion.');
        foreach((array)($books['productionCostRuns']??[]) as $cost)if(($cost['entity']??'')===($row['entity']??'TTI')&&($cost['date']??'')===($row['date']??''))throw new DomainException('Production cost is approved for this date. Review the linked cost before deleting production.');
    }
    if(in_array($key,['tt35localsales','tt35exportersale'],true)){
        if(!empty($row['_ttBridge'])||!empty($row['exporterDealId']))throw new DomainException('This sale is linked to its originating SODA. Reverse it from that workflow first.');
        foreach((array)($row['loads']??$row['containers']??[]) as $load)if(!empty($load['printed'])||!empty($load['gatePassRef'])||!empty($load['gate']))throw new DomainException('A gate pass was issued for this sale. Review its dispatch before deletion.');
        if(!empty($row['pay'])||!empty($row['payments'])||!empty($row['approvedPayment']))throw new DomainException('This sale has payment entries. Review the linked payments before deletion.');
    }
    // Posted accounting links must be reversed at their owning workflow.
    $tokens=array_filter([$id,(string)($row['bridgeId']??''),(string)($row['soda']??''),(string)($row['ref']??'')],static fn($v)=>strlen($v)>=5);
    foreach((array)($books['journals']??[]) as $journal){
        if(($journal['entity']??'')!==($row['entity']??'TTI')||($journal['status']??'')!=='Posted')continue;
        $facts=json_encode([$journal['reference']??'', $journal['meta']??[], $journal['lines']??[]],JSON_THROW_ON_ERROR);
        foreach($tokens as $token)if(preg_match('/(?<![A-Za-z0-9])'.preg_quote($token,'/').'(?![A-Za-z0-9])/',$facts))throw new DomainException('This entry has an Accounts posting. Reverse the linked posting before deleting its Mill source.');
    }
}
function mrd_apply(array $values,array $books,array $user,array $body,string $now): array {
    $key=(string)($body['collection']??'');$id=(string)($body['sourceId']??'');$request=(string)($body['requestKey']??'');$reason=trim((string)($body['reason']??''));
    if(!isset(MRD_KINDS[$key])||$id===''||!preg_match('/^[A-Za-z0-9_-]{16,120}$/D',$request)||mb_strlen($reason)<5||mb_strlen($reason)>300)throw new DomainException('Select a saved entry and enter a deletion reason of 5 to 300 characters.');
    $archive=tt_inv_rows($values,MRD_ARCHIVE);
    foreach($archive as $receipt)if(($receipt['collection']??'')===$key&&(string)$receipt['sourceId']===$id){
        mrd_authorize($user,$key,$receipt['authorization']??$receipt['before'],$values);
        if(($receipt['requestKey']??'')!==$request||($receipt['reason']??'')!==$reason||(int)($receipt['userId']??0)!==(int)$user['id'])throw new DomainException('This entry was already deleted. Reload saved entries.');
        return ['values'=>$values,'receipt'=>$receipt];
    }
    $rows=tt_inv_rows($values,$key);$found=array_values(array_filter($rows,static fn($row)=>mrd_id($key,$row)===$id));
    if(count($found)!==1)throw new DomainException('The original saved entry could not be identified. Reload the saved entries.');
    $row=$found[0];$authority=$row;
    if($key==='tt33bagreceipts'){
        require_once __DIR__.'/bag_receipts_core.php';
        $source=br_source($values,tt_list_masters(),(string)($row['bagId']??''));
        $authority=array_replace($row,$source['scope']);
    }
    mrd_authorize($user,$key,$authority,$values);
    $shown=os_project($user,[$key=>tt_inv_json([$row])]+$values)['values'][$key]??'[]';
    if(!operations_same_value(json_decode($shown,true,512,JSON_THROW_ON_ERROR)[0]??null,$body['expected']??null))throw new DomainException('This entry changed after it was displayed. Reopen the saved entries before deleting.');
    mrd_dependencies($values,$books,$key,$row);
    $next=$values;$remaining=array_values(array_filter($rows,static fn($r)=>mrd_id($key,$r)!==$id));
    if($key==='tt33bagreceipts'){
        require_once __DIR__.'/bag_receipts_core.php';
        $state=bts_state($values);$bagId=(string)($row['bagId']??'');$masters=tt_list_masters();
        $source=br_source($values,$masters,$bagId);
        foreach((array)($books['bagSupplierBills']??[]) as $bill)if(($bill['entity']??'')===$source['scope']['entity']&&($bill['poNo']??'')===$source['scope']['poNo']&&($bill['lineKey']??'')===$source['scope']['poLineKey']&&!empty($bill['journalId'])&&!in_array($bill['status']??'',['Deleted','Cancelled','Reversed'],true))throw new DomainException('This receipt line has a posted supplier bill. Review that bill before deleting the physical receipt.');
        // A receipt with later physical movements is an established lot.
        foreach((array)($state['receiptDispositions']??[]) as $event)if((string)($event['receiptId']??'')===$id)throw new DomainException('This receipt has supplier returns or corrections. Reverse those movements before deletion.');
        foreach((array)($state['transfers']??[]) as $transfer)foreach((array)($transfer['lines']??[]) as $line)if((string)($line['originBagId']??'')===$bagId)throw new DomainException('These bags have transfer history. Review their movements before deleting a receipt.');
        $orders=tt_inv_rows($values,'tt30bags');
        foreach($orders as &$order)if((string)$order['id']===$bagId){$count=(float)($order['received']??0)-(float)($row['qty']??0);if($count<0)throw new DomainException('The received bag counter needs reconciliation.');$order['received']=$count;}unset($order);
        $next['tt30bags']=tt_inv_json($orders);
        $next[$key]=tt_inv_json($remaining);
        $date=(new DateTimeImmutable($now))->setTimezone(new DateTimeZone('Asia/Karachi'))->format('Y-m-d');
        $inventory=bti_project($next,$masters,$source['scope'],$date,$date,$state);
        foreach((array)$inventory['lots'] as $lot)if((string)($lot['originBagId']??'')===$bagId&&((float)($lot['balanceBeforeUnallocated']??0)<0||!empty($lot['hold'])))throw new DomainException('These bags have packing usage or unresolved movements. Review the source bag stock before deleting this receipt.');
    }else{
        $remaining=json_decode(tt_inv_prepare_write($values,$key,tt_inv_json($remaining),$user,$now),true,512,JSON_THROW_ON_ERROR);
    }
    $next[$key]=tt_inv_json($remaining);
    if($key==='tt30prod'){
        $next['tt36bagissues']=tt_inv_json(array_values(array_filter(tt_inv_rows($values,'tt36bagissues'),static fn($r)=>(string)($r['productionId']??'')!==$id)));
        $scope=tt_inv_scope($row);$beforeStock=tt_inv_stock($values,$scope);$afterStock=tt_inv_stock($next,$scope);
        foreach($afterStock as $stock=>$qty)if($qty<-.001&&$qty<(float)($beforeStock[$stock]??0)-.001)throw new DomainException('Deleting production would leave insufficient '.$stock.' stock. Review the later loading or sales first.');
    }
    // Unposted handoff events for a deleted local sale must stop reaching Accounts.
    if(in_array($key,['tt35localsales','tt35exportersale'],true)){
        $bridges=[];foreach((array)($row['loads']??$row['containers']??[]) as $load)if(!empty($load['bridgeId']))$bridges[]=(string)$load['bridgeId'];
        if(!empty($row['bridgeId']))$bridges[]=(string)$row['bridgeId'];
        if($bridges)throw new DomainException('This sale has an Accounts handoff. Review that handoff before deletion.');
    }
    if(in_array($key,TT_INV_SOURCE_KEYS,true))$next=tt_inv_reconcile($next,$now);
    $receipt=['collection'=>$key,'sourceId'=>$id,'requestKey'=>$request,'reason'=>$reason,'at'=>$now,'userId'=>(int)$user['id'],'user'=>(string)($user['full_name']??$user['username']??''),'before'=>$row,'authorization'=>tt_inv_scope($authority)];
    $archive[]=$receipt;$next[MRD_ARCHIVE]=tt_inv_json($archive);
    return ['values'=>$next,'receipt'=>$receipt];
}
