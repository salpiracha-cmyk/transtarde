<?php
declare(strict_types=1);
require_once __DIR__.'/bank_entries_core.php';
/** Date-only Bank Entry correction: financial amounts, accounts and facility metadata are immutable. */
function apa_bank_dates(array &$store,array $user,string $postId,array $input):array {
    $original=$store['journals'][$postId]??null;
    if(!is_array($original)||($original['sourceType']??'')!=='BANK_ENTRY'||($original['status']??'')!=='Posted'||!empty($original['reversalOf'])||!empty($original['amendedByPostId'])||!empty($original['reversedByPostId']))throw new DomainException('Select the current, unreversed Bank Entry Post ID.');
    foreach((array)$store['journals'] as $j)if(($j['reversalOf']??'')===$postId)throw new DomainException('This Bank Entry was already reversed. Open its replacement.');
    $entity=(string)$original['entity'];$date=far_date($input['date']??'','Posting date');$reason=far_text($input['reason']??'',300);if(strlen($reason)<5)throw new DomainException('Enter a correction reason of at least five characters.');
    $fid=(string)($original['meta']['bankFacilityId']??'');
    if($fid&&in_array($original['meta']['bankEntryType']??'',['FINANCE_DRAW','FINANCE_REPAY'],true)&&$date!==$original['date'])foreach((array)$store['journals'] as $j)if($j['id']!==$postId&&($j['entity']??'')===$entity&&($j['meta']['bankFacilityId']??'')===$fid&&in_array($j['meta']['bankEntryType']??'',['FINANCE_DRAW','FINANCE_REPAY'],true)&&empty($j['reversalOf'])&&empty($j['reversedByPostId'])&&empty($j['amendedByPostId'])&&(($j['date']??'')>$original['date']||(($j['date']??'')===$original['date']&&strcmp($j['id'],$postId)>0)))throw new DomainException('Correct later facility principal movements first before changing this posting date.');
    $lines=$original['lines'];$submitted=$input['lines']??null;if(!is_array($submitted)||count($submitted)!==count($lines))throw new DomainException('Keep every original Bank Entry line.');
    $changes=[];
    foreach($lines as $i=>&$line){
        if(($line['account']??'')!=='1110'||(float)($line['credit']??0)<=0)continue;
        $method=$line['bankPaymentMethod']??$original['meta']['bankPaymentMethod']??'';if(!in_array($method,['CHEQUE','ONLINE_BANKING'],true))continue;
        $field=$method==='CHEQUE'?'chequeDate':'bankPaymentDate';$chosen=(string)($submitted[$i][$field]??$line[$field]??$original['meta'][$field]??$date);
        $tracking=tt_accounts_bank_payment_details(['date'=>$date,'paymentAccountId'=>$line['bankAccountId'],'bankPaymentMethod'=>$method,'chequeNo'=>$line['chequeNo']??$original['meta']['chequeNo']??'','chequeDate'=>$chosen,'bankPaymentDate'=>$chosen,'bankReference'=>$line['bankReference']??$original['meta']['bankReference']??'','paymentNarration'=>$line['paymentNarration']??'']);
        $line[$field]=$tracking[$field];$changes[]=['account'=>$line['accountName']??'Bank','oldDate'=>$original['lines'][$i][$field]??$original['date'],'date'=>$chosen];
    }unset($line);
    // Work on a copy so invalid finance chronology cannot mutate the caller's books.
    $next=$store;$reverse=[];foreach($original['lines'] as $l){[$l['debit'],$l['credit']]=[$l['credit'],$l['debit']];foreach([['bankDebit','bankCredit'],['nativeDebit','nativeCredit']] as [$dr,$cr])if(isset($l[$dr])||isset($l[$cr]))[$l[$dr],$l[$cr]]=[$l[$cr]??0,$l[$dr]??0];$reverse[]=$l;}
    $rev=ben_journal($next,$entity,$original['date'],$postId,'Date correction reversal — '.$reason,$reverse,$user,$original['meta']??[],$postId);
    $meta=(array)($original['meta']??[]);$meta['publicPostId']=tt_accounts_public_post($store,$original);$meta['amendmentOfPostId']=$postId;$meta['amendmentReason']=$reason;$meta['reversalPostId']=$rev['id'];$meta['amendmentChanges']=$changes;$meta['amendmentNote']='Edited '.gmdate('Y-m-d').' — '.$reason.' — Posting date: '.$original['date'].' → '.$date;
    $rowCount=count((array)($meta['debitRows']??[]));
    if(($meta['bankEntryType']??'')==='PAYMENT'&&count($lines)===$rowCount*2)foreach($meta['debitRows'] as $i=>&$row){$bankLine=$lines[$rowCount+$i]??[];if(($bankLine['account']??'')!=='1110')continue;foreach(['chequeDate','bankPaymentDate'] as $field)if(isset($bankLine[$field])){$row[$field]=$bankLine[$field];$lines[$i][$field]=$bankLine[$field];}}unset($row);
    // Row payment dates are authoritative; do not leave obsolete aggregate dates in metadata.
    foreach(['chequeDate','bankPaymentDate'] as $field){$values=array_values(array_unique(array_filter(array_column($lines,$field))));if(count($values)===1)$meta[$field]=$values[0];elseif(count($values)>1)unset($meta[$field]);}
    $new=ben_journal($next,$entity,$date,(string)($original['reference']??''),(string)($original['narration']??''),$lines,$user,$meta);
    $next['journals'][$postId]['amendedByPostId']=$new['id'];$next['journals'][$postId]['reversedByPostId']=$rev['id'];$next['journals'][$postId]['amendmentReversalPostId']=$rev['id'];
    ben_validate_finance($next,$entity);
    $next['postAmendments'][]=['originalPostId'=>$postId,'reversalPostId'=>$rev['id'],'replacementPostId'=>$new['id'],'reason'=>$reason,'date'=>$date,'actor'=>$user['username']??'','at'=>gmdate('c'),'entity'=>$entity];$store=$next;
    return ['originalPostId'=>$postId,'reversalPostId'=>$rev['id'],'replacementPostId'=>$new['id'],'publicPostId'=>$meta['publicPostId'],'entity'=>$entity,'changes'=>$changes];
}
