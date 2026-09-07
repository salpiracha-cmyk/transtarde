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
$html = (string)file_get_contents($modules[$id]);
$modulePermissions = $user['permissions'][$permissionName] ?? [];
$access = [
    'module'=>$permissionName, 'moduleId'=>$id, 'user'=>(string)$user['full_name'],
    'role'=>(string)$user['role'], 'permissions'=>$modulePermissions,
    'super'=>(($user['role'] ?? '')==='Super Admin'), 'csrf'=>tt_csrf(),
];
$bootstrap = '<script>window.TT_MODULE_ACCESS='.json_encode($access, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';</script>';
$sharedBootstrap = <<<'HTML'
<script id="tt-shared-operations-bootstrap">
(()=>{
  const access=window.TT_MODULE_ACCESS||{}, endpoint='api/operations.php';
  const allowed=k=>k==='transtrade_export_v2_operational'||/^tt[0-9]{2}[a-z0-9_]{2,60}$/.test(k);
  const originalSet=Storage.prototype.setItem, originalRemove=Storage.prototype.removeItem;
  let applying=false, revision=0, remoteKeys=new Set(), pending=new Map(), timer=0, lastRemoteBy='';
  const directSet=(k,v)=>originalSet.call(localStorage,k,v);
  const parse=(k,d)=>{try{const v=JSON.parse(localStorage.getItem(k));return v??d}catch{return d}};
  const stableId=s=>{let h=2166136261;for(const c of String(s)){h^=c.charCodeAt(0);h=Math.imul(h,16777619)}return 600000000+(h>>>0)%300000000};
  const put=(k,v)=>{const s=JSON.stringify(v);if(localStorage.getItem(k)!==s)localStorage.setItem(k,s)};

  function getRemote(sync=true){
    try{
      const x=new XMLHttpRequest();x.open('GET',endpoint+'?r='+Date.now(),!sync);x.withCredentials=true;
      if(sync){x.send();return x.status===200?JSON.parse(x.responseText):null}
      x.onload=()=>{if(x.status===200)applyRemote(JSON.parse(x.responseText),false)};x.send();
    }catch(e){return null}
  }
  function applyRemote(data,initial){
    if(!data?.ok)return;
    const incoming=Number(data.revision||0), changed=[];applying=true;
    Object.entries(data.values||{}).forEach(([k,v])=>{remoteKeys.add(k);if(allowed(k)&&typeof v==='string'&&!pending.has(k)&&localStorage.getItem(k)!==v){directSet(k,v);changed.push(k);lastRemoteBy=data.meta?.[k]?.updatedBy||lastRemoteBy}});
    applying=false;revision=Math.max(revision,incoming);window.TRANSTRADE_SERVER_NOW_ISO=data.serverNow||window.TRANSTRADE_SERVER_NOW_ISO;
    if(changed.length&&!initial){bridge();notifyRemote()}
  }
  function flush(){
    clearTimeout(timer);timer=0;
    for(const [key,value] of [...pending]){
      pending.delete(key);
      fetch(endpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:access.csrf,key,value})})
        .then(r=>r.json()).then(r=>{if(r.ok)revision=Math.max(revision,Number(r.revision||0));else showSyncError(r.error)}).catch(()=>showSyncError('Shared save is temporarily unavailable.'));
    }
  }
  function queue(key,value){if(!allowed(key)||applying)return;pending.set(key,String(value));clearTimeout(timer);timer=setTimeout(flush,180)}
  Storage.prototype.setItem=function(k,v){originalSet.call(this,k,v);if(this===localStorage)queue(String(k),String(v))};
  Storage.prototype.removeItem=function(k){originalRemove.call(this,k);};

  const initial=getRemote(true);if(initial)applyRemote(initial,true);

  function exportsToMill(){
    const root=parse('transtrade_export_v2_operational',null);if(!root?.millSync)return;
    const contracts=root.contracts||[], contract=ref=>contracts.find(c=>c.ref===ref)||{};
    let bags=parse('tt30bags',[]), oldBags=new Map(bags.filter(x=>x._ttBridge==='exports').map(x=>[x._ttBridgeId,x]));bags=bags.filter(x=>x._ttBridge!=='exports');
    (root.millSync.newExportBags||[]).forEach(x=>{const bid=x.poNo+'|'+x.line,old=oldBags.get(bid)||{};bags.push({...old,id:old.id||stableId('bag|'+bid),_ttBridge:'exports',_ttBridgeId:bid,contractRef:x.contractRef,poNo:x.poNo,brand:x.brand||'UNNAMED BRAND',size:String(x.size||'')+' '+(String(x.unit||'KG').toUpperCase().startsWith('LB')?'lb':'kg'),tare:String(x.orderedTare||0)+' g',supplier:x.supplier||'',ordered:Number(x.totalOrdered||x.requiredBags||0),mill:x.deliverTo||'TTI Rice Mills',received:Number(old.received||0),artworkName:x.artworkName||'',artworkData:x.artworkData||'',status:x.status||'Order from Export — Awaiting Receipt'})});put('tt30bags',bags);

    let pis=parse('tt30prodinst',[]),oldPis=new Map(pis.filter(x=>x._ttBridge==='exports').map(x=>[x._ttBridgeId,x]));pis=pis.filter(x=>x._ttBridge!=='exports');
    (root.millSync.productionInstructions||[]).forEach(x=>{const c=contract(x.contractRef),prod=x.production||{};(x.packings||[]).forEach((pk,i)=>{const bid=x.contractRef+'|'+i,old=oldPis.get(bid)||{},q=c.quality||prod.qualityNotes||'As per export contract';pis.push({...old,id:old.id||stableId('pi|'+bid),_ttBridge:'exports',_ttBridgeId:bid,contractRef:x.contractRef,ref:'PI-'+String(x.contractRef||'').replace(/[^A-Za-z0-9]/g,'-')+(x.packings.length>1?'-'+String.fromCharCode(65+i):''),brand:pk.brand||'UNNAMED BRAND',variety:x.product||c.product||'',quality:q,requiredMT:Number(pk.containers||0)*Number(pk.weightPer||0),producedMT:Number(old.producedMT||0),packing:`${pk.size||''} ${String(c.packingUnit||'KG').toUpperCase().startsWith('LB')?'lb':'kg'} ${pk.type||'export bags'}`,requiredBy:prod.requiredBy||'',broken:q,moisture:'As per contract',chalky:'As per contract',damage:'As per contract',foreign:'As per contract',polish:prod.qualityNotes||'As per approved sample / contract',special:c.quality||prod.qualityNotes||'Follow Export instructions and keep brand separate.',bagSupplier:'As per Export Bag Order',inspection:prod.inspection||c.inspection||'None',dpp:prod.dpp||'Yes'})})});put('tt30prodinst',pis);

    let ships=parse('tt30ship',[]),oldShips=new Map(ships.filter(x=>x._ttBridge==='exports').map(x=>[x._ttBridgeId,x]));ships=ships.filter(x=>x._ttBridge!=='exports');
    let exmills=parse('tt35exmill',[]),oldEx=new Map(exmills.filter(x=>x._ttBridge==='exports').map(x=>[x._ttBridgeId,x]));exmills=exmills.filter(x=>x._ttBridge!=='exports');
    (root.millSync.exportLoading||[]).forEach(x=>{const c=contract(x.contractRef),prod=x.production||{};(x.plan?.allocations||[]).forEach((a,i)=>{const bid=x.lotId+'|'+i,pk=(c.packings||[])[Number(a.packIndex||0)]||(c.packings||[])[0]||{},unitKg=String(c.packingUnit||'KG').toUpperCase().startsWith('LB')?Number(pk.size||0)*.45359237:Number(pk.size||0),bpc=unitKg?Math.round(Number(a.weightPer||0)*1000/unitKg):0,isExternal=/external|ex-mill/i.test(String(a.type||''))&&!/transtrade|tti rice/i.test(String(a.name||''));if(isExternal){const old=oldEx.get(bid)||{};exmills.push({...old,id:old.id||stableId('xm|'+bid),_ttBridge:'exports',_ttBridgeId:bid,soda:'XM-'+String(x.contractRef||'').replace(/[^A-Za-z0-9]/g,'-')+'-'+(i+1),mill:a.name||'External Mill',broker:'Direct',product:c.product||'',qtyKg:Number(a.containers||0)*Number(a.weightPer||0)*1000,loadedKg:Number(old.loadedKg||0),containerBasis:true,deliveryTo:'Ex-Mill',quality:c.quality||'As per export contract',packing:`${pk.size||''} ${c.packingUnit||'KG'} ${pk.type||'bags'}`,special:prod.qualityNotes||c.quality||'',bagTare:String(pk.tare||0)+' g',dryon:prod.dryOn||'No',craft:prod.craftPaper||'No',inspection:a.inspection||prod.inspection||c.inspection||'None',dpp:a.dpp||prod.dpp||'Yes',shipment:x.lotId,contractRef:x.contractRef});return}const old=oldShips.get(bid)||{};ships.push({...old,id:old.id||stableId('ship|'+bid),_ttBridge:'exports',_ttBridgeId:bid,_ttLotId:x.lotId,contractRef:x.contractRef,ref:x.lotId,brand:pk.brand||'UNNAMED BRAND',shipping:c.shippingLine||'As advised by Export',mill:a.name||'TTI Rice Mills',totalContainers:Number(a.containers||0),bagsPerContainer:bpc,bagSize:Number(pk.size||0),inspection:a.inspection||prod.inspection||c.inspection||'None',dpp:a.dpp||prod.dpp||'Yes',emptyRequired:Number(a.emptyBags||0),status:old.status||'Open',containers:old.containers||[],audit:old.audit||[],reportedBrandKg:Number(a.containers||0)*Number(a.weightPer||0)*1000,recovery:Number(old.recovery||.60),byProducts:old.byProducts||{'B2':.32,'CSR':.03,'Powder':.03,'Other':.02}})})});put('tt30ship',ships);put('tt35exmill',exmills);
  }

  function millToExports(){
    const root=parse('transtrade_export_v2_operational',null);if(!root?.shipments)return;let changed=false;
    const add=(shipment,row,location)=>{const lot=root.shipments.find(s=>String(s.id)===String(shipment));if(!lot||!row?.container)return;lot.millActuals=lot.millActuals||[];if(lot.millActuals.some(a=>String(a.number).toUpperCase()===String(row.container).toUpperCase()))return;const c=(root.contracts||[]).find(c=>c.ref===lot.contractRef)||{},pk=(c.packings||[]).find(p=>String(p.brand||'').toUpperCase()===String(row.brand||'').toUpperCase())||(c.packings||[])[0]||{},tare=Number(row.bags||0)*Number(pk.tare||0)/1000,net=Number(row.weight??row.kg??0);lot.millActuals.push({number:row.container,seal:row.seal||'',packing:`${pk.size||''} ${c.packingUnit||'KG'}`,packSize:Number(pk.size||0),unit:c.packingUnit||'KG',bags:Number(row.bags||0),netKg:net,tareKg:tare,grossKg:net+tare,brand:row.brand||pk.brand||'',location,truck:row.truck||'',gatePass:row.gate||'',loadedDate:row.date||''});changed=true};
    const ships=parse('tt30ship',[]);(parse('tt32exportsync',[])||[]).forEach(f=>{const s=ships.find(x=>String(x.ref)===String(f.shipment));const c=s?.containers?.find(x=>String(x.container)===String(f.container));add(f.shipment,{...f,...c,brand:f.brand||s?.brand},'TTI Rice Mills')});
    const sodas=parse('tt35exmill',[]);(parse('tt35exload',[])||[]).forEach(x=>{const s=sodas.find(z=>z.id===x.sodaId);add(x.shipment||s?.shipment,{...x,weight:x.kg,brand:s?.brand||''},s?.mill||'Ex-Mill')});
    if(changed)put('transtrade_export_v2_operational',root);
  }
  function bridge(){try{exportsToMill();millToExports()}catch(e){console.error('Transtrade inter-module bridge',e)}}
  function notifyRemote(){
    let b=document.getElementById('ttSyncNotice');if(!b){b=document.createElement('button');b.id='ttSyncNotice';b.type='button';b.style.cssText='position:fixed;right:12px;top:56px;z-index:100000;border:0;border-radius:10px;padding:10px 13px;background:#16825d;color:#fff;font:700 12px Arial;box-shadow:0 5px 18px #0004';b.onclick=()=>location.reload();document.body.appendChild(b)}b.textContent='Updated'+(lastRemoteBy?' by '+lastRemoteBy:'')+' — refresh';
    const el=document.activeElement,editing=el&&/INPUT|TEXTAREA|SELECT/.test(el.tagName);if(!editing)setTimeout(()=>location.reload(),1200);
  }
  function showSyncError(msg){let b=document.getElementById('saveBadge');if(b){b.textContent='Shared save retry needed';b.style.background='#8d2b2b'}console.error(msg)}
  window.TT_SHARED_SYNC={flush,bridge,poll:()=>getRemote(false)};
  addEventListener('DOMContentLoaded',()=>{bridge();for(let i=0;i<localStorage.length;i++){const k=localStorage.key(i);if(allowed(k)&&!remoteKeys.has(k))queue(k,localStorage.getItem(k))}flush();setInterval(()=>getRemote(false),8000)});
  addEventListener('pagehide',flush);
})();
</script>
HTML;

$guard = <<<'HTML'
<style>#ttUserBar{position:fixed;right:12px;top:12px;z-index:99999;display:flex;align-items:center;gap:9px;background:#102a46;color:#fff;padding:6px 7px 6px 11px;border-radius:10px;box-shadow:0 5px 18px #0004;font:12px Arial}#ttUserBar .ttPower{width:31px;height:31px;display:grid;place-items:center;border-radius:8px;background:#fff;color:#b42318;text-decoration:none;font-size:18px;font-weight:900;line-height:1}#ttUserBar .ttPower:hover{background:#fff0ee}.tt-no-access{display:none!important}</style>
<div id="ttUserBar"><span id="ttUserName"></span><a class="ttPower" href="logout.php" title="Log out" aria-label="Log out">⏻</a></div>
<script>
(()=>{const c=window.TT_MODULE_ACCESS||{},p=c.permissions||{},superUser=!!c.super;document.getElementById('ttUserName').textContent=c.user+' · '+c.role;if(superUser)return;
const norm=s=>String(s||'').toLowerCase().replace(/&/g,'and').replace(/[^a-z0-9]+/g,' ').trim();
const names={'stock':'stock','arrival list':'queue','arrival pohanch':'arrival','new export bags':'newbags','exports specifications':'instructions','production':'production','export loading':'export','local sales':'local','petty cash':'petty','processing expense':'labour','reprocessing bill':'reprocessbill','used bags in and out':'oldbags','reports':'reports','active shipments':'active','sales contracts':'contracts','completed shipments':'completed','cancelled':'cancelled','fi register':'fi','reports and registers':'reports','sales contract':'contract','bag order':'bags','bag artwork and bag order':'bags','production instructions':'production','loading instructions':'loading','customs documents':'customs','b l documents':'bl','commercial documents':'commercial','certificate of origin':'coo','certificates':'certs','bank covering and dispatch':'cover','tg documents':'tg','l c exchange draft':'lcdraft','document output':'print','history and versions':'history'};
let current='';const actions=i=>p==='all'?['View','Create','Edit']:(Array.isArray(p)?p:(p[i]||[]));const can=(i,a)=>p==='all'||actions(i).includes(a);
function idFor(el){let oc=el?.getAttribute?.('onclick')||'',m=oc.match(/(?:openPanel|showTab|openTile)\(['"]([^'"]+)/);if(m)return m[1];let t=norm(el?.querySelector?.('.label,b')?.textContent||el?.textContent);return names[t]||''}
function apply(){document.querySelectorAll('.tile,.tab,#homeGrid .tile,.stock-prominent').forEach(el=>{let i=idFor(el);if(i&&!can(i,'View'))el.classList.add('tt-no-access')});document.querySelectorAll('[data-home-role="admin-only"]').forEach(el=>el.classList.add('tt-no-access'))}
document.addEventListener('click',e=>{let target=e.target.closest('button,.tile,.tab,[onclick]');if(!target)return;let i=idFor(target)||current;if(idFor(target))current=idFor(target);if(i&&!can(i,'View')){e.preventDefault();e.stopImmediatePropagation();alert('This icon is not allowed for your login.');return}let text=norm(target.textContent);let required=/edit|amend|correct|update/.test(text)?'Edit':/(^| )add|(^| )new|create|save|issue|upload|send|confirm|receive|dispatch/.test(text)?'Create':'';if(required&&i&&!can(i,required)){e.preventDefault();e.stopImmediatePropagation();alert(required+' permission is not allowed for this icon.')}},true);
new MutationObserver(apply).observe(document.body,{childList:true,subtree:true});apply();})();
</script>
HTML;

$headPos = stripos($html, '</head>');
if ($headPos !== false) $html = substr_replace($html, $bootstrap.$sharedBootstrap, $headPos, 0);
$bodyPos = strripos($html, '</body>');
if ($bodyPos !== false) $html = substr_replace($html, $guard, $bodyPos, 0); else $html .= $guard;
echo $html;
