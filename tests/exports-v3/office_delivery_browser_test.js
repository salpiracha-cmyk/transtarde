'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),{chromium}=require('playwright');
const source=fs.readFileSync('exports/app.js','utf8');
const bootstrap=fs.readFileSync('module.php','utf8').match(/<script id="tt-shared-operations-bootstrap">([\s\S]*?)<\/script>/)[1];
const hook=`window.__deliveryQA={render:renderOfficeDocumentRefresh,save,root:()=>state,fixture:()=>{
 state=seed();state.contracts=[{id:'C1',ref:'TTI/QA/01',seller:'TTI'}];
 state.shipments=[{id:'P1',kind:'process',contractRef:'TTI/QA/01'},
 {id:'L1',kind:'lot',contractRef:'TTI/QA/01',lotId:'TTI/QA/01/L01',completed:true,status:'Completed'},
 {id:'L2',kind:'lot',contractRef:'TTI/QA/01',lotId:'TTI/QA/01/L02'},
 {id:'L3',kind:'lot',contractRef:'TTI/QA/01',lotId:'TTI/QA/01/L03',cancelled:true}];save();
},failPreparation:()=>saveShipmentFolder=async()=>{throw Error('Fixture missing original')}};mount();restoreContractCheckpoint();`;
const app=source.replace('mount();restoreContractCheckpoint();',hook);
assert.notEqual(app,source);
(async()=>{
 const browser=await chromium.launch({headless:true});
 try{
  const page=await browser.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.route('http://exports.test/**',r=>r.fulfill({contentType:'text/html',body:'<div id="app"></div><div id="printRoot"></div>'}));await page.goto('http://exports.test/');
  await page.evaluate(()=>{
   window.TT_MODULE_ACCESS={moduleId:'exports',module:'Exports',user:'Fixture',role:'Super Admin',super:true,masters:{},csrf:'fixture'};
   window.server={ok:true,revision:1,values:{},meta:{}};window.posts=[];window.reads=[];window.replies={};
   window.XMLHttpRequest=class{open(){}send(){this.status=200;this.responseText=JSON.stringify(server)}};
   window.fetch=async(url,options={})=>{
    if(options.method==='POST'){const body=JSON.parse(options.body);posts.push(body);server.values[body.key]=body.value;server.meta[body.key]={version:(server.meta[body.key]?.version||0)+1};return{ok:true,json:async()=>({ok:true,revision:++server.revision,keyVersion:server.meta[body.key].version})}}
    if(String(url).includes('shipment-status')){reads.push(String(url));if(window.holdCheck)await new Promise(resolve=>window.releaseCheck=resolve);const lot=new URL(url,location.href).searchParams.get('lot');const reply=replies[lot]||{ok:true,found:false};if(reply.throw)throw Error(reply.throw);return{ok:reply.ok,json:async()=>reply}}
    return{ok:true,json:async()=>server};
   };
  });
  await page.addScriptTag({content:bootstrap});await page.addScriptTag({content:app});
  await page.evaluate(async()=>{
   __deliveryQA.fixture();await TT_SHARED_SYNC.saveNow();posts=[];
   replies['TTI/QA/01/L01']={ok:true,found:true,job:{id:'j1',status:'VERIFIED',receiptVerified:true,fileCount:7,verifiedFileCount:7}};
   replies['TTI/QA/01/L02']={ok:true,found:true,job:{id:'j2',status:'PENDING',receiptVerified:false}};
   window.before=JSON.stringify(server);window.beforeRoot=JSON.stringify(__deliveryQA.root());
   __deliveryQA.render();
  });
  const check=page.locator('#checkOfficeRefresh');
  await check.click();await page.waitForFunction(()=>document.getElementById('officeRefreshProgress').textContent.includes('finished'));
  assert.equal(await page.locator('#officeRefreshResults tr').count(),2);
  assert.match(await page.locator('#officeRefreshResults').innerText(),/7 of 7 files/);
  assert.match(await page.locator('#officeRefreshResults').innerText(),/Queued — awaiting office PC/);
  assert.match(await page.locator('#officeRefreshProgress').innerText(),/1 of 2 latest packages verified/);
  assert.deepEqual(await page.evaluate(()=>reads.map(x=>new URL(x,location.href).searchParams.get('lot'))),['TTI/QA/01/L01','TTI/QA/01/L02']);
  assert.equal(await page.evaluate(()=>JSON.stringify(server)===before&&JSON.stringify(__deliveryQA.root())===beforeRoot&&posts.length===0),true,'Checks must not save, queue, post or change a business record');
  console.log('PASS fresh session checks saved active/completed lots; excludes process/cancelled rows; zero writes');

  // A new browser session has no in-memory job list. Unsaved state is not an office-copy source.
  await page.evaluate(()=>{delete window.TT_OFFICE_REFRESH_RESULTS;__deliveryQA.root().shipments.push({kind:'lot',contractRef:'TTI/QA/01',lotId:'UNSAVED'});replies['TTI/QA/01/L01']={ok:true,found:false};replies['TTI/QA/01/L02']={ok:true,found:true,job:{id:'new-job',status:'UNVERIFIED',receiptVerified:false}};reads=[];__deliveryQA.render()});
  await check.click();await page.waitForFunction(()=>document.getElementById('officeRefreshProgress').textContent.includes('finished'));
  assert.equal(await page.locator('#officeRefreshResults tr').count(),2);
  assert.match(await page.locator('#officeRefreshResults').innerText(),/No office package queued/);
  assert.match(await page.locator('#officeRefreshResults').innerText(),/file verification incomplete/);
  assert.doesNotMatch(await page.locator('#officeRefreshResults').innerText(),/UNSAVED|Latest package verified/);
  assert.equal(await page.evaluate(()=>reads.every(x=>!new URL(x,location.href).searchParams.has('id'))),true,'Query the latest saved package, not a remembered older job');

  await page.evaluate(()=>{replies['TTI/QA/01/L01']={ok:false,error:'No access to this shipment'};replies['TTI/QA/01/L02']={ok:true,found:true,job:{id:'new-job',status:'COPIED_LAYOUT_PENDING',receiptVerified:false}};reads=[];holdCheck=true});
  await check.click();await page.waitForFunction(()=>typeof releaseCheck==='function');
  assert.equal(await check.isDisabled(),true);assert.equal(await page.locator('#startOfficeRefresh').isDisabled(),true);
  await page.evaluate(()=>{holdCheck=false;releaseCheck()});await page.waitForFunction(()=>!document.getElementById('checkOfficeRefresh').disabled);
  assert.equal(await page.evaluate(()=>reads.length),2,'A denied row does not suppress the next shipment');
  assert.match(await page.locator('#officeRefreshResults').innerText(),/Unable to check delivery: No access/);
  assert.match(await page.locator('#officeRefreshResults').innerText(),/PC agent update required/);
  assert.match(await page.locator('#officeRefreshProgress').innerText(),/1 delivery checks failed/);
  console.log('PASS reopen, committed-only sources, latest-job lookup, partial denial and concurrent action lock');

  await page.evaluate(()=>{replies['TTI/QA/01/L01']={throw:'Connection lost'};replies['TTI/QA/01/L02']={ok:true,found:true,job:{id:'j2',status:'ERROR',receiptVerified:false,lastError:'Destination unavailable'}}});
  await check.click();await page.waitForFunction(()=>!document.getElementById('checkOfficeRefresh').disabled);
  assert.match(await page.locator('#officeRefreshResults').innerText(),/Connection lost/);assert.match(await page.locator('#officeRefreshResults').innerText(),/Destination unavailable/);
  assert.equal(await page.locator('#startOfficeRefresh').isDisabled(),false);

  await page.evaluate(()=>{__deliveryQA.failPreparation();replies['TTI/QA/01/L01']={ok:true,found:true,job:{id:'j1',status:'VERIFIED',receiptVerified:true,fileCount:7,verifiedFileCount:7}}});
  await page.locator('#startOfficeRefresh').click();await page.waitForFunction(()=>!document.getElementById('startOfficeRefresh').disabled);
  await check.click();await page.waitForFunction(()=>!document.getElementById('checkOfficeRefresh').disabled);
  assert.match(await page.locator('#officeRefreshResults').innerText(),/Latest refresh failed: Fixture missing original/,'A previous delivered package must not hide a later preparation failure');
  await page.evaluate(()=>{TT_OFFICE_REFRESH_RESULTS[0].warnings=['Original needs attention'];TT_OFFICE_REFRESH_RESULTS[0].preparationError=''});
  await check.click();await page.waitForFunction(()=>!document.getElementById('checkOfficeRefresh').disabled);
  assert.match(await page.locator('#officeRefreshResults').innerText(),/Original needs attention/);
  await page.evaluate(()=>{replies['TTI/QA/01/L01'].job.id='newer'});
  await check.click();await page.waitForFunction(()=>!document.getElementById('checkOfficeRefresh').disabled);
  assert.doesNotMatch(await page.locator('#officeRefreshResults').innerText(),/Original needs attention/,'Warnings are bound to the package they described');
  assert.equal(await page.evaluate(()=>posts.length),0);assert.deepEqual(errors,[]);
  await page.evaluate(async()=>{__deliveryQA.root().shipments=[];__deliveryQA.save();await TT_SHARED_SYNC.saveNow();posts=[];reads=[];__deliveryQA.render()});
  await check.click();assert.equal(await page.locator('#officeRefreshProgress').innerText(),'No saved shipment lots to check.');
  assert.equal(await page.evaluate(()=>reads.length+posts.length),0);
  console.log('PASS connection errors, package-specific warnings, preparation failures and explicit empty state');
 }finally{await browser.close()}
})().catch(error=>{console.error(error);process.exitCode=1});
