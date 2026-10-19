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
    return ['bankPaymentMethod'=>$method,'bankReference'=>$method==='CHEQUE'?$cheque:$reference,'chequeNo'=>$method==='CHEQUE'?$cheque:'','chequeDate'=>$method==='CHEQUE'?$chequeDate:'','bankPaymentDate'=>$bankPaymentDate,'paymentNarration'=>$narration];
}

function tt_accounts_track_bank_payment(array &$store,array $originalJournalIds,array $tracking): void {
    if(!$tracking)return;
    $old=array_fill_keys($originalJournalIds,true);
    foreach($store['journals'] as $id=>&$journal){
        if(isset($old[$id]))continue;
        $bank=false;foreach((array)($journal['lines']??[]) as $line)if(($line['account']??'')==='1110'&&!empty($line['bankAccountId'])){$bank=true;break;}
        if(!$bank)continue;
        $journal['meta']=array_merge((array)($journal['meta']??[]),$tracking);
        $label=['CHEQUE'=>'Cheque','ONLINE_BANKING'=>'Online Banking','BANK_TRANSFER'=>'Bank Transfer'][$tracking['bankPaymentMethod']];
        $journal['narration'].=' · '.$label.($tracking['bankReference']!==''?' · '.$tracking['bankReference']:'').($tracking['paymentNarration']!==''?' · '.$tracking['paymentNarration']:'');
    }unset($journal);
}
