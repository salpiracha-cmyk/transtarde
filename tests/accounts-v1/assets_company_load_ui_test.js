'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs');
const {JSDOM}=require('jsdom');
const source=fs.readFileSync('accounts/assets-registry-ui.js','utf8');
const tick=()=>new Promise(resolve=>setImmediate(resolve));
async function fixture(){
  const dom=new JSDOM('<button id="openDirectorAssets">Assets</button>',{url:'https://qa.invalid/directors/',runScripts:'outside-only'});
  const w=dom.window,pending=[],writes=[];
  w.TT_DIRECTORS_ACCESS={csrf:'qa'};
  w.HTMLElement.prototype.scrollIntoView=()=>{};
  w.fetch=(url,options={})=>{if(options.method==='POST')writes.push({url,options});return new Promise((resolve,reject)=>pending.push({url,resolve,reject}));};
  w.eval(source);
  const response=(index,entity)=>pending[index].resolve({ok:true,json:async()=>({ok:true,currency:entity==='TG'?'AED':'PKR',assets:[{id:entity,assetTag:entity+'-ASSET',name:entity+' FIXTURE',country:'QA',city:'QA',ownerName:'QA',currency:entity==='TG'?'AED':'PKR',costCents:10000,paidCents:0,balanceCents:10000,schedule:[]}],posts:[],locations:{countries:{},cities:{}},banks:[]})});
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
  console.log('Assets company UI: latest response wins, close stays closed, failed switches clear old actions, zero writes passed');
})().catch(error=>{console.error(error);process.exitCode=1;});
