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
 // Exercise the actual shared-save bootstrap, including staged data from other icons.
 const bootstrap=fs.readFileSync('module.php','utf8').match(/<script id="tt-shared-operations-bootstrap">([\s\S]*?)<\/script>/)[1];
 const boot=new JSDOM('<body><main></main></body>',{url:'https://fixture.test/module.php?id=milling',runScripts:'outside-only'}),b=boot.window;
 const snapshot={ok:true,revision:1,values:{tt30prod:JSON.stringify([source]),tt30queue:'[]',tt36bagissues:'[]',tt30prodaudit:'[]',tt32labourfeed:'[]',tt30bags:'[]'},meta:{tt30prod:{version:1},tt30queue:{version:1}}};
 b.TT_MODULE_ACCESS={userId:7,moduleId:'milling',module:'Mill',csrf:'fixture'};
 b.XMLHttpRequest=class{open(){}send(){this.status=200;this.responseText=JSON.stringify(snapshot);}};
 let requests=[],release;
 b.fetch=async(url,options)=>{
  requests.push({url,body:JSON.parse(options.body)});
  if(release)await new Promise(resolve=>release(resolve));
  return {ok:true,json:async()=>({ok:true,receipt:{requestKey:requests.at(-1).body.requestKey,collection:'tt30prod',sourceId:'123'},values:{tt30prod:'[]'},meta:{tt30prod:{version:2}}})};
 };
 b.eval(bootstrap);
 const intent={collection:'tt30prod',sourceId:'123',requestKey:'fixture-delete-123',reason:'Duplicate report entered',expected:source};
 const draft='[{"id":"unrelated-shipment-draft"}]';b.localStorage.setItem('tt30ship',draft);
 await b.TT_SHARED_SYNC.deleteMillRecord(intent);
 assert.equal(requests.length,1);assert(requests[0].url.endsWith('/mill_record_delete.php'));
 assert.equal(b.localStorage.getItem('tt30ship'),draft,'Deletion discarded another icon draft');
 assert.equal(b.TT_SHARED_SYNC.readCommitted('tt30prod'),'[]');
 // A real pending edit to the affected source must still block the request.
 b.localStorage.setItem('tt30prod',JSON.stringify([source]));
 await assert.rejects(b.TT_SHARED_SYNC.deleteMillRecord(intent),/unfinished change/);assert.equal(requests.length,1);
 // Reverting that edit to the confirmed source is not an unfinished save.
 b.localStorage.setItem('tt30prod','[]');
 // Bag orders are unrelated to production deletion's returned collections.
 b.localStorage.setItem('tt30bags','[{"id":"other-bag-order-draft"}]');
 let resume;release=resolve=>{resume=resolve;};
 const saving=b.TT_SHARED_SYNC.deleteMillRecord(intent);
 await assert.rejects(b.TT_SHARED_SYNC.deleteMillRecord(intent),/unfinished change/);assert.equal(requests.length,2);
 release=null;resume();await saving;
 assert.equal(b.localStorage.getItem('tt30bags'),'[{"id":"other-bag-order-draft"}]');
 boot.window.close();
 console.log('PASS red circular minus, accessible delete action, required reason, stable retry and refresh only after acknowledgement');
 console.log('PASS actual bootstrap isolates deletion from unrelated drafts, rejects affected pending/in-flight changes and accepts reverted edits');
})().catch(error=>{console.error(error);process.exitCode=1;});
