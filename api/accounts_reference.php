<?php
declare(strict_types=1);

// Search/display aliases never replace the persisted ID or external invoice number.
function tt_accounts_reference_matches(string $query, mixed $reference): bool {
    $reference=(string)$reference;
    if(!preg_match('/^(?:[A-Z]+-)?(\d{4})-(\d+)(?:-JV)?$/i',$reference,$ref))return false;
    if(ctype_digit($query)&&str_starts_with($query,$ref[1])&&strlen($query)>4&&ltrim(substr($query,4),'0')===ltrim($ref[2],'0'))return true;
    if(ctype_digit($query)&&strlen($query)>=2&&strlen($query)<=4&&$query!==$ref[1]&&str_ends_with(str_pad($ref[2],4,'0',STR_PAD_LEFT),$query))return true;
    if(preg_match('/^(?:(\d{4})-)?(\d+)$/',$query,$input))
        return ($input[1]===''||$input[1]===$ref[1])&&ltrim($input[2],'0')===ltrim($ref[2],'0');
    return false;
}

/** Resolve current correction without changing the public ID or source history. */
function tt_accounts_current_journal(array $store,string $id):array {
    $seen=[];$journal=$store['journals'][$id]??[];
    while(!empty($journal['amendedByPostId'])){
        if(isset($seen[$id]))throw new DomainException('Correction history has a loop. Open Ledgers → Post IDs and ask Super Admin to review this posting.');
        $seen[$id]=true;$id=(string)$journal['amendedByPostId'];$journal=$store['journals'][$id]??[];
    }
    return is_array($journal)?$journal:[];
}
/** Historical JV labels preserve their old voucher number; persisted links stay unchanged. */
function tt_accounts_jv_public_post(array $store,array $journal,string $id):string {
    if(!in_array($journal['sourceType']??'',['JV','JV_REVERSAL'],true)||!empty($journal['meta']['sharedPostSequence']))return $id;
    $legacy=(string)($journal['legacyPostId']??$journal['id']??$id);
    $draftId=(string)($journal['meta']['jvDraftId']??'');
    if(preg_match('/^JV-(\d{4})-(\d+)$/',$legacy,$m))return 'POST-'.$m[1].'-'.$m[2].'-JV';
    if(($journal['sourceType']??'')==='JV'&&preg_match('/^JVD-(\d{4})-(\d+)$/',$draftId,$m))return 'POST-'.$m[1].'-'.$m[2].'-JV';
    if(preg_match('/^(?:POST|JV|AUTO)-(\d{4})-(\d+)$/',$legacy,$m))return 'POST-'.$m[1].'-'.$m[2].'-JV';
    return $id;
}

/** Public transaction identity remains the first journal; internal correction IDs remain auditable. */
function tt_accounts_public_post(array $store,array $journal): string {
 $id=(string)($journal['meta']['publicPostId']??$journal['id']??'');$seen=[];
 if(($journal['entity']??'')==='TG'&&empty($journal['meta']['publicPostId'])){
  $receiptIds=(array)($journal['meta']['receiptIds']??[]);if(!$receiptIds&&!empty($journal['meta']['receiptId']))$receiptIds=[$journal['meta']['receiptId']];
  if(count($receiptIds)===1){$receipt=$store['exportReceipts'][(string)$receiptIds[0]]??[];$pak=$store['journals'][(string)($receipt['journalId']??'')]??[];
   if(in_array($pak['entity']??'',['TTI','BRM'],true)&&(($receipt['tgRemittancePostId']??'')===($journal['id']??'')||in_array($journal['id']??'',(array)($receipt['tgMirrorPostIds']??[]),true)))$id=(string)($pak['meta']['publicPostId']??$pak['id']);
  }
 }

 while($id!==''&&!isset($seen[$id])){$seen[$id]=true;$j=$store['journals'][$id]??null;if(!is_array($j))break;$parent=(string)($j['meta']['amendmentOfPostId']??$j['meta']['amendmentOf']??'');if($parent===''||!isset($store['journals'][$parent]))break;$id=$parent;}return tt_accounts_jv_public_post($store,(array)($store['journals'][$id]??$journal),$id);
}
function tt_accounts_amendment_note(array $journal,array $store=[]): string {
 $m=(array)($journal['meta']??[]);$note=(string)($m['amendmentNote']??(!empty($m['amendmentReason'])?'Edited: '.$m['amendmentReason']:''));if($note!=='')return $note;
 $parent=(string)($m['amendmentOfPostId']??$m['amendmentOf']??'');$old=$store['journals'][$parent]??null;if(!is_array($old))return '';
 $changes=[];foreach(['date'=>'Date','narration'=>'Narration','reference'=>'Reference','totalDebit'=>'Amount'] as $field=>$label)if(($old[$field]??'')!==($journal[$field]??''))$changes[]=$label.': '.($old[$field]??'').' → '.($journal[$field]??'');
 return 'Edited'.($changes?' — '.implode('; ',$changes):' — correction retained in audit history');
}

/** Resolve an exact stored/public identity within one company; never guess a numeric search alias. */
function tt_accounts_resolve_post_key(array $store,string $requested,string $entity=''):string {
    $direct=$store['journals'][$requested]??null;
    if(is_array($direct)&&($entity===''||$entity==='ALL'||($direct['entity']??'')===$entity))return $requested;
    $found=[];foreach((array)($store['journals']??[]) as $key=>$journal){
        if(!is_array($journal)||($entity!==''&&$entity!=='ALL'&&($journal['entity']??'')!==$entity)||!empty($journal['reversalOf']))continue;
        if((string)($journal['id']??'')!==$requested&&tt_accounts_public_post($store,$journal)!==$requested)continue;
        $current=tt_accounts_current_journal($store,(string)$key);if(!$current||!empty($current['reversalOf'])||!empty($current['reversedByPostId']))continue;
        $currentKey=(string)$key;foreach((array)$store['journals'] as $candidateKey=>$candidate)if($candidate===$current){$currentKey=(string)$candidateKey;break;}$found[$currentKey]=true;
    }
    if(count($found)>1)throw new DomainException('This public Post ID matches more than one active posting. Open the exact posting from its company ledger.');
    return count($found)===1?(string)array_key_first($found):$requested;
}
