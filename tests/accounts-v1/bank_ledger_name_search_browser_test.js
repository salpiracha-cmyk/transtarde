'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),{chromium}=require('playwright');
(async()=>{const browser=await chromium.launch({headless:true});try{
 const page=await browser.newPage({viewport:{width:1440,height:900}}),errors=[];
 page.on('pageerror',e=>errors.push(e.message));
 await page.route('https://ledger.test/**',r=>r.fulfill({contentType:'text/html',body:'<!doctype html><html><body></body></html>'}));
 await page.goto('https://ledger.test/');
 const native=fs.readFileSync('accounts/Transtrade_Accounts_Master_V1.html','utf8');
 await page.addStyleTag({content:[...native.matchAll(/<style\b[^>]*>([\s\S]*?)<\/style>/gi)].map(m=>m[1]).join('\n')});
 await page.addStyleTag({content:fs.readFileSync('brand-theme.css','utf8')});
 await page.evaluate(()=>{
  localStorage.setItem('tt_accounts_entity','TTI');window.TT_ACCOUNT_ACCESS={super:true,masters:{}};window.reads=[];window.writes=[];window.printed='';
  const accounts=[{code:'CASH|TTI',name:'Office Petty Cash'},{code:'CASH|TTI|STAFF|H',name:'Cash with Hassan'},{code:'1120',name:'All Cash · Office, Staff and Mills'},{code:'BANK|M1',name:'Meezan Bank · 001122 · PKR · Current account'},{code:'BANK|M2',name:'Meezan Bank · 003344 · PKR · Savings account'},{code:'BANK|ZERO',name:'Meezan Bank · 005566 · USD · Dollar account'}];
  window.fetch=async(url,options={})=>{if(String(url).includes('bank_accounts.php'))return {ok:true,json:async()=>({ok:true,entity:'TTI',accounts:[],cash:{id:'CASH|TTI',accountTitle:'Office Petty Cash',currency:'PKR',bookBalance:57180,settings:{active:true}},balancesByCurrency:{PKR:57180},paymentPlanningCurrency:'PKR',paymentPlanningFunds:0})};if(options.method==='POST')writes.push(options.body);const p=Object.fromEntries(new URL(url,location.href).searchParams);reads.push(p);const bank=accounts.find(a=>a.code===p.account),amount=p.account==='CASH|TTI'?57180:p.account==='CASH|TTI|STAFF|H'?2000:p.account==='1120'?153280:p.account==='BANK|M1'?112:p.account==='BANK|M2'?222:0;
   return {ok:true,json:async()=>({ok:true,entity:'TTI',accounts,parties:['Meezan Bank Supplies'],partyAliases:[{name:'Meezan Bank Supplies',code:'SUP-1'}],currency:bank?.code==='BANK|ZERO'?'USD':'PKR',opening:0,closing:bank?amount:null,rows:bank&&amount?[{voucher:'POST-2026-00001',date:'2026-10-01',account:bank.code,accountName:bank.name,debit:amount,credit:0,balance:amount,narration:'Fixture bank movement'}]:[]})};};
  window.TT_PRINT={create:()=>({document:{write:s=>printed=s,close(){}},print(){}})};
 });
 for(const file of ['accounts/accounts-uppercase.js','accounts/accounts-clean-ui.js','accounts/master-autocomplete.js','brand-theme.js','accounts/all-ledgers-ui.js','accounts/bank-accounts-ui.js'])await page.addScriptTag({content:fs.readFileSync(file,'utf8')});
 await page.evaluate(()=>TT_ALL_LEDGERS.open());
 const input=()=>page.getByRole('combobox',{name:'Party or bank account',exact:true});
 await input().fill('meezan bank');
 assert.equal(await page.locator('.tt-party-menu:not([hidden]) .tt-option-row').count(),4,'Typing must expose all three bank accounts and the separate supplier as visible choices');
 assert.equal(await page.evaluate(()=>reads.length),1,'Typing must not replace the statement');
 assert.equal(await page.locator('.tt-party-menu:not([hidden]) .tt-option-edit,.tt-party-menu:not([hidden]) .tt-option-add').count(),0,'Ledger lookup cannot edit/create master records');
 await page.getByRole('button',{name:'Meezan Bank · 001122 · PKR · Current account · Bank account',exact:true}).click();
 await page.waitForFunction(()=>reads.at(-1).account==='BANK|M1'&&document.querySelector('#tal-result')?.textContent.includes('112.00'));
 assert.equal(await page.evaluate(()=>reads.at(-1).party),'');assert.equal(await page.locator('#tal-view').inputValue(),'party');
 await page.locator('#tal-print').click();assert.match(await page.evaluate(()=>printed),/001122/);assert(!/003344/.test(await page.evaluate(()=>printed)));
 await input().fill('003344');await input().press('ArrowDown');await input().press('Enter');
 await page.waitForFunction(()=>reads.at(-1).account==='BANK|M2'&&document.querySelector('#tal-result')?.textContent.includes('222.00'));
 assert.equal(await page.evaluate(()=>reads.length),3,'Keyboard selection must request only the chosen bank, once: '+JSON.stringify(await page.evaluate(()=>reads)));
 await input().fill('SUP-1');assert.equal(await page.getByRole('button',{name:'Meezan Bank Supplies',exact:true}).count(),1,'Party code search must retain the canonical name');
 await input().press('ArrowDown');await input().press('Enter');await page.waitForFunction(()=>reads.at(-1).party==='Meezan Bank Supplies');
 assert.equal(await page.evaluate(()=>reads.at(-1).account),'');
 await input().fill('005566');await page.getByRole('button',{name:'Meezan Bank · 005566 · USD · Dollar account · Bank account',exact:true}).click();
 await page.waitForFunction(()=>reads.at(-1).account==='BANK|ZERO'&&document.querySelector('#tal-result')?.textContent.includes('USD'));
 await input().fill('Office Petty Cash');await page.getByRole('button',{name:'Office Petty Cash · Cash account',exact:true}).click();
 await page.waitForFunction(()=>reads.at(-1).account==='CASH|TTI'&&document.querySelector('#tal-result')?.textContent.includes('57,180.00'));
 assert.equal(await page.evaluate(()=>reads.at(-1).party),'');
 await page.locator('#tal-print').click();assert.match(await page.evaluate(()=>printed),/Office Petty Cash/);assert.match(await page.evaluate(()=>printed),/57,180.00/);assert(!/153,280/.test(await page.evaluate(()=>printed)));
 const downloadPromise=page.waitForEvent('download');await page.locator('#tal-export').click();const download=await downloadPromise;const exported=fs.readFileSync(await download.path()).toString();assert.match(exported,/Office Petty Cash/);assert.match(exported,/>57180</);assert(!/>153280</.test(exported));
 await page.locator('#tal-close').click();await page.evaluate(()=>TT_BANK_ACCOUNTS_UI.open());await page.getByRole('button',{name:'Office Petty Cash Ledger',exact:true}).click();await page.waitForFunction(()=>document.querySelector('#tal-account')?.value==='CASH|TTI');assert.equal(await page.locator('#tal-account').inputValue(),'CASH|TTI');assert.equal(await page.getByRole('button',{name:'+ Bank Entry',exact:true}).isVisible(),false);
 for(const width of [1440,390]){await page.setViewportSize({width,height:900});assert(await page.locator('.tal-box').evaluate(el=>el.scrollWidth<=el.clientWidth+2),'Cash ledger fits '+width);if(process.env.TT_QA_OUTPUT){fs.mkdirSync(process.env.TT_QA_OUTPUT,{recursive:true});await page.screenshot({path:process.env.TT_QA_OUTPUT+'/office-cash-'+width+'.png'});}}
 await page.evaluate(()=>TT_ALL_LEDGERS.open());
 for(const width of [1440,800,390]){
  await page.setViewportSize({width,height:900});await input().fill('meezan bank');
  assert.equal(await page.locator('.tt-party-menu:not([hidden]) .tt-option-row').count(),4);
  const menu=await page.locator('.tt-party-menu:not([hidden])').boundingBox();assert(menu.x>=0&&menu.x+menu.width<=width+1&&menu.y>=0&&menu.y+menu.height<=900,'Choices fit viewport '+width);
  const box=await page.locator('.tal-box').boundingBox();assert(box.x>=0&&box.x+box.width<=width+1,'Dialog fits viewport '+width);
  assert(await page.locator('.tal-box').evaluate(el=>el.scrollWidth<=el.clientWidth+2),'Ledger controls fit '+width);
 }
 if(process.env.TT_QA_OUTPUT){fs.mkdirSync(process.env.TT_QA_OUTPUT,{recursive:true});await page.screenshot({path:process.env.TT_QA_OUTPUT+'/bank-name-mobile.png'});}
 await input().press('Escape');assert.equal(await page.locator('.tt-party-menu:not([hidden])').count(),0);
 await page.locator('#tal-close').click();assert.equal(await page.locator('#tt-all-ledgers').count(),0);
 assert.deepEqual(await page.evaluate(()=>writes),[]);assert.deepEqual(errors,[]);
 console.log('PASS real shared controls: visible bank/name/code choices, distinct click/keyboard statements, zero-activity currency, print identity, desktop/tablet/mobile fit cash card/name/direct routes, office print/Excel scope, separate staff/control choices and no writes');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1});
