'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),{chromium}=require('playwright');
(async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.TT_CHROMIUM_PATH?{executablePath:process.env.TT_CHROMIUM_PATH}:{})});
 try{
  const page=await browser.newPage(),reads=[],writes=[],errors=[];let releaseDate,markDateHeld;const dateHeld=new Promise(resolve=>markDateHeld=resolve);
  await page.clock.setFixedTime(new Date('2026-10-18T12:00:00Z'));
  page.on('pageerror',e=>errors.push(e.message));
  await page.route('http://date-recon.test/**',async route=>{
   const request=route.request(),url=new URL(request.url());
   if(url.pathname.includes('bank_reconciliation.php')){
    if(request.method()!=='GET'){writes.push(request.postData());return route.abort();}
    const date=url.searchParams.get('statementDate');reads.push(date);
    if(date==='2026-09-29')await new Promise(resolve=>{releaseDate=resolve;markDateHeld();});
    const transactions=[{key:'SEP|0',date:'2026-09-30',journalId:'SEP',publicPostId:'POST-SEP',debit:100,credit:0,reference:'SEP',narration:'September receipt'},{key:'OCT|0',date:'2026-10-15',journalId:'OCT',publicPostId:'POST-OCT',debit:20,credit:0,reference:'OCT',narration:'October receipt'}].filter(r=>r.date<=date);
    return route.fulfill({json:{ok:true,revision:1,banks:[{id:'B1',display:'QA Bank',currency:'PKR',isDefault:true}],bank:{id:'B1',currency:'PKR'},statementDate:date,statementBalance:null,bookBalance:transactions.reduce((s,r)=>s+r.debit,0),transactions,history:[],canEdit:true}});
   }
   return route.fulfill({contentType:'text/html',body:'<!doctype html><button id="open">Reconciliation</button><input id="unrelated" value="Keep this draft"><script>window.TT_ACCOUNT_ACCESS={csrf:"fixture"};</script>'});
  });
  await page.goto('http://date-recon.test/accounts/');
  await page.addScriptTag({content:fs.readFileSync('brand-theme.js','utf8')});
  await page.addScriptTag({content:fs.readFileSync('accounts/bank-reconciliation-ui.js','utf8')});
  await page.locator('#open').evaluate(el=>el.onclick=()=>window.TT_BANK_RECONCILIATION.open());
  await page.locator('#open').click();
  const date=page.getByRole('textbox',{name:'Statement date DD-MM-YYYY',exact:true});await date.waitFor();
  await date.press('ControlOrMeta+A');await date.pressSequentially('30092026');
  assert.equal(await page.locator('[name=statementDate]').inputValue(),'2026-09-30');
  const before=reads.length;
  await date.press('Tab');
  await page.waitForFunction(()=>document.querySelector('[data-value=book]')?.textContent==='PKR 100.00',{},{timeout:3000});
  assert.equal(reads.filter(x=>x==='2026-09-30').length,1,'Typed date must send exactly one committed change');
  assert.equal(reads.length,before+1);
  assert.equal(await page.locator('tbody').textContent().then(t=>t.includes('POST-OCT')),false,'October entries must disappear from September statement');
  assert.equal(await page.locator('#unrelated').inputValue(),'Keep this draft');
  const count=reads.length;await date.focus();await date.press('Tab');assert.equal(reads.length,count,'Unchanged focus/blur must not reload');
  // A native picker change forwards once too; its subsequent blur adds nothing.
  await page.locator('[name=statementDate]').evaluate(el=>{el.value='2026-10-18';el.dispatchEvent(new Event('change',{bubbles:true}));});
  await page.waitForFunction(()=>document.querySelector('[data-value=book]')?.textContent==='PKR 120.00');
  assert.equal(reads.filter(x=>x==='2026-10-18').length>=1,true);
  const current=reads.length;await date.focus();await date.press('Tab');assert.equal(reads.length,current);
  await date.press('ControlOrMeta+A');await date.pressSequentially('29092026');await date.press('Tab');
  await page.locator('[data-save]:disabled').waitFor();
  assert.equal(await page.locator('[data-value=book]').textContent(),'PKR 120.00','Fixture holds the new cutoff response');
  let timer;try{await Promise.race([dateHeld,new Promise((_,reject)=>{timer=setTimeout(()=>reject(Error('New cutoff request was not received')),3000)})]);}finally{clearTimeout(timer);}
  assert.equal(typeof releaseDate,'function');releaseDate();
  await page.waitForFunction(()=>document.querySelector('[data-value=book]')?.textContent==='PKR 0.00');
  assert.deepEqual(writes,[]);assert.deepEqual(errors,[]);
  console.log('Reconciliation native date: typed cutoff loads once, October excluded, unchanged blur quiet, picker preserved, draft retained, zero POSTs passed');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
