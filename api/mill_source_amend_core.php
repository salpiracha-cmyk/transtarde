<?php
declare(strict_types=1);
require_once __DIR__.'/mill_liabilities_core.php';

function msa_kind(string $collection): string {
    foreach(ml_kinds() as $kind=>$policy)if($policy['collection']===$collection)return $kind;
    throw new DomainException('Choose a supported Mill bill collection.');
}
function msa_scope(array $user,array $row,string $kind,string $action): void {
    $entity=(string)($row['entity']??'');$mill=(string)($row['millName']??$row['mill']??'');
    if(!in_array($entity,['TTI','BRM'],true)||$mill===''||empty($row['millId']))throw new DomainException('The source company and mill must be explicit.');
    $icon=$kind==='REPROCESSING'?'reprocessbill':'labour';
    if(!tt_user_can_open_module($user,'Mill')||!tt_user_can_module_action($user,'Mill',$icon,$action))throw new DomainException('Mill bill '.$action.' permission is required.');
    $scope=ml_name((string)($user['location']??''));
    if(($user['role']??'')!=='Super Admin'&&!in_array($scope,['','all locations','all authorized locations'],true)&&$scope!==ml_name($mill))throw new DomainException('This bill belongs to another assigned location.');
    $grants=(array)($user['permissions']['Accounts']??[]);
    foreach(['entity-tti','entity-brm','entity-tg'] as $key)if(array_key_exists($key,$grants)){if(!tt_user_can_access_entity($user,$entity,$action))throw new DomainException('Company '.$action.' permission is required.');break;}
}
/** The shared collection writer may append facts; amendments use the audited row route. */
function msa_guard_append(array $values,string $key,string $json,array $user): string {
    $kind=msa_kind($key);$old=ml_rows($values,$key);$incoming=ml_rows([$key=>$json],$key);$index=[];$seen=[];
    foreach($old as $r){$id=(string)($r['id']??'');if($id===''||isset($index[$id]))throw new DomainException('Existing Mill bill identities require review.');$index[$id]=$r;}
    foreach($incoming as $r){
        if(!is_array($r)||!is_string($r['id']??null)&&!is_int($r['id']??null))throw new DomainException('Each Mill bill needs a stable identity.');
        $id=(string)$r['id'];if($id===''||isset($seen[$id]))throw new DomainException('Mill bill identities must be unique.');$seen[$id]=true;
        if(isset($index[$id])){if(!hash_equals(ml_fingerprint($index[$id]),ml_fingerprint($r)))throw new DomainException('Use Amend saved bill to preserve the original source and its audit history.');}
        else{if(isset($r['_sourceAmendments']))throw new DomainException('Bill amendment history is server-owned.');msa_scope($user,$r,$kind,'Create');}
    }
    if(array_diff_key($index,$seen))throw new DomainException('Saved Mill sources cannot be removed through a collection save. Use the explicit correction workflow.');
    return $json;
}
function msa_dependencies(array $books,array $source,string $collection): void {
    $entity=(string)$source['entity'];$id=(string)$source['id'];$key='MILL|'.$entity.'|'.$source['millId'].'|'.$collection.'|'.$id;
    $posted=$books['millLiabilities'][$key]??[];$billId=(string)($posted['billId']??'');$journalId=(string)($posted['journalId']??'');
    if($billId!=='')foreach((array)($books['supplierSettlements']??[]) as $payment){
        if(!is_array($payment)||($payment['entity']??'')!==$entity||!in_array($payment['status']??'',['Posted','Approved / Posted','Approved'],true))continue;
        foreach((array)($payment['allocations']??[]) as $a)if(($a['billId']??'')===$billId&&(float)($a['amount']??0)>0)throw new DomainException('This bill has payment allocations. Reconcile its linked settlement before amending its source.');
    }
    $prefix=['tt38labourbills'=>'LAB','tt38kebills'=>'KE','tt38reprocessbills'=>'RP','tt37processingexpenses'=>'EXP'][$collection];
    foreach((array)($books['productionCostRuns']??[]) as $run){
        if(!is_array($run)||($run['entity']??'')!==$entity)continue;
        foreach((array)($run['calculation']['conversionSources']??[]) as $cost)if(($journalId!==''&&($cost['postId']??'')===$journalId)||($cost['id']??'')===$prefix.'|'.$id)throw new DomainException('This bill is included in an approved production cost. Reconcile the linked cost and stock valuation before amending its source.');
    }
}
function msa_reprocess_arrivals(array $values,array $source,string $from,string $to): array {
    ml_date($from);ml_date($to);if($to<$from)throw new DomainException('The reprocessing cost period is invalid.');
    $facts=[];$total=0.0;
    foreach(ml_rows($values,'tt30slips') as $row){
        if(!is_array($row))continue;$date=(string)($row['unloadingDate']??$row['arrivalDate']??'');
        $mill=(string)($row['millName']??$row['mill']??'');$sameMill=(string)($row['millId']??'')===(string)$source['millId']||($mill!==''&&ml_name($mill)===ml_name((string)($source['millName']??$source['mill'])));
        if(!$sameMill||$date<$from||$date>$to)continue;
        if(empty($row['entity']))throw new DomainException('Assign the historical arrival company before reviewing this reprocessing period.');
        if($row['entity']!==$source['entity'])continue;
        $kg=ml_quantity($row['payableWeight']??0,true);$total+=$kg;
        $facts[]=['id'=>(string)($row['id']??''),'date'=>$date,'kg'=>$kg,'entity'=>$row['entity'],'millId'=>(string)($row['millId']??''),'millName'=>$mill];
    }
    usort($facts,static fn($a,$b)=>strcmp($a['id'],$b['id']));
    return ['arrivalKg'=>$total,'fingerprint'=>ml_fingerprint(['entity'=>$source['entity'],'millId'=>$source['millId'],'from'=>$from,'to'=>$to,'receipts'=>$facts])];
}
/** No journal writes: retain the original row in an immutable server-built amendment trail. */
function msa_amend(array $values,array $masters,array $books,array $user,array $body,string $now): array {
    $collection=(string)($body['collection']??'');$kind=msa_kind($collection);$id=(string)($body['sourceId']??'');$rows=ml_rows($values,$collection);$matches=[];
    foreach($rows as $i=>$row)if((string)($row['id']??'')===$id)$matches[$i]=$row;
    if(count($matches)!==1)throw new DomainException('The saved source is missing or ambiguous. Reopen its history.');
    $index=array_key_first($matches);$before=$matches[$index];if(($body['entity']??'')!==($before['entity']??''))throw new DomainException('The source belongs to another company.');msa_scope($user,$before,$kind,'Edit');
    $request=(string)($body['requestKey']??'');if(!preg_match('/^[A-Za-z0-9_-]{16,120}$/D',$request))throw new DomainException('A stable amendment identity is required.');
    $requestBody=$body;unset($requestBody['csrf']);$hash=ml_fingerprint($requestBody);$history=(array)($before['_sourceAmendments']??[]);
    foreach($history as $entry)if(($entry['requestKey']??'')===$request){
        if(!hash_equals((string)$entry['requestFingerprint'],$hash))throw new DomainException('This amendment identity was used for different details.');
        return ['value'=>(string)$values[$collection],'record'=>$before,'replayed'=>true];
    }
    if(!hash_equals(ml_fingerprint($before),(string)($body['fingerprint']??'')))throw new DomainException('The source changed after review. Reopen the saved bill before amending it.');
    $reason=trim((string)($body['reason']??''));if(strlen($reason)<3||strlen($reason)>500)throw new DomainException('Enter a correction reason between 3 and 500 characters.');
    msa_dependencies($books,$before,$collection);
    $allowed=match($kind){'PROCESSING'=>['date','from','till','ref','party','type','amount','remarks'],'ELECTRICITY'=>['date','readFrom','readTo','ref','party','units','amount'],'LABOUR'=>['date','from','till','contractor','lines'],default=>['date','from','till','rawRemovedKg','rate','loadingExtra','other']};
    $patch=$body['changes']??null;if(!is_array($patch)||!$patch||array_diff(array_keys($patch),$allowed))throw new DomainException('Only the reviewed source fields may be corrected.');
    $after=array_replace($before,$patch);
    if($kind==='PROCESSING')$after['amount']=ml_money($after['amount']??null);
    if($kind==='ELECTRICITY'){$after['units']=ml_quantity($after['units']??0,true);$after['amount']=ml_money($after['amount']??null);}
    if($kind==='LABOUR'){
        $after['party']=$after['contractor'];$total=0.0;
        foreach((array)($after['lines']??[]) as $line){$basis=(string)($line['basis']??'');$total+=ml_quantity($line['qty']??null)/(str_contains($basis,'100 kg')?100:(str_contains($basis,'50 kg')?50:1))*ml_quantity($line['rate']??null);}
        $after['amount']=round($total,2);
    }
    if($kind==='REPROCESSING'){
        $review=msa_reprocess_arrivals($values,$before,(string)$after['from'],(string)$after['till']);
        if(!hash_equals($review['fingerprint'],(string)($body['arrivalFingerprint']??'')))throw new DomainException('Arrival facts changed or were not reviewed. Review the actual arrivals for this period again.');
        $after['arrivalKg']=$review['arrivalKg'];$arrival=ml_quantity($after['arrivalKg']);$removed=ml_quantity($after['rawRemovedKg']??0,true);if($removed>$arrival)throw new DomainException('Raw removals exceed the actual arrival weight.');
        $after['billableKg']=$arrival-$removed;$after['amount']=round($after['billableKg']*ml_quantity($after['rate']??null)+ml_quantity($after['loadingExtra']??0,true)+ml_quantity($after['other']??0,true),2);
        $after['balance']=$after['amount'];
    }
    if(hash_equals(ml_fingerprint($before),ml_fingerprint($after)))throw new DomainException('No source fields changed.');
    // Validates period, total, company ownership and actual active payee/master mapping.
    $candidate=$values;$copy=$rows;$copy[$index]=$after;$candidate[$collection]=json_encode($copy,JSON_THROW_ON_ERROR);ml_source($candidate,$masters,$kind,$id,(string)$before['entity']);
    $original=$before;unset($original['_sourceAmendments']);
    $history[]=['requestKey'=>$request,'requestFingerprint'=>$hash,'at'=>$now,'userId'=>(int)($user['id']??0),'by'=>(string)($user['full_name']??$user['username']??''),'reason'=>$reason,'before'=>$original];
    $after['_sourceAmendments']=$history;$rows[$index]=$after;
    return ['value'=>json_encode($rows,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'record'=>$after,'replayed'=>false];
}
