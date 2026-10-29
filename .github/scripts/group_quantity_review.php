<?php
declare(strict_types=1);
// Invoked through SSH/stdin only. Output is encrypted before it leaves the host.
if(PHP_SAPI!=='cli')exit(1);
$root=(string)getenv('TT_EXPENSE_REVIEW_ROOT');
if(!preg_match('~^/home/[A-Za-z0-9_-]+/domains/app\.transtradeinternational\.com/public_html$~D',$root))exit(1);
require $root.'/auth_store.php';require_once $root.'/api/group_daily_core.php';
$h=fopen(TT_DATA_DIR.'/accounts.json','rb');if(!$h||!flock($h,LOCK_SH))throw new RuntimeException('Accounts source unavailable');
try{
 $raw=(string)stream_get_contents($h);$books=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
 $ops=gd_read_operations(TT_DATA_DIR.'/operations.json');$date=(new DateTimeImmutable('now',new DateTimeZone('Asia/Karachi')))->format('Y-m-d');$g=gd_sources($books,$ops,$date,false);
 $original=[];foreach(['purchaseSodas','purchaseSodasV2']as$key)foreach($books[$key]??[]as$id=>$p)$original[$p['entity'].'|'.($p['id']??$id)]=$p;
 $rows=[];foreach($g['purchaseOrders']as$p){$s=$original[$p['entity'].'|'.$p['sourceId']]??[];$receipts=[];
  foreach(gd_rows($ops['values'],'tt30slips')as$r)if(in_array('tt30slips|'.($r['bridgeId']??$r['id']??''),$p['receiptIds'],true)&&strtoupper((string)($r['entity']??''))===$p['entity'])$receipts[]=['source'=>'Mill receipt','id'=>$r['bridgeId']??$r['id']??'','date'=>$r['unloadingDate']??$r['arrivalDate']??$r['date']??'','truck'=>$r['truck']??$r['vehicle']??$r['truckNo']??'','kg'=>$r['payableWeight']??null];
  foreach($books['purchaseSodaLiftings']??[]as$id=>$r)if(in_array('purchaseSodaLiftings|'.$id,$p['receiptIds'],true))$receipts[]=['source'=>'Direct lifting','id'=>$id,'date'=>$r['date']??'','container'=>$r['container']??'','kg'=>$r['kg']??null];
  $rows[]=$p+['supplier'=>$s['supplier']??$s['supplierName']??'','broker'=>$s['broker']??'','receipts'=>$receipts];
 }
 $payload=['readOnly'=>true,'asOf'=>$date,'purchases'=>$rows,'sales'=>$g['salesContracts'],'holds'=>$g['holds'],'accountsUnchanged'=>hash('sha256',$raw)===hash_file('sha256',TT_DATA_DIR.'/accounts.json')];
 $public=base64_decode((string)getenv('TT_EXPENSE_REVIEW_PUBLIC_KEY_B64'),true);if(!$public)throw new RuntimeException('Encryption key missing');
 echo json_encode(elr_encrypt($payload,$public),JSON_THROW_ON_ERROR).PHP_EOL;
}finally{flock($h,LOCK_UN);fclose($h);}
