<?php
declare(strict_types=1);
require_once __DIR__.'/mill_liabilities_core.php';

/** Non-monetary reconciliation. Existing cash, expenses and monthly balances stay authoritative. */
function mref_policies():array {return ['RENT'=>'tt39rentpayments','SALARY_ADVANCE'=>'tt39salaryadvances'];}
function mref_source(array $values,array $masters,string $entity,string $kind,string $id):array {
    $collection=mref_policies()[$kind]??'';if($collection===''||$id===''||!in_array($entity,['TTI','BRM'],true))throw new DomainException('Select a supported company reference.');
    $matches=array_values(array_filter(ml_rows($values,$collection),static fn($r)=>is_array($r)&&($r['entity']??'')===$entity&&(string)($r['id']??'')===$id));
    if(count($matches)!==1)throw new DomainException('The original reference is missing or ambiguous.');$r=$matches[0];
    if(!empty($r['cancelled'])||in_array($r['status']??'',['Cancelled','Deleted','Reversed'],true))throw new DomainException('The reference was cancelled.');
    $mill=trim((string)($r['millName']??''));if($mill===''||empty($r['millId']))throw new DomainException('Confirm the original company and mill before reconciliation.');$locations=[];
    foreach((array)($masters['mills']??[]) as $m){$v=array_values((array)($m['values']??[]));if(ml_name((string)($v[0]??''))!==ml_name($mill))continue;$owner=(string)($v[7]??'');if($owner===''&&($m['id']??'')==='mills-1'&&ml_name($mill)==='tti rice mills')$owner='TTI';if($owner!==$entity||($v[2]??'')!=='Own Mill'||strcasecmp((string)($v[5]??''),'Active')!==0)continue;if(!empty($r['locationId'])&&$r['locationId']!==($m['id']??''))continue;$locations[]=$m;}
    if(count($locations)!==1)throw new DomainException('Resolve one active owned mill in Mills & Locations Master.');
    $date=ml_date((string)($r['date']??''));$amount=ml_money($r['amount']??null);if($amount<=0)throw new DomainException('A positive original reference amount is required.');$person=trim((string)($r['person']??''));
    $from=(string)($r['fromMonth']??substr($date,0,7));$to=(string)($r['tillMonth']??$from);
    foreach([$from,$to] as $month)if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',$month))throw new DomainException('Confirm the original reference months.');
    if($to<$from||($kind==='SALARY_ADVANCE'&&($person===''||($r['month']??substr($date,0,7))!==substr($date,0,7))))throw new DomainException('Confirm the original person and reference period.');
    return ['key'=>$entity.'|'.$collection.'|'.$id,'entity'=>$entity,'kind'=>$kind,'collection'=>$collection,'sourceId'=>$id,'millName'=>$mill,'locationId'=>(string)$locations[0]['id'],'date'=>$date,'amount'=>$amount,'person'=>$person,'fromMonth'=>$from,'toMonth'=>$to,'reference'=>(string)($r['ref']??''),'fingerprint'=>ml_fingerprint(['source'=>$r,'location'=>$locations[0]]),'snapshot'=>$r];
}
function mref_target(array $books,array $source,string $targetId):array {
    $advance=$source['kind']==='SALARY_ADVANCE';$prefix=$advance?'ADVANCE|':'RENT|';if(!str_starts_with($targetId,$prefix))throw new DomainException('Select an existing payment of the same kind.');
    $id=substr($targetId,strlen($prefix));$p=$books[$advance?'salaryAdvances':'rentSalaryPayments'][$id]??null;
    if(!is_array($p)||($p['entity']??'')!==$source['entity']||(!$advance&&($p['type']??'')!=='RENT'))throw new DomainException('The selected payment belongs to another company or workflow.');
    $m=$books[$advance?'salaryMasters':'rentMasters'][$p['masterId']??'']??null;
    if(!is_array($m)||($m['entity']??'')!==$source['entity'])throw new DomainException('The original canonical master is unavailable.');
    if($advance?ml_name((string)($m['name']??''))!==ml_name($source['person']):ml_name((string)($m['mill']??''))!==ml_name($source['millName']))throw new DomainException('The original person or rent mill does not match.');
    if(($p['date']??'')!==$source['date']||abs(ml_money($p['amount']??null)-$source['amount'])>.005)throw new DomainException('Reference date and full amount must match the existing payment. Split or combined references need their original allocation reviewed first.');
    if((string)($m['effectiveFrom']??'')>$source['date']||(!empty($m['effectiveTo'])&&$m['effectiveTo']<$source['date']))throw new DomainException('The payment date is outside the original master period.');
    $journal=$books['journals'][$p['journalId']??'']??null;$meta=(array)($journal['meta']??[]);
    if(!is_array($journal)||($journal['entity']??'')!==$source['entity']||($journal['status']??'')!=='Posted'||($journal['date']??'')!==$source['date']||($journal['sourceType']??'')!==($advance?'SALARY_ADVANCE':'RENT_PAYMENT')||!empty($journal['reversalOf'])||!empty($journal['reversedBy'])||!empty($journal['reversalJournalId']))throw new DomainException('The original payment Post is unavailable or reversed.');
    foreach((array)($books['journals']??[]) as $j)if(is_array($j)&&($j['entity']??'')===$source['entity']&&($j['status']??'')==='Posted'&&($j['reversalOf']??'')===($journal['id']??$p['journalId']))throw new DomainException('The original payment has a posted reversal.');
    if(($meta[$advance?'salaryMasterId':'rentMasterId']??'')!==($p['masterId']??'')||($advance&&($meta['salaryAdvanceId']??'')!==$id))throw new DomainException('The canonical master/payment journal identity disagrees.');
    $dr=0.;$cr=0.;$cash=0.;$purpose=0.;
    foreach((array)($journal['lines']??[]) as $line){if(!is_array($line))throw new DomainException('Invalid payment journal.');foreach(['debit','credit'] as $side)if(!is_numeric($line[$side]??0)||!is_finite((float)($line[$side]??0))||(float)($line[$side]??0)<0)throw new DomainException('Invalid payment amount.');$d=(float)($line['debit']??0);$c=(float)($line['credit']??0);$dr+=$d;$cr+=$c;$code=(string)($line['account']??'');if(in_array($code,['1110','1120'],true))$cash+=$c-$d;elseif(in_array($code,$advance?['1230']:['2140','1400'],true))$purpose+=$d-$c;else throw new DomainException('The payment has an unexpected accounting component.');}
    if(abs($dr-$cr)>.005||abs($cash-$source['amount'])>.005||abs($purpose-$source['amount'])>.005)throw new DomainException('The original balanced cash payment does not match this reference.');
    if(!$advance){$alloc=(array)($p['allocations']??[]);$months=[];$allocated=0.;foreach($alloc as $r){$month=(string)($r['month']??'');if($month<$source['fromMonth']||$month>$source['toMonth'])throw new DomainException('The original rent allocation is outside the reported months.');$months[]=$month;$allocated+=ml_money($r['amount']??null);}if(!$months||min($months)!==$source['fromMonth']||max($months)!==$source['toMonth']||abs($allocated-$source['amount'])>.005||ml_fingerprint($alloc)!==ml_fingerprint((array)($meta['allocations']??[])))throw new DomainException('Reconcile the original rent month allocations.');}
    // Remaining advance and salary-period settlements legitimately change later.
    // They are displayed from canonical records, never rewritten by this link.
    $identity=array_intersect_key($p,array_flip(['id','entity','masterId','person','date','amount','journalId','type','allocations']));
    return ['id'=>$targetId,'masterId'=>(string)$p['masterId'],'name'=>(string)($advance?$m['name']:$m['payee']),'journalId'=>(string)$p['journalId'],'date'=>$p['date'],'amount'=>$source['amount'],'remaining'=>$advance?($p['remaining']??null):null,'allocations'=>$advance?($p['adjustments']??[]):($p['allocations']??[]),'fingerprint'=>ml_fingerprint(['payment'=>$identity,'master'=>array_intersect_key($m,array_flip(['id','entity','name','mill','payee','effectiveFrom','effectiveTo'])),'journal'=>$journal])];
}
function mref_status(array $books,array $source):array {
    $link=$books['millReferenceLinks'][$source['key']]??null;if(!is_array($link)||($link['status']??'')==='Unlinked')return ['status'=>'Unlinked','link'=>$link];
    try{if(!hash_equals((string)($link['sourceFingerprint']??''),$source['fingerprint']))throw new DomainException('The original reference or mill mapping changed.');$target=mref_target($books,$source,(string)($link['targetId']??''));if(!hash_equals((string)($link['targetFingerprint']??''),$target['fingerprint']))throw new DomainException('The original canonical payment changed.');foreach((array)($books['millReferenceLinks']??[]) as $key=>$other)if($key!==$source['key']&&($other['status']??'')==='Linked'&&(($other['targetId']??'')===$target['id']||($other['journalId']??'')===$target['journalId'])&&($other['entity']??'')===$source['entity'])throw new DomainException('The same payment was linked more than once.');return ['status'=>'Verified existing payment','link'=>$link,'target'=>$target];}catch(DomainException $e){return ['status'=>'Needs reconciliation','link'=>$link,'blocker'=>$e->getMessage()];}
}
function mref_choices(array $books,array $source):array {
    $rows=[];$collection=$source['kind']==='SALARY_ADVANCE'?'salaryAdvances':'rentSalaryPayments';$prefix=$source['kind']==='SALARY_ADVANCE'?'ADVANCE|':'RENT|';
    foreach((array)($books[$collection]??[]) as $id=>$p){if(!is_array($p)||($p['entity']??'')!==$source['entity'])continue;try{$t=mref_target($books,$source,$prefix.(string)($p['id']??$id));foreach((array)($books['millReferenceLinks']??[]) as $key=>$other)if($key!==$source['key']&&($other['status']??'')==='Linked'&&($other['entity']??'')===$source['entity']&&(($other['targetId']??'')===$t['id']||($other['journalId']??'')===$t['journalId']))continue 2;$rows[]=$t;}catch(DomainException){}}
    return $rows;
}
function mref_apply(array &$books,array $source,array $body,array $user):array {
    $requestKey=(string)($body['requestKey']??'');if(!preg_match('/^[A-Za-z0-9_-]{16,120}$/D',$requestKey))throw new DomainException('A stable reconciliation request is required.');
    $reason=trim((string)($body['reason']??''));if($reason===''||strlen($reason)>1000)throw new DomainException('Enter the reconciliation reason.');
    $action=(string)($body['action']??'');if(!in_array($action,['link','unlink'],true))throw new DomainException('Choose Link or Unlink.');
    $fp=ml_fingerprint(array_intersect_key($body,array_flip(['action','entity','kind','sourceId','sourceFingerprint','targetId','targetFingerprint','linkFingerprint','reason'])));$requestId=$source['entity'].'|'.$requestKey;$prior=$books['millReferenceRequests'][$requestId]??null;
    if($prior){if(!hash_equals((string)$prior['fingerprint'],$fp))throw new DomainException('This request was already used for a different reconciliation.');return ['duplicate'=>true,'key'=>$source['key']];}
    if(!hash_equals($source['fingerprint'],(string)($body['sourceFingerprint']??'')))throw new DomainException('The saved reference changed. Refresh deliberately and review it again.');
    $old=$books['millReferenceLinks'][$source['key']]??null;$target=null;if(!hash_equals(ml_fingerprint($old??[]),(string)($body['linkFingerprint']??'')))throw new DomainException('The reference link changed. Refresh deliberately before changing it.');
    if($action==='link'){$target=mref_target($books,$source,(string)($body['targetId']??''));if(!hash_equals($target['fingerprint'],(string)($body['targetFingerprint']??'')))throw new DomainException('The canonical payment changed. Refresh deliberately before linking.');foreach((array)($books['millReferenceLinks']??[]) as $key=>$other)if($key!==$source['key']&&($other['status']??'')==='Linked'&&($other['entity']??'')===$source['entity']&&(($other['targetId']??'')===$target['id']||($other['journalId']??'')===$target['journalId']))throw new DomainException('This original payment is already linked to another reference.');}
    elseif(!$old)throw new DomainException('This reference has no saved link.');
    $history=(array)($old['history']??[]);if($old){$version=$old;unset($version['history']);$history[]=$version;}
    $record=['key'=>$source['key'],'entity'=>$source['entity'],'kind'=>$source['kind'],'sourceId'=>$source['sourceId'],'millName'=>$source['millName'],'status'=>$action==='link'?'Linked':'Unlinked','sourceFingerprint'=>$source['fingerprint'],'sourceSnapshot'=>$source['snapshot'],'targetId'=>$target['id']??'','targetFingerprint'=>$target['fingerprint']??'','journalId'=>$target['journalId']??'','reason'=>$reason,'by'=>(string)($user['username']??''),'userId'=>(int)($user['id']??0),'at'=>gmdate('c'),'history'=>$history];
    $books['millReferenceLinks'][$source['key']]=$record;$books['millReferenceRequests'][$requestId]=['fingerprint'=>$fp,'key'=>$source['key']];$books['revision']=(int)($books['revision']??0)+1;
    return ['duplicate'=>false,'key'=>$source['key'],'message'=>$action==='link'?'Existing payment linked. No new payment or expense was posted.':'Reference link removed; the original payment is unchanged.'];
}
