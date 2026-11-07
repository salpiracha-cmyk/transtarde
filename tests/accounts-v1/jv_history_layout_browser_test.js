'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs');
module.exports=async function verifyHistory(browser){
 const page=await browser.newPage({viewport:{width:1440,height:1000}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
 try{
  await page.route('http://jv-history.test/**',r=>r.fulfill({contentType:'text/html',body:'<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><section class="tt-window"><div class="tt-window-body"><div id="ws-jv" class="workspace active"></div></div></section></body></html>'}));await page.goto('http://jv-history.test/');
  await page.addStyleTag({content:fs.readFileSync('brand-theme.css','utf8')+'body{margin:0;font:14px Arial}.tt-window{width:min(1160px,100%);margin:0 auto}.btn{box-sizing:border-box}.jvw,.jvw *{box-sizing:border-box}'});
  await page.evaluate(()=>{
   localStorage.setItem('tt_accounts_entity','TTI');window.TT_ACCOUNT_ACCESS={super:true,csrf:'fixture'};window.sent=[];window.amended=[];window.deleted=[];window.TT_ALL_LEDGERS={editPosting:id=>amended.push(id),deletePosting:(id,publicId)=>{deleted.push([id,publicId]);return null;}};
   const lines=[{account:'1110',accountName:'Bank Accounts',debit:215118,credit:0},{account:'2140',accountName:'Expense / Utility Payables',subledger:'Habib Metro account closed of Transtrade',debit:0,credit:215118}];
   window.fixture={ok:true,canWrite:true,canApprove:true,accounts:[],targets:[],opening:{allowed:false},jvs:['Rejected','Posted','Pending Approval','Amended'].map((status,i)=>({id:'JVD-'+i,journalId:i===1?'POST-2026-00381':'',date:'2026-09-16',narration:'Account close HMB Korangi <script>bad()</script>',reference:'JV REF '+i,createdBy:'Tayyab',approvedBy:status==='Posted'?'Salman':'',status,lines,totalDebit:610000000.23,totalCredit:610000000.23,rejectionReason:status==='Rejected'?'Already posted through Transport':''}))};
   window.fetch=async(url,init={})=>{if(init.body)sent.push(JSON.parse(init.body));return {ok:true,json:async()=>structuredClone(fixture)}};
  });
  for(const file of ['accounts/accounts-row-controls.js','accounts/accounts-form-layout.js','accounts/accounts-clean-ui.js','accounts/jv-workflow-ui.js'])await page.addScriptTag({content:fs.readFileSync(file,'utf8')});await page.evaluate(()=>TT_JV_WORKFLOW.load());await page.evaluate(()=>TT_ACCOUNTS_CLEAN_UI.normalizeRowControls(document));await page.locator('.jvw-history>summary').click();
  assert.equal(await page.locator('.jvw-history-row').count(),4);assert.equal(await page.locator('[data-jvw-amend]').textContent(),'Edit','Shared row controls must retain labelled history actions');assert.equal(await page.locator('.jvw-history-detail:visible').count(),0);
  const posted=page.locator('.jvw-history-row').nth(1);for(const name of ['View entries','Edit','Print JV','Delete'])assert.equal(await posted.getByRole('button',{name,exact:true}).count(),1);
  assert.equal(await page.locator('.jvw-history-row').nth(2).getByRole('button',{name:'Approve & Post',exact:true}).count(),1);assert.equal(await page.locator('.jvw-history-row').nth(2).getByRole('button',{name:'Reject',exact:true}).count(),1);
  await posted.getByRole('button',{name:'View entries',exact:true}).click();assert.equal(await page.locator('#jvw-details-1').isVisible(),true);assert(await page.locator('#jvw-details-1').innerText().then(t=>t.includes('Habib Metro')));assert.equal(await page.evaluate(()=>sent.length),0,'Viewing accounting details must not write to the server');assert.equal(await page.locator('.jvw-history script').count(),0);
  await posted.getByRole('button',{name:'Hide entries',exact:true}).click();assert.equal(await page.locator('#jvw-details-1').isVisible(),false);await posted.getByRole('button',{name:'Edit',exact:true}).click();assert.deepEqual(await page.evaluate(()=>amended),['POST-2026-00381']);await posted.getByRole('button',{name:'Delete',exact:true}).click();assert.deepEqual(await page.evaluate(()=>deleted),[['POST-2026-00381',undefined]]);assert.equal(await page.evaluate(()=>sent.length),0,'Delete delegates to the controlled cancellation preview, not a raw JV reversal');
  for(const width of [1440,1024,390]){
   await page.setViewportSize({width,height:1000});
   for(const button of await page.locator('.jvw-row-actions button').all()){
    const geometry=await button.evaluate(el=>{const r=el.getBoundingClientRect(),s=getComputedStyle(el);return {x:r.x,right:r.right,width:r.width,height:r.height,scroll:el.scrollWidth,client:el.clientWidth,wrap:s.whiteSpace}});
    assert(geometry.x>=0&&geometry.right<=width+1,JSON.stringify({width,geometry}));assert(geometry.height>=40&&geometry.height<=50,JSON.stringify({width,geometry}));assert(geometry.scroll<=geometry.client+1,'Button label must fit without clipping');assert.equal(geometry.wrap,'nowrap');
   }
   for(const amount of await page.locator('.jvw-history-row .jvw-amount').all())assert(await amount.evaluate(el=>el.scrollWidth<=el.clientWidth+1),'Large amounts must not overlap adjacent columns');
   assert.equal(await page.locator('.jvw-history-table .jvw-amount').first().evaluate(el=>getComputedStyle(el).textAlign),width<=1100?'left':'right');
  }
  await page.evaluate(()=>{fixture.canWrite=false;fixture.canApprove=false;return TT_JV_WORKFLOW.load()});await page.locator('.jvw-history>summary').click();assert.equal(await page.locator('[data-jvw-approve],[data-jvw-reject],[data-jvw-reverse],[data-jvw-edit],[data-jvw-cancel]').count(),0);assert.equal(await page.locator('[data-jvw-amend]').isDisabled(),true);assert.deepEqual(errors,[]);
  console.log('PASS compact JV history, expandable entries, separate coloured actions, desktop/mobile button bounds, read-only permissions and no write on View');
 }finally{await page.close()}
};
