<?php
declare(strict_types=1);

/** The accepted receipt journal is immutable. Its event always points at the current replacement. */
function mps_verified_journal(array $store,array $lifting,string $entity,string $sourceKey): array {
    $journal=$store['journals'][$lifting['journalId']??'']??null;
    $event=$store['events'][$entity.'|COMMODITY_RECEIPT_ACCEPTED|'.$sourceKey]??null;
    $expected=round((float)($lifting['provisionalAmount']??-1),2);
    if(!is_array($journal)||!is_array($event)||($journal['id']??'')!==($lifting['journalId']??'')||($journal['entity']??'')!==$entity||($journal['sourceType']??'')!=='EX_MILL_PURCHASE_LIABILITY'||!in_array($journal['status']??'',!empty($lifting['reversed'])?['Reversed']:['Posted / Bill Pending','Posted'],true)||($journal['meta']['sourceKey']??'')!==$sourceKey||($event['sourceKey']??'')!==$sourceKey||($event['journalId']??'')!==($lifting['journalId']??'')||round((float)($journal['totalDebit']??-2),2)!==$expected||round((float)($journal['totalCredit']??-2),2)!==$expected)throw new DomainException('The saved lifting requires Accounts reconciliation. No record was changed.');
    foreach((array)$store['journals'] as $candidate)if(is_array($candidate)&&($candidate['reversalOf']??'')===($journal['id']??''))throw new DomainException('The lifting journal already has a reversal. Reconcile its current source before continuing.');
    return $journal;
}
function mps_edit_allowed(array $user,string $entity,array $soda): bool {
    if(tt_user_can_module_action($user,'Accounts','purchases','Edit')&&tt_user_can_access_entity($user,$entity,'Edit'))return true;
    if(!tt_user_can_module_action($user,'Mill','export','Edit'))return false;
    foreach(['entity-tti','entity-brm','entity-tg'] as $grant)if(array_key_exists($grant,(array)($user['permissions']['Accounts']??[]))){if(!tt_user_can_access_entity($user,$entity,'Edit'))return false;break;}
    $norm=static fn($value)=>strtolower(trim((string)preg_replace('/\s+/',' ',(string)$value)));
    $assigned=$norm($user['location']??'');$physical=$norm(($soda['locationName']??'')?:($soda['location']??''));
    return in_array($assigned,['','all locations','all authorized locations'],true)||($physical!==''&&$assigned===$physical);
}
function mps_correction_reason(array $body,array $old,array $journal): string {
    if(($body['expectedJournalId']??'')!==($journal['id']??''))throw new DomainException('Reopen this lifting before correcting it; the reviewed posting identity is missing or stale.');
    $reason=trim((string)($body['correctionReason']??''));if(mb_strlen($reason)<5||mb_strlen($reason)>300)throw new DomainException('Enter a correction reason of 5 to 300 characters.');
    foreach(['container','shipmentId','contractRef','lotRef','instructionKey'] as $field)if(($field!=='instructionKey'||array_key_exists($field,$old))&&((string)($old[$field]??'')!==(string)($body[$field]??'')))throw new DomainException('A posted lifting cannot be reassigned to another container or shipment instruction. Reconcile its original source first.');
    if(!empty($old['reversed'])||!empty($body['reversed']))throw new DomainException('Cancelled liftings require Accounts source reconciliation.');
    return $reason;
}
function mps_reverse_journal(array &$store,array $original,array $user,string $reason): array {
    $id=tt_next_post_id((array)$store['journals'],'Accounts','Journal');$lines=(array)($original['lines']??[]);$debit=0.;$credit=0.;
    foreach($lines as &$line){if(!is_array($line))throw new DomainException('The original receipt journal lines need reconciliation.');[$line['debit'],$line['credit']]=[(float)($line['credit']??0),(float)($line['debit']??0)];$debit+=$line['debit'];$credit+=$line['credit'];}unset($line);
    if(count($lines)<2||abs($debit-(float)$original['totalCredit'])>.005||abs($credit-(float)$original['totalDebit'])>.005)throw new DomainException('The original receipt journal lines do not reconcile.');
    $journal=['id'=>$id,'entity'=>$original['entity'],'date'=>$original['date'],'sourceType'=>'EX_MILL_LIFTING_REVERSAL','reference'=>$original['id'],'narration'=>'Lifting correction reversal of '.$original['id'].' — '.$reason,'lines'=>$lines,'totalDebit'=>round($debit,2),'totalCredit'=>round($credit,2),'status'=>'Posted','meta'=>array_merge((array)$original['meta'],['correctionReason'=>$reason,'originalPostId'=>$original['id']]),'reversalOf'=>$original['id'],'createdAt'=>gmdate('c'),'createdBy'=>(string)($user['full_name']??$user['username']??'Mill'),'userId'=>(int)($user['id']??0)];
    $store['journals'][$id]=$journal;return $journal;
}
