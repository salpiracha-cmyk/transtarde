const fs=require('fs');
const vm=require('vm');
const assert=require('assert');

class Element{
  constructor(id=''){this.id=id;this.dataset={};this.style={setProperty(){}};this.attributes={};this.value='';this.checked=false;this.files=[];this.hidden=false}
  set innerHTML(v){this._html=String(v);for(const m of this._html.matchAll(/id="([^"]+)"/g))elements.set(m[1],new Element(m[1]))}
  get innerHTML(){return this._html||''} set textContent(v){this._text=String(v)} get textContent(){return this._text||''}
  setAttribute(k,v){this.attributes[k]=v} getAttribute(k){return this.attributes[k]} addEventListener(k,v){this['on'+k]=v} querySelectorAll(){return[]} remove(){}
}
const elements=new Map([['app',new Element('app')],['printRoot',new Element('printRoot')]]);
const document={title:'Audit',body:new Element('body'),head:new Element('head'),getElementById:id=>elements.get(id)||null,querySelectorAll:()=>[],querySelector:()=>null,createElement:()=>new Element(),addEventListener(){}};
const storage=new Map(),localStorage={getItem:k=>storage.get(k)||null,setItem:(k,v)=>storage.set(k,String(v)),removeItem:k=>storage.delete(k),get length(){return storage.size},key:i=>[...storage.keys()][i]};
const window={document,localStorage,TT_MODULE_ACCESS:{user:'Jazib',module:'Exports'},addEventListener(){},print(){},setTimeout:fn=>fn(),setInterval:()=>0};
const context={window,document,localStorage,console,structuredClone,alert:m=>{throw Error(m)},confirm:()=>true,location:{href:''},setTimeout:fn=>fn(),setInterval:()=>0,clearTimeout(){},FileReader:class{},Date,Intl};context.globalThis=context;
let source=fs.readFileSync(__dirname+'/../../exports/app.js','utf8');
source=source.replace('mount();',`window.__AUDIT__={state,makeShipment,makeLotRecord,blankDocuments,processStatus,lotStatus,fiUsed,actualTotals,plannedTotals,customsPlannedRows,plannedPhysicalRows,millActualsComplete,invoiceLines,commercialInvoiceDoc,packingListDoc,phytoInvoiceDoc,blDraftDoc,cooDoc,coveringDoc,lcDraftDoc,lcControlDoc,tgInternalDoc,salesContractPrint,defaultDocsFor,effectiveTerms,lcSpecificTerms,contractSpecRows,DEFAULT_QUALITY};mount();`);
vm.runInNewContext(source,context,{filename:'app.js'});
const t=window.__AUDIT__;

const customer={id:'C-NS',name:'North Star Foods LLC',code:'NS',address:'Dubai, UAE',country:'UAE',packingDefault:'KG',nextSeq:1};t.state.customers.push(customer);
const contract={id:'CT-1',ref:'TTI/NS/01',seller:'TTI',customerId:customer.id,date:'2026-09-08',product:'IRRI-6 White Rice',broken:5,finish:'Silky Polished & Sortexed',quality:t.DEFAULT_QUALITY,containers:20,weightPer:27,qty:540,tolerance:5,pol:'Port Qasim, Pakistan',podPort:'Jebel Ali, UAE',packingUnit:'KG',currency:'USD',incoterm:'CFR',inspection:'None',paymentCode:'LC_SIGHT',usanceDays:0,signedDeadline:'2026-09-10',paymentDeadline:'2026-09-14',shipmentDate:'2026-09-30',docs:['Commercial Invoice','Packing List'],received:true,status:'Contract Received',packings:[{type:'PP Bags',size:25,brand:'STAR',tare:80,containers:10,weightPer:27,price:410,freight:25,insurance:0,masterBag:{enabled:false,qty:0,tare:0}},{type:'PP Bags',size:10,brand:'MOON',tare:45,containers:10,weightPer:27,price:415,freight:25,insurance:0,masterBag:{enabled:true,qty:200,tare:500}}]};
t.state.contracts.push(contract);
const process=t.makeShipment(contract);process.lc={saved:true,lcNo:'LC-99881',lcDate:'2026-09-08',currency:'USD',amount:230000,issuingBank:'FIRST BANK',advisingBank:'MEEZAN BANK',documents:['Commercial Invoice','Packing List'],conditions:['No transshipment']};process.bagOrders=[{poNo:'PO-260001'}];process.production.sentToMill=true;t.state.shipments.push(process);
assert.equal(contract.paymentDeadline,'2026-09-15','L/C deadline defaults to five banking days');assert.ok(t.effectiveTerms(contract).some(x=>x.includes('FIRST-CLASS BANK')));assert.ok(t.effectiveTerms(contract).some(x=>x.includes('PARTIAL SHIPMENT')));
const plan1={lotId:'LOT-01',loadingDate:'2026-09-12',tba:false,allocations:[{packIndex:0,name:'TTI Rice Mills',type:'TTI',containers:5,weightPer:27,emptyBags:50}]};
const plan2={lotId:'LOT-02',loadingDate:'2026-09-13',tba:false,allocations:[{packIndex:1,name:'External Mill',type:'Ex-Mill',containers:5,weightPer:27,emptyBags:20}]};
const lot1=t.makeLotRecord(process,plan1),lot2=t.makeLotRecord(process,plan2);t.state.shipments.push(lot1,lot2);
assert.equal(process.kind,'process');assert.equal(lot1.kind,'lot');assert.equal(lot1.parentProcessId,process.id);assert.notEqual(lot1.id,lot2.id);assert.equal(lot1.plannedQty,135);assert.equal(lot1.lc.lcNo,'LC-99881');
lot1.millActuals=Array.from({length:5},(_,i)=>({number:`MSCU123456-${i}`,seal:`SL00${i+1}`,bags:1080,netKg:27000,tareKg:86.4,grossKg:27086.4,brand:'STAR',packing:'25 KG',location:'TTI Rice Mills'}));
assert.equal(t.actualTotals(lot1,contract).mt,135);assert.equal(t.actualTotals(lot2,contract).mt,135);assert.equal(process.millActuals.length,0);
assert.equal(t.millActualsComplete(lot1),true);assert.equal(t.millActualsComplete({...lot1,millActuals:lot1.millActuals.slice(0,4)}),false);
lot1.customs.fiAllocations=[{fiId:'FI-1',number:'FI-2026-0001',amount:50000,currency:'USD'}];lot1.customs.gdRefs=[{number:'GD-001',date:'2026-09-19'}];lot1.customs.gdDocument={id:'DOC-GD',name:'GD-001.pdf',downloadUrl:'api/export_documents.php?id=00000000000000000000000000000002',gdRefsFingerprint:JSON.stringify([{number:'GD-001',date:'2026-09-19'}])};lot1.customs.invoiceValue=t.invoiceLines(lot1,contract,true).reduce((sum,line)=>sum+Number(line.amount||0),0);lot1.customs.openAccount=lot1.customs.invoiceValue-50000;lot1.customs.saved=true;lot1.bl={...lot1.bl,blNo:'BL001',onBoardDate:'2026-09-20',vessel:'MV TEST',voyage:'V01',finalFile:'BL001.pdf',finalDocument:{id:'DOC-BL',name:'BL001.pdf',downloadUrl:'api/export_documents.php?id=00000000000000000000000000000001'},finalized:true};lot1.commercial.saved=true;lot1.covering={...lot1.covering,bank:'Meezan Bank',dispatchDate:'2026-09-21',tracking:'DHL123',dispatched:true,frozen:true,documentCounts:{'Commercial Invoice':{originals:1,copies:0},'Packing List':{originals:1,copies:0}}};
const docs={sales:t.salesContractPrint(contract),customInvoice:t.commercialInvoiceDoc(lot1,contract,true),customPacking:t.packingListDoc(lot1,contract,true),invoice:t.commercialInvoiceDoc(lot1,contract,false),packing:t.packingListDoc(lot1,contract,false),phyto:t.phytoInvoiceDoc(lot1,contract),bl:t.blDraftDoc(lot1,contract),coo:t.cooDoc(lot1,contract,false),cover:t.coveringDoc(lot1,contract),control:t.lcControlDoc(lot1,contract),draft:t.lcDraftDoc(lot1,contract)};
for(const [name,html] of Object.entries(docs)){assert.match(html,/docPage/);assert.ok(!/(?:undefined|null|NaN)/.test(html),name+' leaked invalid output')}
assert.ok((docs.sales.match(/salesContractPage/g)||[]).length>=2,'Sales Contract pagination must be content driven');assert.match(docs.sales,/PAGE 1 OF /);assert.match(docs.sales,/A\. PACKED IN NEW SINGLE PP BAGS OF 25 KG EACH/);assert.match(docs.sales,/B\. PACKED IN NEW SINGLE PP BAGS OF 10 KG EACH/);assert.match(docs.sales,/Free from live insects/);assert.match(docs.sales,/<b>REF<\/b>/);assert.match(docs.sales,/PAYMENT TERMS|PAYMENT/);
assert.match(docs.customInvoice,/CUSTOM INVOICE/);assert.match(docs.customInvoice,/BRAND MARKING/);assert.match(docs.customInvoice,/NO\. OF PACKAGES/);assert.match(docs.customInvoice,/TOTAL PAYABLE/);assert.match(docs.customPacking,/CUSTOM PACKING LIST/);assert.match(docs.customPacking,/BRAND MARKING/);assert.doesNotMatch(docs.customInvoice,/MSCU123456/);assert.doesNotMatch(docs.customPacking,/Container|Seal|MSCU123456/);assert.doesNotMatch(docs.phyto,/MSCU123456/);
assert.match(docs.invoice,/BY ORDER AND FOR ACCOUNT OF/);assert.match(docs.invoice,/LC-99881/);assert.doesNotMatch(docs.invoice,/FI-2026-0001/);assert.doesNotMatch(docs.invoice,/LESS ADVANCE|BALANCE PAYABLE/);assert.match(docs.packing,/MSCU123456-0/);assert.match(docs.packing,/SL001/);assert.match(docs.bl,/MSCU123456-0/);assert.match(docs.bl,/SL001/);assert.match(docs.bl,/MV TEST/);assert.match(docs.bl,/plainBlPage/);assert.doesNotMatch(docs.bl,/docLetterhead|docFooterArt|docAutoSign|bagMarking/);assert.match(docs.coo,/CERTIFICATE OF ORIGIN/);assert.match(docs.coo,/PRODUCT OF PAKISTAN/);assert.match(docs.coo,/135\.000 MTONS/);assert.match(docs.cover,/Meezan Bank/i);assert.match(docs.cover,/GD-001/);assert.match(docs.cover,/DHL123/);assert.equal(t.lotStatus(lot1),'Ready to complete');
assert.match(docs.sales,/THEREAFTER SUBJECT TO OUR RE-CONFIRMATION/);
assert.match(docs.sales,/FIRST-CLASS BANK ACCEPTABLE TO THE SELLER/);assert.match(docs.sales,/PARTIAL SHIPMENT TO BE ALLOWED/);
assert.match(docs.control,/DOCUMENTS REQUIRED/);assert.match(docs.control,/No transshipment/);
assert.ok(t.defaultDocsFor({incoterm:'CIF'}).includes('Insurance Policy / Certificate'));
assert.ok(t.defaultDocsFor({incoterm:'FOB'}).some(x=>x.includes('e-Phyto issued by Department of Plant Protection')));
assert.ok(t.defaultDocsFor({incoterm:'FOB'}).some(x=>x.includes('Fumigation Certificate — 1 original + 1 copy')));
const single={...contract,packings:[contract.packings[0]],containers:10,qty:270};assert.doesNotMatch(t.salesContractPrint(single),/A\. PACKED IN NEW SINGLE/);

const tgContract={...contract,id:'CT-TG',ref:'TG/NS/02',seller:'TG',packings:[contract.packings[0]]};t.state.contracts.push(tgContract);const tgProcess=t.makeShipment(tgContract);tgProcess.lc={saved:false,documents:[],conditions:[]};const tgLot=t.makeLotRecord(tgProcess,{lotId:'TG-LOT-1',allocations:[{packIndex:0,name:'TTI Rice Mills',type:'TTI',containers:1,weightPer:27}]});tgLot.customs.rate=300;assert.match(t.tgInternalDoc(tgLot,tgContract),/EXPORT SETTLEMENT STATEMENT/);assert.doesNotMatch(t.tgInternalDoc(tgLot,tgContract),/intercompany|intra[- ]?group|NaN|undefined|null/i);
tgLot.customs.rate=0;assert.equal(t.invoiceLines(tgLot,tgContract,true)[0].rate,0,'TG Customs must never inherit the final-customer rate');

const fractional=t.makeLotRecord(process,{lotId:'LOT-FRACTIONAL',physicalContainers:1,allocations:[{packIndex:0,name:'TTI Rice Mills',type:'TTI',containers:.5,weightPer:27},{packIndex:0,name:'Partner Mill',type:'Ex-Mill',containers:.5,weightPer:27}]});
assert.equal(t.plannedTotals(fractional,contract).mt,27,'fractional source shares must aggregate to the complete planned Customs quantity');assert.equal(t.customsPlannedRows(fractional,contract).length,1,'Customs rows aggregate by packing, not loading source');assert.equal(t.plannedPhysicalRows(fractional,contract).length,1,'B/L placeholder count must remain the instructed physical-container count');assert.doesNotMatch(t.packingListDoc(fractional,contract,true),/TTI Rice Mills|Partner Mill|Container|Seal/);

const mixed=t.makeLotRecord(process,{lotId:'LOT-MIX',allocations:[{packIndex:0,name:'TTI Rice Mills',type:'TTI',containers:1,weightPer:27},{packIndex:1,name:'TTI Rice Mills',type:'TTI',containers:1,weightPer:27}]});
mixed.millActuals=[{number:'MSCU000000-1',bags:1080,netKg:27000,brand:'STAR',packing:'25 KG'},{number:'MSCU000000-2',bags:2700,netKg:27000,brand:'MOON',packing:'10 KG'}];
const mixedLines=t.invoiceLines(mixed,contract,false);assert.equal(mixedLines.length,2);assert.equal(mixedLines[0].amount+mixedLines[1].amount,23625);assert.match(t.lcDraftDoc(mixed,contract),/23,625\.00/);

const fi={value:100000,allocations:[{amount:25000},{amount:15000}]};assert.equal(t.fiUsed(fi),40000);
assert.match(elements.get('main').innerHTML,/Active Contracts/);assert.match(elements.get('main').innerHTML,/millPopover/);

const css=fs.readFileSync(__dirname+'/../../exports/app.css','utf8');assert.match(css,/\.millPopover\{display:none/);assert.match(css,/\.millHover:hover \.millPopover/);assert.match(css,/\.docLetterhead\{position:absolute;left:0;right:0/);assert.match(css,/@page\{size:A4;margin:0/);
const names=[...source.matchAll(/^function\s+([\w$]+)\s*\(/gm)].map(m=>m[1]),duplicates=names.filter((n,i)=>names.indexOf(n)!==i);assert.deepEqual(duplicates,[],'duplicate function declarations remain');
const php=fs.readFileSync(__dirname+'/../main/api/operations.mysql.php','utf8');assert.match(php,/SELECT payload, version FROM tt_operation_records WHERE storage_key = \? FOR UPDATE/);assert.match(php,/operations_merge_export/);assert.match(php,/operations_can_write\(\$user, \$sourceModule\)/);
assert.doesNotMatch(php,/in_array\('all', \$permissions/,'one module must never grant write access to another module');
assert.match(php,/\$baseVersion !== \$version/);assert.match(php,/409/);
const wrapper=fs.readFileSync(__dirname+'/../main/module.php','utf8');assert.match(wrapper,/baseVersion:Number\(queuedBase\.get\(key\)\?\?keyVersions\.get\(key\)\?\?0\)/);assert.match(wrapper,/Update needs review/);assert.match(wrapper,/tt_shared_commit_queue_v1/);
assert.match(source,/ADD LOADING SOURCE/);assert.match(source,/Loading allocations exceed the remaining containers/);assert.match(source,/Final document set is blocked/);assert.match(source,/tt30mills/);
console.log('PASS full Export workflow audit: process, lots, Mill actuals, FI, L/C, TG and print outputs');
