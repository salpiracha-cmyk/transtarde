 'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),{JSDOM}=require('jsdom');
(async()=>{
 const dom=new JSDOM('<div id="purchaseEditor" data-tt-purchase-mode="bags"><table class="ttbag-table"><tbody><tr>'+Array.from({length:10},(_,i)=>'<td>'+({2:'INV1',3:'PO1'}[i]||'')+'</td>').join('')+'</tr></tbody></table></div>',{url:'https://fixture.test/',runScripts:'outside-only'}),w=dom.window;
 w.localStorage.setItem('tt_accounts_entity','TTI');w.TT_ACCOUNT_ACCESS={super:true};let requests=0;w.fetch=()=>{requests++;throw Error('Review must reuse loaded bill data');};w.eval(fs.readFileSync('accounts/bag-bill-review-ui.js','utf8'));
 const root=w.document.querySelector('#purchaseEditor'),bills=[{id:'B1',sellerInvoice:'INV1',poNo:'PO1',status:'Rate Approval Required'}];
 const render=company=>w.document.dispatchEvent(new w.CustomEvent('tt:bag-bills-rendered',{detail:{root,company,bills}}));
 render('BRM');assert.equal(root.querySelectorAll('button').length,0,'Late other-company data must be ignored');render('TTI');assert.equal(root.querySelectorAll('button').length,1);render('TTI');assert.equal(root.querySelectorAll('button').length,1,'Re-enhancement must not duplicate approval controls');
 for(let i=0;i<30;i++)w.document.body.appendChild(w.document.createElement('div'));await new Promise(setImmediate);assert.equal(requests,0,'Unrelated DOM changes never reload bill data');dom.window.close();console.log('PASS bag review reuses loaded data, ignores stale companies and causes no background reads');
})().catch(e=>{console.error(e);process.exitCode=1;});
