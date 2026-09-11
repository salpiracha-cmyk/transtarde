<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
const TT_OP_FILE=TT_DATA_DIR.'/accounts.json';
const TT_OP_MASTER=__DIR__.'/../accounts/accounting_master_v1.json';
function op_out(array $d,int $s=200):never{http_response_code($s);echo json_encode($d,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
function op_def():array{return ['revision'=>0,'journals'=>[],'otherPurchases'=>[],'fixedAssets'=>[],'bankAccountSettings'=>[]];}
function op_read():array{tt_ensure_data_dir();if(!is_file(TT_OP_FILE))return op_def();$h=fopen(TT_OP_FILE,'r');if($h===false||!flock($h,LOCK_SH))throw new RuntimeException('Accounts storage unavailable.');try{
$u=tt_require_login();if(!tt_user_can_open_module($u,'Accounts'))op_out(['ok'=>false,'error'=>'Accounts permission required.'],403);
$e=op_ent((string)($_GET['entity']??'TTI'));op_require_entity($u,$e,false);
if($_SERVER['REQUEST_METHOD']==='GET')op_out(op_payload(op_read(),$e));
if($_SERVER['REQUEST_METHOD']!=='POST')op_out(['ok'=>false,'error'=>'Method not allowed.'],405);
if(!op_can($u))op_out(['ok'=>false,'error'=>'Accounts Create / Edit / Approve permission required.'],403);
$b=json_decode(file_get_contents('php://input')?:'{}',true);if(!is_array($b)||!tt_verify_csrf((string)($b['csrf']??'')))op_out(['ok'=>false,'error'=>'Your session expired. Refresh and try again.'],419);
if(($b['action']??'')!=='post_purchase')op_out(['ok'=>false,'error'=>'Unknown other-purchase action.'],422);
$e=op_ent((string)($b['entity']??''));op_require_entity($u,$e,true);
$date=op_date((string)($b['date']??''),'purchase date');$type=strtoupper(trim((string)($b['type']??'')));if(!in_array($type,['FIXED_ASSET','OPERATING'],true))op_out(['ok'=>false,'error'=>'Select Fixed Asset or Operating Purchase.'],422);
$sub=strtoupper(trim((string)($b['subtype']??'')));$supplier=trim((string)($b['supplier']??''));$desc=trim((string)($b['description']??''));$inv=trim((string)($b['invoiceNo']??''));$invDate=trim((string)($b['invoiceDate']??''));if($invDate!=='')op_date($invDate,'supplier invoice date');$amount=op_money($b['amount']??0);$location=op_location((string)($b['location']??''));if($supplier===''||$desc==='')op_out(['ok'=>false,'error'=>'Supplier / payee and description are required.'],422);
$settlement=strtoupper(trim((string)($b['settlement']??'')));if(!in_array($settlement,['BANK','CASH','CREDIT'],true))op_out(['ok'=>false,'error'=>'Select Bank, Cash or Supplier Bill Due.'],422);
$assetName=trim((string)($b['assetName']??''));$assetTag=strtoupper(trim((string)($b['assetTag']??'')));$custodian=trim((string)($b['custodian']??''));$inServiceDate=trim((string)($b['inServiceDate']??''));$usefulLife=0;
if($type==='FIXED_ASSET'){if($assetName===''||$assetTag===''||$inServiceDate==='')op_out(['ok'=>false,'error'=>'Asset name, unique asset tag and in-service date are required.'],422);op_date($inServiceDate,'in-service date');$usefulLife=op_years($b['usefulLifeYears']??0);}
tt_ensure_data_dir();$h=fopen(TT_OP_FILE,'c+');if($h===false||!flock($h,LOCK_EX))throw new RuntimeException('Accounts storage unavailable.');
try{
rewind($h);$raw=stream_get_contents($h);$s=$raw?json_decode($raw,true):null;if(!is_array($s))$s=op_def();$s=array_replace_recursive(op_def(),$s);
if($inv!=='')foreach((array)$s['otherPurchases']as$old)if(is_array($old)&&($old['entity']??'')===$e&&strcasecmp((string)($old['supplier']??''),$supplier)===0&&strcasecmp((string)($old['invoiceNo']??''),$inv)===0)op_out(['ok'=>false,'error'=>'This supplier invoice is already recorded in these company books.'],409);
if($type==='FIXED_ASSET')foreach((array)$s['fixedAssets']as$old)if(is_array($old)&&($old['entity']??'')===$e&&strcasecmp((string)($old['assetTag']??''),$assetTag)===0)op_out(['ok'=>false,'error'=>'This asset tag already exists in these company books.'],409);
$names=op_names();$debit=op_account($type,$sub);$purchaseId=op_next((array)$s['otherPurchases'],'OP');$meta=['otherPurchaseId'=>$purchaseId,'purchaseType'=>$type,'purchaseSubtype'=>$sub,'supplier'=>$supplier,'invoiceNo'=>$inv,'description'=>$desc,'location'=>$location];
if($settlement==='BANK'){$bankId=trim((string)($b['bankId']??''));$bank=op_bank_master($bankId,$e);$set=is_array($s['bankAccountSettings'][$bankId]??null)?$s['bankAccountSettings'][$bankId]:[];if(empty($set['active'])||empty($set['allowPayments']))op_out(['ok'=>false,'error'=>'Selected bank is not enabled for payments.'],422);$credit='1110';$creditExtra=['bankAccountId'=>$bankId,'bank'=>$bank];$status='Paid';}
elseif($settlement==='CASH'){$bankId='CASH|'.$e;$set=is_array($s['bankAccountSettings'][$bankId]??null)?$s['bankAccountSettings'][$bankId]:['active'=>true,'allowPayments'=>true];if(empty($set['active'])||empty($set['allowPayments']))op_out(['ok'=>false,'error'=>'Cash is not enabled for payments.'],422);$credit='1120';$creditExtra=['paymentAccountId'=>$bankId];$status='Paid';}
else{$bankId='';$credit='2140';$creditExtra=['subledger'=>'Other Supplier / Expense Payable','supplier'=>$supplier];$status='Outstanding';}
$lines=[op_line($debit,$amount,0,$names,$meta),op_line($credit,0,$amount,$names,$creditExtra)];$jid=op_next((array)$s['journals'],'AUTO');$source=$type==='FIXED_ASSET'?'FIXED_ASSET_PURCHASE':'OTHER_PURCHASE';
$s['journals'][$jid]=['id'=>$jid,'entity'=>$e,'date'=>$date,'sourceType'=>$source,'reference'=>$inv!==''?$inv:$purchaseId,'narration'=>($type==='FIXED_ASSET'?'Fixed asset purchase — ':'Other purchase — ').$supplier.' — '.$desc,'lines'=>$lines,'totalDebit'=>$amount,'totalCredit'=>$amount,'status'=>'Posted','meta'=>$meta+['settlement'=>$settlement],'createdAt'=>gmdate('c'),'createdBy'=>(string)($u['full_name']??$u['username']??'Accounts'),'userId'=>(int)($u['id']??0)];
$s['otherPurchases'][$purchaseId]=['id'=>$purchaseId,'entity'=>$e,'date'=>$date,'type'=>$type,'subtype'=>$sub,'supplier'=>$supplier,'description'=>$desc,'invoiceNo'=>$inv,'invoiceDate'=>$invDate,'amount'=>$amount,'location'=>$location,'settlement'=>$settlement,'paymentAccountId'=>$bankId,'status'=>$status,'journalId'=>$jid,'createdAt'=>gmdate('c'),'createdBy'=>(string)($u['full_name']??$u['username']??'Accounts')];
$assetId='';
if($type==='FIXED_ASSET'){$assetId=op_next((array)$s['fixedAssets'],'FA');$s['fixedAssets'][$assetId]=['id'=>$assetId,'entity'=>$e,'assetTag'=>$assetTag,'assetName'=>$assetName,'category'=>$sub,'assetAccount'=>$debit,'assetAccountName'=>$names[$debit],'acquisitionDate'=>$date,'inServiceDate'=>$inServiceDate,'cost'=>$amount,'location'=>$location,'custodian'=>$custodian,'usefulLifeYears'=>$usefulLife,'depreciationMethod'=>'STRAIGHT_LINE','residualValue'=>0,'accumulatedDepreciation'=>0,'netBookValue'=>$amount,'status'=>'Active','purchaseId'=>$purchaseId,'journalId'=>$jid,'createdAt'=>gmdate('c'),'createdBy'=>(string)($u['full_name']??$u['username']??'Accounts')];$s['otherPurchases'][$purchaseId]['assetId']=$assetId;}
$s['revision']=(int)($s['revision']??0)+1;rewind($h);ftruncate($h,0);fwrite($h,json_encode($s,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));fflush($h);
}finally{flock($h,LOCK_UN);fclose($h);}
op_out(op_payload($s,$e)+['saved'=>$s['otherPurchases'][$purchaseId],'assetId'=>$assetId,'revision'=>$s['revision']]);
}catch(Throwable $x){op_out(['ok'=>false,'error'=>'Other purchase could not be posted.'],500);}
