'use strict';
const fs=require('fs'),assert=require('node:assert/strict'),{chromium}=require('playwright');
(async()=>{
 const browser=await chromium.launch({headless:true});
 try{
  const page=await browser.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.setContent('<div class="shell"><main id="main"></main></div>');
  for(const path of ['exports/app.css','exports/release-theme.css','brand-theme.css'])await page.addStyleTag({content:fs.readFileSync(path,'utf8')});
  await page.evaluate(()=>{
   window.state={fi:[{id:'FI1',number:'MBL-EXP-719124-24092026',date:'2026-09-25',type:'Advance',customer:'TRANS GRAINS FOODSTUFF TRADING L.L.C',paymentTerms:'100% ADVANCE',bank:'TTI — Transtrade International · Meezan bank limited · Joria bazar branch, Karachi, Pakistan.',currency:'USD',value:108000,allocations:[{id:'A1',contractRef:'TG/AMT/13',lotRef:'TG/AMT/13/L01',amount:108000}]},{id:'FI2',number:'LARGE-VALUE',date:'2026-09-22',type:'TG Unallocated',customer:'Customer',bank:'Bank',currency:'USD',value:980000000.12,allocations:[]}]};
   window.renderNav=window.refreshFICreditAdviceTags=window.showTgUnallocatedPayments=()=>{};
   window.lcRegisterRows=()=>[];window.fiUsed=f=>f.allocations.reduce((n,a)=>n+a.amount,0);
   window.num=n=>Number(n)||0;window.money=(n,c)=>c+' '+Number(n).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});window.fmt=s=>s;window.esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
   window.original=JSON.stringify(state);
  });
  const native=fs.readFileSync('exports/app.js','utf8').split('\n').find(l=>l.startsWith('function renderFI(){'));assert(native,'Use the actual FI renderer');
  await page.addScriptTag({content:native+'\nrenderFI();'});
  const table=page.getByRole('table').first();
  for(const width of [1363,1024,390]){
   await page.setViewportSize({width,height:936});
   const rows=table.locator('tbody>tr:not(.allocationRow)');assert.equal(await rows.count(),2);
   for(const row of await rows.all())for(const index of [6,7,8]){
    const cell=row.locator('td').nth(index),geometry=await cell.evaluate(el=>{const range=document.createRange();range.selectNodeContents(el);return {lines:range.getClientRects().length,text:el.textContent,align:getComputedStyle(el).textAlign,scroll:el.scrollWidth,client:el.clientWidth}});
    assert.equal(geometry.lines,1,'Currency and all digits stay on one line: '+JSON.stringify({width,...geometry}));assert.equal(geometry.align,'right');assert(geometry.scroll<=geometry.client+1,'Amount is not clipped');
   }
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true,'Only the table wrapper may scroll horizontally');
   assert.equal(await table.locator('thead th').nth(6).evaluate(el=>getComputedStyle(el).textAlign),'right');
  }
  assert.equal(await table.locator('tbody>tr').first().locator('td').nth(6).textContent(),'USD 108,000.00');
  assert.equal(await table.locator('tbody>tr:not(.allocationRow)').nth(1).locator('td').nth(6).textContent(),'USD 980,000,000.12');
  const allocation=table.locator('.allocationRow');assert.equal(await allocation.locator('td').nth(2).getAttribute('colspan'),null,'Allocation amount occupies the same Total column');
  assert.equal(await page.evaluate(()=>JSON.stringify(state)===original),true,'Renderer preserves source records');assert.deepEqual(errors,[]);
  await page.close();console.log('PASS native FI register: intact/right-aligned currency values, allocation Total column, large amounts, desktop/phone contained scrolling and unchanged sources');
 }finally{await browser.close()}
})().catch(e=>{console.error(e);process.exitCode=1});
