<?php
declare(strict_types=1);
require __DIR__.'/auth_store.php';
require_once __DIR__.'/api/mill_recovery_core.php';
$user=tt_require_login();
if(($user['role']??'')!=='Super Admin'){http_response_code(403);exit('Super Admin access required.');}
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){http_response_code(405);exit('Read-only review.');}
header('Cache-Control: private, no-store');
tt_release_read_session();
function ir_escape(mixed $value):string{return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function ir_read(string $path):array{if(!is_file($path))throw new RuntimeException('Required Accounts source is unavailable.');$h=fopen($path,'rb');if(!$h||!flock($h,LOCK_SH))throw new RuntimeException('Accounts source is busy.');try{return mr_decode_store((string)stream_get_contents($h));}finally{flock($h,LOCK_UN);fclose($h);}}
function ir_matches(array $row,array $tokens):bool{$text=json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);foreach($tokens as $token)if($token!==''&&stripos($text,$token)!==false)return true;return false;}
function ir_table(array $rows):void{if(!$rows){echo '<p>No matching saved records.</p>';return;}$columns=[];foreach($rows as $r)foreach($r as $k=>$v)if(!is_array($v)&&!is_object($v))$columns[$k]=true;echo '<div class="scroll"><table><thead><tr>';foreach($columns as $k=>$v)echo '<th>'.ir_escape($k).'</th>';echo '<th>Saved details</th></tr></thead><tbody>';foreach($rows as $r){echo '<tr>';foreach($columns as $k=>$v)echo '<td>'.ir_escape(is_bool($r[$k]??null)?(($r[$k]??false)?'Yes':'No'):($r[$k]??'')).'</td>';echo '<td><details><summary>View record</summary><pre>'.ir_escape(json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)).'</pre></details></td></tr>';}echo '</tbody></table></div>';}
$error='';$sections=[];$pending=[];$backend='';
try{
 $accounts=ir_read(TT_DATA_DIR.'/accounts.json');
 foreach((array)($accounts['bridgeOutbox']??[]) as $item)if(is_array($item)&&($item['status']??'Pending')!=='Posted')$pending[]=$item;
 $tokens=['TG/AMT/13','PS-RICE-26003'];
 foreach($pending as $item)if(ir_matches($item,$tokens)){foreach(['container','liftingId'] as $field)if(!empty($item['body'][$field]))$tokens[]=(string)$item['body'][$field];}
 $ops=mr_read_operations_source(TT_DATA_DIR.'/operations.json',['tt35exmill','tt35exload','tt40exinstructions','transtrade_export_v3_operational']);$backend=$ops['sourceBackend'];
 foreach(['tt35exmill'=>'Mill purchase instructions','tt35exload'=>'Original Mill lifting records','tt40exinstructions'=>'Export loading instructions'] as $key=>$label){$rows=mr_decode_store((string)($ops['values'][$key]??'[]'));$sections[$label]=array_values(array_filter($rows,static fn($r)=>is_array($r)&&ir_matches($r,$tokens)));}
 foreach(['purchaseSodas','purchaseSodasV2','purchaseSodaLiftings','commodityBills','journals','events'] as $key){$rows=[];foreach((array)($accounts[$key]??[]) as $id=>$row)if(is_array($row)&&ir_matches($row,$tokens))$rows[]=['recordKey'=>(string)$id]+$row;$sections['Accounts — '.$key]=$rows;}
 $root=mr_decode_store((string)($ops['values']['transtrade_export_v3_operational']??'{}'));
 foreach(['contracts','shipments'] as $key){$rows=[];foreach((array)($root[$key]??[]) as $row)if(is_array($row)&&ir_matches(array_intersect_key($row,array_flip(['ref','contractRef','lotId'])),$tokens)){$safe=array_intersect_key($row,array_flip(['id','kind','ref','contractRef','lotId','date','seller','buyer','customerId','qty','containers','status','completed','cancelled','loadingPlan','millActuals','packings']));$rows[]=$safe;}$sections['Exports — '.$key]=$rows;}
 $customers=[];foreach((array)($root['customers']??[]) as $row)if(is_array($row))$customers[]=array_intersect_key($row,array_flip(['id','masterId','name','address','notifies','consignees','additionalNotifies']));$sections['Operational customer snapshots — compare with current master']=$customers;
 $currentCustomers=[];foreach((array)(tt_list_masters()['export_customers']??[]) as $row){$v=$row['values']??[];$currentCustomers[]=['name'=>$v[0]??'','address'=>$v[3]??'','notifies'=>json_decode((string)($v[9]?:'[]'),true)];}$sections['Current customer master — used for active documents']=$currentCustomers;
}catch(Throwable $e){$error='The review could not read all required sources. Refresh to try again; no records were changed.';}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Accounts integrity review — Transtrade</title><link rel="stylesheet" href="/brand-theme.css">
<style>
body{margin:0;font:15px system-ui;color:var(--tt-brand-ink)}
header{padding:18px;display:flex;align-items:center;gap:20px;flex-wrap:wrap}
header a,header button{color:inherit}
header button{background:transparent;border:0;border-left:1px solid var(--tt-brand-line);padding:6px 12px;cursor:pointer;font-size:18px}
main{padding:22px}
section{background:white;padding:18px;margin:16px 0;border:1px solid var(--tt-brand-line);border-radius:10px}
.scroll{overflow:auto}
table{border-collapse:collapse;width:100%}
th,td{text-align:left;vertical-align:top;border:1px solid var(--tt-brand-line);padding:8px;min-width:90px;max-width:280px;overflow-wrap:anywhere;white-space:pre-wrap}
th{background:var(--tt-brand-soft)}
td:last-child:has(details[open]){min-width:min(420px,65vw)}
summary{cursor:pointer;color:var(--tt-brand-deep)}
pre{white-space:pre-wrap;overflow-wrap:anywhere;background:var(--tt-brand-ivory);padding:10px;margin:8px 0 0}
.error{background:#fee2e2;padding:15px;color:#991b1b}
.summary{font-weight:700}
h1{font-size:22px;margin:0}
</style></head><body><header><a href="/">Super Admin</a><h1>Accounts integrity review</h1><form method="post" action="logout.php" style="margin-left:auto"><input type="hidden" name="csrf" value="<?=ir_escape(tt_csrf())?>"><button aria-label="Sign out">⏻</button></form></header><main><p class="summary">TG/AMT/13 · Read-only comparison · <?=ir_escape(count($pending))?> pending Accounts handoffs</p><p>This review changes no weights, liabilities or postings. Pending handoff payloads can differ from original Mill records and existing Accounts journals. Compare the saved records before deciding on any correction.</p><?php if($error):?><p role="alert" class="error"><?=ir_escape($error)?></p><?php else:?><p>Operational source: <?=ir_escape($backend)?> · Accounts revision <?=ir_escape($accounts['revision']??0)?></p><?php endif?><section><h2>Pending Accounts handoffs</h2><?php ir_table($pending);?></section><?php foreach($sections as $label=>$rows):?><section><h2><?=ir_escape($label)?></h2><?php ir_table($rows);?></section><?php endforeach?></main></body></html>
