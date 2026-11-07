'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const sandbox={window:{},document:{querySelector:()=>null},localStorage:{getItem:()=>null},Intl,TextEncoder,Uint8Array,DataView,Blob};
vm.createContext(sandbox);
let source=fs.readFileSync('accounts/all-ledgers-ui.js','utf8');
source=source.replace('window.TT_ALL_LEDGERS={','window.__ledgerTest={setCategory:c=>category=c,setAccount:a=>account=a,setRows:r=>data={rows:r,accounts:[],currency:"PKR"},statementRows,postingRows,rowsHtml,totals,registerTotals,tableHtml,displayRef};window.TT_ALL_LEDGERS={');
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
t.setRows([{voucher:'AUTO-2026-000047',date:'2026-09-01',debit:5,credit:0,balance:5},{voucher:'AUTO-2026-000045',date:'2026-10-01',debit:3,credit:0,balance:8}]);assert.equal(t.postingRows()[0].voucher,'AUTO-2026-000047','Ordinary screen ledger is oldest date first');t.setAccount('POSTS');assert.equal(t.postingRows()[0].voucher,'AUTO-2026-000045','Post ID register retains latest date first');t.setAccount('');assert.deepEqual(Array.from(t.statementRows().filter(r=>/^2026-/.test(r[0])).map(r=>r[0])),['2026-09-01','2026-10-01'],'Print and Excel must use ascending dates and ordinary screens use ascending dates');
t.setRows([{voucher:'POST-2026-00011',debit:0,credit:0,balance:null,activityOnly:true,expenseAmount:30},{voucher:'POST-2026-00011',debit:0,credit:0,balance:null,activityOnly:true,expenseAmount:40}]);assert.equal(t.postingRows()[0].expenseAmount,70,'Each expense activity amount appears once');
t.setRows([{voucher:'POST-2026-00012',debit:0,credit:20,balance:20},{voucher:'POST-2026-00012',debit:0,credit:0,balance:null,activityOnly:true,expenseAmount:50}]);assert.equal(t.postingRows()[0].balance,20,'Expense activity cannot overwrite the payable balance');
console.log('Ledger posting grouping, selected-account balances, original identities and invoice references passed');

t.setRows([{voucher:'POST-2026-00015',debit:100,credit:100,canDelete:true},{voucher:'POST-2026-00014',debit:100,credit:100,canDelete:false,deleteReason:'Already cancelled'}]);html=t.rowsHtml();assert.equal((html.match(/data-delete-id=/g)||[]).length,2,'Every displayed posting row must retain a visible minus action');assert(html.includes('data-delete-reason="Already cancelled"'),'Protected rows must explain the reason rather than silently hide their action');

t.setAccount('POSTS');t.setRows([
 {voucher:'PK-1',date:'2026-07-01',account:'POSTS',accountName:'TTI',entity:'TTI',bookCurrency:'PKR',debit:100,credit:100},
 {voucher:'PK-2',date:'2026-07-02',account:'POSTS',accountName:'BRM',entity:'BRM',bookCurrency:'PKR',debit:200,credit:200},
 {voucher:'TG-1',date:'2026-07-03',account:'POSTS',accountName:'TG',entity:'TG',bookCurrency:'AED',debit:367,credit:367}
]);
const currencyTotals=t.registerTotals();assert.equal(currencyTotals.length,3);assert.equal(currencyTotals.find(x=>x.company==='TG').debit,367);assert.equal(currencyTotals.find(x=>x.company==='TTI').debit,100);
html=t.tableHtml();assert(html.includes('TG · AED'));assert(html.includes('TTI · PKR'));assert(!html.includes('667.00'),'All-company total must never mix AED with PKR');
const exportRows=Array.from(t.statementRows());assert(exportRows.some(row=>row[0]==='Date'&&row.includes('Book currency')));assert.equal(exportRows.filter(row=>row[0]==='Subtotal').length,3);assert(exportRows.some(row=>row[0]==='Subtotal'&&row[2]==='TG'&&row[3]==='AED'&&row[5]===367));assert(!exportRows.some(row=>row.includes(667)));
console.log('PASS all-company register currency labels, separate subtotals and shared print/Excel statement');

t.setCategory('bank');t.setAccount('BANK|AED');t.setRows([{voucher:'FEE',date:'2026-07-13',credit:16000,debit:0,balance:-16000},{voucher:'FEE',date:'2026-07-13',credit:30,debit:0,balance:-16030,bankChargeComponent:'Online Banking Charges'},{voucher:'FEE',date:'2026-07-13',credit:1.5,debit:0,balance:-16031.5,bankChargeComponent:'VAT on Online Banking Charges'}]);assert.equal(t.postingRows().length,3,'Bank statement keeps principal, fee and VAT visible separately');assert.deepEqual(Array.from(t.postingRows().map(r=>r.credit)),[16000,30,1.5]);console.log('PASS bank statement preserves separate fee/VAT lines and running balances under one Post ID');
