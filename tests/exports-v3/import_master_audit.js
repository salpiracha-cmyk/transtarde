const fs=require('fs');
const assert=require('assert');
const {JSDOM,VirtualConsole}=require('jsdom');

(async()=>{
  let app=fs.readFileSync(__dirname+'/app.js','utf8');
  app=app.replace('mount();',`window.__IMPORT_AUDIT__={state,parseContractText,parseLCText,showContractReview,showLCReview,makeLotRecord};mount();`);
  const errors=[],alerts=[],vc=new VirtualConsole();vc.on('jsdomError',e=>errors.push(e.message));vc.on('error',e=>errors.push(String(e)));
  const dom=new JSDOM(`<!doctype html><body><div id="app"></div><div id="printRoot"></div><script>${app.replace(/<\/script/gi,'<\\/script')}</script></body>`,{runScripts:'dangerously',url:'https://exports.audit.local/',virtualConsole:vc,beforeParse(w){w.TT_MODULE_ACCESS={user:'Jazib',module:'Exports'};w.alert=m=>alerts.push(String(m));w.confirm=()=>true;w.print=()=>{};w.setInterval=()=>0;w.structuredClone=v=>JSON.parse(JSON.stringify(v));w.FileReader=class{};}});
  const w=dom.window,d=w.document,t=w.__IMPORT_AUDIT__;
  const review=d.createElement('div');review.id='readerReview';d.body.appendChild(review);
  const parsed=t.parseContractText(`
SALES CONTRACT NO: TTI/IMPORT/01
DATE: 08/09/2026
BUYER: IMPORT CUSTOMER LLC
BUYER ADDRESS: JEBEL ALI, DUBAI, UAE
PRODUCT: IRRI-6 WHITE RICE
QUANTITY: 108 MT
NUMBER OF CONTAINERS: 4
2 CONTAINERS 25 KG PP BAGS BRAND ALPHA TARE 80
2 CONTAINERS 10 KG PP BAGS BRAND BETA TARE 45
PRICE PER MT: USD 400
INCOTERM: CIF
PAYMENT TERMS: 100% IRREVOCABLE L/C AT SIGHT
LATEST SHIPMENT DATE: 30/09/2026`);
  t.showContractReview(parsed);
  d.querySelector('#saveContractImport').click();
  assert.equal(alerts.length,0,alerts.join('\n'));
  assert.equal(t.state.contracts.length,1);assert.equal(t.state.shipments.length,1);
  const c=t.state.contracts[0],p=t.state.shipments[0];
  assert.equal(c.packings.length,2);assert.equal(c.packings.reduce((n,x)=>n+x.containers,0),4);assert.equal(c.packings.reduce((n,x)=>n+x.containers*x.weightPer,0),108);
  assert.equal(c.signedDeadline,'2026-09-10');assert.equal(c.paymentDeadline,'2026-09-15');
  assert.equal(c.received,true);assert.equal(p.kind,'process');assert.equal(p.loading.lots.length,0);

  review.innerHTML='';
  const selector=d.createElement('select');selector.id='readerShipment';selector.innerHTML=`<option value="${p.id}" selected>${p.contractRef}</option>`;d.body.appendChild(selector);
  const parsedLC=t.parseLCText(`
:20:LC-IMPORT-1
:31C:260908
:32B:USD43200,00
FIELD 41A: MEEZAN BANK KARACHI
FIELD 42C: AT SIGHT
:43P:ALLOWED
:43T:NOT ALLOWED
FIELD 44C: 30/09/2026
FIELD 44E: PORT QASIM PAKISTAN
FIELD 44F: JEBEL ALI UAE
:46A:
COMMERCIAL INVOICE IN 3 ORIGINALS
PACKING LIST IN 3 COPIES
:47A:
ALL DOCUMENTS MUST QUOTE THE L/C NUMBER
:71B:
CHARGES`);
  t.showLCReview(parsedLC);d.querySelector('#saveLCImport').click();
  assert.equal(alerts.length,0,alerts.join('\n'));assert.equal(p.lc.lcNo,'LC-IMPORT-1');assert.equal(c.paymentCode,'LC_SIGHT');assert.equal(c.currency,'USD');assert.equal(p.lc.partialShipment,'Allowed');assert.equal(p.lc.transshipment,'Not Allowed');assert.deepEqual([...p.lc.conditions],['ALL DOCUMENTS MUST QUOTE THE L/C NUMBER']);
  const lot=t.makeLotRecord(p,{lotId:'LOT-01',allocations:[{packIndex:0,name:'TTI Rice Mills',type:'TTI',containers:2,weightPer:27}]});assert.equal(lot.lc.lcNo,'LC-IMPORT-1');assert.deepEqual([...lot.lc.documents],['COMMERCIAL INVOICE IN 3 ORIGINALS','PACKING LIST IN 3 COPIES']);
  assert.equal(errors.length,0,errors.join('\n'));
  console.log('PASS contract and L/C upload review-to-master audit');
})().catch(e=>{console.error(e);process.exitCode=1});
