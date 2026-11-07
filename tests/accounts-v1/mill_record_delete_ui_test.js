const fs=require('fs');
const assert=require('assert');
const {JSDOM}=require('jsdom');

(async()=>{
 const dom=new JSDOM('<button id="existing">Existing form</button><section id="production"></section>',{url:'https://fixture.test',runScripts:'outside-only'});
 const w=dom.window,d=w.document,source={id:123,entity:'TTI',date:'2026-10-17',rows:[{product:'B3',bags:10}]};
 let calls=[],refreshes=0;
 w.get=()=>[source];w.renderRecentProduction=()=>refreshes++;w.searchProductionReports=()=>{};
 w.TT_SHARED_SYNC={deleteMillRecord:async intent=>{calls.push(intent);if(calls.length===1)throw Error('Connection interrupted');return {requestKey:intent.requestKey};}};
 w.eval(fs.readFileSync('accounts/mill-record-delete-ui.js','utf8'));
 d.querySelector('#production').innerHTML=w.TT_MILL_DELETE.button('tt30prod',123,'production report');
 const trigger=d.querySelector('[data-mill-delete]');
 assert(trigger.querySelector('svg path'));assert(trigger.classList.contains('tt-row-remove'));assert.equal(trigger.getAttribute('aria-label'),'Delete production report');
 trigger.click();
 const panel=d.querySelector('[data-mill-delete-dialog]');
 const save=panel.querySelector('[data-delete]'),reason=panel.querySelector('textarea');
 await save.onclick();assert.equal(calls.length,0,'A reason is required before submitting');
 reason.value='Duplicate report entered';
 await save.onclick();assert.equal(calls.length,1);assert.equal(refreshes,0);assert.equal(panel.querySelector('[data-error]').textContent,'Connection interrupted');assert.equal(save.textContent,'RETRY DELETE');
 await save.onclick();assert.equal(calls.length,2);assert.equal(calls[0].requestKey,calls[1].requestKey);assert.deepEqual(calls[0].expected,source);assert.equal(refreshes,1);assert(!d.querySelector('[data-mill-delete-dialog]'));
 assert.equal(d.querySelector('#existing').textContent,'Existing form');
 dom.window.close();
 console.log('PASS red circular minus, accessible delete action, required reason, stable retry and refresh only after acknowledgement');
})().catch(error=>{console.error(error);process.exitCode=1;});
