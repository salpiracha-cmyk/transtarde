'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),{chromium}=require('playwright');
(async()=>{
 const browser=await chromium.launch({headless:true});
 try{
  const page=await browser.newPage();const errors=[];page.on('pageerror',error=>errors.push(error.message));
  await page.route('http://fixture.test/**',route=>route.fulfill({contentType:'text/html',body:'<button data-expense="utility">Utility</button><button data-expense="card">Card</button><button data-expense="rent">Rent</button><button data-expense="donations">Donations</button><button class="entityBtn">Company</button><div id="expenseEditor"></div>'}));
  await page.goto('http://fixture.test/');
  await page.evaluate(()=>{
   window.TT_ACCOUNT_ACCESS={super:true,csrf:'fixture'};localStorage.setItem('tt_accounts_entity','TTI');window.pending=[];
   window.fetch=(url,options)=>new Promise(resolve=>pending.push({url,options,resolve}));
   window.expenseData={ok:true,utilityMasters:[],utilityPayments:[],creditCardMasters:[],creditCardStatements:[],reimbursements:[],generalExpenses:[],reminders:[],paymentAccounts:[{id:'CASH|TTI',label:'Cash',currency:'PKR',bookBalance:100}],locations:[{id:'OFFICE',name:'Karachi Office',location:'OFFICE',type:'Office'},{id:'MILL',name:'Own Rice Mill',location:'MILL',type:'Own Mill'}]};
   window.resolveNext=(kind,data)=>{const index=pending.findIndex(row=>row.url.includes(kind));if(index<0)throw Error('Missing request '+kind);const request=pending.splice(index,1)[0];request.resolve({ok:true,json:async()=>data||expenseData});};
  });
  for(const path of ['accounts/expense-editor-owner.js','accounts/rent-salary-ui.js','accounts/donations-ui.js','accounts/expenses-v1-ui.js'])await page.addScriptTag({content:fs.readFileSync(path,'utf8')});
  await page.click('[data-expense="utility"]');await page.evaluate(()=>resolveNext('expenses_v1'));await page.waitForSelector('#evAddUtility');
  assert.equal(await page.locator('#evUmPayee').count(),0,'reminder setup starts closed');
  await page.click('#evAddUtility');await page.waitForSelector('#evUmPayee');
  assert.equal(await page.locator('#evUmLocName').inputValue(),'OFFICE','own office auto-selected');
  assert.deepEqual(await page.locator('#evUmLocName option').allTextContents(),['Select company location','Karachi Office','Own Rice Mill']);
  assert.equal(await page.locator('.tte-master-popup').isVisible(),true,'master setup opens as popup');await page.click('#evUmCancel');
  await page.selectOption('#evPayLocName','MILL');
  assert.equal(await page.locator('#evReadFrom').isVisible(),true,'meter dates visible for mill electricity');
  await page.selectOption('#evPayType','INTERNET');
  assert.equal(await page.locator('#evReadFrom').isVisible(),false,'meter dates hidden for other bills');
  await page.click('[data-expense="card"]');await page.evaluate(()=>resolveNext('expenses_v1'));await page.waitForSelector('#evAddCard');
  assert.equal(await page.locator('#evCardName').count(),0,'credit card setup starts closed');
  await page.click('#evAddCard');assert.equal(await page.locator('#evCardName').count(),1);await page.click('#evCardCancel');
  // A slow response from another module must never replace the newly opened form.
  await page.click('[data-expense="rent"]');await page.click('[data-expense="utility"]');
  await page.evaluate(()=>resolveNext('expenses_v1'));await page.waitForSelector('#evAddUtility');
  await page.fill('#evPayee','Unsaved current entry');
  await page.evaluate(()=>resolveNext('rent_salary',{ok:true,salaryMasters:[],rentMasters:[],salaryRows:[],rentRows:[],rentReminders:[],paymentAccounts:[]}));
  await page.waitForTimeout(100);assert.equal(await page.locator('#evPayee').inputValue(),'Unsaved current entry');
  await page.click('[data-expense="donations"]');await page.click('[data-expense="card"]');
  await page.evaluate(()=>resolveNext('expenses_v1'));await page.waitForSelector('#evAddCard');
  await page.evaluate(()=>resolveNext('donations',{ok:true,donations:[],totals:{},paymentAccounts:[]}));
  await page.waitForTimeout(100);assert.equal(await page.locator('#evAddCard').count(),1,'late donation response cannot switch card screen');
  assert.deepEqual(errors,[]);
  console.log('PASS closed setup forms, own office default, dependent meter fields, and slow rent/donation response isolation');
 }finally{await browser.close()}
})().catch(error=>{console.error(error);process.exitCode=1});
