'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),{chromium}=require('playwright');
(async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.TT_BROWSER_EXECUTABLE?{executablePath:process.env.TT_BROWSER_EXECUTABLE}:{})});
 try{
  const page=await browser.newPage({viewport:{width:1440,height:1000}}),errors=[];
  page.on('pageerror',e=>errors.push(e.message));
  await page.goto(process.argv[2]+'/accounts/index.html');await page.locator('#openAssets').click();await page.locator('#faNew').click();
  for(const [name,value] of Object.entries({name:'Karachi Property',assetTag:'BROWSER-001',ownerName:'Director One',unitNo:'Plot 17',address:'Karachi project',purchaseDate:'2025-01-01',cost:'1000'}))await page.locator('[name='+name+']').fill(value);
  await page.locator('[name=countryId]').selectOption('PK');await page.locator('[name=cityId]').selectOption('KHI');
  await page.locator('[name=paymentPattern]').selectOption('MONTHLY');await page.locator('[name=firstDueDate]').fill('2026-10-31');await page.locator('[name=monthlyAmount]').fill('300');await page.locator('[name=instalmentCount]').fill('2');
  await page.locator('#faAddHistory').click();await page.locator('[data-number]').fill('1');await page.locator('[data-date]').fill('2026-06-30');await page.locator('[data-amount]').fill('400');await page.locator('[data-source]').fill('Previous bank payment');
  await page.setViewportSize({width:390,height:844});assert(await page.locator('#ttAssetsWindow .tt-window').evaluate(el=>el.scrollWidth<=el.clientWidth),'Property form fits phone width');
  await page.setViewportSize({width:1440,height:1000});await page.locator('#faRegister [type=submit]').click();await page.locator('#faConfirmHome').click();await page.locator('#faPay').click();
  await page.locator('[name=assetId]').selectOption({label:'BROWSER-001 · KARACHI PROPERTY · PKR 600.00 remaining'});
  assert.equal(await page.locator('#faBankId').inputValue(),'B1');assert.equal(await page.locator('#faMethod').inputValue(),'CHEQUE');
  await page.locator('#faCash').check();await page.locator('#faCashAmount').fill('150');await page.locator('#faBankAmount').fill('150');await page.locator('#faMethod').selectOption('ONLINE_BANKING');
  assert(!await page.locator('#faCheque').isVisible(),'Online payment hides cheque number');
  const books=process.argv[3],before=fs.readFileSync(books),count=Object.keys(JSON.parse(before).journals).length;
  await page.locator('#faPayment [type=submit]').click();await page.locator('[data-review-post]').waitFor();
  assert.deepEqual(fs.readFileSync(books),before,'Green review leaves original books byte-exact');
  await page.locator('[data-review-cancel]').click();await page.locator('#faPayment [type=submit]').waitFor({state:'visible'});
  assert.equal(await page.locator('#faCashAmount').inputValue(),'150');assert.deepEqual(fs.readFileSync(books),before,'Cancelled review posts nothing');
  await page.locator('#faPayment [type=submit]').click();await page.locator('[data-review-post]').click();await page.locator('#tt-post-confirmation').waitFor();
  assert.equal(Object.keys(JSON.parse(fs.readFileSync(books)).journals).length,count+1,'One confirmed split payment');
  assert(await page.locator('#tt-post-confirmation [data-edit]').isDisabled(),'Asset money cannot be amended outside its owning register');assert(await page.locator('#tt-post-confirmation [data-print]').isVisible());
  await page.locator('#tt-post-confirmation [data-close]').click();await page.locator('#faConfirmHome').click();await page.locator('#faPay').click();
  await page.locator('[name=assetId]').selectOption({label:'BROWSER-001 · KARACHI PROPERTY · PKR 300.00 remaining'});await page.locator('#faMethod').selectOption('ONLINE_BANKING');
  await page.locator('#faPayment [type=submit]').click();await page.locator('[data-review-post]').click();await page.locator('#tt-post-confirmation').waitFor();
  await page.locator('#tt-post-confirmation [data-close]').click();await page.locator('#faConfirmHome').click();
  assert(!((await page.locator('#ttAssetsWindow').innerText()).includes('KARACHI PROPERTY')),'Fully paid private property leaves Accounts view');
  assert.equal(Object.keys(JSON.parse(fs.readFileSync(books)).journals).length,count+2);assert.deepEqual(errors,[]);
  console.log('PASS native asset browser: registration/history, phone fit, conditional bank fields, zero-write green review/cancel, one split payment, final payment and private-property restriction');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
