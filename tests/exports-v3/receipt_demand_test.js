'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
const tick=()=>new Promise(setImmediate);
(async()=>{
 const dom=new JSDOM('<main></main><aside></aside>',{url:'https://fixture.test/exports/',runScripts:'outside-only'}),w=dom.window;
 const calls=[];w.fetch=(url)=>new Promise(resolve=>calls.push({url,resolve}));let broadcasts=0;w.addEventListener('tt:shared-updated',()=>broadcasts++);
 w.eval(fs.readFileSync('exports/tg-accounts-receipts.js','utf8'));const api=w.TT_EXPORT_RECEIPTS,host=w.document.querySelector('main'),aside=w.document.querySelector('aside');
 w.dispatchEvent(new w.Event('focus'));w.document.dispatchEvent(new w.Event('visibilitychange'));assert.equal(calls.length,0,'Opening/returning to Exports must not read receipts');
 let renders=0;const render=()=>{renders++;host.innerHTML='<input value="saved party"><select><option>saved choice</option></select>';};
 assert(api.prepare(host,['TG/A'],render));assert(api.prepare(host,['TG/A'],render));assert(api.prepare(aside,['TG/A'],()=>{}));assert.equal(calls.length,1,'Identical pending receipt reads are shared');assert.deepEqual(new URL(calls[0].url,w.location.href).searchParams.getAll('contractRefs[]'),['TG/A']);
 calls[0].resolve({ok:true,json:async()=>({ok:true,receipts:[{id:'R1',contractRef:'TG/A',amount:125,currency:'USD'}]})});await tick();assert.equal(renders,1);assert.equal(api.rows('TG/A')[0].amount,125);
 const input=host.querySelector('input');input.value='unfinished name';input.focus();input.setSelectionRange(3,7);assert.equal(api.prepare(host,['TG/A'],render),false);w.dispatchEvent(new w.Event('focus'));await tick();assert.equal(calls.length,1);assert.equal(input.value,'unfinished name');assert.equal(w.document.activeElement,input);assert.equal(input.selectionStart,3);assert.equal(host.querySelector('select').value,'saved choice');assert.equal(broadcasts,0);
 // A late response may not replace a different report or update its receipt snapshot.
 api.cancel(host);api.prepare(host,['TG/B'],render);api.cancel(host);host.innerHTML='<input value="other form draft">';calls[1].resolve({ok:true,json:async()=>({ok:true,receipts:[{id:'R2',contractRef:'TG/B',amount:800}]})});await tick();assert.equal(host.querySelector('input').value,'other form draft');assert.equal(api.rows('TG/B').length,0);
 api.prepare(host,['TG/B'],render);calls[2].resolve({ok:false,json:async()=>({ok:false,error:'Temporary receipt failure'})});await tick();assert.match(host.querySelector('[role=alert]').textContent,/Temporary/);host.querySelector('button').click();assert.equal(calls.length,4);calls[3].resolve({ok:true,json:async()=>({ok:true,receipts:[]})});await tick();assert.equal(renders,2);assert.equal(api.rows('TG/B').length,0,'Successful zero differs from failure');
 dom.window.close();console.log('PASS receipt demand, scoped queries, in-flight sharing, draft/selection preservation, stale response isolation and retry');
})().catch(e=>{console.error(e);process.exitCode=1});
