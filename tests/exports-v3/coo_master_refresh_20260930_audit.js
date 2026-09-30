const fs=require('fs');
const vm=require('vm');
const assert=require('assert');

class Element{
  constructor(id=''){this.id=id;this.dataset={};this.style={setProperty(){}};this.attributes={};this.value='';this.checked=false;this.files=[];}
  set innerHTML(v){this._html=String(v);for(const m of this._html.matchAll(/id="([^"]+)"/g))elements.set(m[1],new Element(m[1]));}
  get innerHTML(){return this._html||'';}
  set textContent(v){this._text=String(v)} get textContent(){return this._text||''}
  setAttribute(k,v){this.attributes[k]=v} getAttribute(k){return this.attributes[k]}
  addEventListener(k,v){this['on'+k]=v} querySelectorAll(){return[]} remove(){}
}
const elements=new Map([['app',new Element('app')],['printRoot',new Element('printRoot')]]);
const document={title:'Test',body:new Element('body'),head:new Element('head'),getElementById:id=>elements.get(id)||null,querySelectorAll:()=>[],querySelector:()=>null,createElement:()=>new Element(),addEventListener(){}};
const storage=new Map();
const localStorage={getItem:k=>storage.has(k)?storage.get(k):null,setItem:(k,v)=>storage.set(k,String(v)),removeItem:k=>storage.delete(k),get length(){return storage.size},key:i=>[...storage.keys()][i]};
const masterProduct=['Rice','IRRI-6','White Rice','IR6-W5','Pakistan','Active','6.0 mm','5% max','14% max','2.5% max','5% max','','','0.8% max','0.5% max','1% max','2% max','Well milled; silky polished; sortexed','Free from live insects, bad odour and rice fit for human consumption.','Master source'];
const window={document,localStorage,TT_MODULE_ACCESS:{user:'Test User',masters:{product_settings:[{id:'PS1',values:['2025/2026']}],products:[{id:'P1',values:masterProduct}],mills:[{id:'M1',values:['Master Mill','MM','External Mill']}],banks:[{id:'B-TG',values:['Company Account','TG — Trans Grains Foodstuff Trading L.L.C','USD Account','Trans Grains Foodstuff Trading L.L.C','Habib Bank AG Zurich','Dubai Branch','United Arab Emirates','USD','00112233','AE07000112233','HBZUAEAD','Customer remittances','Exports','Active']}] }},addEventListener(){},print(){},setTimeout:fn=>fn(),setInterval:()=>0};
const context={window,document,localStorage,console,structuredClone,alert:m=>{throw new Error('Unexpected alert: '+m)},location:{href:''},setTimeout:fn=>fn(),setInterval:()=>0,clearTimeout(){},FileReader:class{},Date,Intl};
context.globalThis=context;
let source=fs.readFileSync(__dirname+'/../../exports/app.js','utf8');
const mutableExportOverrides=source.split('\n').filter(line=>/^[A-Za-z_$][\w$]*\s*=\s*function\s*\(/.test(line)).map(line=>line.match(/^([A-Za-z_$][\w$]*)/)[1]);
assert.deepEqual(mutableExportOverrides,[],'Export module must not contain mutable function override assignments');
source=source.replace('mount();','window.__EXPORT_TEST__={parseContractText,parseLCText,paymentText,lcSpecificTerms,effectiveTerms,documentsPresented,inspectionDocumentName,packingPrefix,unitRate,makeShipment,salesContractPrint,purchaseOrderPrint,commercialInvoiceDoc,tgInternalCommercialInvoiceDoc,packingListDoc,phytoInvoiceDoc,blDraftDoc,coveringDoc,lcControlDoc,lcDraftDoc,actualRows,millActualsComplete,fiGdRows,applyProductMaster,productLabel,productMasters,qualityDescription,contractSpecRows,currentCropYear,millLocations,brokenEntry,normalizeBrokenEntry,brokenEntryValid,finishChoices,DEFAULT_QUALITY,CONTAINER_RE,state,cooDoc,buyerOf,captureShipmentPartyDetails,shipmentDocumentContext,applyExportCustomerMasters};\nmount();');
vm.runInNewContext(source,context,{filename:'app.js'});
const t=window.__EXPORT_TEST__;


const buyer={id:'AMT-QA',masterId:'AMT-MASTER',name:'AMT Enterprise',code:'AMT',address:'AMT Enterprise 21 Pickten Street Banjul the Gambia.',notifies:[{name:'AMT Clearing',address:'Old Notify Road, Banjul'}]};
const contract={id:'AMT-C-QA',ref:'TG/AMT/13',seller:'TG',customerId:buyer.id,date:'2026-09-25',buyerDetails:{address:buyer.address},product:'White Rice',variety:'IRRI-6',riceType:'White Rice',brokenText:'100%',hsCode:'1006.30',qty:270,containers:10,weightPer:27,tolerance:5,pol:'Port Qasim, Pakistan',podPort:'Banjul',podCountry:'The Gambia',packingUnit:'KG',currency:'USD',incoterm:'CFR',paymentCode:'CAD100',terms:[],docs:[],packings:[{size:50,type:'P.P. Bags',brand:'GHAZAL',tare:120,containers:10,weightPer:27,price:400,masterBag:{enabled:false}}]};
t.state.customers=[buyer];t.state.contracts=[contract];const processShipment=t.makeShipment(contract);
const lot={...structuredClone(processShipment),id:'AMT-OPEN-QA',kind:'lot',lotId:'AMT-1',parentProcessId:processShipment.id,containers:10,completed:false};
lot.millActuals=Array.from({length:10},(_,i)=>({number:'MSCU123456'+i,seal:'SEAL'+i,bags:540,netKg:27000,tareKg:64.8,grossKg:27064.8,brand:'GHAZAL',packing:'50 KG'}));
lot.bl={...lot.bl,vessel:'SLS TOPAZ',voyage:'640W',blNo:'MAEU276778753',onBoardDate:'2026-10-02',consignee:buyer.name+', '+buyer.address,notify:buyer.notifies[0].name+', '+buyer.notifies[0].address};
lot.commercial={...lot.commercial,invoiceNo:contract.ref,date:'2026-10-02',packingConsignee:lot.bl.consignee,packingNotify:lot.bl.notify,notifyParty:lot.bl.consignee};
lot.customs={...lot.customs,exporter:'TTI',description:'PAKISTAN LONG GRAIN WHITE RICE, 100% BROKEN, WELL MILLED, SILKY POLISHED AND WELL SORTEXED, NEW CROP 2026/2027. AS PER PAKISTAN ORIGIN STANDARDS.',rate:400};
lot.coo={...lot.coo,date:'2026-09-25',membershipNo:'36453'};
const closed=structuredClone(lot);closed.id='AMT-CLOSED-QA';closed.completed=true;closed.status='Completed';t.state.shipments=[processShipment,lot,closed];
localStorage.setItem('transtrade_export_v3_operational',JSON.stringify(t.state));lot.bl.vessel='UNSAVED VESSEL';
const closedBefore=t.cooDoc(closed,contract),financials=JSON.stringify({customs:lot.customs,rate:contract.packings[0].price}),master={...buyer,name:'AMT Enterprise',address:'21 Pickten Street Banjul the Gambia.',notifies:[{name:'AMT Clearing',address:'New Notify Road, Banjul'}]};
assert.equal(t.applyExportCustomerMasters([master]),true);
assert.equal(t.buyerOf(contract).address,master.address);
assert.equal(lot.bl.vessel,'UNSAVED VESSEL','master refresh keeps an unfinished entry in memory');
assert.equal(JSON.parse(localStorage.getItem('transtrade_export_v3_operational')).shipments.find(s=>s.id===lot.id).bl.vessel,'SLS TOPAZ','master refresh must not autosave an unfinished entry');
assert.equal(lot.bl.consignee,master.name+', '+master.address);
assert.equal(lot.commercial.packingConsignee,lot.bl.consignee);
assert.ok(lot.bl.notify.includes('New Notify Road'));
assert.equal(JSON.stringify({customs:lot.customs,rate:contract.packings[0].price}),financials,'master refresh must leave transaction values and saved descriptions intact');
assert.equal(t.cooDoc(closed,contract),closedBefore,'completed lot output must retain its original party details');
assert.equal(closed.bl.consignee,buyer.name+', AMT Enterprise 21 Pickten Street Banjul the Gambia.');
const openCoo=t.cooDoc(lot,contract);
assert.doesNotMatch(openCoo,/Amt Enterprise 21 Pickten/i);
assert.doesNotMatch(openCoo,/25-09-2026|GOODS OF PAKISTAN ORIGIN/);
assert.match(openCoo,/INVOICE NO\. TG\/AMT\/13 DATED 02-10-2026/);
assert.match(openCoo,/cooFixedName/);assert.match(openCoo,/cooFixedDesignation/);assert.match(openCoo,/cooFixedCompany/);
assert.equal(t.applyExportCustomerMasters([master]),false,'an unchanged refresh must not save another revision');
t.captureShipmentPartyDetails(lot,contract);lot.completed=true;lot.status='Completed';const newlyClosed=t.cooDoc(lot,contract);
localStorage.setItem('transtrade_export_v3_operational',JSON.stringify(t.state));
t.applyExportCustomerMasters([{...master,name:'AMT Renamed',address:'Another Address',notifies:[]}]);
assert.equal(t.cooDoc(lot,contract),newlyClosed,'a newly completed lot keeps the party details from completion');
assert.ok(JSON.parse(localStorage.getItem('transtrade_export_v3_operational')).shipments.find(s=>s.id===lot.id).documentParties);
const {chromium}=require('playwright'),path=require('path'),out=path.resolve(__dirname,'../../tmp/qa/coo-20260930');fs.mkdirSync(out,{recursive:true});
(async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{})}),page=await browser.newPage({viewport:{width:1000,height:1200}});
 const css=fs.readFileSync(path.resolve(__dirname,'../../exports/app.css'),'utf8'),art=fs.readFileSync(path.resolve(__dirname,'../../exports/assets/KCCI_COO_letterpad.jpg')).toString('base64');
 const render=async(markup,name)=>{
  await page.setContent('<html><head><style>'+css+'</style></head><body><div id="printRoot" aria-hidden="false" style="display:block">'+markup.replace('assets/KCCI_COO_letterpad.jpg','data:image/jpeg;base64,'+art)+'</div></body></html>');
  await page.locator('.cooLetterpadBackground').evaluate(im=>im.decode());
  const fitting=source.slice(source.indexOf('function fitCOOPages('),source.indexOf('function fitTGProformaPages('));await page.addScriptTag({content:fitting});
  assert.equal(await page.evaluate(()=>fitCOOPages(document.getElementById('printRoot'))),true,name+' must fit without clipping: '+JSON.stringify(await page.locator('.cooFixedField').evaluateAll(nodes=>nodes.filter(n=>n.scrollHeight>n.clientHeight+1||n.scrollWidth>n.clientWidth+1).map(n=>({field:n.className,font:getComputedStyle(n).fontSize,scroll:n.scrollHeight,height:n.clientHeight,text:n.innerText})))));
  const measure=()=>{const p=document.querySelector('.cooLetterpadPage'),b=p.getBoundingClientRect();return{width:b.width,height:b.height,fields:[...p.querySelectorAll('.cooFixedField')].map(node=>{const r=node.getBoundingClientRect(),range=document.createRange();range.selectNodeContents(node);return{class:node.className,left:(r.left-b.left)/b.width,top:(r.top-b.top)/b.height,right:(r.right-b.left)/b.width,bottom:(r.bottom-b.top)/b.height,overflowX:node.scrollWidth-node.clientWidth,overflowY:node.scrollHeight-node.clientHeight,textRects:[...range.getClientRects()].map(x=>({left:x.left-r.left,right:x.right-r.right,top:x.top-r.top,bottom:x.bottom-r.bottom}))}})}};
  const screen=await page.evaluate(measure);await page.emulateMedia({media:'print'});const printed=await page.evaluate(measure);
  assert.ok(Math.abs(screen.height-297*96/25.4)<1&&Math.abs(screen.width-210*96/25.4)<1);
  assert.deepEqual(printed,screen,'preview and print must use identical page and field positions');
  for(const field of printed.fields){assert.ok(field.overflowX<=1&&field.overflowY<=1,name+' overflow '+field.class);assert.ok(field.textRects.every(x=>x.left>=-1&&x.right<=1&&x.top>=-1&&x.bottom<=1),name+' text crosses field '+field.class)}
  const membership=printed.fields.find(x=>x.class.includes('cooFixedMembership'));assert.ok(membership.top>.24&&membership.bottom<.263,'membership stays inside the membership box');
  const packs=printed.fields.find(x=>x.class.includes('cooFixedPackages'));assert.ok(packs.left>.157&&packs.right<.262);
  const weights=printed.fields.find(x=>x.class.includes('cooFixedWeight'));assert.ok(weights.left>.73&&weights.right<.855);
  for(const [className,line] of [['cooFixedName',.899],['cooFixedDesignation',.9245],['cooFixedCompany',.950]])assert.ok(printed.fields.find(x=>x.class.includes(className)).bottom<=line+.002,className+' sits above its line');
  await page.locator('.cooLetterpadPage').screenshot({path:path.join(out,name+'.png')});
  await page.pdf({path:path.join(out,name+'.pdf'),format:'A4',printBackground:true,preferCSSPageSize:true});
  await page.emulateMedia({media:'screen'});return printed;
 };
 const measurements=await render(openCoo,'amt-coo');
 const stress=structuredClone(closed);stress.completed=false;stress.status='Lot Created';delete stress.documentParties;
 const longContract={...contract,buyerDetails:{address:'Long Address Road '.repeat(12)},customerId:'LONG-QA'};
 t.state.customers.push({id:'LONG-QA',name:'Long International Trading Company',address:longContract.buyerDetails.address});
 await render(t.cooDoc(stress,longContract),'long-address-coo');
 fs.writeFileSync(path.join(out,'report.json'),JSON.stringify(measurements,null,2));
 await browser.close();console.log('PASS active master amendment, closed-lot snapshots, unchanged financials, and AMT COO screen/print alignment');
})().catch(e=>{console.error(e);process.exit(1)});
