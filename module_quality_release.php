<?php
declare(strict_types=1);
/* Milling/Exports cross-module repair layer, 2026-09-09. */
ob_start();
require __DIR__ . '/module_release.php';
$html = (string)ob_get_clean();
$id = strtolower((string)($_GET['id'] ?? ''));

function tt_q_replace(string $html, string $old, string $new, string $label): string {
    if (!str_contains($html, $old)) {
        error_log('Transtrade quality/loading repair not matched: ' . $label);
        return $html;
    }
    return str_replace($old, $new, $html);
}

$oldShipsDecl = <<<'JS'
let ships=parse('tt30ship',[]),oldShips=new Map(ships.filter(x=>x._ttBridge==='exports').map(x=>[x._ttBridgeId,x]));ships=ships.filter(x=>x._ttBridge!=='exports');
JS;
$newShipsDecl = <<<'JS'
let ships=parse('tt30ship',[]),allBridgeShips=ships.filter(x=>x._ttBridge==='exports'),oldShips=new Map(allBridgeShips.map(x=>[x._ttBridgeId,x]));ships=ships.filter(x=>x._ttBridge!=='exports');const legacyCounts=new Map();allBridgeShips.forEach(x=>legacyCounts.set(String(x._ttBridgeId||''),(legacyCounts.get(String(x._ttBridgeId||''))||0)+1));let quarantine=parse('tt39bridgequarantine',[]),qSeen=new Set(quarantine.map(x=>String(x.key||'')));allBridgeShips.filter(x=>legacyCounts.get(String(x._ttBridgeId||''))>1&&(x.containers||[]).length).forEach(x=>{const key=[x.contractRef,x._ttLotId||x.ref,x._ttBridgeId].join('|');if(!qSeen.has(key)){quarantine.push({key,at:new Date().toISOString(),reason:'Legacy ambiguous lot identity',contractRef:x.contractRef,lotRef:x._ttLotId||x.ref,containers:x.containers});qSeen.add(key)}});if(quarantine.length)put('tt39bridgequarantine',quarantine);
JS;
$html = tt_q_replace($html, $oldShipsDecl, $newShipsDecl, 'bridge shipment migration setup');

$oldExDecl = <<<'JS'
let exmills=parse('tt35exmill',[]),oldEx=new Map(exmills.filter(x=>x._ttBridge==='exports').map(x=>[x._ttBridgeId,x]));exmills=exmills.filter(x=>x._ttBridge!=='exports');
JS;
$newExDecl = <<<'JS'
let exmills=parse('tt35exmill',[]),allBridgeEx=exmills.filter(x=>x._ttBridge==='exports'),oldEx=new Map(allBridgeEx.map(x=>[x._ttBridgeId,x]));exmills=exmills.filter(x=>x._ttBridge!=='exports');
JS;
$html = tt_q_replace($html, $oldExDecl, $newExDecl, 'bridge ex-mill migration setup');

$html = tt_q_replace($html,"const bid=x.lotId+'|'+i,pk=","const lotRecord=(root.shipments||[]).find(s=>String(s.id)===String(x.shipmentId))||{},bid=(x.shipmentId||x.contractRef+'|'+x.lotId)+'|'+i,pk=",'unique loading bridge identity');
$html = tt_q_replace($html,"const old=oldEx.get(bid)||{};exmills.push({","const old=oldEx.get(bid)||allBridgeEx.find(y=>String(y.contractRef||'')===String(x.contractRef||'')&&String(y.shipment||'')===String(x.lotId||'')&&String(y._ttBridgeIndex??String(y._ttBridgeId||'').split('|').pop())===String(i))||{};exmills.push({",'ex-mill legacy identity migration');
$html = tt_q_replace($html,"const old=oldShips.get(bid)||{};ships.push({","const old=oldShips.get(bid)||allBridgeShips.find(y=>String(y.contractRef||'')===String(x.contractRef||'')&&String(y._ttLotId||y.ref||'')===String(x.lotId||'')&&String(y._ttBridgeIndex??String(y._ttBridgeId||'').split('|').pop())===String(i))||{},legacyAmbiguous=legacyCounts.get(String(old._ttBridgeId||''))>1,preservedContainers=legacyAmbiguous?[]:(old.containers||[]);ships.push({",'milling legacy identity migration');
$html = tt_q_replace($html,"_ttBridge:'exports',_ttBridgeId:bid,soda:","_ttBridge:'exports',_ttBridgeId:bid,_ttBridgeIndex:i,_ttShipmentId:x.shipmentId||'',soda:",'ex-mill shipment id carry');
$html = tt_q_replace($html,"shipment:x.lotId,contractRef:x.contractRef","shipment:x.lotId,shipmentId:x.shipmentId||'',contractContainers:Number(lotRecord.containers||0),totalContainers:Math.max(1,Math.ceil(Number(a.containers||0))),contractRef:x.contractRef",'ex-mill contract container carry');
$html = tt_q_replace($html,"product:c.product||'',qtyKg:","product:c.product||'',brand:pk.brand||'UNNAMED BRAND',qtyKg:",'ex-mill brand carry');
$html = tt_q_replace($html,"_ttBridge:'exports',_ttBridgeId:bid,_ttLotId:x.lotId,contractRef:x.contractRef,ref:x.lotId,brand:","_ttBridge:'exports',_ttBridgeId:bid,_ttBridgeIndex:i,_ttShipmentId:x.shipmentId||'',_ttLotId:x.lotId,contractRef:x.contractRef,ref:x.lotId,contractContainers:Number(lotRecord.containers||0),brand:",'milling exact shipment id carry');
$html = tt_q_replace($html,"totalContainers:Number(a.containers||0),bagsPerContainer:","totalContainers:Math.max(1,Math.ceil(Number(a.containers||0))),bagsPerContainer:",'milling assigned physical container count');
$html = tt_q_replace($html,"containers:old.containers||[],audit:old.audit||[]","containers:preservedContainers,audit:old.audit||[]",'discard ambiguous legacy container assignment');

$oldMillStart = <<<'JS'
const root=parse('transtrade_export_v3_operational',null);if(!root?.shipments)return;let changed=false;
JS;
$newMillStart = <<<'JS'
const root=parse('transtrade_export_v3_operational',null);if(!root?.shipments)return;let changed=false;const lotRefCounts=new Map();root.shipments.filter(s=>s.kind==='lot').forEach(s=>lotRefCounts.set(String(s.lotId||''),(lotRefCounts.get(String(s.lotId||''))||0)+1));for(const target of root.shipments.filter(s=>s.kind==='lot')){if(lotRefCounts.get(String(target.lotId||''))<=1)continue;const legacy=(target.millActuals||[]).filter(a=>a?.source==='Milling'&&!a.shipmentId);if(!legacy.length)continue;target.millActualsQuarantine=[...(target.millActualsQuarantine||[]),...legacy.map(a=>({...a,quarantinedAt:new Date().toISOString(),quarantineReason:'Legacy lot-only Milling identity'}))];target.millActuals=(target.millActuals||[]).filter(a=>!(a?.source==='Milling'&&!a.shipmentId));root.alerts=root.alerts||[];root.alerts.unshift({id:'AL-'+Date.now()+'-'+Math.random().toString(36).slice(2,7),area:'MILL ACTUALS',contractRef:target.contractRef,kind:'Migration Review',message:`Legacy Milling container data for ${target.contractRef} / ${target.lotId} was quarantined because the old lot-only identity was ambiguous. Re-enter against the corrected loading instruction.`,createdAt:new Date().toISOString(),seen:false});changed=true}
JS;
$html = tt_q_replace($html,$oldMillStart,$newMillStart,'legacy Export millActual quarantine');

$oldTarget = <<<'JS'
const add=(shipment,row,location)=>{const ref=String(shipment||''),target=root.shipments.find(s=>String(s.id)===ref||String(s.lotId||'')===ref)||root.shipments.find(s=>(s.loading?.lots||[]).some(l=>String(l.lotId)===ref))||root.shipments.find(s=>String(s.contractRef)===ref&&s.kind==='lot');if(!target||!row?.container)return;target.millActuals=target.millActuals||[];
JS;
$newTarget = <<<'JS'
const add=(shipment,row,location)=>{const ref=String(shipment||''),contractRef=String(row?.contractRef||''),lotRef=String(row?.lotRef||row?.shipment||''),target=root.shipments.find(s=>String(s.id)===ref)||(contractRef&&lotRef?root.shipments.find(s=>s.kind==='lot'&&String(s.contractRef||'')===contractRef&&String(s.lotId||'')===lotRef):null);if(!target||!row?.container)return;target.millActuals=target.millActuals||[];
JS;
$html = tt_q_replace($html,$oldTarget,$newTarget,'exact Export target selection');

$oldNet = <<<'JS'
net=Number(row.weight??row.kg??0),contribution={id:contributionId,location,brand:row.brand||pk.brand||'',packing:`${pk.size||''} ${c.packingUnit||'KG'}`,bags,netKg:net,tareKg:tare,grossKg:net+tare,truck:row.truck||'',gatePass:row.gate||'',loadedDate:row.date||''};let actual=target.millActuals.find(a=>String(a.number).replace(/[^A-Za-z0-9]/g,'').toUpperCase()===clean);if(actual){
JS;
$newNet = <<<'JS'
net=Number(row.weight??row.kg??0),docAlloc=(target.loadingPlan?.allocations||[]).find(a=>{const ap=(c.packings||[])[Number(a.packIndex||0)]||{};return String(ap.brand||'').toUpperCase()===String(row.brand||'').toUpperCase()&&(!location||String(a.name||'')===String(location))})||(target.loadingPlan?.allocations||[]).find(a=>{const ap=(c.packings||[])[Number(a.packIndex||0)]||{};return String(ap.brand||'').toUpperCase()===String(row.brand||'').toUpperCase()}),docNet=Number(docAlloc?.weightPer||0)>0?Number(docAlloc.weightPer)*1000:(target.kind==='lot'&&Number(target.containers||0)>0?Number(target.plannedQty||0)*1000/Number(target.containers):net),contribution={id:contributionId,location,brand:row.brand||pk.brand||'',packing:`${pk.size||''} ${c.packingUnit||'KG'}`,bags,netKg:net,millNetKg:net,tareKg:tare,grossKg:net+tare,truck:row.truck||'',gatePass:row.gate||'',loadedDate:row.date||''};let actual=target.millActuals.find(a=>String(a.number).replace(/[^A-Za-z0-9]/g,'').toUpperCase()===clean);const expected=Number(target.containers||0);if(!actual&&expected>0&&target.millActuals.length>=expected){root.alerts=root.alerts||[];root.alerts.unshift({id:'AL-'+Date.now()+'-'+Math.random().toString(36).slice(2,7),area:'MILL ACTUALS',contractRef:target.contractRef,kind:'Container Limit',message:`Rejected extra container ${number}. ${target.contractRef} / ${target.lotId} is instructed for ${expected} physical container(s).`,createdAt:new Date().toISOString(),seen:false});changed=true;return}if(actual){
JS;
$html = tt_q_replace($html,$oldNet,$newNet,'documentary versus mill weight split and Export hard cap');
$html = tt_q_replace($html,"netKg:Number(actual.netKg||0),tareKg:Number(actual.tareKg||0),grossKg:Number(actual.grossKg||0)","netKg:Number(actual.millNetKg??actual.netKg??0),millNetKg:Number(actual.millNetKg??actual.netKg??0),tareKg:Number(actual.tareKg||0),grossKg:Number(actual.grossKg||0)",'legacy contribution mill weight preservation');
$html = tt_q_replace($html,"actual.bags=actual.contributions.reduce((n,x)=>n+Number(x.bags||0),0);actual.netKg=actual.contributions.reduce((n,x)=>n+Number(x.netKg||0),0);actual.tareKg=actual.contributions.reduce((n,x)=>n+Number(x.tareKg||0),0);actual.grossKg=actual.contributions.reduce((n,x)=>n+Number(x.grossKg||0),0);","actual.bags=actual.contributions.reduce((n,x)=>n+Number(x.bags||0),0);actual.millNetKg=actual.contributions.reduce((n,x)=>n+Number(x.millNetKg??x.netKg??0),0);actual.netKg=docNet;actual.documentNetKg=docNet;actual.tareKg=actual.contributions.reduce((n,x)=>n+Number(x.tareKg||0),0);actual.grossKg=actual.netKg+actual.tareKg;actual.shipmentId=target.id;actual.contractRef=target.contractRef;actual.lotRef=target.lotId||ref;",'existing Export actual documentary weight lock');
$html = tt_q_replace($html,"actual={number,seal:String(row.seal||'').toUpperCase(),packing:contribution.packing,packSize:Number(pk.size||0),unit:c.packingUnit||'KG',bags,netKg:net,tareKg:tare,grossKg:net+tare,brand:contribution.brand,location,truck:contribution.truck,gatePass:contribution.gatePass,loadedDate:contribution.loadedDate,lotRef:ref,source:'Milling',contributions:[contribution]}","actual={number,seal:String(row.seal||'').toUpperCase(),packing:contribution.packing,packSize:Number(pk.size||0),unit:c.packingUnit||'KG',bags,netKg:docNet,documentNetKg:docNet,millNetKg:net,tareKg:tare,grossKg:docNet+tare,brand:contribution.brand,location,truck:contribution.truck,gatePass:contribution.gatePass,loadedDate:contribution.loadedDate,shipmentId:target.id,contractRef:target.contractRef,lotRef:target.lotId||ref,source:'Milling',contributions:[contribution]}",'new Export actual documentary weight lock');

$oldTTILoop = <<<'JS'
const ships=parse('tt30ship',[]);(parse('tt32exportsync',[])||[]).forEach(f=>{const s=ships.find(x=>String(x.ref)===String(f.shipment));const c=s?.containers?.find(x=>String(x.container)===String(f.container));add(f.shipment,{...f,...c,brand:f.brand||s?.brand,_contributionId:`TTI|${f.shipment}|${f.container}|${f.brand||s?.brand||''}|${f.gate||c?.gate||''}`},'TTI Rice Mills')});
JS;
$newTTILoop = <<<'JS'
const ships=parse('tt30ship',[]);(parse('tt32exportsync',[])||[]).forEach(f=>{const s=ships.find(x=>f.shipmentId&&String(x._ttShipmentId||'')===String(f.shipmentId))||ships.find(x=>f.contractRef&&String(x.contractRef||'')===String(f.contractRef)&&String(x._ttLotId||x.ref||'')===String(f.lotRef||f.shipment||''));if(!s)return;const c=s?.containers?.find(x=>String(x.container)===String(f.container));add(f.shipmentId||s._ttShipmentId,{...f,...c,shipmentId:f.shipmentId||s._ttShipmentId,contractRef:f.contractRef||s.contractRef,lotRef:f.lotRef||s._ttLotId||s.ref,brand:f.brand||s?.brand,_contributionId:`TTI|${f.shipmentId||s._ttShipmentId}|${f.container}|${f.brand||s?.brand||''}|${f.gate||c?.gate||''}`},'TTI Rice Mills')});
JS;
$html = tt_q_replace($html,$oldTTILoop,$newTTILoop,'exact TTI container routing');

$oldExLoop = <<<'JS'
const sodas=parse('tt35exmill',[]);(parse('tt35exload',[])||[]).forEach(x=>{const s=sodas.find(z=>z.id===x.sodaId);add(x.shipment||s?.shipment,{...x,weight:x.kg,brand:s?.brand||'',_contributionId:`EX|${x.sodaId||''}|${x.shipment||s?.shipment||''}|${x.container}|${x.gate||''}`},s?.mill||'Ex-Mill')});
JS;
$newExLoop = <<<'JS'
const sodas=parse('tt35exmill',[]);(parse('tt35exload',[])||[]).forEach(x=>{const s=sodas.find(z=>z.id===x.sodaId);if(!s)return;add(x.shipmentId||s._ttShipmentId,{...x,weight:x.kg,shipmentId:x.shipmentId||s._ttShipmentId,contractRef:x.contractRef||s.contractRef,lotRef:x.lotRef||s.shipment,brand:s?.brand||'',_contributionId:`EX|${x.sodaId||''}|${x.shipmentId||s._ttShipmentId}|${x.container}|${x.gate||''}`},s?.mill||'Ex-Mill')});
JS;
$html = tt_q_replace($html,$oldExLoop,$newExLoop,'exact Ex-Mill container routing');

$oldNotify = <<<'JS'
function notifyRemote(){
    let b=document.getElementById('ttSyncNotice');if(!b){b=document.createElement('button');b.id='ttSyncNotice';b.type='button';b.style.cssText='position:fixed;right:12px;top:56px;z-index:100000;border:0;border-radius:10px;padding:10px 13px;background:#16825d;color:#fff;font:700 12px Arial;box-shadow:0 5px 18px #0004';b.onclick=()=>location.reload();document.body.appendChild(b)}b.textContent='Updated'+(lastRemoteBy?' by '+lastRemoteBy:'')+' — refresh';
    const el=document.activeElement,editing=el&&/INPUT|TEXTAREA|SELECT/.test(el.tagName);if(!editing)setTimeout(()=>location.reload(),1200);
  }
JS;
$newNotify = <<<'JS'
function notifyRemote(){
    let b=document.getElementById('ttSyncNotice');if(!b){b=document.createElement('button');b.id='ttSyncNotice';b.type='button';b.style.cssText='position:fixed;right:12px;top:56px;z-index:100000;border:0;border-radius:10px;padding:10px 13px;background:#16825d;color:#fff;font:700 12px Arial;box-shadow:0 5px 18px #0004';b.onclick=()=>{bridge();dispatchEvent(new CustomEvent('tt:shared-updated',{detail:{by:lastRemoteBy}}))};document.body.appendChild(b)}b.textContent='Updated'+(lastRemoteBy?' by '+lastRemoteBy:'')+' — synced';dispatchEvent(new CustomEvent('tt:shared-updated',{detail:{by:lastRemoteBy}}));
  }
JS;
$html = tt_q_replace($html,$oldNotify,$newNotify,'no automatic page reload on shared updates');

if($id==='milling'){
    $script='<script src="/milling-quality-identity.js?v=20260909-2"></script>';
    if(str_contains($html,'</body>'))$html=str_replace('</body>',$script.'</body>',$html);else$html.=$script;
}

echo $html;
