'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),{chromium}=require('playwright');
module.exports=async()=>{
 const browser=await chromium.launch({headless:true});
 try{
  const page=await browser.newPage({viewport:{width:1440,height:1000}}),errors=[];
  page.on('pageerror',e=>errors.push(e.message));
  await page.route('http://source-dates.test/**',r=>r.fulfill({contentType:'text/html',body:'<div id="entityHome"><div id="homeGrid"></div></div>'}));
  await page.goto('http://source-dates.test/');
  await page.addStyleTag({content:fs.readFileSync('brand-theme.css','utf8')});
  await page.evaluate(()=>{
   localStorage.setItem('tt_accounts_entity','TTI');window.TT_ACCOUNT_ACCESS={role:'Super Admin',csrf:'fixture'};
   const fixture={banks:[{id:'B1',bankName:'Meezan',accountNumber:'869880',currency:'PKR',availableBalance:10000,settings:{defaultPaymentAccount:true}},{id:'CASH|TTI',bankName:'Petty Cash',currency:'PKR',availableBalance:1000}],parties:['Supplier'],payerAccounts:[],payerParties:[],rows:[],brokerRows:[],plans:[],onAccountPayments:[]};
   window.fetch=async()=>({ok:true,json:async()=>fixture});
  });
  // Match production order: Accounts bundle installs defaulting before date enhancement.
  for(const file of ['accounts/accounts-form-layout.js','accounts/accounts-row-controls.js','accounts/cheque-date-ui.js','accounts/payment-plans-ui.js','brand-theme.js'])await page.addScriptTag({content:fs.readFileSync(file,'utf8')});
  await page.evaluate(()=>TT_PAYMENT_PLANS.choose());await page.click('[data-choose-account]');
  const card=page.locator('[data-on-account]'),row=card.locator('[data-source]').first();
  const native=row.locator('[data-source-cheque-date]'),display=native.locator('..').locator('input[type=text]');
  const voucher=card.locator('[data-payment-date]'),voucherDisplay=voucher.locator('..').locator('input[type=text]');
  await display.waitFor();assert.equal(await native.inputValue(),await voucher.inputValue(),'Cheque defaults immediately from this card voucher');
  await voucherDisplay.fill('06-10-2026');await voucherDisplay.press('Tab');assert.equal(await display.inputValue(),'06-10-2026');
  await display.fill('08-10-2026');await display.press('Tab');await voucherDisplay.fill('07-10-2026');await voucherDisplay.press('Tab');assert.equal(await native.inputValue(),'2026-10-08','Manual cheque date survives voucher changes');
  for(const width of [1440,1000,800,390]){
   await page.setViewportSize({width,height:1000});
   const field=await display.boundingBox(),reference=await row.locator('[data-reference-field]').boundingBox(),label=await row.locator('[data-cheque-date-field]').boundingBox();
   assert(field.width>=140,`Readable date at ${width}px, got ${field.width}`);
   assert(field.x>=label.x&&field.x+field.width<=label.x+label.width+1,'Date stays within its field');
   const overlap=Math.min(label.x+label.width,reference.x+reference.width)-Math.max(label.x,reference.x)>1&&Math.min(label.y+label.height,reference.y+reference.height)-Math.max(label.y,reference.y)>1;
   assert(!overlap,`Reference/date do not overlap at ${width}px`);
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'No page overflow');
  }
  await page.setViewportSize({width:1440,height:1000});
  await card.locator('[data-add-source]').click();const second=card.locator('[data-source]').nth(1),secondDate=second.locator('[data-source-cheque-date]');assert.equal(await secondDate.inputValue(),'2026-10-07','Added source uses current voucher date');
  await row.locator('[data-source-method]').selectOption('ONLINE_BANKING');assert.equal(await row.locator('[data-source-date-label]').textContent(),'Online Banking payment date');assert.equal(await display.inputValue(),'08-10-2026','Switching method retains editable date');
  await row.locator('[data-source-bank]').selectOption('CASH|TTI');assert.equal(await row.locator('[data-cheque-date-field]').isVisible(),false);
  await row.locator('[data-source-bank]').selectOption('B1');assert.equal(await display.inputValue(),'08-10-2026');
  await row.locator('[data-source-type]').selectOption('THIRD_PARTY');assert.equal(await row.locator('[data-cheque-date-field]').isVisible(),false);
  await row.locator('[data-source-type]').selectOption('BANK');assert.equal(await display.inputValue(),'08-10-2026');
  assert.deepEqual(errors,[]);console.log('PASS payment-source date widths, alignment, defaulting, explicit dates, extra sources, online banking, petty cash and third parties');
 }finally{await browser.close();}
};
if(require.main===module)module.exports().catch(e=>{console.error(e);process.exitCode=1;});
