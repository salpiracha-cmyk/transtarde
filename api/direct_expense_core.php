<?php
declare(strict_types=1);
require_once __DIR__.'/accounts_subaccounts_core.php';
require_once __DIR__.'/accounts_reference.php';

/** Capture a supplier obligation once; payments use the existing settlement engine. */
function eiv_capture(array &$s,string $entity,array $b,array $u,array $names,bool $utility=false): array {
    if(!empty($b['paymentAccountId'])||!empty($b['bankPaymentMethod']))throw new InvalidArgumentException('Record the full invoice first; make payments through Party Payments.');
    if(!in_array($entity,['TTI','BRM'],true))throw new InvalidArgumentException('Use the dedicated native-currency supplier bill workflow for TG.');
    $id=trim((string)($b['invoiceId']??''));$old=$id!==''?($s['supplierBills'][$id]??null):null;
    if($id!==''&&(!is_array($old)||($old['entity']??'')!==$entity||empty($old['expenseInvoice'])||($old['status']??'')!=='Posted'))throw new InvalidArgumentException('Select a current expense invoice in this company.');
    if($old){
        $reason=trim((string)($b['reason']??''));if(strlen($reason)<5||strlen($reason)>500)throw new InvalidArgumentException('Enter a correction reason of 5 to 500 characters.');
        if(($b['expectedJournalId']??'')!==$old['journalId'])throw new InvalidArgumentException('The invoice changed. Reopen it before correcting.');
        if(eiv_balance($s,$old)['allocated']>.005)throw new InvalidArgumentException('This invoice has linked payments. Reverse those payments through their original workflow before correcting the invoice.');
        if(($old['invoiceKind']??'GENERAL')!==($utility?'UTILITY':'GENERAL'))throw new InvalidArgumentException('Keep the original invoice type.');
    }
    $date=ev1_date((string)($b['invoiceDate']??''),'Invoice date');$due=ev1_date((string)($b['dueDate']??$date),'Due date');
    if($due<$date)throw new InvalidArgumentException('Due date cannot precede the invoice date.');
    $number=trim((string)($b['invoiceNo']??''));$payee=trim((string)($b['payee']??''));
    if($number===''||strlen($number)>180||$payee===''||strlen($payee)>180)throw new InvalidArgumentException('Enter the supplier and original invoice number (up to 180 characters each).');
    $profile=null;foreach(sac_expense_recipients($s,$entity) as $p)if(sac_normal($p['name'])===sac_normal($payee)){$profile=$p;$payee=$p['name'];break;}
    if(!empty($b['payeeId'])&&(!$profile||$profile['id']!==$b['payeeId']))throw new InvalidArgumentException('Choose the current supplier identity.');
    $notes=trim((string)($b['notes']??''));if(strlen($notes)>1000)throw new InvalidArgumentException('Notes must be no longer than 1000 characters.');
    $extra=['notes'=>$notes];
    if($utility){
        $type=ev1_utility_type((string)($b['utilityType']??''));$owned=ev1_bill_location($b,$entity);$month=ev1_month((string)($b['billMonth']??''));$master=trim((string)($b['utilityMasterId']??''));
        ev1_validate_utility_provider($payee);
        if($master!==''){$m=$s['utilityMasters'][$master]??null;if(!$m||($m['entity']??'')!==$entity||($m['status']??'Active')!=='Active'||($m['utilityType']??'')!==$type||strcasecmp(trim((string)$m['payee']),$payee)!==0||(!empty($m['locationId'])?$m['locationId']!==$owned['id']:tt_location_identity((string)$m['locationName'])!==tt_location_identity($owned['name'])))throw new InvalidArgumentException('The invoice must match the saved utility provider and company location.');}
        $treatment=(string)($b['accountingTreatment']??'BUSINESS_EXPENSE');if(!in_array($treatment,['BUSINESS_EXPENSE','FAMILY_ALLOCATION'],true))throw new InvalidArgumentException('Choose the utility accounting treatment.');
        $production=$owned['location']==='MILL'&&$treatment==='BUSINESS_EXPENSE';$account=$treatment==='FAMILY_ALLOCATION'?ev1_person((string)($b['personAccount']??'')):($production?'5200':ev1_utility_account($type));
        $amount=ev1_amount($b['amount']??0);$personal=pex_fields(array_replace($b,['expenseArea'=>$owned['location']]));
        $extra=$extra+$personal+ev1_reading_period($b,$owned['location']==='MILL'&&$type==='ELECTRICITY')+['accountReference'=>$master!==''?(string)($m['accountReference']??''):trim((string)($b['accountReference']??'')),'utilityMasterId'=>$master,'utilityType'=>$type,'utilityTypeName'=>ev1_utility_name($type),'locationId'=>$owned['id'],'location'=>$owned['location'],'locationName'=>$owned['name'],'accountingTreatment'=>$treatment,'productionCostEligible'=>$production];
        if($production&&$type==='ELECTRICITY')foreach((array)($s['millLiabilities']??[]) as $existing){$snapshot=$existing['sourceSnapshot']??[];if(($existing['entity']??'')===$entity&&($existing['sourceCollection']??'')==='tt38kebills'&&($existing['status']??'')==='Posted'&&strcasecmp(trim((string)($snapshot['ref']??$snapshot['reference']??'')),$number)===0&&tt_location_identity((string)($snapshot['millName']??''))===tt_location_identity($owned['name']))throw new InvalidArgumentException('This mill bill already has a posted liability. Pay or correct its original mill bill instead of recording another invoice.');}
        if(strlen($extra['accountReference'])>180)throw new InvalidArgumentException('Consumer / account reference must be no longer than 180 characters.');
        $rows=[$personal+['amount'=>$amount,'account'=>$account,'accountName'=>$names[$account],'purpose'=>ev1_utility_name($type),'category'=>'UTILITY']];
    }else{
        [$rows,$amount]=dex_rows($b['expenseLines']??null,$names,$s,$entity);foreach($rows as $row)if(in_array($row['account'],['7200','7210','7220','7230'],true))throw new InvalidArgumentException('Record donations through their dedicated workflow, not a supplier invoice.');$month=ev1_month((string)($b['billMonth']??substr($date,0,7)));
    }
    if(isset($b['amount'])&&(!is_numeric($b['amount'])||!is_finite((float)$b['amount'])||abs(round((float)$b['amount'],2)-$amount)>.005))throw new InvalidArgumentException('Invoice total must equal its expense rows.');
    foreach(['supplierBills','generalExpenses','utilityPayments','otherPurchases'] as $collection)foreach((array)($s[$collection]??[]) as $key=>$record){
        if(($record['entity']??'')!==$entity||($collection==='supplierBills'&&(string)$key===$id)||in_array($record['status']??'',['Cancelled','Deleted','Amended'],true))continue;
        $sameParty=sac_normal((string)($record['vendor']??$record['supplier']??$record['payee']??''))===sac_normal($payee);
        $sameNumber=strcasecmp(trim((string)($record['billNo']??$record['invoiceNo']??$record['reference']??'')),$number)===0;
        $sameUtility=$utility&&!empty($extra['utilityMasterId'])&&($record['utilityMasterId']??'')===$extra['utilityMasterId']&&($record['billMonth']??'')===$month;
        if(($sameParty&&$sameNumber)||$sameUtility)throw new InvalidArgumentException('This supplier invoice or utility month is already recorded. Open the existing source instead of recording its expense twice.');
    }
    if($id==='')$id=ev1_id((array)($s['supplierBills']??[]),'EINV');
    if($old){$reason=trim((string)($b['reason']??''));pex_clear($s,$entity,'EXPENSE-INVOICE|'.$id,$u,$names,$reason);$reversed=ev1_reverse_journals($s,[$old['journalId']],$entity,$u,$names,$reason);foreach($reversed as $rid)$s['journals'][$rid]['date']=$s['journals'][$old['journalId']]['date'];}
    $recipient=$profile['id']??'EXP|'.sac_normal($payee);$meta=$extra+['expenseInvoiceId'=>$id,'supplierBillId'=>$id,'payee'=>$payee,'party'=>$payee,'payeeId'=>$recipient,'billMonth'=>$month,'invoiceNo'=>$number,'expenseLines'=>$rows];$lines=[];
    foreach($rows as $row){$fields=$row;unset($fields['account'],$fields['accountName'],$fields['amount']);$lines[]=ev1_line($row['account'],$row['amount'],0,$names,$fields+$extra+['counterparty'=>$payee,'expenseRecipientId'=>$recipient,'expenseInvoiceId'=>$id,'expensePurpose'=>$row['purpose']??'','subledger'=>$row['purpose']??'','expenseCategory'=>$row['category']??'OTHER']);}
    $lines[]=ev1_line('2130',0,$amount,$names,['counterparty'=>$payee,'supplierBillId'=>$id,'expenseInvoiceId'=>$id]);
    $jid=ev1_journal($s,$entity,$date,$utility?'UTILITY_INVOICE':'EXPENSE_INVOICE',$number,'Supplier invoice — '.$payee.' — '.$number,$lines,$u,$meta);
    $personal=pex_apply($s,$entity,'EXPENSE-INVOICE|'.$id,$rows,$date,$jid,$u,$names);
    $s['supplierBills'][$id]=$extra+['id'=>$id,'entity'=>$entity,'expenseInvoice'=>true,'invoiceKind'=>$utility?'UTILITY':'GENERAL','category'=>$utility?'UTILITIES':'SERVICE','payeeId'=>$recipient,'vendor'=>$payee,'broker'=>$payee,'billNo'=>$number,'invoiceNo'=>$number,'reference'=>$number,'billDate'=>$date,'dueDate'=>$due,'billMonth'=>$month,'amount'=>$amount,'supplierPayableTotal'=>$amount,'payableAccount'=>'2130','receiptAllocations'=>[['sourceKey'=>'EXPENSE|'.$id,'supplierPayableShare'=>$amount]],'expenseLines'=>$rows,'personalLink'=>'EXPENSE-INVOICE|'.$id,'personalJournalIds'=>$personal['journalIds'],'journalId'=>$jid,'postingJournalIds'=>array_merge((array)($old['postingJournalIds']??[]),[$jid]),'status'=>'Posted','createdAt'=>$old['createdAt']??gmdate('c'),'createdBy'=>$old['createdBy']??($u['full_name']??$u['username']??''),'updatedAt'=>gmdate('c')];
    if($old){$public=tt_accounts_public_post($s,$s['journals'][$old['journalId']]);$s['journals'][$old['journalId']]['amendedByPostId']=$jid;$s['journals'][$jid]['meta']['publicPostId']=$public;$s['journals'][$jid]['meta']['amendmentOfPostId']=$old['journalId'];$s['journals'][$jid]['meta']['amendmentNote']='Invoice corrected — '.trim((string)$b['reason']);foreach($personal['journalIds'] as $pid)$s['journals'][$pid]['meta']['publicPostId']=$public;if(isset($s['expensePersonalLinks']['EXPENSE-INVOICE|'.$id]))$s['expensePersonalLinks']['EXPENSE-INVOICE|'.$id]['publicPostId']=$public;$s['expenseAudit'][]=['action'=>'AMEND_EXPENSE_INVOICE','entity'=>$entity,'id'=>$id,'before'=>$old,'reason'=>$b['reason'],'at'=>gmdate('c'),'by'=>$u['username']??''];}
    return ['invoiceId'=>$id,'journalId'=>$jid,'amount'=>$amount];
}
function eiv_cancel(array &$s,string $entity,array $b,array $u,array $names): array {
    $id=(string)($b['invoiceId']??'');$old=$s['supplierBills'][$id]??null;
    if(!$old||($old['entity']??'')!==$entity||empty($old['expenseInvoice'])||($old['status']??'')!=='Posted'||($b['expectedJournalId']??'')!==$old['journalId'])throw new InvalidArgumentException('This invoice changed. Reload its current source.');
    if(eiv_balance($s,$old)['allocated']>.005)throw new InvalidArgumentException('Reverse linked payments through their original workflow before cancelling this invoice.');
    $reason=trim((string)($b['reason']??''));if(strlen($reason)<5||strlen($reason)>500)throw new InvalidArgumentException('Enter a cancellation reason of 5 to 500 characters.');pex_clear($s,$entity,'EXPENSE-INVOICE|'.$id,$u,$names,$reason);$ids=ev1_reverse_journals($s,[$old['journalId']],$entity,$u,$names,$reason);
    foreach($ids as $rid)if($s['journals'][$rid]['date']<$old['billDate'])$s['journals'][$rid]['date']=$old['billDate'];
    $s['supplierBills'][$id]['status']='Cancelled';$s['supplierBills'][$id]['cancellationReason']=$reason;$s['expenseAudit'][]=['action'=>'CANCEL_EXPENSE_INVOICE','entity'=>$entity,'id'=>$id,'before'=>$old,'reason'=>$reason,'at'=>gmdate('c'),'by'=>$u['username']??''];
    return ['invoiceId'=>$id,'journalId'=>$ids[0],'status'=>'Cancelled'];
}

/** One recipient and one payment source; every expense has its own debit leg. */
function dex_rows(mixed $input,array $names,array $store=[],string $entity='TTI'): array {
    if(!is_array($input)||count($input)<1||count($input)>50)throw new InvalidArgumentException('Add between one and 50 expense rows.');
    $rows=[];$total=0.0;
    foreach($input as $row){
        if(!is_array($row))throw new InvalidArgumentException('An expense row is invalid.');
        $category=strtoupper(trim((string)($row['category']??'')));$purpose=trim((string)($row['purpose']??''));
        $value=$row['amount']??null;if(!is_numeric($value)||!is_finite((float)$value)||(float)$value<=0||(float)$value>100000000000)throw new InvalidArgumentException('Enter a positive amount for each expense.');
        $amount=round((float)$value,2);if($amount<=0)throw new InvalidArgumentException('Each expense must be at least 0.01.');
        if(strlen($purpose)>500)throw new InvalidArgumentException('Purpose must be no longer than 500 characters.');
        $detail='';$account=match($category){'HOME','ARP'=>'6910','OFFICE','OTHER'=>'6900','EXPORT'=>'5550','MILL'=>'5200','MEDICAL'=>'6230','RENT'=>'6300','DONATION'=>'7200','CAR_REPAIRS'=>'6410','FUEL'=>'6610','VEHICLE_TAX'=>'6620','REPAIRS'=>'6400','TRAVEL'=>'6500','PROFESSIONAL'=>'6700',default=>throw new InvalidArgumentException('Choose Home, Office, Mill, Medical, Rent, Donation or Other.')};
        if($category==='MEDICAL'){$detail=strtoupper((string)($row['medicalFor']??''));if(!in_array($detail,['HOUSEHOLD','COMPANY_STAFF'],true))throw new InvalidArgumentException('Choose household or company/staff for medical expenses.');if($detail==='HOUSEHOLD')$account='6910';}
        if($category==='RENT'){$detail=strtoupper((string)($row['rentFor']??''));if(!in_array($detail,['HOME','OFFICE','MILL','EXPORT'],true))throw new InvalidArgumentException('Choose Home, Office or Mill for rent.');if($detail==='HOME')$account='6910';elseif($detail==='MILL')$account='5200';}
        if($category==='DONATION'){$detail=strtoupper((string)($row['donationType']??''));$account=match($detail){'ZAKAT'=>'7210','SADQA'=>'7220','FI_SABILILLAH'=>'7230','OTHER'=>'7200',default=>throw new InvalidArgumentException('Choose Zakat, Sadqa or Fi Sabilillah.')};}
        if(!empty($row['accountCode'])){
            $selected=(string)$row['accountCode'];$head=sac_entity_chart($store,$entity)[$selected]??null;
            if(!$head||in_array($head['level']??'',['heading','system'],true)||($head['class']!=='Expense'&&!str_starts_with($selected,'FAM-')&&$selected!=='3200'))throw new InvalidArgumentException('Choose an expense, manufacturing cost or family allocation account.');
            $account=$selected;if($category==='DONATION'&&$selected==='7200')$account=match($detail){'ZAKAT'=>'7210','SADQA'=>'7220','FI_SABILILLAH'=>'7230','OTHER'=>'7200'};if(($category==='MEDICAL'&&$detail==='HOUSEHOLD'&&$selected==='6230')||($category==='RENT'&&$detail==='HOME'&&$selected==='6300'))$account='6910';if($category==='RENT'&&$detail==='MILL'&&$selected==='6300')$account='5200';
        }
        $sub=null;$subextra=[];if(!empty($row['subaccountId'])){$sub=sac_resolve($store,$entity,(string)$row['subaccountId']);if($sub['class']!=='Expense'&&!str_starts_with($sub['parentCode'],'FAM-')&&$sub['parentCode']!=='3200')throw new InvalidArgumentException('Choose an expense, manufacturing cost or family allocation subaccount.');if(!empty($row['accountCode'])&&(string)$row['accountCode']!==$sub['parentCode'])throw new InvalidArgumentException('The subaccount has moved. Reopen Expenses and choose its current head.');$account=$sub['parentCode'];$subextra=sac_extra($sub);}
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
        if($category==='ARP'||(!empty($sub)&&($sub['builtinCategory']??'')==='ARP')||(!empty($sub)&&in_array($entity.'-EXPENSE-TYPE-ARP',$sub['ancestorIds']??[],true)))$row=array_replace($row,['expenseFor'=>'FAM-ABU','expenseArea'=>'HOME']);
        if($sub!==null&&isset($sub['homePerson']))$row=array_replace($row,['expenseFor'=>$sub['homePerson'],'expenseArea'=>'HOME']);
        $personal=pex_fields($row);if($personal['expenseArea']==='MILL'&&$category!=='MILL')throw new InvalidArgumentException('Post Milling–Production costs through their dedicated workflow.');
        $rows[]=$personal+$taxExtra+$assetExtra+$subextra+['category'=>$category,'purpose'=>$purpose,'amount'=>$amount,'account'=>$account,'accountName'=>$names[$account],'medicalFor'=>$category==='MEDICAL'?$detail:'','rentFor'=>$category==='RENT'?$detail:'','donationType'=>$category==='DONATION'?$detail:''];$total=round($total+$amount,2);
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
    $narration=trim((string)($body['paymentNarration']??''));if(strlen($narration)>900)throw new InvalidArgumentException('Narration is too long.');$tracking=array_replace(['paymentNarration'=>$narration],$tracking);
    $payee=trim((string)($body['payee']??''));if($payee===''||strlen($payee)>180)throw new InvalidArgumentException('Enter who receives this payment.');
    $profile=null;foreach(sac_expense_recipients($store,$entity) as $candidate)if(sac_normal($candidate['name'])===sac_normal($payee)){$profile=$candidate;break;}
    if($profile)$payee=$profile['name'];if(!empty($body['payeeId'])&&(!$profile||$profile['id']!==(string)$body['payeeId']))throw new InvalidArgumentException('Choose the current expense recipient.');
    $date=ev1_date((string)($body['paymentDate']??''),'Payment date');$reference=trim((string)($body['reference']??''));if(strlen($reference)>180)throw new InvalidArgumentException('Reference is too long.');
    [$rows,$total]=dex_rows($body['expenseLines']??null,$names,$store,$entity);$paymentId=trim((string)($body['paymentAccountId']??''));
    if(isset($body['amount'])&&(!is_numeric($body['amount'])||abs(round((float)$body['amount'],2)-$total)>.005))throw new InvalidArgumentException('Payment total must equal all expense rows.');
    foreach((array)($store['generalExpenses']??[]) as $previous)if(($previous['entity']??'')===$entity&&!in_array(($previous['status']??''),['Deleted','Amended'],true)&&$reference!==''&&strcasecmp((string)($previous['reference']??''),$reference)===0&&strcasecmp((string)($previous['payee']??''),$payee)===0)throw new InvalidArgumentException('This expense reference is already recorded for the recipient.');
    eiv_assert_not_captured($store,$entity,$payee,$reference);
    $credit=ev1_card_charge_credit($store,$entity,$paymentId,$total,$date,$names);dex_check_cheque($store,$entity,$paymentId,$tracking);
    $recipientId=$profile['id']??'EXP|'.sac_normal($payee);if(empty($profile['serviceProvider'])&&!isset($store['paymentPayees'][$entity.'|'.$recipientId]))$store['paymentPayees'][$entity.'|'.$recipientId]=['id'=>$recipientId,'entity'=>$entity,'kind'=>'EXPENSE','name'=>$payee,'active'=>true,'configured'=>true,'accountCode'=>'6900','subaccountId'=>'','expenseCategory'=>'OFFICE'];
    $id=ev1_id((array)($store['generalExpenses']??[]),'GEX');$meta=['generalExpenseId'=>$id,'directExpense'=>true,'payee'=>$payee,'payeeId'=>$recipientId,'paymentAccountId'=>$paymentId,'expenseLines'=>$rows,'paymentNarration'=>$narration];$lines=[];
    foreach($rows as $row)$lines[]=ev1_line($row['account'],$row['amount'],0,$names,array_intersect_key($row,array_flip(['subaccountId','subaccountName','parentAccount','taxCategory','accountClass','expenseClassification','subledger','assetId','assetName','registrationNo','periodFrom','periodTo','challanReference','expenseFor','expenseForName','personalTreatment','expenseArea']))+['counterparty'=>$payee,'expenseRecipientId'=>$recipientId,'generalExpenseId'=>$id,'subledger'=>$row['purpose'],'expenseCategory'=>$row['category'],'expensePurpose'=>$row['purpose'],'medicalFor'=>$row['medicalFor'],'rentFor'=>$row['rentFor'],'donationType'=>$row['donationType'],'location'=>$row['expenseArea']?: (($row['expenseClassification']??'')==='MILL'||$row['category']==='MILL'||$row['rentFor']==='MILL'?'MILL':(($row['expenseClassification']??'')==='HOME'||$row['category']==='HOME'||$row['medicalFor']==='HOUSEHOLD'||$row['rentFor']==='HOME'?'HOME':'OFFICE'))]);
    $lines[]=array_merge($credit,$tracking);$description=implode(' / ',array_filter(array_column($rows,'purpose'),static fn($purpose)=>$purpose!==''));
    $jid=ev1_journal($store,$entity,$date,'DIRECT_EXPENSE_PAYMENT',$reference!==''?$reference:$id,trim($payee.($description!==''?' — '.$description:'')),$lines,$user,$meta);
    $personalLink=pex_apply($store,$entity,'EXPENSE|'.$id,$rows,$date,$jid,$user,$names);
    $store['generalExpenses'][$id]=array_merge($tracking,['id'=>$id,'entity'=>$entity,'expenseType'=>'DIRECT','personalLink'=>'EXPENSE|'.$id,'personalJournalIds'=>$personalLink['journalIds'],'expenseLines'=>$rows,'expenseAccount'=>count($rows)===1?$rows[0]['account']:'','expenseAccountName'=>count($rows)===1?$rows[0]['accountName']:'Multiple expenses','paymentDate'=>$date,'amount'=>$total,'payeeId'=>$recipientId,'payee'=>$payee,'description'=>$description,'reference'=>$reference,'location'=>count($rows)===1?$rows[0]['category']:'MULTIPLE','paymentAccountId'=>$paymentId,'journalId'=>$jid,'status'=>'Posted','createdAt'=>gmdate('c'),'createdBy'=>$user['full_name']??$user['username']??'Accounts']);
    return ['generalExpenseId'=>$id,'journalId'=>$jid,'amount'=>$total];
}
function dex_amend(array &$store,string $entity,array $body,array $user,array $names,array $tracking): array {
    $id=trim((string)($body['id']??''));$old=$store['generalExpenses'][$id]??null;
    if(!is_array($old)||($old['entity']??'')!==$entity||($old['expenseType']??'')!=='DIRECT'||($old['status']??'Posted')!=='Posted')throw new InvalidArgumentException('Select a current expense payment in this company.');
    if(!empty($old['cardStatementId']))throw new InvalidArgumentException('Correct the linked card bill before changing an included expense.');
    $reason=trim((string)($body['reason']??''));if(strlen($reason)<5||strlen($reason)>500)throw new InvalidArgumentException('Enter a correction reason of 5 to 500 characters.');
    pex_clear($store,$entity,'EXPENSE|'.$id,$user,$names,$reason);
    $reversals=ev1_reverse_journals($store,[$old['journalId']],$entity,$user,$names,$reason);$store['generalExpenses'][$id]['status']='Amended';
    $result=dex_post($store,$entity,$body,$user,$names,$tracking);$newId=$result['generalExpenseId'];$newJournal=$result['journalId'];
    $store['generalExpenses'][$id]['replacementExpenseId']=$newId;$store['generalExpenses'][$newId]['amendmentOf']=$id;
    $store['journals'][$newJournal]['meta']['amendmentOf']=$old['journalId'];$publicId=tt_accounts_public_post($store,$store['journals'][$old['journalId']]);$store['journals'][$newJournal]['meta']['publicPostId']=$publicId;$changes=[];foreach(['paymentDate'=>'Date','payee'=>'Recipient','amount'=>'Amount','description'=>'Purpose','reference'=>'Reference'] as $field=>$label)if(($old[$field]??'')!==($store['generalExpenses'][$newId][$field]??''))$changes[]=$label.': '.($old[$field]??'').' → '.($store['generalExpenses'][$newId][$field]??'');$store['journals'][$newJournal]['meta']['amendmentNote']='Edited '.gmdate('Y-m-d').' by '.($user['full_name']??$user['username']??'Accounts').' — '.implode('; ',$changes).' — '.$reason;$store['generalExpenses'][$newId]['publicPostId']=$publicId;$result['publicPostId']=$publicId;if(isset($store['expensePersonalLinks']['EXPENSE|'.$newId]))$store['expensePersonalLinks']['EXPENSE|'.$newId]['publicPostId']=$publicId;foreach((array)($store['expensePersonalLinks']['EXPENSE|'.$newId]['journalIds']??[]) as $personalJournal)$store['journals'][$personalJournal]['meta']['publicPostId']=$publicId;$store['journals'][$old['journalId']]['amendedByPostId']=$newJournal;
    // An amendment replaces the original at its posting date, including historical cutoffs.
    $originalDate=(string)$store['journals'][$old['journalId']]['date'];
    foreach($reversals as $jid){$store['journals'][$jid]['date']=$originalDate;$store['journals'][$jid]['sourceType']='DIRECT_EXPENSE_REVERSAL';$store['journals'][$jid]['narration']='Correction of '.$old['journalId'].' — '.$reason;}
    $store['expenseAudit'][]=['action'=>'AMEND_DIRECT_EXPENSE','entity'=>$entity,'id'=>$id,'replacementExpenseId'=>$newId,'before'=>$old,'reason'=>$reason,'reversalJournalIds'=>$reversals,'at'=>gmdate('c'),'by'=>$user['username']??''];
    return $result+['originalExpenseId'=>$id,'reversalJournalIds'=>$reversals];
}


// Recipient and beneficiary are distinct. Personal recovery journals never change a card or bank payment.
function pex_people(): array { return ['FAM-SALMAN'=>'Salman','FAM-TALHA'=>'Talha','FAM-TAYYAB'=>'Tayyab','FAM-ABU'=>'ARP']; }
function pex_fields(array $row): array {
    $person=strtoupper(trim((string)($row['expenseFor']??'SHARED')));
    if($person!=='SHARED'&&!isset(pex_people()[$person]))throw new InvalidArgumentException('Select Salman, Talha, Tayyab, ARP or Shared–Common.');
    $treatment=strtoupper(trim((string)($row['personalTreatment']??'COMPANY')));
    if(!in_array($treatment,['COMPANY','REMUNERATION','CASH'],true)||($person==='SHARED'&&$treatment!=='COMPANY'))throw new InvalidArgumentException('Choose a person before selecting personal recovery.');
    $area=strtoupper(trim((string)($row['expenseArea']??'')));
    if($area!==''&&!in_array($area,['HOME','OFFICE','MILL','EXPORT'],true))throw new InvalidArgumentException('Choose Home, Office or Milling–Production.');
    return ['expenseFor'=>$person,'expenseForName'=>pex_people()[$person]??'Shared–Common','personalTreatment'=>$treatment,'expenseArea'=>$area];
}
function pex_master(array $s,string $entity,string $person): ?array {
    $aliases=match($person){'FAM-SALMAN'=>['salman','salmanparacha'],'FAM-TALHA'=>['talha','talhaparacha'],'FAM-TAYYAB'=>['tayyab','tayyabparacha'],'FAM-ABU'=>['arp','abu','abdulrazzak','abdulrazzakparacha'],default=>[]};
    $found=[];foreach((array)($s['salaryMasters']??sm_seed_masters()) as $id=>$m)if(($m['entity']??'')===$entity&&($m['category']??'')==='DIRECTOR_REMUNERATION'&&($m['status']??'Active')==='Active'&&in_array(sac_normal((string)$m['name']),$aliases,true))$found[]=array_merge($m,['id'=>(string)($m['id']??$id)]);
    if(count($found)>1)throw new InvalidArgumentException('Duplicate remuneration masters for '.pex_people()[$person].'. Resolve them in Salary Master.');
    return $found[0]??null;
}
function pex_clear(array &$s,string $entity,string $key,array $u,array $names,string $reason): void {
    $record=$s['expensePersonalLinks'][$key]??null;if(!$record)return;
    if(($record['entity']??'')!==$entity)throw new DomainException('Personal adjustment belongs to another company.');
    foreach((array)$record['rows'] as $row){
        if((float)($row['cashRecovered']??0)>.005)throw new InvalidArgumentException('Cash has already been recovered for this expense. Correct the recovery before changing its allocation.');
        $advance=$s['salaryAdvances'][$row['advanceId']??'']??null;
        foreach((array)($advance['adjustments']??[]) as $adjustment){$amount=round((float)$adjustment['amount'],2);if($amount<=0)continue;$pid=$entity.'|'.$adjustment['month'].'|'.$advance['masterId'];$period=$s['salaryPeriods'][$pid]??null;if(!$period)throw new DomainException('The linked remuneration period needs review.');$meta=['party'=>$row['expenseForName'],'person'=>$row['expenseForName'],'salaryMasterId'=>$advance['masterId'],'month'=>$adjustment['month'],'personalLink'=>$key];ev1_journal($s,$entity,(new DateTimeImmutable('now',new DateTimeZone('Asia/Karachi')))->format('Y-m-d'),'PERSONAL_DEDUCTION_CORRECTION',$key,'Restore remuneration deduction — '.$reason,[ev1_line('1230',$amount,0,$names,$meta),ev1_line('2140',0,$amount,$names,$meta)],$u,$meta);$s['salaryPeriods'][$pid]['advanceApplied']=round($period['advanceApplied']-$amount,2);$s['salaryPeriods'][$pid]['outstanding']=round($period['outstanding']+$amount,2);$s['salaryPeriods'][$pid]['status']='Payable';pex_period_summary($s,$pid);}
        if($advance)unset($s['salaryAdvances'][$row['advanceId']]);
        foreach((array)($row['periods']??[]) as $deduction){$pid=$deduction['id'];$s['salaryPeriods'][$pid]['personalDeducted']=round((float)($s['salaryPeriods'][$pid]['personalDeducted']??0)-$deduction['amount'],2);$s['salaryPeriods'][$pid]['outstanding']=round((float)$s['salaryPeriods'][$pid]['outstanding']+$deduction['amount'],2);$s['salaryPeriods'][$pid]['status']='Payable';pex_period_summary($s,$pid);}
    }
    if(!empty($record['journalIds']))ev1_reverse_journals($s,$record['journalIds'],$entity,$u,$names,$reason);
    $s['expenseAudit'][]=['action'=>'REPLACE_PERSONAL_ADJUSTMENTS','entity'=>$entity,'key'=>$key,'before'=>$record,'reason'=>$reason,'at'=>gmdate('c'),'by'=>$u['username']??''];unset($s['expensePersonalLinks'][$key]);
}
function pex_apply(array &$s,string $entity,string $key,array $rows,string $date,string $postId,array $u,array $names): array {
    $saved=[];$journalIds=[];
    foreach($rows as $i=>$raw){$row=pex_fields($raw)+$raw;if($row['personalTreatment']==='COMPANY')continue;$amount=ev1_amount($row['amount'],'Personal amount');$person=$row['expenseFor'];$master=pex_master($s,$entity,$person);if($row['personalTreatment']==='REMUNERATION'&&!$master)throw new InvalidArgumentException('Add or correct the remuneration master for '.$row['expenseForName'].' in this company before choosing a deduction.');
        $meta=['personalLink'=>$key,'expenseFor'=>$person,'expenseForName'=>$row['expenseForName'],'party'=>$row['expenseForName'],'person'=>$row['expenseForName'],'salaryMasterId'=>$master['id']??'','personalTreatment'=>$row['personalTreatment'],'expenseArea'=>$row['expenseArea']?:'HOME','personalRecovery'=>true];$lines=[ev1_line('1230',$amount,0,$names,$meta),ev1_line((string)$row['account'],0,$amount,$names,['personalRecovery'=>true,'personalLink'=>$key,'expenseFor'=>$person])];$left=$amount;$periods=[];
        // Personal spending recovers from the following salary month, never an older payable.
        $recoveryFromMonth=(new DateTimeImmutable($date))->modify('first day of next month')->format('Y-m');
        $jid=ev1_journal($s,$entity,$date,'PERSONAL_EXPENSE_ADJUSTMENT',$key,$row['expenseForName'].' — '.$row['personalTreatment'].' — '.($row['purpose']??'Card personal portion'),$lines,$u,['personalLink'=>$key,'publicPostId'=>$postId,'personalAdjustment'=>true]);$journalIds[]=$jid;$advanceId='';
        if($row['personalTreatment']==='REMUNERATION'&&$left>.005){$advanceId=ev1_id((array)($s['salaryAdvances']??[]),'SADV');$s['salaryAdvances'][$advanceId]=['id'=>$advanceId,'entity'=>$entity,'masterId'=>$master['id'],'person'=>$row['expenseForName'],'date'=>$date,'amount'=>$left,'remaining'=>$left,'months'=>1,'monthsRemaining'=>1,'journalId'=>$jid,'personalLink'=>$key,'recoveryFromMonth'=>$recoveryFromMonth,'adjustments'=>[]];}
        $saved[]=$row+['recoveryFromMonth'=>$recoveryFromMonth,'date'=>$date,'index'=>$i,'advanceId'=>$advanceId,'periods'=>$periods,'cashRecovered'=>0,'recoverable'=>$row['personalTreatment']==='CASH'?$amount:0,'journalId'=>$jid];
    }
    $record=['entity'=>$entity,'key'=>$key,'publicPostId'=>$postId,'rows'=>$saved,'journalIds'=>$journalIds,'updatedAt'=>gmdate('c')];if($saved)$s['expensePersonalLinks'][$key]=$record;return $record;
}
function pex_period_summary(array &$s,string $pid): void {
    $p=$s['salaryPeriods'][$pid]??null;if(!$p)return;$key=$p['entity'].'|'.$p['month'];$mid=$p['salaryMasterId'];
    if(($s['salarySheets'][$key]['status']??'')==='Completed'&&isset($s['salarySheets'][$key]['rows'][$mid])){$s['salarySheets'][$key]['rows'][$mid]['outstanding']=$p['outstanding'];$s['salarySheets'][$key]['rows'][$mid]['advanceApplied']=$p['advanceApplied'];$s['salarySheets'][$key]['rows'][$mid]['personalDeducted']=$p['personalDeducted']??0;$s['salarySheets'][$key]['rows'][$mid]['paidAfterPrepare']=round((float)($p['paidAfterPrepare']??0)+(float)($p['personalDeducted']??0),2);}
}
function pex_card_rows(mixed $input,float $total,string $account): array {
    if(!is_array($input)||count($input)>4)throw new InvalidArgumentException('Select up to four directors and their personal amounts.');$out=[];$seen=[];$sum=0;
    foreach($input as $row){if(!is_array($row))throw new InvalidArgumentException('Invalid personal amount.');$fields=pex_fields($row+['expenseArea'=>'HOME']);if($fields['expenseFor']==='SHARED'||$fields['personalTreatment']==='COMPANY'||isset($seen[$fields['expenseFor']]))throw new InvalidArgumentException('Select each director once and choose remuneration or cash recovery.');$seen[$fields['expenseFor']]=true;$amount=ev1_amount($row['amount']??0,'Personal amount');$sum=round($sum+$amount,2);$out[]=$fields+['amount'=>$amount,'account'=>$account,'purpose'=>'Credit-card personal portion'];}
    if($sum>$total+.005)throw new InvalidArgumentException('Personal amounts cannot exceed the card bill.');return $out;
}
