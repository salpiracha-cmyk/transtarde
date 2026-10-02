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
source=source.replace('mount();','window.__EXPORT_TEST__={parseContractText,parseLCText,paymentText,lcSpecificTerms,effectiveTerms,documentsPresented,inspectionDocumentName,packingPrefix,unitRate,makeShipment,salesContractPrint,purchaseOrderPrint,commercialInvoiceDoc,tgInternalCommercialInvoiceDoc,packingListDoc,phytoInvoiceDoc,blDraftDoc,coveringDoc,lcControlDoc,lcDraftDoc,actualRows,millActualsComplete,fiGdRows,applyProductMaster,productLabel,productMasters,qualityDescription,contractSpecRows,currentCropYear,millLocations,brokenEntry,normalizeBrokenEntry,brokenEntryValid,finishChoices,DEFAULT_QUALITY,CONTAINER_RE,state,cooDoc,buyerOf,captureShipmentPartyDetails,shipmentDocumentContext,applyExportCustomerMasters,uploadDocumentCategory,shipmentUploadName,recordUploadedDocument,finalShipmentDocuments,shipmentFolderDocuments,customsInvoiceValue,shipmentGDReferences,validGDReferenceDate,outputBrand,formatOutputBrandReferences,commercialDescriptionText,blAutoDescription,renderDocumentOutput,renderUploadDocuments};\nmount();');
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

t.state.shipments=[processShipment,lot];lot.commercial.saved=true;lot.commercial.status='Final';lot.seller='TTI';contract.seller='TTI';

assert.match(fs.readFileSync(__dirname+'/../../module.php','utf8'),/exports-shipment-files/,'protected module runtime must inline folder-saving code');
const delivery=fs.readFileSync(__dirname+'/../../api/export_pdf_assets.php','utf8');assert.match(delivery,/tt_current_user/);assert.match(delivery,/tt_user_can_open_module/);assert.match(delivery,/\$assets\[/);
const phytoName='One printout of e-Phyto issued by Department of Plant Protection, Government of Pakistan, (QR verifiable)';
assert.ok(t.uploadDocumentCategory('lot-document-'+phytoName).length<=64);
assert.match(t.uploadDocumentCategory('lot-document-'+phytoName),/^[a-z0-9-]{3,64}$/);
assert.equal(t.shipmentUploadName(phytoName),'Phytosanitary Certificate');
const png='data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAB4AAAAoCAIAAABmcd1FAAAAKklEQVR4nO3MQQEAAAQEMPTvfFLw2gKsk9SNOXrVarVarVar1Wq1Wv1YL8DqA03dgFloAAAAAElFTkSuQmCC';
const coo={id:'COO-QA',name:'CO.png',dataUrl:png};lot.coo.finalDocument=coo;lot.uploadedDocuments=[{name:'Certificate of Origin',finalDocument:coo},{name:'Commercial Invoice',finalDocument:{id:'old-ci',name:'old-invoice.png',dataUrl:png}}];
t.recordUploadedDocument(lot,phytoName,{id:'PHYTO-QA',name:'phyto.png',dataUrl:png},'PC-2026-014');
assert.equal(lot.certs.find(row=>row.type==='Phytosanitary Certificate').reference,'PC-2026-014');
assert.ok(t.finalShipmentDocuments(lot,contract).some(row=>row.name==='Phytosanitary Certificate'&&row.ready));
assert.equal(t.finalShipmentDocuments(lot,contract).filter(row=>row.name==='Certificate of Origin').length,1);
assert.ok(!t.finalShipmentDocuments(lot,contract).some(row=>/Origin Draft|Phytosanitary Invoice|Custom Packing/i.test(row.name)));
// Folder routing uses the buyer customer for every exporter, independent of the TG remitter.
for(const route of ['TTI','BRM','TG']){
 const fixture=structuredClone(lot),agreement=structuredClone(contract);fixture.seller=agreement.seller=route;
 fixture.customs={...fixture.customs,saved:true,customsPaymentCode:'CAD100',fiAllocations:[]};
 fixture.customs.invoiceValue=fixture.customs.openAccount=t.customsInvoiceValue(fixture,agreement);fixture.tgdocs={saved:true};
 const rows=t.shipmentFolderDocuments(fixture,agreement);
 assert.equal(rows.filter(row=>row.folder==='Custom documents'&&row.ready).length,3,route+' must include all reviewed Customs PDFs');
 assert.equal(rows.filter(row=>row.folder==='TG docs').length,route==='TG'?4:0,'optional empty relationship must not create an empty PDF');
 assert.ok(rows.filter(row=>['commercial','packing'].includes(row.key)).every(row=>!row.folder));
}
assert.deepEqual(JSON.parse(JSON.stringify(t.shipmentGDReferences({customs:{gdRefs:[]},bl:{gdRefs:[{number:'KPEX-SB-37338',date:'12-09-2026'}]}}))),[{number:'KPEX-SB-37338',date:'2026-09-12'}]);
assert.equal(t.validGDReferenceDate('2026-02-30'),false);assert.equal(t.validGDReferenceDate('2026-09-12'),true);
assert.equal(t.outputBrand('Ghazal'),'"GHAZAL" Brand');assert.equal(t.outputBrand('"GHAZAL" Brand'),'"GHAZAL" Brand');
assert.equal(t.formatOutputBrandReferences('Ghazal brand / "GHAZAL" BRAND',contract),'"GHAZAL" Brand / "GHAZAL" Brand');
assert.equal(t.formatOutputBrandReferences('A+B brand',{packings:[{brand:'A+B'}]}),'"A+B" Brand');
assert.equal(t.formatOutputBrandReferences('Ghazal Classic / Ghazal',{packings:[{brand:'Ghazal'},{brand:'Ghazal Classic'}]}),'"GHAZAL CLASSIC" Brand / "GHAZAL" Brand');
for(const markup of [t.salesContractPrint(contract),t.purchaseOrderPrint({poNo:'QA-PO',lines:[{brand:'Ghazal',type:'P.P. Bags',size:50,totalBags:5400}]}),t.blDraftDoc(lot,contract),t.commercialInvoiceDoc(lot,contract,true),t.packingListDoc(lot,contract,true),t.phytoInvoiceDoc(lot,contract),t.commercialInvoiceDoc(lot,contract),t.packingListDoc(lot,contract),t.cooDoc(lot,contract),t.tgInternalCommercialInvoiceDoc(lot,contract)]){
 assert.match(markup,/&quot;GHAZAL&quot; Brand/,'all document brand labels must use one canonical format');assert.doesNotMatch(markup,/Brand brand|&quot;&quot;GHAZAL/);
}
const descriptionFixture=structuredClone(lot),originalCustoms=JSON.stringify(descriptionFixture.customs);descriptionFixture.bl={...descriptionFixture.bl,draftSaved:true,descriptionAuto:false,description:'B/L AMENDED WHITE RICE — BUYER APPROVED.\nBROKEN: 5% MAX\nHS CODE: 1006.30'};
for(const route of ['TTI','BRM','TG'])for(const paymentCode of ['CAD100','LC_SIGHT']){
 const shipment=structuredClone(descriptionFixture),agreement={...contract,seller:route,paymentCode};shipment.seller=route;shipment.lc={...shipment.lc,goodsDescription:'OLD LC DESCRIPTION',lcNo:'QA-LC',lcDate:'2026-09-12'};
 for(const markup of [t.commercialInvoiceDoc(shipment,agreement),t.packingListDoc(shipment,agreement),t.cooDoc(shipment,agreement),t.tgInternalCommercialInvoiceDoc(shipment,agreement)]){
  assert.match(markup,/B\/L AMENDED WHITE RICE/);assert.doesNotMatch(markup,/NEW CROP 2026\/2027|OLD LC DESCRIPTION/);assert.equal((markup.match(/HS CODE: 1006.30/g)||[]).length,1,'saved B/L HS code must not be duplicated');
 }
 assert.match(t.commercialInvoiceDoc(shipment,agreement,true),/NEW CROP 2026\/2027/,'Customs description remains controlled by Customs');
}
assert.equal(JSON.stringify(descriptionFixture.customs),originalCustoms);
const autoText=t.blAutoDescription(lot,contract),autoShipment={...descriptionFixture,bl:{...descriptionFixture.bl,description:autoText.replace('PAKISTAN LONG GRAIN WHITE RICE','AMENDED B/L GOODS')}};
assert.match(t.commercialDescriptionText(autoShipment,contract),/AMENDED B\/L GOODS/);assert.doesNotMatch(t.commercialDescriptionText(autoShipment,contract),/SAID TO CONTAIN|TOTAL NET WEIGHT|FEET CONTAINERS/);
assert.ok(t.blAutoDescription(autoShipment,contract).startsWith('SAID TO CONTAIN:'),'B/L generation must not recursively include an old B/L envelope');
const {chromium}=require('playwright'),path=require('path'),out=path.resolve(__dirname,'../../tmp/qa/final-files-20260930');fs.mkdirSync(out,{recursive:true});
(async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{})}),page=await browser.newPage({viewport:{width:1400,height:1300}});
 const root=path.resolve(__dirname,'../..');
 await page.route('http://tt.local/exports/**',route=>{const url=new URL(route.request().url()),file=url.pathname.replace('/exports/','');if(file==='qa.html')return route.fulfill({body:'<html><head></head><body><div id="app"></div><div id="qa-editor"></div><div id="printRoot"></div></body></html>',contentType:'text/html'});return route.fulfill({path:path.join(root,'exports',file)})});
 await page.route('http://tt.local/api/export_pdf_assets.php?**',route=>{const asset=new URL(route.request().url()).searchParams.get('asset');return route.fulfill({path:path.join(root,'exports/vendor',asset==='html2canvas'?'html2canvas-1.4.1.min.js':asset==='pdf-lib'?'pdf-lib-1.17.1.min.js':'jspdf-4.2.1.umd.min.js'),contentType:'application/javascript'})});
 await page.goto('http://tt.local/exports/qa.html');await page.addStyleTag({content:fs.readFileSync(path.join(root,'exports/app.css'),'utf8')});
 let app=fs.readFileSync(path.join(root,'exports/app.js'),'utf8').replace('mount();restoreContractCheckpoint();',`window.__qa={fixture(root){state=root;currentShipmentId=root.shipments.find(row=>row.kind==='lot').id;localStorage.setItem(STORE,JSON.stringify(root));renderShipmentWorkspace=()=>{}},uploads(){renderUploadDocuments(document.getElementById('qa-editor'))},bl(){renderBL(document.getElementById('qa-editor'))},final(){renderDocumentOutput(document.getElementById('qa-editor'))},commercial(){renderCommercial(document.getElementById('qa-editor'))},cover(){renderBankCoveringEditor(document.getElementById('qa-editor'),shipment(),contractByRef(shipment().contractRef),shipment().covering)},missing(){return completionMissing(shipment(),contractByRef(shipment().contractRef))},capturePrint(){printShipmentDoc=(title,html)=>{window.__invoicePrinted={title,html,serverSaves:window.__invoiceServerSaves}}},rows(){return finalShipmentDocuments(shipment(),contractByRef(shipment().contractRef))},state(){return state},upload(file,name){return uploadDocument(file,name,shipment())},packing(){return commercialCopyLabel(packingListDoc(shipment(),contractByRef(shipment().contractRef)),'ORIGINAL')},save(optional=false){return saveShipmentFolder(shipment(),contractByRef(shipment().contractRef),optional)}};`);
 await page.evaluate(()=>{window.TT_MODULE_ACCESS={user:'QA'};window.TT_SHARED_SYNC={saveNow:()=>Promise.resolve({ok:true}),flush(){}};window.alert=message=>{throw Error(message)}});
 await page.addScriptTag({content:app});await page.evaluate(data=>window.__qa.fixture(data),JSON.parse(JSON.stringify(t.state)));
 await page.addScriptTag({content:fs.readFileSync(path.join(root,'exports/shipment-files.js'),'utf8')});
 const invoiceFixture=structuredClone(t.state),invoiceLot=invoiceFixture.shipments.find(row=>row.kind==='lot');invoiceLot.commercial.saved=false;invoiceLot.commercial.status='Draft';invoiceLot.commercial.bankInvoices=[];
 await page.evaluate(data=>{window.__qa.fixture(data);window.__invoiceServerSaves=0;window.TT_SHARED_SYNC.saveNow=async()=>{window.__invoiceServerSaves++;return{ok:true}};window.__qa.capturePrint();window.__qa.commercial()},invoiceFixture);
 assert.equal(await page.locator('#createBankInvoice').innerText(),'EDIT FOR RETENTION');assert.equal(await page.locator('#printRetentionInvoice').innerText(),'PRINT RETENTION INVOICE');
 assert.ok(await page.locator('#createBankInvoice').evaluate(node=>node.closest('.commercialRetentionControls').previousElementSibling.classList.contains('commercialPrintControls')),'retention actions must appear below main invoice print controls');
 await page.locator('#coNo').fill('TG/AMT/13-EDITED');await page.locator('#saveCommercial').click();await page.waitForFunction(()=>window.__invoiceServerSaves>=1);
 assert.equal(await page.evaluate(()=>JSON.parse(localStorage.getItem('transtrade_export_v3_operational')).shipments.find(row=>row.kind==='lot').commercial.invoiceNo),'TG/AMT/13-EDITED');
 await page.locator('#coNo').fill('TG/AMT/13-PRINT');await page.locator('#printCommercial').click();await page.waitForFunction(()=>window.__invoicePrinted);
 assert.ok(await page.evaluate(()=>window.__invoicePrinted.serverSaves>=2),'printing must wait for server-save');assert.equal(await page.evaluate(()=>window.__qa.state().shipments.find(row=>row.kind==='lot').commercial.saved),true);
 assert.equal(await page.evaluate(()=>JSON.parse(localStorage.getItem('transtrade_export_v3_operational')).shipments.find(row=>row.kind==='lot').commercial.invoiceNo),'TG/AMT/13-PRINT');
 await page.locator('#createBankInvoice').click();await page.locator('#bankRetention').fill('1000');await page.locator('#bankInvoiceReason').fill('Bank retention');await page.locator('#saveBankInvoiceVersion').click();await page.locator('#saveBankInvoiceVersion').waitFor({state:'detached'});await page.evaluate(()=>window.__qa.commercial());await page.locator('#printRetentionInvoice').click();
 assert.equal(await page.evaluate(()=>window.__invoicePrinted.title),'RETENTION INVOICE');assert.match(await page.evaluate(()=>window.__invoicePrinted.html),/LESS RETENTION AMOUNT/);
 const coverFixture=structuredClone(invoiceFixture),coverLot=coverFixture.shipments.find(row=>row.kind==='lot');coverLot.covering={bank:'QA BANK',bankAddress:'QA Branch',dispatchDate:'2026-10-01',frozen:true,saved:true,notes:[]};
 await page.evaluate(data=>{window.__qa.fixture(data);window.__invoicePrinted=null;window.__qa.cover()},coverFixture);
 assert.equal(await page.locator('#saveCover').count(),0,'freeze button is removed');assert.equal(await page.locator('#printCover').innerText(),'SAVE / PRINT');await page.locator('#printCover').click();await page.waitForFunction(()=>window.__invoicePrinted?.title==='BANK COVERING LETTER');
 const savedCover=await page.evaluate(()=>JSON.parse(localStorage.getItem('transtrade_export_v3_operational')).shipments.find(row=>row.kind==='lot').covering);assert.equal(savedCover.saved,true);assert.equal(savedCover.frozen,false);assert.equal(savedCover.versions.length,1,'old saved letter is retained without requiring a freeze or amendment reason');
 const uploadedFumigation=structuredClone(invoiceFixture);uploadedFumigation.contracts[0].docs=['Fumigation Certificate'];const fumigationLot=uploadedFumigation.shipments.find(row=>row.kind==='lot');fumigationLot.certs=[];fumigationLot.uploadedDocuments=[{name:'Fumigation Certificate',finalDocument:{id:'FUM-QA'}}];fumigationLot.covering={dispatched:true,frozen:true,documentCounts:{'Fumigation Certificate':{originals:1,copies:1}}};
 await page.evaluate(data=>window.__qa.fixture(data),uploadedFumigation);assert.ok(!(await page.evaluate(()=>window.__qa.missing())).some(name=>/fumigation/i.test(name)),'uploaded fumigation original must satisfy completion');
 fumigationLot.covering.documentCounts['Fumigation Certificate'].originals=0;await page.evaluate(data=>window.__qa.fixture(data),uploadedFumigation);assert.ok(!(await page.evaluate(()=>window.__qa.missing())).some(name=>/dispatch|covering|frozen/.test(name)),'dispatch and covering letters must not block lot completion');
 await page.evaluate(data=>window.__qa.fixture(data),JSON.parse(JSON.stringify(t.state)));
 const officeLocations=await page.evaluate(()=>({windows:window.TT_SHIPMENT_FILES.officeLocation('Win32'),mac:window.TT_SHIPMENT_FILES.officeLocation('MacIntel')}));
 assert.equal(officeLocations.windows.path,String.raw`\\tti-server\TTI DOCS\Transtrade software shipment documents`);assert.equal(officeLocations.mac.path,'smb://tti-server/TTI DOCS/Transtrade software shipment documents');assert.match(officeLocations.windows.connect,/File Explorer/);assert.match(officeLocations.mac.connect,/Finder/);
 await page.evaluate(()=>window.__qa.final());assert.equal(await page.locator('.officeShipmentPath,#chooseShipmentFolder,#saveShipmentFiles').count(),0);assert.equal(await page.locator('#completeLot').innerText(),'LOT COMPLETE');
 let multipart='';await page.route('http://tt.local/api/export_documents.php',route=>{multipart=route.request().postData();return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,document:{id:'a'.repeat(32),name:'phyto.png',downloadUrl:'/api/export_documents.php?id='+'a'.repeat(32)}})})});
 await page.evaluate(async name=>{window.TT_MODULE_ACCESS.csrf='fixture-csrf';await window.__qa.upload(new File([new Uint8Array([1])],'phyto.png',{type:'image/png'}),'lot-document-'+name);delete window.TT_MODULE_ACCESS.csrf},phytoName);
 const category=multipart.match(/name="category"\r\n\r\n([^\r]+)/)[1];assert.match(category,/^[a-z0-9-]{3,64}$/,'actual multipart upload category must satisfy the server API');
 await page.evaluate(()=>window.__qa.uploads());await page.locator('#uploadDocumentType').selectOption('Phytosanitary Certificate');assert.equal(await page.locator('#uploadDocumentReference').getAttribute('readonly'),null);await page.locator('#uploadDocumentReference').fill('PC-2026-999');
 await page.locator('#lotDocumentFile').setInputFiles({name:'phyto.png',mimeType:'image/png',buffer:Buffer.from(png.split(',')[1],'base64')});await page.locator('#saveLotDocument').click();await page.waitForFunction(()=>window.__qa.state().certs===undefined&&window.__qa.state().shipments.find(row=>row.kind==='lot').certs.some(row=>row.reference==='PC-2026-999'));
 const uploadBaseline=await page.evaluate(()=>JSON.parse(localStorage.getItem('transtrade_export_v3_operational')));
 const blFixture=structuredClone(uploadBaseline);const blLot=blFixture.shipments.find(row=>row.kind==='lot');blLot.customs.gdRefs=[];blLot.bl.gdRefs=[];
 await page.evaluate(data=>window.__qa.fixture(data),blFixture);await page.evaluate(()=>window.__qa.bl());await page.locator('[data-bl-gd-number="0"]').fill('KPEX-SB-37338');await page.locator('[data-bl-gd-date="0"]').fill('2026-09-12');await page.locator('#blDesc').fill('AMENDED WHITE RICE FROM SAVED B/L DRAFT');await page.locator('#saveBL').click();assert.ok(await page.evaluate(()=>window.__qa.rows().filter(row=>['commercial','packing'].includes(row.key)).every(row=>row.render().includes('AMENDED WHITE RICE FROM SAVED B/L DRAFT'))),'saved B/L description must reach final generated documents');
 await page.evaluate(()=>window.__qa.uploads());await page.locator('#uploadDocumentType').selectOption('Goods Declaration (GD)');assert.equal(await page.locator('[data-upload-gd-number="0"]').inputValue(),'KPEX-SB-37338');assert.equal(await page.locator('[data-upload-gd-date="0"]').inputValue(),'2026-09-12','GD selected and saved in B/L Draft must flow to uploads');
 for(const source of ['customs','bl','missing']){
  const fixture=structuredClone(uploadBaseline),shipment=fixture.shipments.find(row=>row.kind==='lot');shipment.customs.gdRefs=[];shipment.bl.gdRefs=[];
  if(source!=='missing')shipment[source].gdRefs=[{number:'KPEX-SB-37338',date:source==='bl'?'12-09-2026':'2026-09-12'}];
  await page.evaluate(data=>window.__qa.fixture(data),fixture);await page.evaluate(()=>window.__qa.uploads());await page.locator('#uploadDocumentType').selectOption('Goods Declaration (GD)');
  assert.equal(await page.locator('#uploadGDRefs').count(),0,'GD must not require formatted text');assert.equal(await page.locator('#uploadDocumentReferenceWrap').isVisible(),false);
  assert.equal(await page.locator('[data-upload-gd-number="0"]').inputValue(),source==='missing'?'':'KPEX-SB-37338');assert.equal(await page.locator('[data-upload-gd-date="0"]').inputValue(),source==='missing'?'':'2026-09-12');
  await page.locator('#lotDocumentFile').setInputFiles({name:'GD.png',mimeType:'image/png',buffer:Buffer.from(png.split(',')[1],'base64')});
  if(source==='missing'){
   await page.locator('[data-upload-gd-number="0"]').fill('KPEX-SB-37338');await page.locator('#saveLotDocument').click();assert.match(await page.locator('#uploadDocumentMessage').innerText(),/Enter each GD number and date/);
   assert.equal(await page.evaluate(()=>window.__qa.state().shipments.find(row=>row.kind==='lot').customs.gdRefs.length),0,'typing and validation errors must not commit GD references');
   await page.locator('[data-upload-gd-date="0"]').fill('2026-09-12');await page.locator('#addUploadGD').click();await page.locator('[data-upload-gd-number="1"]').fill('KPEX-SB-37339');await page.locator('[data-upload-gd-date="1"]').fill('2026-09-13');
  }
  await page.locator('#saveLotDocument').click();await page.waitForFunction(()=>window.__qa.state().shipments.find(row=>row.kind==='lot').customs.gdDocument);
  const saved=await page.evaluate(()=>JSON.parse(localStorage.getItem('transtrade_export_v3_operational')).shipments.find(row=>row.kind==='lot'));
  assert.deepEqual(saved.customs.gdRefs,saved.bl.gdRefs,'uploaded GD references must flow back to B/L Draft');assert.equal(saved.customs.gdRefs.length,source==='missing'?2:1);assert.equal(saved.customs.gdRefs[0].date,'2026-09-12');assert.equal(saved.customs.gdDocument.gdRefsFingerprint,JSON.stringify(saved.customs.gdRefs));
  assert.ok(saved.uploadedDocuments.find(row=>row.name==='Goods Declaration (GD)').reference.includes('12-09-2026'));
 }
 await page.evaluate(data=>window.__qa.fixture(data),uploadBaseline);
 await page.evaluate(()=>window.__qa.final());assert.equal(await page.locator('#qa-editor input[type="checkbox"]').count(),0);assert.equal(await page.locator('#qa-editor tr').filter({has:page.getByText('Certificate of Origin',{exact:true})}).count(),1);assert.equal(await page.locator('#saveShipmentFiles').count(),0);assert.equal(await page.locator('#printSet').count(),0);
 await page.locator('#qa-editor').screenshot({path:path.join(out,'merged-final-documents.png')});
 const markup=await page.evaluate(()=>window.__qa.packing());await page.evaluate(html=>{const node=document.getElementById('printRoot');node.style.display='block';node.innerHTML=html},markup);await page.locator('#printRoot img').evaluateAll(async images=>await Promise.all(images.map(image=>image.decode())));
 const positions=await page.evaluate(()=>{const label=document.querySelector('#printRoot .commercialCopyLabel').getBoundingClientRect(),ref=document.querySelector('#printRoot .approvedInvoiceReference').getBoundingClientRect();return{labelBottom:label.bottom,refTop:ref.top,position:getComputedStyle(document.querySelector('#printRoot .commercialCopyLabel')).position}});assert.equal(positions.position,'static');assert.ok(positions.labelBottom<=positions.refTop+1);
 await page.emulateMedia({media:'print'});const printed=await page.evaluate(()=>{const label=document.querySelector('#printRoot .commercialCopyLabel').getBoundingClientRect(),ref=document.querySelector('#printRoot .approvedInvoiceReference').getBoundingClientRect();return{bottom:label.bottom,top:ref.top}});assert.ok(printed.bottom<=printed.top+1);await page.emulateMedia({media:'screen'});
 await page.evaluate(()=>{window.showDirectoryPicker=async()=>({name:'Downloads'})});
 const wrongFolder=await page.evaluate(async()=>{try{await window.TT_SHIPMENT_FILES.choose();return''}catch(error){return error.message}});assert.match(wrongFolder,/mounted TTI DOCS share/,'local Downloads must not be accepted as the office root');
 await page.evaluate(()=>{window.__savedFiles={};window.__folderCalls=[];const root={name:'Transtrade software shipment documents',async queryPermission(){return'granted'},async getDirectoryHandle(name){const make=parts=>({async getDirectoryHandle(name){window.__folderCalls.push(parts.concat(name).join('/'));return make(parts.concat(name))},async getFileHandle(name){return{async createWritable(){let data;return{async write(blob){data=await blob.arrayBuffer()},async close(){window.__savedFiles[parts.concat(name).join('/')]=Array.from(new Uint8Array(data))},async abort(){}}}}}});window.__folderCalls.push(name);return make([name])}};window.showDirectoryPicker=async()=>root});
 // Fake handles cannot be serialized into IndexedDB; choose saves it in memory first.
 await page.evaluate(()=>window.TT_SHIPMENT_FILES.choose().catch(()=>{}));
 const first=await page.evaluate(()=>window.__qa.save());assert.ok(first.path.includes('AMT Enterprise / SHIPMENT #13 / LOT #AMT-1'));assert.ok(first.count>=5);
 const files=await page.evaluate(()=>window.__savedFiles);for(const [name,bytes]of Object.entries(files))if(/Master Shipment Documents\.pdf$/.test(name)){assert.equal(Buffer.from(bytes).subarray(0,4).toString(),'%PDF');fs.writeFileSync(path.join(out,path.basename(name)),Buffer.from(bytes))}
 assert.ok(Object.keys(files).some(name=>name.endsWith('/phyto.png')),'issued phyto is copied into the lot folder');
 assert.equal(await page.evaluate(()=>window.TT_SHIPMENT_FILES.folderParts('AMT Enterprise','TTI/AMT/14','TTI/AMT/14/L02').join('/')),'AMT Enterprise/SHIPMENT #14/LOT #02');
 const draftCopy=await page.evaluate(async()=>{const saved=JSON.parse(localStorage.getItem('transtrade_export_v3_operational'));const copy=structuredClone(saved);copy.shipments.find(row=>row.kind==='lot').commercial.status='Draft';window.__qa.fixture(copy);await window.__qa.save();const found=Object.keys(window.__savedFiles).some(path=>path.endsWith('Commercial Invoice - Draft.pdf'));window.__qa.fixture(saved);return found});assert.equal(draftCopy,true,'Save Draft includes an explicitly labelled draft PDF in the office folder');
 const second=await page.evaluate(()=>window.__qa.save());assert.equal(second.path,first.path,'repeat Save reuses the same folder');assert.equal(second.count,first.count,'repeat Save does not duplicate attachments');
 await page.evaluate(()=>{window.__qa.state().shipments.find(row=>row.kind==='lot').bl.vessel='UNSAVED CHANGE'});await page.evaluate(()=>window.__qa.save());assert.equal(await page.evaluate(()=>JSON.parse(localStorage.getItem('transtrade_export_v3_operational')).shipments.find(row=>row.kind==='lot').bl.vessel),'SLS TOPAZ','folder Save must not commit unfinished entry');
 // Save balanced Customs PDFs under the lot, and repeat across all routes using committed fixtures.
 const committed=await page.evaluate(()=>JSON.parse(localStorage.getItem('transtrade_export_v3_operational')));
 for(const route of ['TTI','BRM','TG']){
  const fixture=structuredClone(committed),shipment=fixture.shipments.find(row=>row.kind==='lot'),agreement=fixture.contracts.find(row=>row.ref===shipment.contractRef);
  shipment.seller=agreement.seller=route;shipment.customs={...shipment.customs,saved:true,customsPaymentCode:'CAD100',fiAllocations:[],invoiceValue:108000,openAccount:108000};shipment.tgdocs={saved:true};shipment.customs.gdDocument={id:'GD-QA',name:'GD.png',dataUrl:png};
  await page.evaluate(data=>window.__qa.fixture(data),fixture);
  const result=await page.evaluate(()=>window.__qa.save());assert.equal(result.path,first.path,route+' must reuse the same buyer / shipment / lot');
  const paths=await page.evaluate(()=>Object.keys(window.__savedFiles));
  const customsZipPath='AMT Enterprise/SHIPMENT #13/LOT #AMT-1/Custom documents.zip';
  assert.ok(paths.includes(customsZipPath),route+' Customs documents ZIP must be saved under the lot');
  const zipBytes=await page.evaluate(name=>window.__savedFiles[name],customsZipPath),zipPath=path.join(out,route+'-customs-documents.zip');
  fs.writeFileSync(zipPath,Buffer.from(zipBytes));
  const zipNames=JSON.parse(require('node:child_process').execFileSync('python3',['-c','import zipfile,json,sys; z=zipfile.ZipFile(sys.argv[1]); assert z.testzip() is None; print(json.dumps(z.namelist()))',zipPath],{encoding:'utf8'}));
  for(const name of ['Customs Invoice.pdf','Customs Packing List.pdf','Phytosanitary Invoice.pdf'])assert.ok(zipNames.includes(name),route+' ZIP missing '+name);
  assert.ok(paths.some(path=>path.endsWith('/GD - GD.pdf')),route+' GD must be a separate PDF');
  if(route==='TG')for(const name of ['Sales Contract / Proforma','Commercial Invoice','Packing List'])assert.ok(paths.some(path=>path.includes('/TG docs/')&&path.includes(name.replaceAll('/','-'))),name+' must be in TG docs');
 }
 await page.evaluate(()=>{const root={name:'Transtrade software shipment documents',async queryPermission(){return'granted'},async getDirectoryHandle(){return this},async getFileHandle(){throw Error('Office share offline')}};window.showDirectoryPicker=async()=>root});await page.evaluate(()=>window.TT_SHIPMENT_FILES.choose().catch(()=>{}));
 const failure=await page.evaluate(async png=>{try{await window.TT_SHIPMENT_FILES.save({customer:'QA',contract:'TTI/QA/01',lot:'TTI/QA/01/L01',rows:[],uploads:[{id:'fixture',name:'fixture.png',dataUrl:png}]});return''}catch(error){return error.message}},png);assert.match(failure,/0 of 2 files saved/,'failed share write must never report success');
 await browser.close();console.log('PASS saved B/L description propagation across TTI/BRM/TG and L/C, canonical quoted brands on all outputs, GD prefill/fallback, editable number/date columns, validated multi-GD uploads, Phyto upload/reference, single final table/COO, packing label, branded PDF, and reusable customer/shipment/lot and Customs/TG subfolder saves across TTI, BRM and TG');
})().catch(error=>{console.error(error);process.exit(1)});
