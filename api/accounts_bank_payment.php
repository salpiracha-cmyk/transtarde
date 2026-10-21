<?php
declare(strict_types=1);

/** Current payment-choice balances exclude future or malformed journal dates. */
function tt_accounts_balance_date(?string $asOf=null): string {
    $date=$asOf??(new DateTimeImmutable('now',new DateTimeZone('Asia/Karachi')))->format('Y-m-d');
    $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    if(!$parsed||$parsed->format('Y-m-d')!==$date)throw new InvalidArgumentException('Select a valid bank balance date.');
    return $date;
}
function tt_accounts_journal_as_of(array $journal,string $entity,string $date): bool {
    $day=(string)($journal['date']??'');$parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$day);
    return ($journal['status']??'')==='Posted'&&($journal['entity']??'')===$entity&&$parsed&&$parsed->format('Y-m-d')===$day&&$day<=$date;
}

function tt_accounts_bank_payment_details(array $input): array {
    if(str_starts_with((string)($input['paymentAccountId']??''),'CARD|'))return [];
    $method=trim((string)($input['bankPaymentMethod']??''));
    if($method==='BANK_TRANSFER')$method='ONLINE_BANKING';
    if($method==='')$method=!empty($input['paymentAccountId'])&&!str_starts_with((string)$input['paymentAccountId'],'CASH|')?'CHEQUE':'';
    if($method==='')return [];
    if(!in_array($method,['CHEQUE','ONLINE_BANKING','BANK_TRANSFER'],true))throw new DomainException('Select a valid bank payment method.');
    $source=trim((string)($input['paymentAccountId']??''));
    if($source===''||str_starts_with($source,'CASH|'))throw new DomainException('Choose the bank for this payment.');
    $reference=trim((string)($input['bankReference']??''));$narration=trim((string)($input['paymentNarration']??''));
    if(strlen($reference)>180||strlen($narration)>900)throw new DomainException('Payment reference or narration is too long.');
    $date=(string)($input['date']??$input['paymentDate']??$input['settlementDate']??'');
    $cheque=trim((string)($input['chequeNo']??''));
    $chequeDate=(string)($input['chequeDate']??$date);$parts=explode('-',$chequeDate);
    if($method==='CHEQUE'&&($cheque===''||strlen($cheque)>180||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$chequeDate)||count($parts)!==3||!checkdate((int)$parts[1],(int)$parts[2],(int)$parts[0])))throw new DomainException('Enter cheque number and a valid cheque date.');
    $bankPaymentDate=$method==='ONLINE_BANKING'?(string)($input['bankPaymentDate']??$date):'';
    if($method==='ONLINE_BANKING'){$parts=explode('-',$bankPaymentDate);if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$bankPaymentDate)||count($parts)!==3||!checkdate((int)$parts[1],(int)$parts[2],(int)$parts[0]))throw new DomainException('Enter a valid Online Banking payment date.');}
    $extra=[];if(!empty($input['addBankCharge'])){$fee=round((float)($input['extraBankChargeNative']??0),2);if(!is_finite($fee)||$fee<=0)throw new DomainException('Enter a bank charge greater than zero.');$extra=['addBankCharge'=>true,'extraBankChargeNative'=>$fee,'chargePaymentAccountId'=>$source];}
    return $extra+['bankPaymentMethod'=>$method,'bankReference'=>$method==='CHEQUE'?$cheque:$reference,'chequeNo'=>$method==='CHEQUE'?$cheque:'','chequeDate'=>$method==='CHEQUE'?$chequeDate:'','bankPaymentDate'=>$bankPaymentDate,'paymentNarration'=>$narration];
}

function tt_accounts_track_bank_payment(array &$store,array $originalJournalIds,array $tracking): void {
    if(!$tracking)return;
    $old=array_fill_keys($originalJournalIds,true);$chargeApplied=false;
    foreach($store['journals'] as $id=>&$journal){
        if(isset($old[$id]))continue;
        $bank=false;foreach((array)($journal['lines']??[]) as $line)if(($line['account']??'')==='1110'&&!empty($line['bankAccountId'])){$bank=true;break;}
        if(!$bank)continue;
        if(!empty($tracking['addBankCharge'])&&!$chargeApplied&&empty($journal['reversalOf'])&&($journal['entity']??'')==='TG'){$chargeApplied=tt_accounts_apply_aed_bank_charge($store,$journal,$tracking);}
        $journal['meta']=array_merge((array)($journal['meta']??[]),$tracking);
        $label=['CHEQUE'=>'Cheque','ONLINE_BANKING'=>'Online Banking','BANK_TRANSFER'=>'Bank Transfer'][$tracking['bankPaymentMethod']];
        $journal['narration'].=' · '.$label.($tracking['bankReference']!==''?' · '.$tracking['bankReference']:'').($tracking['paymentNarration']!==''?' · '.$tracking['paymentNarration']:'');
    }unset($journal);if(!empty($tracking['addBankCharge'])&&!$chargeApplied)throw new DomainException('Additional bank charges require an outgoing TG AED bank payment.');
}

/** Additional AED bank fees are separate expense/VAT/bank lines; never alter the principal. */
function tt_accounts_online_charge_subaccount(array $store): array {
    foreach((array)($store['accountSubaccounts']??[]) as $id=>$row){
        if(!is_array($row)||($row['entity']??'')!=='TG'||(string)($row['parentCode']??'')!=='6800'||empty($row['active']))continue;
        $name=strtoupper(trim((string)($row['name']??'')));
        if(in_array($name,['WEB BANKING CHARGES','ONLINE BANKING CHARGES','ONLINE / WEB BANKING CHARGES'],true))return ['subaccountId'=>(string)($row['id']??$id),'subaccountName'=>(string)$row['name']];
    }
    // A stable built-in account is used only when no configured equivalent exists.
    return ['subaccountId'=>'TG-DEFAULT-ONLINE-BANKING','subaccountName'=>'Online Banking Charges'];
}
function tt_accounts_apply_aed_bank_charge(array $store,array &$journal,array $tracking): bool {
    if(empty($tracking['addBankCharge']))return false;
    if(($journal['entity']??'')!=='TG'||!empty($journal['reversalOf']))return false;
    $bank=null;foreach((array)($journal['lines']??[]) as $line){
        if((string)($line['account']??'')!=='1110'||(float)($line['credit']??0)<=0||strtoupper((string)($line['currency']??''))!=='AED')continue;
        if(!empty($tracking['chargePaymentAccountId'])&&(string)($line['bankAccountId']??'')!==(string)$tracking['chargePaymentAccountId'])continue;
        $bank=$line;break;
    }
    if(!$bank)return false;
    if(!empty($journal['meta']['additionalAedBankCharge']))throw new DomainException('Bank charges have already been applied to this payment.');
    $fee=round((float)($tracking['extraBankChargeNative']??0),2);
    if(!is_finite($fee)||$fee<=0)throw new DomainException('Enter a bank charge greater than zero.');
    $pct=function_exists('tt_company_vat_rate')?tt_company_vat_rate('TG',5):5;$vat=round($fee*$pct/100,2);
    $common=['currency'=>'AED','additionalAedBankCharge'=>true,'bankAccountId'=>$bank['bankAccountId'],'bankReference'=>$tracking['bankReference']??''];
    $journal['lines'][]=['account'=>'6800','accountName'=>'Bank & Finance Charges','debit'=>$fee,'credit'=>0,'nativeDebit'=>$fee,'nativeCredit'=>0,'chargeNative'=>$fee,'chargeType'=>'Online Banking Charges']+tt_accounts_online_charge_subaccount($store)+$common;
    if($vat>0)$journal['lines'][]=['account'=>'1140','accountName'=>'Input VAT','debit'=>$vat,'credit'=>0,'nativeDebit'=>$vat,'nativeCredit'=>0,'vatNative'=>$vat,'vatRate'=>$pct,'chargeType'=>'VAT on Online Banking Charges']+$common;
    foreach([['Online Banking Charges',$fee],['VAT on Online Banking Charges',$vat]] as [$label,$amount]){
        if($amount<=0)continue;
        $journal['lines'][]=['account'=>'1110','accountName'=>$bank['accountName']??'Bank','bankName'=>$bank['bankName']??'','bankAccountTitle'=>$bank['bankAccountTitle']??'','debit'=>0,'credit'=>$amount,'nativeDebit'=>0,'nativeCredit'=>$amount,'bankDebit'=>0,'bankCredit'=>$amount,'chargeType'=>$label,'description'=>$label]+$common;
    }
    $journal['totalDebit']=round(array_sum(array_column($journal['lines'],'debit')),2);$journal['totalCredit']=round(array_sum(array_column($journal['lines'],'credit')),2);
    $journal['meta']['additionalAedBankCharge']=['amount'=>$fee,'vatRate'=>$pct,'vat'=>$vat,'bankDebit'=>round($fee+$vat,2),'bankAccountId'=>$bank['bankAccountId']];
    return true;
}
