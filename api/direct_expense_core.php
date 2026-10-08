<?php
declare(strict_types=1);
require_once __DIR__.'/accounts_subaccounts_core.php';
require_once __DIR__.'/accounts_reference.php';

/** One recipient and one payment source; every expense has its own debit leg. */
function dex_rows(mixed $input,array $names,array $store=[],string $entity='TTI'): array {
    if(!is_array($input)||count($input)<1||count($input)>50)throw new InvalidArgumentException('Add between one and 50 expense rows.');
    $rows=[];$total=0.0;
    foreach($input as $row){
        if(!is_array($row))throw new InvalidArgumentException('An expense row is invalid.');
        $category=strtoupper(trim((string)($row['category']??'')));$purpose=trim((string)($row['purpose']??''));
        $value=$row['amount']??null;if(!is_numeric($value)||!is_finite((float)$value)||(float)$value<=0||(float)$value>100000000000)throw new InvalidArgumentException('Enter a positive amount for each expense.');
        $amount=round((float)$value,2);if($amount<=0)throw new InvalidArgumentException('Each expense must be at least 0.01.');
        if($purpose===''||strlen($purpose)>500)throw new InvalidArgumentException('Enter the purpose of each expense (up to 500 characters).');
        $detail='';$account=match($category){'HOME'=>'6910','OFFICE','OTHER'=>'6900','MILL'=>'5200','MEDICAL'=>'6230','RENT'=>'6300','DONATION'=>'7200','CAR_REPAIRS'=>'6410','FUEL'=>'6610','VEHICLE_TAX'=>'6620','REPAIRS'=>'6400','TRAVEL'=>'6500','PROFESSIONAL'=>'6700',default=>throw new InvalidArgumentException('Choose Home, Office, Mill, Medical, Rent, Donation or Other.')};
        if($category==='MEDICAL'){$detail=strtoupper((string)($row['medicalFor']??''));if(!in_array($detail,['HOUSEHOLD','COMPANY_STAFF'],true))throw new InvalidArgumentException('Choose household or company/staff for medical expenses.');if($detail==='HOUSEHOLD')$account='6910';}
        if($category==='RENT'){$detail=strtoupper((string)($row['rentFor']??''));if(!in_array($detail,['HOME','OFFICE','MILL'],true))throw new InvalidArgumentException('Choose Home, Office or Mill for rent.');if($detail==='HOME')$account='6910';elseif($detail==='MILL')$account='5200';}
        if($category==='DONATION'){$detail=strtoupper((string)($row['donationType']??''));$account=match($detail){'ZAKAT'=>'7210','SADQA'=>'7220','FI_SABILILLAH'=>'7230','OTHER'=>'7200',default=>throw new InvalidArgumentException('Choose Zakat, Sadqa or Fi Sabilillah.')};}
        if(!empty($row['accountCode'])){
            $selected=(string)$row['accountCode'];$head=sac_entity_chart($store,$entity)[$selected]??null;
            if(!$head||in_array($head['level']??'',['heading','system'],true)||($head['class']!=='Expense'&&!str_starts_with($selected,'FAM-')&&$selected!=='3200'))throw new InvalidArgumentException('Choose an expense, manufacturing cost or family allocation account.');
            $account=$selected;if($category==='DONATION'&&$selected==='7200')$account=match($detail){'ZAKAT'=>'7210','SADQA'=>'7220','FI_SABILILLAH'=>'7230','OTHER'=>'7200'};if(($category==='MEDICAL'&&$detail==='HOUSEHOLD'&&$selected==='6230')||($category==='RENT'&&$detail==='HOME'&&$selected==='6300'))$account='6910';if($category==='RENT'&&$detail==='MILL'&&$selected==='6300')$account='5200';
        }
        $subextra=[];if(!empty($row['subaccountId'])){$sub=sac_resolve($store,$entity,(string)$row['subaccountId']);if($sub['class']!=='Expense'&&!str_starts_with($sub['parentCode'],'FAM-')&&$sub['parentCode']!=='3200')throw new InvalidArgumentException('Choose an expense, manufacturing cost or family allocation subaccount.');if(!empty($row['accountCode'])&&(string)$row['accountCode']!==$sub['parentCode'])throw new InvalidArgumentException('The subaccount has moved. Reopen Expenses and choose its current head.');$account=$sub['parentCode'];$subextra=sac_extra($sub);}
        if(in_array($account,['6410','6610','6620'],true))$category=match($account){'6410'=>'CAR_REPAIRS','6610'=>'FUEL','6620'=>'VEHICLE_TAX'};
        $assetExtra=[];$assetId=trim((string)($row['assetId']??''));
        if(in_array($category,['CAR_REPAIRS','FUEL','VEHICLE_TAX'],true)&&$assetId==='')throw new InvalidArgumentException('Choose the vehicle and registration number.');
        if($assetId!==''){
            $asset=$store['managedAssets'][$assetId]??null;
            if(!is_array($asset)||($asset['entity']??'')!==$entity||!in_array($asset['type']??'',['VEHICLE','MOTORCYCLE','MACHINERY','FURNITURE','COMPUTER'],true))throw new InvalidArgumentException('Choose an asset in these company books.');
            if(in_array($category,['CAR_REPAIRS','FUEL','VEHICLE_TAX'],true)&&!in_array($asset['type'],['VEHICLE','MOTORCYCLE'],true))throw new InvalidArgumentException('Choose a registered vehicle.');
            $assetExtra=['assetId'=>$assetId,'assetName'=>$asset['name'],'registrationNo'=>$asset['registrationNo']??''];
        }
        if(!isset($names[$account]))throw new RuntimeException('Expense subaccount is unavailable.');
        $taxExtra=[];if($category==='VEHICLE_TAX'){$start=ev1_date((string)($row['periodFrom']??''),'Tax period from');$end=ev1_date((string)($row['periodTo']??''),'Tax period to');if($end<$start)throw new InvalidArgumentException('Tax period end must follow its start.');$taxExtra=['periodFrom'=>$start,'periodTo'=>$end,'challanReference'=>trim((string)($row['challanReference']??''))];if(strlen($taxExtra['challanReference'])>180)throw new InvalidArgumentException('Challan reference is too long.');}
        $rows[]=$taxExtra+$assetExtra+$subextra+['category'=>$category,'purpose'=>$purpose,'amount'=>$amount,'account'=>$account,'accountName'=>$names[$account],'medicalFor'=>$category==='MEDICAL'?$detail:'','rentFor'=>$category==='RENT'?$detail:'','donationType'=>$category==='DONATION'?$detail:''];$total=round($total+$amount,2);
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
    $profile=null;foreach(sac_payees($store,$entity,'EXPENSE') as $candidate)if(sac_normal($candidate['name'])===sac_normal($payee)){$profile=$candidate;break;}
    if(!empty($body['payeeId'])&&(!$profile||$profile['id']!==(string)$body['payeeId']))throw new InvalidArgumentException('Choose the current expense recipient.');
    $date=ev1_date((string)($body['paymentDate']??''),'Payment date');$reference=trim((string)($body['reference']??''));if(strlen($reference)>180)throw new InvalidArgumentException('Reference is too long.');
    [$rows,$total]=dex_rows($body['expenseLines']??null,$names,$store,$entity);$paymentId=trim((string)($body['paymentAccountId']??''));
    if(isset($body['amount'])&&(!is_numeric($body['amount'])||abs(round((float)$body['amount'],2)-$total)>.005))throw new InvalidArgumentException('Payment total must equal all expense rows.');
    foreach((array)($store['generalExpenses']??[]) as $previous)if(($previous['entity']??'')===$entity&&!in_array(($previous['status']??''),['Deleted','Amended'],true)&&$reference!==''&&strcasecmp((string)($previous['reference']??''),$reference)===0&&strcasecmp((string)($previous['payee']??''),$payee)===0)throw new InvalidArgumentException('This expense reference is already recorded for the recipient.');
    $credit=ev1_pay_line($store,$entity,$paymentId,$total,$names);dex_check_cheque($store,$entity,$paymentId,$tracking);
    $recipientId=$profile['id']??'EXP|'.sac_normal($payee);if(!isset($store['paymentPayees'][$entity.'|'.$recipientId]))$store['paymentPayees'][$entity.'|'.$recipientId]=['id'=>$recipientId,'entity'=>$entity,'kind'=>'EXPENSE','name'=>$payee,'active'=>true,'configured'=>true,'accountCode'=>'6900','subaccountId'=>'','expenseCategory'=>'OFFICE'];
    $id=ev1_id((array)($store['generalExpenses']??[]),'GEX');$meta=['generalExpenseId'=>$id,'directExpense'=>true,'payee'=>$payee,'payeeId'=>$recipientId,'paymentAccountId'=>$paymentId,'expenseLines'=>$rows];$lines=[];
    foreach($rows as $row)$lines[]=ev1_line($row['account'],$row['amount'],0,$names,array_intersect_key($row,array_flip(['subaccountId','subaccountName','parentAccount','taxCategory','accountClass','expenseClassification','subledger','assetId','assetName','registrationNo','periodFrom','periodTo','challanReference']))+['counterparty'=>$payee,'expenseRecipientId'=>$recipientId,'generalExpenseId'=>$id,'subledger'=>$row['purpose'],'expenseCategory'=>$row['category'],'expensePurpose'=>$row['purpose'],'medicalFor'=>$row['medicalFor'],'rentFor'=>$row['rentFor'],'donationType'=>$row['donationType'],'location'=>($row['expenseClassification']??'')==='MILL'||$row['category']==='MILL'||$row['rentFor']==='MILL'?'MILL':(($row['expenseClassification']??'')==='HOME'||$row['category']==='HOME'||$row['medicalFor']==='HOUSEHOLD'||$row['rentFor']==='HOME'?'HOME':'OFFICE')]);
    $lines[]=array_merge($credit,$tracking);$description=implode(' / ',array_column($rows,'purpose'));
    $jid=ev1_journal($store,$entity,$date,'DIRECT_EXPENSE_PAYMENT',$reference!==''?$reference:$id,$payee.' — '.$description,$lines,$user,$meta);
    $store['generalExpenses'][$id]=array_merge($tracking,['id'=>$id,'entity'=>$entity,'expenseType'=>'DIRECT','expenseLines'=>$rows,'expenseAccount'=>count($rows)===1?$rows[0]['account']:'','expenseAccountName'=>count($rows)===1?$rows[0]['accountName']:'Multiple expenses','paymentDate'=>$date,'amount'=>$total,'payeeId'=>$recipientId,'payee'=>$payee,'description'=>$description,'reference'=>$reference,'location'=>count($rows)===1?$rows[0]['category']:'MULTIPLE','paymentAccountId'=>$paymentId,'journalId'=>$jid,'status'=>'Posted','createdAt'=>gmdate('c'),'createdBy'=>$user['full_name']??$user['username']??'Accounts']);
    return ['generalExpenseId'=>$id,'journalId'=>$jid,'amount'=>$total];
}
function dex_amend(array &$store,string $entity,array $body,array $user,array $names,array $tracking): array {
    $id=trim((string)($body['id']??''));$old=$store['generalExpenses'][$id]??null;
    if(!is_array($old)||($old['entity']??'')!==$entity||($old['expenseType']??'')!=='DIRECT'||($old['status']??'Posted')!=='Posted')throw new InvalidArgumentException('Select a current expense payment in this company.');
    $reason=trim((string)($body['reason']??''));if(strlen($reason)<5||strlen($reason)>500)throw new InvalidArgumentException('Enter a correction reason of 5 to 500 characters.');
    $reversals=ev1_reverse_journals($store,[$old['journalId']],$entity,$user,$names,$reason);$store['generalExpenses'][$id]['status']='Amended';
    $result=dex_post($store,$entity,$body,$user,$names,$tracking);$newId=$result['generalExpenseId'];$newJournal=$result['journalId'];
    $store['generalExpenses'][$id]['replacementExpenseId']=$newId;$store['generalExpenses'][$newId]['amendmentOf']=$id;
    $store['journals'][$newJournal]['meta']['amendmentOf']=$old['journalId'];$publicId=tt_accounts_public_post($store,$store['journals'][$old['journalId']]);$store['journals'][$newJournal]['meta']['publicPostId']=$publicId;$changes=[];foreach(['paymentDate'=>'Date','payee'=>'Recipient','amount'=>'Amount','description'=>'Purpose','reference'=>'Reference'] as $field=>$label)if(($old[$field]??'')!==($store['generalExpenses'][$newId][$field]??''))$changes[]=$label.': '.($old[$field]??'').' → '.($store['generalExpenses'][$newId][$field]??'');$store['journals'][$newJournal]['meta']['amendmentNote']='Edited '.gmdate('Y-m-d').' by '.($user['full_name']??$user['username']??'Accounts').' — '.implode('; ',$changes).' — '.$reason;$store['generalExpenses'][$newId]['publicPostId']=$publicId;$result['publicPostId']=$publicId;$store['journals'][$old['journalId']]['amendedByPostId']=$newJournal;
    foreach($reversals as $jid){$store['journals'][$jid]['sourceType']='DIRECT_EXPENSE_REVERSAL';$store['journals'][$jid]['narration']='Correction of '.$old['journalId'].' — '.$reason;}
    $store['expenseAudit'][]=['action'=>'AMEND_DIRECT_EXPENSE','entity'=>$entity,'id'=>$id,'replacementExpenseId'=>$newId,'before'=>$old,'reason'=>$reason,'reversalJournalIds'=>$reversals,'at'=>gmdate('c'),'by'=>$user['username']??''];
    return $result+['originalExpenseId'=>$id,'reversalJournalIds'=>$reversals];
}

