'use strict';
// Synthetic documents only: run the production renderers across every payment
// method, exporter and packing size without issuing or changing an original.
const fs=require('node:fs'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
const dom=new JSDOM('<div id="app"></div><div id="printRoot"></div>',{url:'https://fixture.test/exports/',runScripts:'outside-only'}),w=dom.window;
w.structuredClone=structuredClone;
w.TT_MODULE_ACCESS={user:'Document fixture',masters:{}};
let source=fs.readFileSync('exports/app.js','utf8');
assert(source.includes('mount();restoreContractCheckpoint();'));
source=source.replace('mount();restoreContractCheckpoint();','window.documentFixture={state,makeShipment,paymentText,salesContractPrint,commercialInvoiceDoc,packingListDoc,blDraftDoc,shipmentFolderDocuments};');
w.eval(source);
const t=w.documentFixture,buyer={id:'BUYER',name:'Fixture Buyer',address:'Fixture buyer address',notifies:[{name:'Fixture Notify',address:'Fixture notify address'}]};
t.state.customers=[buyer];
let cases=0,outputs=0;
try{
 for(const seller of ['TTI','BRM','TG'])for(const paymentCode of ['ADV_SCAN','ADV_CAD','ADV100','LC_SIGHT','LC_USANCE','CAD100','CUSTOM'])for(const size of [25,50]){
  const count=paymentCode==='LC_USANCE'?30:2;
  const c={id:'C',ref:seller+'/FIXTURE/01',seller,customerId:buyer.id,date:'2026-10-01',buyerDetails:{address:buyer.address},product:'White Rice',variety:'IRRI-6',riceType:'White Rice',brokenText:'5%',hsCode:'1006.30',qty:count*27,containers:count,weightPer:27,tolerance:5,pol:'Port Qasim, Pakistan',podPort:'Fixture Port',podCountry:'Fixture Country',packingUnit:'KG',currency:'USD',incoterm:'CFR',paymentCode,advancePct:20,usanceDays:120,customPayment:'FIXTURE CUSTOM PAYMENT TERMS',terms:[],termsInitialized:true,docs:['Commercial Invoice','Packing List'],packings:[{size,type:'P.P. Bags',brand:'FIXTURE',tare:100,containers:count,weightPer:27,price:400,masterBag:{enabled:false}}]};
  t.state.contracts=[c];const s=t.makeShipment(c);s.kind='lot';s.seller=seller;s.contractRef=c.ref;
  s.millActuals=Array.from({length:count},(_,i)=>({number:'MSCU'+String(i).padStart(7,'0'),seal:'SEAL'+i,bags:27000/size,netKg:27000,tareKg:27000/size*.1,grossKg:27000+27000/size*.1,brand:'FIXTURE',packing:size+' KG'}));
  s.bl={...s.bl,blNo:'FIXTURE-BL',onBoardDate:'2026-10-02',vessel:'FIXTURE VESSEL',voyage:'F-1',consignee:buyer.name+', '+buyer.address,notify:'Fixture Notify, Fixture notify address'};
  s.commercial={...s.commercial,invoiceNo:c.ref,date:'2026-10-02',saved:true,status:'Final',packingConsignee:s.bl.consignee,packingNotify:s.bl.notify};
  s.lc={...s.lc,saved:true,lcNo:'FIXTURE-LC',lcDate:'2026-10-01',issuingBank:'FIXTURE ISSUING BANK'};
  s.customs={...s.customs,saved:true,exporter:seller==='TG'?'TTI':seller,description:'FIXTURE WHITE RICE',rate:400,customsPaymentCode:paymentCode,fiAllocations:[],gdRefs:[{number:'FIXTURE-GD-1',date:'2026-10-02'},{number:'FIXTURE-GD-2',date:'2026-10-03'}],openAccount:count*27*400};
  s.tgdocs={saved:true};t.state.shipments=[s];
  const before=JSON.stringify({c,s});
  const docs={contract:t.salesContractPrint(c),invoice:t.commercialInvoiceDoc(s,c),packing:t.packingListDoc(s,c),customInvoice:t.commercialInvoiceDoc(s,c,true),customPacking:t.packingListDoc(s,c,true),bl:t.blDraftDoc(s,c)};
  for(const [name,html]of Object.entries(docs)){
   assert(html&&html.includes('docPage'),seller+'/'+paymentCode+'/'+size+'/'+name+' produces a document');
   assert.doesNotMatch(html,/\b(?:NaN|undefined)\b/,name+' has no missing-value output');
   const holder=w.document.createElement('div');holder.innerHTML=html;assert(holder.textContent.includes('FIXTURE'),name+' retains source identity');outputs++;
  }
  assert(t.paymentText(c).length>10,'Each payment branch has readable terms');
  if(paymentCode==='CUSTOM')assert(docs.contract.includes('FIXTURE CUSTOM PAYMENT TERMS'));
  if(paymentCode==='LC_USANCE')assert(docs.contract.includes('120'),'Saved usance days retained');
  // Every actual container must appear once in each packing list, including a
  // 30-container lot that needs multiple physical pages.
  for(const name of ['packing'])for(const row of s.millActuals)assert.equal(docs[name].split(row.number).length-1,1,name+' keeps each actual container exactly once');
  const packing=w.document.createElement('div');packing.innerHTML=docs.packing;
  assert.equal(packing.querySelectorAll('.docPage').length,count===30?4:1,'Packing rows paginate without losing a container');
  assert(packing.textContent.includes((count*27000).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})+' KGS'),'Packing net weight equals original actuals');
  assert(packing.textContent.includes((count*27000/size).toLocaleString()+' BAGS'),'Packing packages equal original bag size and actuals');
  for(const gd of s.customs.gdRefs)assert(docs.customInvoice.includes(gd.number),'Customs output retains every saved GD number');
  const folder=t.shipmentFolderDocuments(s,c);assert(folder.some(row=>row.key==='commercial'));assert(folder.some(row=>row.key==='packing'));
  assert.equal(JSON.stringify({c,s}),before,'Document generation never changes its original sources');cases++;
 }
 console.log('PASS document variants: '+cases+' exporter/payment/packing cases, '+outputs+' original-source-preserving rendered outputs, including 30-container and multi-GD fixtures.');
}finally{w.close();}
