<?php
declare(strict_types=1);

require dirname(__DIR__).'/auth_store.php';
require_once __DIR__.'/accounts_reference.php';
require_once __DIR__.'/accounts_subaccounts_core.php';
require_once __DIR__.'/tg_remittance_core.php';
require_once __DIR__.'/fi_credit_advice_link.php';
header('Cache-Control: no-store');

function alb_fail(string $message, int $status=422): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok'=>false,'error'=>$message]);
    exit;
}
function alb_date(string $value): string {
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    if(!$date||$date->format('Y-m-d')!==$value)alb_fail('Select a valid ledger date.');
    return $value;
}
function alb_cell(mixed $value): string {
    $text=(string)$value;
    // Spreadsheet applications must not interpret a party's text as a formula.
    return preg_match('/^[\s]*[=+\-@]/u',$text)?"'".$text:$text;
}
function alb_party_key(string $value): string {
    $value=trim((string)preg_replace('/\s+/u',' ',$value));
    return function_exists('mb_strtolower')?mb_strtolower($value,'UTF-8'):strtolower($value);
}
function alb_party_name(array $row): string {
    foreach(['supplier','broker','customer','counterparty','party','vendor','subledger'] as $field){
        $value=trim((string)($row[$field]??''));if($value!=='')return $value;
    }
    return '';
}
$user=tt_require_login();
if(!tt_user_can_open_module($user,'Accounts'))alb_fail('Accounts permission required.',403);
$entity=strtoupper(trim((string)($_GET['entity']??'')));
if(!in_array($entity,['TTI','BRM','TG','ALL'],true)||($entity==='ALL'&&!isset($_GET['account']))||($entity==='ALL'&&($_GET['account']??'')!=='POSTS')||($entity!=='ALL'&&!tt_user_can_access_entity($user,$entity,'View')))alb_fail('Company access denied.',403);
$from=alb_date((string)($_GET['from']??date('Y').'-01-01'));
$to=alb_date((string)($_GET['to']??date('Y-m-d')));
if($from>$to)alb_fail('From date cannot be later than To date.');
$account=trim((string)($_GET['account']??''));
$category=trim((string)($_GET['category']??'other'));if(!in_array($category,['party','supplier','customer','bank','other'],true))alb_fail('Select a valid ledger category.');
if($category==='party'&&$account!=='')alb_fail('Party Ledgers uses the party name, without an account-head selection.');
$nativeCurrency=$entity==='TG'?strtoupper(trim((string)($_GET['currency']??'AED'))):'';if($nativeCurrency!==''&&!in_array($nativeCurrency,['AED','USD'],true))alb_fail('Select AED books or USD transactions.');
$party=trim((string)($_GET['party']??''));$parties=[];
$query=strtolower(trim((string)($_GET['q']??'')));
$postEntries=$account==='POSTS';
$bankId=str_starts_with($account,'BANK|')?substr($account,5):'';
$headId=str_starts_with($account,'HEAD|')?substr($account,5):'';
$headCodes=[];
$subaccountId=str_starts_with($account,'SUB|')?substr($account,4):'';
$master=json_decode((string)file_get_contents(dirname(__DIR__).'/accounts/accounting_master_v1.json'),true);
$catalog=[];
// Party balances use receivables, payables, deposits and advances, never the
// matching bank, inventory, income or expense legs of the same journal.
$partyAccounts=array_fill_keys(['1210','1220','1230','1240','1250','1400','2110','2120','2130','2140','2150','2160','2170','2190','2210','2220','2400','2500','2510','2520','3100','3200'],true);
foreach((array)($master['peopleSubledgers']??[]) as $person)if(is_array($person)&&isset($person['code']))$partyAccounts[(string)$person['code']]=true;
$missingPartyLines=0;
$balanceUnavailable=false;
$partyMasters=tt_list_masters();$partyNames=[];
foreach(['business_parties','export_customers'] as $type)foreach((array)($partyMasters[$type]??[]) as $record){
    if(!is_array($record))continue;$name=trim((string)($record['values'][0]??''));
    if($name!==''){$partyNames[(string)($record['id']??$name)]=$name;$partyNames[alb_party_key($name)]=$name;}
}
foreach(array_merge((array)($master['chart']??[]),(array)($master['peopleSubledgers']??[])) as $row){
    if(is_array($row)&&isset($row['code']))$catalog[(string)$row['code']]=(string)($row['name']??$row['code']);
}
foreach(['settlement_policy_v1.json','export_realization_policy_v1.json'] as $policyFile){
    $path=dirname(__DIR__).'/accounts/'.$policyFile;
    $policy=is_file($path)?json_decode((string)file_get_contents($path),true):[];
    foreach((array)($policy['accounts']??[]) as $row)if(is_array($row)&&isset($row['code']))$catalog[(string)$row['code']]=(string)($row['name']??$row['code']);
}
$file=TT_DATA_DIR.'/accounts.json';
$store=tt_fi_advice_project(tt_fi_advice_read_json($file),tt_fi_advice_root());
$headCodes=$headId!==''?sac_descendants($headId,$store,$entity):[];
$subaccountIds=$subaccountId!==''?sac_subtree($store,$entity,$subaccountId):[];
$banks=[];
if($headId!==''&&isset($catalog[$headId]))$catalog[$account]='Head of Accounts · '.$catalog[$headId];
foreach(sac_accounts($store,$entity) as $subaccount)$catalog['SUB|'.$subaccount['id']]=$subaccount['name'].' · '.$subaccount['parentCode'];
foreach((array)(tt_list_masters()['banks']??[]) as $bank){
    if(!is_array($bank))continue;
    $v=(array)($bank['values']??[]);$linked=strtoupper((string)($v[1]??''));
    $belongs=$entity==='TG'?(str_contains($linked,'TRANS GRAINS')||preg_match('/(^|\W)TG($|\W)/',$linked)):
        ($entity==='BRM'?(str_contains($linked,'BUKSH RICE')||preg_match('/(^|\W)BRM($|\W)/',$linked)):
        (str_contains($linked,'TRANSTRADE INTERNATIONAL')||preg_match('/(^|\W)TTI($|\W)/',$linked)));
    if(!$belongs||!in_array(($v[0]??''),['Company Account','Proprietor / Owner Account','Personal Account'],true))continue;
    $id=(string)($bank['id']??'');if($id==='')continue;
    $banks[$id]=['code'=>'BANK|'.$id,'name'=>implode(' · ',array_filter([$v[4]??'Bank',(trim((string)($v[8]??''))!==''?(string)$v[8]:(string)($v[9]??'')),strtoupper((string)($v[7]??'')),$v[3]??''])),'currency'=>strtoupper((string)($v[7]??''))];
}
$catalog['POSTS']='Post ID Register';
foreach($banks as $bank)$catalog[$bank['code']]=$bank['name'];
$expenseActivity=$category==='party'?sac_expense_activity($store,$entity,$from,$to):[];
foreach($expenseActivity as $activity)if($activity['recipient']!=='')$parties[$activity['recipient']]=true;
$requestedPost=trim((string)($_GET['postId']??''));
if($requestedPost!==''){
    $posting=$store['journals'][$requestedPost]??null;
    if(!is_array($posting)||($posting['status']??'')!=='Posted'||($entity!=='ALL'&&($posting['entity']??'')!==$entity)||!tt_user_can_access_entity($user,(string)($posting['entity']??''),'View'))$posting=null;
    if(!$posting)alb_fail('Post ID was not found in these company books.',404);
    $posting['fiTagText']=tt_fi_advice_label((array)($posting['fiTag']??[]));
    $receipt=(array)($store['exportReceipts'][(string)($posting['meta']['receiptId']??'')]??[]);
    if($receipt){$posting['receiptAmendment']=['status'=>$receipt['status']??'','entity'=>$receipt['entity']??'','replacementReceiptId'=>$receipt['replacementReceiptId']??'','amendmentOf'=>$receipt['amendmentOf']??'','reversalPostIds'=>$receipt['reversalPostIds']??[]];}
    header('Content-Type: application/json; charset=UTF-8');
    $postBanks=[];
    foreach((array)(tt_list_masters()['banks']??[]) as $bank){
        if(!is_array($bank)||!in_array(($bank['values'][0]??''),['Company Account','Proprietor / Owner Account','Personal Account'],true))continue;
        $v=(array)($bank['values']??[]);$linked=strtoupper((string)($v[1]??''));$owner=(string)$posting['entity'];
        $belongs=$owner==='TG'?(str_contains($linked,'TRANS GRAINS')||preg_match('/(^|\W)TG($|\W)/',$linked)):
            ($owner==='BRM'?(str_contains($linked,'BUKSH RICE')||preg_match('/(^|\W)BRM($|\W)/',$linked)):
            (str_contains($linked,'TRANSTRADE INTERNATIONAL')||preg_match('/(^|\W)TTI($|\W)/',$linked)));
        if($belongs)$postBanks[]=['id'=>(string)($bank['id']??''),'label'=>implode(' · ',array_filter([$v[4]??'',(trim((string)($v[8]??''))!==''?(string)$v[8]:(string)($v[9]??'')),$v[7]??'',$v[3]??'']))];
    }
    echo json_encode(['ok'=>true,'entity'=>$posting['entity'],'post'=>$posting,'bankAccounts'=>$postBanks],JSON_UNESCAPED_UNICODE);exit;
}
$journals=array_values(array_filter((array)($store['journals']??[]),static fn($j)=>is_array($j)&&($entity==='ALL'?tt_user_can_access_entity($user,(string)($j['entity']??''),'View'):($j['entity']??'')===$entity)&&($j['status']??'')==='Posted'&&($j['date']??'')<=$to));
foreach($journals as $journal)foreach((array)($journal['lines']??[]) as $line)if(is_array($line)&&isset($line['account'])){
    $code=(string)$line['account'];if(!isset($catalog[$code]))$catalog[$code]=(string)($line['accountName']??$code);
}
if($account!==''&&!isset($catalog[$account]))alb_fail('Select an account from All Ledgers.');
ksort($catalog,SORT_NATURAL);
usort($journals,static fn($a,$b)=>strcmp((string)$a['date'],(string)$b['date'])?:strcmp((string)($a['id']??''),(string)($b['id']??'')));
$opening=0.0;$rows=[];
foreach($journals as $journal){
    if($postEntries){
        // Keep every journal in the books, while the user-facing Post ID Register
        // lists only postings that move money through bank or cash.
        $moneyMovement=false;
        foreach((array)($journal['lines']??[]) as $line){
            if(!is_array($line))continue;
            if(in_array((string)($line['account']??''),['1110','1120'],true)||!empty($line['bankAccountId'])||!empty($line['cashAccountId'])){$moneyMovement=true;break;}
        }
        if(!$moneyMovement)continue;
        $date=(string)$journal['date'];if($date<$from)continue;
        $rows[]=['date'=>$date,'voucher'=>(string)($journal['id']??''),'account'=>'POSTS','accountName'=>(string)$journal['entity'],'reference'=>(string)($journal['reference']??''),'narration'=>(string)($journal['narration']??'').(str_starts_with((string)($journal['meta']['notes']??''),'Mirrored settlement for Pakistan receipt ')?' · '.(string)$journal['meta']['notes']:''),'party'=>(string)($journal['sourceType']??'').(!empty($store['exportReceipts'][(string)($journal['meta']['receiptId']??'')]['replacementReceiptId'])?' · Corrected by '.$store['exportReceipts'][(string)$journal['meta']['receiptId']]['replacementReceiptId']:''),'debit'=>round((float)($journal['totalDebit']??0),2),'credit'=>round((float)($journal['totalCredit']??0),2),'balance'=>null];
        continue;
    }
    foreach((array)($journal['lines']??[]) as $line){
        if(!is_array($line))continue;
        $code=(string)($line['account']??'');
        $lineBank=(string)($line['bankAccountId']??$journal['meta']['bankAccountId']??'');
        if($bankId!==''&&($code!=='1110'||$lineBank!==$bankId))continue;
        if($headId!==''&&!in_array($code,$headCodes,true))continue;
        if($subaccountId!==''&&!in_array((string)($line['subaccountId']??''),$subaccountIds,true))continue;
        if($account!==''&&$bankId===''&&$subaccountId===''&&$headId===''&&$code!==$account)continue;
        $lineParty=trim((string)($line['supplier']??$line['broker']??$line['customer']??$line['counterparty']??$line['party']??''));
        $meta=(array)($journal['meta']??[]);
        $source=[];$bill=[];
        if($lineParty===''){$sourceId=(string)($meta['bagBillId']??$meta['billId']??$meta['purchaseId']??'');$source=$store['bagSupplierBills'][$sourceId]??$store['otherPurchases'][$sourceId]??$store['commodityBills'][$sourceId]??[];$lineParty=trim((string)($source['supplier']??$source['broker']??$source['party']??''));}
        if($lineParty===''){$bill=$store['supplierBills'][(string)($meta['supplierBillId']??'')]??[];$lineParty=trim((string)($bill['vendor']??$meta['supplier']??$meta['broker']??$meta['customer']??$meta['counterparty']??''));}
        if($lineParty===''&&!empty($meta['candidateId'])){$candidate=$store['exportCandidates'][(string)$meta['candidateId']]??[];$lineParty=trim((string)($candidate['meta']['customer']??$candidate['meta']['counterparty']??''));}
        $supplierAccount=in_array($code,['2110','2120','2130','2140','2190','1250','2500'],true);
        $customerAccount=in_array($code,['1210','1220','2160','2510'],true);
        if($category==='party'){
            if(!isset($partyAccounts[$code]))continue;
            $lineParty=alb_party_name($line)?:$lineParty;
            if($lineParty==='')$lineParty=alb_party_name($source??[]);
            if($lineParty==='')$lineParty=alb_party_name($bill??[]);
            if($lineParty==='')$lineParty=alb_party_name($meta);
            if($lineParty===''&&!empty($meta['candidateId'])){
                $candidate=(array)($store['exportCandidates'][(string)$meta['candidateId']]??[]);
                $lineParty=alb_party_name($candidate)?:alb_party_name((array)($candidate['meta']??[]));
            }
            if($lineParty===''&&!empty($meta['receiptId']))$lineParty=alb_party_name((array)($store['exportReceipts'][(string)$meta['receiptId']]??[]));
            if($lineParty==='')foreach((array)($master['peopleSubledgers']??[]) as $person){
                if(is_array($person)&&(string)($person['code']??'')===$code){$lineParty=trim((string)($person['name']??''));break;}
            }
            if($lineParty===''){$missingPartyLines++;continue;}
            $lineParty=$partyNames[$lineParty]??$partyNames[alb_party_key($lineParty)]??$lineParty;
            $parties[$lineParty]=true;
            if($party===''||alb_party_key($lineParty)!==alb_party_key($party))continue;
        }
        if($category==='supplier'&&!$supplierAccount)continue;
        if($category==='customer'&&!$customerAccount)continue;
        if($category==='bank'&&!in_array($code,['1110','1120'],true))continue;
        if($lineParty!==''&&in_array($category,['supplier','customer'],true))$parties[$lineParty]=true;
        if($party!==''&&$category!=='party'&&strcasecmp($lineParty,$party)!==0)continue;
        $date=(string)$journal['date'];
        $usdView=$entity==='TG'&&$bankId===''&&$nativeCurrency==='USD';if($usdView&&$code==='7100')continue;if($usdView&&strtoupper((string)($line['currency']??$journal['meta']['currency']??$journal['meta']['transactionCurrency']??''))!=='USD')continue;
        $native=$bankId!==''&&($banks[$bankId]['currency']??'')!==($entity==='TG'?'AED':'PKR');
        $debit=round((float)($native?($line['bankDebit']??0):($line['debit']??0)),2);$credit=round((float)($native?($line['bankCredit']??0):($line['credit']??0)),2);
        $nativeMissing=false;if($usdView){$nd=$line['nativeDebit']??null;$nc=$line['nativeCredit']??null;if($code==='1110'){$nd=$line['bankDebit']??null;$nc=$line['bankCredit']??null;}elseif($nd===null&&isset($line['chargeNative'])){$nd=(float)$line['chargeNative'];$nc=0;}elseif($nd===null&&isset($line['vatNative'])){$nd=(float)$line['vatNative'];$nc=0;}elseif($nd===null&&($journal['meta']['targetAccount']??'')===$code&&isset($journal['meta']['settlementAmountNative'])){$nd=(float)($line['debit']??0)>0?$journal['meta']['settlementAmountNative']:0;$nc=(float)($line['credit']??0)>0?$journal['meta']['settlementAmountNative']:0;}elseif($nd===null&&in_array($code,['1250','2500'],true)&&isset($journal['meta']['amountNative'])){$nd=(float)($line['debit']??0)>0?$journal['meta']['amountNative']:0;$nc=(float)($line['credit']??0)>0?$journal['meta']['amountNative']:0;}if($nd===null&&in_array($code,['1210','1250','2500','2510'],true)&&isset($journal['meta']['transactionAmount'])){$nd=(float)($line['debit']??0)>0?$journal['meta']['transactionAmount']:0;$nc=(float)($line['credit']??0)>0?$journal['meta']['transactionAmount']:0;}$nativeMissing=$nd===null||$nc===null;$debit=round((float)($nd??0),2);$credit=round((float)($nc??0),2);}
        if($category==='party'&&$nativeMissing)$balanceUnavailable=true;
        if($date<$from){if($account!==''||$party!=='')$opening+=$debit-$credit;continue;}
        $linkedNote=(string)($journal['meta']['notes']??'');$tracking=trim(implode(' · ',array_filter([(string)($journal['meta']['bankPaymentMethod']??''),!empty($journal['meta']['chequeNo'])?'Cheque '.$journal['meta']['chequeNo']:''])));
        $row=['date'=>$date,'voucher'=>(string)($journal['id']??''),'account'=>$bankId!==''?$account:$code,'accountName'=>$bankId!==''?$catalog[$account]:(string)($line['accountName']??$catalog[$code]??$code),'reference'=>(string)($journal['reference']??''),'narration'=>(string)($journal['narration']??'').(str_starts_with($linkedNote,'Mirrored settlement for Pakistan receipt ')?' · '.$linkedNote:'').($tracking!==''?' · '.$tracking:''),'party'=>$lineParty!==''?$lineParty:(string)($line['subledger']??$line['bankName']??''),'debit'=>$debit,'credit'=>$credit];
        if($usdView){$row['nativeMissing']=$nativeMissing;$row['bookCurrency']='AED';$row['bookDebit']=(float)($line['debit']??0);$row['bookCredit']=(float)($line['credit']??0);}
        if($native){$row['bookCurrency']=$entity==='TG'?'AED':'PKR';$row['bookDebit']=round((float)($line['debit']??0),2);$row['bookCredit']=round((float)($line['credit']??0),2);$row['nativeMissing']=!isset($line['bankDebit'])&&!isset($line['bankCredit']);}
        $rows[]=$row;
    }
}
if($category==='party'&&$party!==''&&!array_filter(array_keys($parties),static fn($name)=>alb_party_key($name)===alb_party_key($party)))alb_fail('No posted party ledger matches this name in the selected company and period.');
foreach($rows as &$trackingRow){$fiTag=$store['journals'][$trackingRow['voucher']]['fiTag']??[];if($fiTag){$trackingRow['fiTag']=$fiTag;$trackingRow['narration'].=' · '.tt_fi_advice_label($fiTag);}$meta=(array)($store['journals'][$trackingRow['voucher']]['meta']??[]);$trackingRow['chequeNo']=(string)($meta['chequeNo']??'');$trackingRow['bankReference']=(string)($meta['bankReference']??'');}unset($trackingRow);
$balance=round($opening,2);
foreach($rows as &$row){if(($account!==''||$party!=='')&&!$postEntries){$balance=round($balance+$row['debit']-$row['credit'],2);$row['balance']=$balance;}}unset($row);
$closing=$category==='party'&&$balanceUnavailable?null:$balance;
if($category==='party'&&$balanceUnavailable){$opening=null;foreach($rows as &$row)$row['balance']=null;unset($row);}
$expenseTotal=0.0;
if($category==='party'&&$party!=='')foreach($expenseActivity as $activity)if(alb_party_key($activity['recipient'])===alb_party_key($party)){
 $expenseTotal=round($expenseTotal+$activity['amount'],2);
 $rows[]=['date'=>$activity['date'],'voucher'=>$activity['voucher'],'account'=>$activity['account'],'accountName'=>$activity['accountName'],'reference'=>$activity['reference'],'narration'=>$activity['accountName'].' · '.$activity['purpose'].($activity['assetName']!==''?' · '.$activity['assetName'].' '.$activity['registrationNo']:''),'party'=>$activity['recipient'],'debit'=>0,'credit'=>0,'balance'=>null,'activityOnly'=>true,'expenseAmount'=>$activity['amount'],'chequeNo'=>'','bankReference'=>''];
}
if($query!=='')$rows=array_values(array_filter($rows,static fn($row)=>(!preg_match('/^(?:\d{4}-)?\d+$/',$query)&&str_contains(strtolower(implode(' ',array_map('strval',$row))),$query))||tt_accounts_reference_matches($query,$row['voucher'])||tt_accounts_reference_matches($query,$row['reference'])||($row['chequeNo']!==''&&str_contains(strtolower($row['chequeNo']),$query))||($row['bankReference']!==''&&str_contains(strtolower($row['bankReference']),$query))||(preg_match('/^(?:\d{4}-)?\d+$/',$query)&&!preg_match('/^(?:[A-Z]+-)?\d{4}-\d+$/i',$row['reference'])&&str_contains(strtolower($row['reference']),$query))));
// Keep the chronological running balances above, then display newest Post IDs first.
usort($rows,static function(array $a,array $b):int{
 $parts=static function(string $id):array{return preg_match('/^[A-Z]+-(\d{4})-(\d+)$/i',$id,$m)?[(int)$m[1],(int)$m[2]]:[0,0];};
 $ak=$parts((string)$a['voucher']);$bk=$parts((string)$b['voucher']);return($bk[0]<=>$ak[0])?:($bk[1]<=>$ak[1])?:strcmp((string)$b['date'],(string)$a['date'])?:strcmp((string)$b['voucher'],(string)$a['voucher']);
});
if(($_GET['format']??'')==='csv'){
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.preg_replace('/[^A-Z0-9_-]/i','',$entity.'-ledger-'.$from.'-'.$to).'.csv"');
    $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");
    fputcsv($out,['Company',$entity,$category==='party'?'Party':'Account',$category==='party'?$party:($account!==''?$account.' '.$catalog[$account]:'All Ledgers'),'From',$from,'To',$to]);
    fputcsv($out,['Opening Balance','','','','','','','','',($account!==''||$party!=='')?($opening===null?'Unavailable':round($opening,2)):'']);
    fputcsv($out,['Date','Post ID','Account','Account Name','Reference','Narration','Party','Debit','Credit','Balance','Expense paid']);
    foreach($rows as $row)fputcsv($out,array_map('alb_cell',[$row['date'],$row['voucher'],$row['account'],$row['accountName'],$row['reference'],$row['narration'],$row['party'],$row['debit'],$row['credit'],$row['balance']??'',$row['expenseAmount']??'']));
    if($category==='party')fputcsv($out,['Closing Balance','','','','','','','','',$balanceUnavailable?'Unavailable':$closing]);
    fclose($out);exit;
}
header('Content-Type: application/json; charset=UTF-8');
echo json_encode(['ok'=>true,'entity'=>$entity,'accounts'=>array_map(static fn($code,$name)=>['code'=>(string)$code,'name'=>$name],array_keys($catalog),array_values($catalog)),'from'=>$from,'to'=>$to,'account'=>$account,'currency'=>$bankId!==''?($banks[$bankId]['currency']??''):($entity==='TG'?$nativeCurrency:'PKR'),'pendingRemittances'=>$entity==='TG'&&$bankId!==''?tgr_reserved($store,$bankId):0,'availableBalance'=>$entity==='TG'&&$bankId!==''?tgr_bank_balance($store,$bankId,$banks[$bankId]['currency'])['available']:null,'opening'=>$opening===null?null:round($opening,2),'closing'=>($account!==''||$party!=='')&&!$postEntries?$closing:null,'parties'=>array_keys($parties),'category'=>$category,'party'=>$party,'expenseTotal'=>$expenseTotal,'missingPartyLines'=>$category==='party'?$missingPartyLines:0,'balanceUnavailable'=>$category==='party'&&$balanceUnavailable,'rows'=>$rows],JSON_UNESCAPED_UNICODE);


