'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),{JSDOM}=require('jsdom');
const turn=()=>new Promise(setImmediate);
(async()=>{for(const entity of ['TTI','TG']){
 const d=new JSDOM('<!doctype html><body></body>',{url:'https://fixture.test/accounts/',runScripts:'outside-only'}),w=d.window,posts=[];
 const observers=[],Observer=w.MutationObserver;w.MutationObserver=class extends Observer{constructor(fn){super(fn);observers.push(this)}};
 w.localStorage.setItem('tt_accounts_entity',entity);w.TT_ACCOUNT_ACCESS={csrf:'fixture'};w.TT_FORM_VIEWPORT={open(){}};
 const opening={billId:'BF:OPEN-1:0',sourceKey:'OPENING|0',supplier:'Transport One',category:'OPENING',openingBalance:true,billNo:'Balance B/F',billDate:'2026-07-01',total:300,paid:0,outstanding:300,currency:'AED'},bill={billId:'B1',sourceKey:'TRANSPORT|B1',supplier:'Transport One',category:'TRANSPORT',billNo:'Invoice 1',billDate:'2026-08-01',total:100,paid:0,outstanding:100,currency:'AED'};
 const journal={id:'POST-2026-00999',date:'2026-10-12',entity,lines:[{account:'2130',debit:200},{account:'1120',credit:200}]};
 w.fetch=async(url,opt={})=>{
  let payload;if(opt.method==='POST'){posts.push({body:JSON.parse(opt.body),owner:opt.ttConfirmation});payload={ok:true,result:{journalId:journal.id,netPayment:200},transaction:{journalId:journal.id,amountNative:200}};}
  else if(String(url).includes('accounts_ledger_browser'))payload={ok:true,post:journal};
  else if(String(url).includes('tg_bank_transactions'))payload={ok:true,banks:[],openLiabilities:[opening,bill].map(r=>({id:r.billId,counterparty:r.supplier,category:r.category,source:'Supplier / Service Bill',openingBalance:!!r.openingBalance,reference:r.billNo,billDate:r.billDate,currency:r.currency,originalNative:r.total,outstandingNative:r.outstanding}))};
  else payload={ok:true,payables:[opening,bill],accounts:[]};return new Response(JSON.stringify(payload));
 };
 for(const name of ['post-confirmation-ui.js','supplier-settlement-ui.js'])w.eval(fs.readFileSync('accounts/'+name,'utf8'));
 const q=s=>w.document.querySelector(s),change=(s,v)=>{const el=q(s);if(typeof v==='boolean')el.checked=v;else el.value=v;el.dispatchEvent(new w.Event('change',{bubbles:true}));};
 await w.TT_SUPPLIER_SETTLEMENT_UI.openBills('TRANSPORT','Transport One');
 assert.equal(q('[data-allocation-preview]').children.length,0,'Unticked bills are excluded from the initial preview');
 change('[data-include-opening]',true);assert.match(q('[data-allocation-preview]').textContent,/Balance B\/F/);assert(!q('[data-allocation-preview]').textContent.includes('Invoice 1'));
 change('[data-bill]:not([disabled])',true);assert(q('[data-allocation-preview]').textContent.includes('Invoice 1'));
 change('[data-bill]:not([disabled])',false);assert(!q('[data-allocation-preview]').textContent.includes('Invoice 1'),'Unticking the last invoice cannot reactivate all bills');
 change('[data-source]','CASH');q('[data-amount]').value='200';q('[data-amount]').dispatchEvent(new w.Event('input'));
 q('form').dispatchEvent(new w.Event('submit',{cancelable:true}));await turn();await turn();
 assert.equal(posts.length,1);assert.equal(posts[0].owner,'workflow');assert.equal(posts[0].body.explicitBillSelection,true);assert.deepEqual(posts[0].body.selectedBills,entity==='TG'?[opening.billId]:[opening.billId+'|'+opening.sourceKey]);
 assert.equal(q('#ttSimpleBills'),null,'The grey form receipt is removed after successful posting');assert.equal(w.document.querySelectorAll('#tt-post-confirmation').length,1,'Exactly one shared posting confirmation appears');assert(!q('.tt-record-card'));
 q('#tt-post-confirmation [data-close]').click();await turn();assert(!q('#tt-post-confirmation'),'Closing confirmation does not expose another receipt');
 await w.TT_SUPPLIER_SETTLEMENT_UI.openBills('TRANSPORT','Transport One');change('[data-source]','CASH');q('[data-amount]').value='200';q('form').dispatchEvent(new w.Event('submit',{cancelable:true}));await turn();assert.equal(posts.length,1,'An empty selection cannot post against unticked invoices');assert(q('[data-error]').textContent);
 observers.forEach(o=>o.disconnect());w.close();
 }console.log('PASS Pakistan/TG Balance B/F-only payments, explicit invoice exclusion, empty-selection rejection and single shared confirmation');})().catch(e=>{console.error(e);process.exitCode=1});
