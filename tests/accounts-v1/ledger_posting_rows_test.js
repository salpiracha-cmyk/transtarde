'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const sandbox={window:{},document:{querySelector:()=>null},localStorage:{getItem:()=>null},Intl,TextEncoder,Uint8Array,DataView,Blob};
vm.createContext(sandbox);
let source=fs.readFileSync('accounts/all-ledgers-ui.js','utf8');
source=source.replace('window.TT_ALL_LEDGERS={','window.__ledgerTest={setRows:r=>data={rows:r},postingRows,rowsHtml,totals,displayRef};window.TT_ALL_LEDGERS={');
vm.runInContext(source,sandbox);
const t=sandbox.window.__ledgerTest;
t.setRows([
 {voucher:'AUTO-2026-000009',date:'2026-09-30',reference:'JI-8239/26',account:'6800',accountName:'Freight',narration:'Shipment freight',party:'Paklink',debit:100,credit:0,balance:100},
 {voucher:'AUTO-2026-000009',date:'2026-09-30',reference:'JI-8239/26',account:'2140',accountName:'Supplier payable',narration:'Shipment freight',party:'Paklink',debit:0,credit:100,balance:0},
 {voucher:'RV-2026-000009',date:'2026-10-01',reference:'AUTO-2026-000009',account:'6800',accountName:'Freight',narration:'Correction reversal',debit:0,credit:100,balance:-100},
]);
let groups=t.postingRows();assert.equal(groups.length,2,'Original/reversal IDs must stay distinct even when display numbers coincide');const original=groups.find(x=>x.voucher==='AUTO-2026-000009');assert.equal(original.debit,100);assert.equal(original.credit,100);assert.equal(original.balance,0);assert.equal(original.parties.size,1);assert.equal(t.totals().credit,200);
assert.equal(t.displayRef('AUTO-2026-000009'),'POST-2026-00009');assert.equal(t.displayRef('JI-8239/26'),'JI-8239/26','External supplier invoice is preserved');
let html=t.rowsHtml();assert.equal((html.match(/class="tal-posting-row"/g)||[]).length,2);assert(html.includes('data-post-id="AUTO-2026-000009"'));assert(html.includes('data-amend-id="AUTO-2026-000009"'));assert(html.includes('Shipment freight'));
assert.equal(t.displayRef('POST-2026-00009'),'POST-2026-00009','Universal Post IDs remain complete in the ledger');
t.setRows([{voucher:'AUTO-2026-000010',debit:20,credit:0,balance:20,nativeMissing:true},{voucher:'AUTO-2026-000010',debit:0,credit:5,balance:15}]);groups=t.postingRows();assert.equal(groups[0].balance,15);assert.equal(groups[0].nativeMissing,true,'Grouping cannot invent unavailable native currency amounts');
t.setRows([{voucher:'AUTO-2026-000047',date:'2026-09-01',debit:5,credit:0,balance:5},{voucher:'AUTO-2026-000045',date:'2026-10-01',debit:3,credit:0,balance:8}]);assert.equal(t.postingRows()[0].voucher,'AUTO-2026-000047','Highest Post ID first, even for backdated entries');
t.setRows([{voucher:'POST-2026-00011',debit:0,credit:0,balance:null,activityOnly:true,expenseAmount:30},{voucher:'POST-2026-00011',debit:0,credit:0,balance:null,activityOnly:true,expenseAmount:40}]);assert.equal(t.postingRows()[0].expenseAmount,70,'Each expense activity amount appears once');
t.setRows([{voucher:'POST-2026-00012',debit:0,credit:20,balance:20},{voucher:'POST-2026-00012',debit:0,credit:0,balance:null,activityOnly:true,expenseAmount:50}]);assert.equal(t.postingRows()[0].balance,20,'Expense activity cannot overwrite the payable balance');
console.log('Ledger posting grouping, selected-account balances, original identities and invoice references passed');
