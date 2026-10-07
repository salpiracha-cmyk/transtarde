'use strict';
const {chromium}=require('@playwright/test');
(async()=>{
 const base='https://app.transtradeinternational.com';
 const user=process.env.TRANSTRADE_QA_USERNAME,password=process.env.TRANSTRADE_QA_PASSWORD;
 if(user!=='qa.assistant'||!password)throw new Error('Configured read-only QA identity required.');
 const browser=await chromium.launch();
 const context=await browser.newContext();
 // No operational writes, recordings, response bodies or business values.
 await context.route('**/*',route=>{
  const r=route.request(),u=new URL(r.url());
  if(!['GET','HEAD'].includes(r.method())&&!(u.origin===base&&u.pathname==='/login.php'&&r.method()==='POST'))return route.abort();
  return route.continue();
 });
 const page=await context.newPage();page.setDefaultTimeout(60000);
 let stage='login';
 const timings=[];const pending=[];
 page.on('response',response=>{
  if(new URL(response.url()).origin!==base)return;
  pending.push((async()=>{
   const headers=await response.allHeaders();
   const server=headers['server-timing'];
   if(server)timings.push({path:new URL(response.url()).pathname,status:response.status(),serverTiming:server});
  })());
 });
 async function measure(label,url,ready){
  const start=Date.now();
  const response=await page.goto(base+url,{waitUntil:'domcontentloaded',timeout:90000});
  if(ready)await ready.waitFor({state:'visible',timeout:60000});
  const readyMs=Date.now()-start;
  const metrics=await page.evaluate(()=>{
   const n=performance.getEntriesByType('navigation')[0];
   const resource=performance.getEntriesByType('resource').map(r=>({path:new URL(r.name).pathname,ms:Math.round(r.duration),bytes:r.transferSize,decodedBytes:r.decodedBodySize})).sort((a,b)=>b.ms-a.ms).slice(0,8);
   return {ttfbMs:Math.round(n.responseStart-n.requestStart),downloadMs:Math.round(n.responseEnd-n.responseStart),domReadyMs:Math.round(n.domContentLoadedEventEnd),htmlBytes:n.decodedBodySize,topResources:resource};
  });
  console.log(JSON.stringify({label,status:response.status(),readyMs,...metrics}));
 }
 try{
  await measure('login','/login.php',page.locator('input[name=username]'));
  stage='fill existing QA credentials';
  await page.locator('input[name=username]').fill(user);
  await page.locator('input[name=password]').fill(password);
  stage='submit existing QA login';
  const signInStart=Date.now();
  await Promise.all([page.waitForURL(/accounts\/index\.php/, {timeout:90000}),page.locator('button[type="submit"]').click()]);
  console.log(JSON.stringify({label:'sign-in redirect',readyMs:Date.now()-signInStart}));
  stage='Accounts';
  await measure('Accounts','/accounts/index.php',page.locator('#entityHome'));
  stage='read-only Masters shell';
  await measure('shared Admin/Masters shell (read-only QA)','/index.php?view=masters',page.locator('#view-masters'));
  stage='Milling';
  await measure('Milling','/module.php?id=milling',page.locator('#millCards .mill-card').first());
  stage='Exports';
  await measure('Exports','/module.php?id=exports',page.getByRole('button',{name:/Active Shipments/i}));
  const theme=await page.locator('.topbar').evaluate(el=>({background:getComputedStyle(el).backgroundImage,palette:getComputedStyle(document.documentElement).getPropertyValue('--tt-brand-deep').trim()}));
  console.log(JSON.stringify({label:'Exports theme',...theme}));
  await Promise.all(pending);console.log(JSON.stringify({serverTimings:timings}));
  if(theme.palette!=='#2e4527'||!theme.background.includes('46, 69, 39'))throw new Error('Approved palette is not authoritative in Exports.');
 }catch(error){
  await Promise.all(pending);
  console.log(JSON.stringify({failedStage:stage,path:new URL(page.url()).pathname,errorClass:error.name,serverTimings:timings}));
  throw error;
 }finally{await browser.close();}
})().catch(()=>{console.error('Startup profile failed; no business entries were submitted.');process.exitCode=1;});
