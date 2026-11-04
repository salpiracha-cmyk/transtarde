'use strict';
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
if(process.env.TT_QA_BROWSER!=='1'){console.log('SKIP production layout browser fixture (TT_QA_BROWSER=1 required)');process.exit(0);}
const {chromium}=require('playwright'),html=fs.readFileSync('milling/Transtrade_Master_Milling_V3_3_2_AUDITED.html','utf8');
const section=(id,next)=>html.slice(html.indexOf('<section id="'+id+'"'),html.indexOf('<section id="'+next+'"'));
const native=name=>{const a=html.indexOf('function '+name+'('),b=html.indexOf('\nfunction ',a+9);assert(a>=0,name);return html.slice(a,b);};
(async()=>{
 const browser=await chromium.launch({headless:true});
 try{for(const width of [1440,390]){
  const page=await browser.newPage({viewport:{width,height:1000}}),errors=[];page.on('pageerror',error=>errors.push(error.message));
  await page.route('https://production.fixture/**',route=>route.fulfill({contentType:'text/html',body:'<body></body>'}));await page.goto('https://production.fixture/module.php?id=milling');
  const baseCss=html.match(/<style>([\s\S]*?)<\/style>/)[1];
  await page.setContent('<style>'+baseCss+fs.readFileSync('brand-theme.css','utf8')+fs.readFileSync('milling/production-layout.css','utf8')+'</style><main>'+section('production','newbags').replace('class="panel"','class="panel active"')+section('newbags','oldbags')+'</main>');
  await page.evaluate(()=>{
   window.currentMill={name:'Fixture Mill',entity:'TTI'};window.stockEntityForView=()=> 'TTI';window.today=()=> '2026-11-04';window.TT_MODULE_ACCESS={userId:7,masters:{mills:[{id:'M1',values:['Fixture Mill']}]}};
   window.STORE_PRODINST='tt30prodinst';window.STORE_BRANDMETA='tt35brandmeta';window.defaultProductionInstructions=()=>[];window.get=key=>key==='tt30prodinst'?[{brand:'Own marking',contractRef:'OWN-CONTRACT'}]:[];window.escHtml=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
   window.productionRows=['B2 — LOCAL','B3','CSR','B2 CSR','Paddy','Powder','Stone / Sutli / Dust'].map((product,id)=>({id:id+1,product,bags:0,bagWeight:50}));window.productionGroupDelete={ready:false,b2:false};window.TT_PRODUCTION_BAGS={outputs(){}};window.openPanel=()=>{};window.writes=[];window.fixtureChoices=[];window.alert=message=>{throw Error(message)};
   document.getElementById('prodDate').value='2026-11-04';
   window.fetch=async(url,options={})=>{if(options.method==='POST')writes.push(url);return {ok:true,json:async()=>({ok:true,deals:[{id:'F1',party:'Fixture fellow exporter',brand:'MARK A',reference:'FIX-SODA-1',product:'White rice 5% broken',quantityMT:75,productionStockName:'Planned ready rice',productionReady:true,productionMillName:'Fixture Mill',commodity:'RICE',packing:'40 kg PP bags',bagResponsibility:{arrangementName:'Pending Confirmation'}}],choices:fixtureChoices,diagnostics:[{brand:'OLD',owner:'TTI',reference:'OLD-PO',received:0,used:0,remaining:0,reason:'Closed historical order'}]})};};
  });
  await page.addScriptTag({content:['brandBrokenSpec','refreshBrandDropdown','addBrandRow','deleteProductionBrand','renderProductionTable','calcProduction','updateProd'].map(native).join('\n')});
  await page.addScriptTag({content:fs.readFileSync('milling/production-recovery-entry.js','utf8')});await page.evaluate(()=>{TT_PRODUCTION_RECOVERY.reset();renderProductionTable();});
  await page.addScriptTag({content:fs.readFileSync('milling/other-exporter-bags.js','utf8')});await page.addScriptTag({content:fs.readFileSync('brand-theme.js','utf8')});
  await page.locator('[data-field=lotRef]').fill('KEEP-RAW-LOT');await page.locator('.tt-number-display[aria-label="Gross input kilograms"]').fill('1000');
  await page.evaluate(()=>TT_BAG_CHOICES.load());
  assert((await page.locator('#productionFellowInstructions').innerText()).includes('MARK A'));assert((await page.locator('#productionFellowDetails').innerText()).includes('White rice 5% broken'));assert((await page.locator('#productionFellowDetails').innerText()).includes('No received bag lot'));
  assert((await page.locator('[data-default-brand]').textContent()).includes('MARK A'),'Unreceived instructed brand missing from planning references');assert.equal(await page.locator('[data-field=lotRef]').inputValue(),'KEEP-RAW-LOT');assert.equal(await page.locator('[data-field=grossKg]').inputValue(),'1000');assert.equal(await page.locator('#productionAddBrand').isEnabled(),false);assert.equal(await page.locator('#bagChoiceDiagnostics').getAttribute('open'),null);
  await page.locator('#brandType').selectOption('rice');assert.equal(await page.locator('#productionAddBrand').isEnabled(),false,'Unreceived bags became selectable');
  await page.evaluate(()=>{fixtureChoices=[{brand:'MARK A',lotId:'FIX-LOT-1',packingKg:40,owner:'Fixture fellow exporter',available:100,exporterDealId:'F1'}];});await page.evaluate(()=>TT_BAG_CHOICES.load());await page.locator('#brandChoice').selectOption('FIX-LOT-1');assert(await page.locator('#productionAddBrand').isEnabled());await page.locator('#productionAddBrand').click();
  assert.equal(await page.evaluate(()=>productionRows.find(row=>row.exporterDealId==='F1').bagSourceLotId),'FIX-LOT-1');assert.equal(await page.evaluate(()=>productionRows.find(row=>row.exporterDealId==='F1').bagWeight),40);assert.equal(await page.locator('[data-field=lotRef]').inputValue(),'KEEP-RAW-LOT');
  assert.equal(await page.evaluate(()=>TT_PRODUCTION_RECOVERY.capture(productionRows).rawInputs.some(row=>'undefined' in row)),false,'Numeric display control created an undefined raw-input field');
  assert.deepEqual(await page.evaluate(()=>writes),[],'Planning/output selection posted business data');assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+2),false,'Page overflow at '+width);assert.deepEqual(errors,[]);
  assert(await page.locator('#production .search-icon').evaluate(el=>{const b=el.getBoundingClientRect(),c=el.closest('.card').getBoundingClientRect();return b.x>=c.x&&b.y>=c.y&&b.right<=c.right&&b.bottom<=c.bottom;}),'Production report search escaped its section');
  const output=process.env.TT_QA_OUTPUT||(process.env.RUNNER_TEMP?path.join(process.env.RUNNER_TEMP,'inventory-evidence'):'');if(output){fs.mkdirSync(output,{recursive:true});await page.screenshot({path:path.join(output,'production-clean-'+width+'.png'),fullPage:true});}
  await page.close();
 }}finally{await browser.close();}
 console.log('PASS desktop/phone Production layout, actual numeric controls, visible unreceived fellow instruction, disabled stockless Add, real source identity, retained raw draft and zero posts');
})().catch(error=>{console.error(error);process.exitCode=1});
