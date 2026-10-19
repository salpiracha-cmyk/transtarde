'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
const boundaries=[
 ['2026-10-19T18:59:59Z','2026-10-19'],['2026-10-19T19:00:00Z','2026-10-20'],
 ['2026-10-31T19:00:00Z','2026-11-01'],['2026-12-31T19:00:00Z','2027-01-01'],
 ['2028-02-28T19:00:00Z','2028-02-29'],['2028-02-29T19:00:00Z','2028-03-01']
];
(async()=>{
 for(const [instant,expected]of boundaries){
  const dom=new JSDOM('<main><div id="expenseEditor"></div><div id="masterBody"></div></main>',{url:'https://fixture.test/accounts/',runScripts:'outside-only'}),w=dom.window,requests=[];
  try{
   const RealDate=w.Date;w.Date=class extends RealDate{constructor(...args){super(...(args.length?args:[instant]))}static now(){return RealDate.parse(instant)}};
   w.CSS={escape:s=>s};w.TT_ACCOUNT_ACCESS={csrf:'fixture'};
   w.fetch=async(url,options={})=>{requests.push({url,method:options.method||'GET'});assert.notEqual(options.method,'POST','Opening/defaulting fields must never post');return {ok:true,json:async()=>({ok:true,salaryMasters:[],salaryRows:[],salarySheet:{rows:{},status:'Draft'},paymentAccounts:[],salaryAdvances:[],rentRows:[],rentMasters:[],donations:[],totals:{}})}};
   w.eval(fs.readFileSync('accounts/rent-salary-ui.js','utf8'));
   await w.TT_RENT_SALARY_UI.open('salary');w.document.querySelector('#rsAdvanceIcon').click();
   assert.equal(w.document.querySelector('#rsAdvanceDate').value,expected,'Actual salary form uses Karachi day at '+instant);
   assert.equal(w.document.querySelector('#rsMonth').value,expected.slice(0,7),'Salary month follows the same business day');
   assert(requests.every(r=>new URL(r.url,w.location.href).searchParams.get('month')===expected.slice(0,7)),'Salary reads request the business month');
   // Existing manually selected dates do not get rewritten by ordinary typing.
   const date=w.document.querySelector('#rsAdvanceDate');date.value='2026-07-15';date.dispatchEvent(new w.Event('input',{bubbles:true}));
   assert.equal(date.value,'2026-07-15');
   w.eval(fs.readFileSync('accounts/donations-ui.js','utf8'));await w.TT_DONATIONS_UI.refresh();
   assert.equal(w.document.querySelector('#donDate').value,expected,'Actual donation/Zakat form uses Karachi day');
   w.eval(fs.readFileSync('accounts/bank-direct-entry-ui.js','utf8'));
   w.TT_BANK_DIRECT_ENTRY.openProfit({id:'B1',currency:'PKR'});
   assert.equal(w.document.querySelector('#ttBdeDate').value,expected,'Actual legacy saving-profit form uses Karachi day');
   assert(requests.every(r=>r.method==='GET'));
  }finally{w.close()}
 }
 for(const file of ['rent-salary-ui.js','donations-ui.js','bank-direct-entry-ui.js','payables-commodity-split-ui.js','local-sales-costing-ui.js','export-costing-ui.js','source-bridge.js'])assert.doesNotMatch(fs.readFileSync('accounts/'+file,'utf8'),/new Date\(\)\.toISOString\(\)\.slice\(0,10\)/,'Current-day defaults must not truncate UTC in '+file);
 console.log('PASS Karachi business dates: native salary/month, donation/Zakat and saving-profit forms at midnight, month/year and leap-day boundaries; manual dates preserved and zero posts.');
})().catch(e=>{console.error(e);process.exitCode=1});
