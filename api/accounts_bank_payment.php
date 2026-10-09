<?php
declare(strict_types=1);

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
    if($method==='CHEQUE'&&($cheque===''||strlen($cheque)>180||($input['chequeDate']??'')!==$date))throw new DomainException('Enter cheque number and the payment date. Use Issued Cheques for post-dated supplier cheques.');
    return ['bankPaymentMethod'=>$method,'bankReference'=>$method==='CHEQUE'?$cheque:$reference,'chequeNo'=>$method==='CHEQUE'?$cheque:'','chequeDate'=>$method==='CHEQUE'?$date:'','paymentNarration'=>$narration];
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
