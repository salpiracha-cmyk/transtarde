'use strict';
const fs=require('node:fs'),os=require('node:os'),path=require('node:path'),http=require('node:http'),vm=require('node:vm'),assert=require('node:assert/strict'),{chromium}=require('playwright');
(async()=>{
 const captured=[];const test=new Proxy(()=>{},{get:(_,k)=>k==='use'?options=>captured.push(options):()=>{}});
 vm.runInNewContext(fs.readFileSync('tests/live/hostinger-qa.spec.js','utf8'),{require:()=>({test,expect:()=>{}}),process:{env:{}},Date,BigInt});
 assert.equal(captured[0].trace,'off');
 const secret='LOCAL-CANARY-'+require('node:crypto').randomBytes(20).toString('hex');
 const dir=fs.mkdtempSync(path.join(os.tmpdir(),'live-artifact-canary-'));
 const server=http.createServer((req,res)=>{res.setHeader('Content-Type','text/html; charset=utf-8');if(req.method==='POST'){let body='';req.on('data',chunk=>body+=chunk);req.on('end',()=>{assert.equal(new URLSearchParams(body).get('password'),secret);res.end('<h1>Signed in</h1>')});}else res.end('<form method="POST"><input name="username"><input type="password" name="password"><button>Sign in</button></form>')});
 let browser;
 try{
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));browser=await chromium.launch({headless:true});
  const context=await browser.newContext({recordVideo:{dir},viewport:captured[0].viewport});const page=await context.newPage();
  await page.goto('http://127.0.0.1:'+server.address().port);await page.locator('[name=username]').fill('disposable');await page.locator('[name=password]').fill(secret);
  await page.screenshot({path:path.join(dir,'failure-at-login.png')});
  await Promise.all([page.waitForURL('**/'),page.locator('button').click()]);await page.getByRole('heading',{name:'Signed in'}).waitFor();
  // Exercise the artifacts retained after a failure without contacting any hosted service.
  await page.screenshot({path:path.join(dir,'failure-after-login.png')});await context.close();
  const files=fs.readdirSync(dir);assert(files.some(f=>f.endsWith('.webm')));assert(files.some(f=>f.endsWith('.png')));
  for(const name of files){assert(/\.(png|webm)$/.test(name),name);assert(!fs.readFileSync(path.join(dir,name)).includes(Buffer.from(secret)),'Canary retained in '+name);}
  console.log('PASS disposable login/failure artifacts: screenshots and video retained; no trace, HAR, authentication state or canary credential bytes');
 }finally{if(browser)await browser.close();await new Promise(resolve=>server.close(resolve));fs.rmSync(dir,{recursive:true,force:true});}
})().catch(e=>{console.error(e);process.exitCode=1});
