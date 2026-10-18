'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
const source=fs.readFileSync('milling/Transtrade_Master_Milling_V3_3_2_AUDITED.html','utf8');
const dom=new JSDOM('<div id="processingWork"></div>',{runScripts:'outside-only'}),w=dom.window;
const rent=[{entity:'TTI',millId:1,amount:100,ref:'TTI current'},{entity:'TTI',millId:2,amount:200,ref:'TTI other mill'},{entity:'BRM',millId:3,amount:300,ref:'BRM reference'},{amount:50,ref:'Legacy default'}];
const advances=rent.map(r=>({...r,month:'2026-10',personId:1}));advances.push({entity:'TTI',millId:1,month:'2026-09',personId:1,amount:400});
const stores={rent,advances};let writes=0;
Object.assign(w,{currentMill:{id:1,name:'TTI Rice Mills',entity:'TTI'},STORE_RENTPAYMENTS:'rent',STORE_SALARYADVANCES:'advances',get:(k,f)=>structuredClone(stores[k]??f),set:(k,v)=>{writes++;stores[k]=structuredClone(v)},stockEntityForView:()=>w.currentMill.entity,millLocationIdentity:s=>String(s||'').trim().toLowerCase(),costMaster:()=>({rent:1000,rentEffective:'2026-10-01'}),today:()=> '2026-10-18',escHtml:s=>String(s??''),salaryMaster:()=>[{id:1,person:'Fixture',category:'Mill Staff',amount:1000,active:true}],showNonBlockingMessage(){},alert:m=>{throw Error(m)}});
for(const name of ['operationalStockScope','stockScopeMatches','monthCount','renderRentAccount','saveRentPayment','renderSalaryAccount','renderSalarySheet','saveSalaryAdvance']){const a=source.indexOf('function '+name+'('),b=source.indexOf('\nfunction ',a+1);assert(a>=0,name);w.eval(source.slice(a,b));}
const before=JSON.stringify(stores),metrics=root=>[...root.querySelectorAll('.metric b')].map(x=>x.textContent);
for(const [mill,paid,expectedRef] of [[{id:1,name:'TTI Rice Mills',entity:'TTI'},150,'TTI current'],[{id:2,name:'Other TTI Mill',entity:'TTI'},200,'TTI other mill'],[{id:3,name:'BRM Mill',entity:'BRM'},300,'BRM reference']]){
 w.currentMill=mill;w.renderRentAccount();assert.equal(metrics(w.processingWork)[2],'Rs. '+(1000-paid).toLocaleString(),'Rent estimate must use selected company/mill only');
 const rows=w.processingWork.querySelector('table').textContent;assert(rows.includes(expectedRef));for(const r of rent)if(!w.stockScopeMatches(r))assert(!rows.includes(r.ref),'Other-scope rent reference leaked');
 w.renderSalaryAccount();assert.deepEqual(metrics(w.salarySheet),['Rs. 1,000','Rs. '+paid.toLocaleString(),'Rs. '+(1000-paid).toLocaleString()],'Salary advance estimate must retain month and selected scope');
}
assert.equal(writes,0,'Opening/filtering must never save');assert.equal(JSON.stringify(stores),before,'Reads must preserve all reference history');
w.currentMill={id:3,name:'BRM Mill',entity:'BRM'};w.renderRentAccount();w.rentPayAmount.value='25';w.rentPayFrom.value='2026-10';w.rentPayTill.value='2026-10';w.rentPayRef.value='New BRM';w.saveRentPayment();assert.deepEqual(stores.rent.slice(0,rent.length),rent);assert.equal(stores.rent.at(-1).entity,'BRM');assert.equal(stores.rent.at(-1).millId,3);
w.renderSalaryAccount();w.salaryAdvancePerson.value='1';w.salaryAdvanceAmount.value='25';w.salaryAdvanceRef.value='New advance';w.saveSalaryAdvance();assert.deepEqual(stores.advances.slice(0,advances.length),advances);assert.equal(stores.advances.at(-1).entity,'BRM');assert.equal(stores.advances.at(-1).millId,3);assert.equal(writes,2);
dom.window.close();console.log('PASS rent/salary reference views: company/mill/month isolation, existing legacy default policy, read-only opens and explicit saves preserving other history');
