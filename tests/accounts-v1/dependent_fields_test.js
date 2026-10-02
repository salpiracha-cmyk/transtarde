'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict');
const {JSDOM}=require('jsdom');
const read=path=>fs.readFileSync(path,'utf8');
const line=(path,name)=>{const found=read(path).split('\n').find(row=>row.trimStart().startsWith(`function ${name}(`));assert.ok(found,`${path}: ${name}`);return found;};
function fixture(html,source=''){
 const dom=new JSDOM(html,{runScripts:'outside-only'}),w=dom.window;
 w.eval(`const q=s=>document.querySelector(s),qa=s=>[...document.querySelectorAll(s)],num=v=>Number(v||0),fmt=v=>Number(v||0).toFixed(2),esc=v=>String(v??''),today=()=> '2026-10-02';${source}`);
 return w;
}
// Bags: selecting the PO reveals the invoice fields, then the tax tick reveals GST.
{
 const w=fixture('<main></main>',`const lines=()=>[{ratePerBag:10,remainingToBill:100,receivedNotBilled:100,po:{poNo:'PO1',supplier:'Supplier'},packingSize:'50kg',brand:'Fixture'}],latestRate=()=>18;${line('accounts/bag-purchases-ui.js','billForm')}${line('accounts/bag-purchases-ui.js','recalc')}`);
 w.eval('document.querySelector("main").innerHTML=billForm();recalc()');
 const q=id=>w.document.getElementById(id);
 assert.equal(q('bgSupplier').closest('label').hidden,true);assert.equal(q('bgSave').hidden,true);
 q('bgLine').value='0';w.eval('recalc()');
 assert.equal(q('bgSupplier').closest('label').hidden,false);assert.equal(q('bgGstWrap').hidden,true);
 q('bgQty').value='10';q('bgRate').value='12';q('bgSalesTaxInvoice').checked=true;w.eval('recalc()');
 assert.equal(q('bgGstWrap').hidden,false);assert.equal(q('bgTaxWrap').hidden,false);assert.equal(q('bgGst').value,'18');assert.equal(q('bgTotal').textContent,'Rs 141.60');
 q('bgSalesTaxInvoice').checked=false;w.eval('recalc()');assert.equal(q('bgGstWrap').hidden,true);assert.equal(q('bgTotal').textContent,'Rs 120.00');assert.equal(q('bgQty').value,'10');
 q('bgLine').value='';w.eval('recalc()');assert.equal(q('bgSupplier').closest('label').hidden,true);assert.equal(q('bgQty').value,'10');
}
// Native AED uses rate 1 internally; the foreign rate/reason are conditional.
{
 const w=fixture('<select id="bank"><option>AED</option><option>USD</option></select><label><input id="tgTxRate"></label><label><input id="tgTxRateNote" value="Saved reason"></label>',`const selectedBank=()=>({currency:q('#bank').value}),defaultRate=()=>selectedBank().currency==='AED'?1:3.6725;${line('accounts/tg-bank-transactions-ui.js','syncRateFields')}${line('accounts/tg-bank-transactions-ui.js','setRate')}`);
 w.eval('setRate()');const r=w.document.getElementById('tgTxRate'),note=w.document.getElementById('tgTxRateNote');assert.equal(r.value,'1');assert.equal(r.closest('label').hidden,true);
 w.document.getElementById('bank').value='USD';w.eval('setRate()');assert.equal(r.closest('label').hidden,false);assert.equal(note.closest('label').hidden,true);
 r.value='3.7';w.eval('syncRateFields()');assert.equal(note.closest('label').hidden,false);
 w.document.getElementById('bank').value='AED';w.eval('setRate()');assert.equal(note.closest('label').hidden,true);assert.equal(note.value,'Saved reason');
}
// Amendments expose only the chosen asset type, while retaining hidden historical values.
{
 const w=fixture('<main></main>',`const windowBody=()=>q('main'),nav=()=>{},key=()=> 'fixture',request=()=>{},confirmation=()=>{},error=()=>{};${line('accounts/assets-registry-ui.js','amend')}`);
 const check=(type,property,vehicle)=>{
  w.document.querySelector('main').innerHTML='';w.eval(`amend(${JSON.stringify({id:'A1',type,unitNo:'Plot1',registrationNo:'REG1',chassisNo:'CH1',engineNo:'EN1'})})`);
  const form=w.document.querySelector('form');assert.equal(form.elements.unitNo.closest('label').hidden,!property);assert.equal(form.elements.chassisNo.closest('label').hidden,!vehicle);
  assert.equal(new w.FormData(form).get('chassisNo'),'CH1');
 };
 check('PROPERTY',true,false);check('VEHICLE',false,true);check('COMPUTER',false,false);
}
// Salary group toggles the same existing production-cost input.
{
 const source=read('accounts/rent-salary-ui.js'),handler=source.match(/q\('#rsSalCat'\)\.onchange=e=>\{(.*?)\}\}else/s)[1];
 const w=fixture('<select id="rsSalCat"><option value="MILL_STAFF">Mill</option><option value="OFFICE_STAFF">Office</option></select><input id="rsSalTreatment"><label id="rsSalCostWrap"><input id="rsSalCost" type="checkbox"></label>',`const treatmentDefault=()=> 'STAFF_COST';q('#rsSalCat').onchange=e=>{${handler}};`);
 const group=w.document.getElementById('rsSalCat'),wrap=w.document.getElementById('rsSalCostWrap');
 group.value='OFFICE_STAFF';group.dispatchEvent(new w.Event('change'));assert.equal(wrap.hidden,true);assert.equal(w.document.getElementById('rsSalCost').checked,false);
 group.value='MILL_STAFF';group.dispatchEvent(new w.Event('change'));assert.equal(wrap.hidden,false);assert.equal(w.document.getElementById('rsSalCost').checked,true);
}
// FOB hides freight; CFR and CIF reveal the applicable components without changing values.
{
 const w=fixture('<main></main>',`var contractDraft={incoterm:'FOB',packingUnit:'KG',packings:[{brand:'Fixture',size:50,containers:1,freight:7,weightPer:25}]};const priceComponents=()=>({freight:7,insurance:2,finalRate:100,fob:91}),money=v=>String(v);${line('exports/app.js','priceLinesHTML')}`);
 w.eval('document.querySelector("main").innerHTML=priceLinesHTML()');let freight=w.document.querySelector('[data-freight]');assert.equal(freight.closest('.field').hidden,true);assert.equal(freight.value,'7');
 w.eval('contractDraft.incoterm="CFR";document.querySelector("main").innerHTML=priceLinesHTML()');assert.equal(w.document.querySelector('[data-freight]').closest('.field').hidden,false);assert.equal(w.document.querySelector('[data-ins]'),null);
 w.eval('contractDraft.incoterm="CIF";document.querySelector("main").innerHTML=priceLinesHTML()');assert.ok(w.document.querySelector('[data-ins]'));
}
console.log('Dependent fields: PO/tax, unchanged bag calculations, native/foreign rate, saved asset values, salary group and FOB/CFR/CIF passed');
