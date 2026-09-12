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
const masterProduct=['Rice','IRRI-6','White Rice 5% Broken','IR6-W5','Pakistan','Active','6.0 mm','5% max','14% max','2.5% max','5% max','','','0.8% max','0.5% max','1% max','2% max','Well milled','Master quality wording','Master source'];
const window={document,localStorage,TT_MODULE_ACCESS:{user:'Test User',masters:{products:[{id:'P1',values:masterProduct}],mills:[{id:'M1',values:['Master Mill','MM','External']}] }},addEventListener(){},print(){},setTimeout:fn=>fn(),setInterval:()=>0};
const context={window,document,localStorage,console,structuredClone,alert:m=>{throw new Error('Unexpected alert: '+m)},location:{href:''},setTimeout:fn=>fn(),setInterval:()=>0,clearTimeout(){},FileReader:class{},Date,Intl};
context.globalThis=context;
let source=fs.readFileSync(__dirname+'/app.js','utf8');
source=source.replace('mount();','window.__EXPORT_TEST__={parseContractText,parseLCText,paymentText,lcSpecificTerms,packingPrefix,unitRate,makeShipment,salesContractPrint,commercialInvoiceDoc,packingListDoc,phytoInvoiceDoc,blDraftDoc,coveringDoc,lcControlDoc,lcDraftDoc,millActualsComplete,applyProductMaster,productLabel,productMasters,millLocations,DEFAULT_QUALITY,CONTAINER_RE,state};\nmount();');
vm.runInNewContext(source,context,{filename:'app.js'});
const t=window.__EXPORT_TEST__;

assert.match(elements.get('app').innerHTML,/TRANSTRADE EXPORTS/);
assert.ok(elements.has('main')&&elements.has('nav')&&elements.has('logoutTop'));

const contract=t.parseContractText(`
SALES CONTRACT NO: BUYER/PO/77
DATE: 08/09/2026
BUYER: North Star Foods LLC
BUYER ADDRESS: Dubai, UAE
PRODUCT: IRRI-6 WHITE RICE 5% BROKEN
QUANTITY: 540 MT
NUMBER OF CONTAINERS: 20
PACKING: 25 KG PP BAGS TARE 80 BRAND STAR
PRICE PER MT: USD 410
INCOTERM: CFR
PORT OF DISCHARGE: JEBEL ALI
PAYMENT TERMS: 100% IRREVOCABLE L/C AT SIGHT
LATEST SHIPMENT DATE: 30/09/2026`);
assert.equal(contract.ref,'BUYER/PO/77');
assert.equal(contract.qty,540);
assert.equal(contract.containers,20);
assert.equal(contract.weightPer,27);
assert.equal(contract.size,25);
assert.equal(contract.paymentCode,'LC_SIGHT');
assert.equal(contract.quality,t.DEFAULT_QUALITY);
const multi=t.parseContractText(`BUYER: A\nQUANTITY: 540 MT\nNUMBER OF CONTAINERS: 20\n10 CONTAINERS 25 KG PP BAGS BRAND ALPHA TARE 80\n10 CONTAINERS 10 KG PP BAGS BRAND BETA TARE 45\nPRICE PER MT: USD 400`);
assert.equal(multi.packings.length,2);
assert.equal(multi.packings[0].containers,10);
assert.equal(multi.packings[0].brand,'ALPHA');
assert.equal(multi.packings[0].tare,80);
assert.equal(multi.packings[0].weightPer,27);
assert.equal(multi.packings[1].size,10);
assert.equal(multi.packings[1].brand,'BETA');

const lc=t.parseLCText(`
:20:LC-99881
:31C:260908
FIELD 31D: 31/12/2026 DUBAI
:32B:USD221400,00
FIELD 50: NORTH STAR FOODS LLC
FIELD 59: TRANSTRADE INTERNATIONAL
ISSUING BANK: FIRST BANK DUBAI
FIELD 41A: MEEZAN BANK KARACHI
FIELD 42C: AT SIGHT
:43P:ALLOWED
:43T:NOT ALLOWED
FIELD 44C: 30/09/2026
FIELD 44E: PORT QASIM PAKISTAN
FIELD 44F: JEBEL ALI UAE
FIELD 48: 21 DAYS
:46A:
COMMERCIAL INVOICE IN 3 ORIGINALS
PACKING LIST IN 3 COPIES
FULL SET BILL OF LADING
PHYTOSANITARY CERTIFICATE
:47A:
ALL DOCUMENTS MUST QUOTE THE L/C NUMBER
THIRD PARTY DOCUMENTS ARE ACCEPTABLE
:71B:
ALL BANK CHARGES OUTSIDE UAE FOR BENEFICIARY`);
assert.equal(lc.lcNo,'LC-99881');
assert.equal(lc.lcDate,'2026-09-08');
assert.equal(lc.currency,'USD');
assert.equal(lc.amount,221400);
assert.equal(lc.paymentCode,'LC_SIGHT');
assert.equal(lc.partialShipment,'Allowed');
assert.equal(lc.transshipment,'Not Allowed');
assert.ok(lc.documents.length>=4);
assert.deepEqual(lc.conditions,['ALL DOCUMENTS MUST QUOTE THE L/C NUMBER','THIRD PARTY DOCUMENTS ARE ACCEPTABLE']);

assert.equal(t.packingPrefix(0,1),'');
assert.equal(t.packingPrefix(0,2),'A. ');
assert.equal(t.packingPrefix(1,2),'B. ');
assert.ok(t.CONTAINER_RE.test('MSCU1234567'));
assert.ok(!t.CONTAINER_RE.test('MSCU123456'));
assert.equal(t.unitRate({price:400,freight:30,insurance:2},{incoterm:'FOB'}),400);
assert.equal(t.unitRate({price:400,freight:30,insurance:2},{incoterm:'CFR'}),430);
assert.equal(t.unitRate({price:400,freight:30,insurance:2},{incoterm:'CIF'}),432);
const lockedLC=t.lcSpecificTerms({paymentCode:'LC_SIGHT',paymentDeadline:'2026-09-15'}).join('\n');
assert.match(lockedLC,/IF THE L\/C IS ISSUED BY A BANK WHICH IS NOT A FIRST-CLASS BANK ACCEPTABLE TO THE SELLER/);
assert.match(lockedLC,/ALL BANK CHARGES OUTSIDE PAKISTAN SHALL BE FOR BUYER’S ACCOUNT\. CONFIRMATION CHARGES, IF ANY, SHALL ALSO BE FOR BUYER’S ACCOUNT\./);
const masterDraft={product:'',broken:0,finish:'',quality:'',specMode:'Pakistan Origin Standard',specRows:[]};
t.applyProductMaster(masterDraft,t.productLabel(t.productMasters()[0]));
assert.equal(masterDraft.quality,'Master quality wording');
assert.equal(masterDraft.specMode,'Contract Specific');
assert.ok(masterDraft.specRows.some(x=>x.name==='Moisture'&&x.value==='14% max'));
assert.ok(t.millLocations().some(x=>x.name==='Master Mill'));

const c={id:'C1',ref:'TTI/NS/01',seller:'TTI',customerId:'C-DAYA',date:'2026-09-08',product:'IRRI-6 White Rice',broken:5,finish:'Silky Polished & Sortexed',quality:t.DEFAULT_QUALITY,qty:540,tolerance:5,shipmentDate:'2026-09-30',pol:'Port Qasim, Pakistan',podPort:'Jebel Ali, UAE',packingUnit:'KG',currency:'USD',incoterm:'CFR',paymentCode:'LC_SIGHT',advancePct:0,usanceDays:0,docs:['Commercial Invoice','Packing List'],packings:[{size:25,type:'PP Bags',brand:'STAR',tare:80,containers:20,weightPer:27,price:410,masterBag:{enabled:false,qty:0,tare:0}}]};
const s=t.makeShipment(c);s.lc={saved:true,lcNo:'LC-99881',lcDate:'2026-09-08',issuingBank:'FIRST BANK',advisingBank:'MEEZAN BANK',documents:['Commercial Invoice','Packing List']};s.millActuals=[{number:'MSCU123456-7',seal:'SL001',bags:1080,netKg:27000,tareKg:86.4,grossKg:27086.4,brand:'STAR',packing:'25 KG'}];s.bl={...s.bl,blNo:'BL001',onBoardDate:'2026-09-20',vessel:'MV TEST',voyage:'V01'};s.customs={...s.customs,fiAllocations:['FI-1'],gdRefs:['GD-1'],bank:'Meezan Bank',iban:'PK00TEST'};
for(const [name,html] of Object.entries({invoice:t.commercialInvoiceDoc(s,c,false),packing:t.packingListDoc(s,c,false),phyto:t.phytoInvoiceDoc(s,c),cover:t.coveringDoc(s,c),draft:t.lcDraftDoc(s,c)})){
  assert.match(html,/docPage/);assert.match(html,/TTI_header\.png/);assert.ok(!/undefined|null/.test(html),name+' leaked invalid text');
}
const plainBL=t.blDraftDoc(s,c);assert.match(plainBL,/plainBlPage/);assert.doesNotMatch(plainBL,/TTI_header\.png|docFooterArt|docAutoSign/);
assert.match(t.commercialInvoiceDoc(s,c,false),/LC-99881/);
assert.doesNotMatch(t.commercialInvoiceDoc(s,c,false),/FI-1/);
assert.match(t.coveringDoc(s,c),/GD-1/);
const validLot={containers:2,millActuals:[{number:'MSCU123456-7',seal:'S1'},{number:'TGHU765432-1',seal:'S2'}]};
assert.ok(t.millActualsComplete(validLot));
assert.ok(!t.millActualsComplete({...validLot,millActuals:[{number:'BAD',seal:'S1'},{number:'TGHU765432-1',seal:'S2'}]}));
assert.ok(!t.millActualsComplete({...validLot,millActuals:[{number:'MSCU123456-7',seal:''},{number:'TGHU765432-1',seal:'S2'}]}));
assert.ok(!t.millActualsComplete({...validLot,millActuals:[{number:'MSCU123456-7',seal:'S1'},{number:'MSCU123456-7',seal:'S2'}]}));
s.lc.documents=Array.from({length:20},(_,i)=>`Required document ${i+1}`);
s.lc.conditions=Array.from({length:20},(_,i)=>`Condition ${i+1}`);
assert.equal((t.lcControlDoc(s,c).match(/class="docPage branded"/g)||[]).length,5,'long L/C control paginates');
assert.equal((t.salesContractPrint(c).match(/class="docPage branded"/g)||[]).length,3,'sales contract paginates terms and acceptance safely');
assert.equal((t.coveringDoc(s,c).match(/class="docPage branded"/g)||[]).length,2,'long covering checklist paginates');
console.log('PASS export release unit/integration assertions');
