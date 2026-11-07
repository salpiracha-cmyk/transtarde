<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
require_once dirname(__DIR__).'/runtime_html.php';
$u=tt_require_login();if(!tt_user_can_open_module($u,'Accounts')){http_response_code(403);exit('Accounts permission required.');}
$view=(string)($_GET['view']??'report');if(!in_array($view,['report','print','master'],true))$view='report';
$entities=array_values(array_filter(['TTI','BRM'],static fn($e)=>tt_user_can_access_entity($u,$e,'View')));
if(!$entities){http_response_code(403);exit('Pakistan company access required.');}
$p=$u['permissions']['Accounts']??[];$canTax=($u['role']??'')==='Super Admin'||$p==='all';
if(is_array($p))foreach($p as $right)if(in_array($right,['Create','Edit','Approve'],true)||(is_array($right)&&array_intersect($right,['Create','Edit','Approve'])))$canTax=true;
if($view==='master'&&!$canTax){http_response_code(403);exit('Accounts Create / Edit / Approve permission required.');}
$entity=(string)($_GET['entity']??'');if(!in_array($entity,$entities,true))$entity=$entities[0];
$title=['report'=>'Bag Orders, Receipts & Tax','print'=>'Monthly Bag Supplier Files','master'=>'Bag GST Rates'][$view];
$access=['super'=>($u['role']??'')==='Super Admin','role'=>$u['role']??'','permissions'=>$p,'csrf'=>tt_csrf()];tt_release_read_session();
header('Content-Type: text/html; charset=UTF-8');header('Cache-Control: no-store');ob_start();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=htmlspecialchars($title,ENT_QUOTES,'UTF-8')?> · Transtrade</title><link rel="stylesheet" href="/brand-theme.css"><style>body{margin:0;background:#eef3f6;color:#173747;font-family:Arial,sans-serif}.bag-top{background:#102b43;color:white;display:flex;align-items:center;justify-content:space-between;padding:16px 22px}.bag-top a{color:inherit;text-decoration:none}.bag-top nav{display:flex;align-items:center;gap:18px}.bag-top .power{border-left:1px solid #ffffff44;padding-left:16px;font-size:22px}.bag-page{max-width:1200px;margin:auto;padding:20px}.bag-page select{padding:9px}.bag-page-toolbar{display:flex;align-items:center;gap:20px;flex-wrap:wrap}.bag-page .btn{padding:9px 13px;border:1px solid #c6d4dc;border-radius:7px;text-decoration:none;color:#183e4b;background:white;cursor:pointer}.bag-page .green{background:#16734b;color:white}.bag-page [hidden]{display:none!important}</style></head><body>
<header class="bag-top"><strong>TRANSTRADE ACCOUNTS</strong><nav><span><?=htmlspecialchars((string)($u['full_name']??$u['username']??''),ENT_QUOTES,'UTF-8')?></span><a class="power" href="/logout.php" aria-label="Log out">⏻</a></nav></header>
<main class="bag-page"><div class="bag-page-toolbar"><a href="<?=$view==='master'?'/accounts/?return=bag-master':'/accounts/?return=bag-report'?>">← Accounts</a><a href="/index.php?view=masters&amp;from=accounts">Master Records</a><label>Company <select id="bagPageCompany"><?php foreach($entities as $e):?><option <?=$e===$entity?'selected':''?>><?=htmlspecialchars($e,ENT_QUOTES,'UTF-8')?></option><?php endforeach?></select></label></div><h1><?=htmlspecialchars($title,ENT_QUOTES,'UTF-8')?></h1><section class="workspace active" id="ws-purchases"><div id="purchaseEditor"></div></section></main>
<script>window.TT_ACCOUNT_ACCESS=<?=json_encode($access,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;window.TT_BAG_PAGE_MODE=<?=json_encode($view)?>;localStorage.setItem('tt_accounts_entity',<?=json_encode($entity)?>);</script><script src="/accounts/accounts-form-layout.js"></script><script src="/accounts/print-dialog.js"></script><script src="/accounts/bag-purchases-ui.js"></script><script src="/accounts/bag-report-page.js"></script><script src="/brand-theme.js"></script><script src="/session-activity.js"></script></body></html>
<?php echo tt_version_local_assets((string)ob_get_clean(),'/accounts/');
