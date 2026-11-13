<?php
require __DIR__.'/../../api/directors_trading_core.php';
require __DIR__.'/../../api/bank_entries_core.php';
require __DIR__.'/../../api/accounts_reports_core.php';
function tt_next_post_id(array $rows,...$args){return 'POST-'.(count($rows)+1);}
function check($v,$m){if(!$v)throw new RuntimeException($m);}
function closeTo($a,$b,$m){check(abs($a-$b)<.005,$m.' '.$a.' / '.$b);}
function blocked($f,$m){try{$f();}catch(DomainException){return;}throw new RuntimeException($m);}
$u=['id'=>1,'username'=>'fixture'];$d=dt_deal(['entity'=>'TTI','date'=>'2026-10-01','partyId'=>'HK','direction'=>'SALE','arrangement'=>'EX_MILL','currency'=>'PKR','price'=>109000,'priceUnit'=>'KG','quantityMT'=>100,'incoterm'=>'EX_MILL','billingWeightBasis'=>'GROSS','fx'=>1,'bagRatePKRPerKg'=>1.5],[['id'=>'HK','name'=>'HKM']])+['id'=>'DEAL','loadings'=>[],'settlementPosts'=>[],'payments'=>[]];
$s=['journals'=>[],'exporterDeals'=>[]];$native=[['ref'=>'DEAL|MILL|1','exporterDealId'=>'DEAL','entity'=>'TTI','displayName'=>'IRRI-6 — Emrooz','containers'=>[['id'=>'C1','date'=>'2026-10-02','g'=>25000,'net'=>24900]]]];$ops=serialize($native);
foreach([1000000,500000] as $i=>$amount)dt_advance($s,$d,['amountPKR'=>$amount,'bankId'=>'CASH|TTI','reference'=>'part-'.$i],$u,'2026-10-02');
$d['bagDeductionPKR']=37500;$d['bagDeductionLoadedMT']=25;$d['localReviewVersion']=2;$d['localReviewHash']=dt_local_review_hash($s,$d,dt_native_state($d,$native,'2026-10-02'));
$w=dt_working($s,$d,[],'2026-10-02',$native);check($w['costPending']&&$w['profitPKR']===null,'Never invent a zero cost profit');
$p=dt_post_settlement($s,$d,$w,$u,'2026-10-02','Final quantities reviewed');$s['exporterDeals']['DEAL']=$d;
closeTo(dt_book_balance($s,'DEAL','2026-10-02'),1187500,'Partial receipts reduce final net bill');
$report=ar_build_report($s,json_decode(file_get_contents(__DIR__.'/../../accounts/accounting_master_v1.json'),true),'TTI','2026-10-01','2026-10-02',null,'pl');check($report['profitAndLoss']['profit']===null&&count($report['profitAndLoss']['warnings'])>0,'P&L suppresses incomplete profit');
check(count(sc_pending_warnings($s,'2026-10-02','TTI'))===1,'Pending cost in reports');check(!sc_pending_warnings($s,'2026-10-01','TTI'),'No future warning');check(!sc_pending_warnings($s,'2026-10-02','BRM'),'Company isolation');
$lines=$s['journals'][$p['postId']]['lines'];check(!in_array('5100',array_column($lines,'account')),'No unapproved COGS');
$netAccount=function($code)use(&$s){$v=0;foreach($s['journals'] as $j)foreach($j['lines'] as $l)if($l['account']===$code)$v+=$l['debit']-$l['credit'];return $v;};
closeTo($netAccount('4200'),-2725000,'Full gross sale value');closeTo($netAccount('5300'),37500,'Bags once');
blocked(fn()=>dt_post_settlement($s,$d,$w,$u,'2026-10-02','Reviewed'),'Duplicate bill blocked');
// Full gross payment after partial receipts creates the bags credit for refund.
dt_advance($s,$d,['amountPKR'=>1225000,'bankId'=>'CASH|TTI','reference'=>'full-rice'],$u,'2026-10-02');closeTo(dt_book_balance($s,'DEAL','2026-10-02'),-37500,'Full receipt creates refundable credit');
dt_payment($s,$d,['amountPKR'=>37500,'bankId'=>'CASH|TTI','reference'=>'bags-refund'],$u,'2026-10-02');closeTo(dt_book_balance($s,'DEAL','2026-10-02'),0,'Refund settles account');$summary=sc_payment_summary($s,'DEAL','2026-10-02');closeTo($summary['receivedPKR'],2725000,'All receipts');closeTo($summary['refundedPKR'],37500,'Refund separately visible');
// Approved production cost can arrive later without invalidating commercial review.
$s['inventoryCostRates']=[['id'=>'R1','entity'=>'TTI','product'=>'IRRI-6 — Emrooz','effectiveFrom'=>'2026-10-01','costPerKg'=>90,'inventoryAccount'=>'1320']];$w=dt_working($s,$d,[],'2026-10-03',$native);check($w['rows'][0]['expensesComplete'],'Cost approval does not invalidate final quantities');$c=dt_post_local_cost($s,$d,$w,$u,'2026-10-03');$s['exporterDeals']['DEAL']=$d;
closeTo($netAccount('5100'),2250000,'Approved COGS uses gross stock quantity');closeTo($netAccount('4200'),-2725000,'No repeat revenue');closeTo($netAccount('5300'),37500,'No repeat bags');check(!sc_pending_warnings($s,'2026-10-03'),'Pending clears after cost posted');check(count(sc_pending_warnings($s,'2026-10-02'))===1,'Historical report retains then pending status');
blocked(fn()=>dt_post_local_cost($s,$d,$w,$u,'2026-10-03'),'Duplicate cost rejected');
$s['inventoryCostRates'][0]['costPerKg']=92;$w=dt_working($s,$d,[],'2026-10-04',$native);dt_post_local_cost($s,$d,$w,$u,'2026-10-04');closeTo($netAccount('5100'),2300000,'Cost correction posts delta only');
// Losing a rate must never reverse already posted cost when billing an adjustment.
$s['inventoryCostRates']=[];$d['bagDeductionPKR']=37000;$d['localReviewHash']=dt_local_review_hash($s,$d,dt_native_state($d,$native,'2026-10-04'));$w=dt_working($s,$d,[],'2026-10-04',$native);dt_post_settlement($s,$d,$w,$u,'2026-10-04','Reviewed correction');closeTo($netAccount('5100'),2300000,'Missing rate does not reverse historical cost');
$bad=$w;$bad['ownStockCost']['issues'][]='This Mill sale already has a local-sale posting.';blocked(fn()=>dt_post_settlement($s,$d,$bad,$u,'2026-10-04'),'Duplicate-source blocker retained');
$s['inventoryCostRates']=[['entity'=>'TTI','product'=>'IRRI-6 — Emrooz','effectiveFrom'=>'2026-10-01','costPerKg'=>92,'inventoryAccount'=>'1320']];$w=dt_working($s,$d,[],'2026-10-04',$native);$journalCount=count($s['journals']);dt_post_local_cost($s,$d,$w,$u,'2026-10-04');$s['exporterDeals']['DEAL']=$d;check(!sc_pending_warnings($s,'2026-10-04'),'Resolved unchanged approved cost clears warning');check(count($s['journals'])===$journalCount,'No empty correction journal');
$native[0]['containers'][]=['id'=>'C2','date'=>'2026-10-04','g'=>1000,'net'=>990];$s['inventoryCostRates']=[['entity'=>'TTI','product'=>'IRRI-6 — Emrooz','effectiveFrom'=>'2026-10-01','costPerKg'=>92,'inventoryAccount'=>'1320']];$w=dt_working($s,$d,[],'2026-10-04',$native);blocked(fn()=>dt_post_local_cost($s,$d,$w,$u,'2026-10-04'),'Cost-only action cannot bill new deliveries');array_pop($native[0]['containers']);check(serialize($native)===$ops,'Financial operations never mutate physical stock');
echo "PASS partial receipts, final bill with pending cost, full receipt/refund, dated pending reports, cost catch-up/correction, duplicate guards and unchanged stock\n";

// Bank > Receive money uses receiptKind metadata, including corrected historical receipts.
$first=array_key_first($s['journals']);$s['journals'][$first]['sourceType']='BANK_RECEIPT';$s['journals'][$first]['meta']['receiptKind']='FELLOW_EXPORTER';
closeTo(sc_payment_summary($s,'DEAL','2026-10-04')['receivedPKR'],2725000,'Include existing bank receipt route');
$reversal=$s['journals'][$first];$reversal['id']='REV';$reversal['reversalOf']=$first;$reversal['date']='2026-10-05';foreach($reversal['lines'] as &$line){$dr=$line['debit'];$line['debit']=$line['credit'];$line['credit']=$dr;}unset($line);$s['journals']['REV']=$reversal;
closeTo(sc_payment_summary($s,'DEAL','2026-10-05')['receivedPKR'],1725000,'Reversal reduces receipts, not a new refund');closeTo(sc_payment_summary($s,'DEAL','2026-10-04')['receivedPKR'],2725000,'Receipt reversal respects report date');
echo "PASS bank-entry receipts and dated reversals in payment summary\n";
