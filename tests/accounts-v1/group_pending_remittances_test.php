<?php
declare(strict_types=1);
require __DIR__.'/../../api/group_management_core.php';
function pending_check(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
$post=['id'=>'PK1','entity'=>'TTI','status'=>'Posted','date'=>'2026-07-07','lines'=>[['account'=>'1240','debit'=>0,'credit'=>100]]];
$draft=['id'=>'TGRD1','status'=>'Pending','date'=>'2026-07-07','currency'=>'USD','amountNative'=>100,'pakJournalIds'=>['PK1']];
$state=['revision'=>7,'journals'=>['PK1'=>$post],'tgRemittanceDrafts'=>['TGRD1'=>$draft,'FUTURE'=>array_replace($draft,['id'=>'FUTURE','date'=>'2026-11-01']),'POSTED'=>array_replace($draft,['id'=>'POSTED','status'=>'Posted'])]];
$before=serialize($state);$result=gm_pending_tg_remittances($state,'2026-10-18');
pending_check(count($result['rows'])===1&&$result['totals']===['USD'=>100.0]&&!$result['warnings'],'Only original pending through cutoff is shown');
pending_check($result['rows'][0]['pakJournalIds']===['PK1']&&$result['rows'][0]['status']==='Awaiting TG bank confirmation','Original advice and pending status retained');
$source=gm_financial_source($state,'2026-10-18');
pending_check($source['pendingTgRemittances']===$result&&count($source['rows'])===1&&$source['rows'][0]['amount']===-100.0,'Pending evidence never deducts money from books');
pending_check(count($source['warnings'])===2,'Intercompany and pending bank-confirmation holds remain visible');
$ops=['values'=>['transtrade_export_v3_operational'=>'{"contracts":[],"shipments":[]}']];require __DIR__.'/../../api/group_daily_core.php';
$daily=gd_sources($state,$ops,'2026-10-18');pending_check(!gd_positions($daily,['buckets'=>[]])['complete'],'Pending confirmation cannot complete group reconciliation');
$bad=$state;$bad['tgRemittanceDrafts']['TGRD1']['pakJournalIds']=['MISSING'];$held=gm_pending_tg_remittances($bad,'2026-10-18');pending_check(!$held['rows']&&$held['warnings'],'Missing advice is held without guessing');
$bad=$state;$bad['tgRemittanceDrafts']['TGRD1']['date']='2026-02-30';pending_check(gm_pending_tg_remittances($bad,'2026-10-18')['warnings']!==[],'Invalid original date is held');
$bad=$state;$bad['tgRemittanceDrafts']['TGRD1']['amountNative']=-1;pending_check(gm_pending_tg_remittances($bad,'2026-10-18')['warnings']!==[],'Invalid amount is held');
$duplicate=$state;$duplicate['tgRemittanceDrafts']['COPY']=$draft;$held=gm_pending_tg_remittances($duplicate,'2026-10-18');pending_check($held['totals']===['USD'=>100.0]&&$held['warnings'],'Repeated pending identities cannot inflate totals');
$settled=$state;$settled['tgRemittanceDrafts']['TGRD1']['status']='Posted';pending_check(!gm_pending_tg_remittances($settled,'2026-10-18')['rows'],'Explicitly posted original is no longer pending');
pending_check(serialize($state)===$before,'Projection changes no original records');
echo "PASS pending TG reconciliation: original linked advice, cutoff/status/invalid-source holds, no invented bank/payable settlement, blocked completion and zero source writes\n";
