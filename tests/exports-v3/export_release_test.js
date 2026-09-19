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
const window={document,localStorage,TT_MODULE_ACCESS:{user:'Test User',masters:{product_settings:[{id:'PS1',values:['2025/2026']}],products:[{id:'P1',values:masterProduct}],mills:[{id:'M1',values:['Master Mill','MM','External']}] }},addEventListener(){},print(){},setTimeout:fn=>fn(),setInterval:()=>0};
const context={window,document,localStorage,console,structuredClone,alert:m=>{throw new Error('Unexpected alert: '+m)},location:{href:''},setTimeout:fn=>fn(),setInterval:()=>0,clearTimeout(){},FileReader:class{},Date,Intl};
context.globalThis=context;
let source=fs.readFileSync(__dirname+'/../../exports/app.js','utf8');
const mutableExportOverrides=source.split('\n').filter(line=>/^[A-Za-z_$][\w$]*\s*=\s*function\s*\(/.test(line)).map(line=>line.match(/^([A-Za-z_$][\w$]*)/)[1]);
assert.deepEqual(mutableExportOverrides,[],'Export module must not contain mutable function override assignments');
source=source.replace('mount();','window.__EXPORT_TEST__={parseContractText,parseLCText,paymentText,lcSpecificTerms,effectiveTerms,documentsPresented,inspectionDocumentName,packingPrefix,unitRate,makeShipment,salesContractPrint,purchaseOrderPrint,commercialInvoiceDoc,tgInternalCommercialInvoiceDoc,packingListDoc,phytoInvoiceDoc,blDraftDoc,coveringDoc,lcControlDoc,lcDraftDoc,millActualsComplete,applyProductMaster,productLabel,productMasters,qualityDescription,contractSpecRows,currentCropYear,millLocations,brokenEntry,normalizeBrokenEntry,brokenEntryValid,finishChoices,DEFAULT_QUALITY,CONTAINER_RE,state};\nmount();');
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
assert.equal(masterDraft.quality,'PAKISTAN LONG GRAIN IRRI-6 WHITE RICE, 5% BROKEN, WELL MILLED, SILKY POLISHED AND SORTEXED, NEW CROP 2025/2026, AS PER ORIGIN STANDARD.');
assert.equal(masterDraft.cropYear,'2025/2026');
assert.equal(masterDraft.productIdentityCode,'IR6-W5');
assert.equal(masterDraft.specMode,'As per Pakistan Origin Standards');
assert.equal(t.normalizeBrokenEntry('15 - 20 %'),'15-20');
assert.ok(t.brokenEntryValid('5%'));
assert.ok(t.brokenEntryValid('15-20%'));
assert.ok(!t.brokenEntryValid('20-15%'));
const selectedQuality={...masterDraft,brokenText:'10%',broken:10,finish:'Well milled, double polished and well sortexed'};
assert.equal(t.qualityDescription(selectedQuality),'PAKISTAN LONG GRAIN IRRI-6 WHITE RICE, 10% BROKEN, WELL MILLED, DOUBLE POLISHED AND WELL SORTEXED, NEW CROP 2025/2026, AS PER ORIGIN STANDARD.');
assert.ok(masterDraft.specRows.some(x=>x.name==='Moisture'&&x.value==='14% max'));
assert.ok(!t.contractSpecRows(masterDraft).some(x=>/broken|finish|crop year/i.test(x.name)));
assert.match(t.salesContractPrint({...masterDraft,id:'PM1',ref:'TTI/TEST/01',seller:'TTI',customerId:'',date:'2026-09-13',qty:27,tolerance:5,shipmentDate:'2026-09-30',pol:'Karachi Port, Pakistan',podPort:'Banjul, The Gambia',packingUnit:'KG',currency:'USD',incoterm:'FOB',paymentCode:'ADV100',advancePct:100,signedDeadline:'2026-09-15',paymentDeadline:'2026-09-16',docs:[],terms:[],packings:[{size:50,type:'PP Bags',brand:'TEST',tare:100,containers:1,weightPer:27,price:500,freight:0,insurance:0,masterBag:{enabled:false}}]}),/Pakistan IRRI-6 long grain white rice/i);
assert.ok(t.millLocations().some(x=>x.name==='Master Mill'));

const c={id:'C1',ref:'TTI/NS/01',seller:'TTI',customerId:'C-DAYA',date:'2026-09-08',product:'IRRI-6 White Rice',hsCode:'1006.30',broken:5,finish:'Silky Polished & Sortexed',quality:t.DEFAULT_QUALITY,qty:540,tolerance:5,shipmentDate:'2026-09-30',pol:'Port Qasim, Pakistan',podPort:'Jebel Ali',podCountry:'United Arab Emirates',packingUnit:'KG',currency:'USD',incoterm:'CFR',paymentCode:'LC_SIGHT',advancePct:0,usanceDays:0,docs:['Commercial Invoice','Packing List'],packings:[{size:25,type:'PP Bags',brand:'STAR',tare:80,containers:20,weightPer:27,price:410,masterBag:{enabled:false,qty:0,tare:0}}]};
t.state.customers.push({id:'C-DAYA',name:'North Star Foods LLC',address:'Jebel Ali Free Zone, Dubai, United Arab Emirates',notifies:[{name:'North Star Clearing LLC',address:'Port Road, Dubai, United Arab Emirates'}]});
const s=t.makeShipment(c);s.lc={saved:true,lcNo:'LC-99881',lcDate:'2026-09-08',issuingBank:'FIRST BANK',advisingBank:'MEEZAN BANK',documents:['Commercial Invoice','Packing List']};s.millActuals=[{number:'MSCU123456-7',seal:'SL001',bags:1080,netKg:27000,tareKg:86.4,grossKg:27086.4,brand:'STAR',packing:'25 KG'}];s.bl={...s.bl,blNo:'BL001',onBoardDate:'2026-09-20',vessel:'MV TEST',voyage:'V01'};s.customs={...s.customs,fiAllocations:['FI-1'],gdRefs:['GD-1'],bank:'Meezan Bank',iban:'PK00TEST'};
for(const [name,html] of Object.entries({invoice:t.commercialInvoiceDoc(s,c,false),packing:t.packingListDoc(s,c,false),phyto:t.phytoInvoiceDoc(s,c),cover:t.coveringDoc(s,c),draft:t.lcDraftDoc(s,c)})){
  assert.match(html,/docPage/);if(name==='cover'){assert.doesNotMatch(html,/TTI_header\.png|TTI_sign\.png|docFooterArt/)}else assert.match(html,/TTI_header\.png/);assert.ok(!/undefined|null/.test(html),name+' leaked invalid text');
}
const plainBL=t.blDraftDoc(s,c);assert.match(plainBL,/oceanBlPage/);assert.match(plainBL,/OCEAN BILL OF LADING/);assert.doesNotMatch(plainBL,/TTI_header\.png|docFooterArt|docAutoSign/);
assert.match(plainBL,/SAID TO CONTAIN/);assert.match(plainBL,/1 × 20 FEET CONTAINERS/);assert.match(plainBL,/1,080 PP BAGS OF 25 KG EACH/i);assert.match(plainBL,/HS CODE: 1006\.30/);assert.match(plainBL,/TOTAL NET WEIGHT/);assert.match(plainBL,/TOTAL GROSS WEIGHT/);assert.match(plainBL,/NON-NEGOTIABLE COPIES[\s\S]*<span>5<\/span>/);assert.match(plainBL,/MARKS AND NUMBERS \/ NUMBER AND KIND OF PACKAGES \/ DESCRIPTION OF GOODS/);assert.doesNotMatch(plainBL,/ILLUSTRATIVE|SAMPLE DATA/);
assert.match(t.commercialInvoiceDoc(s,c,false),/LC-99881/);
assert.match(t.commercialInvoiceDoc(s,c,false),/BUYER — NAME AND ADDRESS/);assert.match(t.commercialInvoiceDoc(s,c,false),/North Star Foods LLC/);assert.match(t.commercialInvoiceDoc(s,c,false),/Jebel Ali Free Zone, Dubai, United Arab Emirates/);assert.match(t.commercialInvoiceDoc(s,c,false),/B\/L NUMBER/);assert.match(t.commercialInvoiceDoc(s,c,false),/B\/L DATE/);assert.match(t.commercialInvoiceDoc(s,c,false),/L\/C ISSUING BANK/);assert.match(t.commercialInvoiceDoc(s,c,false),/CFR Jebel Ali, United Arab Emirates/);assert.match(t.commercialInvoiceDoc(s,c,false),/HS CODE: 1006\.30/);assert.match(t.commercialInvoiceDoc(s,c,false),/PAYMENT TERMS/);
assert.match(t.commercialInvoiceDoc(s,c,false),/FI-1/);
assert.match(t.packingListDoc(s,c,false),/>PACKING LIST</);assert.doesNotMatch(t.packingListDoc(s,c,false),/>COMMERCIAL PACKING LIST</);assert.match(t.packingListDoc(s,c,false),/B\/L NUMBER \/ DATE/);assert.match(t.packingListDoc(s,c,false),/COUNTRY OF ORIGIN: PAKISTAN/);
const tgContract={...c,seller:'TG',ref:'TG/NS/01'};const tgShipment=t.makeShipment(tgContract);tgShipment.seller='TG';tgShipment.bl={...tgShipment.bl,blNo:'TGBL001',onBoardDate:'2026-09-20',vessel:'MV TEST',voyage:'V01'};tgShipment.millActuals=structuredClone(s.millActuals);tgShipment.customs={...tgShipment.customs,currency:'USD',rate:350,paymentTerms:'Advance + Cash Against Documents',customsPaymentCode:'ADV_CAD',fiAllocations:[{number:'TG-FI-1',date:'2026-09-10',amount:5000}],gdRefs:[{number:'TG-GD-1',date:'2026-09-20'}]};
const internal=t.tgInternalCommercialInvoiceDoc(tgShipment,tgContract);assert.match(internal,/BUYER \/ REMITTER/);assert.match(internal,/Advance \+ Cash Against Documents/i);assert.match(internal,/TG-FI-1/);assert.match(internal,/TG-GD-1/);
assert.match(t.phytoInvoiceDoc(s,c),/CFR Jebel Ali, United Arab Emirates/);assert.match(t.phytoInvoiceDoc(s,c),/HS CODE: 1006\.30/);assert.match(t.phytoInvoiceDoc(s,c),/BRAND &amp; MARKING/);
assert.match(t.coveringDoc(s,c),/GD-1/);
const validLot={containers:2,millActuals:[{number:'MSCU123456-7',seal:'S1'},{number:'TGHU765432-1',seal:'S2'}]};
assert.ok(t.millActualsComplete(validLot));
assert.ok(!t.millActualsComplete({...validLot,millActuals:[{number:'BAD',seal:'S1'},{number:'TGHU765432-1',seal:'S2'}]}));
assert.ok(!t.millActualsComplete({...validLot,millActuals:[{number:'MSCU123456-7',seal:''},{number:'TGHU765432-1',seal:'S2'}]}));
assert.ok(!t.millActualsComplete({...validLot,millActuals:[{number:'MSCU123456-7',seal:'S1'},{number:'MSCU123456-7',seal:'S2'}]}));
s.lc.documents=Array.from({length:20},(_,i)=>`Required document ${i+1}`);
s.lc.conditions=Array.from({length:20},(_,i)=>`Condition ${i+1}`);
assert.equal((t.lcControlDoc(s,c).match(/class="docPage branded"/g)||[]).length,5,'long L/C control paginates');
Object.assign(c,{containers:20,podPort:'Jebel Ali',podCountry:'United Arab Emirates',insurance:"Buyer's Account",terms:['EDITABLE TERM'],termsInitialized:true});
const normalContract=t.salesContractPrint(c);
assert.equal((normalContract.match(/salesContractPhysicalPage/g)||[]).length,2,'the representative Sales Contract fills two explicit physical pages without fixed-bucket gaps');
assert.doesNotMatch(normalContract,/salesContractFlowPage/,'browser-controlled flowing pages are not used');
assert.equal((normalContract.match(/<h1 class="docTitle">SALES CONTRACT<\/h1>/g)||[]).length,1,'Sales Contract title appears on page one only');
assert.match(normalContract,/Page 1 of 2[\s\S]*Page 2 of 2/,'page numbering is printed in each DATE row');
assert.equal((normalContract.match(/salesContractPageStamp/g)||[]).length,1,'non-final pages carry the page stamp while the final seller signature avoids duplication');
for(const heading of ['PORT OF LOADING','PORT OF DISCHARGE','INSURANCE','PACKING / BRAND-MARKING','OTHER TERMS AND CONDITIONS','DOCUMENTS TO BE PRESENTED FOR NEGOTIATION'])assert.match(normalContract,new RegExp(heading));
assert.match(normalContract,/Packed in new single PP Bags of 25 kgs each with buyer’s marking \/ STAR brand\./);
assert.match(normalContract,/approvedSimplePrice\">USD\. 410\.00 CFR Jebel Ali, United Arab Emirates\.<\/div>/);
assert.match(normalContract,/TOTAL CFR VALUE 540 M\.TONS × USD 410\.00[\s\S]*USD\. 221,400\.00\/=/);
assert.match(normalContract,/UNITED STATES DOLLARS TWO HUNDRED TWENTY ONE THOUSAND FOUR HUNDRED ONLY/);
assert.match(normalContract,/EDITABLE TERM/);
for(const [first,next] of [['PRICE','PAYMENT'],['PAYMENT','OTHER TERMS AND CONDITIONS'],['OTHER TERMS AND CONDITIONS','DOCUMENTS TO BE PRESENTED FOR NEGOTIATION']])assert.ok(normalContract.indexOf(first)<normalContract.indexOf(next),`${first} flows before ${next}`);
const anyInspection={...c,inspection:'Any inspection company',documentsPresented:[]};
assert.ok(t.effectiveTerms(anyInspection).includes('Inspection by any independent, reputable international inspection company having its registered office in Pakistan, at Seller’s cost.'));
assert.deepEqual({...t.documentsPresented(anyInspection).find(row=>row.name==='Inspection Certificates')},{name:'Inspection Certificates',original:1,copies:1,autoSource:'inspection',sequence:3});
const namedInspection={...c,inspection:'SGS Pakistan Private Limited',documentsPresented:[]};
assert.ok(t.documentsPresented(namedInspection).some(row=>row.name==='SGS Pakistan Private Limited’s Certificates'&&row.original===1&&row.copies===1));
const purchaseOrder=t.purchaseOrderPrint({poNo:'PO-260001',supplier:'QA BAG SUPPLIER',requiredDate:'2026-09-20',deliverTo:'TTI RICE MILLS',lines:[{brand:'STAR',type:'P.P. Bags',size:25,unit:'KG',tare:80,totalBags:21816,handle:'No',artworkData:'data:image/png;base64,AA==',masterBag:{enabled:true,bagsPerMaster:20,quantity:1091,tare:120,printed:false}}]});
assert.equal((purchaseOrder.match(/class="docPage plainOrderPage poOnePage"/g)||[]).length,1,'Bag Purchase Order including its marking stays on one page');
assert.match(purchaseOrder,/TOTAL ORDER/);assert.match(purchaseOrder,/21,816/);assert.match(purchaseOrder,/MASTER BAG/);assert.match(purchaseOrder,/1,091/);assert.match(purchaseOrder,/BAG MARKING/);
assert.doesNotMatch(purchaseOrder,/Required \+ Extra|EMPTY BAGS|Unit Rate|Line Amount|Customer|Sales Contract|Authorised Signatory|HANDLE: YES/);
assert.match(purchaseOrder,/poOnePage/);
assert.match(purchaseOrder,/TRANSTRADE INTERNATIONAL/);
assert.match(purchaseOrder,/BRAND AND BAG SPECIFICATION/);
assert.match(purchaseOrder,/APPROVED BAG MARKING/);
assert.match(purchaseOrder,/P\.O\. NUMBER IS MENTIONED ON THE DELIVERY ORDER AND ALSO ON THE FINAL BILL/);
assert.match(purchaseOrder,/PAGE 1 OF 1/);
const purchaseOrderTwoLines=t.purchaseOrderPrint({poNo:'PO-260002',supplier:'QA BAG SUPPLIER',issuedAt:'2026-09-14',requiredDate:'2026-09-20',deliverTo:'TTI RICE MILLS',lines:[{brand:'STAR',type:'P.P. Bags',size:25,unit:'KG',tare:80,totalBags:1000,artworkData:'data:image/png;base64,AA==',masterBag:{enabled:false}},{brand:'MOON',type:'BOPP laminated Bags',size:50,unit:'KG',tare:110,totalBags:500,masterBag:{enabled:true,bagsPerMaster:2,quantity:250,tare:140,printed:false}}]});
assert.match(purchaseOrderTwoLines,/<td>1<\/td>[\s\S]*<td>2<\/td>[\s\S]*<td>3<\/td>/,'primary bags are numbered first and master bag follows as serial 3');
assert.doesNotMatch(purchaseOrderTwoLines,/3\.1|2\.1/);
assert.match(source,/contractSpecificationSequence/);
assert.match(purchaseOrder,/KINDLY ENSURE THE P\.O\. NUMBER IS MENTIONED ON THE DELIVERY ORDER AND ALSO ON THE FINAL BILL\./);
const longContract={...c,terms:Array.from({length:18},(_,i)=>`Long contract term ${i+1}`),documentsPresented:Array.from({length:12},(_,i)=>({sequence:i+1,name:`Document ${i+1}`,original:1,copies:1}))};
const longContractOutput=t.salesContractPrint(longContract);assert.equal((longContractOutput.match(/salesContractPhysicalPage/g)||[]).length,3,'long Sales Contract uses balanced explicit pages');assert.match(longContractOutput,/Long contract term 18/);assert.match(longContractOutput,/Document 12/);assert.match(longContractOutput,/S\.NO/);
const longContractPages=longContractOutput.split('<div class="printDoc">').slice(1);
for(const [index,page] of longContractPages.entries()){
 assert.ok((page.match(/DOCUMENTS TO BE PRESENTED FOR NEGOTIATION/g)||[]).length<=1,`page ${index+1} must contain one continuous documents table`);
 assert.ok((page.match(/OTHER TERMS AND CONDITIONS/g)||[]).length<=1,`page ${index+1} must contain one continuous terms list`);
}
assert.match(longContractPages.at(-1),/VALIDITY[\s\S]*contractSignatureGrid/,'validity and final Seller\/Buyer signatures stay together on the last page');
assert.doesNotMatch(longContractPages.at(-1),/salesContractPageStamp/,'the last page must not duplicate the Seller stamp');
assert.equal((longContractOutput.match(/salesContractPageStamp/g)||[]).length,longContractPages.length-1,'only non-final pages receive the separate page stamp');
assert.match(source,/master\.printed=false/,'a new Bag Order never defaults Printed Master Bag to ticked');
assert.match(source,/approvedContractPriceItem/,'each packing price block is atomic across printed pages');
assert.match(source,/contractPaymentBlock/,'the complete payment block moves together when a page fills');
assert.doesNotMatch(source,/querySelector\('\.stamp'\)\?\.remove\(\)/,'the sent Production Instructions stamp is never removed by a later renderer');
assert.match(source,/const imageNodes=\[\.\.\.root\.querySelectorAll\('img'\)\],pending=/,'print waits for document artwork using declared local variables');
assert.doesNotMatch(source,/,images=\[/,'print must never create the undeclared images variable that broke Review and Save\/Print');
assert.match(source,/const issuedContract=state\.contracts\.find\(x=>x\.id===contractDraft\.id&&x\.issued\);[\s\S]{0,120}checkpointContractDraft\(\);return true/,'an issued-contract amendment stays attached to its existing contract and shipment');
assert.match(source,/contractSplitWorkspace/);
assert.match(source,/cBrokenContract/);
assert.match(source,/cFinishContract/);
assert.match(source,/managedOptionSelectHTML\('mPackType','packing_types'/);
assert.match(fs.readFileSync(__dirname+'/../../auth_store.php','utf8'),/'packing_types'=>/);
assert.equal((t.coveringDoc(s,c).match(/class="docPage bankCoveringPage"/g)||[]).length,1,'covering letter produces one plain physical-letterhead copy');
const brandCss=fs.readFileSync(__dirname+'/../../brand-theme.css','utf8');
const brandJs=fs.readFileSync(__dirname+'/../../brand-theme.js','utf8');
const modulePhp=fs.readFileSync(__dirname+'/../../module.php','utf8');
const accountsPhp=fs.readFileSync(__dirname+'/../../accounts/index.php','utf8');
const loginPhp=fs.readFileSync(__dirname+'/../../login.php','utf8');
assert.match(brandCss,/--tt-brand-main:#4f7650/);
assert.doesNotMatch(brandJs,/TTI_header\.png|ttBrandLogo/);
assert.match(modulePhp,/masterOptions/,'branding merge retains managed master-option bootstrap');
assert.match(modulePhp,/brand-theme\.css/);
assert.match(accountsPhp,/brand-theme\.js/);
assert.doesNotMatch(loginPhp,/authBrandLogo|TTI_header\.png/);
assert.match(loginPhp,/authBrandName">TRANSTRADE INTERNATIONAL/);

/* Exact final-output regression proof for the September 14 repair. */
const customsInvoice=t.commercialInvoiceDoc(s,c,true);
const customsPacking=t.packingListDoc(s,c,true);
const phytosanitary=t.phytoInvoiceDoc(s,c);
for(const label of ['CUSTOMS INVOICE','DRAWEE','VESSEL / VOYAGE','NET WEIGHT','GROSS WEIGHT','TARE WEIGHT'])assert.match(customsInvoice,new RegExp(label.replace('/','\\/')));
assert.match(customsInvoice,/PAYMENT TERMS/,'direct-shipment Customs output must print the Sales Contract payment terms');
assert.match(customsInvoice,/workable and irrevocable 100% L\/C at sight/i,'direct-shipment Customs output does not carry the selected Sales Contract payment wording');
assert.doesNotMatch(customsInvoice,/PAYMENT BREAKDOWN/,'single-mode payments do not render a redundant breakdown');
assert.match(customsInvoice,/PP Bags of 25 KG/i);
assert.doesNotMatch(customsInvoice,/customsBankOutput|ACCOUNT TITLE:|SWIFT CODE:/);
assert.match(customsPacking,/CUSTOM PACKING LIST/);
assert.match(customsPacking,/TOTAL NET WEIGHT/);
assert.match(customsPacking,/TOTAL GROSS WEIGHT/);
assert.doesNotMatch(customsPacking,/NAME &amp; ADDRESS|NAME & ADDRESS/);
assert.equal(phytosanitary.replaceAll('PHYTOSANITARY INVOICE','CUSTOMS INVOICE'),customsInvoice,'Phytosanitary must be the Customs Invoice structure with title change only');

const cifContract={...c,incoterm:'CIF',insurance:"Seller's Account",showAllPrices:true,paymentCode:'ADV_CAD',advancePct:30,terms:["Insurance shall be for Buyer’s account."],termsInitialized:true,packings:c.packings.map(p=>({...p,contractRate:425,price:398,freight:25,insurance:2}))};
const cifOutput=t.salesContractPrint(cifContract);
assert.match(cifOutput,/USD\. 425\.00 P\/M\/T CIF/);assert.match(cifOutput,/TOTAL CIF VALUE/);
assert.match(cifOutput,/USD\. 398\.00 P\/M\/T FOB/);
assert.match(cifOutput,/USD\. 25\.00 P\/M\/T FREIGHT/);
assert.match(cifOutput,/USD\. 2\.00 P\/M\/T INSURANCE/);
assert.match(cifOutput,/Insurance Seller’s account\./);
assert.match(cifOutput,/Insurance shall be for Seller’s account\./);
assert.doesNotMatch(cifOutput,/Insurance shall be for Buyer’s account\./);
assert.match(cifOutput,/PAYMENT[\s\S]*ADVANCE[\s\S]*BALANCE/);
const cfrTerms=t.effectiveTerms({...cifContract,incoterm:'CFR',insurance:"Buyer's Account"});
assert.ok(cfrTerms.includes('Insurance shall be for Buyer’s account.'));
assert.ok(!cfrTerms.includes('Insurance shall be for Seller’s account.'));

const orderedPo=t.purchaseOrderPrint({poNo:'PO-ORDER',supplier:'SUPPLIER',requiredDate:'2026-09-20',deliverTo:'MILL',lines:[
 {brand:'FIRST',type:'P.P. Bags',size:50,unit:'KG',tare:100,totalBags:100,handle:'No',masterBag:{enabled:true,bagsPerMaster:2,quantity:50,tare:120,printed:false}},
 {brand:'SECOND',type:'Cotton Bags',size:25,unit:'KG',tare:90,totalBags:200,handle:'Yes',masterBag:{enabled:false}}
]});
assert.match(orderedPo,/<td>1<\/td>[\s\S]*First[\s\S]*<td>2<\/td>[\s\S]*Second[\s\S]*masterBagOrderRow[\s\S]*<td>3<\/td>[\s\S]*Master Bag/i);
assert.match(orderedPo,/Second[\s\S]*<b>With handle<\/b>/i);
assert.doesNotMatch(orderedPo,/Unit Rate|Line Amount|Sales Contract|Customer/);

console.log('PASS export release unit/integration assertions');
