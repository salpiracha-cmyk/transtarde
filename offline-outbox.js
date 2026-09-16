(()=>{
'use strict';

const access=window.TT_MODULE_ACCESS||window.TT_ACCOUNT_ACCESS||{};
const DB_NAME='transtrade-offline-outbox-v2', STORE='pending';
const MODULE=access.moduleId||access.module||'module';
const USER_KEY=[MODULE,access.user||'user'].join('|');
const nativeFetch=window.fetch.bind(window);
const resourceVersions=new Map();
let explicitAction=null,recoveryPromptOpen=false;

const uuid=()=>globalThis.crypto?.randomUUID?.()||'tt-'+Date.now().toString(36)+'-'+Math.random().toString(36).slice(2);
const methodOf=(input,init)=>String(init?.method||input?.method||'GET').toUpperCase();
const urlOf=input=>new URL(typeof input==='string'?input:String(input?.url||''),location.href);
const isApi=url=>url.origin===location.origin&&/\/api\/[A-Za-z0-9_.-]+\.php$/.test(url.pathname);
const entityOf=(url,body)=>String(url.searchParams.get('entity')||body?.entity||'').toUpperCase();
const resourceKey=(url,body)=>url.pathname+'|'+entityOf(url,body);
const finalWords=/\b(next|save|confirm|issue|post|create|complete|send|receive|dispatch|approve|verify|delete)\b/i;

function beginAction(label){explicitAction={id:uuid(),label:String(label||'Final action'),startedAt:new Date().toISOString()}}
function actionLabel(el){return String(el?.getAttribute?.('aria-label')||el?.title||el?.textContent||el?.value||'').replace(/\s+/g,' ').trim()}
document.addEventListener('click',event=>{const el=event.target.closest('button,input[type="submit"],[role="button"]');if(!el)return;const label=actionLabel(el);if(finalWords.test(label))beginAction(label);else explicitAction=null},true);
document.addEventListener('submit',event=>beginAction(actionLabel(event.submitter)||'Submit'),true);
document.addEventListener('input',()=>{explicitAction=null},true);

const openDb=()=>new Promise((resolve,reject)=>{const req=indexedDB.open(DB_NAME,1);req.onupgradeneeded=()=>{const db=req.result;if(!db.objectStoreNames.contains(STORE)){const s=db.createObjectStore(STORE,{keyPath:'id'});s.createIndex('userKey','userKey',{unique:false})}};req.onsuccess=()=>resolve(req.result);req.onerror=()=>reject(req.error)});
const transact=async(mode,fn)=>{const db=await openDb();return new Promise((resolve,reject)=>{const tx=db.transaction(STORE,mode),store=tx.objectStore(STORE);let result;try{result=fn(store)}catch(e){db.close();reject(e);return}tx.oncomplete=()=>{db.close();resolve(result)};tx.onerror=()=>{db.close();reject(tx.error)};tx.onabort=()=>{db.close();reject(tx.error||new Error('Offline recovery storage was interrupted.'))}})};
const list=async()=>{const db=await openDb();return new Promise((resolve,reject)=>{const tx=db.transaction(STORE,'readonly'),req=tx.objectStore(STORE).index('userKey').getAll(USER_KEY);req.onsuccess=()=>resolve((req.result||[]).sort((a,b)=>String(a.createdAt).localeCompare(String(b.createdAt))));req.onerror=()=>reject(req.error);tx.oncomplete=()=>db.close()})};
const put=entry=>transact('readwrite',s=>s.put(entry));
const remove=id=>transact('readwrite',s=>s.delete(id));
const removeMany=ids=>transact('readwrite',s=>{for(const id of ids)s.delete(id)});

async function serializeBody(body){
  if(body==null)return{kind:'none',value:null,parsed:null};
  if(typeof body==='string'){let parsed=null;try{parsed=JSON.parse(body)}catch{}return{kind:'text',value:body,parsed}}
  if(body instanceof URLSearchParams)return{kind:'params',value:String(body),parsed:null};
  if(body instanceof FormData){const value=[];for(const pair of body.entries())value.push(pair);return{kind:'form',value,parsed:null}}
  return null;
}
function restoreBody(saved){if(saved.kind==='text')return saved.value;if(saved.kind==='params')return new URLSearchParams(saved.value);if(saved.kind==='form'){const fd=new FormData();for(const [k,v] of saved.value||[])fd.append(k,v);return fd}return undefined}
function headersWith(initHeaders,entry){const h=new Headers(initHeaders||{});h.set('X-TT-Transaction-ID',entry.id);if(entry.baseResourceVersion!==null)h.set('X-TT-Base-Resource-Version',String(entry.baseResourceVersion));return h}
async function updateNotice(knownEntries=null){
  // Recovery remains active, but pending handoffs must never create a floating status badge.
  document.getElementById('ttOfflineNotice')?.remove();
  document.getElementById('ttAccountsOutboxBadge')?.remove();
  document.querySelectorAll('[data-tt-accounts-outbox-badge]').forEach(node=>node.remove());
  return knownEntries||await list();
}

const html=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
function entryReference(entry){const parsed=entry?.body?.parsed||Object.fromEntries((entry?.body?.kind==='form'?entry.body.value:[])||[]),keys=['contractRef','contract_ref','lotId','lotRef','ref','invoiceNo','poNo','key','entity'];for(const key of keys){const value=parsed?.[key];if(value!==undefined&&value!==null&&String(value).trim())return String(value).trim()}return entityOf(urlOf(entry.url),parsed)||'General update'}
function entryStatus(entry){if(entry.status==='conflict')return'Conflict — newer server information exists';if(entry.status==='processing')return'Server is still processing';if(entry.status==='failed')return'Not acknowledged by server';if(entry.status==='offline')return'Saved while server was unavailable';return'Pending review'}
function installRecoveryStyles(){if(document.getElementById('ttRecoveryStyles'))return;const style=document.createElement('style');style.id='ttRecoveryStyles';style.textContent=`.ttRecoveryBackdrop{position:fixed;inset:0;z-index:2147483000;background:#09150dcc;display:grid;place-items:center;padding:24px}.ttRecoveryDialog{width:min(920px,96vw);max-height:88vh;overflow:hidden;background:#fff;border-radius:16px;box-shadow:0 24px 80px #0008;color:#17221b;font:14px/1.4 Arial,sans-serif}.ttRecoveryHead{padding:20px 22px 14px;border-bottom:1px solid #d9e1dc}.ttRecoveryHead h2{margin:0 0 5px;font-size:22px}.ttRecoveryHead p{margin:0;color:#536158}.ttRecoveryList{max-height:58vh;overflow:auto;padding:10px 22px}.ttRecoveryRow{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:14px;padding:13px 0;border-bottom:1px solid #e3e8e5}.ttRecoveryRow:last-child{border-bottom:0}.ttRecoveryTitle{font-weight:800}.ttRecoveryMeta{margin-top:3px;color:#647168;font-size:12px}.ttRecoveryStatus{margin-top:5px;color:#7a4b00;font-weight:700;font-size:12px}.ttRecoveryActions{display:flex;gap:7px;align-items:center}.ttRecoveryActions button,.ttRecoveryFoot button{border:0;border-radius:8px;padding:9px 13px;font-weight:800;cursor:pointer}.ttRecoveryUpload{background:#2f7148;color:#fff}.ttRecoveryDiscard{background:#f3e7e5;color:#98291f}.ttRecoveryFoot{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:14px 22px;border-top:1px solid #d9e1dc;background:#f7f9f7}.ttRecoveryClose{background:#e5ebe7;color:#213d2d}.ttRecoveryConfirm{grid-column:1/-1;background:#fff4e5;border:1px solid #e9c487;border-radius:9px;padding:10px;display:flex;justify-content:space-between;align-items:center;gap:10px}.ttRecoveryConfirm button{padding:7px 10px}@media(max-width:650px){.ttRecoveryRow{grid-template-columns:1fr}.ttRecoveryActions{justify-content:flex-start}}`;document.head.appendChild(style)}
function closeRecoveryModal(){document.getElementById('ttRecoveryModal')?.remove();recoveryPromptOpen=false}
async function reconcileEntries(entries){if(!entries.length||!navigator.onLine)return entries;try{const ids=entries.map(entry=>entry.id).join(','),response=await nativeFetch('/api/offline-status.php?ids='+encodeURIComponent(ids),{credentials:'same-origin',headers:{Accept:'application/json'}});if(!response.ok)return entries;const result=await response.json(),statuses=result?.transactions||{},now=Date.now()/1000;for(const entry of entries){const status=statuses[entry.id]||{};if(status.state==='complete'){await remove(entry.id);continue}if(status.state==='pending'&&now-Number(status.time||0)<600){entry.status='processing';entry.updatedAt=new Date().toISOString();await put(entry)}else if(entry.status==='processing'){entry.status='pending';entry.updatedAt=new Date().toISOString();await put(entry)}}return await list()}catch(error){console.error('Saved-entry reconciliation',error);return entries}}
async function discardEntry(entry,row){let confirmBox=row.querySelector('.ttRecoveryConfirm');if(confirmBox)return;confirmBox=document.createElement('div');confirmBox.className='ttRecoveryConfirm';confirmBox.innerHTML=`<span>Discard this saved entry from this device? This cannot be uploaded afterwards.</span><span><button type="button" data-keep>Keep</button> <button type="button" class="ttRecoveryDiscard" data-confirm-discard>Discard entry</button></span>`;row.appendChild(confirmBox);confirmBox.querySelector('[data-keep]').onclick=()=>confirmBox.remove();confirmBox.querySelector('[data-confirm-discard]').onclick=async()=>{await remove(entry.id);row.remove();const remaining=await list();if(!remaining.length)closeRecoveryModal()}}
async function uploadEntry(entry,row){if(entry.status==='conflict'||entry.status==='processing')return;const button=row.querySelector('.ttRecoveryUpload');button.disabled=true;button.textContent='Uploading…';try{const result=await replay(entry);if(result.ok){row.remove();if(!(await list()).length)closeRecoveryModal();return}entry.status=result.conflict?'conflict':'failed';row.querySelector('.ttRecoveryStatus').textContent=entryStatus(entry);button.textContent='Upload';button.disabled=result.conflict}catch{entry.status='offline';row.querySelector('.ttRecoveryStatus').textContent=entryStatus(entry);button.textContent='Upload';button.disabled=false}}
async function bulkDiscard(entries,conflictsOnly,modal){const selected=entries.filter(entry=>!conflictsOnly||entry.status==='conflict');if(!selected.length)return;const label=conflictsOnly?'conflicting entries':'remaining entries';if(!window.confirm(`Discard ${selected.length} ${label} from this device?\n\nThey cannot be uploaded later. Current server data will not be deleted.`))return;await removeMany(selected.map(entry=>entry.id));const remaining=await list();if(remaining.length)showRecoveryModal(remaining);else closeRecoveryModal()}
function showRecoveryModal(entries){closeRecoveryModal();if(!entries.length)return;installRecoveryStyles();recoveryPromptOpen=true;const conflicts=entries.filter(entry=>entry.status==='conflict').length;const modal=document.createElement('div');modal.id='ttRecoveryModal';modal.className='ttRecoveryBackdrop';modal.innerHTML=`<section class="ttRecoveryDialog" role="dialog" aria-modal="true" aria-labelledby="ttRecoveryTitle"><header class="ttRecoveryHead"><h2 id="ttRecoveryTitle">Saved entries awaiting server confirmation</h2><p>${entries.length} entr${entries.length===1?'y was':'ies were'} kept safely on this device. Entries already confirmed by the server have been removed automatically. Review each remaining entry before uploading or discarding it.</p></header><div class="ttRecoveryList"></div><footer class="ttRecoveryFoot"><span>No entry is uploaded automatically.</span><span class="ttRecoveryActions">${conflicts?`<button type="button" class="ttRecoveryDiscard ttRecoveryDiscardConflicts">Discard all conflicts (${conflicts})</button>`:''}<button type="button" class="ttRecoveryDiscard ttRecoveryDiscardAll">Discard all remaining (${entries.length})</button><button type="button" class="ttRecoveryClose">Continue without uploading</button></span></footer></section>`;const listNode=modal.querySelector('.ttRecoveryList');for(const entry of entries){const row=document.createElement('article');row.className='ttRecoveryRow';row.innerHTML=`<div><div class="ttRecoveryTitle">${html(entry.actionLabel||'Saved action')}</div><div class="ttRecoveryMeta">${html(String(entry.module||MODULE).toUpperCase())} · ${html(entryReference(entry))} · ${html(new Date(entry.createdAt).toLocaleString())}</div><div class="ttRecoveryStatus">${html(entryStatus(entry))}</div></div><div class="ttRecoveryActions"><button type="button" class="ttRecoveryUpload" ${entry.status==='conflict'||entry.status==='processing'?'disabled':''}>Upload</button><button type="button" class="ttRecoveryDiscard">Discard</button></div>`;row.querySelector('.ttRecoveryUpload').onclick=()=>uploadEntry(entry,row);row.querySelector('.ttRecoveryDiscard').onclick=()=>discardEntry(entry,row);listNode.appendChild(row)}modal.querySelector('.ttRecoveryDiscardConflicts')?.addEventListener('click',()=>bulkDiscard(entries,true,modal));modal.querySelector('.ttRecoveryDiscardAll').onclick=()=>bulkDiscard(entries,false,modal);modal.querySelector('.ttRecoveryClose').onclick=closeRecoveryModal;document.body.appendChild(modal)}

async function prepareEntry(input,init,url,saved,action){
  const key=resourceKey(url,saved.parsed),id=uuid();
  const entry={id,userKey:USER_KEY,module:MODULE,actionId:action?.id||id,actionLabel:action?.label||'Final action',url:url.pathname+url.search,method:methodOf(input,init),headers:[...new Headers(init?.headers||input?.headers||{}).entries()].filter(([k])=>!/^x-tt-/i.test(k)),body:saved,resourceKey:key,baseResourceVersion:resourceVersions.has(key)?resourceVersions.get(key):null,createdAt:new Date().toISOString(),updatedAt:new Date().toISOString(),status:'pending'};
  await put(entry);await updateNotice();return entry;
}
async function acknowledged(response){if(!response.ok)return false;const type=response.headers.get('content-type')||'';if(!/json/i.test(type))return true;try{const data=await response.clone().json();return data?.ok===true}catch{return false}}
async function processResponse(entry,response){
  const version=response.headers.get('X-TT-Resource-Version');
  if(version!==null)resourceVersions.set(entry.resourceKey,Number(version));
  if(await acknowledged(response)){await remove(entry.id);await updateNotice();return}
  entry.status=response.status===409?'conflict':'failed';
  entry.error=response.status===409?'A newer server version exists. Review before uploading.':'The server did not confirm this action. The submitted entry remains on this device.';
  entry.updatedAt=new Date().toISOString();
  await put(entry);await updateNotice()
}

window.fetch=async function(input,init={}){
  const url=urlOf(input),method=methodOf(input,init);
  if(method==='GET'&&isApi(url)){
    const response=await nativeFetch(input,init),version=response.headers.get('X-TT-Resource-Version');
    if(version!==null)resourceVersions.set(resourceKey(url,null),Number(version));
    return response;
  }
  if(!isApi(url)||!['POST','PUT','PATCH','DELETE'].includes(method))return nativeFetch(input,init);
  const action=explicitAction;
  if(!action){let body=null;if(typeof init?.body==='string'){try{body=JSON.parse(init.body)}catch{}}const response=await nativeFetch(input,init),version=response.headers.get('X-TT-Resource-Version');if(version!==null)resourceVersions.set(resourceKey(url,body),Number(version));return response}
  explicitAction=null;
  const saved=await serializeBody(init?.body??input?.body);if(!saved)return nativeFetch(input,init);
  const entry=await prepareEntry(input,init,url,saved,action);
  const requestInit={...init,headers:headersWith(init?.headers??input?.headers,entry)};
  try{const response=await nativeFetch(input,requestInit);await processResponse(entry,response);return response}catch(error){entry.status='offline';entry.error='Server acknowledgement was not received.';entry.updatedAt=new Date().toISOString();await put(entry);await updateNotice();throw error}
};

async function replay(entry){const init={method:entry.method,credentials:'same-origin',headers:headersWith(entry.headers,entry),body:restoreBody(entry.body)};const response=await nativeFetch(entry.url,init);await processResponse(entry,response);return{ok:await acknowledged(response),conflict:response.status===409,status:response.status}}
async function offerRecovery(){
  if(recoveryPromptOpen)return;
  let entries=await list();
  entries=await reconcileEntries(entries);
  await updateNotice(entries);
  if(!entries.length)return;
  showRecoveryModal(entries)
}
const recoverPending=()=>offerRecovery();
async function boot(){
  const entries=await list();
  await updateNotice(entries);
  if(entries.length)await offerRecovery();
  addEventListener('online',offerRecovery);
  if(window.TT_SHARED_SYNC?.saveNow){
    const original=window.TT_SHARED_SYNC.saveNow.bind(window.TT_SHARED_SYNC);
    window.TT_SHARED_SYNC.saveNow=(...args)=>{beginAction('Shared final save');return original(...args)}
  }
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});else boot();
window.TT_OFFLINE_OUTBOX={list,recoverPending,offerRecovery,beginAction};
})();
