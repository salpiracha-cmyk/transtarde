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
   // Exports opens from the top of a padded shell: its header is initially
   // 18px below the viewport, then pins to zero during the form-opening scroll.
   await page.setContent('<style>body{margin:0}.shell{padding:18px}.topbar{position:sticky;top:0;height:74px;z-index:2;background:white}main{margin-top:40px}.panel{padding:16px}h2{margin:0 0 20px}.field{display:block}input{height:40px}.spacer{height:1400px}</style><div class="shell"><div class="topbar">Exports header</div><main><section class="panel"><h2>Sales Contract</h2><label class="field">Customer<input id="exportFirst"></label><div class="spacer"></div></section></main></div>');
   await page.addScriptTag({content:fs.readFileSync('brand-theme.js','utf8')});
   await page.evaluate(()=>TT_FORM_VIEWPORT.open(document.querySelector('.panel')));
   await page.waitForTimeout(150);
   assert.equal(await page.locator('.panel h2').evaluate(el=>el.getBoundingClientRect().top>=document.querySelector('.topbar').getBoundingClientRect().bottom+4),true,'new form heading stays below a header that becomes sticky during opening');
   assert.equal(await page.locator('#exportFirst').evaluate(el=>el.getBoundingClientRect().top>=74&&el.getBoundingClientRect().bottom<=innerHeight),true,'Exports first field remains visible');
   // Render the actual Milling header CSS together with its authenticated wrapper.
   // A fixed-height, non-wrapping header previously hid logout outside a phone viewport.
   const milling=fs.readFileSync('milling/Transtrade_Master_Milling_V3_3_2_AUDITED.html','utf8');
   const moduleSource=fs.readFileSync('module.php','utf8');
   const millingStyles=[...milling.matchAll(/<style>([\s\S]*?)<\/style>/g)].map(x=>x[1]).join('\n');
   const wrapperStyles=[...moduleSource.matchAll(/<style>([\s\S]*?)<\/style>/g)].map(x=>x[1]).join('\n');
   await page.setContent(`<style>${millingStyles}\n${wrapperStyles}</style>${milling.match(/<header>[\s\S]*?<\/header>/)[0]}<main><h2>Arrival List</h2><input aria-label="Truck number"></main>`);
   await page.evaluate(()=>{
    const header=document.querySelector('header');header.classList.add('ttHasHeaderControls');
    header.querySelector(':scope>div:not(.brand)').remove();
    header.insertAdjacentHTML('beforeend','<a id="ttConsoleTop" href="#">Console</a><button id="ttMasterTop">M</button><span class="ttHeaderDate">27-10-2026</span><div id="ttUserBar" class="ttHeaderUser"><span id="ttUserName">Fixture Milling Administrator · Super Admin</span><a class="ttPower" aria-label="Log out" href="#">⏻</a></div>');
   });
   await page.addStyleTag({content:fs.readFileSync('brand-theme.css','utf8')});
   const headerLayout=await page.evaluate(()=>{
    const header=document.querySelector('header').getBoundingClientRect(),controls=[...document.querySelectorAll('header .brand,header #ttConsoleTop,header #ttMasterTop,header .ttHeaderDate,header #ttUserBar,header .ttPower')].map(e=>{const r=e.getBoundingClientRect();return {left:r.left,right:r.right,top:r.top,bottom:r.bottom}});
    return {width:innerWidth,scrollWidth:document.documentElement.scrollWidth,bottom:header.bottom,heading:document.querySelector('main h2').getBoundingClientRect().top,controls};
   });
   assert.ok(headerLayout.scrollWidth<=viewport.width,'Milling header must not widen the page');
   assert.ok(headerLayout.controls.every(r=>r.left>=0&&r.right<=viewport.width&&r.top>=0&&r.bottom<=headerLayout.bottom),'Milling identity and logout stay within the header');
   assert.ok(headerLayout.heading>=headerLayout.bottom,'Milling heading stays below the complete header');
   await page.close();
  }
  console.log('Desktop and phone: first input, reused popup, delayed load, focus and background stability passed');
 }finally{await browser.close()}
})().catch(error=>{console.error(error);process.exitCode=1});
