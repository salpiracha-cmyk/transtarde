<?php
declare(strict_types=1);

/** One recipient and one payment source; every expense has its own debit leg. */
function dex_rows(mixed $input,array $names): array {
    if(!is_array($input)||count($input)<1||count($input)>50)throw new InvalidArgumentException('Add between one and 50 expense rows.');
    $rows=[];$total=0.0;
    foreach($input as $row){
        if(!is_array($row))throw new InvalidArgumentException('An expense row is invalid.');
        $category=strtoupper(trim((string)($row['category']??'')));$purpose=trim((string)($row['purpose']??''));
        $value=$row['amount']??null;if(!is_numeric($value)||!is_finite((float)$value)||(float)$value<=0||(float)$value>100000000000)throw new InvalidArgumentException('Enter a positive amount for each expense.');
        $amount=round((float)$value,2);if($amount<=0)throw new InvalidArgumentException('Each expense must be at least 0.01.');
        if($purpose===''||strlen($purpose)>500)throw new InvalidArgumentException('Enter the purpose of each expense (up to 500 characters).');
        $detail='';$account=match($category){'HOME'=>'FAM-HOUSEHOLD','OFFICE','OTHER'=>'6900','MILL'=>'5200','MEDICAL'=>'6230','RENT'=>'6300','DONATION'=>'7200',default=>throw new InvalidArgumentException('Choose Home, Office, Mill, Medical, Rent, Donation or Other.')};
        if($category==='MEDICAL'){$detail=strtoupper((string)($row['medicalFor']??''));if(!in_array($detail,['HOUSEHOLD','COMPANY_STAFF'],true))throw new InvalidArgumentException('Choose household or company/staff for medical expenses.');if($detail==='HOUSEHOLD')$account='FAM-HOUSEHOLD';}
        if($category==='RENT'){$detail=strtoupper((string)($row['rentFor']??''));if(!in_array($detail,['HOME','OFFICE','MILL'],true))throw new InvalidArgumentException('Choose Home, Office or Mill for rent.');if($detail==='HOME')$account='FAM-HOUSEHOLD';elseif($detail==='MILL')$account='5200';}
        if($category==='DONATION'){$detail=strtoupper((string)($row['donationType']??''));$account=match($detail){'ZAKAT'=>'7210','SADQA'=>'7220','FI_SABILILLAH'=>'7230',default=>throw new InvalidArgumentException('Choose Zakat, Sadqa or Fi Sabilillah.')};}
        if(!isset($names[$account]))throw new RuntimeException('Expense subaccount is unavailable.');
        $rows[]=['category'=>$category,'purpose'=>$purpose,'amount'=>$amount,'account'=>$account,'accountName'=>$names[$account],'medicalFor'=>$category==='MEDICAL'?$detail:'','rentFor'=>$category==='RENT'?$detail:'','donationType'=>$category==='DONATION'?$detail:''];$total=round($total+$amount,2);
    }
    return [$rows,$total];
}
function dex_check_cheque(array $store,string $entity,string $bankId,array $tracking): void {
    if(($tracking['bankPaymentMethod']??'')!=='CHEQUE')return;
    $number=strtoupper(trim((string)($tracking['chequeNo']??'')));
    foreach((array)($store['journals']??[]) as $journal){
        if(($journal['entity']??'')!==$entity||($journal['status']??'')!=='Posted'||!empty($journal['reversalOf'])||!empty($journal['reversedByPostId'])||!empty($journal['amendedByPostId']))continue;
        foreach((array)($journal['lines']??[]) as $line)if(($line['account']??'')==='1110'&&(float)($line['credit']??0)>0&&($line['bankAccountId']??$journal['meta']['bankAccountId']??'')===$bankId&&strtoupper((string)($line['chequeNo']??$journal['meta']['chequeNo']??''))===$number)throw new InvalidArgumentException('This bank cheque number has already been posted.');
    }
    foreach((array)($store['issuedCheques']??[]) as $cheque)if(($cheque['entity']??'')===$entity&&($cheque['bankAccountId']??$cheque['paymentAccountId']??'')===$bankId&&strtoupper((string)($cheque['chequeNo']??''))===$number&&!in_array($cheque['status']??'',['Cancelled','Voided','Reversed'],true))throw new InvalidArgumentException('This cheque is already recorded in Issued Cheques.');
}
function dex_post(array &$store,string $entity,array $body,array $user,array $names,array $tracking): array {
    $payee=trim((string)($body['payee']??''));if($payee===''||strlen($payee)>180)throw new InvalidArgumentException('Enter who receives this payment.');
    $date=ev1_date((string)($body['paymentDate']??''),'Payment date');$reference=trim((string)($body['reference']??''));if(strlen($reference)>180)throw new InvalidArgumentException('Reference is too long.');
    [$rows,$total]=dex_rows($body['expenseLines']??null,$names);$paymentId=trim((string)($body['paymentAccountId']??''));
    if(isset($body['amount'])&&(!is_numeric($body['amount'])||abs(round((float)$body['amount'],2)-$total)>.005))throw new InvalidArgumentException('Payment total must equal all expense rows.');
    foreach((array)($store['generalExpenses']??[]) as $previous)if(($previous['entity']??'')===$entity&&!in_array(($previous['status']??''),['Deleted','Amended'],true)&&$reference!==''&&strcasecmp((string)($previous['reference']??''),$reference)===0&&strcasecmp((string)($previous['payee']??''),$payee)===0)throw new InvalidArgumentException('This expense reference is already recorded for the recipient.');
    $credit=ev1_pay_line($store,$entity,$paymentId,$total,$names);dex_check_cheque($store,$entity,$paymentId,$tracking);
    $id=ev1_id((array)($store['generalExpenses']??[]),'GEX');$meta=['generalExpenseId'=>$id,'directExpense'=>true,'payee'=>$payee,'paymentAccountId'=>$paymentId,'expenseLines'=>$rows];$lines=[];
    foreach($rows as $row)$lines[]=ev1_line($row['account'],$row['amount'],0,$names,['generalExpenseId'=>$id,'subledger'=>$row['purpose'],'expenseCategory'=>$row['category'],'expensePurpose'=>$row['purpose'],'medicalFor'=>$row['medicalFor'],'rentFor'=>$row['rentFor'],'donationType'=>$row['donationType'],'location'=>$row['category']==='MILL'||$row['rentFor']==='MILL'?'MILL':($row['category']==='HOME'||$row['medicalFor']==='HOUSEHOLD'||$row['rentFor']==='HOME'?'HOME':'OFFICE')]);
    $lines[]=array_merge($credit,$tracking);$description=implode(' / ',array_column($rows,'purpose'));
    $jid=ev1_journal($store,$entity,$date,'DIRECT_EXPENSE_PAYMENT',$reference!==''?$reference:$id,$payee.' — '.$description,$lines,$user,$meta);
    $store['generalExpenses'][$id]=['id'=>$id,'entity'=>$entity,'expenseType'=>'DIRECT','expenseLines'=>$rows,'expenseAccount'=>count($rows)===1?$rows[0]['account']:'','expenseAccountName'=>count($rows)===1?$rows[0]['accountName']:'Multiple expenses','paymentDate'=>$date,'amount'=>$total,'payee'=>$payee,'description'=>$description,'reference'=>$reference,'location'=>count($rows)===1?$rows[0]['category']:'MULTIPLE','paymentAccountId'=>$paymentId,'journalId'=>$jid,'status'=>'Posted','createdAt'=>gmdate('c'),'createdBy'=>$user['full_name']??$user['username']??'Accounts'];
    return ['generalExpenseId'=>$id,'journalId'=>$jid,'amount'=>$total];
}
function dex_amend(array &$store,string $entity,array $body,array $user,array $names,array $tracking): array {
    $id=trim((string)($body['id']??''));$old=$store['generalExpenses'][$id]??null;
    if(!is_array($old)||($old['entity']??'')!==$entity||($old['expenseType']??'')!=='DIRECT'||($old['status']??'Posted')!=='Posted')throw new InvalidArgumentException('Select a current expense payment in this company.');
    $reason=trim((string)($body['reason']??''));if(strlen($reason)<5||strlen($reason)>500)throw new InvalidArgumentException('Enter a correction reason of 5 to 500 characters.');
    $reversals=ev1_reverse_journals($store,[$old['journalId']],$entity,$user,$names,$reason);$store['generalExpenses'][$id]['status']='Amended';
    $result=dex_post($store,$entity,$body,$user,$names,$tracking);$newId=$result['generalExpenseId'];$newJournal=$result['journalId'];
    $store['generalExpenses'][$id]['replacementExpenseId']=$newId;$store['generalExpenses'][$newId]['amendmentOf']=$id;
    $store['journals'][$newJournal]['meta']['amendmentOf']=$old['journalId'];$store['journals'][$old['journalId']]['amendedByPostId']=$newJournal;
    foreach($reversals as $jid){$store['journals'][$jid]['sourceType']='DIRECT_EXPENSE_REVERSAL';$store['journals'][$jid]['narration']='Correction of '.$old['journalId'].' — '.$reason;}
    $store['expenseAudit'][]=['action'=>'AMEND_DIRECT_EXPENSE','entity'=>$entity,'id'=>$id,'replacementExpenseId'=>$newId,'before'=>$old,'reason'=>$reason,'reversalJournalIds'=>$reversals,'at'=>gmdate('c'),'by'=>$user['username']??''];
    return $result+['originalExpenseId'=>$id,'reversalJournalIds'=>$reversals];
}
