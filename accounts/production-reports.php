<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
require_once dirname(__DIR__).'/inventory_reconciliation.php';
require_once dirname(__DIR__).'/runtime_html.php';
$user=tt_require_login();$accounts=tt_user_can_open_module($user,'Accounts');
$mill=tt_user_can_open_module($user,'Mill')&&tt_inv_has_action($user,'Mill',['reports','production']);
$entities=array_values(array_filter(['TTI','BRM'],static fn($e)=>($accounts?tt_user_can_access_entity($user,$e,'View'):true)&&(tt_inv_can_report($user,$e)||$mill)));
if(!$entities){http_response_code(403);exit('Production report access is required.');}
$entity=is_string($_GET['entity']??null)?$_GET['entity']:'';if(!in_array($entity,$entities,true))$entity=$entities[0];
$config=['entity'=>$entity,'entities'=>$entities];tt_release_read_session();
header('Content-Type: text/html; charset=UTF-8');header('Cache-Control: no-store');ob_start();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Production & Recovery Reports · Transtrade</title><link rel="stylesheet" href="/brand-theme.css"><style>body{font-family:Arial,sans-serif;margin:0;background:#eef3f6;color:#183e30}header{background:#102b43;color:white;padding:16px 22px;display:flex;gap:20px;align-items:center}header nav{margin-left:auto;display:flex;align-items:center;gap:18px}header a{color:inherit;text-decoration:none}.power{border-left:1px solid #ffffff44;padding-left:16px;font-size:22px}main{padding:20px;max-width:1600px;margin:20px auto;background:white;border-radius:10px}.mr-back{display:inline-block;margin-bottom:15px}@media(max-width:600px){header,main{padding:12px}main{margin:10px}header strong{font-size:14px}}</style></head><body><header><strong>TRANSTRADE · PRODUCTION REPORTS</strong><nav><span><?=htmlspecialchars((string)($user['full_name']??$user['username']??''),ENT_QUOTES,'UTF-8')?></span><a class="power" href="/logout.php" aria-label="Sign out">⏻</a></nav></header><main><a class="mr-back" href="<?=$accounts?'/accounts/?return=production-report':'/module.php?module=Mill'?>">← <?=$accounts?'Accounts':'Milling'?></a><section id="productionRecoveryReport"></section></main><script>window.TT_RECOVERY_CONFIG=<?=json_encode($config,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;</script><script src="/brand-theme.js"></script><script src="/accounts/mill-recovery-ui.js"></script><script src="/session-activity.js"></script><script>TT_MILL_RECOVERY_REPORTS.mount(document.getElementById('productionRecoveryReport'),window.TT_RECOVERY_CONFIG);</script></body></html>
<?php echo tt_version_local_assets((string)ob_get_clean(),'/accounts/');
