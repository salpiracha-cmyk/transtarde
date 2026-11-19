<?php
declare(strict_types=1);
define('TT_DATA_DIR',sys_get_temp_dir().'/tt-contract-lifecycle-'.getmypid());
require dirname(__DIR__,2).'/product_stage.php';
require dirname(__DIR__,2).'/master_store.php';
require dirname(__DIR__,2).'/api/telegram_dispatch_core.php';
function verify(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
$defaults=tt_default_masters();$legacy=$defaults;
$legacy['export_payment_terms']=array_values(array_filter($legacy['export_payment_terms'],fn($row)=>$row['id']!=='SCAN100'));
$normalized=tt_normalize_masters($legacy);
$scan=array_values(array_filter($normalized['export_payment_terms'],fn($row)=>$row['id']==='SCAN100'));
verify(count($scan)===1,'Existing master stores receive the new preset once.');
verify($scan[0]['values'][2]==='100% by TT upon reciept of scan copies of shipment documents by the buyer.','Exact requested contract wording.');
verify($scan[0]['values'][1]==='ADV_SCAN'&&$scan[0]['values'][5]==='0','Scan-copy payment has zero advance.');
tt_validate_export_payment_term($scan[0]['values'],$legacy['export_payment_terms'],'SCAN100');
foreach($normalized['export_payment_terms'] as &$row)if($row['id']==='SCAN100'){$row['values'][2]='Owner edited wording.';$row['values'][4]='Inactive';}unset($row);
$normalized=tt_normalize_masters($normalized);
$scan=array_values(array_filter($normalized['export_payment_terms'],fn($row)=>$row['id']==='SCAN100'));
verify(count($scan)===1&&$scan[0]['values'][2]==='Owner edited wording.'&&$scan[0]['values'][4]==='Inactive','Reload preserves owner edits and deactivation.');
$now=new DateTimeImmutable('2026-11-19 11:05:00',new DateTimeZone('Asia/Karachi'));
$actions=['Draft created after Buyer & Reference','Draft updated','Draft updated','Created','Amended','Signed contract received'];
$audits=[];
foreach($actions as $index=>$action)$audits[]=['id'=>'DRAFT-AUD-'.$index,'area'=>'Sales Contract','action'=>$action,'at'=>$now->format(DATE_ATOM),'contractRef'=>'TTI/TEST/01'];
$audits[]=['id'=>'PO-AUD','area'=>'BAG ORDER','action'=>'Issued','at'=>$now->format(DATE_ATOM)];
$ops=['values'=>['tt30queue'=>'[]','tt30prod'=>'[]','tt30ship'=>'[]','transtrade_export_v3_operational'=>json_encode(['audits'=>$audits])]];
$state=['since'=>$now->setTime(0,0)->getTimestamp(),'sent'=>[],'observed'=>[]];
$jobs=array_values(array_filter(iterator_to_array(tg_notification_jobs($ops,$state,$now)),fn($job)=>str_starts_with($job['key'],'export|')));
verify(array_column($jobs,'key')===['export|PO-AUD','export|DRAFT-AUD-3','export|DRAFT-AUD-0'],'Both channel generators keep only start/final issue, preserving other export events.');
// Check response scheduling without any real social transport or shutdown work.
$source=file_get_contents(dirname(__DIR__,2).'/api/operations.mysql.php');
$start=strpos($source,'function operations_social_after_save(');
$end=strpos($source,"\nfunction ",$start+1);
$function=substr($source,$start,$end-$start);
eval(str_replace('register_shutdown_function','fixture_register_shutdown',$function));
$scheduled=0;
function fixture_register_shutdown(Closure $callback): void {$GLOBALS['scheduled']++;}
function litespeed_finish_request(): void {}
mkdir(TT_DATA_DIR);file_put_contents(TT_DATA_DIR.'/telegram-dispatch.json','{}');
$_SERVER['REQUEST_METHOD']='POST';
foreach(['Draft updated','Draft created after Buyer & Reference','Created'] as $action){
 operations_social_after_save(['ok'=>true,'revision'=>1,'contractReceipt'=>['auditId'=>'SAVE'],'value'=>json_encode(['audits'=>[['id'=>'SAVE','area'=>'Sales Contract','action'=>$action]]])],200);
}
verify($scheduled===2,'Intermediate tabs do not schedule a full social scan; start and final issue do.');
unlink(TT_DATA_DIR.'/telegram-dispatch.json');rmdir(TT_DATA_DIR);
echo "PASS existing-master migration, exact scan wording, zero advance, deactivation, lifecycle notifications and no draft social scan\n";
