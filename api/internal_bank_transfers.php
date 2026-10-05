<?php
declare(strict_types=1);
require_once __DIR__.'/accounts_bank_payment.php';
require dirname(__DIR__).'/auth_store.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
const TT_INTERNAL_BANK_FILE=TT_DATA_DIR.'/accounts.json';
function ibt_out(array $body,int $status=200):never{http_response_code($status);echo json_encode($body,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
function ibt_entity(string $value):string{$value=strtoupper(trim($value));if(!in_array($value,['TTI','BRM','TG'],true))ibt_out(['ok'=>false,'error'=>'Choose TTI, BRM or TG books.'],422);return $value;}
function ibt_can_write(array $user):bool{if(($user['role']??'')==='Super Admin')return true;$p=$user['permissions']['Accounts']??null;if($p==='all')return true;if(!is_array($p))return false;if(in_array('Create',$p,true)||in_array('Edit',$p,true)||in_array('Approve',$p,true))return true;foreach($p as $v)if(is_array($v)&&count(array_intersect(['Create','Edit','Approve'],$v)))return true;return false;}
function ibt_can_post_entity(array $user,string $entity):bool{return ($user['role']??'')==='Super Admin'||tt_user_can_access_entity($user,$entity,'Create')||tt_user_can_access_entity($user,$entity,'Edit')||tt_user_can_access_entity($user,$entity,'Approve');}
function ibt_read():array{tt_ensure_data_dir();if(!is_file(TT_INTERNAL_BANK_FILE))return ['revision'=>0,'journals'=>[],'internalBankTransfers'=>[]];$h=fopen(TT_INTERNAL_BANK_FILE,'r');if(!$h||!flock($h,LOCK_SH))throw new RuntimeException('Accounts unavailable.');try{$raw=stream_get_contents($h);}finally{flock($h,LOCK_UN);fclose($h);}return array_replace_recursive(['revision'=>0,'journals'=>[],'internalBankTransfers'=>[]],(array)json_decode($raw?:'{}',true));}
function ibt_entity_of(string $linked):string{$v=strtoupper($linked);if(str_contains($v,'BRM')||str_contains($v,'BUKSH RICE'))return 'BRM';if(str_contains($v,'TRANS GRAINS')||preg_match('/(^|\W)TG($|\W)/',$v))return 'TG';if(str_contains($v,'TTI')||str_contains($v,'TRANSTRADE INTERNATIONAL'))return 'TTI';return '';}
function ibt_accounts():array{$out=[];foreach((array)(tt_list_masters()['banks']??[]) as $row){if(!is_array($row))continue;$v=array_values((array)($row['values']??[]));while(count($v)<14)$v[]='';$id=(string)($row['id']??'');if($id==='')continue;$out[$id]=['id'=>$id,'type'=>(string)$v[0],'entity'=>ibt_entity_of((string)$v[1]),'owner'=>trim((string)$v[2]),'title'=>(string)$v[3],'bank'=>(string)$v[4],'currency'=>strtoupper(trim((string)$v[7])),'number'=>(string)$v[8],'iban'=>(string)$v[9],'status'=>(string)$v[13]];}return $out;}
function ibt_bank_balance(array $store,string $entity,string $bankId,string $currency):array{$native=0.0;$functional=0.0;foreach((array)($store['journals']??[]) as $j){if(!is_array($j)||($j['status']??'')!=='Posted'||($j['entity']??'')!==$entity)continue;$meta=(array)($j['meta']??[]);foreach((array)($j['lines']??[]) as $line){if(!is_array($line)||($line['account']??'')!=='1110'||(string)($line['bankAccountId']??$meta['bankAccountId']??'')!==$bankId)continue;$functional+=(float)($line['debit']??0)-(float)($line['credit']??0);$native+=$currency==='PKR'?(float)($line['debit']??0)-(float)($line['credit']??0):(float)($line['bankDebit']??0)-(float)($line['bankCredit']??0);}}return ['native'=>round($native,2),'functional'=>round($functional,2),'carryingRate'=>$currency==='PKR'||$currency==='AED'?1:(abs($native)>.0001?round($functional/$native,8):0)];}
function ibt_catalog():array{$raw=json_decode((string)file_get_contents(__DIR__.'/../accounts/accounting_master_v1.json'),true);$out=[];foreach((array)($raw['chart']??[]) as $row)if(is_array($row)&&isset($row['code']))$out[(string)$row['code']]=(string)($row['name']??'');return $out;}
function ibt_line(string $account,float $dr,float $cr,array $catalog,array $meta=[]):array{if(!isset($catalog[$account]))ibt_out(['ok'=>false,'error'=>'Approved ledger '.$account.' is unavailable.'],422);return array_merge(['account'=>$account,'accountName'=>$catalog[$account],'debit'=>round($dr,2),'credit'=>round($cr,2)],$meta);}
function ibt_next(array $rows,string $prefix):string{$n=count($rows)+1;do{$id=$prefix.'-'.gmdate('Y').'-'.str_pad((string)$n++,6,'0',STR_PAD_LEFT);}while(isset($rows[$id]));return $id;}
function ibt_payload(array $store,string $entity,array $user):array{
    $accounts=ibt_accounts();$sources=[];$destinations=[];
    foreach($accounts as $row){
        $sameCompany=$row['entity']===$entity;
        $isCompany=$sameCompany&&$row['type']==='Company Account';
        $isPersonal=in_array($row['entity'],['TTI','BRM','TG'],true)
            &&in_array($row['type'],['Personal Account','Proprietor / Owner Account'],true)
            &&$row['owner']!==''
            &&($sameCompany||($entity!=='TG'&&$row['entity']!=='TG'&&$row['currency']==='PKR'&&ibt_can_post_entity($user,$row['entity'])));
        if(!$isCompany&&!$isPersonal)continue;
        if(strcasecmp($row['status'],'Active')!==0||($row['number']===''&&$row['iban']===''))continue;
        if($entity!=='TG'&&$row['currency']!=='PKR')continue;
        if($isPersonal&&!$sameCompany&&!tt_bank_can_transact($row['id']))continue;
        $safe=['id'=>$row['id'],'type'=>$row['type'],'entity'=>$row['entity'],'owner'=>$row['owner'],'title'=>$row['title'],'bank'=>$row['bank'],'number'=>$row['number'],'iban'=>$row['iban'],'currency'=>$row['currency']];
        if($isCompany){
            if(!tt_bank_can_transact($row['id']))continue;
            $setting=(array)($store['bankAccountSettings'][$row['id']]??[]);
            $safe['isDefault']=!empty($setting['defaultPaymentAccount'])?2:(!empty($setting['defaultReceiptAccount'])?1:0);
            $safe['balance']=ibt_bank_balance($store,$entity,$row['id'],$row['currency']);
            $sources[]=$safe;
        }
        $destinations[]=$safe;
    }
    $history=array_values(array_filter((array)($store['internalBankTransfers']??[]),static fn($x)=>is_array($x)&&($x['entity']??'')===$entity));
    usort($history,static fn($a,$b)=>strcmp((string)$b['date'],(string)$a['date']));
    return ['ok'=>true,'entity'=>$entity,'sources'=>$sources,'destinations'=>$destinations,'history'=>array_slice($history,0,30)];
}
try{
 $user=tt_require_login();if(!tt_user_can_open_module($user,'Accounts'))ibt_out(['ok'=>false,'error'=>'Accounts permission required.'],403);
 $entity=ibt_entity((string)($_GET['entity']??'TTI'));if(($user['role']??'')!=='Super Admin'&&!tt_user_can_access_entity($user,$entity,'View'))ibt_out(['ok'=>false,'error'=>'You cannot access these company books.'],403);
 if($_SERVER['REQUEST_METHOD']==='GET')ibt_out(ibt_payload(ibt_read(),$entity,$user));
 if($_SERVER['REQUEST_METHOD']!=='POST')ibt_out(['ok'=>false,'error'=>'Method not allowed.'],405);if(!ibt_can_write($user))ibt_out(['ok'=>false,'error'=>'Accounts Create / Edit / Approve permission required.'],403);
 if(!ibt_can_post_entity($user,$entity))ibt_out(['ok'=>false,'error'=>'Posting permission for this company is required.'],403);
 $body=json_decode((function_exists('tt_accounts_input')?tt_accounts_input():file_get_contents('php://input'))?:'{}',true);if(!is_array($body)||!tt_verify_csrf((string)($body['csrf']??'')))ibt_out(['ok'=>false,'error'=>'Session expired. Refresh and try again.'],419);if(($body['action']??'')!=='post_transfer')ibt_out(['ok'=>false,'error'=>'Unknown transfer action.'],422);
 $date=trim((string)($body['date']??''));if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))ibt_out(['ok'=>false,'error'=>'Transfer date required.'],422);$sourceId=trim((string)($body['sourceBankId']??''));$destinationId=trim((string)($body['destinationBankId']??''));$reference=trim((string)($body['reference']??''));try{$tracking=tt_accounts_bank_payment_details($body+['paymentAccountId'=>$sourceId]);}catch(DomainException $e){ibt_out(['ok'=>false,'error'=>$e->getMessage()],422);}$narration=trim((string)($body['narration']??''));$amount=round((float)($body['amount']??0),2);if($sourceId===$destinationId||$sourceId===''||$destinationId==='')ibt_out(['ok'=>false,'error'=>'Choose different source and destination accounts.'],422);if($reference==='')$reference='IBT-'.bin2hex(random_bytes(12));if($narration===''||$amount<=0)ibt_out(['ok'=>false,'error'=>'Amount and why the transfer is happening are required.'],422);
 tt_ensure_data_dir();$h=fopen(TT_INTERNAL_BANK_FILE,'c+');if(!$h||!flock($h,LOCK_EX))throw new RuntimeException('Accounts unavailable.');try{rewind($h);$raw=stream_get_contents($h);$store=array_replace_recursive(['revision'=>0,'journals'=>[],'internalBankTransfers'=>[]],(array)json_decode($raw?:'{}',true));$eligible=ibt_payload($store,$entity,$user);$source=null;$destination=null;foreach($eligible['sources'] as $x)if($x['id']===$sourceId)$source=$x;foreach($eligible['destinations'] as $x)if($x['id']===$destinationId)$destination=$x;if(!$source||!$destination)ibt_out(['ok'=>false,'error'=>'Choose an active company source and an authorised destination account.'],422);if($source['currency']!==$destination['currency'])ibt_out(['ok'=>false,'error'=>'Choose a destination in the same currency. Use the approved FX workflow for conversion.'],422);foreach((array)$store['internalBankTransfers'] as $x)if(is_array($x)&&($x['entity']??'')===$entity&&$x['sourceBankId']===$sourceId&&strcasecmp((string)$x['reference'],$reference)===0)ibt_out(['ok'=>false,'error'=>'This source bank reference has already been posted.'],409);
 $crossEntity=$destination['entity']!==$entity;
 if($crossEntity&&($destination['type']==='Company Account'||$source['currency']!=='PKR'))ibt_out(['ok'=>false,'error'=>'Cross-company transfers here require a PKR company source and a linked personal destination.'],422);
 $kind=$crossEntity?'INTERCOMPANY_ADVANCE':($destination['type']==='Company Account'?'INTERNAL_BANK_TRANSFER':'PERSONAL_BANK_ADVANCE');
 $rate=(float)$source['balance']['carryingRate'];if($rate<=0)$rate=(float)(tt_company_fx_rate($entity,$source['currency'],'AED')??0);if($rate<=0)ibt_out(['ok'=>false,'error'=>'Configure a valid company exchange rate for this currency.'],422);
 $functional=round($amount*$rate,2);$catalog=ibt_catalog();$id=ibt_next((array)$store['internalBankTransfers'],'IBT');
 $jid=tt_next_post_id((array)$store['journals'],'Accounts','Journal');
 $createdAt=gmdate('c');$createdBy=(string)($user['full_name']??$user['username']??'Accounts');
 $record=['id'=>$id,'entity'=>$entity,'destinationEntity'=>$destination['entity'],'postingKind'=>$kind,'date'=>$date,'sourceBankId'=>$sourceId,'destinationBankId'=>$destinationId,'destinationType'=>$destination['type'],'personalOwner'=>$destination['owner'],'currency'=>$source['currency'],'amount'=>$amount,'functionalAmount'=>$functional,'reference'=>$reference,'narration'=>$narration,'journalId'=>$jid,'createdAt'=>$createdAt,'createdBy'=>$createdBy];
 $record=array_merge($record,$tracking);
 $srcMeta=['bankAccountId'=>$sourceId,'bankName'=>$source['bank'],'bankAccountTitle'=>$source['title'],'currency'=>$source['currency'],'bankDebit'=>0,'bankCredit'=>$amount]+$tracking;
 $lines=[ibt_line('1110',0,$functional,$catalog,$srcMeta)];
 if($kind==='INTERNAL_BANK_TRANSFER'){
     $dstMeta=['bankAccountId'=>$destinationId,'bankName'=>$destination['bank'],'bankAccountTitle'=>$destination['title'],'currency'=>$source['currency'],'bankDebit'=>$amount,'bankCredit'=>0];
     array_unshift($lines,ibt_line('1110',$functional,0,$catalog,$dstMeta));
 }elseif($kind==='INTERCOMPANY_ADVANCE'){
     array_unshift($lines,ibt_line('1240',$functional,0,$catalog,['counterpartyEntity'=>$destination['entity'],'destinationBankId'=>$destinationId,'intercompanyTransferId'=>$id]));
 }else{
     array_unshift($lines,ibt_line('1230',$functional,0,$catalog,['counterparty'=>$destination['owner'],'personalBankId'=>$destinationId,'purpose'=>$narration]));
 }
 $journal=['id'=>$jid,'entity'=>$entity,'date'=>$date,'sourceType'=>$kind,'reference'=>$reference,'narration'=>$narration,'lines'=>$lines,'totalDebit'=>$functional,'totalCredit'=>$functional,'status'=>'Posted','meta'=>$record,'createdAt'=>$createdAt,'createdBy'=>$createdBy,'userId'=>(int)($user['id']??0),'reversalOf'=>null];
 $store['journals'][$jid]=$journal;
 $mirror=null;
 if($crossEntity){
     $mirrorId=tt_next_post_id((array)$store['journals'],'Accounts','Journal');
     $record['mirrorJournalId']=$mirrorId;
     $journal['meta']=$record;
     $store['journals'][$jid]=$journal;
     $mirrorLines=[
         ibt_line('1110',$functional,0,$catalog,['bankAccountId'=>$destinationId,'bankName'=>$destination['bank'],'bankAccountTitle'=>$destination['title'],'currency'=>'PKR','bankDebit'=>$amount,'bankCredit'=>0,'intercompanyTransferId'=>$id]),
         ibt_line('2500',0,$functional,$catalog,['counterpartyEntity'=>$entity,'sourceBankId'=>$sourceId,'intercompanyTransferId'=>$id]),
     ];
     $mirror=['id'=>$mirrorId,'entity'=>$destination['entity'],'date'=>$date,'sourceType'=>'INTERCOMPANY_ADVANCE_RECEIPT','reference'=>$reference,'narration'=>$narration,'lines'=>$mirrorLines,'totalDebit'=>$functional,'totalCredit'=>$functional,'status'=>'Posted','meta'=>$record+['counterpartyEntity'=>$entity],'createdAt'=>$createdAt,'createdBy'=>$createdBy,'userId'=>(int)($user['id']??0),'reversalOf'=>null];
     $store['journals'][$mirrorId]=$mirror;
 }
 $store['internalBankTransfers'][$id]=$record;$store['revision']=(int)($store['revision']??0)+1;
 rewind($h);ftruncate($h,0);fwrite($h,json_encode($store,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));fflush($h);
 }finally{flock($h,LOCK_UN);fclose($h);}ibt_out(ibt_payload($store,$entity,$user)+['transfer'=>$record,'journal'=>$journal,'mirrorJournal'=>$mirror]);
}catch(Throwable $e){error_log('Internal bank transfer: '.$e->getMessage());ibt_out(['ok'=>false,'error'=>'Bank transfer could not be completed.'],500);}
