<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
require_once dirname(__DIR__).'/api/opening_balance_core.php';
require_once dirname(__DIR__).'/runtime_html.php';
$user=tt_require_login();
if(!job_authorized($user)){http_response_code(403);exit('Opening balances require Super Admin or authorised Directors access.');}
$entities=job_entities($user);
if(!$entities){http_response_code(403);exit('No company books have been assigned.');}
$access=['role'=>$user['role']??'', 'user'=>$user['full_name']??'', 'super'=>($user['role']??'')==='Super Admin', 'csrf'=>tt_csrf(), 'entities'=>$entities, 'masters'=>tt_user_visible_masters($user), 'masterPermissions'=>$user['master_permissions']??[]];
$return=($user['role']??'')==='Super Admin'?'../index.php':'../directors/index.php';
header('Content-Type: text/html; charset=UTF-8');
ob_start();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Opening Balances — Transtrade</title>
<style>body{margin:0;background:#eef3f2;color:#173139;font:14px Arial,sans-serif}.topbar{background:#173139;color:white;display:flex;align-items:center;gap:16px;padding:16px 22px}.topbar a{color:inherit}.sp{flex:1}.power{border-left:1px solid #ffffff55;padding-left:16px;font-size:22px;text-decoration:none}.workspace{max-width:1200px;margin:20px auto;background:white;border-radius:12px}.panelHead{padding:18px}.panelHead h2{margin:10px 0}.backBtn,.btn{background:white;border:1px solid #bbcdca;border-radius:7px;padding:9px 12px;cursor:pointer;color:#173139;text-decoration:none}.btn.green{background:#167356;color:white}.btn:disabled{opacity:.5;cursor:default}.tableWrap{overflow-x:auto}input,select{box-sizing:border-box}[hidden]{display:none!important}.jvw-extra-fields label{display:flex;flex-direction:column;gap:5px}</style>
<link rel="stylesheet" href="/brand-theme.css?v=20261006-number-entry-1"></head><body>
<header class="topbar"><b>TRANSTRADE</b><span>Opening Balances</span><span class="sp"></span><span><?=htmlspecialchars((string)($user['full_name']??''))?> · <?=htmlspecialchars((string)($user['role']??''))?></span><a class="power" href="../logout.php" aria-label="Log out" title="Log out">⏻</a></header>
<main id="ws-jv" class="workspace active"></main>
<script>window.TT_ACCOUNT_ACCESS=<?=json_encode($access,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;window.TT_OPENING_ONLY=true;const allowed=window.TT_ACCOUNT_ACCESS.entities;if(!allowed.includes(localStorage.getItem('tt_accounts_entity')))localStorage.setItem('tt_accounts_entity',allowed[0]);document.addEventListener('click',e=>{if(e.target.closest('[data-back]'))location.href=<?=json_encode($return)?>});</script>
<script src="/session-activity.js?v=20260929-1"></script><script src="/brand-theme.js?v=20261006-number-entry-1"></script><script src="master-autocomplete.js?v=20261006-roles-2"></script><script src="jv-workflow-ui.js?v=20261006-party-opening-2"></script><script>window.TT_JV_WORKFLOW.load();</script>
</body></html>
<?php echo tt_version_local_assets((string)ob_get_clean(),'/accounts/'); ?>
