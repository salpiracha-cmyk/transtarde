'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),{chromium}=require('playwright');
(async()=>{
 const browser=await chromium.launch({headless:true});
 try{
  const page=await browser.newPage({viewport:{width:1100,height:800}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.route('http://fixture.test/**',r=>r.fulfill({contentType:'text/html',body:'<!doctype html><div id="expenseEditor"></div>'}));await page.goto('http://fixture.test');
  await page.evaluate(()=>{
   window.TT_ACCOUNT_ACCESS={super:true,csrf:'fixture'};localStorage.setItem('tt_accounts_entity','TTI');window.posts=[];
   const rate={key:'bagSupplierBills|B40',supplier:'Example Bags',reference:'PAST-40',date:'2026-09-01',currency:'PKR',quantity:1000,unitRate:50,taxRate:18,whtRate:99,packingSize:'40 kg',specification:'Woven',amount:59000};
   window.fixture={ok:true,estimatedBills:[],expenseRecipients:['Example Bags'],estimateRateHistory:[{...rate,key:'FOREIGN',currency:'USD',unitRate:1000,date:'2026-09-30'},{...rate,key:'B50',packingSize:'50 kg',unitRate:70,date:'2026-09-29'},rate]};
   window.fetch=async(url,options={})=>{if(options.body){posts.push(JSON.parse(options.body));return {ok:false,json:async()=>({ok:false,error:'Fixture lost acknowledgement; entry retained'})};}return {ok:true,json:async()=>structuredClone(fixture)}};
  });
  await page.addScriptTag({content:fs.readFileSync('accounts/expense-desk-ui.js','utf8')});await page.evaluate(()=>TT_EXPENSE_DESK.estimates());await page.click('[data-est-new]');
  const fill=async(name,value)=>page.locator('[name="'+name+'"]').fill(value);
  await fill('supplier','Example Bags');await fill('reference','ASAS-2 bags');await page.locator('summary').click();await fill('shipmentId','S2');await fill('expenseKind','BAGS');await fill('sourceKey','BAG|P2|L1');await fill('purpose','Missing bags');await fill('date','2026-10-01');await fill('packingSize','40kg');await fill('specification','Woven');await fill('quantity','2000');
  await page.click('[data-est-suggest]');assert.equal(await page.locator('[name=unitRate]').inputValue(),'50');assert.equal(await page.locator('[name=quantity]').inputValue(),'2000');assert.equal(await page.locator('[name=whtRate]').inputValue(),'','never infer current WHT from old invoice');assert.match(await page.locator('[data-est-proof]').textContent(),/PAST-40/);
  await fill('whtRate','5');await page.selectOption('[name=whtBase]','TOTAL');assert.match(await page.locator('[data-est-total]').textContent(),/118,000.00.*112,100.00.*5,900.00/);
  assert.equal(await page.evaluate(()=>posts.length),0,'typing and suggesting do not save');await page.click('button[type=submit]');await page.waitForFunction(()=>posts.length===1);
  let post=await page.evaluate(()=>posts[0]);assert.equal(post.action,'save_estimated_bill');assert.equal(post.rateSource,'bagSupplierBills|B40');assert.equal(post.sourceKey,'BAG|P2|L1');assert(!post.bankPaymentMethod);assert.match(await page.locator('[data-est-error]').textContent(),/retained/);
  await page.click('button[type=submit]');await page.waitForFunction(()=>posts.length===2);assert.equal(await page.evaluate(()=>posts[1].requestKey),post.requestKey,'ambiguous retry reuses idempotency key');
  await fill('packingSize','50 kg');await page.click('[data-est-suggest]');assert.equal(await page.locator('[name=unitRate]').inputValue(),'70');await fill('unitRate','71');await page.click('button[type=submit]');await page.waitForFunction(()=>posts.length===3);assert.equal(await page.evaluate(()=>posts[2].rateSource),'','manual override clears stale evidence');assert.notEqual(await page.evaluate(()=>posts[2].requestKey),post.requestKey);
  await page.selectOption('[name=rateBasis]','NET');await fill('quantity','1');await fill('unitRate','56050');assert.match(await page.locator('[data-est-total]').textContent(),/59,000.00.*56,050.00.*2,950.00/);
  await page.evaluate(()=>{fixture.estimatedBills=[{id:'EST1',supplier:'Example Bags',reference:'SHIP1',purpose:'Bags',date:'2026-10-01',currency:'PKR',quantity:1000,unitRate:50,packingSize:'40 kg',specification:'Woven',taxRate:18,whtRate:5,whtBase:'TOTAL',rateBasis:'GROSS',grossAmount:59000,whtAmount:2950,supplierNet:56050,status:'Open',version:1}];});
  await page.evaluate(()=>TT_EXPENSE_DESK.estimates());await page.click('[data-est-edit]');const options=await page.locator('[name=actualBillKey] option').allTextContents();assert(options.some(s=>s.includes('PAST-40')));assert(!options.some(s=>s.includes('USD')));
  await page.selectOption('[name=actualBillKey]','bagSupplierBills|B40');await fill('resolutionNote','Actual bill for same shipment');await page.click('[data-est-resolve]');await page.waitForFunction(()=>posts.length===4);post=await page.evaluate(()=>posts[3]);assert.equal(post.action,'resolve_estimated_bill');assert.equal(post.estimateId,'EST1');assert.equal(post.version,1);
  assert.deepEqual(errors,[]);console.log('PASS estimated bill browser: comparable packing/currency, actual quantity, explicit WHT, totals, source clearing, stable retry, manual save and replacement');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1});
