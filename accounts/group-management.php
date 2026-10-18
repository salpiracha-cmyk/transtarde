<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
require_once dirname(__DIR__).'/api/group_management_core.php';
require_once dirname(__DIR__).'/runtime_html.php';
$user=tt_require_login();$origin=($_GET['origin']??'accounts')==='directors'?'Directors':'Accounts';$kind=strtoupper((string)($_GET['kind']??'CASHFLOW'));
if(!in_array($kind,['CASHFLOW','ZAKAT','COSTING','DAILY'],true))$kind='CASHFLOW';
$allowed=array_values(array_filter(['CASHFLOW','ZAKAT','COSTING','DAILY'],static fn($k)=>gm_permission($user,$k,'View',$origin)));
if(!in_array($kind,$allowed,true)){http_response_code(403);exit('Shared group report access is required.');}
$config=['csrf'=>tt_csrf(),'kind'=>$kind,'allowed'=>$allowed,'origin'=>strtolower($origin),'today'=>(new DateTimeImmutable('now',new DateTimeZone('Asia/Karachi')))->format('Y-m-d')];tt_release_read_session();
header('Cache-Control: private, no-store');ob_start();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Group Management — Transtrade</title><link rel="stylesheet" href="/brand-theme.css"><link rel="stylesheet" href="group-management.css"></head><body><header><b>TRANSTRADE</b><span>Group Management</span><nav><span><?=htmlspecialchars((string)($user['full_name']??''),ENT_QUOTES,'UTF-8')?></span><a class="power" href="/logout.php" aria-label="Log out">⏻</a></nav></header><main><a href="<?=$origin==='Directors'?'/directors/index.php':'/accounts/?area=reports'?>">← <?=htmlspecialchars($origin)?></a><h1>Group Management</h1><div class="toolbar"><label>Report<select id="gmKind"></select></label><label>Assessment / report date<input id="gmDate" type="date" value="<?=htmlspecialchars($config['today'])?>"></label><button id="gmOpen">Open / Refresh</button><label>Saved dates<select id="gmSaved"><option value="">Choose a saved report</option></select></label><button id="gmPrint">Print</button><button id="gmCSV">Export CSV</button></div><div id="gmMessage" role="status" aria-live="polite"></div><div id="gmSummary"></div><nav id="gmSections" class="sections" aria-label="Report sections"></nav><section id="gmEditor"></section><section id="gmSources"></section></main><dialog id="gmPostDetail"><button id="gmCloseDetail" aria-label="Close" class="close">×</button><div id="gmDetailBody"></div></dialog><script>window.TT_GROUP_CONFIG=<?=json_encode($config,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;</script><script src="/session-activity.js"></script><script src="/brand-theme.js"></script><script src="group-management.js"></script></body></html>
<?php echo tt_version_local_assets((string)ob_get_clean(),'/accounts/'); ?>
