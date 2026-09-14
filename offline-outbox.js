(()=>{
'use strict';

const access=window.TT_MODULE_ACCESS||window.TT_ACCOUNT_ACCESS||{};
const DB_NAME='transtrade-offline-outbox-v2', STORE='pending';
const MODULE=access.moduleId||access.module||'module';
const USER_KEY=[MODULE,access.user||'user'].join('|');
const nativeFetch=window.fetch.bind(window);
const resourceVersions=new Map();
let explicitAction=null,recoveryRunning=false,recoveryPromptOpen=false;

const uuid=()=>globalThis.crypto?.randomUUID?.()||'tt-'+Date.now().toString(36)+'-'+Math.random().toString(36).slice(2);
const methodOf=(input,init)=>String(init?.method||input?.method||'GET').toUpperCase();
const urlOf=input=>new URL(typeof input==='string'?input:String(input?.url||''),location.href);
const isApi=url=>url.origin===location.origin&&/\/api\/[A-Za-z0-9_.-]+\.php$/.test(url.pathname);
const entityOf=(url,body)=>String(url.searchParams.get('entity')||body?.entity||'').toUpperCase();
const resourceKey=(url,body)=>url.pathname+'|'+entityOf(url,body);
const finalWords=/\b(next|save|confirm|issue|post|create|complete|send|receive|dispatch|approve|verify|delete)\b/i;
const visible=el=>{const s=getComputedStyle(el);return s.display!=='none'&&s.visibility!=='hidden'&&el.getClientRects().length>0};

function beginAction(label){explicitAction={id:uuid(),label:String(label||'Final action'),startedAt:new Date().toISOString()}}
function actionLabel(el){return String(el?.getAttribute?.('aria-label')||el?.title||el?.textContent||el?.value||'').replace(/\s+/g,' ').trim()}
document.addEventListener('click',event=>{const el=event.target.closest('button,input[type="submit"],[role="button"]');if(!el)return;const label=actionLabel(el);if(finalWords.test(label))beginAction(label);else explicitAction=null},true);
document.addEventListener('submit',event=>beginAction(actionLabel(event.submitter)||'Submit'),true);
document.addEventListener('input',()=>{explicitAction=null},true);

const captureUi=()=>({
  href:location.pathname+location.search+location.hash,
  scrollX,scrollY,
  focus:document.activeElement?.id||document.activeElement?.getAttribute?.('name')||'',
  visibleIds:[...document.querySelectorAll('section[id],.panel[id],.workspace[id],.view[id],[data-view][id]')].filter(visible).map(x=>x.id).slice(0,20),
  fields:[...document.querySelectorAll('input,select,textarea')].filter(x=>visible(x)&&x.type!=='password'&&x.type!=='file').map(x=>({id:x.id||'',name:x.name||'',type:x.type||x.tagName.toLowerCase(),value:x.value,checked:!!x.checked})).slice(0,400)
});
const openDb=()=>new Promise((resolve,reject)=>{const req=indexedDB.open(DB_NAME,1);req.onupgradeneeded=()=>{const db=req.result;if(!db.objectStoreNames.contains(STORE)){const s=db.createObjectStore(STORE,{keyPath:'id'});s.createIndex('userKey','userKey',{unique:false})}};req.onsuccess=()=>resolve(req.result);req.onerror=()=>reject(req.error)});
const transact=async(mode,fn)=>{const db=await openDb();return new Promise((resolve,reject)=>{const tx=db.transaction(STORE,mode),store=tx.objectStore(STORE);let result;try{result=fn(store)}catch(e){db.close();reject(e);return}tx.oncomplete=()=>{db.close();resolve(result)};tx.onerror=()=>{db.close();reject(tx.error)};tx.onabort=()=>{db.close();reject(tx.error||new Error('Offline recovery storage was interrupted.'))}})};
const list=async()=>{const db=await openDb();return new Promise((resolve,reject)=>{const tx=db.transaction(STORE,'readonly'),req=tx.objectStore(STORE).index('userKey').getAll(USER_KEY);req.onsuccess=()=>resolve((req.result||[]).sort((a,b)=>String(a.createdAt).localeCompare(String(b.createdAt))));req.onerror=()=>reject(req.error);tx.oncomplete=()=>db.close()})};
const put=entry=>transact('readwrite',s=>s.put(entry));
const remove=id=>transact('readwrite',s=>s.delete(id));

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
  const entries=knownEntries||await list(),count=entries.length,conflicts=entries.filter(x=>x.status==='conflict').length;
  let notice=document.getElementById('ttOfflineNotice');
  if(!count){notice?.remove();return}
  if(!notice){
    notice=document.createElement('button');
    notice.id='ttOfflineNotice';
    notice.type='button';
    notice.setAttribute('aria-live','polite');
    notice.style.cssText='position:fixed;right:12px;top:98px;z-index:100000;border:0;border-radius:10px;padding:9px 12px;background:#9a6000;color:#fff;font:700 11px Arial;box-shadow:0 5px 18px #0003;cursor:pointer';
    notice.addEventListener('click',()=>offerRecovery({restore:true}));
    document.body?.appendChild(notice);
  }
  notice.style.background=conflicts?'#a93a34':navigator.onLine?'#9a6000':'#6c4a00';
  notice.textContent=count+' offline / unsynced entr'+(count===1?'y':'ies')+(conflicts?' · '+conflicts+' conflict'+(conflicts===1?'':'s'):'');
  notice.title=navigator.onLine?'Review and upload pending entries':'Entries are safe on this device until connectivity returns';
}

async function prepareEntry(input,init,url,saved,action){
  const key=resourceKey(url,saved.parsed),id=uuid();
  const entry={id,userKey:USER_KEY,module:MODULE,actionId:action?.id||id,actionLabel:action?.label||'Final action',url:url.pathname+url.search,method:methodOf(input,init),headers:[...new Headers(init?.headers||input?.headers||{}).entries()].filter(([k])=>!/^x-tt-/i.test(k)),body:saved,resourceKey:key,baseResourceVersion:resourceVersions.has(key)?resourceVersions.get(key):null,createdAt:new Date().toISOString(),updatedAt:new Date().toISOString(),ui:captureUi(),status:'pending'};
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

function reopenWorkflow(ui){for(const id of ui?.visibleIds||[]){const short=id.replace(/^ws-/,'');if(typeof window.openPanel==='function'&&document.getElementById(id)?.matches('section.panel')){try{window.openPanel(id)}catch{}}const opener=document.querySelector(`[data-workspace="${CSS.escape(short)}"],[data-target="${CSS.escape(id)}"],[href="#${CSS.escape(id)}"]`);opener?.click?.()}}
function restoreUi(ui){return new Promise(resolve=>{if(!ui){resolve();return}reopenWorkflow(ui);requestAnimationFrame(()=>requestAnimationFrame(()=>{for(const f of ui.fields||[]){const selector=f.id?'#'+CSS.escape(f.id):f.name?'[name="'+CSS.escape(f.name)+'"]':null,el=selector?document.querySelector(selector):null;if(!el||el.type==='password'||el.type==='file')continue;if(f.type==='checkbox'||f.type==='radio')el.checked=!!f.checked;else el.value=f.value??'';el.dispatchEvent(new Event('input',{bubbles:true}));el.dispatchEvent(new Event('change',{bubbles:true}))}scrollTo(Number(ui.scrollX||0),Number(ui.scrollY||0));if(ui.focus){const el=document.getElementById(ui.focus)||document.querySelector('[name="'+CSS.escape(ui.focus)+'"]');el?.focus?.()}resolve()}))})}
async function replay(entry){const init={method:entry.method,credentials:'same-origin',headers:headersWith(entry.headers,entry),body:restoreBody(entry.body)};const response=await nativeFetch(entry.url,init);await processResponse(entry,response);return{ok:await acknowledged(response),conflict:response.status===409,status:response.status}}
async function offerRecovery({restore=false}={}){
  if(recoveryRunning||recoveryPromptOpen)return;
  const entries=await list();
  await updateNotice(entries);
  if(!entries.length)return;
  if(restore)await restoreUi(entries[0]?.ui);
  if(!navigator.onLine){alert(entries.length+' offline / unsynced entr'+(entries.length===1?'y is':'ies are')+' safe on this device. Reconnect, then choose the unsynced notice to upload.');return}
  recoveryPromptOpen=true;
  const approved=confirm(entries.length+' offline / unsynced entr'+(entries.length===1?'y is':'ies are')+' saved on this device. Upload pending '+(entries.length===1?'entry':'entries')+' and continue?');
  recoveryPromptOpen=false;
  if(!approved)return;
  recoveryRunning=true;
  try{
    for(const entry of entries){
      if(entry.status==='conflict'){alert('Upload stopped: newer server data exists. The local entry was not overwritten or removed.');break}
      if(!navigator.onLine){alert('Connectivity was lost again. Remaining entries are still safe on this device.');break}
      try{
        const result=await replay(entry);
        if(result.conflict){alert('Upload stopped: newer server data exists. The local entry was not overwritten or removed.');break}
        if(!result.ok){alert('The server did not acknowledge this entry. It remains unsynced on this device.');break}
      }catch{alert('The server could not be reached. Remaining entries are still safe on this device.');break}
    }
  }catch(error){console.error('Offline recovery',error)}
  finally{recoveryRunning=false;await updateNotice()}
}
const recoverPending=()=>offerRecovery({restore:true});
async function boot(){
  const entries=await list();
  await updateNotice(entries);
  if(entries.length)await offerRecovery({restore:true});
  addEventListener('online',()=>offerRecovery({restore:true}));
  if(window.TT_SHARED_SYNC?.saveNow){
    const original=window.TT_SHARED_SYNC.saveNow.bind(window.TT_SHARED_SYNC);
    window.TT_SHARED_SYNC.saveNow=(...args)=>{beginAction('Shared final save');return original(...args)}
  }
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});else boot();
window.TT_OFFLINE_OUTBOX={list,recoverPending,offerRecovery,beginAction};
})();
