<?php
declare(strict_types=1);
require dirname(__DIR__) . '/auth_store.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

const TT_SL_FILE = TT_DATA_DIR . '/accounts.json';
function sl_out(array $d,int $s=200): never { http_response_code($s); echo json_encode($d,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); exit; }
function sl_entity(string $v): string { $v=strtoupper(trim($v)); if(!in_array($v,['TTI','BRM'],true))sl_out(['ok'=>false,'error'=>'Supplier ledger is available for TTI or BRM.'],422); return $v; }
function sl_read(): array { tt_ensure_data_dir(); if(!is_file(TT_SL_FILE))return []; $h=fopen(TT_SL_FILE,'r'); if($h===false||!flock($h,LOCK_SH))throw new RuntimeException('Accounts storage unavailable.'); try{$raw=stream_get_contents($h);}finally{flock($h,LOCK_UN);fclose($h);} $s=$raw?json_decode($raw,true):null; return is_array($s)?$s:[]; }
function sl_commodity(array $b): string { $c=strtoupper(trim((string)($b['commodity']??'RICE'))); return $c==='MAIZE'?'CORN':$c; }

try{
  $u=tt_require_login(); if(!tt_user_can_open_module($u,'Accounts'))sl_out(['ok'=>false,'error'=>'Accounts permission required.'],403);
  if($_SERVER['REQUEST_METHOD']!=='GET')sl_out(['ok'=>false,'error'=>'Method not allowed.'],405);
  $entity=sl_entity((string)($_GET['entity']??'TTI')); $broker=trim((string)($_GET['broker']??''));
  $s=sl_read(); $rows=[]; $brokers=[];
  foreach((array)($s['commodityBills']??[]) as $bill){
    if(!is_array($bill)||($bill['entity']??'')!==$entity)continue;
    if(!in_array((string)($bill['status']??''),['Verified / Posted','Posted','Outstanding','Paid'],true))continue;
    $b=trim((string)($bill['broker']??'')); if($b==='')continue; $brokers[$b]=true; if($broker!==''&&strcasecmp($broker,$b)!==0)continue;
    $amt=round((float)($bill['supplierPayableTotal']??($bill['finalCommodityValue']??0)),2); if($amt<=0)continue;
    $rows[]=['date'=>(string)($bill['billDate']??''),'sort'=>10,'type'=>'PURCHASE_BILL','description'=>'Purchase Bill','broker'=>$b,'commodity'=>sl_commodity($bill),'soda'=>(string)(($bill['sodas'][0]??'')?:''),'billNo'=>(string)($bill['billNo']??$bill['id']??''),'reference'=>(string)($bill['id']??''),'charge'=>$amt,'payment'=>0.0,'advance'=>0.0];
  }
  foreach((array)($s['supplierAdvances']??[]) as $a){
    if(!is_array($a)||($a['entity']??'')!==$entity)continue; $b=trim((string)($a['supplier']??'')); if($b==='')continue; $brokers[$b]=true; if($broker!==''&&strcasecmp($broker,$b)!==0)continue;
    $amt=round((float)($a['amount']??0),2); if($amt<=0)continue;
    $rows[]=['date'=>(string)($a['date']??''),'sort'=>20,'type'=>'SUPPLIER_ADVANCE','description'=>'Supplier Advance','broker'=>$b,'commodity'=>'','soda'=>(string)($a['soda']??''),'billNo'=>'','reference'=>(string)($a['id']??''),'charge'=>0.0,'payment'=>$amt,'advance'=>$amt];
  }
  foreach((array)($s['supplierSettlements']??[]) as $st){
    if(!is_array($st)||($st['entity']??'')!==$entity)continue; if(!in_array((string)($st['status']??''),['Posted','Approved / Posted','Approved'],true))continue;
    $b=trim((string)($st['broker']??'')); if($b==='')continue; $brokers[$b]=true; if($broker!==''&&strcasecmp($broker,$b)!==0)continue;
    $type=(string)($st['type']??'');
    if($type==='Supplier Advance Applied'){
      $rows[]=['date'=>(string)($st['date']??''),'sort'=>30,'type'=>'ADVANCE_APPLIED','description'=>'Advance Applied','broker'=>$b,'commodity'=>'','soda'=>'','billNo'=>'','reference'=>(string)($st['id']??''),'charge'=>0.0,'payment'=>0.0,'advance'=>0.0];
      continue;
    }
    $amt=round((float)($st['amount']??0),2); if($amt<=0)continue;
    $sodas=[];$bills=[];$commodity='';
    foreach((array)($st['allocations']??[]) as $al){ if(!is_array($al))continue; if(($al['soda']??'')!=='')$sodas[(string)$al['soda']]=true; if(($al['billNo']??'')!=='')$bills[(string)$al['billNo']]=true; $billId=(string)($al['billId']??''); $bill=$s['commodityBills'][$billId]??null; if(is_array($bill))$commodity=sl_commodity($bill); }
    $rows[]=['date'=>(string)($st['date']??''),'sort'=>40,'type'=>'PAYMENT','description'=>'Supplier Payment','broker'=>$b,'commodity'=>$commodity,'soda'=>implode(', ',array_keys($sodas)),'billNo'=>implode(', ',array_keys($bills)),'reference'=>(string)($st['reference']??$st['id']??''),'charge'=>0.0,'payment'=>$amt,'advance'=>0.0];
  }
  usort($rows,static fn($a,$b)=>strcmp((string)$a['date'],(string)$b['date'])?:((int)$a['sort']<=> (int)$b['sort'])?:strcmp((string)$a['reference'],(string)$b['reference']));
  $balance=0.0; foreach($rows as &$r){ $balance=round($balance+(float)$r['charge']-(float)$r['payment'],2); $r['balance']=$balance; $r['position']=$balance>0.005?'Payable':($balance<-.005?'Advance':'Nil'); } unset($r);
  $brokerList=array_keys($brokers); sort($brokerList,SORT_NATURAL|SORT_FLAG_CASE);
  sl_out(['ok'=>true,'entity'=>$entity,'broker'=>$broker,'brokers'=>$brokerList,'rows'=>$rows,'balance'=>$balance,'position'=>$balance>0.005?'Payable':($balance<-.005?'Advance':'Nil')]);
}catch(Throwable $e){sl_out(['ok'=>false,'error'=>'Supplier ledger could not be loaded.'],500);}

