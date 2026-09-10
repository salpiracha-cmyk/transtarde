<?php
declare(strict_types=1);
require dirname(__DIR__) . '/auth_store.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

const TT_SPH_FILE = TT_DATA_DIR . '/accounts.json';

function sph_out(array $d,int $s=200): never { http_response_code($s); echo json_encode($d,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); exit; }
function sph_entity(string $v): string { $v=strtoupper(trim($v)); if(!in_array($v,['TTI','BRM'],true))sph_out(['ok'=>false,'error'=>'Payment history is available for TTI or BRM.'],422); return $v; }
function sph_commodity(string $v): string { $v=strtoupper(trim($v)); if($v==='MAIZE')$v='CORN'; if(!in_array($v,['','RICE','CORN'],true))sph_out(['ok'=>false,'error'=>'Commodity must be Rice or Corn / Maize.'],422); return $v; }
function sph_read(): array { tt_ensure_data_dir(); if(!is_file(TT_SPH_FILE))return []; $h=fopen(TT_SPH_FILE,'r'); if($h===false||!flock($h,LOCK_SH))throw new RuntimeException('Accounts storage unavailable.'); try{$raw=stream_get_contents($h);}finally{flock($h,LOCK_UN);fclose($h);} $s=$raw?json_decode($raw,true):null; return is_array($s)?$s:[]; }

try{
  $u=tt_require_login(); if(!tt_user_can_open_module($u,'Accounts'))sph_out(['ok'=>false,'error'=>'Accounts permission required.'],403);
  if($_SERVER['REQUEST_METHOD']!=='GET')sph_out(['ok'=>false,'error'=>'Method not allowed.'],405);
  $entity=sph_entity((string)($_GET['entity']??'TTI')); $commodity=sph_commodity((string)($_GET['commodity']??'')); $broker=trim((string)($_GET['broker']??''));
  $s=sph_read(); $bills=(array)($s['commodityBills']??[]); $rows=[];
  foreach((array)($s['supplierSettlements']??[]) as $st){
    if(!is_array($st)||($st['entity']??'')!==$entity)continue;
    if(!in_array((string)($st['status']??''),['Posted','Approved / Posted','Approved'],true))continue;
    if($broker!==''&&strcasecmp((string)($st['broker']??''),$broker)!==0)continue;
    foreach((array)($st['allocations']??[]) as $a){
      if(!is_array($a))continue; $billId=(string)($a['billId']??''); $bill=is_array($bills[$billId]??null)?$bills[$billId]:[];
      $c=strtoupper(trim((string)($bill['commodity']??'RICE'))); if($c==='MAIZE')$c='CORN'; if($commodity!==''&&$c!==$commodity)continue;
      $rows[]=[
        'date'=>(string)($st['date']??''),'settlementId'=>(string)($st['id']??''),'type'=>(string)($st['type']??''),'broker'=>(string)($st['broker']??''),
        'commodity'=>$c,'soda'=>(string)($a['soda']??(($bill['sodas'][0]??'')?:'')),'billNo'=>(string)($a['billNo']??$bill['billNo']??$billId),
        'truck'=>(string)($a['truck']??''),'pohanch'=>(string)($a['pohanch']??''),'amount'=>round((float)($a['amount']??0),2),
        'paymentMode'=>(string)($st['paymentMode']??($st['type']??'')),'bankName'=>(string)($st['bankName']??''),'reference'=>(string)($st['reference']??''),
        'createdBy'=>(string)($st['createdBy']??'')
      ];
    }
  }
  usort($rows,static fn($a,$b)=>strcmp((string)$b['date'],(string)$a['date'])?:strcmp((string)$b['settlementId'],(string)$a['settlementId']));
  $brokers=array_values(array_unique(array_filter(array_map(static fn($r)=>(string)$r['broker'],$rows)))); sort($brokers,SORT_NATURAL|SORT_FLAG_CASE);
  sph_out(['ok'=>true,'entity'=>$entity,'commodity'=>$commodity,'rows'=>$rows,'brokers'=>$brokers]);
}catch(Throwable $e){sph_out(['ok'=>false,'error'=>'Supplier payment history could not be loaded.'],500);}