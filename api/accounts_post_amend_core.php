<?php
declare(strict_types=1);
require_once __DIR__.'/accounts_reference.php';
require_once __DIR__.'/accounts_bank_payment.php';
require_once __DIR__.'/opening_balance_core.php';

/** Journal-level correction shared by every Accounts posting source. Caller holds the accounts.json lock. */
function apa_correct(array &$store, array $user, string $postId, array $input): array {
    $original=$store['journals'][$postId]??null;
    if (in_array($original['sourceType']??'', ['BANK_ENTRY','BANK_ENTRY_REVERSAL'], true)) throw new DomainException('Correct bank entries from Bank Entry so facility balances and subaccounts stay linked.');
    if(!empty($original['meta']['investmentTransactionId']))throw new DomainException('Correct this posting in Assets & Investments so broker funds, routing balances and shares stay linked.');
    if(!empty($original['meta']['directExpense']))throw new DomainException('Correct this expense in Pay Expense so its item breakdown and voucher stay linked.');
    if(!empty($original['meta']['planId']))throw new DomainException('Correct this payment through its Payment Plan so truck allocations and plan progress stay linked.');
    $opening=($original['sourceType']??'')==='OPENING_BALANCE_BF'||!empty($original['meta']['openingBalance']);if($opening&&!job_authorized($user))throw new DomainException('Opening balance corrections require authorised management access.');if($opening&&($original['sourceType']??'')==='OPENING_BALANCE_REVERSAL')throw new DomainException('Select the current opening balance.');
    if(!is_array($original)||($original['status']??'')!=='Posted')throw new DomainException('Posted entry was not found.');
    if(!empty($original['meta']['tgRemittanceId']))throw new DomainException('Use the TG remittance review to reopen and correct this linked payment with a reason.');
    if(isset($store['exportReceipts'][(string)($original['meta']['receiptId']??'')]))throw new DomainException('Amend the linked credit advice to correct all its Pakistan and TG postings together.');
    if(!empty($original['reversalOf']))throw new DomainException('Select the original or replacement Post ID to amend.');
    foreach((array)($store['journals']??[]) as $journal)
        if(is_array($journal)&&($journal['reversalOf']??'')===$postId)throw new DomainException('This Post ID was already amended. Open its replacement Post ID.');
    $entity=(string)($original['entity']??'');
    if(!in_array($entity,['TTI','BRM','TG'],true))throw new DomainException('Unknown company on this posting.');
    $reason=trim((string)($input['reason']??''));
    if($reason===''||mb_strlen($reason)>300)throw new DomainException('Enter a correction reason (up to 300 characters).');
    $date=$opening?JOB_DATE:trim((string)($input['date']??''));
    $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    if(!$parsed||$parsed->format('Y-m-d')!==$date)throw new DomainException('Enter a valid correction date.');
    $reference=trim((string)($input['reference']??$original['reference']??''));
    $narration=trim((string)($input['narration']??$original['narration']??''));
    if(mb_strlen($reference)>180||mb_strlen($narration)>500)throw new DomainException('Reference or narration is too long.');
    $submitted=$input['lines']??null;
    if(!is_array($submitted)||count($submitted)<2||count($submitted)>((($original['sourceType']??'')==='SALARY_MONTH_COMPLETED'||($original['meta']['originalSourceType']??'')==='SALARY_MONTH_COMPLETED')?500:100))throw new DomainException('Enter at least two journal lines within the posting limit.');
    if($opening){if(count($submitted)!==2)throw new DomainException('Opening balances require the account and clearing line only.');foreach($submitted as $i=>$entry)if(($entry['account']??'')!==($original['lines'][$i]['account']??'')||trim((string)($entry['subledger']??''))!==trim((string)($original['lines'][$i]['subledger']??'')))throw new DomainException('Keep the original opening account and party; amend its amount.');}
    $master=json_decode((string)file_get_contents(__DIR__.'/../accounts/accounting_master_v1.json'),true);
    $names=[];
    foreach(array_merge((array)($master['chart']??[]),(array)($master['peopleSubledgers']??[])) as $account)
        if(is_array($account)&&isset($account['code'])&&($account['level']??'')!=='heading')$names[(string)$account['code']]=(string)($account['name']??$account['code']);
    foreach((array)($original['lines']??[]) as $line)if(isset($line['account']))$names[(string)$line['account']]=(string)($line['accountName']??$line['account']);
    $banks=[];
    foreach((array)(tt_list_masters()['banks']??[]) as $bank){
        if(!is_array($bank)||!in_array(($bank['values'][0]??''),['Company Account','Proprietor / Owner Account','Personal Account'],true))continue;
        $banks[(string)($bank['id']??'')]=$bank;
    }
    $lines=[];$debit=0;$credit=0;
    foreach($submitted as $i=>$entry){
        if(!is_array($entry))throw new DomainException('Invalid journal line.');
        $account=trim((string)($entry['account']??''));
        if(in_array($account,['1430','1440','1610','2610'],true))throw new DomainException('Use the investment or bank-finance register for this account.');
        if(!isset($names[$account]))throw new DomainException('Line '.($i+1).' requires an approved ledger account.');
        foreach(['debit','credit'] as $field){$value=$entry[$field]??0;if(!is_numeric($value)||!is_finite((float)$value)||round((float)$value,2)<0)throw new DomainException('Invalid amount on line '.($i+1).'.');}
        $dr=round((float)($entry['debit']??0),2);$cr=round((float)($entry['credit']??0),2);
        if(($dr<=0&&$cr<=0)||($dr>0&&$cr>0))throw new DomainException('Each line needs either a debit or a credit.');
        $line=(array)($original['lines'][$i]??[]);
        if(!empty($line['subaccountId'])&&$account!==($line['account']??''))throw new DomainException('Correct named subaccount classification through its original entry form.');
        if($opening){if(($entry['bankAccountId']??'')!==($line['bankAccountId']??''))throw new DomainException('Keep the original opening bank account.');if(isset($line['nativeDebit'])||isset($line['nativeCredit'])){$nativeRatio=((float)($line['nativeDebit']??0)+(float)($line['nativeCredit']??0))/max(.01,(float)($line['debit']??0)+(float)($line['credit']??0));$line['nativeDebit']=round($dr*$nativeRatio,2);$line['nativeCredit']=round($cr*$nativeRatio,2);}}$line['account']=$account;$line['accountName']=$names[$account];$line['debit']=$dr;$line['credit']=$cr;
        foreach(['subledger','party','counterparty','memo','billNo','lineReference'] as $field)
            if(isset($entry[$field]))$line[$field]=mb_substr(trim((string)$entry[$field]),0,180);
        $bankId=trim((string)($entry['bankAccountId']??''));
        if($account==='1110'&&$bankId==='')throw new DomainException('Choose the actual bank account on each bank line.');
        if($bankId==='')foreach(['bankAccountId','bankName','bankAccountTitle','currency','bankDebit','bankCredit','revaluationOnly','bankPaymentMethod','bankReference','chequeNo','chequeDate','paymentNarration'] as $key)unset($line[$key]);
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
            $line['bankDebit']=$dr>0?$native:0;$line['bankCredit']=$cr>0?$native:0;if($opening){$line['nativeDebit']=$line['bankDebit'];$line['nativeCredit']=$line['bankCredit'];}
            if($cr>0&&!empty($entry['bankPaymentMethod'])){$method=(string)$entry['bankPaymentMethod'];$tracking=tt_accounts_bank_payment_details(['paymentAccountId'=>$bankId,'date'=>$date,'bankPaymentMethod'=>$method,'bankReference'=>$entry['bankReference']??'','chequeNo'=>$method==='CHEQUE'?($entry['bankReference']??''):'','chequeDate'=>$date,'paymentNarration'=>$entry['paymentNarration']??'']);$line=array_merge($line,$tracking);}
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
    $reverseId=tt_next_post_id((array)$store['journals'],'Accounts','Journal',$date);
    $reverseLines=[];
    foreach((array)$original['lines'] as $line){$swap=$line;$swap['debit']=round((float)($line['credit']??0),2);$swap['credit']=round((float)($line['debit']??0),2);
        if(isset($line['bankDebit'])||isset($line['bankCredit'])){$swap['bankDebit']=round((float)($line['bankCredit']??0),2);$swap['bankCredit']=round((float)($line['bankDebit']??0),2);}if(isset($line['nativeDebit'])||isset($line['nativeCredit'])){$swap['nativeDebit']=$line['nativeCredit']??0;$swap['nativeCredit']=$line['nativeDebit']??0;}$reverseLines[]=$swap;}
    $now=gmdate('c');$actor=(string)($user['full_name']??$user['username']??'Accounts');
    $store['journals'][$reverseId]=['id'=>$reverseId,'entity'=>$entity,'date'=>$date,'sourceType'=>'POST_AMENDMENT_REVERSAL','reference'=>$postId,'narration'=>'Correction reversal of '.$postId.' — '.$reason,'lines'=>$reverseLines,'totalDebit'=>round((float)$original['totalCredit'],2),'totalCredit'=>round((float)$original['totalDebit'],2),'status'=>'Posted','meta'=>['reason'=>$reason,'originalPostId'=>$postId],'createdAt'=>$now,'createdBy'=>$actor,'userId'=>(int)($user['id']??0),'reversalOf'=>$postId];
    $replacementId=tt_next_post_id((array)$store['journals'],'Accounts','Journal',$date);
    $replacement=$original;
    $replacement['id']=$replacementId;$replacement['date']=$date;$replacement['reference']=$reference;$replacement['narration']=$narration;$replacement['lines']=$lines;
    // A distinct source type prevents source-workflow scanners from treating a correction as a second bill or payment.
    $replacement['sourceType']=$opening?'OPENING_BALANCE_BF':'POST_AMENDMENT_CORRECTION';
    $replacement['totalDebit']=$debit/100;$replacement['totalCredit']=$credit/100;$replacement['meta']=['originalSourceType'=>(string)($original['meta']['originalSourceType']??$original['sourceType']??''),'amendmentOfPostId'=>$postId,'amendmentReason'=>$reason,'reversalPostId'=>$reverseId];
    if($opening)$replacement['meta']=array_replace((array)$original['meta'],$replacement['meta']);$changes=[];foreach($lines as $i=>$line){$before=$original['lines'][$i]??[];if(($before['debit']??0)!==$line['debit']||($before['credit']??0)!==$line['credit'])$changes[]=['account'=>$line['accountName'],'party'=>$line['subledger']??'','oldDebit'=>$before['debit']??0,'oldCredit'=>$before['credit']??0,'debit'=>$line['debit'],'credit'=>$line['credit']];}$replacement['meta']['amendmentChanges']=$changes;
    $replacement['createdAt']=$now;$replacement['createdBy']=$actor;$replacement['userId']=(int)($user['id']??0);$replacement['reversalOf']=null;
    $replacement['meta']['publicPostId']=tt_accounts_public_post($store,$original);$replacement['meta']['amendmentNote']='Edited '.gmdate('Y-m-d').' by '.$actor.' — '.$reason.($date!==$original['date']?' — Date: '.$original['date'].' → '.$date:'');
    if($opening){unset($replacement['openingReversalId'],$replacement['amendedByPostId'],$replacement['amendmentReversalPostId']);$store['journals'][$postId]['openingReversalId']=$reverseId;foreach((array)($store['jvDrafts']??[]) as $key=>$draft)if(($draft['journalId']??'')===$postId){$store['jvDrafts'][$key]['status']='Amended';$next=$replacement;$next['id']='JVD-AMEND-'.$replacementId;$next['journalId']=$replacementId;$next['openingBalance']=true;$store['jvDrafts'][$next['id']]=$next;}}
    $store['journals'][$replacementId]=$replacement;
    $store['journals'][$postId]['amendedByPostId']=$replacementId;
    $store['journals'][$postId]['amendmentReversalPostId']=$reverseId;
    // Source records continue to identify the historical operation; their amount fields cannot
    // be inferred from arbitrary ledger corrections. The correction changes the financial ledgers.
    $store['postAmendments'][]=['originalPostId'=>$postId,'reversalPostId'=>$reverseId,'replacementPostId'=>$replacementId,'reason'=>$reason,'date'=>$date,'actor'=>$actor,'at'=>$now,'entity'=>$entity];
    return ['originalPostId'=>$postId,'reversalPostId'=>$reverseId,'replacementPostId'=>$replacementId,'publicPostId'=>$replacement['meta']['publicPostId'],'entity'=>$entity,'changes'=>$changes];
}

