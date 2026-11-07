<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
require_once dirname(__DIR__).'/api/opening_stock_store.php';
$user=tt_require_login();if(!osb_authorized($user)){http_response_code(403);exit('Opening stock requires management access to TTI.');}
header('Cache-Control: no-store');
if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','POST'],true)){header('Allow: GET, POST');http_response_code(405);exit('Method not allowed.');}
$error='';$saved=false;
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    if(!tt_verify_csrf((string)($_POST['csrf']??''))){http_response_code(419);$error='Session expired. Reopen the page.';}
    else try{osb_save($_POST,$user);$saved=true;}catch(DomainException|InvalidArgumentException $e){http_response_code(422);$error=$e->getMessage();}catch(Throwable $e){error_log('Opening stock: '.$e->getMessage());http_response_code($e->getCode()===409?409:500);$error=$e->getCode()===409?$e->getMessage():'Opening stock could not be saved. Reopen and check the saved records before retrying.';}
}
try{$json=tt_inv_read_values()['tt32stockadj']??'[]';$existing=osb_existing($json);}catch(Throwable $e){http_response_code(503);exit('Stock source is unavailable. No opening stock has been assumed.');}
$esc=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Opening Stock · Accounts</title><link rel="stylesheet" href="/brand-theme.css"><style>body{margin:0;background:#eef3f2;color:#173139;font:16px Arial}header{display:flex;gap:20px;padding:18px;background:#173139;color:#fff}header a{color:inherit}main{max-width:800px;margin:24px auto;background:white;padding:24px;border-radius:12px}table{width:100%;border-collapse:collapse}th,td{padding:12px;text-align:left;border-bottom:1px solid #ddd}input{padding:10px;width:150px}button{padding:12px;background:#167356;color:white;border:0;border-radius:6px;margin-top:18px}.error{color:#ae2020}.success{color:#167356}.power{margin-left:auto}</style></head><body>
<header><b>TRANSTRADE · Accounts</b><span><?= $esc($user['full_name']??'') ?></span><a class="power" href="/logout.php" aria-label="Log out">⏻</a></header><main>
<a href="/accounts/opening-balances.php">← Accounts Balance B/F</a><h1>Opening by-product stock</h1>
<p>TTI Rice Mills · Transtrade International · IRRI-6 · <strong>1 July 2026</strong></p>
<p>Physical quantities only. No purchase, production or monetary journal is created.</p>
<?php if($error):?><p role="alert" class="error"><?= $esc($error) ?></p><?php endif ?>
<?php if($saved):?><p role="status" class="success">Opening stock saved and verified from the server.</p><?php endif ?>
<?php if($existing):?><h2>Saved opening quantities</h2><table><thead><tr><th>Product</th><th>Tonnes</th><th>Kilograms</th></tr></thead><tbody><?php foreach($existing as $r):foreach(($r['byProducts']??[]) as $n=>$kg):?><tr><td><?= $esc($n) ?></td><td><?= $esc(number_format((float)$kg/1000,3)) ?></td><td><?= $esc(number_format((float)$kg,0)) ?></td></tr><?php endforeach;endforeach ?></tbody></table><p>This opening is recorded. Reopening or refreshing does not add it again.</p>
<?php else:?><form method="post"><input type="hidden" name="csrf" value="<?= $esc(tt_csrf()) ?>"><input type="hidden" name="date" value="<?= OSB_DATE ?>"><input type="hidden" name="revision" value="<?= hash('sha256',$json) ?>"><table><thead><tr><th>Product</th><th>Opening tonnes</th></tr></thead><tbody><?php foreach(OSB_PRODUCTS as $name):?><tr><td><label for="os-<?= $esc(str_replace(' ','-',$name)) ?>"><?= $esc($name) ?></label></td><td><input id="os-<?= $esc(str_replace(' ','-',$name)) ?>" name="tonnes[<?= $esc($name) ?>]" inputmode="decimal" type="number" step="any" min="0.001" max="100000" required value="<?= $esc($_POST['tonnes'][$name]??'') ?>"></td></tr><?php endforeach ?></tbody></table><button type="submit">Confirm opening stock</button></form><?php endif ?>
</main></body></html>
