'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
(async()=>{
 const dom=new JSDOM('<body><input id="draft" value="unchanged"></body>',{url:'https://fixture.test',runScripts:'outside-only'}),w=dom.window;
 w.HTMLDialogElement.prototype.showModal=function(){this.open=true};w.HTMLDialogElement.prototype.close=function(){this.open=false};
 w.eval(fs.readFileSync('accounts/all-ledgers-ui.js','utf8').replace('  window.TT_ALL_LEDGERS=', '  window.reasonForTest=cancellationReason; window.TT_ALL_LEDGERS='));
 let result=w.reasonForTest('POST-2026-00490'),dialog=w.document.querySelector('dialog');assert.equal(dialog.getAttribute('aria-label'),'Cancel posting');assert(dialog.textContent.includes('POST-2026-00490'));dialog.querySelector('[data-back]').click();assert.equal(await result,null);assert.equal(w.document.querySelector('dialog'),null);
 result=w.reasonForTest('POST-2026-00490');dialog=w.document.querySelector('dialog');dialog.querySelector('textarea').value='Duplicate opening; retain original';dialog.querySelector('form').dispatchEvent(new w.Event('submit',{cancelable:true}));assert.equal(await result,'Duplicate opening; retain original');assert.equal(w.document.querySelector('#draft').value,'unchanged');
 result=w.reasonForTest('POST-2026-00490');w.document.querySelector('dialog').dispatchEvent(new w.Event('cancel',{cancelable:true}));assert.equal(await result,null);w.close();console.log('PASS cancellation reason: explicit review, keep/escape cancel, original Post ID and preserved draft');
})().catch(e=>{console.error(e);process.exitCode=1});
