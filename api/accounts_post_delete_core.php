<?php
declare(strict_types=1);
require_once __DIR__.'/supplier_opening_core.php';
require_once __DIR__.'/accounts_post_delete_tg.php';
require_once __DIR__.'/tg_native_currency.php';

/** Cancellation capabilities; linked workflows use their own reversal routines under the caller's lock. */
function apd_record_index(array $s):array { $records=[];foreach(['utilityPayments','generalExpenses','reimbursements','creditCardStatements','donations'] as $collection)foreach((array)($s[$collection]??[]) as $r)foreach(array_merge([$r['journalId']??'', $r['captureJournalId']??'', $r['statementJournalId']??''],(array)($r['postingJournalIds']??[])) as $post)if($post!=='')$records[$post]=true;return $records; }
/** Only the current invoice journal owns the live supplier obligation. */
function apd_expense_invoice(array $s,array $j):string {
    $id=(string)($j['meta']['expenseInvoiceId']??'');$bill=$s['supplierBills'][$id]??[];
    if($id!==''&&!empty($bill['expenseInvoice'])&&($bill['entity']??'')===($j['entity']??'')&&($bill['status']??'')==='Posted'&&($bill['journalId']??'')===($j['id']??''))return (string)$id;
    return '';
}
/** Reuse payment cancellation, including advance applications and cheque clearance. */
function apd_cancel_allocations(array &$s,array $u,string $entity,callable $matches,string $reason,array $path):void {
    foreach(array_keys((array)($s['supplierSettlements']??[])) as $sid){
        $payment=$s['supplierSettlements'][$sid];
        if(($payment['entity']??'')!==$entity||!in_array($payment['status']??'',['Posted','Approved / Posted','Approved'],true)||!$matches($payment))continue;
        $post=(string)($payment['journalId']??'');
        if(($payment['type']??'')==='Round JV Allocation')throw new DomainException('Payment '.$sid.' is a round JV allocation. Open Journal Vouchers and correct its original Post ID '.$post.' first. No deletion was saved.');
        if($post===''||!isset($s['journals'][$post]))throw new DomainException('Linked supplier payment '.$sid.' has no journal. Open Party Payments and repair that payment before deleting this posting.');
        apd_delete_working($s,$u,$post,['reason'=>$reason],$path);
    }
}
function apd_cancel_settlement(array &$s,array $u,string $entity,string $sid,string $date,string $reason,array $path):void {
    if(!defined('TT_SETTLEMENTS_FUNCTIONS_ONLY'))define('TT_SETTLEMENTS_FUNCTIONS_ONLY',true);require_once __DIR__.'/supplier_settlements.php';require_once __DIR__.'/payment_plan_core.php';
    $payment=$s['supplierSettlements'][$sid];$created=[];
    foreach((array)($s['supplierAdvances']??[]) as $aid=>$advance)if(($advance['entity']??'')===$entity&&($advance['journalId']??'')===($payment['journalId']??'')){
        $created[]=$aid;apd_cancel_allocations($s,$u,$entity,static fn($later)=>($later['id']??'')!==$sid&&($later['advanceId']??'')===$aid,$reason,$path);
    }
    pl_reverse($s,$u,$entity,$sid,$date,$reason);
    foreach($created as $aid){$s['supplierAdvances'][$aid]['status']='Cancelled';$s['supplierAdvances'][$aid]['availableAmount']=0.0;$s['supplierAdvances'][$aid]['allocatedAmount']=0.0;$s['supplierAdvances'][$aid]['correctionReason']=$reason;}
    foreach((array)($s['paymentPlans']??[]) as $pid=>$plan)foreach((array)($plan['groups']??[]) as $key=>$group)if(($group['settlementId']??'')===$sid){unset($s['paymentPlans'][$pid]['groups'][$key]['settlementId']);$s['paymentPlans'][$pid]['status']='Open';}
}
function apd_supported(array $s,array $j,?array $recordIndex=null):bool {
    if(!empty($j['reversalOf'])||!empty($j['reversedByPostId'])||!empty($j['meta']['reversedByPostId'])||!empty($j['meta']['deletedFromBooks']))return false;
    if(apd_tg_supported($j)||($j['sourceType']??'')==='SALARY_ALLOCATED_PAYMENT'||apd_expense_invoice($s,$j)!=='')return true;
    $records=$recordIndex??apd_record_index($s);if(isset($records[$j['id']]))return true;
    if(!empty($j['meta']['historicalBagBillId']))return true;
    return (($j['sourceType']??'')==='POST_AMENDMENT_CORRECTION'&&in_array($j['meta']['originalSourceType']??'',['JV','OPENING_BALANCE_BF'],true))||in_array($j['sourceType']??'',['JV','OPENING_BALANCE_BF','BANK_ENTRY','SALARY_ADVANCE'],true)||($j['sourceType']??'')==='FORWARD_BOOKING_SETTLEMENT'||!empty($j['meta']['investmentTransactionId'])||!empty($j['meta']['receiptId'])||!empty($j['meta']['tgRemittanceId'])||!empty($j['meta']['settlementId']);
}
function apd_delete_reason(array $s,array $j,array $u,?array $recordIndex=null):string {
    if(!empty($j['reversalOf']))return 'This is a cancellation/audit entry. It cannot be deleted again; use the original workflow to enter a new transaction.';
    if(!empty($j['reversedByPostId'])||!empty($j['meta']['reversedByPostId'])||!empty($j['meta']['deletedFromBooks']))return 'This posting has already been cancelled. Its history is retained for audit.';
    if(!function_exists('tt_post_correction_allowed')||!tt_post_correction_allowed($u,$j)||!tt_user_can_access_entity($u,(string)$j['entity'],'Edit'))return 'Company and original workflow Edit permission are required to delete this posting.';
    if(!apd_supported($s,$j,$recordIndex))return 'Cancel this linked posting in its originating workflow so its allocations, registers and related postings remain consistent.';
    return '';
}
function apd_delete(array &$s,array $u,string $id,array $b):array {
    $fingerprint=hash('sha256',json_encode([$s,$id,$b['reason']??''],JSON_THROW_ON_ERROR));
    if(!empty($b['deletionFingerprint'])&&!hash_equals($fingerprint,(string)$b['deletionFingerprint']))throw new DomainException('The posting or its links changed. Review the deletion impact again.');
    $working=$s;$result=apd_delete_working($working,$u,$id,$b);$impact=[];
    foreach($result['cancelledPostIds'] as $cancelled){$journal=$s['journals'][$cancelled];$lines=[];foreach($journal['lines'] as $line){$native=($journal['entity']??'')==='TG'?tgn_line($journal,$line):['currency'=>'PKR','debit'=>$line['debit'],'credit'=>$line['credit']];$lines[]=['account'=>$line['account'],'name'=>$line['subaccountName']??$line['accountName']??$line['account'],'party'=>$line['counterparty']??$line['subledger']??'','bankAccountId'=>$line['bankAccountId']??'','currency'=>$native['currency'],'debit'=>$native['debit'],'credit'=>$native['credit']];}$impact[]=['postId'=>tt_accounts_public_post($s,$journal),'date'=>$journal['date'],'entity'=>$journal['entity'],'source'=>$journal['sourceType'],'narration'=>$journal['narration']??'','lines'=>$lines];}
    $sources=[];foreach($working as $collection=>$records){if(in_array($collection,['journals','postDeletions'],true)||!is_array($records))continue;foreach($records as $key=>$record)if(is_array($record)&&isset($s[$collection][$key])&&$record!==$s[$collection][$key])$sources[]=['register'=>$collection,'id'=>(string)$key,'beforeStatus'=>$s[$collection][$key]['status']??'','afterStatus'=>$record['status']??'Updated','effect'=>'Linked balances and allocations recalculated'];}
    $s=$working;return $result+['fingerprint'=>$fingerprint,'impact'=>$impact,'sources'=>$sources];
}
function apd_delete_working(array &$s,array $u,string $id,array $b,array $path=[]):array {
    $j=$s['journals'][$id]??null;$seen=[];while(is_array($j)&&!empty($j['amendedByPostId'])){if(isset($seen[$id]))throw new DomainException('The correction history needs review.');$seen[$id]=true;$id=$j['amendedByPostId'];$j=$s['journals'][$id]??null;}
    if(!is_array($j)||($j['status']??'')!=='Posted'||!apd_supported($s,$j))throw new DomainException('This posting has already been cancelled, or requires cancellation in its originating workflow.');
    $e=$j['entity'];if(!tt_post_correction_allowed($u,$j)||!tt_user_can_access_entity($u,$e,'Edit'))throw new DomainException('Original workflow and company Edit permission required.');
    $reason=trim((string)($b['reason']??''));if(mb_strlen($reason)<5||mb_strlen($reason)>300)throw new DomainException('Enter a deletion reason of 5 to 300 characters.');
    if(isset($path[$id]))throw new DomainException('The linked payment history contains a cycle at '.$id.'. Open Party Payments to repair its allocations before deleting.');$path[$id]=true;
    $before=$s;$journalIds=array_keys((array)$s['journals']);$date=$j['date'];$handled=false;
    if(($j['sourceType']??'')==='OPENING_BALANCE_BF'){
        $prefix='BF:'.$id.':';apd_cancel_allocations($s,$u,$e,static function($payment)use($prefix){foreach((array)($payment['allocations']??[]) as $a)if(str_starts_with((string)($a['billId']??''),$prefix))return true;return false;},$reason,$path);
        sop_assert_unpaid($s,$id);
    }
    $invoiceId=apd_expense_invoice($s,$j);
    if($invoiceId!==''){
        apd_cancel_allocations($s,$u,$e,static function($payment)use($invoiceId){foreach((array)($payment['allocations']??[]) as $a)if(($a['billId']??'')===$invoiceId)return true;return false;},$reason,$path);
        if(!defined('TT_EXPENSES_FUNCTIONS_ONLY'))define('TT_EXPENSES_FUNCTIONS_ONLY',true);require_once __DIR__.'/expenses_v1.php';
        eiv_cancel($s,$e,['invoiceId'=>$invoiceId,'expectedJournalId'=>$id,'reason'=>$reason],$u,ev1_names());$handled=true;
    }
    if(apd_tg_supported($j)){$seen=[];apd_tg_cancel($s,$u,$id,$reason,$seen);$handled=true;}
    if(!$handled&&($j['sourceType']??'')==='SALARY_ALLOCATED_PAYMENT'){if(!defined('TT_RENT_SALARY_FUNCTIONS_ONLY'))define('TT_RENT_SALARY_FUNCTIONS_ONLY',true);require_once __DIR__.'/rent_salary_v2.php';sfw_reverse_payment($s,$e,substr((string)$j['meta']['salarySheetKey'],strlen($e)+1),['journalId'=>$id,'date'=>$date,'reason'=>$reason],$u,rsv2_names());$handled=true;}
    foreach(['utilityPayments','generalExpenses','reimbursements','creditCardStatements','donations'] as $collection){foreach((array)($s[$collection]??[]) as $rid=>$r){if($handled||!in_array($id,array_merge([$r['journalId']??'', $r['captureJournalId']??'', $r['statementJournalId']??''],(array)($r['postingJournalIds']??[])),true))continue;
        if(!defined('TT_EXPENSES_FUNCTIONS_ONLY'))define('TT_EXPENSES_FUNCTIONS_ONLY',true);require_once __DIR__.'/expenses_v1.php';ev1_delete_expense($s,['collection'=>$collection,'id'=>$rid,'reason'=>$reason],$e,$u,ev1_names());$handled=true;break 2;
    }}
    if(!$handled&&!empty($j['meta']['investmentTransactionId'])){require_once __DIR__.'/company_investments_core.php';inv_reverse($s,$e,['transactionId'=>$j['meta']['investmentTransactionId'],'date'=>$date,'reason'=>$reason],$u);$handled=true;}
    if(!$handled&&!empty($j['meta']['historicalBagBillId'])){require_once __DIR__.'/historical_bag_core.php';hbg_cancel($s,$e,(string)$j['meta']['historicalBagBillId'],$reason,$u);$handled=true;}
    if(!$handled&&($j['sourceType']??'')==='FORWARD_BOOKING_SETTLEMENT'){require_once __DIR__.'/forward_booking_core.php';fwb_reverse_settlement($s,$e,(string)$j['meta']['forwardBookingId'],$reason,$u);$handled=true;}
    if(!$handled&&($j['sourceType']??'')==='BANK_ENTRY'){require_once __DIR__.'/assets_registry_core.php';require_once __DIR__.'/bank_entries_core.php';ben_reverse($s,$e,['journalId'=>$id,'date'=>$date,'reason'=>$reason],$u);$handled=true;}
    if(!$handled&&!empty($j['meta']['tgRemittanceId'])){require_once __DIR__.'/tg_remittance_core.php';tgr_reopen($s,['remittanceId'=>$j['meta']['tgRemittanceId'],'date'=>$date,'reason'=>$reason],$u);$handled=true;}
    if(!$handled&&!empty($j['meta']['receiptId'])&&isset($s['exportReceipts'][$j['meta']['receiptId']])){require_once __DIR__.'/accounts_receipt_amend_core.php';$receipt=$s['exportReceipts'][$j['meta']['receiptId']];foreach(array_unique(array_merge([$receipt['entity']],!empty($receipt['tgMirrorPostIds'])?['TG']:[])) as $company)if(!tt_user_can_access_entity($u,$company,'Edit'))throw new DomainException('Edit permission for every linked company is required.');er_reverse_receipt_store($s,$j['meta']['receiptId'],$receipt['entity'],$date,$reason,$u);$handled=true;}
    if(!$handled&&!empty($j['meta']['settlementId'])&&isset($s['supplierSettlements'][$j['meta']['settlementId']])){apd_cancel_settlement($s,$u,$e,(string)$j['meta']['settlementId'],$date,$reason,$path);$handled=true;}
    if(!$handled&&($j['sourceType']??'')==='SALARY_ADVANCE'){
        $aid=(string)($j['meta']['salaryAdvanceId']??'');$advance=$s['salaryAdvances'][$aid]??null;
        if(!is_array($advance)||($advance['journalId']??'')!==$id||($advance['entity']??'')!==$e)throw new DomainException('The salary advance source needs review before cancellation.');
        if(($advance['status']??'')==='Deleted')throw new DomainException('This salary advance has already been cancelled.');
        $applied=array_sum(array_map(static fn($a)=>(float)($a['amount']??0),(array)($advance['adjustments']??[])));
        if($applied>.005||abs((float)($advance['remaining']??0)-(float)($advance['amount']??0))>.005)throw new DomainException('Reverse the linked salary deductions before deleting this advance.');
        $s['salaryAdvances'][$aid]['remaining']=0;$s['salaryAdvances'][$aid]['monthsRemaining']=0;$s['salaryAdvances'][$aid]['status']='Deleted';$s['salaryAdvances'][$aid]['deletedAt']=gmdate('c');$s['salaryAdvances'][$aid]['deletedBy']=$u['username']??'';$s['salaryAdvances'][$aid]['deleteReason']=$reason;
    }
    if(!$handled){if(!in_array($j['sourceType']??'',['JV','OPENING_BALANCE_BF','POST_AMENDMENT_CORRECTION','SALARY_ADVANCE'],true))throw new DomainException('Cancel the linked record through its original workflow.');
        if(($j['sourceType']??'')==='POST_AMENDMENT_CORRECTION'&&!in_array($j['meta']['originalSourceType']??'',['JV','OPENING_BALANCE_BF'],true))throw new DomainException('Cancel the originating bill or payment first so its allocation history stays linked.');
        $reverse=$j;$reverseId=tt_next_post_id((array)$s['journals'],'Accounts','Journal',$date);$reverse['id']=$reverseId;$reverse['reversalOf']=$id;$reverse['sourceType']='POST_DELETION';$reverse['meta']=['originalPostId'=>$id,'deletionReason'=>$reason];$reverse['narration']='Deleted '.$id.' — '.$reason;$reverse['createdAt']=gmdate('c');$reverse['createdBy']=$u['full_name']??$u['username']??'';$reverse['userId']=$u['id']??0;unset($reverse['amendedByPostId'],$reverse['openingReversalId']);foreach($reverse['lines'] as &$line)foreach([['debit','credit'],['bankDebit','bankCredit'],['nativeDebit','nativeCredit']] as [$dr,$cr])if(isset($line[$dr])||isset($line[$cr]))[$line[$dr],$line[$cr]]=[$line[$cr]??0,$line[$dr]??0];unset($line);[$reverse['totalDebit'],$reverse['totalCredit']]=[$j['totalCredit'],$j['totalDebit']];$s['journals'][$reverseId]=$reverse;$s['journals'][$id]['reversedByPostId']=$reverseId;if(($j['sourceType']??'')==='OPENING_BALANCE_BF')$s['journals'][$id]['openingReversalId']=$reverseId;
        foreach((array)($s['jvDrafts']??[]) as $key=>$draft)if(($draft['journalId']??'')===$id||(!empty($draft['journalId'])&&(tt_accounts_current_journal($s,(string)$draft['journalId'])['id']??'')===$id)){$s['jvDrafts'][$key]['status']='Reversed';$s['jvDrafts'][$key]['reversalReason']=$reason;$s['jvDrafts'][$key]['reversedAt']=gmdate('c');$s['jvDrafts'][$key]['reversalJournalId']=$reverseId;}
    }
    $cancelled=[];$reversals=[];foreach(array_diff(array_keys($s['journals']),$journalIds) as $reverseId){$reverse=&$s['journals'][$reverseId];$originalId=$reverse['reversalOf']??$reverse['meta']['reversalOf']??null;if(!$originalId||!isset($s['journals'][$originalId]))continue;$cancelled[]=$originalId;$reversals[]=$reverseId;$s['journals'][$originalId]['meta']['deletedFromBooks']=true;$reverse['meta']['deletedFromBooks']=true;$reverse['date']=$s['journals'][$originalId]['date'];unset($reverse);}
    if(!$cancelled)throw new DomainException('The source did not produce a complete cancellation. No deletion was saved.');
    // Retain immutable audit snapshots separately; ordinary ledgers hide each fully neutralised pair.
    $s['postDeletions'][]=['postId'=>$id,'publicPostId'=>tt_accounts_public_post($before,$j),'at'=>gmdate('c'),'by'=>$u['username']??'','userId'=>$u['id']??0,'entity'=>$e,'reason'=>$reason,'cancelledPostIds'=>$cancelled,'reversalPostIds'=>$reversals,'before'=>array_intersect_key($before['journals'],array_flip($cancelled))];
    return ['publicPostId'=>tt_accounts_public_post($before,$j),'status'=>'Deleted','cancelledPostIds'=>$cancelled,'reversalPostIds'=>$reversals];
}
