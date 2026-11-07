'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),{JSDOM}=require('jsdom');
(async()=>{
 const dom=new JSDOM('<header class="topbar"></header><button id="close" aria-label="Close form">×</button><div id="rows"><button id="remove" data-remove-source>×</button><button id="add">+ Payment Source</button></div><p id="validation" role="alert"></p><label>Amount<input id="amount" required></label>',{runScripts:'outside-only',url:'https://fixture.test'}),w=dom.window;
 w.document.querySelector('.topbar').getBoundingClientRect=()=>({bottom:62});
 // jsdom has no layout; explicitly model the visible validation field. Native visibility is tested separately.
 w.document.querySelector('#validation').getClientRects=()=>[{width:300,height:20}];
 w.fetch=async()=>new Response(JSON.stringify({ok:true}));w.eval(fs.readFileSync('accounts/accounts-notifications.js','utf8'));w.eval(fs.readFileSync('accounts/accounts-row-controls.js','utf8'));
 await new Promise(setImmediate);
 const remove=w.document.querySelector('#remove'),add=w.document.querySelector('#add');let calls=0;remove.onclick=()=>calls++;
 w.TT_ACCOUNTS_ROW_CONTROLS.refresh();assert.equal(remove.textContent,'−');assert(remove.classList.contains('tt-account-minus'));assert.equal(w.document.querySelector('#close').textContent,'×');assert.equal(add.querySelector('.tt-account-plus-sign').textContent,'+');remove.click();assert.equal(calls,1,'formatting preserves handlers and button identity');
 w.document.querySelector('#validation').textContent='Choose a payer before posting.';await new Promise(setImmediate);const popup=w.document.querySelector('#tt-accounts-error');assert.equal(popup.hidden,false);assert.equal(popup.style.top,'74px','Top notice must leave the header and Master button usable');assert.match(popup.textContent,/Choose a payer/);
 popup.querySelector('button').click();assert.equal(popup.hidden,true);w.document.querySelector('#amount').checkValidity();assert.equal(popup.hidden,false);assert.match(popup.textContent,/Amount/);
 const dynamic=w.document.createElement('button');dynamic.dataset.removeRow='';dynamic.textContent='Remove row';w.document.querySelector('#rows').appendChild(dynamic);await new Promise(setImmediate);assert.equal(dynamic.textContent,'−');assert.equal(dynamic.getAttribute('aria-label'),'Remove row');
 w.close();console.log('PASS Accounts consistent controls, preserved handlers, dynamic rows, top inline and required-field errors');
})().catch(error=>{console.error(error);process.exitCode=1;});
