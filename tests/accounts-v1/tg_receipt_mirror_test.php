<?php
declare(strict_types=1);
// Standalone accounting fixture: no authentication, live master data, or production writes.
function er_respond(array $message,int $status=200): never {throw new RuntimeException((string)($message['error']??'unexpected error'));}
function er_bank_master(string $id,string $entity): array {if($id!=='tg-usd'||$entity!=='TG')throw new RuntimeException('wrong bank');return ['id'=>$id,'accountType'=>'Company Account','accountTitle'=>'TG USD','bankName'=>'TG Bank','currency'=>'USD','masterStatus'=>'Active','accountNumber'=>'123','iban'=>''];}
function er_line(string $account,float $debit,float $credit,array $catalog,array $extra=[]): array {return array_merge(['account'=>$account,'debit'=>round($debit,2),'credit'=>round($credit,2)],$extra);}
function er_next_id(array $rows,string $prefix): string {return $prefix.'-2026-'.str_pad((string)(count($rows)+1),6,'0',STR_PAD_LEFT);}
function er_post_journal(array &$store,array $user,string $entity,string $date,string $sourceType,string $reference,string $narration,array $lines,array $meta): array {
    $debit=round(array_sum(array_column($lines,'debit')),2);$credit=round(array_sum(array_column($lines,'credit')),2);
    if(abs($debit-$credit)>.001)throw new RuntimeException('journal mismatch');
    $id=er_next_id($store['journals'],'AUTO');return $store['journals'][$id]=compact('id','entity','date','sourceType','reference','narration','lines','meta')+['status'=>'Posted','totalDebit'=>$debit,'totalCredit'=>$credit];
}
require dirname(__DIR__,2).'/api/export_receipt_tg_mirror.php';
function check(bool $test,string $message): void {if(!$test)throw new RuntimeException($message);}
$store=['journals'=>['AUTO-2026-000001'=>['entity'=>'TG','status'=>'Posted','lines'=>[['account'=>'1110','bankAccountId'=>'tg-usd','bankDebit'=>200.00,'bankCredit'=>0,'debit'=>734.00,'credit'=>0]]]],'tgBankTransactions'=>[],'exportCandidates'=>['pk-invoice'=>['mirrorCandidateId'=>'tg-payable'],'tg-payable'=>['entity'=>'TG','candidateType'=>'TG_INTERCOMPANY_PAYABLE','journalId'=>'AUTO-OLD','counterparty'=>'TTI','transactionAmount'=>100,'currentCarryingRate'=>3.60]]];
$store['journals']['AUTO-2026-000002']=['entity'=>'TTI','status'=>'Posted','meta'=>[]];
$catalog=array_fill_keys(['1110','1250','2500','7100'],[]);
$rows=er_mirror_tg_receipt($store,[],['remitter'=>'TG','tgBankAccountId'=>'tg-usd'],[['targetType'=>'INTERCOMPANY_RECEIVABLE','targetId'=>'pk-invoice','invoiceRef'=>'INV-1','foreignAmount'=>100]],$catalog,'ER-2026-000001','AUTO-2026-000002','TTI','2026-09-25','USD','BANK-1');
check(count($rows)===1,'invoice produces one TG payment');
$lines=$rows[0]['journal']['lines'];check($lines[0]['account']==='2500'&&$lines[0]['debit']===360.00,'posted payable is debited at its historic AED rate');
check($lines[1]['account']==='1110'&&$lines[1]['credit']===367.00&&$lines[1]['bankCredit']===100.00,'USD bank decreases by the native amount at its book rate');
check($lines[2]['account']==='7100'&&$lines[2]['debit']===7.00,'AED carrying difference has its own FX line');
check($store['journals']['AUTO-2026-000002']['meta']['tgPostIds']===[ $rows[0]['journal']['id'] ],'Pakistan Post ID links to TG Post ID');
$advance=er_mirror_tg_receipt($store,[],['remitter'=>'TG','tgBankAccountId'=>'tg-usd'],[['targetType'=>'UNAPPLIED_TG','targetId'=>'','foreignAmount'=>25]],$catalog,'ER-2026-000002','AUTO-2026-000002','TTI','2026-09-25','USD','BANK-2');
check($advance[0]['journal']['lines'][0]['account']==='1250','TG advance is a supplier advance rather than an invoice payable');
check(er_mirror_tg_receipt($store,[],['remitter'=>'TG','tgPaymentId'=>'TGBK-OLD'],[], $catalog,'ER-OLD','AUTO-OLD','TTI','2026-09-25','USD','BANK-OLD')===[],'previously posted TG payment is never deducted again');
echo "TG credit advice atomic mirror fixture passed.\n";
