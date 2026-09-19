<?php
declare(strict_types=1);
require __DIR__ . '/auth_store.php';
require_once __DIR__ . '/runtime_html.php';
$user = tt_require_login();
$modules = [
    'milling' => __DIR__ . '/milling/Transtrade_Master_Milling_V3_3_2_AUDITED.html',
    'exports' => __DIR__ . '/exports/index.html',
];
$id = strtolower((string)($_GET['id'] ?? ''));
if (!isset($modules[$id]) || !is_file($modules[$id])) { http_response_code(404); exit('Module not found.'); }
$permissionName = $id === 'milling' ? 'Mill' : 'Exports';
if (!tt_user_can_open_module($user, $permissionName)) { http_response_code(403); exit('You do not have permission to open this module.'); }
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Vary: Cookie');
header('X-LiteSpeed-Cache-Control: no-cache');
$html = (string)file_get_contents($modules[$id]);
if ($id === 'exports') {
    $cssFile = __DIR__ . '/exports/app.css';
    $jsFile = __DIR__ . '/exports/app.js';
    if (!is_file($cssFile) || !is_file($jsFile)) { http_response_code(503); exit('Export module assets are unavailable.'); }
    $css = (string)file_get_contents($cssFile);
    $js = (string)file_get_contents($jsFile);
    foreach (['TTI_header.png','TTI_sign.png','BRM_header.png','BRM_sign.png','TG_header.png','TG_footer.png','TG_sign.png','KCCI_COO_letterpad.jpg'] as $asset) {
        $path = __DIR__ . '/exports/assets/' . $asset;
        if (is_file($path)) {
            $mime = str_ends_with(strtolower($asset), '.jpg') || str_ends_with(strtolower($asset), '.jpeg') ? 'image/jpeg' : 'image/png';
            $js = str_replace('assets/' . $asset, 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($path)), $js);
        }
    }
    $html = tt_replace_html_once(
        '~<link\b[^>]*href=["\'](?:exports/)?app\.css[^"\']*["\'][^>]*>~i',
        static fn(): string => '<style id="exports-app-css">' . $css . '</style>',
        $html
    );
    $inlineJs = str_replace('</script', '<\/script', $js);
    $html = tt_replace_html_once(
        '~<script\b[^>]*src=["\'](?:exports/)?app\.js[^"\']*["\'][^>]*>\s*</script>~i',
        static fn(): string => '<script id="exports-app-js">' . $inlineJs . '</script>',
        $html
    );
}
$modulePermissions = $user['permissions'][$permissionName] ?? [];
$access = [
    'module'=>$permissionName, 'moduleId'=>$id, 'user'=>(string)$user['full_name'],
    'role'=>(string)$user['role'], 'permissions'=>$modulePermissions,
    'super'=>(($user['role'] ?? '')==='Super Admin'), 'csrf'=>tt_csrf(),
    'masterAccess'=>tt_user_can_access_masters($user), 'masterPermissions'=>$user['master_permissions'] ?? [],
    'masters'=>tt_list_masters(), 'masterOptions'=>tt_master_options(),
];
$bootstrap = '<script>window.TT_MODULE_ACCESS='.json_encode($access, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';</script>';
$sharedBootstrap = <<<'HTML'
<script id="tt-shared-operations-bootstrap">
(()=>{
  const access=window.TT_MODULE_ACCESS||{}, endpoint='api/operations.mysql.php';
  const EXPORT_STORE='transtrade_export_v3_operational';
  const allowed=k=>k===EXPORT_STORE||/^tt[0-9]{2}[a-z0-9_]{2,60}$/.test(k);
  const originalSet=Storage.prototype.setItem, originalRemove=Storage.prototype.removeItem;
  const LEGACY_QUEUE_STORE='tt_shared_commit_queue_v1', LEGACY_OUTBOX_DB='transtrade-offline-outbox-v2';
  let applying=false, revision=0, remoteKeys=new Set(), pending=new Map(), inFlight=new Set(), keyVersions=new Map(), queuedBase=new Map(), timer=0, inboundRetry=0, lastRemoteBy='', lastInboundCheck=0, commitWaiters=[];
  const directSet=(k,v)=>originalSet.call(localStorage,k,v);
  const markSaveState=()=>{};
  // The former device outbox is deliberately retired. A workflow action now
  // succeeds only after its write is acknowledged by the server.
  try{originalRemove.call(localStorage,LEGACY_QUEUE_STORE)}catch{}
  try{indexedDB.deleteDatabase(LEGACY_OUTBOX_DB)}catch{}
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
    Object.entries(data.values||{}).forEach(([k,v])=>{remoteKeys.add(k);if(allowed(k)&&typeof v==='string'&&!pending.has(k)&&!inFlight.has(k)&&localStorage.getItem(k)!==v){directSet(k,v);changed.push(k);lastRemoteBy=data.meta?.[k]?.updatedBy||lastRemoteBy}});
    Object.entries(data.meta||{}).forEach(([k,m])=>{if(!pending.has(k)&&!inFlight.has(k))keyVersions.set(k,Number(m?.version||0))});
    applying=false;revision=Math.max(revision,incoming);window.TRANSTRADE_SERVER_NOW_ISO=data.serverNow||window.TRANSTRADE_SERVER_NOW_ISO;
    if(changed.length&&!initial){bridge();notifyRemote()}
  }
  function settleCommits(error=''){
    if(!error&&(pending.size||inFlight.size))return;
    const waiters=commitWaiters.splice(0);for(const w of waiters){clearTimeout(w.timer);error?w.reject(new Error(error)):w.resolve({ok:true,revision})}
  }
  function saveNow(){
    if(!pending.size&&!inFlight.size)return Promise.resolve({ok:true,revision});
    return new Promise((resolve,reject)=>{const waiter={resolve,reject,timer:0};waiter.timer=setTimeout(()=>{const i=commitWaiters.indexOf(waiter);if(i>=0){commitWaiters.splice(i,1);reject(new Error('Save timed out. Nothing was advanced; please retry.'))}},20000);commitWaiters.push(waiter);flush()})
  }
  function refreshNow(){
    if(pending.size||inFlight.size)return Promise.reject(new Error('Finish the current save before refreshing.'));
    return fetch(endpoint+'?r='+Date.now(),{credentials:'same-origin'}).then(r=>r.json()).then(data=>{if(!data?.ok)throw new Error(data?.error||'Shared data could not be refreshed.');applyRemote(data,false);return data})
  }
  function flush(){
    clearTimeout(timer);timer=0;
    for(const [key,value] of [...pending]){
      if(inFlight.has(key))continue;
      pending.delete(key);
      inFlight.add(key);
      fetch(endpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:access.csrf,key,value,baseVersion:Number(queuedBase.get(key)??keyVersions.get(key)??0),sourceModule:access.module||'Super Admin'})})
        .then(r=>r.json()).then(r=>{inFlight.delete(key);if(r.ok){revision=Math.max(revision,Number(r.revision||0));keyVersions.set(key,Number(r.keyVersion||r.revision||0));queuedBase.delete(key);if(pending.has(key)){clearTimeout(timer);timer=setTimeout(flush,180);return}if(!pending.size&&!inFlight.size){markSaveState('Saved');settleCommits();if(typeof dispatchEvent==='function'&&typeof CustomEvent==='function')dispatchEvent(new CustomEvent('tt:shared-saved',{detail:{key}}))}return}if(!pending.has(key))pending.set(key,value);const message=r.conflict?'This record changed elsewhere. Refresh and review it before retrying.':(r.error||'The change was not saved.');showSyncError(message,!!r.conflict);settleCommits(message)})
        .catch(()=>{inFlight.delete(key);if(!pending.has(key))pending.set(key,value);const message='The change was not saved. Check the connection and retry; nothing was advanced.';showSyncError(message);settleCommits(message)});
    }
  }
  function queue(key,value){if(!allowed(key)||applying)return;if(!pending.has(key))queuedBase.set(key,Number(keyVersions.get(key)||0));pending.set(key,String(value));clearTimeout(timer);timer=setTimeout(flush,180)}
  Storage.prototype.setItem=function(k,v){originalSet.call(this,k,v);if(this===localStorage)queue(String(k),String(v))};
  Storage.prototype.removeItem=function(k){originalRemove.call(this,k);};

  const initial=getRemote(true);if(initial?.ok&&!Object.prototype.hasOwnProperty.call(initial.values||{},EXPORT_STORE)&&!pending.has(EXPORT_STORE)){applying=true;originalRemove.call(localStorage,EXPORT_STORE);applying=false}if(initial)applyRemote(initial,true);

  function exportsToMill(){
    const root=parse(EXPORT_STORE,null);if(!root?.millSync)return;
    const contracts=root.contracts||[], contract=ref=>contracts.find(c=>c.ref===ref)||{}, commercialBase=value=>String(value||'').replace(/\s+(?:RAW|READY|FINISHED)\s+RICE$/i,'').replace(/^READY\s+RICE\s*[—-]\s*/i,'').replace(/\s+(?:WHITE|PARBOIL(?:ED)?|STEAM|SELLA)\s+RICE$/i,'').trim(), readyDisplay=value=>{const base=commercialBase(value);return base?base+' READY RICE':'READY RICE'};
    let bags=parse('tt30bags',[]), oldBags=new Map(bags.filter(x=>x._ttBridge==='exports').map(x=>[x._ttBridgeId,x]));bags=bags.filter(x=>x._ttBridge!=='exports');
    (root.millSync.newExportBags||[]).forEach((x,index)=>{const bid=[x.poNo||x.contractRef||'NO-PO',x.line??x.lineId??index,x.contractRef||'',x.brand||'',x.size||'',x.orderedTare||''].join('|'),old=oldBags.get(bid)||{};bags.push({...old,id:old.id||stableId('bag|'+bid),_ttBridge:'exports',_ttBridgeId:bid,contractRef:x.contractRef,poNo:x.poNo,brand:x.brand||'UNNAMED BRAND',size:String(x.size||'')+' '+(String(x.unit||'KG').toUpperCase().startsWith('LB')?'lb':'kg'),tare:String(x.orderedTare||0)+' g',supplier:x.supplier||'',ordered:Number(x.totalOrdered||x.requiredBags||0),mill:x.deliverTo||'TTI Rice Mills',received:Number(old.received||0),artworkName:x.artworkName||'',artworkData:x.artworkData||'',status:x.status||'Order from Export — Awaiting Receipt'})});put('tt30bags',bags);

    let pis=parse('tt30prodinst',[]),oldPis=new Map(pis.filter(x=>x._ttBridge==='exports').map(x=>[x._ttBridgeId,x]));pis=pis.filter(x=>x._ttBridge!=='exports');
    (root.millSync.productionInstructions||[]).forEach(x=>{const c=contract(x.contractRef),prod=x.production||{};(x.packings||[]).forEach((pk,i)=>{const bid=x.contractRef+'|'+i,old=oldPis.get(bid)||{},specs=x.specifications||[],spec=n=>String(specs.find(v=>String(v.name||'').toLowerCase().includes(n))?.value||''),q=x.quality||c.quality||prod.qualityNotes||'As per export contract';pis.push({...old,id:old.id||stableId('pi|'+bid),_ttBridge:'exports',_ttBridgeId:bid,contractRef:x.contractRef,ref:'PI-'+String(x.contractRef||'').replace(/[^A-Za-z0-9]/g,'-')+(x.packings.length>1?'-'+String.fromCharCode(65+i):''),brand:pk.brand||'UNNAMED BRAND',variety:x.product||c.product||'',quality:q,requiredMT:Number(pk.containers||0)*Number(pk.weightPer||0),producedMT:Number(old.producedMT||0),packing:`${pk.size||''} ${String(c.packingUnit||'KG').toUpperCase().startsWith('LB')?'lb':'kg'} ${pk.type||'export bags'}`,requiredBy:prod.requiredBy||'',broken:spec('broken')||'As per contract',moisture:spec('moisture')||'As per contract',chalky:spec('chalky')||'As per contract',damage:spec('damage')||spec('yellow')||'As per contract',foreign:spec('foreign')||'As per contract',polish:spec('finish')||prod.qualityNotes||'As per approved sample / contract',special:c.quality||prod.qualityNotes||'Follow Export instructions and keep brand separate.',bagSupplier:'As per Export Bag Order',inspection:prod.inspection||c.inspection||'None',dpp:prod.dpp||'Yes'})})});put('tt30prodinst',pis);

    let ships=parse('tt30ship',[]),allBridgeShips=ships.filter(x=>x._ttBridge==='exports'),oldShips=new Map(allBridgeShips.map(x=>[x._ttBridgeId,x]));ships=ships.filter(x=>x._ttBridge!=='exports');const legacyIndex=x=>String(x._ttBridgeIndex??String(x._ttBridgeId||'').split('|').pop()),legacyIdentity=x=>{const c=String(x.contractRef||''),l=String(x._ttLotId||x.ref||''),i=legacyIndex(x);return c&&l&&i!==''?`${c}|${l}|${i}`:''},legacyGroups=new Map();allBridgeShips.forEach(x=>{const k=legacyIdentity(x);if(k)legacyGroups.set(k,[...(legacyGroups.get(k)||[]),x])});let quarantine=parse('tt39bridgequarantine',[]),qSeen=new Set(quarantine.map(x=>String(x.key||'')));allBridgeShips.filter(x=>(x.containers||[]).length&&(!legacyIdentity(x)||(legacyGroups.get(legacyIdentity(x))||[]).length!==1)).forEach(x=>{const key=`MILL|${x.id||''}|${x._ttBridgeId||''}|${x.contractRef||''}|${x._ttLotId||x.ref||''}`;if(!qSeen.has(key)){quarantine.push({key,at:new Date().toISOString(),reason:'Legacy loading record cannot be uniquely proven by Contract + Lot + allocation',contractRef:x.contractRef||'',lotRef:x._ttLotId||x.ref||'',containers:x.containers});qSeen.add(key)}});if(quarantine.length)put('tt39bridgequarantine',quarantine);
    let exmills=parse('tt35exmill',[]),allBridgeEx=exmills.filter(x=>x._ttBridge==='exports'),oldEx=new Map(allBridgeEx.map(x=>[x._ttBridgeId,x]));exmills=exmills.filter(x=>x._ttBridge!=='exports');const exLegacyIndex=x=>String(x._ttBridgeIndex??String(x._ttBridgeId||'').split('|').pop()),exLegacyIdentity=x=>{const c=String(x.contractRef||''),l=String(x.shipment||''),i=exLegacyIndex(x);return c&&l&&i!==''?`${c}|${l}|${i}`:''},exLegacyGroups=new Map();allBridgeEx.forEach(x=>{const k=exLegacyIdentity(x);if(k)exLegacyGroups.set(k,[...(exLegacyGroups.get(k)||[]),x])});let exLoads=parse('tt35exload',[]),ambiguousExIds=new Set(allBridgeEx.filter(x=>!exLegacyIdentity(x)||(exLegacyGroups.get(exLegacyIdentity(x))||[]).length!==1).map(x=>String(x.id)));exLoads.filter(x=>ambiguousExIds.has(String(x.sodaId))).forEach(x=>{const key=`EXMILL|${x.id||''}|${x.sodaId||''}|${x.container||''}`;if(!qSeen.has(key)){quarantine.push({key,at:new Date().toISOString(),reason:'Legacy Ex-Mill loading record cannot be uniquely proven by Contract + Lot + allocation',contractRef:x.contractRef||'',lotRef:x.lotRef||x.shipment||'',containers:[x]});qSeen.add(key)}});if(ambiguousExIds.size){exLoads=exLoads.filter(x=>!ambiguousExIds.has(String(x.sodaId)));put('tt35exload',exLoads)}if(quarantine.length)put('tt39bridgequarantine',quarantine);
    (root.millSync.exportLoading||[]).forEach(x=>{if(!x.contractRef||!x.lotId)return;const c=contract(x.contractRef),prod=x.production||{};(x.plan?.allocations||[]).forEach((a,i)=>{const lotRecord=(root.shipments||[]).find(s=>String(s.id)===String(x.shipmentId))||{},bid=(x.shipmentId?x.shipmentId+'|':'')+x.contractRef+'|'+x.lotId+'|'+i,pk=(c.packings||[])[Number(a.packIndex||0)]||(c.packings||[])[0]||{},unitKg=String(c.packingUnit||'KG').toUpperCase().startsWith('LB')?Number(pk.size||0)*.45359237:Number(pk.size||0),bpc=unitKg?Math.round(Number(a.weightPer||0)*1000/unitKg):0,isExternal=/external|ex-mill/i.test(String(a.type||''))&&!/transtrade|tti rice/i.test(String(a.name||''));if(isExternal){const exLegacyKey=`${x.contractRef}|${x.lotId}|${i}`,exLegacyMatches=exLegacyGroups.get(exLegacyKey)||[],old=oldEx.get(bid)||(exLegacyMatches.length===1?exLegacyMatches[0]:{}),baseVariety=commercialBase(c.product),displayName=readyDisplay(baseVariety);exmills.push({...old,id:old.id||stableId('xm|'+bid),_ttBridge:'exports',_ttBridgeId:bid,_ttBridgeIndex:i,_ttShipmentId:x.shipmentId||'',soda:'XM-'+String(x.contractRef||'').replace(/[^A-Za-z0-9]/g,'-')+'-'+(i+1),mill:a.name||'External Mill',broker:'Direct',product:displayName,commercialProduct:c.product||'',baseVariety,productStage:'READY',displayName,brand:pk.brand||'UNNAMED BRAND',qtyKg:Number(a.containers||0)*Number(a.weightPer||0)*1000,loadedKg:Number(old.loadedKg||0),containerBasis:true,deliveryTo:'Ex-Mill',quality:c.quality||'As per export contract',packing:`${pk.size||''} ${c.packingUnit||'KG'} ${pk.type||'bags'}`,special:prod.qualityNotes||c.quality||'',bagTare:String(pk.tare||0)+' g',dryon:a.dryOn||prod.dryOn||'No',craft:a.craftPaper||prod.craftPaper||'No',inspection:a.inspection||prod.inspection||c.inspection||'None',dpp:a.dpp||prod.dpp||'Yes',shipment:x.lotId,shipmentId:x.shipmentId||'',contractContainers:Number(lotRecord.containers||0),totalContainers:Math.max(1,Math.ceil(Number(a.containers||0))),contractRef:x.contractRef});return}const legacyKey=`${x.contractRef}|${x.lotId}|${i}`,legacyMatches=legacyGroups.get(legacyKey)||[],old=oldShips.get(bid)||(legacyMatches.length===1?legacyMatches[0]:{}),preservedContainers=oldShips.has(bid)||legacyMatches.length===1?(old.containers||[]):[];ships.push({...old,id:old.id||stableId('ship|'+bid),_ttBridge:'exports',_ttBridgeId:bid,_ttBridgeIndex:i,_ttShipmentId:x.shipmentId||'',_ttLotId:x.lotId,contractRef:x.contractRef,ref:x.lotId,contractContainers:Number(lotRecord.containers||0),brand:pk.brand||'UNNAMED BRAND',shipping:c.shippingLine||'As advised by Export',mill:a.name||'TTI Rice Mills',totalContainers:Math.max(1,Math.ceil(Number(a.containers||0))),bagsPerContainer:bpc,bagSize:Number(pk.size||0),dryon:a.dryOn||prod.dryOn||'No',craft:a.craftPaper||prod.craftPaper||'No',inspection:a.inspection||prod.inspection||c.inspection||'None',dpp:a.dpp||prod.dpp||'Yes',emptyRequired:Number(a.emptyBags||0),status:old.status||'Open',containers:preservedContainers,audit:old.audit||[],reportedBrandKg:Number(a.containers||0)*Number(a.weightPer||0)*1000,recovery:Number(old.recovery||.60),byProducts:old.byProducts||{'B2':.32,'CSR':.03,'Powder':.03,'Other':.02}})})});put('tt30ship',ships);put('tt35exmill',exmills);
  }

  function millToExports(){
    const root=parse('transtrade_export_v3_operational',null);if(!root?.shipments)return;const priorAlerts=Array.isArray(root.alerts)?root.alerts:[],seenAlerts=new Set();root.alerts=priorAlerts.filter(a=>{const key=[a.area||'',a.contractRef||'',a.kind||'',a.message||''].join('|');if(seenAlerts.has(key))return false;seenAlerts.add(key);return true});let changed=root.alerts.length!==priorAlerts.length;const alertOnce=(bridgeKey,data)=>{const duplicate=root.alerts.some(a=>a.bridgeKey===bridgeKey||(a.area===data.area&&a.contractRef===data.contractRef&&a.kind===data.kind&&a.message===data.message));if(duplicate)return false;root.alerts.unshift({...data,bridgeKey});changed=true;return true};for(const target of root.shipments.filter(s=>s.kind==='lot')){const legacy=(target.millActuals||[]).filter(a=>a?.source==='Milling'&&!a.shipmentId);if(!legacy.length)continue;const exact=legacy.filter(a=>String(a.contractRef||'')===String(target.contractRef||'')&&String(a.lotRef||'')===String(target.lotId||'')),ambiguous=legacy.filter(a=>!exact.includes(a));exact.forEach(a=>{a.shipmentId=target.id;a.contractRef=target.contractRef;a.lotRef=target.lotId;changed=true});if(!ambiguous.length)continue;const prior=target.millActualsQuarantine||[],qNumbers=new Set(prior.map(a=>String(a.number||a.container||'')));target.millActualsQuarantine=[...prior,...ambiguous.filter(a=>!qNumbers.has(String(a.number||a.container||''))).map(a=>({...a,quarantinedAt:new Date().toISOString(),quarantineReason:'Legacy Milling identity lacks a provable Contract + Lot match'}))];target.millActuals=(target.millActuals||[]).filter(a=>!ambiguous.includes(a));alertOnce('migration|'+target.id,{id:'AL-'+Date.now()+'-'+Math.random().toString(36).slice(2,7),area:'MILL ACTUALS',contractRef:target.contractRef,kind:'Migration Review',message:`Unproven legacy Milling container data for ${target.contractRef} / ${target.lotId} was quarantined. Valid Contract + Lot records were preserved.`,createdAt:new Date().toISOString(),seen:false});changed=true}
    const add=(shipment,row,location)=>{const ref=String(shipment||''),contractRef=String(row?.contractRef||''),lotRef=String(row?.lotRef||row?.shipment||''),target=root.shipments.find(s=>String(s.id)===ref)||(contractRef&&lotRef?root.shipments.find(s=>s.kind==='lot'&&String(s.contractRef||'')===contractRef&&String(s.lotId||'')===lotRef):null);if(!target||!row?.container)return;target.millActuals=target.millActuals||[];const clean=String(row.container).replace(/[^A-Za-z0-9]/g,'').toUpperCase(),number=/^[A-Z]{4}\d{7}$/.test(clean)?clean.slice(0,10)+'-'+clean.slice(10):String(row.container).toUpperCase(),contributionId=String(row._contributionId||[location,row.brand,row.gate,row.date,row.bags,row.weight??row.kg].join('|'));const c=(root.contracts||[]).find(c=>c.ref===target.contractRef)||{},pk=(c.packings||[]).find(p=>String(p.brand||'').toUpperCase()===String(row.brand||'').toUpperCase())||(c.packings||[])[0]||{},bags=Number(row.bags||0),tare=bags*Number(pk.tare||0)/1000+(pk.masterBag?.enabled?Number(pk.masterBag.qty||0)*Number(pk.masterBag.tare||0)/1000:0),net=Number(row.weight??row.kg??0),docAlloc=(target.loadingPlan?.allocations||[]).find(a=>{const ap=(c.packings||[])[Number(a.packIndex||0)]||{};return String(ap.brand||'').toUpperCase()===String(row.brand||'').toUpperCase()&&(!location||String(a.name||'')===String(location))})||(target.loadingPlan?.allocations||[]).find(a=>{const ap=(c.packings||[])[Number(a.packIndex||0)]||{};return String(ap.brand||'').toUpperCase()===String(row.brand||'').toUpperCase()}),docNet=Number(docAlloc?.weightPer||0)>0?Number(docAlloc.weightPer)*1000:(target.kind==='lot'&&Number(target.containers||0)>0?Number(target.plannedQty||0)*1000/Number(target.containers):net),contribution={id:contributionId,location,brand:row.brand||pk.brand||'',packing:`${pk.size||''} ${c.packingUnit||'KG'}`,bags,netKg:net,millNetKg:net,tareKg:tare,grossKg:net+tare,truck:row.truck||'',gatePass:row.gate||'',loadedDate:row.date||''};let actual=target.millActuals.find(a=>String(a.number).replace(/[^A-Za-z0-9]/g,'').toUpperCase()===clean);const expected=Number(target.containers||0);if(!actual&&expected>0&&target.millActuals.length>=expected){alertOnce('limit|'+target.id+'|'+clean,{id:'AL-'+Date.now()+'-'+Math.random().toString(36).slice(2,7),area:'MILL ACTUALS',contractRef:target.contractRef,kind:'Container Limit',message:`Rejected extra container ${number}. ${target.contractRef} / ${target.lotId} is instructed for ${expected} physical container(s).`,createdAt:new Date().toISOString(),seen:false});return}if(actual){actual.contributions=Array.isArray(actual.contributions)&&actual.contributions.length?actual.contributions:[{id:'legacy',location:actual.location,brand:actual.brand,packing:actual.packing,bags:Number(actual.bags||0),netKg:Number(actual.millNetKg??actual.netKg??0),millNetKg:Number(actual.millNetKg??actual.netKg??0),tareKg:Number(actual.tareKg||0),grossKg:Number(actual.grossKg||0)}];if(actual.contributions.some(x=>String(x.id)===contributionId))return;if(actual.seal&&row.seal&&String(actual.seal).toUpperCase()!==String(row.seal).toUpperCase()){alertOnce('conflict|'+target.id+'|'+clean+'|'+String(actual.seal)+'|'+String(row.seal),{id:'AL-'+Date.now()+'-'+Math.random().toString(36).slice(2,7),area:'MILL ACTUALS',contractRef:target.contractRef,kind:'Mill Conflict',message:`Container ${number} returned with conflicting seals ${actual.seal} / ${row.seal}. Export review required.`,createdAt:new Date().toISOString(),seen:false});return}actual.contributions.push(contribution);actual.bags=actual.contributions.reduce((n,x)=>n+Number(x.bags||0),0);actual.millNetKg=actual.contributions.reduce((n,x)=>n+Number(x.millNetKg??x.netKg??0),0);actual.netKg=docNet;actual.documentNetKg=docNet;actual.tareKg=actual.contributions.reduce((n,x)=>n+Number(x.tareKg||0),0);actual.grossKg=actual.netKg+actual.tareKg;actual.shipmentId=target.id;actual.contractRef=target.contractRef;actual.lotRef=target.lotId||ref;actual.location=[...new Set(actual.contributions.map(x=>x.location).filter(Boolean))].join(' + ');actual.brand=[...new Set(actual.contributions.map(x=>x.brand).filter(Boolean))].join(' + ');actual.packing=[...new Set(actual.contributions.map(x=>x.packing).filter(Boolean))].join(' + ');actual.truck=[...new Set(actual.contributions.map(x=>x.truck).filter(Boolean))].join(' + ');actual.gatePass=[...new Set(actual.contributions.map(x=>x.gatePass).filter(Boolean))].join(' + ');actual.loadedDate=[...new Set(actual.contributions.map(x=>x.loadedDate).filter(Boolean))].join(' + ')}else{actual={number,seal:String(row.seal||'').toUpperCase(),packing:contribution.packing,packSize:Number(pk.size||0),unit:c.packingUnit||'KG',bags,netKg:docNet,documentNetKg:docNet,millNetKg:net,tareKg:tare,grossKg:docNet+tare,brand:contribution.brand,location,truck:contribution.truck,gatePass:contribution.gatePass,loadedDate:contribution.loadedDate,shipmentId:target.id,contractRef:target.contractRef,lotRef:target.lotId||ref,source:'Milling',contributions:[contribution]};target.millActuals.push(actual)}alertOnce('update|'+target.id+'|'+clean+'|'+contributionId,{id:'AL-'+Date.now()+'-'+Math.random().toString(36).slice(2,7),area:'MILL ACTUALS',contractRef:target.contractRef,kind:'Mill Update',message:`Container ${number} / seal ${row.seal||''} contribution received from ${location}`,createdAt:new Date().toISOString(),seen:false});changed=true};
    const ships=parse('tt30ship',[]);ships.filter(s=>s&&s._ttBridge==='exports').forEach(s=>(s.containers||[]).forEach(c=>add(s._ttShipmentId||s.shipmentId,{...c,shipmentId:s._ttShipmentId||s.shipmentId||'',contractRef:s.contractRef||'',lotRef:s._ttLotId||s.ref||'',brand:s.brand||'',_contributionId:`TTI|${s._ttShipmentId||s.shipmentId||''}|${c.container}|${s.brand||''}|${c.gate||''}`},'TTI Rice Mills')));(parse('tt32exportsync',[])||[]).forEach(f=>{const s=ships.find(x=>f.shipmentId&&String(x._ttShipmentId||'')===String(f.shipmentId))||ships.find(x=>f.contractRef&&String(x.contractRef||'')===String(f.contractRef)&&String(x._ttLotId||x.ref||'')===String(f.lotRef||f.shipment||''));if(!s)return;const c=s?.containers?.find(x=>String(x.container)===String(f.container));add(f.shipmentId||s._ttShipmentId,{...f,...c,shipmentId:f.shipmentId||s._ttShipmentId,contractRef:f.contractRef||s.contractRef,lotRef:f.lotRef||s._ttLotId||s.ref,brand:f.brand||s?.brand,_contributionId:`TTI|${f.shipmentId||s._ttShipmentId}|${f.container}|${f.brand||s?.brand||''}|${f.gate||c?.gate||''}`},'TTI Rice Mills')});
    const sodas=parse('tt35exmill',[]);(parse('tt35exload',[])||[]).forEach(x=>{const s=sodas.find(z=>z.id===x.sodaId);if(!s)return;add(x.shipmentId||s._ttShipmentId,{...x,weight:x.kg,shipmentId:x.shipmentId||s._ttShipmentId,contractRef:x.contractRef||s.contractRef,lotRef:x.lotRef||s.shipment,brand:s?.brand||'',_contributionId:`EX|${x.sodaId||''}|${x.shipmentId||s._ttShipmentId}|${x.container}|${x.gate||''}`},s?.mill||'Ex-Mill')});
    if(changed)put(EXPORT_STORE,root);
  }
  function bridge(){try{exportsToMill();millToExports()}catch(e){console.error('Transtrade inter-module bridge',e)}}
  function notifyRemote(){dispatchEvent(new CustomEvent('tt:shared-updated',{detail:{by:lastRemoteBy}}))}
  function showSyncError(msg,conflict){console.error(msg);if(conflict)dispatchEvent(new CustomEvent('tt:shared-conflict',{detail:{message:msg}}))}
  function checkInbound(){const now=Date.now();if(pending.size||inFlight.size){clearTimeout(inboundRetry);inboundRetry=setTimeout(checkInbound,300);return}if(now-lastInboundCheck<800)return;lastInboundCheck=now;getRemote(false)}
  window.TT_SHARED_SYNC={flush,saveNow,refresh:refreshNow,bridge,poll:checkInbound};
  // Permanent rule: only explicit application actions save. Inbound checks are read-only and run when staff return to a tab.
  // Remote changes are staged locally and announced through an internal event without persistent interface notices.
  // Exports must reconcile Mill actuals before app.js reads state. Milling must finish its legacy startup first,
  // otherwise that startup can overwrite newly delivered Loading Instructions.
  if(access.moduleId==='exports')bridge();else addEventListener('DOMContentLoaded',()=>{bridge()});
  addEventListener('focus',checkInbound);
  document.addEventListener('visibilitychange',()=>{if(!document.hidden)checkInbound()});
})();
</script>
HTML;

$guard = <<<'HTML'
<style>#ttAccountsOutboxBadge,#ttOfflineNotice,[data-tt-accounts-outbox-badge]{display:none!important}#ttUserBar{position:fixed;right:12px;top:12px;z-index:99999;display:flex;align-items:center;gap:9px;background:#102a46;color:#fff;padding:6px 7px 6px 11px;border-radius:10px;box-shadow:0 5px 18px #0004;font:12px Arial}#ttUserBar.ttHeaderUser{position:static;z-index:auto;flex:0 0 auto;background:rgba(255,255,255,.09);padding:5px 6px 5px 11px;border:1px solid rgba(255,255,255,.16);border-radius:12px;box-shadow:none;font:700 12px Arial;white-space:nowrap}.topbar.ttHasHeaderControls{padding-right:18px}#ttUserBar .ttPower{width:31px;height:31px;display:grid;place-items:center;border-radius:8px;background:#fff;color:#b42318;text-decoration:none;font-size:18px;font-weight:900;line-height:1}#ttUserBar .ttPower:hover{background:#fff0ee}#ttMasterTop{position:static;flex:0 0 auto;width:34px;height:34px;padding:0;border:1px solid rgba(255,255,255,.5);border-radius:8px;background:rgba(255,255,255,.12);color:#fff;display:grid;place-items:center;font:900 18px/1 Arial;cursor:pointer}#ttMasterTop:hover,#ttMasterTop:focus{background:#fff;color:#102a46;outline:none}.ttHeaderDate{color:#fff;font:700 12px Arial;white-space:nowrap}.tt-no-access{display:none!important}@media(max-width:1000px){#ttUserBar.ttHeaderUser{font-size:11px}}@media(max-width:900px){.topbar.ttHasHeaderControls{flex-wrap:wrap}.topbar.ttHasHeaderControls .spacer{display:none}}</style>
<div id="ttUserBar"><span id="ttUserName"></span><a class="ttPower" href="logout.php" title="Log out" aria-label="Log out">⏻</a></div>
<script>
(()=>{const c=window.TT_MODULE_ACCESS||{},p=c.permissions||{},superUser=!!c.super,bar=document.getElementById('ttUserBar');document.getElementById('ttUserName').textContent=c.user+' · '+c.role;const placeHeaderUser=()=>{if(!bar)return;const top=c.moduleId==='exports'?document.querySelector('.topbar'):c.moduleId==='milling'?document.querySelector('header'):null;if(!top)return;top.classList.add('ttHasHeaderControls');document.getElementById('logoutTop')?.remove();if(c.moduleId==='milling')top.querySelectorAll(':scope > div:not(.brand):not(#ttUserBar)').forEach(x=>x.remove());bar.classList.add('ttHeaderUser');top.appendChild(bar);let date=top.querySelector('.topDate');if(!date){date=document.createElement('span');date.className='topDate ttHeaderDate';date.textContent=new Date().toLocaleDateString(undefined,{weekday:'short',day:'2-digit',month:'short',year:'numeric'});top.insertBefore(date,bar)}if(c.masterAccess){let master=document.getElementById('ttMasterTop')||document.getElementById('masterTop');if(!master){master=document.createElement('button')}master.id='ttMasterTop';master.type='button';master.textContent='M';master.title='Master Records';master.setAttribute('aria-label','Master Records');master.onclick=()=>window.location.href='/index.php?view=masters';top.insertBefore(master,date)}else{document.getElementById('ttMasterTop')?.remove();document.getElementById('masterTop')?.remove()}};if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',placeHeaderUser);else placeHeaderUser();if(superUser)return;
const norm=s=>String(s||'').toLowerCase().replace(/&/g,'and').replace(/[^a-z0-9]+/g,' ').trim();
const names={'m':'masters','master data':'masters','settings masters':'masters','stock':'stock','arrival list':'queue','arrival pohanch':'arrival','new export bags':'newbags','exports specifications':'instructions','production':'production','export loading':'export','local sales':'local','petty cash':'petty','processing expense':'labour','reprocessing bill':'reprocessbill','used bags in and out':'oldbags','reports':'reports','active shipments':'active','sales contracts':'contracts','completed shipments':'completed','cancelled':'cancelled','fi register':'fi','reports and registers':'reports','sales contract':'contract','bag order':'bags','bag artwork and bag order':'bags','production instructions':'production','loading instructions':'loading','customs documents':'customs','b l documents':'bl','commercial documents':'commercial','certificate of origin':'coo','certificates':'certs','bank covering and dispatch':'cover','tg documents':'tg','l c exchange draft':'lcdraft','document output':'print','history and versions':'history'};
let current='';const actions=i=>p==='all'?['View','Create','Edit']:(Array.isArray(p)?p:(p[i]||[]));const can=(i,a)=>p==='all'||actions(i).includes(a);
function idFor(el){let oc=el?.getAttribute?.('onclick')||'',m=oc.match(/(?:openPanel|showTab|openTile)\(['"]([^'"]+)/);if(m)return m[1];let t=norm(el?.querySelector?.('.label,b')?.textContent||el?.textContent);return names[t]||''}
function apply(){document.querySelectorAll('.tile,.tab,#homeGrid .tile,.stock-prominent').forEach(el=>{let i=idFor(el);if(i&&!can(i,'View'))el.classList.add('tt-no-access')});document.querySelectorAll('[data-home-role="admin-only"]').forEach(el=>el.classList.add('tt-no-access'))}
document.addEventListener('click',e=>{let target=e.target.closest('button,.tile,.tab,[onclick]');if(!target)return;if(target.id==='ttMasterTop'){current='masters';return}let i=idFor(target)||current;if(idFor(target))current=idFor(target);if(i&&!can(i,'View')){e.preventDefault();e.stopImmediatePropagation();alert('This icon is not allowed for your login.');return}let text=norm(target.textContent);let required=/edit|amend|correct|update/.test(text)?'Edit':/(^| )add|(^| )new|create|save|issue|upload|send|confirm|receive|dispatch/.test(text)?'Create':'';if(required&&i&&!can(i,required)){e.preventDefault();e.stopImmediatePropagation();alert(required+' permission is not allowed for this icon.')}},true);
new MutationObserver(apply).observe(document.body,{childList:true,subtree:true});apply();})();
</script>
HTML;

$brandHead = '<link rel="stylesheet" href="/brand-theme.css?v=20260913-3">';
$headPos = stripos($html, '</head>');
if ($headPos !== false) $html = substr_replace($html, $brandHead.$bootstrap.$sharedBootstrap, $headPos, 0);
$accountsSourceBridge = '<script src="accounts/source-bridge.js?v=20260915-commercial-docs-1"></script><script src="accounts/loading-programme-sync.js?v=20260911-2"></script>';
$brandBody = '<script src="/brand-theme.js?v=20260913-3"></script>';
$bodyPos = strripos($html, '</body>');
if ($bodyPos !== false) $html = substr_replace($html, $accountsSourceBridge.$guard.$brandBody, $bodyPos, 0); else $html .= $accountsSourceBridge.$guard.$brandBody;
echo $html;
