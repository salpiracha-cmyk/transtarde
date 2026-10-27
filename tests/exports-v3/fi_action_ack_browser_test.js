'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),{chromium}=require('playwright');
const source=fs.readFileSync('exports/app.js','utf8');
const bootstrap=fs.readFileSync('module.php','utf8').match(/<script id="tt-shared-operations-bootstrap">([\s\S]*?)<\/script>/)[1];
const fixtureSource=fs.readFileSync('tests/exports-v3/focus_preservation_browser_qa.js','utf8');
const hook=fixtureSource.match(/const hook = `([\s\S]*?)`;\nconst app/)[1].replace('openFI:openFIModal,openContract,save,','getState:()=>state,load,reportTable,closeModal,openFI:openFIModal,openFIEdit,openFITransfer,openFIAllocation,removeFIAllocation,cancelContract,openContract,save,');
const app=source.replace('mount();restoreContractCheckpoint();',hook),STORE='transtrade_export_v3_operational';
(async()=>{
 const browser=await chromium.launch({headless:true});
 try{
  for(const action of ['create','edit','transfer','allocate','reverse','cancel','cancel-lot'])for(const fault of ['delayed','rejected','lost-ack']){
   const page=await browser.newPage();page.on('pageerror',e=>console.error('PAGE',e.message));await page.route('http://exports.test/**',r=>r.fulfill({contentType:'text/html',body:'<div id="app"></div><div id="printRoot"></div>'}));await page.goto('http://exports.test/');
   await page.evaluate(()=>{
    window.TT_MODULE_ACCESS={moduleId:'exports',module:'Exports',user:'Fixture',role:'Super Admin',super:true,masters:{},csrf:'fixture'};
    window.alerts=[];window.alert=m=>alerts.push(m);window.confirm=()=>true;window.prompt=()=>'Fixture correction';
    window.server={ok:true,revision:1,values:{},meta:{}};window.posts=[];window.fault='';
    window.XMLHttpRequest=class{open(){}send(){this.status=200;this.responseText=JSON.stringify(server)}};
    window.fetch=async(url,o={})=>{
     if(o.method!=='POST')return{ok:true,json:async()=>server};
     const body=JSON.parse(o.body);posts.push(body);
     if(fault==='rejected')return{ok:false,json:async()=>({ok:false,error:'Fixture permission rejection'})};
     server.values[body.key]=body.value;server.meta[body.key]={version:(server.meta[body.key]?.version||0)+1};server.revision++;
     if(fault==='lost-ack')throw Error('Disconnected after commit');
     if(fault==='delayed')await new Promise(resolve=>window.releaseSave=resolve);
     return{ok:true,json:async()=>({ok:true,revision:server.revision,keyVersion:server.meta[body.key].version})};
    };
   });
   await page.addScriptTag({content:bootstrap});await page.addScriptTag({content:app});
   await page.evaluate(async()=>{
    __focusQA.fixture();const root=__focusQA.getState();root.fi=[{id:'F1',number:'FI-1',customer:'Focus QA Buyer',exporter:'TTI',currency:'USD',value:1000,date:'2026-10-01',bank:'Bank',allocations:[]},{id:'F2',number:'FI-2',customer:'Focus QA Buyer',exporter:'TTI',currency:'USD',value:500,date:'2026-10-01',bank:'Bank',allocations:[]}];
    root.shipments.find(x=>x.id==='L-FOCUS').buyer='Focus QA Buyer';__focusQA.save();await TT_SHARED_SYNC.saveNow();posts=[];
   });
   const button=await page.evaluate(async({action,fault,STORE})=>{
    const q=id=>document.getElementById(id),root=__focusQA.getState();
    if(action==='reverse'){root.fi[0].allocations=[{id:'A1',lotId:'L-FOCUS',contractRef:'TTI/FOCUS/01',lotRef:'L01',amount:100}];root.shipments.find(x=>x.id==='L-FOCUS').customs.fiAllocations=[{fiId:'F1',allocationId:'A1',amount:100}];}
    if(action==='cancel')root.shipments=root.shipments.filter(x=>x.kind!=='lot');
    __focusQA.save();await TT_SHARED_SYNC.saveNow();window.before=localStorage.getItem(STORE);posts=[];window.fault=fault;
    if(action==='create'){window.callbackCount=0;__focusQA.openFI(()=>callbackCount++);q('mFICustomer').value='Focus QA Buyer';q('mFIBank').add(new Option('Bank','Bank'));q('mFIBank').value='Bank';q('mFICur').value='USD';q('mFIValue').value='250';return 'saveFI'}
    if(action==='edit'){__focusQA.openFIEdit('F1');q('efReason').value='Fixture correction';q('efValue').value='1200';return 'saveFIEdit'}
    if(action==='transfer'){__focusQA.openFITransfer('F1');q('tfAmount').value='100';q('tfReason').value='Fixture correction';return 'saveTransfer'}
    if(action==='allocate'){__focusQA.openFIAllocation('F1');q('faAmount').value='100';return 'saveFIAllocation'}
    if(action==='reverse'){__focusQA.setView('fi');return document.querySelector('[data-fi-remove]').id='reverseFixture'}
    if(action==='cancel-lot'){__focusQA.open('L-FOCUS');return 'cancelLot'}
    __focusQA.setView('contracts');return document.querySelector('[data-cancel-contract]').id='cancelFixture';
   },{action,fault,STORE});
   await page.evaluate(()=>window.before=JSON.stringify(__focusQA.getState()));
   await page.locator('#'+button).click();
   if(fault==='delayed'){
    await page.waitForFunction(()=>typeof releaseSave==='function');assert.equal(await page.locator('#'+button).count(),1,'Action stays in place before acknowledgement');assert.equal(await page.locator('#'+button).isDisabled(),true);
    await page.evaluate(()=>releaseSave());await page.waitForFunction(id=>!document.getElementById(id),button);
   }else{
    await page.waitForFunction(()=>document.getElementById('exportSaveError')?.textContent.includes('Reload and review'));
    assert.equal(await page.locator('#'+button).isDisabled(),true,'Uncertain actions cannot be replayed blindly');
    assert.equal(await page.evaluate(()=>posts.length),1,'No automatic retry or rollback POST');
    assert.equal(await page.evaluate(STORE=>localStorage.getItem(STORE)===before,STORE),true,'Only local staged state is restored');
    if(action==='edit')assert.equal(await page.locator('#efReason').inputValue(),'Fixture correction','Entered reason survives failure');
   }
   const result=await page.evaluate(STORE=>JSON.parse(server.values[STORE]),STORE);
   if(fault!=='rejected'){
    if(action==='transfer'){assert.equal(result.fi[0].value,900);assert.equal(result.fi[1].value,600);assert.equal(result.fi[0].transfers.length,1)}
    if(action==='allocate')assert.equal(result.fi[0].allocations.length,1);
    if(action==='create')assert.equal(result.fi.length,3);
    if(action==='edit')assert.equal(result.fi[0].value,1200);
    if(action==='reverse')assert.equal(result.fi[0].allocations.length,0);
    if(action==='cancel-lot'){
     assert.equal(result.shipments.find(x=>x.id==='L-FOCUS').cancelled,true);
     if(fault==='delayed'){
      const after=await page.evaluate(()=>{const restored=__focusQA.load();return {retained:restored.shipments.some(x=>x.id==='L-FOCUS'&&x.cancelled),cancelledReport:__focusQA.reportTable('cancelled'),activeReport:__focusQA.reportTable('pendingShipments')}});
      assert.equal(after.retained,true);assert.match(after.cancelledReport,/TTI\/FOCUS\/01\/L01/);assert.doesNotMatch(after.activeReport,/TTI\/FOCUS\/01\/L01/);
      await page.evaluate(()=>__focusQA.open('L-FOCUS'));assert.equal(await page.locator('main input,main select,main textarea,#cancelLot,#completeLot,[data-final-upload]').count(),0,'Cancelled lot is read-only');
     }
    }
    if(action==='cancel'){assert.equal(result.contracts.length,0);assert.equal(result.cancelledContracts[0].reason,'Fixture correction')}
   }
   if(action==='create')assert.equal(await page.evaluate(()=>callbackCount),fault==='delayed'?1:0,'Linked Customs flow resumes only after acknowledgement');
   if(fault!=='delayed'){
    await page.evaluate(()=>{__focusQA.closeModal();__focusQA.openFITransfer('F1');document.getElementById('tfAmount').value='100';document.getElementById('tfReason').value='Second action without review';});
    await page.locator('#saveTransfer').click();assert.equal(await page.evaluate(()=>posts.length),1,'Reopening a different form cannot bypass uncertain-save review');
   }
   assert.deepEqual(await page.evaluate(()=>alerts),[]);await page.close();console.log('PASS '+action+' '+fault);
  }
 }finally{await browser.close()}
})().catch(e=>{console.error(e);process.exitCode=1});
