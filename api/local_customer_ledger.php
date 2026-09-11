<?php
declare(strict_types=1);
require dirname(__DIR__) . '/auth_store.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
const TT_LCL_FILE = TT_DATA_DIR . '/accounts.json';
function lcl_out(array $d,int $s=200):never{http_response_code($s);echo json_encode($d,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
function lcl_entity(string $v):string{$v=strtoupper(trim($v));if(!in_array($v,['TTI','BRM'],true))lcl_out(['ok'=>false,'error'=>'Customer ledger is available for TTI or BRM.'],422);return $v;}
function lcl_read():array{tt_ensure_data_dir();if(!is_file(TT_LCL_FILE))return []; $h=fopen(TT_LCL_FILE,'r');if($h===false||!flock($h,LOCK_SH))throw new RuntimeException('Accounts storage unavailable.');try{$raw=stream_get_contents($h);}finally{flock($h,LOCK_UN);fclose($h);} $s=$raw?json_decode($raw,true):null;return is_array($s)?$s:[];}
try{
 $u=tt_require_login();if(!tt_user_can_open_module($u,'Accounts'))lcl_out(['ok'=>false,'error'=>'Accounts permission required.'],403);
 if($_SERVER['REQUEST_METHOD']!=='GET')lcl_out(['ok'=>false,'error'=>'Method not allowed.'],405);
 $entity=lcl_entity((string)($_GET['entity']??'TTI'));$party=trim((string)($_GET['party']??''));$s=lcl_read();$rows=[];$parties=[];
 foreach((array)($s['localSalesCandidates']??[]) as $x){
  if(!is_array($x)||($x['entity']??'')!==$entity||empty($x['journalId']))continue;
  if(!in_array((string)($x['status']??''),['Approved / Posted','Posted','Approved'],true))continue;
  $p=trim((string)($x['party']??''));if($p==='')continue;$parties[$p]=true;if($party!==''&&strcasecmp($p,$party)!==0)continue;
  $rows[]=['date'=>(string)($x['saleDate']??''),'sort'=>(string)($x['createdAt']??''),'party'=>$p,'soda'=>(string)($x['soda']??''),'product'=>(string)($x['product']??''),'type'=>'Local Sale','reference'=>(string)($x['gatePass']??$x['sourceKey']??''),'debit'=>round((float)($x['amount']??0),2),'credit'=>0.0];
 }
 foreach((array)($s['localSalesPaymentCandidates']??[]) as $x){
  if(!is_array($x)||($x['entity']??'')!==$entity||empty($x['journalId']))continue;
  if(!in_array((string)($x['status']??''),['Approved / Posted','Posted','Approved'],true))continue;
  $p=trim((string)($x['party']??''));if($p==='')continue;$parties[$p]=true;if($party!==''&&strcasecmp($p,$party)!==0)continue;
  $rows[]=['date'=>(string)($x['paymentDate']??''),'sort'=>(string)($x['reviewedAt']??$x['createdAt']??''),'party'=>$p,'soda'=>(string)($x['soda']??''),'product'=>(string)($x['product']??''),'type'=>(string)($x['paymentType']??'Payment'),'reference'=>(string)($x['reference']??$x['sourceKey']??''),'debit'=>0.0,'credit'=>round((float)($x['amount']??0),2)];
 }
 usort($rows,static fn($a,$b)=>strcmp((string)$a['date'],(string)$b['date'])?:strcmp((string)$a['sort'],(string)$b['sort']));$bal=0.0;foreach($rows as &$r){$bal=round($bal+(float)$r['debit']-(float)$r['credit'],2);$r['balance']=$bal;$r['position']=$bal>0.005?'Receivable':($bal<-.005?'Customer Advance':'Nil');}unset($r);
 $plist=array_keys($parties);sort($plist,SORT_NATURAL|SORT_FLAG_CASE);lcl_out(['ok'=>true,'entity'=>$entity,'party'=>$party,'parties'=>$plist,'rows'=>$rows,'closingBalance'=>$bal,'position'=>$bal>0.005?'Receivable':($bal<-.005?'Customer Advance':'Nil')]);
}catch(Throwable $e){lcl_out(['ok'=>false,'error'=>'Customer ledger could not be loaded.'],500);}

