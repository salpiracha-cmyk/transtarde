<?php
declare(strict_types=1);

/** Journal-level correction shared by every Accounts posting source. Caller holds the accounts.json lock. */
function apa_correct(array &$store, array $user, string $postId, array $input): array {
    $original=$store['journals'][$postId]??null;
    if(!is_array($original)||($original['status']??'')!=='Posted')throw new DomainException('Posted entry was not found.');
    if(isset($store['exportReceipts'][(string)($original['meta']['receiptId']??'')]))throw new DomainException('Amend the linked credit advice to correct all its Pakistan and TG postings together.');
    if(!empty($original['reversalOf']))throw new DomainException('Select the original or replacement Post ID to amend.');
    foreach((array)($store['journals']??[]) as $journal)
        if(is_array($journal)&&($journal['reversalOf']??'')===$postId)throw new DomainException('This Post ID was already amended. Open its replacement Post ID.');
    $entity=(string)($original['entity']??'');
    if(!in_array($entity,['TTI','BRM','TG'],true))throw new DomainException('Unknown company on this posting.');
    $reason=trim((string)($input['reason']??''));
    if($reason===''||mb_strlen($reason)>300)throw new DomainException('Enter a correction reason (up to 300 characters).');
    $date=trim((string)($input['date']??''));
    $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    if(!$parsed||$parsed->format('Y-m-d')!==$date)throw new DomainException('Enter a valid correction date.');
    $reference=trim((string)($input['reference']??$original['reference']??''));
    $narration=trim((string)($input['narration']??$original['narration']??''));
    if($reference===''||mb_strlen($reference)>180||$narration===''||mb_strlen($narration)>500)throw new DomainException('Reference and narration are required.');
    $submitted=$input['lines']??null;
    if(!is_array($submitted)||count($submitted)<2||count($submitted)>100)throw new DomainException('Enter between two and 100 journal lines.');
    $master=json_decode((string)file_get_contents(__DIR__.'/../accounts/accounting_master_v1.json'),true);
    $names=[];
    foreach(array_merge((array)($master['chart']??[]),(array)($master['peopleSubledgers']??[])) as $account)
        if(is_array($account)&&isset($account['code'])&&($account['level']??'')!=='heading')$names[(string)$account['code']]=(string)($account['name']??$account['code']);
    foreach((array)($original['lines']??[]) as $line)if(isset($line['account']))$names[(string)$line['account']]=(string)($line['accountName']??$line['account']);
    $banks=[];
    foreach((array)(tt_list_masters()['banks']??[]) as $bank){
        if(!is_array($bank)||($bank['values'][0]??'')!=='Company Account')continue;
        $banks[(string)($bank['id']??'')]=$bank;
    }
    $lines=[];$debit=0;$credit=0;
    foreach($submitted as $i=>$entry){
        if(!is_array($entry))throw new DomainException('Invalid journal line.');
        $account=trim((string)($entry['account']??''));
        if(!isset($names[$account]))throw new DomainException('Line '.($i+1).' requires an approved ledger account.');
        foreach(['debit','credit'] as $field){$value=$entry[$field]??0;if(!is_numeric($value)||!is_finite((float)$value)||round((float)$value,2)<0)throw new DomainException('Invalid amount on line '.($i+1).'.');}
        $dr=round((float)($entry['debit']??0),2);$cr=round((float)($entry['credit']??0),2);
        if(($dr<=0&&$cr<=0)||($dr>0&&$cr>0))throw new DomainException('Each line needs either a debit or a credit.');
        $line=(array)($original['lines'][$i]??[]);
        $line['account']=$account;$line['accountName']=$names[$account];$line['debit']=$dr;$line['credit']=$cr;
        foreach(['subledger','party','counterparty','memo','billNo','lineReference'] as $field)
            if(isset($entry[$field]))$line[$field]=mb_substr(trim((string)$entry[$field]),0,180);
        $bankId=trim((string)($entry['bankAccountId']??''));
        if($account==='1110'&&$bankId==='')throw new DomainException('Choose the actual bank account on each bank line.');
        if($bankId==='')foreach(['bankAccountId','bankName','bankAccountTitle','currency','bankDebit','bankCredit','revaluationOnly'] as $key)unset($line[$key]);
        if($bankId!==''){
            if($account!=='1110'||!isset($banks[$bankId]))throw new DomainException('Bank line '.($i+1).' has an invalid bank account.');
            $bank=$banks[$bankId];$values=(array)($bank['values']??[]);$owner=strtoupper((string)($values[1]??''));
            $matches=$entity==='TG'?(str_contains($owner,'TRANS GRAINS')||preg_match('/(^|\W)TG($|\W)/',$owner)):
                ($entity==='BRM'?(str_contains($owner,'BUKSH RICE')||preg_match('/(^|\W)BRM($|\W)/',$owner)):
                (str_contains($owner,'TRANSTRADE INTERNATIONAL')||preg_match('/(^|\W)TTI($|\W)/',$owner)));
            if(!$matches)throw new DomainException('The selected bank belongs to another company.');
            $currency=strtoupper((string)($values[7]??''));
            $native=$entry['nativeAmount']??null;
            if(!is_numeric($native)||!is_finite((float)$native)||round((float)$native,2)<0)throw new DomainException('Enter the bank amount in '.$currency.' on line '.($i+1).'.');
            $native=round((float)$native,2);
            if($entity!=='TG'&&$currency==='PKR'&&abs($native-($dr+$cr))>.005)throw new DomainException('PKR bank amount must equal the book amount.');
            $line['bankAccountId']=$bankId;$line['bankName']=(string)($values[4]??'');$line['bankAccountTitle']=(string)($values[3]??'');$line['currency']=$currency;
            $line['bankDebit']=$dr>0?$native:0;$line['bankCredit']=$cr>0?$native:0;
        }
        $lines[]=$line;$debit+=(int)round($dr*100);$credit+=(int)round($cr*100);
    }
    $difference=$debit-$credit;
    if($entity==='TG'&&$difference!==0)throw new DomainException('TG journals must balance exactly to two decimal places.');
    if($entity!=='TG'&&abs($difference)>99)throw new DomainException('Pakistan journals may differ by no more than PKR 0.99. Add the missing charge or correct the entry.');
    if($difference!==0){
        $amount=abs($difference)/100;
        $lines[]=['account'=>'7195','accountName'=>$names['7195']??'Rounding Adjustment','debit'=>$difference<0?$amount:0,'credit'=>$difference>0?$amount:0,'reason'=>'Pakistan posting rounding'];
        $debit+=max(0,-$difference);$credit+=max(0,$difference);
    }
    if($debit<=0||$debit!==$credit)throw new DomainException('The corrected posting must balance.');
    $next=static function(array $journals,string $prefix):string{$n=count($journals)+1;do{$id=$prefix.'-'.gmdate('Y').'-'.str_pad((string)$n++,6,'0',STR_PAD_LEFT);}while(isset($journals[$id]));return $id;};
    $reverseId=$next((array)$store['journals'],'RV');
    $reverseLines=[];
    foreach((array)$original['lines'] as $line){$swap=$line;$swap['debit']=round((float)($line['credit']??0),2);$swap['credit']=round((float)($line['debit']??0),2);
        if(isset($line['bankDebit'])||isset($line['bankCredit'])){$swap['bankDebit']=round((float)($line['bankCredit']??0),2);$swap['bankCredit']=round((float)($line['bankDebit']??0),2);}$reverseLines[]=$swap;}
    $now=gmdate('c');$actor=(string)($user['full_name']??$user['username']??'Accounts');
    $store['journals'][$reverseId]=['id'=>$reverseId,'entity'=>$entity,'date'=>$date,'sourceType'=>'POST_AMENDMENT_REVERSAL','reference'=>$postId,'narration'=>'Correction reversal of '.$postId.' — '.$reason,'lines'=>$reverseLines,'totalDebit'=>round((float)$original['totalCredit'],2),'totalCredit'=>round((float)$original['totalDebit'],2),'status'=>'Posted','meta'=>['reason'=>$reason,'originalPostId'=>$postId],'createdAt'=>$now,'createdBy'=>$actor,'userId'=>(int)($user['id']??0),'reversalOf'=>$postId];
    $replacementId=$next((array)$store['journals'],'AUTO');
    $replacement=$original;
    $replacement['id']=$replacementId;$replacement['date']=$date;$replacement['reference']=$reference;$replacement['narration']=$narration;$replacement['lines']=$lines;
    // A distinct source type prevents source-workflow scanners from treating a correction as a second bill or payment.
    $replacement['sourceType']='POST_AMENDMENT_CORRECTION';
    $replacement['totalDebit']=$debit/100;$replacement['totalCredit']=$credit/100;$replacement['meta']=['originalSourceType'=>(string)($original['sourceType']??''),'amendmentOfPostId'=>$postId,'amendmentReason'=>$reason,'reversalPostId'=>$reverseId];
    $replacement['createdAt']=$now;$replacement['createdBy']=$actor;$replacement['userId']=(int)($user['id']??0);$replacement['reversalOf']=null;
    $store['journals'][$replacementId]=$replacement;
    $store['journals'][$postId]['amendedByPostId']=$replacementId;
    $store['journals'][$postId]['amendmentReversalPostId']=$reverseId;
    // Source records continue to identify the historical operation; their amount fields cannot
    // be inferred from arbitrary ledger corrections. The correction changes the financial ledgers.
    $store['postAmendments'][]=['originalPostId'=>$postId,'reversalPostId'=>$reverseId,'replacementPostId'=>$replacementId,'reason'=>$reason,'date'=>$date,'actor'=>$actor,'at'=>$now,'entity'=>$entity];
    return ['originalPostId'=>$postId,'reversalPostId'=>$reverseId,'replacementPostId'=>$replacementId,'entity'=>$entity];
}
