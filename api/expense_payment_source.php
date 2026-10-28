<?php
declare(strict_types=1);
require_once __DIR__.'/salary_master_store.php';
require_once __DIR__.'/accounts_ledger_visibility.php';
require_once __DIR__.'/petty_cash_core.php';

/** Shared source validation for expense capture and subsequent supplier-payment corrections. */
function esp_source_error(array $error,int $status=422):never {throw new InvalidArgumentException((string)($error['error']??'Payment source unavailable.'));}

function esp_line(string $account,float $debit,float $credit,array $names,array $meta=[]):array{if(!isset($names[$account]))esp_source_error(['ok'=>false,'error'=>'Approved account '.$account.' is unavailable.'],422);return array_merge(['account'=>$account,'accountName'=>$names[$account],'debit'=>round($debit,2),'credit'=>round($credit,2)],$meta);}

function esp_banks():array{$masters=tt_list_masters();$out=[];foreach((array)($masters['banks']??[])as$row){if(!is_array($row))continue;$v=array_values((array)($row['values']??[]));while(count($v)<14)$v[]='';$linked=strtoupper((string)$v[1]);$entity=str_contains($linked,'BRM')||str_contains($linked,'BUKSH RICE')?'BRM':(str_contains($linked,'TTI')||str_contains($linked,'TRANSTRADE INTERNATIONAL')?'TTI':(str_contains($linked,'TG')||str_contains($linked,'TRANS GRAINS')?'TG':''));if($entity==='')continue;$out[(string)($row['id']??'')]=['id'=>(string)($row['id']??''),'entity'=>$entity,'accountType'=>(string)$v[0],'accountTitle'=>(string)$v[3],'bankName'=>(string)$v[4],'currency'=>strtoupper(trim((string)$v[7])),'accountNumber'=>(string)$v[8],'iban'=>(string)$v[9],'masterStatus'=>(string)$v[13]];}return $out;}

function esp_pay_line(array $s,string $entity,string $id,float $amount,array $names):array{if(str_starts_with($id,'CASH|'.$entity.'|STAFF|')){ $h=pc_account($s,$entity,$id);return esp_line('1120',0,$amount,$names,['cashAccountId'=>$id,'paymentAccountId'=>$id,'cashAccountName'=>'Cash with '.$h['name'],'subledger'=>'Cash with '.$h['name'],'currency'=>'PKR','paymentType'=>'Cash']);}if($id==='CASH|'.$entity&&isset($s['bankAccountSettings'][$id]['active'])&&!$s['bankAccountSettings'][$id]['active'])esp_source_error(['ok'=>false,'error'=>'This cash account is inactive.'],422);if($id==='CASH|'.$entity)return esp_line('1120',0,$amount,$names,['paymentAccountId'=>$id,'paymentType'=>'Cash']);if(str_starts_with($id,'CARD|')){$cardId=substr($id,5);$card=$s['creditCardMasters'][$cardId]??null;if(!is_array($card)||($card['entity']??'')!==$entity||($card['status']??'Active')!=='Active')throw new InvalidArgumentException('Select an active company credit card.');return esp_line('2400',0,$amount,$names,['cardMasterId'=>$cardId,'paymentAccountId'=>$id,'counterparty'=>$card['cardName'],'paymentType'=>'Credit Card']);}$a=esp_banks()[$id]??null;$expected=$entity==='TG'?'AED':'PKR';if(!is_array($a)||($a['entity']??'')!==$entity||!in_array(($a['accountType']??''),['Company Account','Proprietor / Owner Account','Personal Account'],true)||($a['currency']??'')!==$expected||strcasecmp((string)($a['masterStatus']??'Active'),'Active')!==0||(trim((string)$a['accountNumber'])===''&&trim((string)$a['iban'])===''))esp_source_error(['ok'=>false,'error'=>'Select a valid '.$expected.' company bank account.'],422);$set=is_array($s['bankAccountSettings'][$id]??null)?$s['bankAccountSettings'][$id]:[];if(!tt_bank_can_transact($id))esp_source_error(['ok'=>false,'error'=>'This bank account is not enabled for payments.'],422);return esp_line('1110',0,$amount,$names,['bankAccountId'=>$id,'bankName'=>$a['bankName'],'accountTitle'=>$a['accountTitle'],'accountNumber'=>$a['accountNumber'],'iban'=>$a['iban'],'currency'=>$a['currency'],'bankCurrency'=>$a['currency'],'bankDebit'=>0,'bankCredit'=>$amount,'exchangeRate'=>1]);}

function esp_due_date(string $month,int $day):string{$first=new DateTimeImmutable($month.'-01');$last=(int)$first->modify('last day of this month')->format('d');return $month.'-'.str_pad((string)min($day,$last),2,'0',STR_PAD_LEFT);}

function esp_card_classified(array $s,string $id):float {
 $sum=0;foreach((array)($s['journals']??[]) as $j)if(($j['status']??'')==='Posted'&&empty($j['meta']['deletedFromBooks']))foreach((array)($j['lines']??[]) as $l)if(!empty($l['classificationWithinBill'])&&($l['cardStatementId']??'')===$id)$sum+=(float)($l['credit']??0)-(float)($l['debit']??0);return round($sum,2);
}

function esp_card_capacity(array $s,array $st,float $extra=0,?array $personal=null):void {
 $used=(float)($st['includedUtilityTotal']??0)+(float)($st['includedExpenseTotal']??0)+esp_card_classified($s,$st['id'])+array_sum(array_column($personal??(array)($st['personalAmounts']??[]),'amount'))+$extra;
 if($used>(float)$st['total']+.005)throw new InvalidArgumentException('Utilities, other expenses and personal deductions cannot exceed the full card bill.');
}

function esp_card_charge_credit(array $s,string $entity,string $paymentId,float $amount,string $date,array $names,string $statementId=''):array {
 $credit=esp_pay_line($s,$entity,$paymentId,$amount,$names);if(!str_starts_with($paymentId,'CARD|'))return $credit;
 $cardId=substr($paymentId,5);$card=$s['creditCardMasters'][$cardId];$matches=[];
 foreach((array)($s['creditCardStatements']??[]) as $st)if(($st['entity']??'')===$entity&&($st['cardMasterId']??'')===$cardId&&($st['status']??'')!=='Deleted'&&($statementId!==''?($st['id']??'')===$statementId:($st['statementDate']??'')>=$date))$matches[]=$st;
 usort($matches,static fn($a,$b)=>strcmp($a['statementDate'],$b['statementDate']));$st=$matches[0]??null;
 if($statementId!==''&&!$st)throw new InvalidArgumentException('Select a saved bill for this credit card.');
 if($st){if(empty($st['simpleStatement'])||empty($st['statementJournalId']))throw new InvalidArgumentException('Convert the historical bill before classifying its expenses.');esp_card_capacity($s,$st,$amount);return esp_line((string)$st['expenseAccount'],0,$amount,$names,['cardMasterId'=>$cardId,'paymentAccountId'=>$paymentId,'cardStatementId'=>$st['id'],'cardStatementMonth'=>$st['statementMonth'],'classificationWithinBill'=>true,'counterparty'=>$card['cardName'],'paymentType'=>'Credit Card']);}
 $day=(int)($card['statementDay']??31);$month=substr($date,0,7);if($date>esp_due_date($month,$day))$month=(new DateTimeImmutable($month.'-01'))->modify('+1 month')->format('Y-m');
 return $credit+['cardStatementMonth'=>$month];
}
