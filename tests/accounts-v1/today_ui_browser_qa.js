const fs=require('node:fs');
const path=require('node:path');
const assert=require('node:assert/strict');
const {chromium}=require('playwright');
const root=path.resolve(__dirname,'../..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');
const output=path.join(root,'tmp/qa');fs.mkdirSync(output,{recursive:true});
const bank={id:'UI-BANK',bankName:'Test Bank',accountTitle:'Test Company',currency:'USD',accountNumber:'123456',iban:'TEST-IBAN',swift:'TESTSWIFT',accountType:'Company Account',isDefault:true,retentionAccount:true,status:'Active',country:'Pakistan',customHistory:'preserve'};
const company={id:'UI-COMPANY',values:['Test Company','TTI','Pakistan','Test address','Exporter','','',JSON.stringify([{name:'Test Owner',share:100}]),'123','','','','',JSON.stringify([bank]),'[]','[]','[]','{}']};
const session={id:'ui-super',name:'UI Test',username:'ui-test',role:'Super Admin',csrf:'test',permissions:{},masterAccess:true};
let posted;
(async()=>{
 const browser=await chromium.launch({headless:true});
 try{
  let page=await browser.newPage({viewport:{width:900,height:740}});
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.route('https://ui.test/**',async route=>{
   const url=new URL(route.request().url());
   if(url.pathname.includes('/api/')){
    const body=route.request().postDataJSON();
    if(body?.type==='companies'&&body.action==='update'){posted=body;company.values=body.values;}
    const data={ok:true,masters:{companies:[company]},options:{currencies:['PKR','USD'],countries:['Pakistan']},users:[],requests:[],approvals:[]};
    return route.fulfill({json:data});
   }
   if(url.pathname==='/index.php')return route.fulfill({contentType:'text/html',body:read('index.html').replace(/<script[\s\S]*?<\/script>/g,'').replace('</head>',`<script>window.TT_SESSION=${JSON.stringify(session)};</script></head>`)});
   const file=path.join(root,url.pathname.slice(1));
   if(fs.existsSync(file)&&fs.statSync(file).isFile())return route.fulfill({body:fs.readFileSync(file),contentType:url.pathname.endsWith('.css')?'text/css':'text/javascript'});
   return route.fulfill({status:404,body:''});
  });
  await page.goto('https://ui.test/index.php?view=masters&from=accounts');
  await page.addScriptTag({content:read('brand-theme.js')});
  await page.addScriptTag({content:read('admin/app.js')});
  await page.waitForSelector('[data-edit-master="UI-COMPANY"]');
  // Drawer is reachable in a half-window and its controls are above the dismiss overlay.
  await page.locator('#menuButton').click();
  await page.waitForTimeout(250);
  assert.equal(await page.locator('#menuButton').getAttribute('aria-expanded'),'true');
  await page.locator('#sidebar [data-view="masters"]').click();
  assert.equal(await page.locator('#menuButton').getAttribute('aria-expanded'),'false');
  await page.locator('#menuButton').click();await page.keyboard.press('Escape');
  assert.equal(await page.locator('#menuButton').getAttribute('aria-expanded'),'false');
  assert.equal(await page.evaluate(()=>document.activeElement.id),'menuButton');
  // Both master routes use the same company form. Its Banks tab shows existing editable accounts.
  await page.locator('[data-edit-master="UI-COMPANY"]:visible').click();
  await page.locator('[data-company-editor-tab="banks"]').click();
  await page.locator('[data-bank-name]').waitFor({state:'visible'});
  assert.equal(await page.locator('[data-bank-number]').inputValue(),'123456');
  await page.locator('[data-bank-branch]').fill('Updated branch');
  await page.locator('#masterForm button[type="submit"]').click();
  await page.waitForFunction(()=>!document.getElementById('masterDialog').open);
  assert.ok(posted,'company form saves through the existing master endpoint');
  const saved=JSON.parse(posted.values[13])[0];
  assert.equal(saved.branch,'Updated branch');assert.equal(saved.isDefault,true);assert.equal(saved.customHistory,'preserve');assert.equal(saved.retentionAccount,true);
  // Opening Edit from the company detail Banks tab opens the same Banks editor.
  await page.locator('[data-open-master="UI-COMPANY"]').click();
  await page.locator('[data-company-tab="banks"]').click();
  await page.locator('[data-edit-master="UI-COMPANY"]:visible').click();
  await page.locator('[data-bank-name]').waitFor({state:'visible'});
  await page.screenshot({path:path.join(output,'company-banks-edit.png'),fullPage:true});
  await page.locator('[data-close-dialog="masterDialog"]').first().click();
  await page.setViewportSize({width:600,height:740});await page.locator('#menuButton').click();await page.waitForTimeout(250);
  await page.screenshot({path:path.join(output,'control-centre-narrow-menu.png'),fullPage:true});
  await page.locator('#closeNavigation').click();
  await page.setViewportSize({width:1400,height:900});await page.waitForFunction(()=>!document.getElementById('sidebar').inert);assert.equal(await page.evaluate(()=>document.getElementById('sidebar').inert),false);
  // Reused Accounts windows reset their internal scroll; normal input changes preserve position.
  await page.evaluate(()=>{document.body.insertAdjacentHTML('beforeend','<div class="tt-layer" hidden><div role="dialog"><div class="tt-window-body" style="height:100px;overflow:auto"><input><div style="height:1000px"></div></div></div></div>');});
  await page.evaluate(()=>{document.querySelector('.tt-layer').hidden=false;});
  await page.waitForTimeout(120);await page.locator('.tt-window-body').evaluate(el=>el.scrollTop=300);
  await page.evaluate(()=>{const host=document.querySelector('.tt-layer');host.hidden=true;host.hidden=false;});
  await page.waitForTimeout(120);assert.equal(await page.locator('.tt-window-body').evaluate(el=>el.scrollTop),0);
  await page.locator('.tt-window-body').evaluate(el=>el.scrollTop=200);await page.locator('.tt-window-body input').evaluate(el=>{el.value='edited';el.dispatchEvent(new Event('input',{bubbles:true}));});
  await page.waitForTimeout(120);assert.equal(await page.locator('.tt-window-body').evaluate(el=>el.scrollTop),200);
  // Actual Export editor starts at its title and step navigation retains a visible title.
  assert.deepEqual(errors,[]);await page.close();
  page=await browser.newPage({viewport:{width:1400,height:900}});page.on('pageerror',e=>errors.push(e.message));
  await page.route('https://exports.test/**',r=>r.fulfill({body:'<!doctype html><html><body><div id="app"></div><div id="printRoot"></div></body></html>',contentType:'text/html'}));
  await page.goto('https://exports.test/');await page.addStyleTag({content:read('exports/app.css')});
  await page.addScriptTag({content:read('brand-theme.js')});
  await page.addScriptTag({content:read('exports/app.js').replace('mount();restoreContractCheckpoint();','window.__UI_TEST__={startContract};mount();')});
  await page.evaluate(()=>{document.getElementById('main').style.minHeight='1800px';window.scrollTo(0,600);window.__UI_TEST__.startContract();});
  await page.waitForTimeout(150);
  const title=page.locator('#main h2').first();assert.equal(await title.textContent(),'Sales Contract');
  const rect=await title.boundingBox();assert.ok(rect.y>=0&&rect.y<220,`Sales Contract heading appears at the top, y=${rect.y}`);
  await page.screenshot({path:path.join(output,'sales-contract-opens-at-top.png'),fullPage:true});
  await page.locator('[data-step="2"]').click();await page.waitForTimeout(120);
  const stepRect=await page.locator('#main h2').first().boundingBox();assert.ok(stepRect.y>=0&&stepRect.y<220,'changing a step keeps the form title visible');
  await page.evaluate(()=>window.scrollTo(0,400));await page.locator('#backStep').click();await page.waitForTimeout(120);
  const backRect=await page.locator('#main h2').first().boundingBox();assert.ok(backRect.y>=0&&backRect.y<220,'Back returns to the full form heading');
  const failed=await browser.newPage();
  await failed.route('https://failed.test/**',r=>r.fulfill({body:'<div id="app"></div><div id="printRoot"></div>',contentType:'text/html'}));
  await failed.goto('https://failed.test/');
  await failed.evaluate(()=>{
    window.TT_MODULE_ACCESS={moduleId:'exports',module:'Exports'};
    window.XMLHttpRequest=class{open(){}send(){this.status=503;this.responseText='unavailable'}};
    localStorage.setItem('transtrade_export_v3_operational',JSON.stringify({shipments:[],contracts:[],preserveMarker:true}));
  });
  const bootstrap=read('module.php').match(/<script id="tt-shared-operations-bootstrap">([\s\S]*?)<\/script>/)[1];
  await failed.addScriptTag({content:bootstrap});await failed.addScriptTag({content:read('exports/app.js')});
  await failed.locator('#ttExportsLoadError').waitFor({state:'visible'});
  assert.equal(await failed.locator('#app').innerHTML(),'','failed initial read does not mount an empty Exports book');
  assert.equal(await failed.evaluate(()=>JSON.parse(localStorage.getItem('transtrade_export_v3_operational')).preserveMarker),true,'failed read preserves cached data');
  assert.equal(await failed.evaluate(()=>window.TT_SHARED_SYNC.saveNow().then(()=>false,()=>true)),true,'saving is blocked before a successful read');
  await failed.screenshot({path:path.join(output,'exports-load-failure.png')});await failed.close();
  assert.deepEqual(errors,[]);
  console.log('PASS company banks edit/save, detail tab routing, half-window/mobile navigation, reused dialog scroll and Sales Contract opening position');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1});
