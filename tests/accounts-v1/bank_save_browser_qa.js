'use strict';
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require('playwright');
const root=path.resolve(__dirname,'../..'),read=p=>fs.readFileSync(path.join(root,p),'utf8');
(async()=>{
 const browser=await chromium.launch({headless:true});
 try{
  for(const viewport of [{width:900,height:740},{width:390,height:844}]){
   const page=await browser.newPage({viewport});
   const bank={id:'fixture-bank',bankName:'Fixture Bank',accountTitle:'Fixture Company',currency:'PKR',accountNumber:'fixture-only',accountType:'Company Account',depositType:'CURRENT',isDefault:true,retentionAccount:false,status:'Active'};
   const company={id:'fixture-company',values:['Fixture Company','TTI','Pakistan','Fixture address','','','',JSON.stringify([{name:'Fixture Owner',share:100}]),'','','','','',JSON.stringify([bank]),'[]','[]','[]','{}']};
   const session={id:'fixture-owner',name:'Fixture',role:'Super Admin',csrf:'fixture',permissions:{},masterAccess:true};
   let reject=false,posts=0,release=null,hold=false,started=null;
   const errors=[];page.on('pageerror',e=>errors.push(e.stack));
   await page.route('https://bank-save.test/**',async route=>{
    const url=new URL(route.request().url());
    if(url.pathname.includes('/api/')){
     const body=route.request().postDataJSON();
     if(body?.type==='companies'&&body.action==='update'){
      posts++;
      if(reject)return route.fulfill({status:422,json:{ok:false,error:'Fixture server rejected the save. Please correct the account.'}});
      if(hold)await new Promise(resolve=>{release=resolve;started();});
      company.values=body.values;
      const rows=JSON.parse(company.values[13]);
      const parent=rows.find(row=>row.id==='fixture-bank');
      if(parent.retentionEnabled&&!rows.some(row=>row.retentionParentBankId===parent.id))rows.push({id:'fixture-retention',retentionParentBankId:parent.id,bankName:'Fixture Bank Retention Account',accountTitle:parent.accountTitle,currency:'USD',accountType:'Company Account',depositType:'CURRENT',accountNumber:'',iban:'',retentionAccount:true,isDefault:false,status:'Active'});
      company.values[13]=JSON.stringify(rows);
     }
     return route.fulfill({json:{ok:true,masters:{companies:[company]},options:{currencies:['PKR','USD'],countries:['Pakistan']},users:[],requests:[],approvals:[]}});
    }
    if(url.pathname==='/index.php')return route.fulfill({contentType:'text/html',body:read('index.html').replace(/<script[\s\S]*?<\/script>/g,'').replace('</head>',`<script>window.TT_SESSION=${JSON.stringify(session)};</script></head>`)});
    const file=path.join(root,url.pathname.slice(1));
    return fs.existsSync(file)?route.fulfill({body:fs.readFileSync(file),contentType:url.pathname.endsWith('.css')?'text/css':'text/javascript'}):route.fulfill({status:404,body:''});
   });
   await page.goto('https://bank-save.test/index.php?view=masters');
   await page.addScriptTag({content:read('admin/app.js')});
   await page.locator('[data-edit-master="fixture-company"]:visible').click();
   await page.locator('[data-company-editor-tab="banks"]').click();
   await page.locator('[data-edit-company-bank="fixture-bank"]').click();
   assert.equal(await page.locator('#bankRetention').isVisible(),true,'PKR bank keeps its retention tick');
   assert.equal(await page.locator('#bankRetention').isDisabled(),false);
   assert.match(await page.locator('#bankRetentionHelp').innerText(),/separate USD retention account/);
   await page.locator('#bankRetention').check();
   await page.locator('#bankBranch').fill('Saved branch');
   reject=true;await page.locator('#bankSave').click();
   await page.locator('#bankSaveError').waitFor({state:'visible'});
   assert.match(await page.locator('#bankSaveError').innerText(),/server rejected/);
   assert.equal(await page.locator('#bankDialog').evaluate(el=>el.open),true);
   assert.equal(await page.locator('#bankSave').isEnabled(),true,'failure permits retry');
   assert.equal(JSON.parse(company.values[13])[0].branch,undefined,'rejected edit is not persisted');
   const errorRect=await page.locator('#bankSaveError').boundingBox();assert.ok(errorRect.y>=0&&errorRect.y+errorRect.height<=viewport.height,'error is visible inside the popup');
   reject=false;hold=true;const saveStarted=new Promise(resolve=>started=resolve);await page.locator('#bankSave').click();
   await saveStarted;await page.waitForFunction(()=>document.getElementById('bankSave').disabled);
   await page.evaluate(()=>document.getElementById('bankForm').requestSubmit());
   assert.equal(posts,2,'pending save is not submitted twice');
   assert.ok(release);release();hold=false;
   await page.waitForFunction(()=>!document.getElementById('bankDialog').open);
   const saved=JSON.parse(company.values[13])[0];assert.equal(saved.currency,'PKR');assert.equal(saved.retentionAccount,false);assert.equal(saved.retentionEnabled,true);assert.equal(saved.branch,'Saved branch');assert.equal(saved.isDefault,true);
   await page.locator('[data-edit-company-bank="fixture-bank"]').click();
   assert.equal(await page.locator('#bankBranch').inputValue(),'Saved branch','reopening reads the confirmed save');
   assert.equal(await page.locator('#bankSaveError').isVisible(),false,'old error clears on reopening');
   assert.equal(await page.locator('#bankRetention').isChecked(),true,'parent tick survives reopening');
   await page.locator('#bankSave').click();
   await page.waitForFunction(()=>!document.getElementById('bankDialog').open);
   assert.equal(JSON.parse(company.values[13]).filter(row=>row.retentionParentBankId==='fixture-bank').length,1);
   await page.locator('[data-edit-company-bank="fixture-retention"]').click();
   assert.equal(await page.locator('#bankCurrency').inputValue(),'USD');
   assert.equal(await page.locator('#bankCurrency').isDisabled(),true,'linked ledger currency cannot be changed');
   assert.equal(await page.locator('#bankNumber').inputValue(),'');
   await page.locator('#bankSave').click();
   await page.waitForFunction(()=>!document.getElementById('bankDialog').open);
   await page.locator('[data-edit-company-bank="fixture-bank"]').click();
   await page.locator('input[name="bankOwnership"][value="Proprietor / Owner Account"]').check();
   assert.equal(await page.locator('#bankRetention').isChecked(),false);assert.equal(await page.locator('#bankRetention').isVisible(),false);
   assert.deepEqual(errors,[]);await page.close();
  }
  console.log('PASS desktop/phone PKR retention tick, separate USD ledger, repeat saves and linked ledger editing, visible server errors, retry, duplicate-submit guard and saved-record reopening');
 }finally{await browser.close()}
})().catch(error=>{console.error(error);process.exitCode=1});
