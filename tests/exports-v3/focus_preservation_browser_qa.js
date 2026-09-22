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
async function pageFor(moduleId='exports', withApp=true) {
 const page=await browser.newPage({viewport:{width:1400,height:900}});
 await page.route('http://127.0.0.1:41739/**',r=>r.fulfill({contentType:'text/html',body:'<!doctype html><html><head><style>body{margin:0}#main{min-height:2200px}.lotEditorShell{padding:20px}input,select,textarea{display:block;margin:5px}textarea{height:80px}.hidden{display:none}</style></head><body><div id="app"></div><div id="printRoot"></div></body></html>'}));
 await page.setContent('<!doctype html><html><head><style>body{margin:0}#main{min-height:2200px}.lotEditorShell{padding:20px}input,select,textarea{display:block;margin:5px}textarea{height:80px}.hidden{display:none}</style></head><body><div id="app"></div><div id="printRoot"></div></body></html>');
 await page.evaluate(({moduleId,STORE})=>{
  class TestStorage{constructor(){this.values=new Map()}getItem(k){return this.values.get(String(k))??null}setItem(k,v){this.values.set(String(k),String(v))}removeItem(k){this.values.delete(String(k))}clear(){this.values.clear()}key(i){return [...this.values.keys()][i]??null}get length(){return this.values.size}}
  window.Storage=TestStorage;Object.defineProperty(window,'localStorage',{value:new TestStorage()});Object.defineProperty(window,'sessionStorage',{value:new TestStorage()});
  window.TT_MODULE_ACCESS={moduleId,module:moduleId==='exports'?'Exports':'Milling',user:'Isolated Focus QA',masters:{},csrf:'TEST-ONLY'};
  window.alerts=[];window.alert=m=>alerts.push(String(m));window.confirm=()=>true;window.print=()=>{};
  window.server={ok:true,revision:1,values:{},meta:{}};
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
 },{moduleId,STORE});
 await page.addScriptTag({content:bootstrap});
 if(withApp){await page.addScriptTag({content:app});await page.evaluate(async()=>{__focusQA.fixture();await TT_SHARED_SYNC.saveNow();TT_SHARED_SYNC.bridge();await TT_SHARED_SYNC.saveNow();network.posts=[];network.gets=0})}
 return page;
}
async function check(name,fn){await fn();results.push(name);console.log('PASS '+name)}
(async()=>{
 browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{})});
 try{
  const page=await pageFor();
  await check('Customs unsaved text, live node, cursor, scroll and state survive repeated focus/visibility events without posting',async()=>{
   await page.evaluate(()=>__focusQA.open('L-FOCUS','customs'));
   await page.locator('#cuNo').fill('UNSAVED-CUSTOMS-987');
   await page.evaluate(()=>{window.savedNode=document.getElementById('cuNo');savedNode.focus();savedNode.setSelectionRange(3,7);window.scrollTo(0,180);window.savedScroll=scrollY;window.savedState=__focusQA.snapshot().state;for(let i=0;i<4;i++)focusReturn();dispatchEvent(new CustomEvent('tt:shared-updated'))});
   await page.waitForTimeout(250);
   const out=await page.evaluate(()=>({same:savedNode===document.getElementById('cuNo'),text:savedNode.value,selection:[savedNode.selectionStart,savedNode.selectionEnd],scroll:scrollY===savedScroll,state:JSON.stringify(__focusQA.snapshot().state)===JSON.stringify(savedState),posts:network.posts.length,gets:network.gets,route:__focusQA.snapshot()}));
   assert.equal(out.same,true);assert.equal(out.text,'UNSAVED-CUSTOMS-987');assert.deepEqual(out.selection,[3,7]);assert.equal(out.scroll,true);assert.equal(out.state,true);assert.equal(out.posts,0);assert.equal(out.gets,0);assert.equal(out.route.activeWorkspace,'customs');assert.equal(out.route.currentShipmentId,'L-FOCUS');
  });
  await check('Explicit Customs save still persists entered number and returns to this lot',async()=>{
   await page.locator('#saveCustoms').click();await page.evaluate(()=>TT_SHARED_SYNC.saveNow());
   const out=await page.evaluate(STORE=>({saved:JSON.parse(server.values[STORE]).shipments.find(s=>s.id==='L-FOCUS').customs.invoiceNo,posts:network.posts.filter(p=>p.key===STORE).length,route:__focusQA.snapshot()}),STORE);
   assert.equal(out.saved,'UNSAVED-CUSTOMS-987');assert.ok(out.posts>0);assert.equal(out.route.currentShipmentId,'L-FOCUS');assert.equal(out.route.activeWorkspace,'');
  });
  for(const key of ['bl','commercial','coo','cover','uploads'])await check(key+' editor survives an incoming shared notification',async()=>{
   await page.evaluate(key=>{__focusQA.open('L-FOCUS',key);window.editor=document.getElementById('workspaceDetail');window.before=JSON.stringify(__focusQA.snapshot().state);dispatchEvent(new CustomEvent('tt:shared-updated'));focusReturn()},key);
   assert.equal(await page.evaluate(()=>editor===document.getElementById('workspaceDetail')&&before===JSON.stringify(__focusQA.snapshot().state)),true);
   assert.equal(await page.evaluate(()=>__focusQA.snapshot().activeWorkspace),key);
  });
  await check('Selected upload file is not discarded by a tab return',async()=>{
   const input=page.locator('#workspaceDetail input[type=file]').first();assert.equal(await input.count(),1);
   await input.setInputFiles({name:'focus-test.txt',mimeType:'text/plain',buffer:Buffer.from('isolated QA only')});
   await page.evaluate(()=>{dispatchEvent(new CustomEvent('tt:shared-updated'));focusReturn()});
   assert.equal(await input.evaluate(e=>e.files[0]?.name),'focus-test.txt');
  });
  for(const key of ['bags','loading'])await check(key+' process form survives a tab return',async()=>{
   await page.evaluate(key=>{__focusQA.open('P-FOCUS',key);window.editor=document.getElementById('workspaceDetail');dispatchEvent(new CustomEvent('tt:shared-updated'));focusReturn()},key);
   assert.equal(await page.evaluate(()=>editor===document.getElementById('workspaceDetail')),true);
  });
  await check('Contract editor and FI modal retain their original nodes',async()=>{
   await page.evaluate(()=>{__focusQA.home();__focusQA.openContract('CT-FOCUS');window.contractNode=document.getElementById('contractBody');dispatchEvent(new CustomEvent('tt:shared-updated'));focusReturn()});
   assert.equal(await page.evaluate(()=>contractNode===document.getElementById('contractBody')),true);
   await page.evaluate(()=>{__focusQA.home();__focusQA.openFI();window.modal=document.querySelector('[data-modal]');dispatchEvent(new CustomEvent('tt:shared-updated'));focusReturn()});
   assert.equal(await page.evaluate(()=>modal===document.querySelector('[data-modal]')),true);
   await page.locator('[data-modal-close]').first().click();
  });
  // Isolate inbound polling from the prior editor tests and their throttle/retry timers.
  const overview=await pageFor();
  await check('Incoming Exports change refreshes lot overview without returning home',async()=>{
   await overview.evaluate(STORE=>{__focusQA.open('L-FOCUS');remoteChange(STORE,v=>{v.shipments.find(s=>s.id==='L-FOCUS').buyer='UPDATED BUYER';return v});focusReturn()},STORE);
   await overview.waitForFunction(()=>__focusQA.snapshot().state.shipments.find(s=>s.id==='L-FOCUS').buyer==='UPDATED BUYER');
   assert.equal(await overview.evaluate(()=>__focusQA.snapshot().view),'shipments');assert.equal(await overview.evaluate(()=>__focusQA.snapshot().currentShipmentId),'L-FOCUS');
  });
  await overview.close();
  await check('Home search query, filter and cursor survive refreshed data',async()=>{
   await page.evaluate(()=>__focusQA.home());await page.locator('#homeSearch').fill('NO-MATCH');
   await page.evaluate(()=>{homeSearch.focus();homeSearch.setSelectionRange(2,4);dispatchEvent(new CustomEvent('tt:shared-updated'))});
   assert.deepEqual(await page.locator('#homeSearch').evaluate(e=>[e.value,e.selectionStart,e.selectionEnd]),['NO-MATCH',2,4]);
   assert.equal(await page.locator('[data-search-card]').first().evaluate(e=>e.hidden),true);
  });
  await check('Completed list and an open report remain on their selected screen',async()=>{
   await page.evaluate(()=>{__focusQA.setView('completed');dispatchEvent(new CustomEvent('tt:shared-updated'))});
   assert.equal(await page.locator('#main h2').innerText(),'Completed Shipments');
   await page.evaluate(()=>__focusQA.setView('reports'));await page.locator('[data-report]').first().click();
   await page.evaluate(()=>{window.report=document.getElementById('reportDetail');dispatchEvent(new CustomEvent('tt:shared-updated'));focusReturn()});
   assert.equal(await page.evaluate(()=>report===document.getElementById('reportDetail')),true);
  });
  await page.close();
  const race=await pageFor();
  await check('Late network response cannot replace a newly opened editor or its storage snapshot',async()=>{
   await race.evaluate(STORE=>{__focusQA.open('L-FOCUS');network.hold=true;window.oldValue=localStorage.getItem(STORE);window.oldVersion=server.meta[STORE].version;remoteChange(STORE,v=>{v.shipments.find(s=>s.id==='L-FOCUS').customs.invoiceNo='OTHER-USER';return v});TT_SHARED_SYNC.poll()},STORE);
   await race.waitForFunction(()=>network.held.length>0);
   await race.evaluate(()=>{__focusQA.open('L-FOCUS','customs');window.editor=document.getElementById('workspaceDetail');network.held.splice(0).forEach(deliver=>deliver());network.hold=false});
   assert.equal(await race.evaluate(STORE=>localStorage.getItem(STORE)===oldValue,STORE),true);assert.equal(await race.evaluate(()=>editor===document.getElementById('workspaceDetail')),true);
  });
  await check('Deferred response does not advance write version or silently overwrite another user',async()=>{
   const outcome=await race.evaluate(async STORE=>{__focusQA.save();try{await TT_SHARED_SYNC.saveNow();return{unexpected:true}}catch(e){return{message:e.message,base:network.posts.filter(p=>p.key===STORE).at(-1).baseVersion,old:oldVersion,value:JSON.parse(server.values[STORE]).shipments.find(s=>s.id==='L-FOCUS').customs.invoiceNo}}},STORE);
   assert.equal(outcome.unexpected,undefined);assert.match(outcome.message,/changed elsewhere/);assert.equal(outcome.base,outcome.old);assert.equal(outcome.value,'OTHER-USER');
  });
  await race.close();
  const deferred=await pageFor();
  await check('Deferred shared and Milling data automatically resumes after closing the editor',async()=>{
   await deferred.evaluate(STORE=>{__focusQA.open('L-FOCUS','customs');remoteChange(STORE,v=>{v.shipments.find(s=>s.id==='L-FOCUS').buyer='DEFERRED BUYER';return v});remoteChange('tt30arrival',()=>[{id:123,station:'QA station'}]);focusReturn()},STORE);
   await deferred.waitForTimeout(1100);assert.equal(await deferred.evaluate(()=>network.gets),0);
   await deferred.locator('#closeLotEditor').click();
   await deferred.waitForFunction(()=>__focusQA.snapshot().state.shipments.find(s=>s.id==='L-FOCUS').buyer==='DEFERRED BUYER');
   assert.equal(await deferred.evaluate(()=>JSON.parse(localStorage.getItem('tt30arrival'))[0].station),'QA station');
   assert.equal(await deferred.evaluate(()=>__focusQA.snapshot().currentShipmentId),'L-FOCUS');
  });
  await deferred.close();
  const other=await pageFor('milling',false);
  await check('Exports protection does not pause another module',async()=>{
   await other.evaluate(()=>{window.TT_EXPORT_WORKSPACE_BUSY=()=>true;remoteChange('tt30arrival',()=>[{id:999,station:'Other module'}]);TT_SHARED_SYNC.poll()});
   await other.waitForFunction(()=>localStorage.getItem('tt30arrival')?.includes('Other module'));
  });await other.close();
  console.log(JSON.stringify({ok:true,passed:results.length,tests:results},null,2));
 }finally{await browser.close()}
})().catch(error=>{console.error(error);process.exitCode=1});
