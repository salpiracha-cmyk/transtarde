'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
(async()=>{
 const dom=new JSDOM('<div id="purchaseEditor"></div>',{url:'https://fixture.test/accounts/',runScripts:'outside-only'}),w=dom.window;
 const observers=[],Observer=w.MutationObserver;w.MutationObserver=class extends Observer{constructor(fn){super(fn);observers.push(this)}};
 try{
 w.TT_ACCOUNT_ACCESS={super:true};w.localStorage.setItem('tt_accounts_entity','TTI');
 const line={lineKey:'L1',orderedQty:100,receivedQty:100,billedQty:0,remainingToBill:100,receivedNotBilled:100,bagType:'PP',packingSize:'50kg',brand:'BRAND'};
 const data={ok:true,entity:'TTI',bagSuppliers:[{name:'Supplier'}],purchaseOrders:[{poNo:'BAD',supplier:'Supplier',shipmentRef:'SHIP',lines:[{...line,billingBlocked:true,receiptConflict:'Receipt R1: Brand <original> differs',receivedNotBilled:null}]},{poNo:'GOOD',supplier:'Supplier',shipmentRef:'SHIP',lines:[line]}],bills:[],taxRates:[]};
 let posts=0;w.fetch=async(u,o={})=>{if(o.method==='POST')posts++;return {ok:true,json:async()=>data}};
 w.eval(fs.readFileSync('accounts/bag-purchases-ui.js','utf8'));
 await w.TT_BAG_PURCHASES_UI.mount();const q=s=>w.document.querySelector(s);
 const change=(id,v)=>{q('#'+id).value=v;q('#'+id).dispatchEvent(new w.Event('change',{bubbles:true}))};
 assert.match(q('#bgReceiptConflicts').textContent,/BAD.*L1.*Supplier.*R1/);assert.equal(q('#bgReceiptConflicts original'),null);
 change('bgLinkedSupplier','Supplier');change('bgLine','0');assert(q('#bgSave').disabled);assert.match(q('#bgCheck').textContent,/R1/);
 q('#bgInvoice').value='typed invoice';change('bgLine','1');assert(!q('#bgSave').disabled);assert.equal(q('#bgInvoice').value,'typed invoice');
 change('bgLine','0');q('#bgSave').dispatchEvent(new w.Event('click'));assert.equal(posts,0,'Even a synthetic click cannot post the conflict');
 w.TT_BAG_PAGE_MODE='report';await w.TT_BAG_PURCHASES_UI.mount();assert(q('#bgRun'));assert(q('#bgReceiptConflicts'));assert.equal(posts,0);
 console.log('PASS conflict warning, safe escaping, blocked selection and synthetic save, healthy selection, preserved typing and report controls');
 }finally{observers.forEach(o=>o.disconnect());w.close();}
})().catch(e=>{console.error(e);process.exitCode=1});
