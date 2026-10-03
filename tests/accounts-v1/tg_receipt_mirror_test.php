<?php
declare(strict_types=1);
function tt_next_post_id(array $existing, string $module = 'Accounts', string $area = 'Journal', ?string $date = null): string {
    $year=substr($date ?: date('Y-m-d'),0,4);
    $n=count($existing)+1;
    do {$id='POST-'.$year.'-'.str_pad((string)$n,5,'0',STR_PAD_LEFT);$n++;} while (isset($existing[$id]));
    return $id;
}
function er_respond(array $v,int $status=200):never{throw new DomainException($v['error']??'unexpected');}
function er_bank_master(string $id,string $entity):array{return ['id'=>$id,'accountType'=>'Company Account','accountTitle'=>'TG USD','bankName'=>'TG BANK','currency'=>'USD','masterStatus'=>'Active','accountNumber'=>'123','iban'=>''];}
function tt_company_fx_rate(string $entity,string $from,string $to):?float{return 3.67;}
require dirname(__DIR__,2).'/api/export_receipt_tg_mirror.php';
require dirname(__DIR__,2).'/api/fi_credit_advice_link.php';
function check(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
$s=['journals'=>[],'exportReceipts'=>[],'exportCandidates'=>[]];$names=array_fill_keys(['1110','1250','2500','7100','6800','1140'],'ACCOUNT');$banks=['USD'=>['id'=>'USD','bank'=>'TG BANK','title'=>'USD BANK','currency'=>'USD'],'AED'=>['id'=>'AED','bank'=>'TG BANK','title'=>'AED BANK','currency'=>'AED']];
foreach([108000,32000] as $i=>$amount){$rid='ER-'.$i;$pj='PK-'.$i;$s['journals'][$pj]=['id'=>$pj,'entity'=>'TTI','status'=>'Posted','meta'=>['receiptId'=>$rid],'narration'=>'CREDIT ADVICE'];$alloc=[['targetType'=>'UNAPPLIED_TG','foreignAmount'=>$amount]];
 $draft=er_mirror_tg_receipt($s,[],['remitter'=>'TG','tgBankAccountId'=>'USD'],$alloc,$names,$rid,$pj,'TTI','2026-10-01','USD','ADVICE-'.$i)[0]['draft'];
 $s['exportReceipts'][$rid]=['id'=>$rid,'entity'=>'TTI','remitter'=>'TG','date'=>'2026-10-01','foreignAmount'=>$amount,'transactionCurrency'=>'USD','journalId'=>$pj,'bankAdviceRef'=>'ADVICE-'.$i,'allocations'=>$alloc,'status'=>'Accounts Approved / Posted','tgRemittanceDraftId'=>$draft['id']];
}
check(count($s['journals'])===2,'Pending TG advices must not create TG journals');$balance=tgr_bank_balance($s,'USD','USD');check($balance['posted']===0.0&&$balance['pending']===140000.0&&$balance['available']===-140000.0,'Pending principal reduces available balance without a funds block');
$items=tgr_items($s);$body=['ids'=>array_column($items,'id'),'fingerprints'=>array_combine(array_column($items,'id'),array_map('tgr_fingerprint',$items)),'requestKey'=>'fixture-request-123456','date'=>'2026-10-01','bankReference'=>'SWIFT-140000','chargeBankAccountId'=>'USD','chargeAmount'=>30,'vatAmount'=>1.5,'sameRemittanceConfirmed'=>true];
$r=tgr_confirm($s,$body,['username'=>'FIXTURE'],$banks,$names,['USD'=>3.67,'AED'=>1]);$j=$s['journals'][$r['journalId']];
check($j['totalDebit']===$j['totalCredit'],'Confirmed principal and fee/VAT balance in AED');check(tgr_bank_balance($s,'USD','USD')['posted']===-140031.5,'Bank reduces by 140000 PLUS charge and VAT exactly once');check(tgr_reserved($s,'USD')===0.0,'Posting releases its pending reserve');check(count($s['tgRemittances'])===1&&count($s['tgBankTransactions'])===1,'Two receipts combine into one TG remittance');
$count=count($s['journals']);check(tgr_confirm($s,$body,[],$banks,$names,['USD'=>3.67])['id']===$r['id']&&count($s['journals'])===$count,'Idempotent retry cannot deduct again');
try{tgr_confirm($s,$body+['extra'=>'changed'],[],$banks,$names,['USD'=>3.67]);throw new RuntimeException('changed retry accepted');}catch(DomainException $e){}
check($s['exportReceipts']['ER-0']['tgRemittanceId']===$s['exportReceipts']['ER-1']['tgRemittanceId'],'Both Pakistan receipts keep links to one TG post');
$corrected=$s;$before=tgr_bank_balance($corrected,'USD','USD')['available'];
$opened=tgr_reopen($corrected,['remittanceId'=>$r['id'],'date'=>'2026-10-02','reason'=>'CORRECT BANK CHARGE'],[]);
check(tgr_reserved($corrected,'USD')===140000.0&&tgr_bank_balance($corrected,'USD','USD')['posted']===0.0,'Reopening reverses complete journal and restores pending principal');
check(empty($corrected['exportReceipts']['ER-0']['tgRemittanceId'])&&count($opened['draftIds'])===2,'Reasoned reopening releases both receipt links together');
$review=tgr_items($corrected);$repost=$body;$repost['date']='2026-10-02';$repost['requestKey']='corrected-request-123456';$repost['ids']=array_column($review,'id');$repost['fingerprints']=array_combine(array_column($review,'id'),array_map('tgr_fingerprint',$review));
$new=tgr_confirm($corrected,$repost,[],$banks,$names,['USD'=>3.67,'AED'=>1]);check($new['bankReference']==='SWIFT-140000'&&tgr_bank_balance($corrected,'USD','USD')['posted']===-140031.5,'Corrected remittance reuses its bank reference without doubling money');
// Exact matched FI utilisation adds invoice number and amount only after Customs is saved.
$receipt=$s['exportReceipts']['ER-0'];$receipt['date']='2026-10-01';$receipt['remitter']='TG';$receipt['foreignAmount']=108000;$s['exportReceipts']['ER-0']=$receipt;
$root=['fi'=>[['id'=>'FI1','number'=>'FI-108','date'=>'2026-10-01','customer'=>'TG','exporter'=>'TTI','currency'=>'USD','value'=>108000,'allocations'=>[['id'=>'A1','lotId'=>'LOT1','contractRef'=>'TG/C1','amount'=>50000]]]],'shipments'=>[['id'=>'LOT1','seller'=>'TG','contractRef'=>'TG/C1','customs'=>['saved'=>true,'invoiceNo'=>'CUSTOM-1','currency'=>'USD','exporter'=>'TTI','fiAllocations'=>[['fiId'=>'FI1','allocationId'=>'A1','amount'=>50000]]],'tgdocs'=>['saved'=>true,'customsInvoiceNo'=>'TG-PACK-1','currency'=>'USD','exporter'=>'TTI']]]];
$projected=tt_fi_advice_project($s,$root);check(str_contains($projected['journals'][$r['journalId']]['narration'],'TG-PACK-1 USD 50,000.00'),'A utilized advance updates the grouped TG narration with exact invoice and amount');check($projected['journals'][$r['journalId']]['lines']===$j['lines'],'Narration linking never changes financial lines');
$root['shipments'][0]['customs']['saved']=false;$again=tt_fi_advice_project($projected,$root);check(!str_contains($again['journals'][$r['journalId']]['narration'],'TG-PACK-1'),'Unsaved or reversed utilisation is removed from generated narration');
$direct=$s;$direct['exportReceipts']['ER-0']['allocations']=[['targetType'=>'EXPORT_RECEIVABLE','targetId'=>'DIRECT-INVOICE','invoiceRef'=>'LOT-OLD','foreignAmount'=>108000]];$direct['exportCandidates']['DIRECT-INVOICE']=['meta'=>['commercialInvoiceNo'=>'CI-ACTUAL-108']];
$direct=tt_fi_advice_project($direct,[]);check(str_contains($direct['journals']['PK-0']['narration'],'CI-ACTUAL-108'),'A direct receipt narration uses the actual known invoice number');
// Legacy mirror: principal is already deducted. Consolidation reverses it and posts once.
$old=['journals'=>['PK'=>['id'=>'PK','entity'=>'TTI','status'=>'Posted','meta'=>[]],'TGOLD'=>['id'=>'TGOLD','entity'=>'TG','status'=>'Posted','date'=>'2026-10-01','meta'=>['receiptId'=>'EROLD','currency'=>'USD','amountNative'=>100],'lines'=>[['account'=>'1250','debit'=>367,'credit'=>0],['account'=>'1110','bankAccountId'=>'USD','bankName'=>'TG BANK','currency'=>'USD','bankDebit'=>0,'bankCredit'=>100,'debit'=>0,'credit'=>367]]]],'exportReceipts'=>['EROLD'=>['id'=>'EROLD','entity'=>'TTI','remitter'=>'TG','date'=>'2026-10-01','transactionCurrency'=>'USD','foreignAmount'=>100,'journalId'=>'PK','bankAdviceRef'=>'OLD','status'=>'Accounts Approved / Posted','tgMirrorPostIds'=>['TGOLD'],'allocations'=>[['targetType'=>'UNAPPLIED_TG','foreignAmount'=>100]]]],'tgBankTransactions'=>['OLDTX'=>['id'=>'OLDTX','kind'=>'Payment','amountNative'=>100,'journalId'=>'TGOLD','bankAccountId'=>'USD']]];
$item=tgr_items($old)[0];check($item['legacy']&&tgr_reserved($old,'USD')===0.0,'Already posted mirrors have no additional pending reserve');$b=['ids'=>[$item['id']],'fingerprints'=>[$item['id']=>tgr_fingerprint($item)],'requestKey'=>'legacy-request-123456','date'=>'2026-10-01','bankReference'=>'','chargeBankAccountId'=>'AED','chargeAmount'=>10,'vatAmount'=>.5,'sameRemittanceConfirmed'=>true,'reason'=>'COMBINE OLD MIRROR'];
$posted=tgr_confirm($old,$b,[],$banks,$names,['USD'=>3.67,'AED'=>1]);check(tgr_bank_balance($old,'USD','USD')['posted']===-100.0&&tgr_bank_balance($old,'AED','AED')['posted']===-10.5,'Legacy review keeps original USD debit and adds fees only to the actual charge bank');check(count($posted['legacyReversalPostIds'])===1,'Legacy correction retains reversal audit');$reverse=$old['journals'][array_values($posted['legacyReversalPostIds'])[0]];check($reverse['meta']['currency']==='USD'&&$reverse['meta']['amountNative']===100.0,'Legacy reversal retains native currency so the USD party ledger cancels the old payment');
echo "TG pending, grouped principal plus charges/VAT, overdraft, idempotency, exact invoice utilisation and legacy consolidation passed.\n";
