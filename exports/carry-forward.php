<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
require_once dirname(__DIR__).'/api/carry_forward_core.php';
require_once dirname(__DIR__).'/runtime_html.php';
$u=tt_require_login();if(!cf_entities($u)){http_response_code(403);exit('Accounts or authorised management permission required.');}
$access=['csrf'=>tt_csrf(),'user'=>(string)($u['full_name']??$u['username']??''),'entities'=>cf_entities($u)];tt_release_read_session();
header('Cache-Control: no-store');header('Content-Type: text/html; charset=UTF-8');
ob_start();?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Carry-forward Shipments · Transtrade</title><link rel="stylesheet" href="/brand-theme.css"><link rel="stylesheet" href="/exports/carry-forward.css"></head><body>
<header class="cf-top"><a href="/index.php">TRANSTRADE</a><strong>Carry-forward Shipments</strong><nav><a href="/index.php?view=masters&amp;from=exports" aria-label="Master Records" title="Master Records">M</a><span><?=htmlspecialchars($access['user'],ENT_QUOTES,'UTF-8')?></span><a href="/logout.php" aria-label="Sign out" title="Sign out">⏻</a></nav></header>
<main id="cf-main"><div class="cf-toolbar"><a href="/module.php?id=exports">← Exports</a><a href="/accounts/">Accounts</a><label>Company<select id="cf-company"></select></label><button type="button" id="cf-refresh">Refresh register</button><button type="button" id="cf-new" hidden>＋ Carry-forward shipment</button></div><div id="cf-message" role="status"></div><section id="cf-content" aria-live="polite">Loading shipment register…</section></main>
<script>window.TT_CARRY_FORWARD_ACCESS=<?=json_encode($access,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;</script><script src="/exports/carry-forward-ui.js"></script><script src="/brand-theme.js"></script><script src="/session-activity.js"></script></body></html>
<?php echo tt_version_local_assets((string)ob_get_clean(),'/exports/');
