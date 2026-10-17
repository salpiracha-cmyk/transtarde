<?php
declare(strict_types=1);

/** Mill sources are evidence for review, never evidence that cash was paid. */
function ml_kinds(): array {
    return [
        'LABOUR'=>['collection'=>'tt38labourbills','expense'=>'5200','payable'=>'2190','role'=>'Labour Contractor'],
        'ELECTRICITY'=>['collection'=>'tt38kebills','expense'=>'5200','payable'=>'2140','role'=>'Service Provider'],
        'PROCESSING'=>['collection'=>'tt37processingexpenses','expense'=>'5200','payable'=>'2140','role'=>'Service Provider'],
        'REPROCESSING'=>['collection'=>'tt38reprocessbills','expense'=>'5200','payable'=>'2140','role'=>'Service Provider'],
    ];
}
function ml_date(string $date): string {
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);$errors=DateTimeImmutable::getLastErrors();
    if (!$d||($errors&&($errors['warning_count']||$errors['error_count']))||$d->format('Y-m-d')!==$date) throw new DomainException('A valid saved source date is required.');
    return $date;
}
function ml_quantity(mixed $value,bool $allowZero=false): float {
    if (!is_numeric($value)||!is_finite((float)$value)||($allowZero?(float)$value<0:(float)$value<=0)) throw new DomainException('Enter a valid saved source quantity or rate.');
    return (float)$value;
}
function ml_money(mixed $amount): float {return round(ml_quantity($amount),2);}
function ml_name(string $name): string {return strtolower(trim((string)preg_replace('/\s+/',' ',$name)));}
function ml_rows(array $values,string $key): array {
    $rows=$values[$key]??'[]';if (is_string($rows)) $rows=json_decode($rows,true,512,JSON_THROW_ON_ERROR);
    if (!is_array($rows)||!array_is_list($rows)) throw new DomainException('Saved Mill source must be a list.');
    return $rows;
}
function ml_fingerprint(array $source): string {
    $sort=static function(mixed $value)use(&$sort):mixed {if (!is_array($value)) return $value;if (!array_is_list($value)) ksort($value);foreach ($value as &$v) $v=$sort($v);unset($v);return $value;};
    return hash('sha256',json_encode($sort($source),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}

/** Resolve the committed record and exact active master identities server-side. */
function ml_source(array $values,array $masters,string $kind,string $id,string $entity): array {
    $policy=ml_kinds()[$kind]??null;if (!$policy||$id===''||!in_array($entity,['TTI','BRM'],true)) throw new DomainException('Choose a supported Mill bill and company.');
    $matches=array_values(array_filter(ml_rows($values,$policy['collection']),static fn($r)=>is_array($r)&&(string)($r['id']??'')===$id));
    if (count($matches)!==1) throw new DomainException('The committed Mill source is missing or ambiguous.');
    $r=$matches[0];if (($r['entity']??'')!==$entity) throw new DomainException('The Mill bill belongs to another company.');
    if (!empty($r['cancelled'])||in_array($r['status']??'',['Cancelled','Reversed','Deleted'],true)) throw new DomainException('This source has been cancelled.');
    if (empty($r['millId'])||trim((string)($r['millName']??$r['mill']??''))==='') throw new DomainException('The source company and mill must be explicitly recorded.');
    $millName=trim((string)($r['millName']??$r['mill']));$locations=[];
    foreach ((array)($masters['mills']??[]) as $m) {
        $v=array_values((array)($m['values']??[]));
        if (ml_name((string)($v[0]??''))!==ml_name($millName)) continue;
        if (strcasecmp((string)($v[5]??''),'Active')!==0) throw new DomainException('The source mill is inactive or unverified.');
        if (!empty($r['locationId'])&&($m['id']??'')!==$r['locationId']) throw new DomainException('The source mill ID and name disagree.');
        $owner=strtoupper(trim((string)($v[7]??'')));
        if ($owner===''&&($m['id']??'')==='mills-1'&&ml_name($millName)==='tti rice mills') $owner='TTI';
        if ($kind==='REPROCESSING'&&!in_array($v[2]??'',['External Mill','Reprocessing Mill'],true)) throw new DomainException('Select the actual external reprocessing mill.');
        if ($kind!=='REPROCESSING'&&($owner!==$entity||($v[2]??'')!=='Own Mill')) throw new DomainException('This company does not own the selected bill location.');
        $locations[]=['id'=>(string)$m['id'],'name'=>(string)$v[0]];
    }
    if (count($locations)!==1) throw new DomainException('Resolve one active mill in Mills & Locations Master.');
    $party=trim((string)($r['party']??$r['contractor']??$r['mill']??''));$parties=[];
    foreach ((array)($masters['business_parties']??[]) as $m) {
        $v=array_values((array)($m['values']??[]));if (ml_name((string)($v[0]??''))!==ml_name($party)) continue;
        if (strcasecmp((string)($v[10]??''),'Active')!==0) throw new DomainException('The source payee is inactive or unverified.');
        $roles=array_map('trim',preg_split('/[;,|]/',(string)($v[2]??''))?:[]);
        if (!in_array(ml_name($policy['role']),array_map('ml_name',$roles),true)) throw new DomainException('The source payee needs the correct active business-party role.');
        $parties[]=['id'=>(string)$m['id'],'name'=>(string)$v[0]];
    }
    if (count($parties)!==1) throw new DomainException('Resolve one active source payee in Business Parties.');
    $date=ml_date((string)($r['date']??''));$from=ml_date((string)($kind==='ELECTRICITY'?($r['readFrom']??''):($r['from']??'')));$to=ml_date((string)($kind==='ELECTRICITY'?($r['readTo']??''):($r['till']??'')));
    if ($date<$to) throw new DomainException('The bill date cannot precede the billed period end.');
    if ($to<$from) throw new DomainException('The source cost period is invalid.');
    $amount=ml_money($r['amount']??null);$reference=trim((string)($r['ref']??$r['billNo']??''));if ($reference==='') throw new DomainException('The original bill reference is required.');
    if ($kind==='LABOUR') {
        if (strtoupper(trim((string)($r['commodity']??'')))!=='RICE') throw new DomainException('Maize labour bill and rate design remains deferred.');
        $total=0.0;$lines=(array)($r['lines']??[]);if (!$lines) throw new DomainException('The saved labour work lines are missing.');
        foreach ($lines as $line) {
            $qty=ml_quantity($line['qty']??null);$rate=ml_quantity($line['rate']??null);$basis=(string)($line['basis']??'');
            if ($basis==='') throw new DomainException('Saved labour rate basis is required.');
            $total+=$qty/(str_contains($basis,'100 kg')?100:(str_contains($basis,'50 kg')?50:1))*$rate;
        }
        if (abs(round($total,2)-$amount)>.01) throw new DomainException('Saved labour lines and bill total disagree.');
    }
    if ($kind==='REPROCESSING') {
        $arrival=ml_quantity($r['arrivalKg']??null);$removed=ml_quantity($r['rawRemovedKg']??0,true);$billable=ml_quantity($r['billableKg']??null,true);$rate=ml_quantity($r['rate']??null);
        if ($removed>$arrival||abs($billable-($arrival-$removed))>.0005) throw new DomainException('Reprocessing arrival, raw returns and billable quantity disagree.');
        $gross=round($billable*$rate+ml_quantity($r['loadingExtra']??0,true)+ml_quantity($r['other']??0,true),2);
        if (abs($gross-$amount)>.01) throw new DomainException('Reprocessing quantities, rates, extras and bill total disagree.');
    }
    if($kind==='PROCESSING'){
        if(($r['settlementMode']??'')!=='CANONICAL_PAYABLE'||!empty($r['payment'])||!empty($r['payments'])||(float)($r['paid']??0)>0)throw new DomainException('Reconcile historical reported processing payments to canonical Accounts before posting this cost.');
        if(!in_array($r['type']??'',['Plant Expense','Milling Expense'],true))throw new DomainException('Select an actual plant or milling processing cost.');
    }
    // Reported paid amounts cannot be imported as either cash or a second liability.
    if ($kind==='REPROCESSING'&&((float)($r['paid']??0)>0||!empty($r['payments']))) throw new DomainException('Reconcile reported reprocessing payments to canonical Accounts before posting this bill.');
    $key='MILL|'.$entity.'|'.$r['millId'].'|'.$policy['collection'].'|'.$id;
    return ['key'=>$key,'kind'=>$kind,'sourceId'=>$id,'collection'=>$policy['collection'],'entity'=>$entity,'millId'=>(string)$r['millId'],'locationId'=>$locations[0]['id'],'millName'=>$locations[0]['name'],
        'partyId'=>$parties[0]['id'],'party'=>$parties[0]['name'],'date'=>$date,'from'=>$from,'to'=>$to,'reference'=>$reference,'amount'=>$amount,'workLines'=>$kind==='LABOUR'?array_map(static fn($l)=>array_intersect_key($l,array_flip(['label','key','qty','basis','rate','auto'])),(array)($r['lines']??[])):[],'reprocessing'=>$kind==='REPROCESSING'?array_intersect_key($r,array_flip(['arrivalKg','rawRemovedKg','billableKg','rate','loadingExtra','other'])):null,'expenseAccount'=>$policy['expense'],'payableAccount'=>$policy['payable'],'fingerprint'=>ml_fingerprint(['source'=>$r,'location'=>$locations[0],'party'=>$parties[0],'policy'=>$policy]),'original'=>$r];
}

/** Pure posting transform. Caller owns the operations + Accounts locks and access. */
function ml_post(array &$store,array $source,array $user,string $expectedFingerprint,string $journalId,string $now): array {
    if (!hash_equals($source['fingerprint'],$expectedFingerprint)) throw new DomainException('The Mill bill changed after review. Reopen it before posting.');
    $existing=$store['millLiabilities'][$source['key']]??null;
    if (is_array($existing)&&($existing['status']??'')!=='Reversed') {
        if (!hash_equals((string)$existing['fingerprint'],$source['fingerprint'])) throw new DomainException('This posted source was amended. Use the linked correction workflow.');
        $journal=$store['journals'][$existing['journalId']??'']??null;$bill=$store['supplierBills'][$existing['billId']??'']??null;
        if (!is_array($journal)||($journal['status']??'')!=='Posted'||($journal['meta']['millSourceKey']??'')!==$source['key']||!is_array($bill)||($bill['entity']??'')!==$source['entity']) throw new DomainException('The previous acknowledgement has no verified canonical journal and bill. Reconcile it before retrying.');
        return ['duplicate'=>true,'record'=>$existing];
    }
    foreach (['supplierBills','otherPurchases','utilityPayments','generalExpenses'] as $collection) foreach ((array)($store[$collection]??[]) as $bill) {
        if (!is_array($bill)||($bill['entity']??'')!==$source['entity']||in_array($bill['status']??'',['Cancelled','Deleted','Reversed'],true)) continue;
        $party=(string)($bill['vendor']??$bill['supplier']??$bill['payee']??'');$ref=(string)($bill['billNo']??$bill['invoiceNo']??$bill['reference']??'');
        if (ml_name($party)===ml_name($source['party'])&&strcasecmp(trim($ref),$source['reference'])===0) throw new DomainException('This provider reference already exists in Accounts. Reconcile it instead of posting again.');
    }
    if ($journalId===''||isset($store['journals'][$journalId])) throw new DomainException('A unique canonical Post ID is required.');
    if (is_array($existing)&&hash_equals((string)$existing['fingerprint'],$source['fingerprint'])) throw new DomainException('Amend the original source before replacing a reversed bill.');
    $billId='MB-'.substr(hash('sha256',$source['key'].'|'.$source['fingerprint']),0,24);$amount=$source['amount'];$by=(string)($user['full_name']??$user['username']??'Accounts');
    $meta=['millSourceKey'=>$source['key'],'sourceFingerprint'=>$source['fingerprint'],'sourceCollection'=>$source['collection'],'sourceRecordId'=>$source['sourceId'],'locationId'=>$source['locationId'],'millName'=>$source['millName'],'costFrom'=>$source['from'],'costTo'=>$source['to'],'partyId'=>$source['partyId'],'supplier'=>$source['party'],'billId'=>$billId,'productionCostEligible'=>true];
    $lines=[['accountName'=>'Milling / Conversion Cost','account'=>$source['expenseAccount'],'debit'=>$amount,'credit'=>0,'counterparty'=>$source['party']]+$meta,
        ['accountName'=>$source['payableAccount']==='2190'?'Labour Contractors Payable':'Supplier Payables & Expenses','account'=>$source['payableAccount'],'debit'=>0,'credit'=>$amount,'counterparty'=>$source['party'],'subledger'=>$source['party']]+$meta];
    $store['journals'][$journalId]=['id'=>$journalId,'entity'=>$source['entity'],'date'=>$source['date'],'sourceType'=>'MILL_BILL_LIABILITY','reference'=>$source['reference'],'narration'=>$source['kind'].' bill — '.$source['millName'].' — '.$source['party'],'lines'=>$lines,'totalDebit'=>$amount,'totalCredit'=>$amount,'status'=>'Posted','meta'=>$meta,'createdAt'=>$now,'createdBy'=>$by,'userId'=>(int)($user['id']??0),'reversalOf'=>null];
    $store['supplierBills'][$billId]=['id'=>$billId,'entity'=>$source['entity'],'category'=>'MILL_'.$source['kind'],'vendor'=>$source['party'],'broker'=>$source['party'],'billNo'=>$source['reference'],'billDate'=>$source['date'],'dueDate'=>$source['date'],'payableAccount'=>$source['payableAccount'],'expenseAccount'=>$source['expenseAccount'],'supplierPayableTotal'=>$amount,'receiptAllocations'=>[['sourceKey'=>$source['key'],'supplierPayableShare'=>$amount,'payee'=>$source['party'],'dueDate'=>$source['date'],'reference'=>$source['reference']]],'journalId'=>$journalId,'postingJournalIds'=>[$journalId],'status'=>'Outstanding','sourceRecord'=>$meta,'createdAt'=>$now,'createdBy'=>$by];
    $record=['key'=>$source['key'],'fingerprint'=>$source['fingerprint'],'billId'=>$billId,'journalId'=>$journalId,'entity'=>$source['entity'],'sourceCollection'=>$source['collection'],'sourceRecordId'=>$source['sourceId'],'amount'=>$amount,'status'=>'Posted','postedAt'=>$now,'postedBy'=>$by,'userId'=>(int)($user['id']??0),'sourceSnapshot'=>$source['original']];
    if (is_array($existing)) {$prior=$existing;unset($prior['versions']);$record['versions']=array_merge((array)($existing['versions']??[]),[$prior]);}
    $store['millLiabilities'][$source['key']]=$record;$store['millLiabilityAudit'][]=['at'=>$now,'action'=>'POST','key'=>$source['key'],'journalId'=>$journalId,'by'=>$by,'userId'=>(int)($user['id']??0)];
    return ['duplicate'=>false,'record'=>$record];
}

function ml_reverse(array &$store,string $sourceKey,string $entity,array $user,string $reason,string $journalId,string $date,string $now): array {
    $record=$store['millLiabilities'][$sourceKey]??null;
    if (!is_array($record)||$record['entity']!==$entity) throw new DomainException('The linked Mill liability was not found in this company.');
    if (trim($reason)==='') throw new DomainException('A correction or cancellation reason is required.');
    if (($record['status']??'')==='Reversed') return ['duplicate'=>true,'record'=>$record];
    foreach ((array)($store['supplierSettlements']??[]) as $payment) {
        if (!is_array($payment)||($payment['entity']??'')!==$entity||!in_array($payment['status']??'',['Posted','Approved / Posted','Approved'],true)) continue;
        foreach ((array)($payment['allocations']??[]) as $allocation) {
            if (($allocation['billId']??'')===$record['billId']&&(float)($allocation['amount']??0)>0) throw new DomainException('This bill has payment allocations. Correct or reverse its linked settlement before changing the liability.');
        }
    }
    $prefix=['tt38labourbills'=>'LAB','tt38kebills'=>'KE','tt38reprocessbills'=>'RP','tt37processingexpenses'=>'EXP'][$record['sourceCollection']??'']??'';
    foreach ((array)($store['productionCostRuns']??[]) as $run) {
        if (!is_array($run)||($run['entity']??'')!==$entity) continue;
        foreach ((array)($run['calculation']['conversionSources']??[]) as $cost) {
            if (($cost['postId']??'')===$record['journalId']||($prefix!==''&&($cost['id']??'')===$prefix.'|'.$record['sourceRecordId'])) throw new DomainException('This bill is included in an approved production cost. Reconcile the linked cost and stock valuation before reversing its liability.');
        }
    }
    $linkedBill=$store['supplierBills'][$record['billId']]??null;
    if (!is_array($linkedBill)||($linkedBill['entity']??'')!==$entity) throw new DomainException('The linked canonical supplier bill is unavailable.');
    $original=$store['journals'][$record['journalId']]??null;
    if (!is_array($original)||($original['status']??'')!=='Posted') throw new DomainException('The original posted journal is unavailable.');
    foreach ((array)($store['journals']??[]) as $journal) if (($journal['reversalOf']??'')===$original['id']) throw new DomainException('The original journal already has a reversal; reconcile its source status.');
    if ($journalId===''||isset($store['journals'][$journalId])) throw new DomainException('A unique reversal Post ID is required.');
    $date=ml_date($date);$by=(string)($user['full_name']??$user['username']??'Accounts');$lines=[];
    foreach ($original['lines'] as $line) {$reverse=$line;$reverse['debit']=$line['credit'];$reverse['credit']=$line['debit'];$lines[]=$reverse;}
    $store['journals'][$journalId]=['id'=>$journalId,'entity'=>$entity,'date'=>$date,'sourceType'=>'MILL_BILL_REVERSAL','reference'=>$original['id'],'narration'=>'Reversal of '.$original['id'].': '.trim($reason),'lines'=>$lines,'totalDebit'=>$original['totalCredit'],'totalCredit'=>$original['totalDebit'],'status'=>'Posted','meta'=>['millSourceKey'=>$sourceKey,'billId'=>$record['billId'],'reason'=>trim($reason)],'reversalOf'=>$original['id'],'createdAt'=>$now,'createdBy'=>$by,'userId'=>(int)($user['id']??0)];
    $bill=&$store['supplierBills'][$record['billId']];
    $bill['originalSupplierPayableTotal']=$bill['supplierPayableTotal'];$bill['supplierPayableTotal']=0;
    foreach ($bill['receiptAllocations'] as &$allocation) $allocation['supplierPayableShare']=0;unset($allocation);
    $bill['status']='Reversed';$bill['reversalJournalId']=$journalId;unset($bill);
    $record['status']='Reversed';$record['reversalJournalId']=$journalId;$record['reversalReason']=trim($reason);$record['reversedAt']=$now;$record['reversedBy']=$by;
    $store['millLiabilities'][$sourceKey]=$record;$store['millLiabilityAudit'][]=['at'=>$now,'action'=>'REVERSE','key'=>$sourceKey,'journalId'=>$journalId,'reason'=>trim($reason),'by'=>$by,'userId'=>(int)($user['id']??0)];
    return ['duplicate'=>false,'record'=>$record];
}
