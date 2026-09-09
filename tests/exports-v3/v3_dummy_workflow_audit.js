const fs=require('fs');
const vm=require('vm');
const assert=require('assert');

class Element{
  constructor(id=''){this.id=id;this.dataset={};this.style={};this.value='';this.checked=false;this.files=[];this.hidden=false}
  set innerHTML(v){this._html=String(v);for(const m of this._html.matchAll(/id="([^"]+)"/g))elements.set(m[1],new Element(m[1]))}
  get innerHTML(){return this._html||''} set textContent(v){this._text=String(v)} get textContent(){return this._text||''}
  setAttribute(){} addEventListener(k,v){this['on'+k]=v} querySelector(){return null} querySelectorAll(){return[]} remove(){}
}
const elements=new Map([['app',new Element('app')],['printRoot',new Element('printRoot')]]);
const document={title:'V3 Audit',body:new Element('body'),getElementById:id=>elements.get(id)||null,querySelectorAll:()=>[],querySelector:()=>null,createElement:()=>new Element(),addEventListener(){}};
const storage=new Map(),localStorage={getItem:k=>storage.get(k)||null,setItem:(k,v)=>storage.set(k,String(v)),removeItem:k=>storage.delete(k),get length(){return storage.size},key:i=>[...storage.keys()][i]};
const window={document,localStorage,TT_MODULE_ACCESS:{user:'Director Test',role:'Director',module:'Exports'},addEventListener(){},open(){},print(){},setTimeout:fn=>fn(),setInterval:()=>0};
const context={window,document,localStorage,console,structuredClone,alert:m=>{throw Error(m)},prompt:()=>'',confirm:()=>true,location:{href:''},setTimeout:fn=>fn(),setInterval:()=>0,clearTimeout(){},FileReader:class{},FormData:class{},fetch:async()=>({ok:true,json:async()=>({ok:true})}),Date,Intl};context.globalThis=context;
let source=fs.readFileSync(__dirname+'/app.js','utf8');
source=source.replace('mount();',`window.__V3__={state,makeShipment,makeLotRecord,completionMissing,accountsFor,accountsTotal,purchaseOrderPrint,reportTable,REPORT_DEFS,lcRegisterRows,setCurrent:id=>currentShipmentId=id};mount();`);
vm.runInNewContext(source,context,{filename:'app.js'});
const t=window.__V3__;

const buyer={id:'BUYER',name:'Dummy Buyer',code:'DB',address:'Dubai',packingDefault:'KG',nextSeq:1,notifies:[{name:'Notify Co',address:'Jebel Ali'}]};
const contract={id:'C1',ref:'TTI/DB/01',seller:'TTI',customerId:buyer.id,date:'2026-09-08',product:'IRRI-6 White Rice',quality:'Fit for human consumption',containers:1,weightPer:27,qty:27,tolerance:5,pol:'Port Qasim',podPort:'Jebel Ali',destPort:'Dubai',packingUnit:'KG',currency:'USD',incoterm:'FOB',inspection:'None',paymentCode:'ADV_SCAN',docs:['Commercial Invoice','Full set clean on-board Bill of Lading','Certificate of Origin','e-Phyto','Fumigation Certificate'],terms:[],received:true,status:'Contract Received',packings:[{type:'PP Bags',size:25,brand:'DUMMY',tare:80,containers:1,weightPer:27,price:420,freight:0,insurance:0,masterBag:{enabled:false,qty:0,tare:0}}]};
t.state.customers.push(buyer);t.state.contracts.push(contract);
const process=t.makeShipment(contract);process.production.sentToMill=true;process.loading.draft={physicalContainers:1};t.state.shipments.push(process);
const plan={lotId:'LOT-01',loadingDate:'2026-09-09',allocations:[{packIndex:0,name:'TTI Rice Mills',type:'TTI',containers:.5,weightPer:27},{packIndex:0,name:'Partner Mill',type:'Ex-Mill',containers:.5,weightPer:27}]};
const lot=t.makeLotRecord(process,plan);t.state.shipments.push(lot);t.setCurrent(lot.id);
assert.equal(lot.containers,1,'one physical container remains one record across two source contributions');
assert.equal(lot.contributionCount,1);
assert.equal(lot.plannedQty,27);

const po={poNo:'PO-TEST',supplier:'Bag Supplier',deliverTo:'TTI Rice Mills',requiredDate:'2026-09-10',issuedAt:'2026-09-08T12:00:00Z',lines:[{brand:'DUMMY',type:'PP Bags',size:25,unit:'KG',tare:80,handle:'No',requiredBags:1080,extraBags:20,extraPct:1.85,totalBags:1100,masterBag:{enabled:false},artworkAttached:true,approved:true,artworkData:'data:image/png;base64,AAA'}]};
const poHtml=t.purchaseOrderPrint(po);assert.match(poHtml,/APPROVED BAG MARKING/);assert.match(poHtml,/APPROVED · GOOD SIDE/);assert.match(poHtml,/data:image\/png/);

t.state.accountsReceipts.push({id:'R1',receiptNo:'RCPT-01',contractRef:contract.ref,lotRef:lot.lotId,currency:'USD',amount:4000,date:'2026-09-10',status:'Posted'});
assert.equal(t.accountsTotal(lot,contract),4000,'Accounts receipt flows once to commercial documents');

lot.millActuals=[{number:'MSCU123456-7',seal:'SL001',bags:1080,netKg:27000,tareKg:86.4,grossKg:27086.4,brand:'DUMMY',packing:'25 KG',location:'TTI Rice Mills + Partner Mill'}];
lot.customs.invoiceValue=11340;lot.customs.openAccount=11340;lot.customs.saved=true;lot.customs.gdRefs=[{number:'GD-01',date:'2026-09-11'}];
lot.bl={...lot.bl,blNo:'BL-01',onBoardDate:'2026-09-12',finalized:true,finalDocument:{id:'D1',name:'BL-01.pdf',downloadUrl:'api/export_documents.php?id=11111111111111111111111111111111'}};
lot.commercial.saved=true;lot.coo={...lot.coo,saved:true,finalDocument:{id:'D2',name:'COO.pdf'}};
lot.certs=[{type:'e-Phyto Certificate',finalDocument:{id:'D3',name:'phyto.pdf'}},{type:'Fumigation Certificate',finalDocument:{id:'D4',name:'fumigation.pdf'}}];
lot.covering={...lot.covering,dispatched:true,frozen:true,dispatchDate:'2026-09-13',sentThrough:'DHL',tracking:'AWB-1'};
assert.deepEqual([...t.completionMissing(lot,contract)],[],'all final completion gates pass only when originals and dispatch exist');
lot.bl.finalDocument=null;assert.ok(t.completionMissing(lot,contract).some(x=>x.includes('Final / Original B/L')));lot.bl.finalDocument={id:'D1',name:'BL-01.pdf'};

t.state.fi.push({id:'FI1',number:'FI-01',type:'L/C',customer:buyer.name,currency:'USD',value:12000,bank:'Test Bank',lcNo:'LC-01',lcDate:'2026-09-08',allocations:[]});
assert.ok(t.lcRegisterRows().some(x=>x.lcNo==='LC-01'));
assert.equal(t.REPORT_DEFS.length,15);
for(const [key] of t.REPORT_DEFS){const html=t.reportTable(key);assert.match(html,/table/);assert.doesNotMatch(html,/undefined|NaN/)}

console.log('PASS Clean V3 dummy workflow: approved PO marking, shared-container loading, Accounts receipts, final gates, L/C and 15 reports');
