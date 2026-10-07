<?php
declare(strict_types=1);
require_once __DIR__.'/expense_reminders.php';
require_once dirname(__DIR__).'/auth_store.php';
define('TT_BANK_FUNCTIONS_ONLY',true);require_once __DIR__.'/bank_accounts.php';
require_once __DIR__.'/accounts_reviews_core.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function ad_out(array $v,int $status=200):never{http_response_code($status);echo json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
function ad_entity(string $v):string{$v=strtoupper(trim($v));if(!in_array($v,['TTI','BRM','TG'],true))ad_out(['ok'=>false,'error'=>'Select valid company books.'],422);return $v;}
function ad_store():array{$p=TT_DATA_DIR.'/accounts.json';if(!is_file($p))return[];$h=fopen($p,'r');if(!$h||!flock($h,LOCK_SH))throw new RuntimeException('Accounts storage unavailable.');try{$raw=stream_get_contents($h);}finally{flock($h,LOCK_UN);fclose($h);} $v=$raw?json_decode($raw,true):[];return is_array($v)?$v:[];}
function ad_paid(array $s,string $bill):float{$n=0.0;foreach((array)($s['supplierSettlements']??[])as$set){if(!is_array($set)||!in_array((string)($set['status']??''),['Posted','Approved / Posted','Approved'],true))continue;foreach((array)($set['allocations']??[])as$a)if(is_array($a)&&(string)($a['billId']??'')===$bill)$n+=(float)($a['amount']??0);}return round($n,2);}
function ad_rows(array $map):array{$out=array_values(array_filter($map,static fn($x)=>(float)($x['amount']??0)>.005));usort($out,static fn($a,$b)=>(float)$b['amount']<=>(float)$a['amount']);return$out;}

try{
 $u=tt_require_login();if(!tt_user_can_open_module($u,'Accounts'))ad_out(['ok'=>false,'error'=>'Accounts permission required.'],403);$e=ad_entity((string)($_GET['entity']??''));if(($u['role']??'')!=='Super Admin'&&!tt_user_can_access_entity($u,$e,'View'))ad_out(['ok'=>false,'error'=>'You do not have permission for these company books.'],403);if($_SERVER['REQUEST_METHOD']==='GET')tt_release_read_session();$s=ad_store();
 $bank=[];$local=[];$export=[];
 foreach((array)($s['journals']??[])as$j){if(!is_array($j)||($j['status']??'')!=='Posted'||($j['entity']??'')!==$e)continue;$jm=is_array($j['meta']??null)?$j['meta']:[];foreach((array)($j['lines']??[])as$l){if(!is_array($l))continue;$a=(string)($l['account']??'');$delta=round((float)($l['debit']??0)-(float)($l['credit']??0),2);if($a==='1110'||$a==='1120'){$id=(string)($l['bankAccountId']??$jm['bankAccountId']??($a==='1120'?'CASH|'.$e:'UNASSIGNED'));$name=(string)($l['bankAccountTitle']??$l['bankName']??$jm['bankAccountTitle']??$jm['bankName']??($a==='1120'?'Cash / Petty Cash':'Bank account'));$cur=strtoupper((string)($l['currency']??$jm['currency']??($e==='TG'?'AED':'PKR')));$bankDelta=$a==='1110'&&$cur!=='PKR'&&array_key_exists('bankDebit',$l)?round((float)($l['bankDebit']??0)-(float)($l['bankCredit']??0),2):$delta;$key=$cur.'|'.$id;if(!isset($bank[$key]))$bank[$key]=['label'=>$name,'currency'=>$cur,'amount'=>0.0];$bank[$key]['amount']=round($bank[$key]['amount']+$bankDelta,2);}if($a==='1220'){$name=(string)($l['party']??$jm['party']??'Local customer');$key=strtolower($name).'|'.(string)($l['soda']??$jm['soda']??'');if(!isset($local[$key]))$local[$key]=['label'=>$name,'reference'=>(string)($l['soda']??$jm['soda']??''),'currency'=>'PKR','amount'=>0.0];$local[$key]['amount']=round($local[$key]['amount']+$delta,2);}if($a==='1210'){$name=(string)($l['party']??$l['customer']??$jm['customer']??$j['narration']??'Export customer');$cur='PKR';$key=strtolower($name).'|'.$cur;if(!isset($export[$key]))$export[$key]=['label'=>$name,'currency'=>$cur,'amount'=>0.0];$export[$key]['amount']=round($export[$key]['amount']+$delta,2);}}}
 $commodity=[];$expenses=[];
 foreach((array)($s['commodityBills']??[])as$b){if(!is_array($b)||($b['entity']??'')!==$e)continue;$out=round(max(0,(float)($b['supplierPayableTotal']??$b['total']??0)-ad_paid($s,(string)($b['id']??''))),2);if($out>.005)$commodity[]=['label'=>(string)($b['broker']??$b['party']??'Commodity supplier'),'reference'=>(string)($b['billNo']??$b['id']??''),'date'=>(string)($b['dueDateFrom']??$b['billDate']??''),'currency'=>'PKR','amount'=>$out];}
 foreach((array)($s['supplierBills']??[])as$b){if(!is_array($b)||($b['entity']??'')!==$e)continue;$out=round(max(0,(float)($b['supplierPayableTotal']??0)-ad_paid($s,(string)($b['id']??''))),2);if($out>.005)$expenses[]=['label'=>(string)($b['vendor']??$b['broker']??'Supplier'),'reference'=>(string)($b['billNo']??$b['id']??''),'date'=>(string)($b['dueDate']??$b['billDate']??''),'currency'=>'PKR','amount'=>$out];}
 if($e==='TG'){foreach(tgr_items($s) as $draft){if(!empty($draft['legacy']))continue;$key=$draft['currency'].'|'.$draft['bankAccountId'];if(!isset($bank[$key]))$bank[$key]=['label'=>$draft['bank'],'currency'=>$draft['currency'],'amount'=>0.0];$bank[$key]['pending']=round((float)($bank[$key]['pending']??0)+(float)$draft['amountNative'],2);}foreach($bank as &$row){$row['postedAmount']=$row['amount'];$row['amount']=round($row['amount']-(float)($row['pending']??0),2);$row['label'].=' · AVAILABLE';}unset($row);}
 $attention=ar_items($s,$e);foreach(tt_expense_due_reminders($s,$e) as $reminder)$attention[]=['kind'=>'REMINDER','type'=>$reminder['type']==='UTILITY'?'Utility Bill':'Credit Card','reference'=>$reminder['label'],'message'=>$reminder['status'].' · due '.$reminder['dueDate'],'target'=>['expense'=>$reminder['type']==='UTILITY'?'utility':'card','month'=>$reminder['month']]];
 $due=$expenses;
 foreach((array)($s['creditCardStatements']??[]) as $statement){
   if(!is_array($statement)||($statement['entity']??'')!==$e||in_array(($statement['status']??'Pending'),['Paid','Deleted'],true))continue;
   $due[]=['label'=>(string)($statement['cardName']??'Credit card').' credit card','reference'=>(string)($statement['id']??''),'date'=>(string)($statement['dueDate']??''),'currency'=>$e==='TG'?'AED':'PKR','amount'=>(float)($statement['total']??0)];
 }
 foreach((array)($s['rentMasters']??[]) as $rent){
   if(!is_array($rent)||($rent['entity']??'')!==$e||($rent['status']??'Active')!=='Active')continue;
   $month=(new DateTimeImmutable('now',new DateTimeZone('Asia/Karachi')))->format('Y-m');
   if((string)($rent['effectiveFrom']??'9999-12-31')>$month.'-31'||((string)($rent['effectiveTo']??'')!==''&&(string)$rent['effectiveTo']<$month.'-01'))continue;
   $first=(string)($rent['firstPaymentMonth']??substr((string)($rent['effectiveFrom']??''),0,7));
   $interval=match((string)($rent['paymentPattern']??'Monthly')){'Quarterly'=>3,'Twice-Yearly','Half-Yearly'=>6,default=>1};
   if(!preg_match('/^\d{4}-\d{2}$/',$first))continue;
   $months=12*((int)substr($month,0,4)-(int)substr($first,0,4))+(int)substr($month,5,2)-(int)substr($first,5,2);
   if($months<0||$months%$interval!==0)continue;
   $day=max(1,min((int)($rent['dueDay']??1),(int)date('t',strtotime($month.'-01'))));
   $dueDate=$month.'-'.str_pad((string)$day,2,'0',STR_PAD_LEFT);
   $period=$s['rentPeriods'][$e.'|'.$month.'|'.(string)($rent['id']??'')]??null;
   $amount=is_array($period)?(float)($period['outstanding']??0):(float)($rent['monthlyAmount']??0)*$interval;
   if($amount>.005)$due[]=['label'=>(string)($rent['mill']??'').' rent · '.(string)($rent['payee']??''),'reference'=>(string)($rent['id']??''),'date'=>$dueDate,'currency'=>$e==='TG'?'AED':'PKR','amount'=>$amount];
 }
 $currentBanks=ba_payload($s,$e);$masterDefaults=[];foreach((array)(tt_list_masters()['companies']??[]) as $company){$cv=(array)($company['values']??[]);if(strtoupper(trim((string)($cv[1]??'')))!==$e)continue;foreach(tt_master_json_array($cv[13]??'') as $a)if(!empty($a['isDefault'])&&strcasecmp((string)($a['status']??'Active'),'Active')===0)$masterDefaults[(string)$a['id']]=true;}$bank=[];foreach($currentBanks['accounts'] as $a){$tail=substr(preg_replace('/\W/','',(string)($a['accountNumber']?:$a['iban'])),-6);$bank[]=['label'=>$a['bankName'].($tail!==''?' · …'.$tail:''),'bankAccountId'=>$a['id'],'isDefault'=>!empty($a['settings']['active'])&&(isset($masterDefaults[$a['id']])||(!$masterDefaults&&(!empty($a['settings']['defaultPaymentAccount'])||!empty($a['settings']['defaultReceiptAccount'])))),'currency'=>$a['currency'],'amount'=>$a['availableBalance']];}if(abs((float)$currentBanks['cash']['bookBalance'])>.005)$bank[]=['label'=>'Cash / Petty Cash','currency'=>$currentBanks['cash']['currency'],'amount'=>$currentBanks['cash']['bookBalance']];if(abs((float)$currentBanks['unassignedBankBalance'])>.005)$bank[]=['label'=>'Unassigned bank posting','currency'=>'PKR','amount'=>$currentBanks['unassignedBankBalance']];
 $sets=['bank'=>array_values($bank),'commodity'=>ad_rows($commodity),'local'=>ad_rows($local),'export'=>ad_rows($export),'expenses'=>ad_rows($expenses),'due'=>ad_rows($due)];foreach($sets as&$rows)if(is_array($rows))foreach($rows as&$row)$row['dateDisplay']=preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',(string)($row['date']??''),$m)?$m[3].'-'.$m[2].'-'.$m[1]:'';unset($row);unset($rows);
 usort($sets['due'],static fn($a,$b)=>strcmp((string)($a['date']??''),(string)($b['date']??'')));
 ad_out(['ok'=>true,'entity'=>$e,'summaries'=>$sets,'attention'=>$attention,'serverNow'=>gmdate('c')]);
}catch(Throwable $x){error_log('Accounts dashboard: '.$x->getMessage());ad_out(['ok'=>false,'error'=>'Accounts summary is temporarily unavailable.'],500);}

