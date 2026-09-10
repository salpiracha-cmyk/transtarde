const fs=require('fs');
const vm=require('vm');
const assert=require('assert');

class Element{
  constructor(id=''){this.id=id;this.dataset={};this.style={};this.value='';this.checked=false;this.files=[];this.hidden=false}
  set innerHTML(v){this._html=String(v);for(const m of this._html.matchAll(/id="([^"]+)"/g))elements.set(m[1],new Element(m[1]))}
  get innerHTML(){return this._html||''}
  set textContent(v){this._text=String(v)}
  get textContent(){return this._text||''}
  setAttribute(){}
  addEventListener(k,v){this['on'+k]=v}
  querySelector(){return null}
  querySelectorAll(){return[]}
  remove(){}
}
const elements=new Map([['app',new Element('app')],['printRoot',new Element('printRoot')]]);
const document={title:'TTI QA/02 Audit',body:new Element('body'),getElementById:id=>elements.get(id)||null,querySelectorAll:()=>[],querySelector:()=>null,createElement:()=>new Element(),addEventListener(){}};
const storage=new Map();
const localStorage={getItem:k=>storage.get(k)||null,setItem:(k,v)=>storage.set(k,String(v)),removeItem:k=>storage.delete(k),get length(){return storage.size},key:i=>[...storage.keys()][i]};
const window={document,localStorage,TT_MODULE_ACCESS:{user:'QA',role:'Director',module:'Exports'},addEventListener(){},open(){},print(){},setTimeout:fn=>fn(),setInterval:()=>0};
const context={window,document,localStorage,console,structuredClone,alert:m=>{throw Error(m)},prompt:()=>'',confirm:()=>true,location:{href:''},setTimeout:fn=>fn(),setInterval:()=>0,clearTimeout(){},FileReader:class{},FormData:class{},fetch:async()=>({ok:true,json:async()=>({ok:true})}),Date,Intl};
context.globalThis=context;

let source=fs.readFileSync(__dirname+'/app.js','utf8');
source=source.replace('mount();',`window.__QA02__={state,makeShipment,makeLotRecord,loadingRemainingByPack,completionMissing,actualTotals,millActualsComplete,customsBalanced,gdRefsFingerprint,lotStatus};mount();`);
vm.runInNewContext(source,context,{filename:'app.js'});
const t=window.__QA02__;

assert.doesNotMatch(source,/setInterval\(\(\)=>window\.TT_SHARED_SYNC\?\.poll/,'Exports must not autosave or refresh an active form in the background');
assert.match(source,/Manual save only/,'manual-save thumb rule must remain visible');

const buyer={id:'BUY-QA02',name:'Global Transit',code:'GT',address:'Dubai, UAE',packingDefault:'KG',nextSeq:3,notifies:[]};
const contract={
  id:'C-QA02',ref:'TTI/QA/02',seller:'TTI',customerId:buyer.id,date:'2026-09-10',
  product:'IRRI-6 White Rice',broken:5,finish:'Silky Polished & Sortexed',
  quality:'Free from live insects, bad odour and rice fit for human consumption.',
  containers:1,weightPer:26,qty:26,tolerance:0,pol:'Port Qasim, Pakistan',
  podPort:'Jebel Ali, UAE',destPort:'Dubai, UAE',packingUnit:'KG',currency:'USD',
  incoterm:'FOB',inspection:'SGS',paymentCode:'CAD',received:true,status:'Contract Received',
  docs:['Commercial Invoice','Full set clean on-board Bill of Lading','Certificate of Origin','e-Phyto Certificate','Fumigation Certificate'],
  terms:[],
  packings:[{type:'PP Bags',size:25,brand:'QA GOLD',tare:500,containers:1,weightPer:26,price:600,freight:0,insurance:0,requiredBags:1040,extraBags:11,totalBags:1051,masterBag:{enabled:true,qty:20,tare:500,total:53}}]
};
t.state.customers.push(buyer);
t.state.contracts.push(contract);

const process=t.makeShipment(contract);
process.bagOrders=[{poNo:'PO-260001',supplier:'ALEEM',deliverTo:'TTI Rice Mills',totalBags:1051}];
process.production.sentToMill=true;
t.state.shipments.push(process);

const lot=t.makeLotRecord(process,{lotId:'LOT-01',physicalContainers:1,loadingDate:'2026-09-11',allocations:[{packIndex:0,name:'TTI Rice Mills',type:'TTI',containers:1,weightPer:26,emptyBags:0,dryOn:'No',craftPaper:'No',dpp:'Yes',inspection:'SGS'}]});
t.state.shipments.push(lot);
assert.equal(lot.contractRef,'TTI/QA/02');
assert.equal(lot.lotId,'LOT-01');
assert.equal(lot.containers,1);
assert.equal(lot.plannedQty,26);
assert.deepEqual(Array.from(t.loadingRemainingByPack(process,contract)),[0],'fully allocated QA/02 cannot offer a false LOT-02');

lot.millActuals=[{number:'TTQU000027-0',seal:'QA02-SL-01',bags:1040,netKg:26000,tareKg:520,grossKg:26520,brand:'QA GOLD',packing:'25 KG',location:'TTI Rice Mills',gatePass:'QA02-GP-01',shipmentId:lot.id,contractRef:contract.ref,lotRef:lot.lotId,source:'Milling'}];
assert.equal(t.millActualsComplete(lot),true);
assert.equal(t.actualTotals(lot,contract).mt,26);
assert.equal(t.actualTotals(lot,contract).bags,1040);

const invoiceValue=15600;
lot.customs.invoiceValue=invoiceValue;
lot.customs.openAccount=invoiceValue;
lot.customs.fiAllocations=[];
lot.customs.saved=true;
lot.customs.gdRefs=[{number:'QA-GD-002',date:'2026-09-10'}];
lot.customs.gdDocument={id:'DOC-QA02-GD',name:'QA_GD_TTI_QA_02.pdf',gdRefsFingerprint:t.gdRefsFingerprint(lot.customs.gdRefs)};
assert.equal(t.customsBalanced(lot,contract),true,'CAD/Open Account must balance the exact Customs invoice value');

lot.bl={...lot.bl,blNo:'QA02-BL-001',onBoardDate:'2026-09-12',finalized:true,finalDocument:{id:'DOC-QA02-BL',name:'QA02-BL-001.pdf'}};
lot.commercial.saved=true;
lot.coo={...lot.coo,saved:true,finalDocument:{id:'DOC-QA02-COO',name:'QA02-COO.pdf'}};
lot.certs=[
  {type:'e-Phyto Certificate',finalDocument:{id:'DOC-QA02-PHYTO',name:'QA02-EPHYTO.pdf'}},
  {type:'Fumigation Certificate',finalDocument:{id:'DOC-QA02-FUM',name:'QA02-FUMIGATION.pdf'}}
];
lot.covering={...lot.covering,dispatched:true,frozen:true,dispatchDate:'2026-09-13',sentThrough:'DHL',tracking:'QA02-AWB-001',documentCounts:Object.fromEntries(contract.docs.map(name=>[name,{originals:1,copies:0}]))};
assert.deepEqual([...t.completionMissing(lot,contract)],[],'TTI/QA/02 must satisfy every final shipment closure gate');
assert.equal(t.lotStatus(lot),'Ready to complete');

lot.customs.gdDocument.gdRefsFingerprint=t.gdRefsFingerprint([{number:'WRONG-GD',date:'2026-09-10'}]);
assert.ok(t.completionMissing(lot,contract).some(x=>x.includes('Goods Declaration')),'a mismatched GD file must block final closure');
lot.customs.gdDocument.gdRefsFingerprint=t.gdRefsFingerprint(lot.customs.gdRefs);
assert.deepEqual([...t.completionMissing(lot,contract)],[],'restoring the matching GD reference reopens final closure');

console.log('PASS TTI/QA/02 regression: explicit save, one-lot loading, Mill actuals, CAD balance, GD gate, documents and closure');
