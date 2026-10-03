<?php
declare(strict_types=1);

/** Pending remittances reserve native bank funds; only confirmation creates journals. */
function tgr_id(array $rows,string $prefix):string {
 $n=count($rows)+1;do{$id=$prefix.'-'.gmdate('Y').'-'.str_pad((string)$n++,6,'0',STR_PAD_LEFT);}while(isset($rows[$id]));return $id;
}
function tgr_money(mixed $value,string $label,bool $positive=false):float {
 if(!is_numeric($value)||!is_finite((float)$value)||(float)$value<0||(float)$value>1000000000)throw new DomainException('Enter a valid '.$label.'.');
 $n=round((float)$value,2);if($positive&&$n<=0)throw new DomainException($label.' must be greater than zero.');return $n;
}
function tgr_date(string $v):string {
 $d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);$today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Karachi')))->format('Y-m-d');
 if(!$d||$d->format('Y-m-d')!==$v||$v>$today)throw new DomainException('Enter a valid remittance date, no later than today.');return $v;
}
function tgr_line(string $code,float $dr,float $cr,array $names,array $extra=[]):array {
 if(!isset($names[$code]))throw new DomainException('Accounting account '.$code.' is missing.');
 return ['account'=>$code,'accountName'=>is_array($names[$code])?(string)($names[$code]['name']??$code):(string)$names[$code],'debit'=>round($dr,2),'credit'=>round($cr,2)]+$extra;
}
function tgr_journal(array &$s,array $u,string $date,string $type,string $ref,string $text,array $lines,array $meta):array {
 $dr=round(array_sum(array_column($lines,'debit')),2);$cr=round(array_sum(array_column($lines,'credit')),2);
 if((int)round($dr*100)!==(int)round($cr*100))throw new RuntimeException('Remittance journal is unbalanced.');
 $id=tt_next_post_id((array)($s['journals']??[]),'Accounts','Journal');return $s['journals'][$id]=['id'=>$id,'entity'=>'TG','date'=>$date,'sourceType'=>$type,'reference'=>strtoupper($ref),'narration'=>strtoupper($text),'lines'=>$lines,'totalDebit'=>$dr,'totalCredit'=>$cr,'status'=>'Posted','meta'=>$meta,'createdAt'=>gmdate('c'),'createdBy'=>(string)($u['full_name']??$u['username']??'Accounts'),'userId'=>(int)($u['id']??0),'reversalOf'=>null];
}
function tgr_items(array $s):array {
 $out=[];
 foreach((array)($s['tgRemittanceDrafts']??[]) as $x)if(is_array($x)&&($x['status']??'')==='Pending')$out[$x['id']]=$x;
 // Older automatic mirrors are already deducted: review/consolidate them without reserving twice.
 foreach((array)($s['exportReceipts']??[]) as $r){
  if(!is_array($r)||($r['status']??'')!=='Accounts Approved / Posted'||strtoupper((string)($r['remitter']??''))!=='TG'||!empty($r['tgRemittanceId']))continue;
  $ids=(array)($r['tgMirrorPostIds']??[]);if(!$ids)continue;$alloc=[];$bank='';$name='';$valid=true;
  foreach($ids as $jid){$j=$s['journals'][$jid]??[];if(($j['status']??'')!=='Posted'||($j['entity']??'')!=='TG'||($j['meta']['receiptId']??'')!==$r['id']){$valid=false;break;}
   foreach((array)($s['journals']??[]) as $other)if(($other['reversalOf']??'')===$jid)$valid=false;
   foreach((array)($j['lines']??[]) as $l){if(($l['account']??'')==='1110'){$bid=(string)($l['bankAccountId']??'');if($bank!==''&&$bank!==$bid)$valid=false;$bank=$bid;$name=(string)($l['bankName']??'TG bank');}}
   $alloc[]=['legacyJournalId'=>$jid,'lines'=>$j['lines']??[]];
  }
  if(!$valid||$bank==='')continue;$id='LEGACY|'.$r['id'];
  $out[$id]=['id'=>$id,'status'=>'Pending','legacy'=>true,'receiptIds'=>[$r['id']],'pakJournalIds'=>[$r['journalId']],'counterparty'=>$r['entity'],'date'=>$r['date'],'currency'=>$r['transactionCurrency'],'bankAccountId'=>$bank,'bank'=>$name,'amountNative'=>(float)$r['foreignAmount'],'bankAdviceRefs'=>[$r['bankAdviceRef']],'allocations'=>$alloc,'invoiceRefs'=>array_values(array_unique(array_filter(array_merge(array_column((array)($r['allocations']??[]),'invoiceRef'),array_column((array)($r['invoiceUtilisations']??[]),'invoiceRef'))))),'version'=>hash('sha256',json_encode([$ids,$r['allocations']??[]]))];
 }
 return array_values($out);
}
function tgr_reserved(array $s,string $bank):float {
 $n=0.0;foreach(tgr_items($s) as $x)if(empty($x['legacy'])&&$x['bankAccountId']===$bank)$n+=(float)$x['amountNative'];return round($n,2);
}
function tgr_bank_balance(array $s,string $id,string $currency):array {
 $native=0.0;$book=0.0;
 foreach((array)($s['journals']??[]) as $j)if(($j['entity']??'')==='TG'&&($j['status']??'')==='Posted')foreach((array)($j['lines']??[]) as $l)if(($l['account']??'')==='1110'&&(string)($l['bankAccountId']??$j['meta']['bankAccountId']??'')===$id){$native+=(float)($l['bankDebit']??($currency==='AED'?($l['debit']??0):0))-(float)($l['bankCredit']??($currency==='AED'?($l['credit']??0):0));$book+=(float)($l['debit']??0)-(float)($l['credit']??0);}
 $pending=tgr_reserved($s,$id);return ['posted'=>round($native,2),'pending'=>$pending,'available'=>round($native-$pending,2),'carryingRate'=>abs($native)>.0001?$book/$native:0];
}
function tgr_fingerprint(array $x):string {return hash('sha256',json_encode($x,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));}
function tgr_confirm(array &$s,array $b,array $u,array $banks,array $names,array $rates):array {
 $key=(string)($b['requestKey']??'');if(!preg_match('/^[a-zA-Z0-9-]{16,80}$/',$key))throw new DomainException('Reopen the remittance review to obtain its posting ID.');
 $hash=hash('sha256',json_encode($b));$prior=$s['tgRemittanceRequests'][$key]??null;
 if($prior){if($prior['hash']!==$hash)throw new DomainException('This review has already posted with different details.');return $s['tgRemittances'][$prior['id']];}
 $ids=array_values((array)($b['ids']??[]));if(!$ids||count($ids)>100||count(array_unique($ids))!==count($ids))throw new DomainException('Select the credit advices belonging to this remittance.');
 $items=array_column(tgr_items($s),null,'id');$chosen=[];$first=null;$amount=0.0;$receiptIds=[];$invoices=[];$refs=[];$legacy=[];$lines=[];
 foreach($ids as $id){$x=$items[$id]??null;if(!$x||($b['fingerprints'][$id]??'')!==tgr_fingerprint($x))throw new DomainException('This remittance changed or was posted. Reopen the review.');
  $first??=$x;foreach(['bankAccountId','currency','counterparty'] as $f)if($x[$f]!==$first[$f])throw new DomainException('Combine only the same TG bank, currency and receiving company.');
  $chosen[]=$x;$amount+=(float)$x['amountNative'];$receiptIds=array_merge($receiptIds,$x['receiptIds']);$invoices=array_merge($invoices,(array)($x['invoiceRefs']??[]));$refs=array_merge($refs,$x['bankAdviceRefs']);
 }
 if(empty($b['sameRemittanceConfirmed']))throw new DomainException('Confirm the selected advices belong to one actual remittance.');
 $date=tgr_date((string)($b['date']??''));$ref=strtoupper(trim((string)($b['bankReference']??'')));$bank=$banks[$first['bankAccountId']]??null;
 if(!$bank||$bank['currency']!==$first['currency'])throw new DomainException('Complete and activate the selected TG bank in Company Master.');
 foreach($chosen as $source)if(!empty($source['reopenedFrom'])&&$date<$source['date'])throw new DomainException('Reposting date cannot precede the correction reversal.');
 $cur=$first['currency'];$balance=tgr_bank_balance($s,$first['bankAccountId'],$cur);$rate=$cur==='AED'?1:(float)$balance['carryingRate'];if($rate<=0)$rate=(float)($rates[$cur]??0);if($rate<=0)throw new DomainException('Set the TG '.$cur.'/AED exchange rate in Company Master.');
 $charge=tgr_money($b['chargeAmount']??0,'bank charge');$vat=tgr_money($b['vatAmount']??0,'VAT');$chargeBankId=(string)($b['chargeBankAccountId']??$first['bankAccountId']);$chargeBank=$banks[$chargeBankId]??null;
 if(($charge+$vat)>0&&!$chargeBank)throw new DomainException('Choose the TG bank actually debited for charges and VAT.');
 $reason=strtoupper(trim((string)($b['reason']??'')));$hasLegacy=(bool)array_filter($chosen,static fn($x)=>!empty($x['legacy']));if($hasLegacy&&strlen($reason)<5)throw new DomainException('Enter a reason to consolidate the previously posted TG mirrors.');
 foreach((array)($s['tgRemittances']??[]) as $r)if(($r['status']??'')==='Posted'&&$ref!==''&&$r['bankAccountId']===$first['bankAccountId']&&strcasecmp($r['bankReference'],$ref)===0)throw new DomainException('This remittance bank reference is already posted.');
 foreach((array)($s['tgBankTransactions']??[]) as $tx)if($ref!==''&&($tx['status']??'')!=='Reversed for Amendment'&&($tx['bankAccountId']??'')===$first['bankAccountId']&&strcasecmp((string)($tx['bankReference']??''),$ref)===0)throw new DomainException('That bank reference is already posted. Link its existing payment rather than posting again.');
 if(count($receiptIds)!==count(array_unique($receiptIds)))throw new DomainException('A credit advice cannot appear twice in the same remittance.');
 foreach($receiptIds as $receiptId)if(($s['exportReceipts'][$receiptId]['status']??'')!=='Accounts Approved / Posted'||!empty($s['exportReceipts'][$receiptId]['tgRemittanceId']))throw new DomainException('A selected credit advice changed or is already confirmed.');
 $rid=tgr_id((array)($s['tgRemittances']??[]),'TGR');$txid=tgr_id((array)($s['tgBankTransactions']??[]),'TGBK');$liabilityAllocations=[];
 foreach($chosen as $x)foreach($x['allocations'] as $a){
  if(isset($a['legacyJournalId'])){
   $old=$s['journals'][$a['legacyJournalId']];$rev=[];
   foreach($old['lines'] as $l){$q=$l;$q['debit']=(float)$l['credit'];$q['credit']=(float)$l['debit'];if(isset($l['bankDebit'])||isset($l['bankCredit'])){$q['bankDebit']=(float)($l['bankCredit']??0);$q['bankCredit']=(float)($l['bankDebit']??0);}$rev[]=$q;
    if(!in_array($l['account'],['1110','7100'],true)){$l['currency']=$cur;$l['nativeDebit']=(float)($l['debit']??0)>0?(float)($old['meta']['amountNative']??0):0;$l['nativeCredit']=(float)($l['credit']??0)>0?(float)($old['meta']['amountNative']??0):0;$lines[]=$l;if(!empty($l['sourceLiabilityId']))$liabilityAllocations[]=['id'=>$l['sourceLiabilityId'],'amountNative'=>(float)($old['meta']['amountNative']??0)];}
   }
   $reversal=tgr_journal($s,$u,$date,'TG_REMITTANCE_CONSOLIDATION_REVERSAL',$old['id'],'CONSOLIDATE '.$old['id'].' · '.$reason,$rev,['tgRemittanceId'=>$rid,'reason'=>$reason,'currency'=>$cur,'amountNative'=>(float)($old['meta']['amountNative']??0)]);$s['journals'][$reversal['id']]['reversalOf']=$old['id'];$legacy[$old['id']]=$reversal['id'];
  }else{
   $amountPart=(float)$a['foreignAmount'];$target=(string)$a['targetAccount'];$targetRate=$target==='1250'?$rate:(float)$a['payableRate'];
   if($a['sourceLiabilityId']!==''){$liability=$s['exportCandidates'][$a['sourceLiabilityId']]??null;if(!$liability||($liability['entity']??'')!=='TG'||($liability['counterparty']??'')!==$first['counterparty']||($liability['transactionCurrency']??'')!==$cur||empty($liability['journalId']))throw new DomainException('A linked invoice payable changed. Review the source invoice first.');$targetRate=(float)($liability['currentCarryingRate']??$liability['functionalRate']??0);if($targetRate<=0)throw new DomainException('The linked invoice carrying rate is missing.');}

   $lines[]=tgr_line($target,$amountPart*$targetRate,0,$names,['counterparty'=>$first['counterparty'],'sourceLiabilityId'=>$a['sourceLiabilityId'],'receiptId'=>$x['receiptIds'][0],'currency'=>$cur,'nativeDebit'=>$amountPart,'nativeCredit'=>0]);
   if($a['sourceLiabilityId']!=='')$liabilityAllocations[]=['id'=>$a['sourceLiabilityId'],'amountNative'=>$amountPart];
  }
 }
 $bankExtra=['bankAccountId'=>$first['bankAccountId'],'bankName'=>$bank['bank'],'bankAccountTitle'=>$bank['title'],'currency'=>$cur,'bankDebit'=>0,'bankCredit'=>round($amount,2),'bankPaymentMethod'=>'ONLINE_BANKING','bankReference'=>$ref];
 $lines[]=tgr_line('1110',0,$amount*$rate,$names,$bankExtra);
 if($charge+$vat>0){$ccy=$chargeBank['currency'];$cb=tgr_bank_balance($s,$chargeBankId,$ccy);$cr=$ccy==='AED'?1:(float)$cb['carryingRate'];if($cr<=0)$cr=(float)($rates[$ccy]??0);if($cr<=0)throw new DomainException('Set the charge-bank exchange rate in Company Master.');
  if($charge>0)$lines[]=tgr_line('6800',$charge*$cr,0,$names,['currency'=>$ccy,'nativeDebit'=>$charge,'nativeCredit'=>0,'chargeType'=>'OUTGOING REMITTANCE CHARGE']);
  if($vat>0)$lines[]=tgr_line('1140',$vat*$cr,0,$names,['currency'=>$ccy,'nativeDebit'=>$vat,'nativeCredit'=>0,'chargeType'=>'BANK CHARGE INPUT VAT']);
  $lines[]=tgr_line('1110',0,($charge+$vat)*$cr,$names,['bankAccountId'=>$chargeBankId,'bankName'=>$chargeBank['bank'],'bankAccountTitle'=>$chargeBank['title'],'currency'=>$ccy,'bankDebit'=>0,'bankCredit'=>$charge+$vat,'bankPaymentMethod'=>'ONLINE_BANKING','bankReference'=>$ref]);
 }
 $difference=round(array_sum(array_column($lines,'credit'))-array_sum(array_column($lines,'debit')),2);
 if($difference>0)$lines[]=tgr_line('7100',$difference,0,$names,['fxDirection'=>'Loss']);elseif($difference<0)$lines[]=tgr_line('7100',0,-$difference,$names,['fxDirection'=>'Gain']);
 $invoices=array_values(array_unique(array_filter($invoices)));$text='TG REMITTANCE TO '.$first['counterparty'].' · '.$cur.' '.number_format($amount,2,'.',',').' · CREDIT ADVICES '.implode(', ',$refs).($invoices?' · INVOICES '.implode(', ',$invoices):' · ADVANCE');
 $record=['id'=>$rid,'bankAccountId'=>$first['bankAccountId'],'bankReference'=>$ref,'currency'=>$cur,'amountNative'=>round($amount,2),'date'=>$date,'counterparty'=>$first['counterparty'],'receiptIds'=>array_values(array_unique($receiptIds)),'draftIds'=>$ids,'invoiceRefs'=>$invoices,'bankChargeNative'=>$charge,'bankVatNative'=>$vat,'chargeBankAccountId'=>$chargeBankId,'chargeCurrency'=>$chargeBank['currency']??$cur,'reason'=>$reason,'legacyReversalPostIds'=>$legacy,'status'=>'Posted','createdAt'=>gmdate('c'),'createdBy'=>(string)($u['full_name']??$u['username']??'Accounts')];
 $meta=$record+['tgRemittanceId'=>$rid,'tgBankTransactionId'=>$txid,'kind'=>'Payment','liabilityAllocations'=>$liabilityAllocations,'bankPaymentMethod'=>'ONLINE_BANKING'];
 $j=tgr_journal($s,$u,$date,'TG_REMITTANCE_CONFIRMED',$ref?:$rid,$text,$lines,$meta);$record['journalId']=$j['id'];$s['tgRemittances'][$rid]=$record;
 $s['tgBankTransactions'][$txid]=$record+['kind'=>'Payment','paymentType'=>'REMITTANCE','bank'=>$bank['bank'],'journalId'=>$j['id'],'liabilityAllocations'=>$liabilityAllocations,'bankDebitNative'=>round($amount+(($chargeBankId===$first['bankAccountId'])?$charge+$vat:0),2),'notes'=>'CONFIRMED REMITTANCE '.$rid];
 foreach($chosen as $x)if(empty($x['legacy'])){$s['tgRemittanceDrafts'][$x['id']]['status']='Posted';$s['tgRemittanceDrafts'][$x['id']]['remittanceId']=$rid;}
 foreach($record['receiptIds'] as $id){$s['exportReceipts'][$id]['tgRemittanceId']=$rid;$s['exportReceipts'][$id]['tgRemittancePostId']=$j['id'];$pj=$s['exportReceipts'][$id]['journalId'];$s['journals'][$pj]['meta']['tgRemittanceId']=$rid;$s['journals'][$pj]['meta']['tgPostIds']=[$j['id']];}
 foreach((array)($s['tgBankTransactions']??[]) as $id=>$tx)if(isset($legacy[$tx['journalId']??''])){$s['tgBankTransactions'][$id]['status']='Reversed for Amendment';$s['tgBankTransactions'][$id]['reversalJournalId']=$legacy[$tx['journalId']];}
 $s['tgRemittances'][$rid]['reviewSources']=$chosen;
 $s['tgRemittanceRequests'][$key]=['hash'=>$hash,'id'=>$rid];$s['workflowAudit'][]=['type'=>'TG_REMITTANCE','action'=>'POST','id'=>$rid,'user'=>$record['createdBy'],'at'=>gmdate('c'),'sourceIds'=>$ids,'reason'=>$reason];return $record;
}

/** A reasoned correction reverses the complete remittance and returns its principal to review. */
function tgr_reopen(array &$s,array $b,array $u):array {
 $id=(string)($b['remittanceId']??'');$r=$s['tgRemittances'][$id]??null;$reason=strtoupper(trim((string)($b['reason']??'')));$date=tgr_date((string)($b['date']??''));
 if(!$r||($r['status']??'')!=='Posted')throw new DomainException('This remittance has already changed. Reopen its current review.');
 if(strlen($reason)<5)throw new DomainException('Enter a valid reason for reopening this remittance.');
 if($date<$r['date'])throw new DomainException('Correction date cannot precede the original TG remittance.');
 $old=$s['journals'][$r['journalId']]??null;if(!$old||!empty($old['amendedByPostId']))throw new DomainException('This remittance has another ledger correction; review that Post ID first.');
 foreach((array)$s['journals'] as $j)if(($j['reversalOf']??'')===$r['journalId'])throw new DomainException('This remittance is already reversed.');
 $drafts=[];
 foreach((array)($r['reviewSources']??[]) as $source){$parts=[];
  foreach($source['allocations'] as $a){if(!isset($a['legacyJournalId'])){$parts[]=$a;continue;}$original=$s['journals'][$a['legacyJournalId']];$native=(float)$original['meta']['amountNative'];foreach($original['lines'] as $l)if(!in_array($l['account'],['1110','7100'],true))$parts[]=['targetAccount'=>$l['account'],'sourceLiabilityId'=>(string)($l['sourceLiabilityId']??''),'foreignAmount'=>$native,'payableRate'=>(float)$l['debit']/max(.01,$native)];}
  $did=tgr_id((array)($s['tgRemittanceDrafts']??[]),'TGRD');$source['id']=$did;$source['legacy']=false;$source['status']='Pending';$source['allocations']=$parts;$source['version']=1;$source['date']=$date;$source['originalRemittanceDate']=$r['date'];$source['reopenedFrom']=$id;$source['reopenReason']=$reason;unset($source['fingerprint']);$s['tgRemittanceDrafts'][$did]=$source;$drafts[]=$did;
 }
 if(!$drafts)throw new DomainException('The original remittance sources are unavailable.');
 $lines=[];foreach($old['lines'] as $l){$q=$l;$q['debit']=$l['credit'];$q['credit']=$l['debit'];foreach([['bankDebit','bankCredit'],['nativeDebit','nativeCredit']] as [$dr,$cr])if(isset($l[$dr])||isset($l[$cr])){$q[$dr]=$l[$cr]??0;$q[$cr]=$l[$dr]??0;}$lines[]=$q;}
 $rev=tgr_journal($s,$u,$date,'TG_REMITTANCE_AMENDMENT_REVERSAL',$old['id'],'REOPEN REMITTANCE '.$id.' · '.$reason,$lines,['tgRemittanceId'=>$id,'reason'=>$reason]);$s['journals'][$rev['id']]['reversalOf']=$old['id'];
 $s['tgRemittances'][$id]['status']='Reversed for Amendment';$s['tgRemittances'][$id]['reversalJournalId']=$rev['id'];$s['tgRemittances'][$id]['reopenReason']=$reason;
 foreach($r['receiptIds'] as $rid){unset($s['exportReceipts'][$rid]['tgRemittanceId'],$s['exportReceipts'][$rid]['tgRemittancePostId']);$s['exportReceipts'][$rid]['tgMirrorPostIds']=[];$pj=$s['exportReceipts'][$rid]['journalId'];unset($s['journals'][$pj]['meta']['tgRemittanceId']);$s['journals'][$pj]['meta']['tgPostIds']=[];foreach($drafts as $did)if(in_array($rid,$s['tgRemittanceDrafts'][$did]['receiptIds'],true))$s['exportReceipts'][$rid]['tgRemittanceDraftId']=$did;}
 foreach((array)($s['tgBankTransactions']??[]) as $txid=>$tx)if(($tx['journalId']??'')===$old['id']){$s['tgBankTransactions'][$txid]['status']='Reversed for Amendment';$s['tgBankTransactions'][$txid]['reversalJournalId']=$rev['id'];}
 $s['workflowAudit'][]=['type'=>'TG_REMITTANCE','action'=>'REOPEN','id'=>$id,'reason'=>$reason,'actor'=>(string)($u['username']??''),'at'=>gmdate('c')];return ['id'=>$id,'reversalJournalId'=>$rev['id'],'draftIds'=>$drafts];
}
