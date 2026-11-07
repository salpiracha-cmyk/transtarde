'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),{chromium}=require('playwright');
(async()=>{const browser=await chromium.launch({headless:true});try{
 const context=await browser.newContext({viewport:{width:1280,height:900}}),writes=[],errors=[];
 const native=fs.readFileSync('accounts/Transtrade_Accounts_Master_V1.html','utf8');
 const scripts=['accounts/company-context-guard.js','accounts/accounts-uppercase.js','accounts/accounts-clean-ui.js','brand-theme.js'].map(file=>'<script>'+fs.readFileSync(file,'utf8')+'</script>').join('');
 const fixture='<form id="fixtureForm"><label>Unsaved note<input id="fixtureNote"></label><button id="fixturePost">Post fixture</button></form><script>document.querySelector("#fixtureForm").onsubmit=e=>{e.preventDefault();fetch("/api/bank_entries.php",{method:"POST",body:JSON.stringify({entity:localStorage.getItem("tt_accounts_entity")})}).catch(()=>{});};</script>';
 const html=native.replace('</body>',fixture+scripts+'</body>');
 await context.addInitScript(()=>{window.TT_ACCOUNT_ACCESS={user:'Fixture',role:'Super Admin',entities:['TTI','BRM','TG'],permissions:'all',super:true};});
 await context.route('https://context.test/**',route=>{const req=route.request();if(req.method()==='POST'){writes.push(JSON.parse(req.postData()));return route.fulfill({contentType:'application/json',body:'{"ok":true}'});}return route.fulfill({contentType:'text/html',body:html});});
 const first=await context.newPage(),second=await context.newPage();for(const page of [first,second])page.on('pageerror',e=>errors.push(e.message));
 await first.goto('https://context.test/accounts/');await second.goto('https://context.test/accounts/');
 await first.locator('#fixtureNote').fill('Retained draft');
 await second.locator('.entityBtn[data-entity=TG]').click();
 await first.getByRole('dialog',{name:'Company changed in another tab'}).waitFor();
 assert.equal(await first.locator('.entityBtn.active').getAttribute('data-entity'),'TTI');
 assert.equal(await first.locator('#fixtureNote').inputValue(),'RETAINED DRAFT');
 await first.keyboard.press('Escape');assert(await first.locator('#ttCompanyChanged').isVisible(),'Escape cannot dismiss a mismatched company guard');
 await first.evaluate(()=>document.querySelector('#fixtureForm').requestSubmit());
 const error=await first.evaluate(()=>fetch('/api/bank_entries.php',{method:'POST',body:'{"entity":"TG"}'}).then(()=>'',e=>e.message));
 assert.match(error,/Company changed/);const objectError=await first.evaluate(()=>fetch(new URL('/api/bank_entries.php',location.href),{method:'POST',body:'{}'}).then(()=>'',e=>e.message));assert.match(objectError,/Company changed/);assert.deepEqual(writes,[],'Neither native submit nor delayed fetch can send a wrong-company posting');
 // Returning the other tab to the original company leaves this unsaved form intact.
 await second.locator('.entityBtn[data-entity=TTI]').click();await first.locator('#ttCompanyChanged').waitFor({state:'detached'});assert.equal(await first.locator('#fixtureNote').inputValue(),'RETAINED DRAFT');
 await second.locator('.entityBtn[data-entity=TG]').click();await first.locator('#ttCompanyChanged').waitFor();
 await first.setViewportSize({width:390,height:844});const box=await first.locator('#ttCompanyChanged').boundingBox();assert(box.x>=0&&box.x+box.width<=391&&box.y>=0&&box.y+box.height<=845);
 if(process.env.TT_QA_OUTPUT){fs.mkdirSync(process.env.TT_QA_OUTPUT,{recursive:true});await first.screenshot({path:process.env.TT_QA_OUTPUT+'/company-context-mobile.png'});}
 await first.getByRole('button',{name:'Reload Accounts',exact:true}).click();await first.waitForLoadState();
 assert.equal(await first.locator('.entityBtn.active').getAttribute('data-entity'),'TG');assert.equal(await first.locator('#ttCompanyChanged').count(),0);
 await first.locator('#fixturePost').click();await first.waitForFunction(()=>document.querySelector('#fixtureForm')!==null);assert.deepEqual(writes,[{entity:'TG'}]);
 // Same-tab company selection remains available and changes its native heading.
 await first.locator('.entityBtn[data-entity=BRM]').click();assert.equal(await first.locator('.entityBtn.active').getAttribute('data-entity'),'BRM');assert.equal(await first.locator('#ttCompanyChanged').count(),0);
 assert.deepEqual(errors,[]);console.log('PASS two real tabs: stale-company modal, retained draft, blocked submit/delayed fetch, safe return/reload, same-tab switching and mobile fit');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1});
