'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require('playwright');
const root=path.resolve(__dirname,'../..'),out=path.join(root,'tmp/qa/documents-20260919');fs.mkdirSync(out,{recursive:true});
const hook=`
window.__docsQA={
 fixture(seller='TTI',code='CAD100',customsCode='CAD100'){
  const b={id:'QA-DOC-BUYER',name:'QA Document Buyer Limited',address:'Buyer Building 100, Full Address Road, Dubai, United Arab Emirates',notifies:[{id:'NOTIFY-QA',name:'QA Document Receiver Limited',address:'Receiver Building 200, Full Address Avenue, Dubai, United Arab Emirates'}]};
  const c={id:'QA-DOC-CONTRACT',ref:seller+'/DOC-QA/01',seller,customerId:b.id,date:'2026-09-01',product:'IRRI-6 White Rice',variety:'IRRI-6',riceType:'White Rice',brokenText:'5%',finish:'Well milled; silky polished; well sortexed',cropYear:'2026/2027',quality:'PAKISTAN LONG GRAIN IRRI-6 WHITE RICE',additionalQuality:'Free from live insects and bad odour; rice fit for human consumption.',hsCode:'1006.30',specMode:'As per Pakistan Origin Standards',qty:54,containers:2,weightPer:27,tolerance:5,shipmentDate:'2026-09-30',signedDeadline:'2026-09-03',paymentDeadline:'2026-09-08',pol:'Port Qasim, Pakistan',podPort:'Jebel Ali',podCountry:'United Arab Emirates',packingUnit:'KG',currency:'USD',incoterm:'CFR',paymentCode:code,advancePct:30,customPayment:'Payment as agreed by the contracting parties.',usanceDays:90,inspection:'No',insurance:"Buyer's Account",terms:[],docs:['Commercial Invoice','Bill of Lading','Packing List','Certificate of Origin','Phytosanitary Certificate','Fumigation Certificate','Goods Declaration (GD)'],packings:[{size:25,type:'P.P. Bags',brand:'ASAS QA',tare:80,containers:2,weightPer:27,price:410,freight:20,insurance:0,masterBag:{enabled:true,bagsPerMaster:2,quantity:1080,qty:1080,tare:120}}]};
  state=seed();state.customers=[b];state.contracts=[c];const p=makeShipment(c);p.id='QA-PROCESS';p.kind='process';const s=structuredClone(p);s.id='QA-LOT';s.kind='lot';s.lotId='QA-LOT-01';s.parentProcessId=p.id;s.containers=2;
  s.millActuals=[{number:'MSCU1234567',seal:'QA001',bags:1080,netKg:27000,tareKg:86.4,grossKg:27086.4,brand:'ASAS QA',packing:'25 KG'},{number:'TGHU7654321',seal:'QA002',bags:1080,netKg:27000,tareKg:86.4,grossKg:27086.4,brand:'ASAS QA',packing:'25 KG'}];
  s.bl={...s.bl,blNo:'QA-BL-001',onBoardDate:'2026-09-19',vessel:'QA VESSEL',voyage:'QA-VOY-1',consignee:b.name+'\\n'+b.address,notify:b.notifies[0].name+'\\n'+b.notifies[0].address};
  s.lc={saved:code.startsWith('LC_'),lcNo:'QA-LC-001',lcDate:'2026-09-05',issuingBank:'QA ISSUING BANK',documents:c.docs,conditions:[]};
  s.customs={...s.customs,exporter:'TTI',invoiceNo:'QA-CUSTOMS-601',date:'2026-09-19',rate:400,currency:'USD',customsPaymentCode:customsCode,paymentTerms:ttDirectCustomsPaymentLabel(customsCode),description:'PAKISTAN LONG GRAIN IRRI-6 WHITE RICE',fiAllocations:[{number:'QA-FI-001',date:'2026-09-02',amount:6480,bank:'QA BANK PAKISTAN'}],gdRefs:[{number:'QA-GD-001',date:'2026-09-17'},{number:'QA-GD-002',date:'2026-09-18'}],bank:'QA BANK PAKISTAN',iban:'QA-IBAN-NOT-FOR-PAYMENT',openAccount:15120};
  s.commercial={...s.commercial,invoiceNo:c.ref,date:'2026-09-19',containerSize:'20'};
  const covering={bank:'QA BANK PAKISTAN',bankAddress:'QA Trade Branch, Main Road',dispatchDate:'2026-09-19',originalBank:{name:'QA ORIGINAL BANK',branch:'Trade Branch',address:'Original Documents Address, Dubai, UAE'},copyBanks:[{name:'QA CONFIRMING BANK',branch:'Confirmation Branch',address:'Copy Documents Address, London, UK'}],notes:[]};
  s.covering=structuredClone(covering);s.tgdocs={covering:structuredClone(covering),activeTab:'proforma'};
  const canvas=document.createElement('canvas');canvas.width=220;canvas.height=160;const ctx=canvas.getContext('2d');ctx.fillStyle='#fff';ctx.fillRect(0,0,220,160);ctx.fillStyle='#111';ctx.font='bold 28px Arial';ctx.fillText('ASAS QA',32,75);ctx.font='14px Arial';ctx.fillText('SAMPLE MARKING',35,110);const art=canvas.toDataURL();
  p.bagOrders=[{poNo:'QA-PO-1',lines:[{brand:' ASAS QA ',artworkAttached:true,artworkDocument:{dataUrl:art}}]}];s.bagOrders=[];
  state.shipments=[p,s];state.accountsReceipts=[{id:'QA-RECEIPT',lotId:s.id,amount:code==='ADV100'?23220:6966,currency:'USD',status:'Posted'}];currentShipmentId=s.id;activeWorkspace='tg';
  window.__fixture={s,c,b,p,art};return{total:customsInvoiceValue(s,c),directTotal:invoiceLines(s,c,false).reduce((sum,row)=>sum+row.amount,0)}
 },
 mutate(fn){return eval('('+fn+')')(window.__fixture)},
 html(kind,internal=false){const {s,c}=window.__fixture;return kind==='cover'?coveringDoc(s,c,internal?s.tgdocs.covering:s.covering,internal):kind==='tgInvoice'?tgInternalCommercialInvoiceDoc(s,c):kind==='invoice'?commercialInvoiceDoc(s,c):kind==='tgPacking'?packingListDoc(s,c,true,true):tgProformaDoc(s,c)},
 editor(internal=false){const {s,c}=window.__fixture;window.__bankEditor=renderBankCoveringEditor(document.getElementById('qa-editor'),s,c,internal?s.tgdocs.covering:s.covering,internal)},
 readEditor(){window.__bankEditor.read()},
 tg(){renderTG(document.getElementById('qa-editor'))},
 state(){return structuredClone(state)},
 refs(){return coveringRefs(window.__fixture.s)},
 fit(){return fitTGProformaPages(document.getElementById('printRoot'))},
 print(html){return directPrint('BANK COVERING LETTER',html,'with')},
 markupDeleted(){const {s,p}=window.__fixture;p.bagOrders[0].lines[0].artworkAttached=false;return bagArtworkFor(s,'ASAS QA')},
 resetRendering(){renderShipmentWorkspace=()=>{window.__rerenders=(window.__rerenders||0)+1}}
};`;
let app=fs.readFileSync(path.join(root,'exports/app.js'),'utf8');assert.equal(app.split('mount();restoreContractCheckpoint();').length,2);app=app.replace('mount();restoreContractCheckpoint();',hook);
const reports=[];
(async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{})});
 const page=await browser.newPage({viewport:{width:1200,height:1300}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.route('http://tt.local/exports/assets/**',r=>r.fulfill({path:path.join(root,'exports/assets',path.basename(new URL(r.request().url()).pathname))}));
 await page.setContent('<html><head><base href="http://tt.local/exports/"></head><body><div id="app"></div><div id="qa-editor"></div><div id="printRoot"></div></body></html>');
 // Isolated Storage API fixture: no network and no production records.
 await page.evaluate(()=>{const saved=new Map();Object.defineProperty(window,'localStorage',{value:{getItem:key=>saved.get(String(key))??null,setItem:(key,value)=>saved.set(String(key),String(value)),removeItem:key=>saved.delete(String(key)),clear:()=>saved.clear()}})});
 await page.addStyleTag({content:fs.readFileSync(path.join(root,'exports/app.css'),'utf8')});
 await page.evaluate(()=>{window.TT_MODULE_ACCESS={user:'Document QA',masters:{banks:[{values:['BANK-QA','TTI TRANSTRADE INTERNATIONAL','','TRANSTRADE INTERNATIONAL','QA BANK PAKISTAN','QA Trade Branch, Main Road','Pakistan','USD','QA-ACCOUNT','QA-IBAN-NOT-FOR-PAYMENT','QA-SWIFT','','','Active']},{values:['TG-BANK-QA','TG TRANS GRAINS FOODSTUFF TRADING L.L.C','','TRANS GRAINS FOODSTUFF TRADING L.L.C','QA BANK DUBAI','QA Dubai Branch','United Arab Emirates','USD','QA-TG-ACCOUNT','QA-TG-IBAN','QA-TG-SWIFT','','','Active']}]}};window.confirm=()=>true;window.alert=message=>window.__alert=message;window.print=()=>{window.__prints=(window.__prints||0)+1}});
 await page.addScriptTag({content:app});await page.evaluate(()=>window.__docsQA.resetRendering());
 const fixture=(seller,code,customsCode)=>page.evaluate(([a,b,c])=>window.__docsQA.fixture(a,b,c),[seller,code,customsCode]);
 const mutate=fn=>page.evaluate(fn=>window.__docsQA.mutate(fn),String(fn));
 const html=(kind,internal=false)=>page.evaluate(([k,t])=>window.__docsQA.html(k,t),[kind,internal]);
 async function render(markup,name,pdf=false){
  await page.evaluate(markup=>{const root=document.getElementById('printRoot');root.style.display='block';root.className='printModeWith';root.setAttribute('aria-hidden','false');root.innerHTML=markup},markup);
  await page.evaluate(async()=>{await Promise.all([...document.querySelectorAll('#printRoot img')].map(im=>im.decode().catch(()=>{})));window.__docsQA.fit()});
  const report=await page.evaluate(()=>{const page=document.querySelector('#printRoot .docPage'),root=document.getElementById('printRoot');return{text:root.innerText,pages:root.querySelectorAll('.docPage').length,images:[...root.querySelectorAll('img')].map(im=>({loaded:im.complete&&im.naturalWidth>0,source:im.getAttribute('src').slice(0,80)})),width:Math.max(0,page.scrollWidth-page.clientWidth),end:Math.max(...[...page.children].filter(node=>!node.matches('.docLetterhead,.docFooterArt,.docPageNo')).map(node=>node.getBoundingClientRect().bottom-page.getBoundingClientRect().top)),netBold:root.querySelector('.commercialNetWeight')?[...root.querySelector('.commercialNetWeight').children].map(n=>getComputedStyle(n).fontWeight):[],grossNormal:root.querySelector('.commercialWeightTable tbody tr')?[...root.querySelector('.commercialWeightTable tbody tr').children].map(n=>getComputedStyle(n).fontWeight):[]}});
  assert.ok(report.width<2,name+' must not overflow horizontally');assert.ok(report.images.every(im=>im.loaded),name+' contains an unloaded image');reports.push({name,...report});
  if(pdf){await page.screenshot({path:path.join(out,name+'.png'),fullPage:true});await page.pdf({path:path.join(out,name+'.pdf'),format:'A4',printBackground:true,preferCSSPageSize:true,margin:{top:'0',left:'0',right:'0',bottom:'0'}})}
  return report;
 }
 // Every supported route, for both direct Pakistan exporters, with on/off dispatch.
 for(const seller of ['TTI','BRM'])for(const code of ['CAD100','ADV_CAD','CUSTOM','ADV100','ADV_SCAN','LC_SIGHT','LC_USANCE'])for(const enabled of [false,true]){
  await fixture(seller,code);await mutate(({s})=>{s.covering.dispatchOriginals=false;s.covering.dispatchCopies=false});
  await page.evaluate(enabled=>{window.__fixture.s.covering.dispatchOriginals=enabled;window.__fixture.s.covering.dispatchCopies=enabled},enabled);
  const markup=await html('cover'),r=await render(markup,seller+'-'+code+'-'+enabled,seller==='TTI'&&(!enabled||code==='LC_SIGHT'));
  assert.equal(r.pages,1);assert.equal(r.images.length,0);assert.ok(!/docLetterhead|docFooterArt|docAutoSign|commercialRemittance|Authorised Signatory/.test(markup));
  assert.ok(r.text.includes('TO,\nMANAGER,\nQA BANK PAKISTAN'));assert.ok(r.text.includes('KARACHI, PAKISTAN'));
  assert.ok(r.text.includes('SETTLE/UTILIZE THE APPLICABLE F.I AND GD ACCORDINGLY.'));
  const gd=await page.locator('.coverGDRow').allTextContents();assert.equal(gd.length,2);assert.ok(gd.every(t=>t.endsWith('0001')));
  const originals=code.startsWith('LC_')||(['CAD100','ADV_CAD','CUSTOM'].includes(code)&&enabled);
  assert.equal(r.text.toUpperCase().includes('QA ORIGINAL BANK'),originals);assert.equal(r.text.toUpperCase().includes('QA CONFIRMING BANK'),code.startsWith('LC_')&&enabled);
  if(code==='ADV_CAD')assert.ok(r.text.includes('USD 16,254.00'));
  if(code.startsWith('LC_'))assert.ok(r.text.includes('QA-LC-001')&&r.text.includes('05-09-2026'));
  if(['ADV100','ADV_SCAN'].includes(code))assert.ok(!r.text.includes('DISPATCH THE ORIGINAL'));
 }
 // FI only / GD only / neither: do not invent a reference in the closing sentence.
 for(const type of ['fi','gd','neither']){
  await fixture('TTI','ADV100');await page.evaluate(type=>{if(type!=='fi')window.__fixture.s.customs.fiAllocations=[];if(type!=='gd')window.__fixture.s.customs.gdRefs=[]},type);
  const r=await render(await html('cover'),'refs-'+type);const closing=r.text.match(/KINDLY RECORD[^.]+(?:F\.I[^.]+)?[\s\S]*?ACCORDINGLY\./)?.[0]||r.text;
  if(type==='fi'){assert.ok(r.text.includes('APPLICABLE F.I ACCORDINGLY'));assert.ok(!r.text.includes('APPLICABLE F.I AND GD'))}
  if(type==='gd')assert.ok(r.text.includes('APPLICABLE GD ACCORDINGLY'));
  if(type==='neither')assert.ok(r.text.includes('KINDLY RECORD THE ENCLOSED DOCUMENTS ACCORDINGLY.'));
 }
 for(const code of ['ADV100','CAD100','ADV_CAD']){
  await fixture('TG','ADV_SCAN',code);await mutate(({s})=>{s.tgdocs.covering.dispatchOriginals=true});
  const r=await render(await html('cover',true),'TG-cover-'+code,true);assert.equal(r.images.length,0);assert.equal(r.pages,1);assert.ok(r.text.includes('QA-CUSTOMS-601'));assert.equal(r.text.toUpperCase().includes('QA ORIGINAL BANK'),code!=='ADV100');
  if(code==='ADV_CAD')assert.ok(r.text.includes('USD 15,120.00'));
 }
 // UI checkbox visibility, mandatory GD locking, validation and print cancellation.
 await fixture('TTI','CAD100');await page.evaluate(()=>window.__docsQA.editor());
 assert.equal(await page.locator('#cvOriginalBankFields.hidden').count(),1);await page.locator('#cvDispatchOriginals').check();assert.equal(await page.locator('#cvOriginalBankFields.hidden').count(),0);
 await page.locator('#cvOriginalBankName').fill('CHOSEN BANK');await page.locator('#cvOriginalBankAddress').fill('CHOSEN FULL ADDRESS');await page.evaluate(()=>window.__docsQA.readEditor());assert.ok((await html('cover')).toUpperCase().includes('CHOSEN FULL ADDRESS'));
 await page.locator('#cvDispatchOriginals').uncheck();assert.ok(!(await html('cover')).toUpperCase().includes('CHOSEN FULL ADDRESS'));
 assert.equal(await page.locator('[data-mandatory-gd="true"][readonly]').count(),2);assert.equal(await page.locator('[data-cv-remove-doc][disabled]').count(),2);
 await page.locator('#addCoverDocument').click();await page.locator('[data-cv-doc-name]').last().fill('QA Added Certificate');await page.locator('#addCoverNote').click();await page.locator('[data-cv-note]').last().fill('QA note retained');await page.evaluate(()=>window.__docsQA.readEditor());assert.ok((await html('cover')).includes('QA Added Certificate'));
 await page.evaluate(()=>{window.confirm=()=>false;window.__prints=0});await page.evaluate(markup=>window.__docsQA.print(markup),await html('cover'));assert.equal(await page.evaluate(()=>window.__prints),0);
 await page.evaluate(()=>window.confirm=()=>true);await page.evaluate(markup=>window.__docsQA.print(markup),await html('cover'));assert.equal(await page.evaluate(()=>window.__prints),1);await page.waitForTimeout(300);
 await fixture('TTI','LC_SIGHT');await page.evaluate(()=>window.__docsQA.editor());await page.locator('#cvDispatchCopies').check();await page.locator('[data-cv-copy-bank-address]').fill('CHOSEN CONFIRMING ADDRESS');await page.evaluate(()=>window.__docsQA.readEditor());assert.ok((await html('cover')).toUpperCase().includes('CHOSEN CONFIRMING ADDRESS'));
 await fixture('TTI','ADV100');await page.evaluate(()=>window.__docsQA.editor());assert.equal(await page.locator('#cvDispatchOriginals,#cvDispatchCopies,#cvOriginalBankFields').count(),0);
 // TG importer changes transfer to PL, retain complete addresses, and persist with the pack.
 await fixture('TG','ADV_SCAN');await page.evaluate(()=>window.__docsQA.tg());await page.locator('[data-tg-tab="invoice"]').click();await page.locator('#tgShowImporter').check();await page.locator('#tgImporterChoice').selectOption('NOTIFY:NOTIFY-QA');await page.locator('[data-tg-tab="packing"]').click();
 let r=await render(await html('tgPacking'),'TG-packing-importer',true);assert.ok(r.text.toUpperCase().includes('QA DOCUMENT RECEIVER LIMITED')&&r.text.toUpperCase().includes('RECEIVER BUILDING 200'));assert.ok(!r.text.includes('NOTIFY PARTY'));
 r=await render(await html('tgInvoice'),'TG-invoice-importer',true);assert.ok(r.text.includes('BUYER / REMITTER'));assert.ok(r.text.includes('IMPORTER / RECEIVER OF GOODS'));assert.ok(!r.text.includes('BY ORDER AND FOR ACCOUNT OF'));assert.ok(r.images.some(im=>im.source.includes('TTI_sign')));assert.ok(r.images.some(im=>im.source.startsWith('data:image/')));
 await page.locator('#saveTGPack').click();assert.equal(await page.evaluate(()=>JSON.parse(localStorage.getItem('transtrade_export_v3_operational')).shipments.find(s=>s.id==='QA-LOT').tgdocs.importer.party.address),'Receiver Building 200, Full Address Avenue, Dubai, United Arab Emirates');
 await page.evaluate(()=>window.__docsQA.tg());await page.locator('[data-tg-tab="invoice"]').click();await page.locator('#tgShowImporter').uncheck();r=await render(await html('tgInvoice'),'TG-invoice-no-importer');assert.ok(!r.text.includes('IMPORTER / RECEIVER'));r=await render(await html('tgPacking'),'TG-packing-default');assert.ok(r.text.toUpperCase().includes('TRANS GRAINS'));
 // One-page TG proforma has planned/customs values and original contract terms, not shipment metadata.
 await fixture('TG','ADV_SCAN');r=await render(await html('proforma'),'TG-proforma',true);assert.ok(r.text.includes('USD 21,600.00'));assert.ok(r.text.includes('30% advance'));assert.ok(r.text.includes('scan copies'));assert.ok(!/QA VESSEL|QA-VOY|QA-BL|B\/L NUMBER|B\/L DATE|VESSEL|VOYAGE/.test(r.text));assert.ok(r.text.includes('30-09-2026'));assert.ok(r.end<1028,'TG proforma must leave an inch below content');

 for(const code of ['LC_SIGHT','LC_USANCE']){
  await fixture('TG',code);r=await render(await html('proforma'),'TG-proforma-'+code,true);assert.ok(r.end<1028,'TG '+code+' proforma must fit one page with its original contract terms: '+r.end);assert.ok(r.text.includes('QA-CUSTOMS-601'));assert.ok(!r.text.includes('QA VESSEL'));
 }
 await fixture('TG','ADV_CAD');await mutate(({s,c,p})=>{c.packings[0].containers=1;c.packings.push({...structuredClone(c.packings[0]),brand:'SECOND QA',size:50,masterBag:{enabled:false}});s.millActuals[1].brand='SECOND QA';s.millActuals[1].packing='50 KG';s.millActuals[1].bags=540;p.bagOrders[0].lines.push({...p.bagOrders[0].lines[0],brand:'SECOND QA'})});r=await render(await html('tgInvoice'),'TG-invoice-two-packings',true);assert.ok(r.text.includes('QUANTITY'));assert.ok(r.end<1055,'Two-packing TG invoice must fit: '+r.end);
 // Actual-buyer option, rather than Notify, carries the same full address.
 await fixture('TG','ADV_CAD');await page.evaluate(()=>window.__docsQA.tg());await page.locator('[data-tg-tab="invoice"]').click();await page.locator('#tgShowImporter').check();r=await render(await html('tgPacking'),'TG-packing-actual-buyer');assert.ok(r.text.toUpperCase().includes('BUYER BUILDING 100'));
 // An empty placeholder must never be rendered as an existing FI/GD reference.
 await fixture('TTI','ADV100');await mutate(({s})=>{s.customs.fiAllocations=[{}];s.customs.gdRefs=[{}]});r=await render(await html('cover'),'empty-reference-placeholders');assert.ok(!r.text.includes('[object Object]'));assert.ok(r.text.includes('KINDLY RECORD THE ENCLOSED DOCUMENTS ACCORDINGLY.'));
 // Normal and L/C invoices, both payment bank rules and the compact weight typography.
 for(const code of ['CAD100','ADV_CAD','ADV100','LC_SIGHT']){
  await fixture('TTI',code);r=await render(await html('invoice'),'Direct-invoice-'+code,true);assert.ok(r.text.includes('IN KGS')&&r.text.includes('IN M.TONS'));assert.ok(r.text.includes('54,172.80')&&r.text.includes('54.173'));assert.ok(r.netBold.every(weight=>Number(weight)>=700));assert.ok(r.grossNormal.every(weight=>Number(weight)===400));assert.equal(r.text.includes('PLEASE REMIT THE PROCEEDS'),['CAD100','ADV_CAD'].includes(code));assert.ok(r.end<1055,'Commercial invoice signature must fit');
 }
 await fixture('TG','ADV_CAD','ADV_CAD');await mutate(({s})=>{s.customs.openAccount=0});r=await render(await html('tgInvoice'),'TG-zero-outstanding');assert.ok(!r.text.includes('PLEASE REMIT THE PROCEEDS'));
 assert.equal(await page.evaluate(()=>window.__docsQA.markupDeleted()),'','A removed marking must never fall back to an older artwork');
 assert.deepEqual(errors,[],'No browser JavaScript errors');fs.writeFileSync(path.join(out,'report.json'),JSON.stringify(reports.map(({text,...r})=>r),null,2));await browser.close();console.log('PASS 19-09-2026 document payment matrix, GD copies, print confirmation, TG importer persistence, layout and image QA ('+reports.length+' outputs).');
})().catch(error=>{console.error(error);process.exit(1)});
