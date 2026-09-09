const fs=require('fs');
const vm=require('vm');
const assert=require('assert');

class Element{
  constructor(id=''){this.id=id;this.dataset={};this.style={setProperty(){}};this.attributes={};this.value='';this.checked=false;this.files=[];this.hidden=false}
  set innerHTML(v){this._html=String(v);for(const m of this._html.matchAll(/id="([^"]+)"/g))elements.set(m[1],new Element(m[1]))}
  get innerHTML(){return this._html||''} set textContent(v){this._text=String(v)} get textContent(){return this._text||''}
  setAttribute(k,v){this.attributes[k]=v} addEventListener(k,v){this['on'+k]=v} querySelectorAll(){return[]} querySelector(){return null} prepend(){} insertAdjacentHTML(){} remove(){}
}
const elements=new Map([['app',new Element('app')],['printRoot',new Element('printRoot')]]);
const document={title:'TG Audit',body:new Element('body'),head:new Element('head'),getElementById:id=>elements.get(id)||null,querySelectorAll:()=>[],querySelector:()=>null,createElement:()=>new Element(),addEventListener(){}};
const storage=new Map(),localStorage={getItem:k=>storage.get(k)||null,setItem:(k,v)=>storage.set(k,String(v)),removeItem:k=>storage.delete(k)};
const window={document,localStorage,TT_MODULE_ACCESS:{user:'Audit',module:'Exports'},addEventListener(){},print(){}};
const context={window,document,localStorage,console,structuredClone,alert:m=>{throw Error(m)},confirm:()=>true,location:{href:''},setTimeout:fn=>fn(),setInterval:()=>0,clearTimeout(){},FileReader:class{},Date,Intl};context.globalThis=context;
let source=fs.readFileSync(__dirname+'/app.js','utf8');
source=source.replace('mount();',`window.__TG_AUDIT__={state,makeShipment,makeLotRecord,commercialInvoiceDoc,packingListDoc,phytoInvoiceDoc,blDraftDoc,cooDoc,tgInternalDoc,tgPakistanCoveringDoc,SELLERS};mount();`);
vm.runInNewContext(source,context,{filename:'app.js'});
const t=window.__TG_AUDIT__;

const buyer={id:'C-TG',name:'FINAL BUYER LLC',code:'FB',address:'Mombasa, Kenya',country:'Kenya',packingDefault:'KG',nextSeq:1};
t.state.customers.push(buyer);
const contract={id:'CT-TG',ref:'TG/FB/01',seller:'TG',customerId:buyer.id,date:'2026-09-08',product:'IRRI-6 White Rice',quality:'Export quality rice',containers:1,weightPer:27,qty:27,tolerance:5,pol:'Port Qasim, Pakistan',podPort:'Mombasa, Kenya',packingUnit:'KG',currency:'USD',incoterm:'FOB',paymentCode:'LC_SIGHT',shipmentDate:'2026-09-30',docs:['Commercial Invoice','Packing List'],received:true,status:'Contract Received',packings:[{type:'PP Bags',size:25,brand:'GOOD RICE',tare:80,containers:1,weightPer:27,price:410,freight:0,insurance:0,masterBag:{enabled:false,qty:0,tare:0}}]};
t.state.contracts.push(contract);
const process=t.makeShipment(contract);process.lc={saved:true,lcNo:'LC-CUSTOMER-01',lcDate:'2026-09-08',documents:['Commercial Invoice','Packing List'],conditions:[]};
const lot=t.makeLotRecord(process,{lotId:'LOT-TG-01',allocations:[{packIndex:0,name:'BRM',type:'Ex-Mill',containers:1,weightPer:27}]});
lot.millActuals=[{number:'MSCU1234567',seal:'SEAL-1',bags:1080,netKg:27000,grossKg:27086.4,brand:'GOOD RICE',packing:'25 KG',location:'BRM'}];
Object.assign(lot.customs,{exporter:'BRM',rate:350,invoiceNo:'BRM/TG/01',bank:'Meezan Bank Limited',iban:'PK66MEZN0001020103869880',openAccount:4450,fiAllocations:[{number:'FI-TG-01',amount:5000,currency:'USD'}],gdRefs:[{number:'GD-TG-01',date:'2026-09-10'}],saved:true});

const customerInvoice=t.commercialInvoiceDoc(lot,contract,false);
assert.match(customerInvoice,/assets\/TG_header\.jpg/);
assert.match(customerInvoice,/FINAL BUYER LLC/);
assert.match(customerInvoice,/410\.00/);

const pakistanInvoice=t.commercialInvoiceDoc(lot,contract,true);
assert.match(pakistanInvoice,/assets\/BRM_header\.png/);
assert.match(pakistanInvoice,/TRANS GRAINS FOODSTUFF TRADING L\.L\.C/);
assert.match(pakistanInvoice,/350\.00/);
assert.doesNotMatch(pakistanInvoice,/410\.00/);
assert.match(pakistanInvoice,/FI-TG-01/);
assert.match(pakistanInvoice,/GD-TG-01/);
assert.match(pakistanInvoice,/Meezan Bank Limited/);
assert.match(pakistanInvoice,/PK66MEZN0001020103869880/);
assert.doesNotMatch(pakistanInvoice,/LC-CUSTOMER-01/);

for(const html of [t.packingListDoc(lot,contract,true),t.phytoInvoiceDoc(lot,contract)]){
  assert.match(html,/assets\/BRM_header\.png/);
  assert.match(html,/TRANS GRAINS FOODSTUFF TRADING L\.L\.C/);
}
for(const html of [t.blDraftDoc(lot,contract),t.cooDoc(lot,contract,false)]){
  assert.match(html,/assets\/BRM_header\.png/);
  assert.match(html,/FINAL BUYER LLC/);
}
for(const html of [t.tgInternalDoc(lot,contract),t.tgPakistanCoveringDoc(lot,contract)]){
  assert.match(html,/assets\/BRM_header\.png/);
  assert.match(html,/FI-TG-01/);
  assert.match(html,/GD-TG-01/);
  assert.doesNotMatch(html,/410\.00/);
}
console.log('PASS TG route audit: TG customer pack and BRM/TTI Pakistan pack remain legally and financially separate');
