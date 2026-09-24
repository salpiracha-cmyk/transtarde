(()=>{
'use strict';

const access=window.TT_MODULE_ACCESS||window.TT_ACCOUNT_ACCESS||{};
const DB_NAME='transtrade-offline-outbox-v2',STORE='pending';
const MODULE=access.moduleId||access.module||'module';
const USER_KEY=[MODULE,access.user||'user'].join('|');
const nativeFetch=window.fetch.bind(window);
const resourceVersions=new Map();
const finalWords=/\b(next|save|confirm|issue|post|create|complete|send|receive|dispatch|approve|verify|delete|deactivate|amend|update|pay|record|allocate|settle|utilize)\b/i;
let explicitAction=null,recoveryPromptOpen=false;

const uuid=()=>globalThis.crypto?.randomUUID?.()||'tt-'+Date.now().toString(36)+'-'+Math.random().toString(36).slice(2);
const methodOf=(input,init)=>String(init?.method||input?.method||'GET').toUpperCase();
const urlOf=input=>new URL(typeof input==='string'?input:String(input?.url||''),location.href);
const isApi=url=>url.origin===location.origin&&/\/api\/[A-Za-z0-9_.-]+\.php$/.test(url.pathname);
const entityOf=(url,body)=>String(url.searchParams.get('entity')||body?.entity||'').toUpperCase();
const resourceKey=(url,body)=>url.pathname+'|'+entityOf(url,body);
const html=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
const cssEscape=value=>globalThis.CSS?.escape?.(String(value))||String(value).replace(/[^A-Za-z0-9_-]/g,char=>'\\'+char);

function domPath(node){
  if(!node||node===document.body)return'body';
  if(node.id)return'#'+cssEscape(node.id);
  const parts=[];
  while(node&&node.nodeType===1&&node!==document.body&&parts.length<8){
    let part=node.tagName.toLowerCase();
    const siblings=[...(node.parentElement?.children||[])].filter(item=>item.tagName===node.tagName);
    if(siblings.length>1)part+=`:nth-of-type(${siblings.indexOf(node)+1})`;
    parts.unshift(part);node=node.parentElement;
  }
  return'body>'+parts.join('>');
}

function workflowSnapshot(source=null){
  const root=source?.closest?.('form,[role="dialog"],.workspace.active,.panel:not(.hidden),.modal,.overlay')||document.querySelector('.workspace.active,[role="dialog"],.panel:not(.hidden)')||document.body;
  const controls=[];
  root.querySelectorAll('input,select,textarea,[contenteditable="true"]').forEach(control=>{
    if(control.type==='file')return;
    controls.push({selector:domPath(control),value:control.isContentEditable?control.textContent:control.value,checked:'checked'in control?!!control.checked:null,selected:control.tagName==='SELECT'&&control.multiple?[...control.selectedOptions].map(option=>option.value):null});
  });
  return{url:location.pathname+location.search+location.hash,root:domPath(root),controls,scrollX:scrollX||0,scrollY:scrollY||0,active:domPath(document.activeElement),capturedAt:new Date().toISOString()};
}

function restoreWorkflow(workflow){
  if(!workflow)return;
  const target=new URL(workflow.url||location.href,location.href);
  if(target.pathname!==location.pathname||target.search!==location.search){sessionStorage.setItem('tt-offline-restore',JSON.stringify(workflow));location.href=target.pathname+target.search+target.hash;return;}
  for(const item of workflow.controls||[]){
    const control=document.querySelector(item.selector);if(!control)continue;
    if(item.selected&&control.tagName==='SELECT'){for(const option of control.options)option.selected=item.selected.includes(option.value);}
    else if(control.isContentEditable)control.textContent=item.value??'';
    else control.value=item.value??'';
    if(item.checked!==null&&'checked'in control)control.checked=!!item.checked;
    control.dispatchEvent(new Event('input',{bubbles:true}));control.dispatchEvent(new Event('change',{bubbles:true}));
  }
  requestAnimationFrame(()=>{scrollTo(Number(workflow.scrollX||0),Number(workflow.scrollY||0));document.querySelector(workflow.active)?.focus?.();});
}

function beginAction(label,source=null){explicitAction={id:uuid(),label:String(label||'Final action'),startedAt:Date.now(),workflow:workflowSnapshot(source)}}
function activeAction(){return explicitAction&&Date.now()-explicitAction.startedAt<15000?explicitAction:null}
function actionLabel(element){return String(element?.getAttribute?.('aria-label')||element?.title||element?.textContent||element?.value||'').replace(/\s+/g,' ').trim()}
document.addEventListener('click',event=>{const element=event.target.closest('button,input[type="submit"],[role="button"]');if(!element)return;const label=actionLabel(element);if(finalWords.test(label))beginAction(label,element)},true);
document.addEventListener('submit',event=>beginAction(actionLabel(event.submitter)||'Submit',event.target),true);

const openDb=()=>new Promise((resolve,reject)=>{const request=indexedDB.open(DB_NAME,1);request.onupgradeneeded=()=>{const db=request.result;if(!db.objectStoreNames.contains(STORE)){const store=db.createObjectStore(STORE,{keyPath:'id'});store.createIndex('userKey','userKey',{unique:false})}};request.onsuccess=()=>resolve(request.result);request.onerror=()=>reject(request.error)});
const transact=async(mode,operation)=>{const db=await openDb();return new Promise((resolve,reject)=>{const tx=db.transaction(STORE,mode),store=tx.objectStore(STORE);let result;try{result=operation(store)}catch(error){db.close();reject(error);return}tx.oncomplete=()=>{db.close();resolve(result)};tx.onerror=()=>{db.close();reject(tx.error)};tx.onabort=()=>{db.close();reject(tx.error||new Error('Offline recovery storage was interrupted.'))}})};
const list=async()=>{const db=await openDb();return new Promise((resolve,reject)=>{const tx=db.transaction(STORE,'readonly'),request=tx.objectStore(STORE).index('userKey').getAll(USER_KEY);request.onsuccess=()=>resolve((request.result||[]).sort((a,b)=>String(a.createdAt).localeCompare(String(b.createdAt))));request.onerror=()=>reject(request.error);tx.oncomplete=()=>db.close()})};
const put=entry=>transact('readwrite',store=>store.put(entry));
const remove=id=>transact('readwrite',store=>store.delete(id));
const removeMany=ids=>transact('readwrite',store=>{for(const id of ids)store.delete(id)});

async function serializeBody(body){
  if(body==null)return{kind:'none',value:null,parsed:null};
  if(typeof body==='string'){let parsed=null;try{parsed=JSON.parse(body)}catch{}return{kind:'text',value:body,parsed};}
  if(body instanceof URLSearchParams)return{kind:'params',value:String(body),parsed:Object.fromEntries(body)};
  if(body instanceof FormData){const value=[];for(const pair of body.entries())value.push(pair);return{kind:'form',value,parsed:Object.fromEntries(value.filter(([,v])=>typeof v==='string'))};}
  return null;
}
function restoreBody(saved){if(saved.kind==='text')return saved.value;if(saved.kind==='params')return new URLSearchParams(saved.value);if(saved.kind==='form'){const body=new FormData();for(const [key,value]of saved.value||[])body.append(key,value);return body}return undefined}
function requestHeaders(headers,entry){const output=new Headers(headers||{});output.set('X-TT-Transaction-ID',entry.id);if(entry.baseResourceVersion!==null)output.set('X-TT-Base-Resource-Version',String(entry.baseResourceVersion));return output}

function installStyles(){
  if(document.getElementById('ttRecoveryStyles'))return;
  const style=document.createElement('style');style.id='ttRecoveryStyles';style.textContent=`#ttOfflineNotice{position:fixed;right:14px;bottom:14px;z-index:2147482990;border:0;border-radius:10px;background:#9b4b00;color:#fff;padding:10px 14px;font:800 13px Arial;box-shadow:0 8px 25px #0004;cursor:pointer}.ttRecoveryBackdrop{position:fixed;inset:0;z-index:2147483000;background:#09150dcc;display:grid;place-items:center;padding:24px}.ttRecoveryDialog{width:min(920px,96vw);max-height:88vh;overflow:hidden;background:#fff;border-radius:16px;box-shadow:0 24px 80px #0008;color:#17221b;font:14px/1.4 Arial}.ttRecoveryHead{padding:20px 22px 14px;border-bottom:1px solid #d9e1dc}.ttRecoveryHead h2{margin:0 0 5px;font-size:22px}.ttRecoveryHead p{margin:0;color:#536158}.ttRecoveryList{max-height:58vh;overflow:auto;padding:10px 22px}.ttRecoveryRow{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:14px;padding:13px 0;border-bottom:1px solid #e3e8e5}.ttRecoveryTitle{font-weight:800}.ttRecoveryMeta{margin-top:3px;color:#647168;font-size:12px}.ttRecoveryStatus{margin-top:5px;color:#7a4b00;font-weight:700;font-size:12px}.ttRecoveryActions,.ttRecoveryFootActions{display:flex;gap:7px;align-items:center;flex-wrap:wrap}.ttRecoveryActions button,.ttRecoveryFoot button{border:0;border-radius:8px;padding:9px 13px;font-weight:800;cursor:pointer}.ttRecoveryUpload{background:#2f7148;color:#fff}.ttRecoveryRestore{background:#e2eaf7;color:#193d72}.ttRecoveryDiscard{background:#f3e7e5;color:#98291f}.ttRecoveryFoot{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:14px 22px;border-top:1px solid #d9e1dc;background:#f7f9f7}.ttRecoveryClose{background:#e5ebe7;color:#213d2d}@media(max-width:650px){.ttRecoveryRow{grid-template-columns:1fr}.ttRecoveryFoot{align-items:flex-start;flex-direction:column}}`;document.head.appendChild(style);
}
async function updateNotice(known=null){
  const entries=known||await list();installStyles();let notice=document.getElementById('ttOfflineNotice');
  if(!entries.length){notice?.remove();return entries;}
  if(!notice){notice=document.createElement('button');notice.id='ttOfflineNotice';notice.type='button';notice.onclick=offerRecovery;document.body.appendChild(notice);}
  notice.textContent=`Unsynced entries: ${entries.length}`;notice.setAttribute('aria-label',`${entries.length} unsynced entries. Review and upload.`);
  dispatchEvent(new CustomEvent('tt:offline-count',{detail:{count:entries.length}}));return entries;
}
function entryReference(entry){const parsed=entry?.body?.parsed||{},keys=['contractRef','contract_ref','lotId','lotRef','ref','invoiceNo','poNo','key','entity'];for(const key of keys)if(String(parsed?.[key]??'').trim())return String(parsed[key]).trim();return entityOf(urlOf(entry.url),parsed)||'General update'}
function entryStatus(entry){if(entry.status==='conflict')return'Conflict — newer server information exists';if(entry.status==='processing')return'Original request may still be processing';if(entry.status==='failed')return'Not acknowledged by server';if(entry.status==='offline')return'Saved while server was unavailable';return'Pending upload'}

async function reconcile(entries){
  if(!entries.length||!navigator.onLine)return entries;
  try{
    const ids=entries.map(entry=>entry.id).join(','),response=await nativeFetch('/api/offline-status.php?ids='+encodeURIComponent(ids),{credentials:'same-origin',headers:{Accept:'application/json'}});
    if(!response.ok)return entries;const result=await response.json(),statuses=result?.transactions||{};
    for(const entry of entries){const status=statuses[entry.id]||{};if(status.state==='complete'){await remove(entry.id);continue}if(status.state==='pending'){entry.status='processing';entry.updatedAt=new Date().toISOString();await put(entry)}}
    return list();
  }catch(error){console.error('Saved-entry reconciliation',error);return entries;}
}
function closeRecovery(){document.getElementById('ttRecoveryModal')?.remove();recoveryPromptOpen=false}
async function replay(entry){const response=await nativeFetch(entry.url,{method:entry.method,credentials:'same-origin',headers:requestHeaders(entry.headers,entry),body:restoreBody(entry.body)});await processResponse(entry,response);return{ok:await acknowledged(response),conflict:response.status===409}}
async function uploadEntry(entry,row){const button=row.querySelector('.ttRecoveryUpload');button.disabled=true;button.textContent='Uploading…';try{const result=await replay(entry);if(result.ok){row.remove();const remaining=await updateNotice();if(!remaining.length)closeRecovery();return}entry.status=result.conflict?'conflict':'failed';row.querySelector('.ttRecoveryStatus').textContent=entryStatus(entry);button.textContent='Upload';button.disabled=result.conflict}catch{entry.status='offline';entry.updatedAt=new Date().toISOString();await put(entry);row.querySelector('.ttRecoveryStatus').textContent=entryStatus(entry);button.textContent='Upload';button.disabled=false;await updateNotice()}}
async function discardEntry(entry,row){if(!confirm('Discard this saved entry from this device? It cannot be uploaded afterwards.'))return;await remove(entry.id);row.remove();const remaining=await updateNotice();if(!remaining.length)closeRecovery()}
async function bulkDiscard(entries){if(!confirm(`Discard all ${entries.length} saved entries from this device? They cannot be uploaded afterwards.`))return;await removeMany(entries.map(entry=>entry.id));await updateNotice([]);closeRecovery()}
function showRecovery(entries){
  closeRecovery();if(!entries.length)return;installStyles();recoveryPromptOpen=true;
  const modal=document.createElement('div');modal.id='ttRecoveryModal';modal.className='ttRecoveryBackdrop';modal.innerHTML=`<section class="ttRecoveryDialog" role="dialog" aria-modal="true" aria-labelledby="ttRecoveryTitle"><header class="ttRecoveryHead"><h2 id="ttRecoveryTitle">Upload pending entries?</h2><p>${entries.length} submitted entr${entries.length===1?'y is':'ies are'} safely stored on this device because the server did not acknowledge them. Nothing uploads automatically. Restore the exact workflow, upload after review, or continue working.</p></header><div class="ttRecoveryList"></div><footer class="ttRecoveryFoot"><span>Local copies clear only after confirmed server acknowledgement.</span><span class="ttRecoveryFootActions"><button type="button" class="ttRecoveryDiscard" data-discard-all>Discard all</button><button type="button" class="ttRecoveryClose">Continue without uploading</button></span></footer></section>`;
  const listNode=modal.querySelector('.ttRecoveryList');
  for(const entry of entries){const row=document.createElement('article');row.className='ttRecoveryRow';row.innerHTML=`<div><div class="ttRecoveryTitle">${html(entry.actionLabel||'Saved action')}</div><div class="ttRecoveryMeta">${html(String(entry.module||MODULE).toUpperCase())} · ${html(entryReference(entry))} · ${html(new Date(entry.createdAt).toLocaleString())}</div><div class="ttRecoveryStatus">${html(entryStatus(entry))}</div></div><div class="ttRecoveryActions"><button type="button" class="ttRecoveryRestore">Restore form</button><button type="button" class="ttRecoveryUpload" ${entry.status==='conflict'||entry.status==='processing'?'disabled':''}>Upload</button><button type="button" class="ttRecoveryDiscard">Discard</button></div>`;row.querySelector('.ttRecoveryRestore').onclick=()=>{restoreWorkflow(entry.workflow);closeRecovery()};row.querySelector('.ttRecoveryUpload').onclick=()=>uploadEntry(entry,row);row.querySelector('.ttRecoveryDiscard').onclick=()=>discardEntry(entry,row);listNode.appendChild(row)}
  modal.querySelector('[data-discard-all]').onclick=()=>bulkDiscard(entries);modal.querySelector('.ttRecoveryClose').onclick=closeRecovery;document.body.appendChild(modal);
}
async function offerRecovery(){if(recoveryPromptOpen)return;let entries=await list();entries=await reconcile(entries);await updateNotice(entries);if(entries.length)showRecovery(entries)}

async function prepareEntry(input,init,url,saved,action){
  const key=resourceKey(url,saved.parsed),id=uuid();
  const entry={id,userKey:USER_KEY,module:MODULE,actionId:action.id,actionLabel:action.label,url:url.pathname+url.search,method:methodOf(input,init),headers:[...new Headers(init?.headers||input?.headers||{}).entries()].filter(([key])=>!/^x-tt-/i.test(key)),body:saved,workflow:action.workflow,resourceKey:key,baseResourceVersion:resourceVersions.has(key)?resourceVersions.get(key):null,createdAt:new Date().toISOString(),updatedAt:new Date().toISOString(),status:'pending'};
  await put(entry);await updateNotice();return entry;
}
async function acknowledged(response){if(!response.ok)return false;const type=response.headers.get('content-type')||'';if(!/json/i.test(type))return true;try{const data=await response.clone().json();return data?.ok===true}catch{return false}}
async function processResponse(entry,response){
  const version=response.headers.get('X-TT-Resource-Version');if(version!==null)resourceVersions.set(entry.resourceKey,Number(version));
  if(await acknowledged(response)){await remove(entry.id);await updateNotice();return}
  entry.status=response.status===409?'conflict':'failed';entry.error=response.status===409?'Newer server information exists.':'The server did not confirm this action.';entry.updatedAt=new Date().toISOString();await put(entry);await updateNotice();
}

window.fetch=async function(input,init={}){
  const url=urlOf(input),method=methodOf(input,init);
  if(method==='GET'&&isApi(url)){const response=await nativeFetch(input,init),version=response.headers.get('X-TT-Resource-Version');if(version!==null)resourceVersions.set(resourceKey(url,null),Number(version));return response;}
  if(!isApi(url)||!['POST','PUT','PATCH','DELETE'].includes(method))return nativeFetch(input,init);
  const action=activeAction();
  if(!action){let body=null;if(typeof init?.body==='string'){try{body=JSON.parse(init.body)}catch{}}const response=await nativeFetch(input,init),version=response.headers.get('X-TT-Resource-Version');if(version!==null)resourceVersions.set(resourceKey(url,body),Number(version));return response;}
  const saved=await serializeBody(init?.body??input?.body);if(!saved)return nativeFetch(input,init);
  const entry=await prepareEntry(input,init,url,saved,action),requestInit={...init,headers:requestHeaders(init?.headers??input?.headers,entry)};
  try{const response=await nativeFetch(input,requestInit);await processResponse(entry,response);return response}catch(error){entry.status='offline';entry.error='Server acknowledgement was not received.';entry.updatedAt=new Date().toISOString();await put(entry);await updateNotice();throw error;}
};

async function boot(){
  const restore=sessionStorage.getItem('tt-offline-restore');if(restore){sessionStorage.removeItem('tt-offline-restore');try{restoreWorkflow(JSON.parse(restore))}catch{}}
  const entries=await list();await updateNotice(entries);if(entries.length)await offerRecovery();
  if(window.TT_SHARED_SYNC?.saveNow){const original=window.TT_SHARED_SYNC.saveNow.bind(window.TT_SHARED_SYNC);window.TT_SHARED_SYNC.saveNow=(label='Save')=>{beginAction(typeof label==='string'?label:'Shared final save');return original()};}
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});else boot();
window.TT_OFFLINE_OUTBOX={list,offerRecovery,beginAction,restoreWorkflow};
})();
