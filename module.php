<?php
declare(strict_types=1);
require __DIR__ . '/auth_store.php';
$user = tt_require_login();
$modules = [
    'milling' => __DIR__ . '/milling/Transtrade_Master_Milling_V3_3_2_AUDITED.html',
    'exports' => __DIR__ . '/exports/Transtrade_Exports_Master_Prototype_V2_6_Final_Stabilized.html',
];
$id = strtolower((string)($_GET['id'] ?? ''));
if (!isset($modules[$id]) || !is_file($modules[$id])) { http_response_code(404); exit('Module not found.'); }
$permissionName = $id === 'milling' ? 'Mill' : 'Exports';
if (!tt_user_can_open_module($user, $permissionName)) { http_response_code(403); exit('You do not have permission to open this module.'); }
header('Content-Type: text/html; charset=UTF-8');
$html=(string)file_get_contents($modules[$id]);
$modulePermissions=$user['permissions'][$permissionName] ?? [];
$access=['module'=>$permissionName,'user'=>(string)$user['full_name'],'role'=>(string)$user['role'],'permissions'=>$modulePermissions,'super'=>(($user['role'] ?? '')==='Super Admin')];
$bootstrap='<script>window.TT_MODULE_ACCESS='.json_encode($access,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';</script>';
$guard=<<<'HTML'
<style>#ttUserBar{position:fixed;right:12px;bottom:12px;z-index:99999;background:#102a46;color:#fff;padding:8px 11px;border-radius:10px;box-shadow:0 5px 18px #0004;font:12px Arial}#ttUserBar a{color:#fff;font-weight:800;margin-left:10px}.tt-no-access{display:none!important}</style>
<div id="ttUserBar"><span id="ttUserName"></span><a href="logout.php">Sign out</a></div>
<script>
(()=>{const c=window.TT_MODULE_ACCESS||{},p=c.permissions||{},superUser=!!c.super;document.getElementById('ttUserName').textContent=c.user+' · '+c.role;if(superUser)return;
const norm=s=>String(s||'').toLowerCase().replace(/&/g,'and').replace(/[^a-z0-9]+/g,' ').trim();
const names={
'stock':'stock','arrival list':'queue','arrival pohanch':'arrival','new export bags':'newbags','exports specifications':'instructions','production':'production','export loading':'export','local sales':'local','petty cash':'petty','processing expense':'labour','reprocessing bill':'reprocessbill','used bags in and out':'oldbags','reports':'reports',
'active shipments':'active','sales contracts':'contracts','completed shipments':'completed','cancelled':'cancelled','fi register':'fi','reports and registers':'reports','sales contract':'contract','bag order':'bags','bag artwork and bag order':'bags','production instructions':'production','loading instructions':'loading','customs documents':'customs','b l documents':'bl','commercial documents':'commercial','certificate of origin':'coo','certificates':'certs','bank covering and dispatch':'cover','tg documents':'tg','l c exchange draft':'lcdraft','document output':'print','history and versions':'history'};
let current='';const actions=i=>p==='all'?['View','Create','Edit']:(Array.isArray(p)?p:(p[i]||[]));const can=(i,a)=>p==='all'||actions(i).includes(a);
function idFor(el){let oc=el?.getAttribute?.('onclick')||'',m=oc.match(/(?:openPanel|showTab|openTile)\(['"]([^'"]+)/);if(m)return m[1];let t=norm(el?.querySelector?.('.label,b')?.textContent||el?.textContent);return names[t]||''}
function apply(){document.querySelectorAll('.tile,.tab,#homeGrid .tile,.stock-prominent').forEach(el=>{let i=idFor(el);if(i&&!can(i,'View'))el.classList.add('tt-no-access')});document.querySelectorAll('[data-home-role="admin-only"]').forEach(el=>el.classList.add('tt-no-access'))}
document.addEventListener('click',e=>{let target=e.target.closest('button,.tile,.tab,[onclick]');if(!target)return;let i=idFor(target)||current;if(idFor(target))current=idFor(target);if(i&&!can(i,'View')){e.preventDefault();e.stopImmediatePropagation();alert('This icon is not allowed for your login.');return}let text=norm(target.textContent);let required=/edit|amend|correct|update/.test(text)?'Edit':/(^| )add|(^| )new|create|save|issue|upload|send|confirm|receive|dispatch/.test(text)?'Create':'';if(required&&i&&!can(i,required)){e.preventDefault();e.stopImmediatePropagation();alert(required+' permission is not allowed for this icon.')}},true);
new MutationObserver(apply).observe(document.body,{childList:true,subtree:true});apply();
})();
</script>
HTML;
$html=str_replace('</head>',$bootstrap.'</head>',$html);
echo str_replace('</body>',$guard.'</body>',$html);
