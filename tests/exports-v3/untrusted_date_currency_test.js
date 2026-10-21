'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom'),fixture=require('../accounts-v1/monthly_tax_invoice_fixture');
const dom=new JSDOM('<div id="nav"></div><div id="app"></div><main id="main"></main><div id="readerReview"></div><div id="output"></div>',{url:'https://fixture.test/exports/',runScripts:'outside-only'}),w=dom.window,d=w.document;
w.structuredClone=structuredClone;w.TT_MODULE_ACCESS={user:'Security fixture',masters:{}};w.scrollTo=()=>{};w.queueMicrotask=()=>{};
let source=fs.readFileSync(process.env.TT_EXPORT_SOURCE||'exports/app.js','utf8');
assert(source.includes('mount();restoreContractCheckpoint();'));
source=source.replace('mount();restoreContractCheckpoint();','window.securityFixture={state,fmt,money,purchaseOrderPrint,renderFI,renderContracts,showContractReview,salesContractReference,specimenReference,ttApprovedInvoiceReference,packingListDoc,cooDoc};');
w.eval(source);const t=w.securityFixture;let writes=0,requests=0;
w.Storage.prototype.setItem=()=>{writes++;};w.fetch=()=>{requests++;throw Error('Review must not make requests');};
const input=fixture();Object.assign(t.state,input);const c=input.contracts[0],s=input.shipments[0];
try{
 for(const value of ['<img id="security-injected-date" src=x onerror="globalThis.executed=true">','<svg id="security-injected-date" onload="globalThis.executed=true"></svg>']){
  c.date=value;s.bl.onBoardDate=value;s.lc={...s.lc,lcNo:'FIXTURE-LC',lcDate:value};s.commercial.date=value;c.paymentCode='LC_SIGHT';
  const before=JSON.stringify(input);t.renderContracts();
  assert(!d.getElementById('security-injected-date'),'Contract table must not interpret stored date markup');
  assert.equal(d.querySelector('#main tbody tr td:nth-child(2)').textContent,value);
  for(const html of [t.salesContractReference(c),t.specimenReference('REF',value),t.ttApprovedInvoiceReference('INV',value),t.packingListDoc(s,c),t.cooDoc(s,c),t.purchaseOrderPrint({poNo:'PO',requiredDate:value,lines:[]})]){
   d.getElementById('output').innerHTML=html;
   assert(!d.getElementById('security-injected-date'),'Document date must not become markup');
  }
  t.state.fi=[{id:'F1',number:'FI',date:'2026-10-01',lcDate:value,lcNo:'LC',value:100,currency:'USD',type:'Advance',customer:'Fixture Buyer',allocations:[]}];t.renderFI();
  assert(!d.getElementById('security-injected-date'),'FI L/C date must remain literal');assert(d.getElementById('main').textContent.includes(value));t.state.fi=input.fi;
  assert.equal(JSON.stringify(input),before,'Rendering retains the original saved date values');
  assert.equal(t.fmt(value),value,'Plaintext date formatting must not receive HTML entities');
 }
 c.date='2026-10-01';t.renderContracts();assert.equal(d.querySelector('#main tbody tr td:nth-child(2)').textContent,'01-10-2026');
 for(const currency of ['USD" autofocus onfocus="globalThis.executed=true','"><img id="security-injected-currency" src=x onerror="globalThis.executed=true">','X&Y']){
  const extracted={currency,buyer:'Fixture Buyer',buyerAddress:'Fixture address',ref:'FIXTURE/IMPORT',date:'2026-10-01',qty:27,containers:1,size:25,type:'PP Bags',brand:'FIXTURE',price:400,packings:[]};
  const before=JSON.stringify(t.state);t.showContractReview(extracted);
  const control=d.getElementById('riCurrency');assert(control);assert.equal(control.value,currency);
  assert(!control.hasAttribute('autofocus')&&!control.hasAttribute('onfocus'));
  assert(!d.getElementById('security-injected-currency'),'Extraction review must not create provider-supplied elements');
  assert.equal(JSON.stringify(t.state),before,'Review never commits the extracted values');
  const previous=t.state.fi;t.state.fi=[{id:'F1',number:'FI',date:'2026-10-01',lcDate:'2026-10-01',lcNo:'LC',value:100,currency,type:'Advance',customer:'Fixture Buyer',allocations:[]}];t.renderFI();
  assert(!d.getElementById('security-injected-currency'),'Saved currency must remain literal in amount cells');assert(d.getElementById('main').textContent.includes(currency));assert.equal(t.money(100,currency).includes(currency),true,'Plaintext money retains original currency');t.state.fi=previous;
 }
 assert.equal(writes,0);assert.equal(requests,0);assert.equal(w.executed,undefined);
 console.log('PASS production contract/date document sinks and import-currency review: literal values, normal dates, preserved plaintext/originals, no injected attributes/elements and no save/request');
}finally{w.close();}
