<?php
declare(strict_types=1);
function br_date(string $v):string {
 $d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);if(!$d||$d->format('Y-m-d')!==$v)throw new DomainException('Select a valid statement date.');return $v;
}
function br_entity_from_linked(string $v):string {
 $u=strtoupper($v);if(str_contains($u,'BUKSH RICE')||preg_match('/\bBRM\b/',$u))return 'BRM';if(str_contains($u,'TRANS GRAINS')||preg_match('/\bTG\b/',$u))return 'TG';if(str_contains($u,'TRANSTRADE INTERNATIONAL')||preg_match('/\bTTI\b/',$u))return 'TTI';return '';
}
function br_banks(array $s,string $entity):array {
 $m=tt_list_masters();$out=[];foreach((array)($m['banks']??[]) as $r){
  if(!is_array($r))continue;$v=array_values((array)($r['values']??[]));while(count($v)<14)$v[]='';
  $id=(string)($r['id']??'');if(br_entity_from_linked((string)$v[1])!==$entity||!in_array(trim((string)$v[0]),['Company Account','Proprietor / Owner Account','Personal Account'],true))continue;
  $set=(array)($s['bankAccountSettings'][$id]??[]);if(array_key_exists('reconciliationEnabled',$set)&&empty($set['reconciliationEnabled']))continue;
  $raw=preg_replace('/\W+/','',trim((string)($v[8]?:$v[9])))??'';$currency=strtoupper(trim((string)$v[7]))?:($entity==='TG'?'AED':'PKR');
  $out[]=['id'=>$id,'bankName'=>(string)$v[4],'accountTitle'=>(string)$v[3],'currency'=>$currency,'display'=>trim((string)$v[4]).' · '.$currency.($raw!==''?' · •••'.substr($raw,-5):'')];
 }usort($out,static fn($a,$b)=>strcmp($a['display'],$b['display']));return $out;
}
function br_txns(array $s,string $entity,string $bankId,string $toDate,string $currency=''):array {
 $rows=[];$book=0.0;$missing=false;$base=$entity==='TG'?'AED':'PKR';
 foreach((array)($s['journals']??[]) as $jid=>$j){
  if(!is_array($j)||($j['status']??'')!=='Posted'||($j['entity']??'')!==$entity)continue;
  $date=(string)($j['date']??'');if($date===''||$date>$toDate)continue;$jm=(array)($j['meta']??[]);
  foreach((array)($j['lines']??[]) as $i=>$l){
   if(!is_array($l)||(string)($l['account']??'')!=='1110'||(string)($l['bankAccountId']??$jm['bankAccountId']??'')!==$bankId)continue;
   $amt=null;if(isset($l['bankDebit'])||isset($l['bankCredit']))$amt=(float)($l['bankDebit']??0)-(float)($l['bankCredit']??0);
   elseif($currency===''||$currency===$base)$amt=(float)($l['debit']??0)-(float)($l['credit']??0);
   else $missing=true;
   if($amt!==null)$book+=$amt;
   $rows[]=['key'=>(string)$jid.'|'.$i,'date'=>$date,'journalId'=>(string)$jid,'reference'=>(string)($j['reference']??''),'narration'=>(string)($j['narration']??''),'debit'=>$amt===null?null:round(max(0,$amt),2),'credit'=>$amt===null?null:round(max(0,-$amt),2),'net'=>$amt===null?null:round($amt,2),'amountUnavailable'=>$amt===null];
  }
 }usort($rows,static fn($a,$b)=>strcmp($a['date'],$b['date'])?:strcmp($a['journalId'],$b['journalId']));return ['rows'=>$rows,'bookBalance'=>$missing?null:round($book,2),'balanceUnavailable'=>$missing];
}
function br_saved(array $s,string $key,string $date):array {
 $history=(array)($s['bankReconciliationHistory'][$key]??[]);$legacy=$s['bankReconciliations'][$key]??null;
 if(is_array($legacy)&&isset($legacy['statementDate']))$history[$legacy['statementDate']]??=$legacy;
 ksort($history);$prior=[];foreach($history as $day=>$r)if($day<=$date&&is_array($r))$prior=$r;
 return ['exact'=>(array)($history[$date]??[]),'prior'=>$prior,'history'=>array_values(array_reverse($history,true))];
}
function br_totals(array $tx,array $keys,?float $statement):array {
 $deposits=0.0;$payments=0.0;$cleared=0.0;foreach($tx['rows'] as $r){
  if($r['net']===null)continue;if(in_array($r['key'],$keys,true))$cleared+=$r['net'];else{$deposits+=$r['debit'];$payments+=$r['credit'];}
 }
 $adjusted=$statement===null?null:round($statement+$deposits-$payments,2);
 $difference=$adjusted===null||$tx['bookBalance']===null?null:round($adjusted-$tx['bookBalance'],2);
 return ['clearedBalance'=>round($cleared,2),'outstandingDeposits'=>round($deposits,2),'outstandingPayments'=>round($payments,2),'adjustedStatementBalance'=>$adjusted,'difference'=>$difference];
}
function br_payload(array $s,string $entity,string $bankId,string $date):array {
 $banks=br_banks($s,$entity);$bank=null;foreach($banks as $b)if($b['id']===$bankId){$bank=$b;break;}
 $tx=$bank?br_txns($s,$entity,$bankId,$date,$bank['currency']):['rows'=>[],'bookBalance'=>0,'balanceUnavailable'=>false];
 $saved=br_saved($s,$entity.'|'.$bankId,$date);$rec=$saved['exact'];$keys=array_values(array_intersect((array)($saved['prior']['clearedKeys']??[]),array_column($tx['rows'],'key')));
 $statement=isset($rec['statementBalance'])?(float)$rec['statementBalance']:null;
 $rows=array_map(static function($r)use($keys){$r['cleared']=in_array($r['key'],$keys,true);return $r;},$tx['rows']);
 return ['ok'=>true,'entity'=>$entity,'revision'=>(int)($s['revision']??0),'banks'=>$banks,'bank'=>$bank,'statementDate'=>$date,'bookBalance'=>$tx['bookBalance'],'balanceUnavailable'=>$tx['balanceUnavailable'],'statementBalance'=>$statement,'transactions'=>$rows,'reconciliation'=>$rec,'history'=>$saved['history'],'serverNow'=>gmdate('c')]+br_totals($tx,$keys,$statement);
}
function br_save(array &$s,string $entity,array $body,array $user):array {
 $date=br_date((string)($body['statementDate']??''));$bankId=trim((string)($body['bankId']??''));$bank=null;foreach(br_banks($s,$entity) as $b)if($b['id']===$bankId)$bank=$b;
 if(!$bank)throw new DomainException('Select a bank account in these company books.');
 $raw=$body['statementBalance']??null;if($raw===null||$raw===''||!is_numeric($raw)||!is_finite((float)$raw))throw new DomainException('Enter the statement closing balance, including zero or an overdraft.');
 $statement=round((float)$raw,2);$tx=br_txns($s,$entity,$bankId,$date,$bank['currency']);if($tx['balanceUnavailable'])throw new DomainException('A foreign-currency entry is missing its bank-currency amount. Correct that entry before reconciliation.');
 if(!is_array($body['clearedKeys']??null))throw new DomainException('Select the cleared bank-book entries.');$keys=array_values(array_unique(array_map('strval',$body['clearedKeys'])));
 foreach($keys as $k)if(!in_array($k,array_column($tx['rows'],'key'),true))throw new DomainException('A selected transaction is unavailable for this bank and statement date.');
 $totals=br_totals($tx,$keys,$statement);$rec=['entity'=>$entity,'bankId'=>$bankId,'statementDate'=>$date,'statementBalance'=>$statement,'bookBalance'=>$tx['bookBalance'],'clearedKeys'=>$keys,'status'=>abs($totals['difference'])<=.005?'Reconciled':'Difference to Review','updatedAt'=>gmdate('c'),'updatedBy'=>(string)($user['full_name']??$user['username']??'Accounts')]+$totals;
 $key=$entity.'|'.$bankId;$old=$s['bankReconciliations'][$key]??null;if(is_array($old)&&isset($old['statementDate']))$s['bankReconciliationHistory'][$key][$old['statementDate']]??=$old;
 $s['bankReconciliationHistory'][$key][$date]=$rec;
 if(!is_array($old)||$date>=(string)($old['statementDate']??''))$s['bankReconciliations'][$key]=$rec;
 $s['bankReconciliationAudit'][]=['entity'=>$entity,'bankId'=>$bankId,'statementDate'=>$date,'before'=>$old,'after'=>$rec,'by'=>$user['username']??'','at'=>gmdate('c')];return $rec;
}
