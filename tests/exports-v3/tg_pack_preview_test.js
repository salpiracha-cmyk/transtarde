'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),{JSDOM}=require('jsdom');
const dom=new JSDOM('<div id="app"></div><div id="editor"></div><div id="printRoot"></div>',{url:'https://fixture.test/exports/',runScripts:'outside-only'}),w=dom.window;
w.structuredClone=structuredClone;w.setInterval=()=>0;w.confirm=()=>true;let alerts=[];w.alert=s=>alerts.push(s);w.TT_MODULE_ACCESS={user:'Fixture',masters:{}};
let syncs=0,failSave=false,printed=[],delaySave=false,releaseSave;w.TT_SHARED_SYNC={flush(){},saveNow:async()=>{syncs++;if(failSave)throw Error('Fixture save refused');if(delaySave)await new Promise(resolve=>releaseSave=resolve);}};
let source=fs.readFileSync('exports/app.js','utf8');source=source.replace('mount();restoreContractCheckpoint();','window.__tg={state,makeShipment,renderTG,tgRelationshipLetterDoc,tgRelationshipWording,tgRelationshipValidation,tgRelationshipValues,tgInternalCommercialInvoiceDoc,packingListDoc,coveringDoc,setCurrent:id=>{currentShipmentId=id},printer:fn=>{printShipmentDoc=fn}};');w.eval(source);
const t=w.__tg,customer={id:'BUYER',name:'New Buyer',address:'New Buyer Address',notifies:[{name:'Receiver Two',address:'Receiver Two Address'}]};
const c={id:'C',ref:'TG/QA/01',seller:'TG',customerId:'BUYER',date:'2026-10-01',product:'White Rice',variety:'IRRI-6',riceType:'White Rice',hsCode:'1006.30',qty:27,containers:1,tolerance:5,pol:'Port Qasim',podPort:'Banjul',podCountry:'Gambia',packingUnit:'KG',currency:'USD',incoterm:'CFR',paymentCode:'CAD100',terms:[],docs:[],packings:[{size:50,type:'P.P. Bags',brand:'QA',tare:100,containers:1,weightPer:27,price:450,masterBag:{enabled:false}}]};
t.state.customers=[customer];t.state.contracts=[c];const s=t.makeShipment(c);s.id='LOT';s.kind='lot';s.lotId='01';s.customs={...s.customs,rate:400,exporter:'TTI',bank:'Fixture Bank',invoiceNo:'CUSTOM-QA',date:'2026-10-01',fiAllocations:[],gdRefs:[]};s.millActuals=[{number:'MSCU1234567',seal:'QA',bags:540,netKg:27000,tareKg:54,grossKg:27054,brand:'QA',packing:'50 KG'}];
s.tgdocs={relationship:{wording:'Our Importer OLD TRADER is a trader and they have sold this cargo to OLD BUYER. Being the importer of the cargo. OLD BUYER is the final receiver of this cargo. We have Business Relationship with all parties in this Contract.'}};t.state.shipments=[s];t.setCurrent('LOT');t.printer((title,html)=>printed.push({title,html}));
// Resolve a canonical selector label without printing its company/account suffix.
const bankRow=['Company Account','TTI — Transtrade International','','','Fixture Bank','Fixture Branch','','USD','1234567890','','','','','Active'];w.TT_MODULE_ACCESS.masters.banks=[{id:'BANK-QA',values:bankRow}];
const labelled=structuredClone(s);labelled.customs.bank=[bankRow[1],bankRow[4],bankRow[5],bankRow[8]].join(' · ');
const selectedBank=t.tgRelationshipValues(labelled,c);assert.equal(selectedBank.bank,'Fixture Bank');assert.equal(selectedBank.bankAddress,'Fixture Branch');assert.doesNotMatch(selectedBank.bank,/1234567890/);
labelled.tgdocs.relationship.bank='Manually entered bank';assert.equal(t.tgRelationshipValues(labelled,c).bank,'Manually entered bank','Unmatched custom bank names are never split or guessed');
const d=w.document.getElementById('editor'),q=x=>d.querySelector(x),input=(id,value)=>{q(id).value=value;q(id).dispatchEvent(new w.Event('input',{bubbles:true}));},tab=key=>q(`[data-tg-tab="${key}"]`).click();
const before=JSON.stringify(s.tgdocs.relationship);assert.match(t.tgRelationshipLetterDoc(s,c),/OLD BUYER/);assert.equal(JSON.stringify(s.tgdocs.relationship),before,'Reading historical letter does not migrate or rewrite it');
t.renderTG(d);assert.equal(syncs,0);assert.equal(d.querySelectorAll('.lotSplitWorkspace').length,1);assert.ok(q('#tgPreviewPaper .docPage'),'First tab renders a real document');
for(const key of ['invoice','packing','cover','relationship','proforma']){tab(key);assert.ok(q('#tgPreviewPaper .docPage'),key+' preview');}
tab('relationship');assert.match(q('#tgPreviewPaper').textContent,/New Buyer Address/);assert.doesNotMatch(q('#tgPreviewPaper').textContent,/OLD BUYER/);assert.match(s.tgdocs.relationship.previousWording,/OLD BUYER/);
input('#tgRelBank','Another Bank');input('#tgRelBuyer','Trader <img src=x onerror=bad()>');assert.match(q('#tgPreviewPaper').textContent,/Another Bank/);assert.equal(q('#tgPreviewPaper [onerror]'),null,'Party fields are escaped');
const focus=q('#tgRelBuyer');focus.focus();input('#tgRelBuyer','Updated Trader');assert.equal(w.document.activeElement,focus,'Preview update preserves active entry');
q('#tgRelParty').value='NOTIFY:0';q('#tgRelParty').dispatchEvent(new w.Event('change',{bubbles:true}));assert.match(q('#tgPreviewPaper').textContent,/Receiver Two Address/);assert.doesNotMatch(q('#tgPreviewPaper').textContent,/New Buyer Address/);
assert.equal(syncs,0,'Typing, selecting and switching tabs never save');
(async()=>{
 await q('#tgPrintCurrent').onclick();assert.equal(syncs,1);assert.equal(printed.length,1);assert.equal(printed[0].html,q('#tgPreviewPaper').innerHTML,'Print uses exactly the reviewed preview');assert.match(printed[0].html,/Receiver Two Address/);
 delaySave=true;const pending=q('#tgPrintCurrent').onclick();input('#tgRelBank','Unsaved during request');releaseSave();await pending;delaySave=false;assert.equal(printed.length,2);assert.match(printed[1].html,/Another Bank/);assert.doesNotMatch(printed[1].html,/Unsaved during request/,'An in-flight print uses the acknowledged snapshot');
 failSave=true;await q('#tgPrintCurrent').onclick();assert.equal(printed.length,2,'Failed server acknowledgement must not print');failSave=false;
 tab('invoice');q('#tgShowImporter').checked=true;q('#tgShowImporter').dispatchEvent(new w.Event('change',{bubbles:true}));q('#tgImporterChoice').value='NOTIFY:0';q('#tgImporterChoice').dispatchEvent(new w.Event('change',{bubbles:true}));assert.match(q('#tgPreviewPaper').textContent,/Receiver Two/);tab('packing');assert.match(q('#tgPreviewPaper').textContent,/Receiver Two/);
 tab('relationship');q('#tgRelParty').value='INVOICE';q('#tgRelParty').dispatchEvent(new w.Event('change',{bubbles:true}));assert.match(q('#tgPreviewPaper').textContent,/Receiver Two Address/);
 q('#tgRelParty').value='CUSTOM';q('#tgRelParty').dispatchEvent(new w.Event('change',{bubbles:true}));input('#tgRelAddress','');const count=printed.length;await q('#tgPrintCurrent').onclick();assert.equal(printed.length,count);assert.ok(alerts.some(a=>a.includes('receiver address')),'Missing receiver address prevents an incomplete print');
 if(process.env.TT_TG_PACK_BROWSER==='1'){
  const {chromium}=require('playwright'),browser=await chromium.launch({headless:true}),page=await browser.newPage({viewport:{width:1450,height:1000}});
  try{
   await page.route('http://fixture.test/**',route=>route.fulfill({contentType:'text/html',body:'<html><body></body></html>'}));await page.goto('http://fixture.test');
   await page.setContent('<div class="lotEditorShell"><div id="editor"></div></div><div id="printRoot"></div>');
   await page.addStyleTag({content:fs.readFileSync('exports/app.css','utf8')});
   await page.evaluate(()=>{window.TT_MODULE_ACCESS={user:'Fixture',masters:{}};window.TT_SHARED_SYNC={flush(){},saveNow:async()=>{}};window.setInterval=()=>0;});
   await page.addScriptTag({content:source});
   const fixture=JSON.parse(JSON.stringify(t.state));fixture.shipments[0].tgdocs.relationship.finalPartyChoice='INVOICE';
   await page.evaluate(fixture=>{const t=window.__tg;Object.assign(t.state,fixture);t.setCurrent('LOT');t.renderTG(document.querySelector('#editor'));},fixture);
   await page.locator('[data-tg-tab="relationship"]').click();await page.getByLabel('Bank',{exact:true}).fill('Preview Bank');
   await page.locator('#tgPreviewPaper').getByText('Preview Bank',{exact:false}).first().waitFor();
   for(const width of [1450,800,390]){
    await page.setViewportSize({width,height:1000});await page.waitForTimeout(80);
    const sizes=await page.evaluate(()=>({overflow:document.documentElement.scrollWidth-innerWidth,preview:document.querySelector('#tgPreviewPaper').scrollWidth-document.querySelector('#tgPreviewPaper').clientWidth}));
    assert.ok(sizes.overflow<=2&&sizes.preview<=2,JSON.stringify({width,...sizes}));
   }
   fs.mkdirSync('tmp/qa/tg-pack',{recursive:true});await page.setViewportSize({width:1450,height:1000});await page.screenshot({path:'tmp/qa/tg-pack/relationship-preview.png',fullPage:true});
  }finally{await browser.close();}
 }
 console.log('PASS TG pack: five actual previews, linked receiver/address, standard draft, preserved historical wording, escaped values, focus/manual-save safety and acknowledged print parity');w.close();
})().catch(e=>{console.error(e);w.close();process.exitCode=1});
