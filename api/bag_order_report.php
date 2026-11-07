<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/document_filenames.php';
require dirname(__DIR__).'/auth_store.php';
require_once __DIR__.'/accounts_shipment_source.php';
require_once __DIR__.'/bag_order_report_core.php';
header('Cache-Control: no-store, no-cache, must-revalidate');
function bor_out(array $value,int $status=200):never{http_response_code($status);header('Content-Type: application/json; charset=UTF-8');echo json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
function bor_read_file(string $path):array{
    if(!is_file($path))return [];$handle=fopen($path,'rb');if(!$handle||!flock($handle,LOCK_SH))throw new RuntimeException('Report source unavailable.');
    try{$value=json_decode((string)stream_get_contents($handle),true,512,JSON_THROW_ON_ERROR);}finally{flock($handle,LOCK_UN);fclose($handle);}
    if(!is_array($value))throw new RuntimeException('Report source is invalid.');return $value;
}
try{
    $user=tt_require_login();$entity=strtoupper(trim((string)($_GET['entity']??'')));
    if($_SERVER['REQUEST_METHOD']!=='GET')bor_out(['ok'=>false,'error'=>'Method not allowed.'],405);
    if(!in_array($entity,['TTI','BRM'],true)||!tt_user_can_open_module($user,'Accounts')||!tt_user_can_access_entity($user,$entity,'View')||!tt_user_can_module_action($user,'Accounts','reports','View'))bor_out(['ok'=>false,'error'=>'Accounts report and company access required.'],403);
    $today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Karachi')))->format('Y-m-d');
    $filters=['from'=>(string)($_GET['from']??substr($today,0,7).'-01'),'to'=>(string)($_GET['to']??$today),'basis'=>(string)($_GET['basis']??'activity')];
    if(bor_date($filters['from'])!==$filters['from']||bor_date($filters['to'])!==$filters['to']||$filters['from']>$filters['to']||!in_array($filters['basis'],['activity','orders'],true))bor_out(['ok'=>false,'error'=>'Choose a valid date range and report basis.'],422);
    foreach(['brand','supplier','bagType','packingSize','q'] as $key){$filters[$key]=trim((string)($_GET[$key]??''));if(strlen($filters[$key])>200)bor_out(['ok'=>false,'error'=>'A report filter is too long.'],422);}
    tt_release_read_session();$store=bor_read_file(TT_DATA_DIR.'/accounts.json');$keys=['tt30bags','tt33bagreceipts','transtrade_export_v3_operational','tt41bagtransfers'];$values=[];$db=tt_accounts_exports_db();
    if($db){$db->beginTransaction();try{$stmt=$db->prepare('SELECT storage_key,payload FROM tt_operation_records WHERE storage_key IN (?,?,?,?)');$stmt->execute($keys);foreach($stmt->fetchAll() as $record)$values[$record['storage_key']]=json_decode((string)$record['payload'],true,512,JSON_THROW_ON_ERROR);$db->commit();}catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}}
    else{$operations=bor_read_file(TT_DATA_DIR.'/operations.json');foreach($keys as $key){$raw=$operations['values'][$key]??'[]';$values[$key]=is_string($raw)?json_decode($raw,true,512,JSON_THROW_ON_ERROR):$raw;}}
    foreach($keys as $key)if(isset($values[$key])&&!is_array($values[$key]))throw new RuntimeException('Report source is invalid.');
    $orders=bor_original_dates((array)($store['bagPurchaseOrders']??[]),(array)($values['transtrade_export_v3_operational']??[]),$entity);
    $report=bor_report($orders,(array)($values['tt30bags']??[]),(array)($values['tt33bagreceipts']??[]),$entity,$filters,(array)($values['tt41bagtransfers']['receiptDispositions']??[]));
    if(($_GET['format']??'')==='csv'){
        header('Content-Type: text/csv; charset=UTF-8');tt_document_disposition(tt_document_name(['party'=>$filters['supplier'],'company'=>$entity,'title'=>'Bag Orders and Receipts','from'=>$filters['from'],'to'=>$filters['to'],'extension'=>'csv']),true);$file=fopen('php://output','w');fwrite($file,"\xEF\xBB\xBF");
        $write=static function(array $cells)use($file):void{fputcsv($file,array_map(static fn($value)=>is_string($value)&&preg_match('/^[\s]*[=+@-]/',$value)?"'".$value:$value,$cells),',','"','');};
        $write(['Company',$entity,'From',$filters['from'],'To',$filters['to'],'Basis',$filters['basis']]);foreach(['brand','supplier','bagType','packingSize','q'] as $key)$write([$key,$filters[$key]]);foreach($report['warnings'] as $warning)$write(['Review',$warning]);
        $keys=['orderDate','poNo','lineKey','supplier','brand','bagType','packingSize','shipment','orderedInPeriod','receivedInPeriod','receivedThroughEnd','netReceivedThroughEnd','returnedInPeriod','returnedCurrent','quarantinedCurrent','ordered','receivedCurrent','outstandingCurrent','undatedReceived'];
        $write(['Order date','PO','Line','Supplier','Brand','Bag type','Packing','Shipment / contract','Ordered in period','Gross received in period','Gross dated receipts through end','Net dated receipts through end','Supplier returns in period','Supplier returns current','Quarantined current','Order quantity','Net received current','Outstanding current','Undated prior receipts']);
        foreach($report['rows'] as $row)$write(array_map(static fn($key)=>$key==='orderDate'&&$row[$key]!==''?implode('-',array_reverse(explode('-',$row[$key]))):$row[$key],$keys));
        $write(array_merge(['Filtered totals','','','','','','',''],array_map(static fn($key)=>$report['totals'][$key]??0,array_slice($keys,8))));fclose($file);exit;
    }
    bor_out($report);
}catch(Throwable $error){error_log('Bag order report: '.$error->getMessage());bor_out(['ok'=>false,'error'=>'Could not read the bag orders and receipt report.'],500);}
