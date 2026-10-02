'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs');
const {chromium}=require('playwright');
(async()=>{
 const browser=await chromium.launch({headless:true});
 try{
  for(const viewport of [{width:1440,height:900},{width:390,height:844}]){
   const page=await browser.newPage({viewport});
   await page.setContent(`<style>body{margin:0}header{position:sticky;top:0;height:60px;background:white;z-index:2}.spacer{height:1400px}.workspace{padding:20px}.tt-layer{position:fixed;inset:0;background:white}.tt-window{height:100%;overflow:auto}.field{display:block;margin:20px}input{height:32px}</style><header>Header</header><div class="spacer"></div><section class="workspace"><h2>New form</h2><label class="field">First entry<input id="first"></label><div class="spacer"></div></section>`);
   await page.addStyleTag({content:fs.readFileSync('brand-theme.css','utf8')});
   await page.addScriptTag({content:fs.readFileSync('brand-theme.js','utf8')});
   await page.evaluate(()=>{window.scrollTo(0,2200);document.querySelector('.workspace').classList.add('active')});
   await page.waitForTimeout(150);
   assert.equal(await page.locator('#first').evaluate(el=>{const r=el.getBoundingClientRect();return r.top>=60&&r.bottom<=innerHeight}),true,'first field under header');
   assert.equal(await page.evaluate(()=>document.activeElement.tagName),'BODY','no keyboard focus');
   await page.evaluate(()=>{window.scrollTo(0,1900);document.querySelector('.workspace').append(document.createElement('span'))});
   await page.waitForTimeout(150);assert.ok(await page.evaluate(()=>scrollY)>1800,'background update retains position');
   await page.evaluate(()=>{const root=document.createElement('div');root.className='tt-layer';root.innerHTML='<div class="tt-window"><h2>Popup</h2><label class="field">First entry<input id="popupFirst"></label><div class="spacer"></div></div>';document.body.append(root)});
   await page.waitForTimeout(150);
   await page.evaluate(()=>{const root=document.querySelector('.tt-layer');root.querySelector('.tt-window').scrollTop=900;root.hidden=true});
   await page.evaluate(()=>document.querySelector('.tt-layer').hidden=false);
   await page.waitForTimeout(150);
   assert.equal(await page.locator('.tt-window').evaluate(el=>el.scrollTop),0,'reused popup resets nested scroll');
   await page.evaluate(()=>{const root=document.querySelector('.tt-layer');root.innerHTML='<div class="tt-window"><h2>Loading</h2></div>';TT_FORM_VIEWPORT.open(root)});
   await page.waitForTimeout(150);
   await page.evaluate(()=>{document.querySelector('.tt-window').innerHTML='<h2>Loaded form</h2><label class="field">Delayed input<input id="delayed"></label><div class="spacer"></div>';document.querySelector('.tt-window').scrollTop=700});
   await page.waitForTimeout(150);
   assert.equal(await page.locator('#delayed').evaluate(el=>el.getBoundingClientRect().bottom<=innerHeight),true,'delayed first input visible');
   await page.evaluate(()=>{const root=document.querySelector('.tt-layer');root.innerHTML='<div class="tt-window"><h2>Loading again</h2><div class="spacer"></div></div>';TT_FORM_VIEWPORT.open(root)});
   await page.waitForTimeout(100);
   await page.evaluate(()=>{document.dispatchEvent(new Event('input',{bubbles:true}));document.querySelector('.tt-window').scrollTop=500;const label=document.createElement('label');label.innerHTML='<input>';document.querySelector('.tt-window').append(label)});
   await page.waitForTimeout(150);
   assert.equal(await page.locator('.tt-window').evaluate(el=>el.scrollTop),500,'user activity cancels deferred positioning');
   await page.evaluate(()=>{const label=document.createElement('label');label.className='grid';label.hidden=true;label.innerHTML='<input>';document.body.append(label)});
   assert.equal(await page.locator('label[hidden]').evaluate(el=>getComputedStyle(el).display),'none','hidden grid stays hidden');
   await page.close();
  }
  console.log('Desktop and phone: first input, reused popup, delayed load, focus and background stability passed');
 }finally{await browser.close()}
})().catch(error=>{console.error(error);process.exitCode=1});
