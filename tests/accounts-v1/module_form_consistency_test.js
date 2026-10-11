'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
const script=(w,name)=>w.eval(fs.readFileSync('accounts/'+name,'utf8'));
const turn=()=>new Promise(setImmediate);
function dom(html=''){return new JSDOM('<!doctype html><html><head></head><body>'+html+'</body></html>',{runScripts:'outside-only',url:'https://accounts.test/',pretendToBeVisual:true});}
(async()=>{
 let d=dom(),w=d.window;w.localStorage.setItem('tt_accounts_entity','TTI');w.TT_ACCOUNT_ACCESS={csrf:'fixture'};w.TT_FORM_VIEWPORT={open(){}};
 let posts=[];w.fetch=async(url,opt={})=>({ok:true,json:async()=>opt.body?(posts.push(JSON.parse(opt.body)),{ok:true,result:{journalId:'POST-1',netPayment:995,date:'2026-10-11'}}):String(url).includes('supplier_settlements')?{ok:true,payables:[{billId:'B',sourceKey:'SERVICE|B',supplier:'MAPCO',category:'CLEARING',billNo:'123',billDate:'2026-07-01',outstanding:1000,total:1000,paid:0}]}:{ok:true,accounts:[]}});
 script(w,'accounts-form-layout.js');script(w,'supplier-settlement-ui.js');await w.TT_SUPPLIER_SETTLEMENT_UI.openBills('CLEARING','MAPCO');await turn();
 let q=s=>w.document.querySelector(s);q('[data-source]').value='THIRD_PARTY';q('[data-source]').dispatchEvent(new w.Event('change'));assert(q('[data-reference]').disabled,'Hidden cheque reference must not block third-party posts');assert(!q('[data-reference]').required);q('[data-payer]').value='ABC';
 q('[data-source]').value='CASH';q('[data-source]').dispatchEvent(new w.Event('change'));q('[data-discount-enabled]').checked=true;q('[data-discount-enabled]').dispatchEvent(new w.Event('change'));
 q('[data-discount]').value='5';q('[data-discount]').dispatchEvent(new w.Event('input'));q('[data-discount-reason]').value='Agreed rounding';assert.match(q('[data-payment-total]').textContent,/995/);assert.match(q('[data-allocation-preview]').textContent,/1,000/);
 q('form').dispatchEvent(new w.Event('submit',{cancelable:true}));await turn();assert.equal(posts[0].amount,995);assert.equal(posts[0].discountAmount,5);assert.equal(posts[0].discountReason,'Agreed rounding');w.close();
 d=dom('<label>Payer<input id="payer" data-master-role="party"></label>');w=d.window;w.TT_ACCOUNT_ACCESS={super:true,masters:{business_parties:[{id:'A',values:['ABC','','Supplier','','','','','','','','Active']}]}};script(w,'master-autocomplete.js');await turn();w.document.querySelector('#payer').focus();assert.match(w.document.querySelector('.tt-party-menu').textContent,/ABC/,'Explicit party role must resolve its real list');assert(w.document.querySelector('.tt-option-edit'));assert(w.document.querySelector('.tt-option-add'));w.close();
 d=dom('<div id="ws-bank"><div class="panelHead"></div></div>');w=d.window;w.localStorage.setItem('tt_accounts_entity','TG');w.TT_ACCOUNT_ACCESS={};w.TT_FORM_VIEWPORT={open(){}};
 const banks=[{id:'USD',currency:'USD',bank:'Bank A',title:'TG USD',balance:{native:1000}},{id:'AED',currency:'AED',bank:'Bank B',title:'TG AED',balance:{native:1000}}];
 w.fetch=async()=>({ok:true,json:async()=>({ok:true,banks,rates:{sellUsdToAed:3.67,buyUsdWithAed:3.67},history:[]})});script(w,'tg-bank-transfer-ui.js');await w.TT_TG_BANK_TRANSFER_UI.open();q=s=>w.document.querySelector(s);
 q('#tgfxSource').value='USD';q('#tgfxSource').dispatchEvent(new w.Event('change'));q('#tgfxDest').value='AED';q('#tgfxAmount').value='123.45';q('#tgfxNotes').value='Keep payment purpose';q('#tgfxDate').value='2026-10-01';q('#tgfxDate').dispatchEvent(new w.Event('change'));await turn();
 assert.equal(q('#tgfxDate').value,'2026-10-01');assert.equal(q('#tgfxSource').value,'USD');assert.equal(q('#tgfxDest').value,'AED');assert.equal(q('#tgfxAmount').value,'123.45');assert.equal(q('#tgfxNotes').value,'Keep payment purpose');assert(!q('#tgfxPost').disabled);w.close();
 console.log('PASS supplier discount draft/preview, hidden cheque validation, party add/edit, TG date/rate updates preserve all fields');
})().catch(e=>{console.error(e);process.exitCode=1;});
