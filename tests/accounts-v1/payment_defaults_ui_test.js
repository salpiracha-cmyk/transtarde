'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
function load(path,anchor,expose){
 const sandbox={window:{},document:{querySelector:()=>null,querySelectorAll:()=>[],addEventListener:()=>{},documentElement:{}},localStorage:{getItem:()=>null},MutationObserver:class{observe(){}},Intl};
 vm.createContext(sandbox);vm.runInContext(fs.readFileSync(path,'utf8').replace(anchor,expose+anchor),sandbox);return sandbox.window.__test;
}
const rows=[{id:'receipt',currency:'USD',settings:{defaultReceiptAccount:true}},{id:'payment',currency:'USD',settings:{defaultPaymentAccount:true}},{id:'aed',currency:'AED',settings:{defaultPaymentAccount:true}}];
const tx=load('accounts/tg-bank-transactions-ui.js','new MutationObserver(ensureButton)', 'window.__test={set:d=>data=d,banks};');
assert(tx,'TG transaction test hook');tx.set({banks:rows});assert.equal(tx.banks('payment','USD')[0].id,'payment');assert.equal(tx.banks('receipt','USD')[0].id,'receipt');tx.set({banks:rows.filter(r=>r.id!=='payment')});assert.equal(tx.banks('payment','USD')[0].id,'receipt');
const fx=load('accounts/tg-bank-transfer-ui.js','window.TT_TG_BANK_TRANSFER_UI=', 'window.__test={set:d=>data=d,banks};');fx.set({banks:rows});assert.equal(fx.banks('USD','source')[0].id,'payment');assert.equal(fx.banks('USD','destination')[0].id,'receipt');assert.equal(fx.banks('AED','source').length,1);
const st=load('accounts/supplier-settlement-ui.js','window.TT_SUPPLIER_SETTLEMENT_UI=', 'window.__test={set:d=>paymentDraft=d,paymentReferenceLabel};');assert(st,'Supplier settlement test hook');for(const [method,label] of [['CHEQUE','Cheque number'],['ONLINE_BANKING','Online banking transaction reference (optional)']]){st.set({bankMethod:method});assert.equal(st.paymentReferenceLabel(),label)}
console.log('Independent payment/receipt defaults, currency filtering, fallback and method reference labels passed');
