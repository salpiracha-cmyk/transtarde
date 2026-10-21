<?php
declare(strict_types=1);
function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function tt_company_fx_rate(string $e,string $from,string $to):?float{return $e==='TG'&&$from==='USD'&&$to==='AED'?3.67:null;}
function tt_company_vat_rate(string $e,float $default=5):float{return 7.5;}
function tt_bank_can_transact(string $bank):bool{return true;}
function tt_list_masters():array{return ['banks'=>[['id'=>'TGUSD','values'=>['Company Account','TG','','TG USD','TG BANK','','','USD','123','','','','','Active']]]];}
function tt_next_post_id(array $rows,string $module='Accounts',string $area='Journal',?string $date=null):string{return 'POST-2026-'.str_pad((string)(count($rows)+1),5,'0',STR_PAD_LEFT);}
require __DIR__.'/../../api/bank_entries_core.php';
$store=['journals'=>[]];
$setup=sac_master($store,'TG',['operation'=>'add','name'=>'Remittance fee','parentCode'=>'6800'],[]);$id=$setup['subaccountId'];
check(!array_filter(sac_accounts([],'TG'),static fn($r)=>$r['parentCode']==='6800'&&$r['active']),'TG must not offer Pakistan seeded charges');
check((bool)array_filter(sac_accounts([],'TTI'),static fn($r)=>$r['parentCode']==='6800'&&$r['active']),'TTI retains its charge setup');
try{sac_resolve($store,'TTI',$id);throw new RuntimeException('Cross-company category was accepted');}catch(DomainException $expected){}
$result=ben_post($store,'TG',['type'=>'BANK_CHARGE','bankId'=>'TGUSD','date'=>'2026-10-01','amount'=>100,'subaccountId'=>$id,'exchangeRate'=>999],[]);$j=$result['journal'];
check($j['totalDebit']===$j['totalCredit']&&$j['meta']['exchangeRate']===3.67,'Master rate, balanced journal and no manual FX override');
check($j['reference']===$j['id'],'Charge without bank reference uses its allocated Post ID');
check($j['meta']['bankVatNative']===7.5&&$j['meta']['bankDebitNative']===107.5,'VAT comes from company master and is included once');
$bank=array_values(array_filter($j['lines'],static fn($l)=>$l['account']==='1110'))[0];
check(tgn_line($j,$bank)['credit']===107.5,'USD bank debit retains native gross amount');
$before=serialize($j);$usd=tgn_present($j,'USD','2026-10-01');$aed=tgn_present($j,'AED','2026-10-01');
check(array_sum(array_column($usd['lines'],'debit'))===107.5,'USD report uses fee plus master VAT');
check(round(array_sum(array_column($aed['lines'],'debit')),2)===394.53,'AED report translates at master rate');
check(serialize($j)===$before,'Report translation never mutates a saved journal');
check(tgn_line(['meta'=>['transactionCurrency'=>'USD','transactionAmount'=>123]],['account'=>'1250','debit'=>451.41,'credit'=>0])['debit']===123.0,'Legacy trade principal retains explicit transaction amount');
check(tgn_line(['meta'=>['currency'=>'USD']],['account'=>'6900','debit'=>100,'credit'=>0])['unavailable'],'Never invent a missing historical rate');
sac_master($store,'TG',['operation'=>'delete','id'=>$id],[]);
check(sac_resolve($store,'TG',$id,false)['active']===false&&isset($store['journals'][$j['id']]),'Deleting charge type archives it and preserves its posted ledger');
$shared=['journals'=>['PK'=>['id'=>'PK','entity'=>'TTI'],'TG'=>['id'=>'TG','entity'=>'TG','meta'=>['receiptIds'=>['R']]],'OTHER'=>['id'=>'OTHER','entity'=>'TG']],'exportReceipts'=>['R'=>['journalId'=>'PK','tgRemittancePostId'=>'TG']]];
$before=serialize($shared);
check(tt_accounts_public_post($shared,$shared['journals']['TG'])==='PK','Linked remittance shares the Pakistan public Post ID');
check(tt_accounts_public_post($shared,$shared['journals']['PK'])==='PK','TTI retains the same public Post ID');
check(tt_accounts_public_post($shared,$shared['journals']['OTHER'])==='OTHER','Unrelated TG posting stays independent');
check(serialize($shared)===$before,'Sharing a public ID must never duplicate or modify financial entries');
echo "TG native ledgers, master VAT/rates, separate charge types, report-only translation and linked public IDs passed\n";
