'use strict';
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {chromium} = require('playwright');
const root = path.resolve(__dirname, '../..');
const source = fs.readFileSync(path.join(root, 'exports/app.js'), 'utf8');
const wrapper = fs.readFileSync(path.join(root, 'module.php'), 'utf8');
const bootstrap = wrapper.match(/<script id="tt-shared-operations-bootstrap">([\s\S]*?)<\/script>/)?.[1];
assert.ok(bootstrap, 'test must use the production shared-data bootstrap');
const STORE = 'transtrade_export_v3_operational';
const hook = `window.__focusQA={
 getState:()=>state,setState:data=>{state=data},contractByRef,unitRate,customsInvoiceValue,gdRefsFingerprint,load,openShipment,reopenCompletedLot,
 snapshot:()=>({view,currentShipmentId,activeWorkspace,state:structuredClone(state)}),
 open:(id,key='')=>{currentShipmentId=id;view='shipments';activeWorkspace=key;renderShipmentWorkspace()},
 home:()=>{contractDraft=null;activeWorkspace='';view='home';render()},
 setView:value=>{view=value;render()},
 openFI:openFIModal,openContract,save,
 fixture:()=>{
  state=seed();
  const buyer={id:'C-FOCUS',name:'Focus QA Buyer',code:'FOCUS',address:'Dubai',country:'UAE',packingDefault:'KG',nextSeq:1};
  state.customers.push(buyer);
  const c={id:'CT-FOCUS',ref:'TTI/FOCUS/01',seller:'TTI',customerId:buyer.id,date:'2026-09-21',product:'IRRI-6 White Rice',variety:'IRRI-6',riceType:'White Rice',hsCode:'1006.30',broken:5,brokenText:'5%',finish:'Well milled; silky polished; well sortexed',cropYear:'2026/2027',quality:DEFAULT_QUALITY,specMode:'Contract Specific',specRows:[],containers:2,weightPer:27,qty:54,tolerance:5,shipmentDate:'2026-10-01',pol:'Port Qasim, Pakistan',podPort:'Jebel Ali',podCountry:'United Arab Emirates',packingUnit:'KG',currency:'USD',incoterm:'CFR',inspection:'None',insurance:"Buyer's Account",paymentCode:'CAD100',docs:defaultDocsFor({incoterm:'CFR'}),terms:[],signedDeadline:'2026-09-23',paymentDeadline:'2026-09-25',issued:true,received:true,status:'Contract Received',packings:[{type:'P.P. Bags',size:25,brand:'FOCUS',tare:80,containers:2,weightPer:27,price:410,freight:20,extraBagPct:0,masterBag:{enabled:false,qty:0,tare:0}}]};
  state.contracts.push(c);
  const p=makeShipment(c);p.id='P-FOCUS';state.shipments.push(p);
  const lot=makeLotRecord(p,{lotId:'TTI/FOCUS/01/L01',physicalContainers:1,allocations:[{packIndex:0,name:'TTI Rice Mills',type:'TTI',containers:1,weightPer:27}]});
  lot.id='L-FOCUS';lot.millActuals=[{number:'MSCU1234567',seal:'QA001',bags:1080,netKg:27000,tareKg:86.4,grossKg:27086.4,brand:'FOCUS',packing:'25 KG',location:'TTI Rice Mills'}];
  lot.bl.vessel='MV QA';lot.bl.voyage='QA1';state.shipments.push(lot);save();
 }
};mount();restoreContractCheckpoint();`;
const app = source.replace('mount();restoreContractCheckpoint();', hook);
assert.notEqual(app, source, 'production app test hook was installed');
const results=[];
let browser;
async function pageFor(moduleId='exports', withApp=true, remote=null) {
 const page=await browser.newPage({viewport:{width:1400,height:900}});
 await page.route('http://127.0.0.1:41739/**',r=>r.fulfill({contentType:'text/html',body:'<!doctype html><html><head><style>body{margin:0}#main{min-height:2200px}.lotEditorShell{padding:20px}input,select,textarea{display:block;margin:5px}textarea{height:80px}.hidden{display:none}</style></head><body><div id="app"></div><div id="printRoot"></div></body></html>'}));
 await page.setContent('<!doctype html><html><head><style>body{margin:0}#main{min-height:2200px}.lotEditorShell{padding:20px}input,select,textarea{display:block;margin:5px}textarea{height:80px}.hidden{display:none}</style></head><body><div id="app"></div><div id="printRoot"></div></body></html>');
 await page.evaluate(({moduleId,STORE,remote})=>{
  class TestStorage{constructor(){this.values=new Map()}getItem(k){return this.values.get(String(k))??null}setItem(k,v){if(this.quota){const e=new Error('full');e.name='QuotaExceededError';throw e}this.values.set(String(k),String(v))}removeItem(k){this.values.delete(String(k))}clear(){this.values.clear()}key(i){return [...this.values.keys()][i]??null}get length(){return this.values.size}}
  window.Storage=TestStorage;Object.defineProperty(window,'localStorage',{value:new TestStorage()});Object.defineProperty(window,'sessionStorage',{value:new TestStorage()});
  window.TT_MODULE_ACCESS={moduleId,module:moduleId==='exports'?'Exports':'Milling',user:'Isolated Focus QA',masters:{},csrf:'TEST-ONLY'};
  window.alerts=[];window.alert=m=>alerts.push(String(m));window.confirm=()=>true;window.print=()=>{};
  window.server=remote||{ok:true,revision:1,values:{},meta:{}};
  window.network={gets:0,posts:[],held:[],hold:false};
  window.XMLHttpRequest=class {
   open(method,url,async=true){this.async=async}
   send(){this.status=200;this.responseText=JSON.stringify(server);if(this.async){network.gets++;const deliver=()=>this.onload?.();if(network.hold)network.held.push(deliver);else setTimeout(deliver,0)}}
  };
  window.fetch=async(url,options={})=>{
   if(options.method==='POST'){
    const body=JSON.parse(options.body);network.posts.push(body);
    const version=Number(server.meta[body.key]?.version||0);
    if(body.baseVersion!==version)return{ok:false,json:async()=>({ok:false,conflict:true,error:'Version conflict'})};
    server.revision++;server.values[body.key]=body.value;server.meta[body.key]={version:version+1,updatedBy:'Isolated Focus QA'};
    return{ok:true,json:async()=>({ok:true,revision:server.revision,keyVersion:version+1})};
   }
   return{ok:true,json:async()=>structuredClone(server)};
  };
  window.remoteChange=(key,mutate)=>{let value=JSON.parse(server.values[key]||'null');value=mutate(value);server.values[key]=JSON.stringify(value);server.meta[key]={version:Number(server.meta[key]?.version||0)+1,updatedBy:'Other test session'};server.revision++};
  window.focusReturn=()=>{window.dispatchEvent(new Event('focus'));document.dispatchEvent(new Event('visibilitychange'))};
 },{moduleId,STORE,remote});
 await page.addScriptTag({content:bootstrap});
 if(withApp){await page.addScriptTag({content:app});if(!remote)await page.evaluate(async()=>{__focusQA.fixture();await TT_SHARED_SYNC.saveNow();TT_SHARED_SYNC.bridge();await TT_SHARED_SYNC.saveNow();network.posts=[];network.gets=0})}
 return page;
}
async function check(name,fn){await fn();results.push(name);console.log('PASS '+name)}

async function ready(page,quota=false){
 await page.evaluate(async({STORE,quota})=>{
  TT_MODULE_ACCESS.super=true;TT_MODULE_ACCESS.role='Super Admin';
  const s=__focusQA.getState().shipments.find(s=>s.id==='L-FOCUS'),c=__focusQA.contractByRef(s.contractRef);
  c.docs=[];s.createdAt='2025-01-01T00:00:00Z';s.customs.saved=true;s.customs.invoiceNo='CUSTOMS-OLD';s.customs.rate=__focusQA.unitRate(c.packings[0],c);s.customs.invoiceValue=__focusQA.customsInvoiceValue(s,c);s.customs.openAccount=s.customs.invoiceValue;
  s.customs.gdRefs=[{number:'GD-QA',date:'2026-09-21'}];s.customs.gdDocument={name:'GD.pdf',gdRefsFingerprint:__focusQA.gdRefsFingerprint(s.customs.gdRefs)};
  s.bl.finalized=true;s.bl.finalDocument={name:'BL.pdf'};s.bl.blNo='BL-QA';s.bl.onBoardDate='2026-09-21';s.bl.description='BL AUTHORITATIVE DESCRIPTION';s.customs.description='CUSTOMS ONLY DESCRIPTION';
  s.commercial.saved=true;s.commercial.status='Final';s.commercial.invoiceNo='TTI/FOCUS/01';s.commercial.date='2026-09-21';
  window.archives=[];window.TT_SHIPMENT_FILES={access:async()=>true,save:async options=>{if(window.archiveFail)throw new Error('Queue unavailable');archives.push({optional:options.optional,createdAt:options.committedSnapshot.shipments.find(s=>s.id==='L-FOCUS').createdAt,gdRefs:options.committedSnapshot.shipments.find(s=>s.id==='L-FOCUS').customs.gdRefs,description:options.committedSnapshot.shipments.find(s=>s.id==='L-FOCUS').bl.description,rows:options.rows.map(r=>({name:r.name,ready:r.ready,folder:r.folder}))});if(window.archiveHold)await new Promise(resolve=>window.releaseArchive=resolve);return{count:options.rows.length,path:'QA Buyer/SHIPMENT #01/LOT #01',status:'PENDING'}}};
  if(quota){const native=Storage.prototype.setItem;window.nativeWriter=native;Storage.prototype.setItem=function(k,v){return native.call(this,k,v)};}
  __focusQA.save();await TT_SHARED_SYNC.saveNow();network.posts=[];
  if(quota){ // The production bridge catches the native quota exception, not this wrapper.
   localStorage.quota=true;
  }
  window.testFetch=window.fetch;window.fetch=async(url,options)=>{
   if(options?.method==='POST'){const body=JSON.parse(options.body),root=body.key===STORE?JSON.parse(body.value):null;
    if(root?.shipments?.find(s=>s.id==='L-FOCUS')?.reopenedAt&&!root?.shipments?.find(s=>s.id==='L-FOCUS')?.completed&&window.rejectReopening)return{ok:false,json:async()=>({ok:false,error:'Reopening denied'})};
    if(root?.shipments?.find(s=>s.id==='L-FOCUS')?.completed&&window.rejectCompletion)return{ok:false,json:async()=>({ok:false,error:'Completion denied'})};
    const response=await testFetch(url,options);
    if(window.incompleteCanonical&&root?.shipments){const canonical=structuredClone(root);canonical.shipments.find(s=>s.id==='L-FOCUS').millActuals=[];server.values[STORE]=JSON.stringify(canonical);return{ok:true,json:async()=>({...await response.json(),value:server.values[STORE]})}}
    if(root?.shipments?.find(s=>s.id==='L-FOCUS')?.completed&&window.holdCompletion)await new Promise(resolve=>window.releaseCompletion=resolve);return response;
   }return testFetch(url,options);
  };
  window.prompt=()=>window.reason??'QA correction';
 },{STORE,quota});
}
(async()=>{
 browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{})});
 try{
 await check('LOT COMPLETE stays active until hosted acknowledgement; office interval is independent and parent stays active for remaining quantity',async()=>{
  const p=await pageFor();await ready(p,true);await p.addScriptTag({content:fs.readFileSync(path.join(root,'exports/office-agent-shipment-hooks.js'),'utf8')});await p.evaluate(()=>{archiveHold=true;holdCompletion=true;__focusQA.open('L-FOCUS','output')});
  await p.locator('#completeLot').click();await p.waitForFunction(()=>typeof releaseArchive==='function');await p.evaluate(()=>{window.originalEditor=document.getElementById('workspaceDetail');focusReturn();dispatchEvent(new CustomEvent('tt:shared-updated'))});assert.equal(await p.evaluate(()=>originalEditor===document.getElementById('workspaceDetail')),true);await p.evaluate(()=>{archiveHold=false;releaseArchive()});await p.waitForFunction(()=>typeof releaseCompletion==='function');
  assert.equal(await p.evaluate(()=>__focusQA.snapshot().view),'shipments');
  assert.equal(await p.evaluate(()=>document.documentElement.classList.contains('tt-save-waiting')),true);
  await p.evaluate(()=>{holdCompletion=false;releaseCompletion()});await p.waitForFunction(()=>__focusQA.snapshot().view==='home');
  const result=await p.evaluate(STORE=>({live:__focusQA.snapshot().state,server:JSON.parse(server.values[STORE]),archives}),STORE);
  assert.equal(result.server.shipments.find(s=>s.id==='L-FOCUS').completed,true);assert.equal(result.live.shipments.find(s=>s.id==='L-FOCUS').completed,true);assert.equal(result.live.shipments.find(s=>s.id==='P-FOCUS').completed,false);assert.equal(result.archives[0].description,'BL AUTHORITATIVE DESCRIPTION');
  const persisted=await p.evaluate(()=>structuredClone(server));const fresh=await pageFor('exports',true,persisted);assert.equal(await fresh.evaluate(()=>__focusQA.getState().shipments.find(s=>s.id==='L-FOCUS').completed),true);await fresh.close();
  await p.evaluate(()=>{__focusQA.setState(__focusQA.load());__focusQA.home()});assert.equal(await p.evaluate(()=>__focusQA.getState().shipments.find(s=>s.id==='L-FOCUS').completed),true);
  const before=await p.evaluate(()=>network.posts.length);await p.evaluate(()=>__focusQA.openShipment('L-FOCUS'));assert.equal(await p.locator('input,textarea,select,#cancelLot,[data-final-upload]').count(),0);assert.equal(await p.evaluate(()=>network.posts.length),before);
  await p.evaluate(async()=>{reason='';await __focusQA.reopenCompletedLot('L-FOCUS')});assert.equal(await p.evaluate(()=>network.posts.length),before);
  await p.evaluate(async()=>{reason='QA correction';rejectReopening=true;await __focusQA.reopenCompletedLot('L-FOCUS')});assert.equal(await p.evaluate(STORE=>JSON.parse(server.values[STORE]).shipments.find(s=>s.id==='L-FOCUS').completed,STORE),true);assert.equal(await p.evaluate(()=>__focusQA.getState().shipments.find(s=>s.id==='L-FOCUS').completed),true);
  await p.evaluate(async()=>{rejectReopening=false;reason='QA correction';await __focusQA.reopenCompletedLot('L-FOCUS')});assert.equal(await p.evaluate(STORE=>JSON.parse(server.values[STORE]).shipments.find(s=>s.id==='L-FOCUS').completed,STORE),false);
  assert.equal(await p.evaluate(STORE=>JSON.parse(server.values[STORE]).shipments.find(s=>s.id==='L-FOCUS').reopenHistory.at(-1).reason,STORE),'QA correction');await p.close();
 });
 await check('Incomplete canonical server actuals block closure despite complete browser cache',async()=>{
  const p=await pageFor();await ready(p);await p.evaluate(()=>{incompleteCanonical=true;__focusQA.open('L-FOCUS','output')});await p.locator('#completeLot').click();await p.waitForFunction(()=>document.querySelector('#shipmentFolderMessage .notice.warn')?.textContent.includes('server shipment is incomplete'));assert.equal(await p.evaluate(()=>archives.length),0);assert.equal(await p.evaluate(STORE=>!!JSON.parse(server.values[STORE]).shipments.find(s=>s.id==='L-FOCUS').completed,STORE),false);await p.close();
 });
 for(const fault of ['rejectCompletion','archiveFail'])await check(fault+' preserves open server lot without posting automatic rollback',async()=>{
  const p=await pageFor();await ready(p);await p.evaluate(fault=>{window[fault]=true;__focusQA.open('L-FOCUS','output')},fault);await p.locator('#completeLot').click();await p.waitForFunction(()=>alerts.some(s=>s.includes('not confirmed'))||document.querySelector('#shipmentFolderMessage .notice.warn'));
  assert.equal(await p.evaluate(STORE=>!!JSON.parse(server.values[STORE]).shipments.find(s=>s.id==='L-FOCUS').completed,STORE),false);
  const count=await p.evaluate(()=>network.posts.length);await p.evaluate(()=>TT_SHARED_SYNC.saveNow());assert.equal(await p.evaluate(()=>network.posts.length),count);
  await p.evaluate(fault=>window[fault]=false,fault);await p.locator('#completeLot').click();await p.waitForFunction(()=>__focusQA.snapshot().view==='home');assert.equal(await p.evaluate(STORE=>JSON.parse(server.values[STORE]).shipments.find(s=>s.id==='L-FOCUS').completed,STORE),true);await p.close();
 });
 await check('Existing old lot Customs and active BL saves queue partial committed packages; GD survives Customs save',async()=>{
  const p=await pageFor();await ready(p);await p.evaluate(()=>{__focusQA.getState().shipments.find(s=>s.id==='L-FOCUS').commercial.status='Draft';__focusQA.open('L-FOCUS','customs')});await p.locator('#saveCustoms').click();await p.waitForFunction(()=>archives.length>0);
  assert.equal(await p.evaluate(()=>archives[0].optional),true);assert.equal(await p.evaluate(()=>archives[0].createdAt),'2025-01-01T00:00:00Z');assert.equal(await p.evaluate(()=>archives[0].gdRefs[0].number),'GD-QA');
  await p.evaluate(()=>__focusQA.open('L-FOCUS','bl'));await p.locator('#blDesc').fill('UPDATED BL DESCRIPTION');await p.locator('#saveBL').click();await p.waitForFunction(()=>archives.length>1);assert.equal(await p.evaluate(()=>archives.at(-1).description),'UPDATED BL DESCRIPTION');await p.close();
 });
 await check('Staff completed VIEW never exposes REOPEN; competing status writer and reopening scripts are absent',async()=>{
  const p=await pageFor();await ready(p);await p.evaluate(()=>{TT_MODULE_ACCESS.super=false;const lot=__focusQA.getState().shipments.find(s=>s.id==='L-FOCUS');lot.completed=true;lot.status='Completed';__focusQA.setView('completed')});assert.equal(await p.locator('[data-reopen-lot]').count(),0);const before=await p.evaluate(()=>network.posts.length);await p.evaluate(()=>__focusQA.reopenCompletedLot('L-FOCUS'));assert.equal(await p.evaluate(()=>network.posts.length),before);await p.close();
  assert.doesNotMatch(fs.readFileSync(path.join(root,'exports/index.html'),'utf8'),/reopen-completed-lots\.js/);assert.doesNotMatch(fs.readFileSync(path.join(root,'exports/office-agent-shipment-hooks.js'),'utf8'),/repairCompletedLots/);
 });
 await check('Active Shipments hides a fully covered completed lot even if parent completed flag is stale',async()=>{
  const p=await pageFor();await ready(p);await p.evaluate(()=>{const state=__focusQA.getState(),lot=state.shipments.find(s=>s.id==='L-FOCUS'),process=state.shipments.find(s=>s.id==='P-FOCUS'),contract=state.contracts.find(c=>c.ref==='TTI/FOCUS/01');lot.completed=true;lot.status='Completed';process.completed=false;process.status='Lot Created';process.plannedQty=27;contract.qty=27;contract.status='Contract Received';__focusQA.home()});
  assert.equal(await p.locator('[data-search-card*="tti/focus/01"]').count(),0);
  assert.match(await p.locator('#activeCards').innerText(),/No active contracts or shipments/);
  await p.close();
 });
 await check('Persisted hostile Customs dates remain data and never create executable DOM',async()=>{
  const p=await pageFor();await ready(p);await p.evaluate(()=>{const lot=__focusQA.getState().shipments.find(s=>s.id==='L-FOCUS');lot.customs.date='\"><img id="injected-date" src=x onerror="window.dateInjected=true">';__focusQA.open('L-FOCUS','customs')});
  assert.equal(await p.locator('#injected-date').count(),0);
  assert.equal(await p.evaluate(()=>!!window.dateInjected),false);
  assert.equal(await p.locator('#cuDate').count(),1);
  await p.close();
 });
 console.log('PASS '+results.length+' completion and progressive archive browser scenarios');
 }finally{await browser?.close()}
})().catch(error=>{console.error(error);process.exitCode=1});
