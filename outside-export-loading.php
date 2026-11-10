<?php
declare(strict_types=1);
require __DIR__.'/auth_store.php';
require_once __DIR__.'/runtime_html.php';
$user=tt_require_login();
$mill=($_GET['origin']??'')==='mill';
$module=$mill?'Mill':'Exports';
if(!tt_user_can_open_module($user,$module)||!tt_user_can_module_action($user,$module,$mill?'export':'loading','View')){
    http_response_code(403);exit('Loading access is required.');
}
$config=['origin'=>$mill?'mill':'exports','csrf'=>tt_csrf()];
tt_release_read_session();
header('Cache-Control: private, no-store');
ob_start();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Outside Mill Inspection &amp; Loading</title>
<link rel="stylesheet" href="/accounts/group-management.css"><link rel="stylesheet" href="/directors/exporter-trading.css">
</head><body><header><b>TRANSTRADE</b><span><?=htmlspecialchars($module)?> · Outside loading</span><nav><a class="power" href="/logout.php" aria-label="Log out">⏻</a></nav></header>
<main><a href="/module.php?id=<?=$mill?'milling':'exports'?>">← <?=htmlspecialchars($module)?></a><h1>Outside Mill Inspection &amp; Loading</h1>
<div class="toolbar"><label>Find by contract, lot, site or container<input type="search" id="oelSearch"></label><button type="button" id="oelRefresh">Refresh</button></div>
<p id="oelStatus" role="status" aria-live="polite"></p><section id="oelWorkspace" class="outsideLoadingWorkspace"></section></main>
<script>window.TT_OUTSIDE_LOADING=<?=json_encode($config,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;</script>
<script src="/session-activity.js"></script><script src="/accounts/outside-export-loading.js"></script></body></html>
<?php echo tt_version_local_assets((string)ob_get_clean(),'/'); ?>
