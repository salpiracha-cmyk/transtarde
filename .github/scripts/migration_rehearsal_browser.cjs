'use strict';
// A private loopback target only. Never print source records or credentials.
const {chromium}=require('playwright');
const {readFileSync}=require('node:fs');
const {createHash}=require('node:crypto');
const assert=require('node:assert/strict');
const source='https://app.transtradeinternational.com';
const target=process.env.TT_REHEARSAL_URL;
assert.match(target||'',/^http:\/\/127\.0\.0\.1:\d{4,5}$/);
const gate=readFileSync(process.env.TT_REHEARSAL_GATE_FILE,'utf8').trim();
assert.match(gate,/^[a-f0-9]{64}$/);
const stable=value=>Array.isArray(value)?value.map(stable):value&&typeof value==='object'?Object.fromEntries(Object.keys(value).sort().map(key=>[key,stable(value[key])])):value;
const digest=value=>createHash('sha256').update(JSON.stringify(stable(value))).digest('hex');
async function signIn(context,base){
 const page=await context.newPage();
 await page.goto(base+'/login.php',{waitUntil:'domcontentloaded',timeout:90000});
 await page.locator('input[name=username]').fill(process.env.TRANSTRADE_QA_USERNAME);
 await page.locator('input[name=password]').fill(process.env.TRANSTRADE_QA_PASSWORD);
 await page.locator('button[type=submit]').click();
 await page.waitForURL('**/accounts/index.php',{timeout:60000});
 await page.locator('#entityHome').waitFor({state:'visible',timeout:45000});return page;
}
async function controls(context,base){
 const json=async path=>{const response=await context.request.get(base+path);assert.equal(response.status(),200,'Control HTTP status');const data=await response.json();assert.equal(data.ok,true,'Control response');return data;};
 const operations=await json('/api/operations.mysql.php');
 const values=Object.fromEntries(Object.entries(operations.values||{}).map(([key,value])=>{try{return[key,JSON.parse(value)];}catch{return[key,value];}}));
 assert.ok(Object.keys(values).length>0,'Operational source is populated');
 const asOf=new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Karachi',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
 const reports={};for(const entity of ['TTI','BRM','TG']){
  const report=await json('/api/accounts_reports.php?'+new URLSearchParams({entity,from:'0001-01-01',asOf}));
  assert.equal(report.trialBalance.balanced,true,entity+' trial balance');
  assert.ok(Math.abs(report.balanceSheet.totalAssets-report.balanceSheet.totalLiabilitiesAndEquity)<.011,entity+' balance sheet');
  assert.equal(report.dataQuality.skippedMalformedLines,0,'Malformed report lines');
  reports[entity]=digest({trialBalance:report.trialBalance,balanceSheet:report.balanceSheet,generalLedger:report.generalLedger,dataQuality:report.dataQuality});
 }
 return{operations:digest(values),keys:Object.keys(values).length,reports};
}
(async()=>{
 const browser=await chromium.launch({headless:true});let copy;
 try{
  // The gate header is confined to the disposable loopback context.
  const original=await browser.newContext({serviceWorkers:'block'});
  copy=await browser.newContext({serviceWorkers:'block',extraHTTPHeaders:{'X-TT-Rehearsal':gate}});
  let blockedWrites=0;
  for(const [context,base]of[[original,source],[copy,target]])await context.route('**/*',route=>{
   const request=route.request(),url=new URL(request.url());
   const login=url.pathname==='/login.php'&&request.method()==='POST';
   if(url.origin!==base||(!['GET','HEAD'].includes(request.method())&&!login)){if(url.origin===base&&url.pathname!=='/api/session_activity.php')blockedWrites++;return route.abort();}
   return route.continue();
  });
  const ready=await copy.request.get(target+'/__audit/ready',{timeout:30000});assert.equal(ready.status(),200);const manifest=await ready.json();assert.ok(manifest.files>=3);
  await signIn(original,source);const before=await controls(original,source);
  const page=await signIn(copy,target);const restored=await controls(copy,target);assert.equal(digest(restored),digest(before),'Source and target controls agree');
  for(const id of ['exports','milling']){
   await page.goto(target+'/module.php?id='+id,{waitUntil:'domcontentloaded',timeout:90000});
   await page.locator(id==='exports'?'#app':'#homeGrid').waitFor({state:'visible',timeout:45000});
   await page.locator('#ttMasterTop').waitFor({state:'visible',timeout:45000});
  }
  const rollback=await copy.request.post(target+'/__audit/rollback');assert.equal(rollback.status(),200,'Isolated rollback HTTP status');
  const result=await rollback.json();assert.equal(result.ok,true);assert.equal(result.storesRestored,3);
  // Restoring auth invalidates any target login revision; authenticate normally again.
  await signIn(copy,target);const after=await controls(copy,target);assert.equal(digest(after),digest(before),'Rollback retains every control');
  const unchanged=await controls(original,source);assert.equal(digest(unchanged),digest(before),'Production business controls remain unchanged');
  assert.equal(blockedWrites,0,'No attempted business writes');
  console.log('PRIVATE_RECOVERY_REHEARSAL_OK '+JSON.stringify({manifestFiles:manifest.files,manifestBytes:manifest.bytes,operationalKeys:before.keys,companies:3,nativeModules:3,canonicalStoresRestored:3,sourceTargetControlsAgree:true,sourceBusinessWrites:0}));
 }finally{
  if(copy)await copy.request.post(target+'/__audit/finish',{timeout:10000}).catch(()=>{});
  await browser.close();
 }
})().catch(()=>{console.error('Private rehearsal failed; no production restore or migration was performed. Private payloads are not logged.');process.exitCode=1;});
