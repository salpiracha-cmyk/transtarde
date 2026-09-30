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
source=source.replace('mount();','window.__EXPORT_TEST__={parseContractText,parseLCText,paymentText,lcSpecificTerms,effectiveTerms,documentsPresented,inspectionDocumentName,packingPrefix,unitRate,makeShipment,salesContractPrint,purchaseOrderPrint,commercialInvoiceDoc,tgInternalCommercialInvoiceDoc,packingListDoc,phytoInvoiceDoc,blDraftDoc,coveringDoc,lcControlDoc,lcDraftDoc,actualRows,millActualsComplete,fiGdRows,applyProductMaster,productLabel,productMasters,qualityDescription,contractSpecRows,currentCropYear,millLocations,brokenEntry,normalizeBrokenEntry,brokenEntryValid,finishChoices,DEFAULT_QUALITY,CONTAINER_RE,state,cooDoc,buyerOf,captureShipmentPartyDetails,shipmentDocumentContext,applyExportCustomerMasters,uploadDocumentCategory,shipmentUploadName,recordUploadedDocument,finalShipmentDocuments,shipmentFolderDocuments,customsInvoiceValue,renderDocumentOutput,renderUploadDocuments};\nmount();');
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
const png='data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jBqkAAAAASUVORK5CYII=';
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
const {chromium}=require('playwright'),path=require('path'),out=path.resolve(__dirname,'../../tmp/qa/final-files-20260930');fs.mkdirSync(out,{recursive:true});
(async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{})}),page=await browser.newPage({viewport:{width:1400,height:1300}});
 const root=path.resolve(__dirname,'../..');
 await page.route('http://tt.local/exports/**',route=>{const url=new URL(route.request().url()),file=url.pathname.replace('/exports/','');if(file==='qa.html')return route.fulfill({body:'<html><head></head><body><div id="app"></div><div id="qa-editor"></div><div id="printRoot"></div></body></html>',contentType:'text/html'});return route.fulfill({path:path.join(root,'exports',file)})});
 await page.route('http://tt.local/api/export_pdf_assets.php?**',route=>{const asset=new URL(route.request().url()).searchParams.get('asset');return route.fulfill({path:path.join(root,'exports/vendor',asset==='html2canvas'?'html2canvas-1.4.1.min.js':'jspdf-4.2.1.umd.min.js'),contentType:'application/javascript'})});
 await page.goto('http://tt.local/exports/qa.html');await page.addStyleTag({content:fs.readFileSync(path.join(root,'exports/app.css'),'utf8')});
 let app=fs.readFileSync(path.join(root,'exports/app.js'),'utf8').replace('mount();restoreContractCheckpoint();',`window.__qa={fixture(root){state=root;currentShipmentId=root.shipments.find(row=>row.kind==='lot').id;localStorage.setItem(STORE,JSON.stringify(root));renderShipmentWorkspace=()=>{}},uploads(){renderUploadDocuments(document.getElementById('qa-editor'))},final(){renderDocumentOutput(document.getElementById('qa-editor'))},rows(){return finalShipmentDocuments(shipment(),contractByRef(shipment().contractRef))},state(){return state},upload(file,name){return uploadDocument(file,name,shipment())},packing(){return commercialCopyLabel(packingListDoc(shipment(),contractByRef(shipment().contractRef)),'ORIGINAL')},save(optional=false){return saveShipmentFolder(shipment(),contractByRef(shipment().contractRef),optional)}};`);
 await page.evaluate(()=>{window.TT_MODULE_ACCESS={user:'QA'};window.TT_SHARED_SYNC={saveNow:()=>Promise.resolve({ok:true}),flush(){}};window.alert=message=>{throw Error(message)}});
 await page.addScriptTag({content:app});await page.evaluate(data=>window.__qa.fixture(data),JSON.parse(JSON.stringify(t.state)));
 await page.addScriptTag({content:fs.readFileSync(path.join(root,'exports/shipment-files.js'),'utf8')});
 let multipart='';await page.route('http://tt.local/api/export_documents.php',route=>{multipart=route.request().postData();return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,document:{id:'a'.repeat(32),name:'phyto.png',downloadUrl:'/api/export_documents.php?id='+'a'.repeat(32)}})})});
 await page.evaluate(async name=>{window.TT_MODULE_ACCESS.csrf='fixture-csrf';await window.__qa.upload(new File([new Uint8Array([1])],'phyto.png',{type:'image/png'}),'lot-document-'+name);delete window.TT_MODULE_ACCESS.csrf},phytoName);
 const category=multipart.match(/name="category"\r\n\r\n([^\r]+)/)[1];assert.match(category,/^[a-z0-9-]{3,64}$/,'actual multipart upload category must satisfy the server API');
 await page.evaluate(()=>window.__qa.uploads());await page.locator('#uploadDocumentType').selectOption('Phytosanitary Certificate');assert.equal(await page.locator('#uploadDocumentReference').getAttribute('readonly'),null);await page.locator('#uploadDocumentReference').fill('PC-2026-999');
 await page.locator('#lotDocumentFile').setInputFiles({name:'phyto.png',mimeType:'image/png',buffer:Buffer.from(png.split(',')[1],'base64')});await page.locator('#saveLotDocument').click();await page.waitForFunction(()=>window.__qa.state().certs===undefined&&window.__qa.state().shipments.find(row=>row.kind==='lot').certs.some(row=>row.reference==='PC-2026-999'));
 await page.evaluate(()=>window.__qa.final());assert.equal(await page.locator('#qa-editor input[type="checkbox"]').count(),0);assert.equal(await page.locator('#qa-editor tr').filter({has:page.getByText('Certificate of Origin',{exact:true})}).count(),1);assert.equal(await page.locator('#saveShipmentFiles').isDisabled(),false);assert.equal(await page.locator('#printSet').count(),0);
 await page.locator('#qa-editor').screenshot({path:path.join(out,'merged-final-documents.png')});
 const markup=await page.evaluate(()=>window.__qa.packing());await page.evaluate(html=>{const node=document.getElementById('printRoot');node.style.display='block';node.innerHTML=html},markup);await page.locator('#printRoot img').evaluateAll(async images=>await Promise.all(images.map(image=>image.decode())));
 const positions=await page.evaluate(()=>{const label=document.querySelector('#printRoot .commercialCopyLabel').getBoundingClientRect(),ref=document.querySelector('#printRoot .approvedInvoiceReference').getBoundingClientRect();return{labelBottom:label.bottom,refTop:ref.top,position:getComputedStyle(document.querySelector('#printRoot .commercialCopyLabel')).position}});assert.equal(positions.position,'static');assert.ok(positions.labelBottom<=positions.refTop+1);
 await page.emulateMedia({media:'print'});const printed=await page.evaluate(()=>{const label=document.querySelector('#printRoot .commercialCopyLabel').getBoundingClientRect(),ref=document.querySelector('#printRoot .approvedInvoiceReference').getBoundingClientRect();return{bottom:label.bottom,top:ref.top}});assert.ok(printed.bottom<=printed.top+1);await page.emulateMedia({media:'screen'});
 await page.evaluate(()=>{window.__savedFiles={};window.__folderCalls=[];const root={name:'Transtrade software shipment documents',async queryPermission(){return'granted'},async getDirectoryHandle(name){const make=parts=>({async getDirectoryHandle(name){window.__folderCalls.push(parts.concat(name).join('/'));return make(parts.concat(name))},async getFileHandle(name){return{async createWritable(){let data;return{async write(blob){data=await blob.arrayBuffer()},async close(){window.__savedFiles[parts.concat(name).join('/')]=Array.from(new Uint8Array(data))},async abort(){}}}}}});window.__folderCalls.push(name);return make([name])}};window.showDirectoryPicker=async()=>root});
 // Fake handles cannot be serialized into IndexedDB; choose saves it in memory first.
 await page.evaluate(()=>window.TT_SHIPMENT_FILES.choose().catch(()=>{}));
 const first=await page.evaluate(()=>window.__qa.save());assert.ok(first.path.includes('AMT Enterprise / SHIPMENT #13 / LOT #AMT-1'));assert.ok(first.count>=5);
 const files=await page.evaluate(()=>window.__savedFiles);for(const [name,bytes]of Object.entries(files))if(/Commercial Invoice\.pdf$|Packing List\.pdf$/.test(name)){assert.equal(Buffer.from(bytes).subarray(0,4).toString(),'%PDF');fs.writeFileSync(path.join(out,path.basename(name)),Buffer.from(bytes))}
 assert.ok(Object.keys(files).some(name=>name.endsWith('Uploaded - phyto.png')),'issued phyto is copied into the lot folder');
 assert.equal(await page.evaluate(()=>window.TT_SHIPMENT_FILES.folderParts('AMT Enterprise','TTI/AMT/14','TTI/AMT/14/L02').join('/')),'AMT Enterprise/SHIPMENT #14/LOT #02');
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
  for(const name of ['Customs Invoice.pdf','Customs Packing List.pdf','Phytosanitary Invoice.pdf','Uploaded - GD.png'])assert.ok(paths.includes('AMT Enterprise/SHIPMENT #13/LOT #AMT-1/Custom documents/'+name),route+' missing nested '+name);
  if(route==='TG')for(const name of ['Sales Contract / Proforma','Commercial Invoice','Packing List','Bank Covering Letter'])assert.ok(paths.some(path=>path.includes('/TG docs/')&&path.includes(name.replaceAll('/','-'))),name+' must be in TG docs');
 }
 await page.evaluate(()=>{const root={name:'fixture',async queryPermission(){return'granted'},async getDirectoryHandle(){return this},async getFileHandle(){throw Error('Office share offline')}};window.showDirectoryPicker=async()=>root});await page.evaluate(()=>window.TT_SHIPMENT_FILES.choose().catch(()=>{}));
 const failure=await page.evaluate(async png=>{try{await window.TT_SHIPMENT_FILES.save({customer:'QA',contract:'TTI/QA/01',lot:'TTI/QA/01/L01',rows:[],uploads:[{id:'fixture',name:'fixture.png',dataUrl:png}]});return''}catch(error){return error.message}},png);assert.match(failure,/0 of 1 files saved/,'failed share write must never report success');
 await browser.close();console.log('PASS Phyto upload/reference, single final table/COO, packing label, branded PDF, and reusable customer/shipment/lot and Customs/TG subfolder saves across TTI, BRM and TG');
})().catch(error=>{console.error(error);process.exit(1)});
