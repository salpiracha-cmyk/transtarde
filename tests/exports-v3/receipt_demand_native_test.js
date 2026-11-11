'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
const tick=()=>new Promise(setImmediate);
(async()=>{
 const dom=new JSDOM('<div id="app"></div><div id="printRoot"></div>',{url:'https://fixture.test/exports/',runScripts:'outside-only',pretendToBeVisual:true}),w=dom.window;
 w.structuredClone=structuredClone;w.scrollTo=()=>{};w.alert=e=>{throw Error(e)};w.confirm=()=>true;w.print=()=>{};w.TT_MODULE_ACCESS={moduleId:'exports',module:'Exports',role:'Super Admin',user:'Fixture',masters:{},csrf:'fixture'};
 const reads=[];w.fetch=async url=>{if(String(url).includes('tg_export_accounts_receipts'))return new Promise(resolve=>reads.push({url,resolve}));return{ok:true,json:async()=>({ok:true,masters:{}})}};
 // Reuse the established native contract/lot fixture and exercise production renderers.
 const focus=fs.readFileSync('tests/exports-v3/focus_preservation_browser_qa.js','utf8');let hook=focus.match(/const hook = `([\s\S]*?)`;\nconst app/)[1];
 hook=hook.replace(' fixture:()=>{',` setTG:()=>{for(const c of state.contracts)c.seller='TG';for(const s of state.shipments)s.seller='TG';const lot=state.shipments.find(s=>s.id==='L-FOCUS');lot.commercial.notifyParty='Saved custom consignee';},
 queue:()=>saveShipmentFolder(state.shipments.find(s=>s.id==='L-FOCUS'),state.contracts.find(c=>c.ref==='TTI/FOCUS/01')),
 committed:()=>JSON.stringify(state),
 completed:()=>{const lot=state.shipments.find(s=>s.id==='L-FOCUS');lot.completed=true;lot.status='Completed';},
 fixture:()=>{`);
 w.eval(fs.readFileSync('exports/tg-accounts-receipts.js','utf8'));w.eval(fs.readFileSync('exports/app.js','utf8').replace('mount();restoreContractCheckpoint();',hook));
 const qa=w.__focusQA,q=s=>w.document.querySelector(s),deliver=async(amount=100)=>{reads.at(-1).resolve({ok:true,json:async()=>({ok:true,receipts:[{id:'TG-R1',contractRef:'TTI/FOCUS/01',receiptType:'CUSTOMER_ADVANCE',amount,currency:'USD',date:'2026-11-11',status:'Posted'}]})});await tick();await tick();};
 qa.fixture();qa.setTG();qa.home();assert.equal(reads.length,0);qa.open('L-FOCUS');assert.equal(reads.length,0,'Shipment menu needs no receipts');qa.open('L-FOCUS','bl');assert.equal(reads.length,0,'BL form needs no receipts');
 qa.open('L-FOCUS','commercial');assert.equal(reads.length,1);assert(!q('#coNotify'),'Receipt-dependent form waits before presenting balances');await deliver();assert(q('#coNotify'));assert.equal(q('#coNotify').value,'__editable__');assert.equal(q('#coDraweeCustom').value,'Saved custom consignee');assert.match(q('#workspaceDetail').textContent,/100\.00/);
 const field=q('#coDraweeCustom');field.value='Unfinished customer wording';field.focus();field.setSelectionRange(4,9);w.dispatchEvent(new w.Event('focus'));w.document.dispatchEvent(new w.Event('visibilitychange'));await tick();assert.equal(reads.length,1);assert.equal(field.value,'Unfinished customer wording');assert.equal(w.document.activeElement,field);assert.equal(field.selectionStart,4);
 q('[data-commercial-tab="packing"]').click();assert(q('#packingConsignee'));assert.equal(reads.length,1,'Packing tab reuses the loaded view');
 qa.home();qa.setView('reports');assert.equal(reads.length,1);q('[data-report="contracts"]').click();assert.equal(reads.length,1);q('[data-report="invoice"]').click();assert.equal(reads.length,2);q('[data-report="containers"]').click();await deliver();assert.match(q('#reportDetail').textContent,/Container/);assert(!q('#reportDetail').textContent.includes('ReceivedBalance'),'Late invoice read must not replace selected report');
 let packed;w.TT_SHARED_SYNC={readCommitted:()=>qa.committed()};w.TT_SHIPMENT_FILES={save:async options=>{packed=options.rows.find(row=>row.key==='commercial').render();return {count:1};}};
 const queued=qa.queue();assert.equal(reads.length,3,'Explicit folder action loads its own receipt snapshot');await deliver(222);await queued;assert.match(packed,/222\.00/);assert.equal(w.TT_EXPORT_RECEIPTS.rows('TTI/FOCUS/01')[0].amount,100,'Pack reads must not update the open form receipt snapshot');
 qa.completed();qa.open('L-FOCUS');assert.equal(reads.length,4);await deliver();assert.equal(reads.length,4,'Completed render must not loop/reload');assert.match(q('#workspaceDetail').textContent,/FINAL SHIPMENT DOCUMENTS/);assert(q('#workspaceDetail [data-tt-completed-reprint]'));assert(!q('#completeLot'));
 dom.window.close();console.log('PASS native Exports home/BL/invoice/packing/report/completed flows, saved custom choices, pending drafts and completed reprint controls');
})().catch(e=>{console.error(e);process.exitCode=1});
