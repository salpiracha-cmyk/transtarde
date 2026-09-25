<?php
declare(strict_types=1);

require dirname(__DIR__).'/auth_store.php';
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
$user=tt_require_login();
if(!tt_user_can_open_module($user,'Accounts'))alb_fail('Accounts permission required.',403);
$entity=strtoupper(trim((string)($_GET['entity']??'')));
if(!in_array($entity,['TTI','BRM','TG'],true)||!tt_user_can_access_entity($user,$entity,'View'))alb_fail('Company access denied.',403);
$from=alb_date((string)($_GET['from']??date('Y').'-01-01'));
$to=alb_date((string)($_GET['to']??date('Y-m-d')));
if($from>$to)alb_fail('From date cannot be later than To date.');
$account=trim((string)($_GET['account']??''));
$query=strtolower(trim((string)($_GET['q']??'')));
$postEntries=$account==='POSTS';
$bankId=str_starts_with($account,'BANK|')?substr($account,5):'';
$master=json_decode((string)file_get_contents(dirname(__DIR__).'/accounts/accounting_master_v1.json'),true);
$catalog=[];
foreach(array_merge((array)($master['chart']??[]),(array)($master['peopleSubledgers']??[])) as $row){
    if(is_array($row)&&isset($row['code']))$catalog[(string)$row['code']]=(string)($row['name']??$row['code']);
}
foreach(['settlement_policy_v1.json','export_realization_policy_v1.json'] as $policyFile){
    $path=dirname(__DIR__).'/accounts/'.$policyFile;
    $policy=is_file($path)?json_decode((string)file_get_contents($path),true):[];
    foreach((array)($policy['accounts']??[]) as $row)if(is_array($row)&&isset($row['code']))$catalog[(string)$row['code']]=(string)($row['name']??$row['code']);
}
$file=TT_DATA_DIR.'/accounts.json';
$store=is_file($file)?json_decode((string)file_get_contents($file),true):[];
$banks=[];
foreach((array)(tt_list_masters()['banks']??[]) as $bank){
    if(!is_array($bank))continue;
    $v=(array)($bank['values']??[]);$linked=strtoupper((string)($v[1]??''));
    $belongs=$entity==='TG'?(str_contains($linked,'TRANS GRAINS')||preg_match('/(^|\W)TG($|\W)/',$linked)):
        ($entity==='BRM'?(str_contains($linked,'BUKSH RICE')||preg_match('/(^|\W)BRM($|\W)/',$linked)):
        (str_contains($linked,'TRANSTRADE INTERNATIONAL')||preg_match('/(^|\W)TTI($|\W)/',$linked)));
    if(!$belongs||($v[0]??'')!=='Company Account')continue;
    $id=(string)($bank['id']??'');if($id==='')continue;
    $banks[$id]=['code'=>'BANK|'.$id,'name'=>trim((string)($v[4]??'Bank')).' · '.trim((string)($v[3]??'')).' · '.strtoupper((string)($v[7]??'')),'currency'=>strtoupper((string)($v[7]??''))];
}
$catalog['POSTS']='Post Entry Ledger';
foreach($banks as $bank)$catalog[$bank['code']]=$bank['name'];
$requestedPost=trim((string)($_GET['postId']??''));
if($requestedPost!==''){
    $posting=$store['journals'][$requestedPost]??null;
    if(!is_array($posting)||($posting['status']??'')!=='Posted'||!tt_user_can_access_entity($user,(string)($posting['entity']??''),'View'))$posting=null;
    if(!$posting)alb_fail('Post ID was not found in these company books.',404);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok'=>true,'entity'=>$posting['entity'],'post'=>$posting],JSON_UNESCAPED_UNICODE);exit;
}
$journals=array_values(array_filter((array)($store['journals']??[]),static fn($j)=>is_array($j)&&($j['entity']??'')===$entity&&($j['status']??'')==='Posted'&&($j['date']??'')<=$to));
foreach($journals as $journal)foreach((array)($journal['lines']??[]) as $line)if(is_array($line)&&isset($line['account'])){
    $code=(string)$line['account'];if(!isset($catalog[$code]))$catalog[$code]=(string)($line['accountName']??$code);
}
if($account!==''&&!isset($catalog[$account]))alb_fail('Select an account from All Ledgers.');
ksort($catalog,SORT_NATURAL);
usort($journals,static fn($a,$b)=>strcmp((string)$a['date'],(string)$b['date'])?:strcmp((string)($a['id']??''),(string)($b['id']??'')));
$opening=0.0;$rows=[];
foreach($journals as $journal){
    if($postEntries){
        $date=(string)$journal['date'];if($date<$from)continue;
        $rows[]=['date'=>$date,'voucher'=>(string)($journal['id']??''),'account'=>'POSTS','accountName'=>'Post Entry Ledger','reference'=>(string)($journal['reference']??''),'narration'=>(string)($journal['narration']??'').(str_starts_with((string)($journal['meta']['notes']??''),'Mirrored settlement for Pakistan receipt ')?' · '.(string)$journal['meta']['notes']:''),'party'=>(string)($journal['sourceType']??''),'debit'=>round((float)($journal['totalDebit']??0),2),'credit'=>round((float)($journal['totalCredit']??0),2),'balance'=>null];
        continue;
    }
    foreach((array)($journal['lines']??[]) as $line){
        if(!is_array($line))continue;
        $code=(string)($line['account']??'');
        $lineBank=(string)($line['bankAccountId']??$journal['meta']['bankAccountId']??'');
        if($bankId!==''&&($code!=='1110'||$lineBank!==$bankId))continue;
        if($account!==''&&$bankId===''&&$code!==$account)continue;
        $date=(string)$journal['date'];
        $native=$bankId!==''&&($banks[$bankId]['currency']??'')!==($entity==='TG'?'AED':'PKR');
        $debit=round((float)($native?($line['bankDebit']??0):($line['debit']??0)),2);$credit=round((float)($native?($line['bankCredit']??0):($line['credit']??0)),2);
        if($date<$from){if($account!=='')$opening+=$debit-$credit;continue;}
        $linkedNote=(string)($journal['meta']['notes']??'');
        $row=['date'=>$date,'voucher'=>(string)($journal['id']??''),'account'=>$bankId!==''?$account:$code,'accountName'=>$bankId!==''?$catalog[$account]:(string)($line['accountName']??$catalog[$code]??$code),'reference'=>(string)($journal['reference']??''),'narration'=>(string)($journal['narration']??'').(str_starts_with($linkedNote,'Mirrored settlement for Pakistan receipt ')?' · '.$linkedNote:''),'party'=>(string)($line['subledger']??$line['party']??$line['counterparty']??$line['bankName']??''),'debit'=>$debit,'credit'=>$credit];
        if($native){$row['bookCurrency']=$entity==='TG'?'AED':'PKR';$row['bookDebit']=round((float)($line['debit']??0),2);$row['bookCredit']=round((float)($line['credit']??0),2);$row['nativeMissing']=!isset($line['bankDebit'])&&!isset($line['bankCredit']);}
        $rows[]=$row;
    }
}
$balance=round($opening,2);
foreach($rows as &$row){if($account!==''&&!$postEntries){$balance=round($balance+$row['debit']-$row['credit'],2);$row['balance']=$balance;}}unset($row);
$closing=$balance;
if($query!=='')$rows=array_values(array_filter($rows,static fn($row)=>str_contains(strtolower(implode(' ',array_map('strval',$row))),$query)));
if(($_GET['format']??'')==='csv'){
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.preg_replace('/[^A-Z0-9_-]/i','',$entity.'-ledger-'.$from.'-'.$to).'.csv"');
    $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");
    fputcsv($out,['Company',$entity,'Account',$account!==''?$account.' '.$catalog[$account]:'All Ledgers','From',$from,'To',$to]);
    fputcsv($out,['Opening Balance','','','','','','','','',$account!==''?round($opening,2):'']);
    fputcsv($out,['Date','Voucher','Account','Account Name','Reference','Narration','Party','Debit','Credit','Balance']);
    foreach($rows as $row)fputcsv($out,array_map('alb_cell',[$row['date'],$row['voucher'],$row['account'],$row['accountName'],$row['reference'],$row['narration'],$row['party'],$row['debit'],$row['credit'],$row['balance']??'']));
    fclose($out);exit;
}
header('Content-Type: application/json; charset=UTF-8');
echo json_encode(['ok'=>true,'entity'=>$entity,'accounts'=>array_map(static fn($code,$name)=>['code'=>(string)$code,'name'=>$name],array_keys($catalog),array_values($catalog)),'from'=>$from,'to'=>$to,'account'=>$account,'currency'=>$bankId!==''?($banks[$bankId]['currency']??''):($entity==='TG'?'AED':'PKR'),'opening'=>round($opening,2),'closing'=>$account!==''&&!$postEntries?$closing:null,'rows'=>$rows],JSON_UNESCAPED_UNICODE);
