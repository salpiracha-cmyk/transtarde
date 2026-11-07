'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs');
const {JSDOM}=require('jsdom');
const source=fs.readFileSync('accounts/assets-registry-ui.js','utf8');
const tick=()=>new Promise(resolve=>setImmediate(resolve));
async function fixture(capabilities={}){
  const dom=new JSDOM('<button id="openDirectorAssets">Assets</button>',{url:'https://qa.invalid/directors/',runScripts:'outside-only'});
  const w=dom.window,pending=[],writes=[];
  w.TT_DIRECTORS_ACCESS={csrf:'qa'};
  w.HTMLElement.prototype.scrollIntoView=()=>{};
  w.fetch=(url,options={})=>{if(options.method==='POST')writes.push({url,options});return new Promise((resolve,reject)=>pending.push({url,resolve,reject}));};
  w.eval(source);
  const response=(index,entity)=>pending[index].resolve({ok:true,json:async()=>({ok:true,currency:entity==='TG'?'AED':'PKR',assets:[{id:entity,assetTag:entity+'-ASSET',name:entity+' FIXTURE',country:'QA',city:'QA',ownerName:'QA',currency:entity==='TG'?'AED':'PKR',costCents:10000,paidCents:0,balanceCents:10000,schedule:[]}],posts:[],locations:{countries:{},cities:{}},banks:[],...capabilities})});
  w.document.querySelector('#openDirectorAssets').click();response(0,'TTI');await tick();
  return {dom,w,pending,writes,response,q:s=>w.document.querySelector(s),change(entity){const select=w.document.querySelector('#faCompany');select.value=entity;select.dispatchEvent(new w.Event('change'));}};
}
(async()=>{
  // Deliver the requests in reverse order: the latest selected books must win.
  const f=await fixture();f.change('BRM');f.change('TG');
  assert.match(f.pending[1].url,/entity=BRM/);assert.match(f.pending[2].url,/entity=TG/);
  f.response(2,'TG');await tick();f.response(1,'BRM');await tick();
  assert.equal(f.q('#faCompany').value,'TG','Older company response must not reset the selected books');
  assert.match(f.q('.tt-window-body').textContent,/TG-ASSET/,'Displayed assets must belong to selected company');
  assert.doesNotMatch(f.q('.tt-window-body').textContent,/BRM-ASSET/);
  assert.equal(f.writes.length,0);f.dom.window.close();
  // Closing invalidates every pending response, including failures.
  for(const fail of [false,true]){
    const f=await fixture();f.change('BRM');f.q('[data-fa-close]').click();
    if(fail)f.pending[1].reject(Error('Old request failed'));else f.response(1,'BRM');
    await tick();assert.equal(f.q('#ttAssetsWindow').hidden,true,'Closed asset window must stay closed');
    assert.equal(f.q('.tt-window-body').textContent,'','No old private snapshot or error may return after close');
    assert.equal(f.writes.length,0);f.dom.window.close();
  }
  // A failed switch must expose no actionable record from the prior company.
  const f2=await fixture();f2.change('BRM');
  assert.equal(f2.q('[data-fa-view]'),null,'Old-company actions must be removed while loading');
  f2.pending[1].reject(Error('BRM unavailable'));await tick();
  assert.equal(f2.q('[data-fa-view]'),null);assert.match(f2.q('[role=alert]').textContent,/BRM unavailable/);
  assert.equal(f2.writes.length,0);f2.dom.window.close();
  // A saved response arriving after close must not resurrect a private window.
  const f3=await fixture();f3.q('#faVehicle').click();
  f3.q('[name=name]').value='Fixture vehicle';f3.q('[name=registrationNo]').value='QA-123';
  f3.q('form').dispatchEvent(new f3.w.Event('submit',{bubbles:true,cancelable:true}));
  assert.equal(f3.writes.length,1);f3.q('[data-fa-close]').click();
  f3.pending[1].resolve({ok:true,json:async()=>({ok:true,assets:[],posts:[],locations:{countries:{},cities:{}},banks:[],result:{message:'VEHICLE SAVED',postId:'QA-ONLY'}})});
  await tick();assert.equal(f3.q('#ttAssetsWindow').hidden,true,'An acknowledged save must not reopen a closed asset window');
  assert.equal(f3.q('.tt-window-body').textContent,'','No stale confirmation or private data may return after close');f3.dom.window.close();
  const f4=await fixture({canCreate:false,canEdit:false,canDelete:false});
  for(const id of ['faVehicle','faNew','faPay'])assert(f4.q('#'+id).hidden,'View-only users must not see '+id);
  f4.q('#faLocations').click();for(const id of ['faCountryAdd','faCityAdd','faCountryDelete','faCityDelete'])assert(f4.q('#'+id).hidden);
  assert.equal(f4.writes.length,0);f4.dom.window.close();
  console.log('Assets company UI: latest response wins, close stays closed, failed switches clear old actions, zero writes passed');
})().catch(error=>{console.error(error);process.exitCode=1;});
