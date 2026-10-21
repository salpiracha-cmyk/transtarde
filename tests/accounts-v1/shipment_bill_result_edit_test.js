const fs=require('node:fs'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
(async()=>{
 const source=fs.readFileSync('accounts/accounts-accounting-desk.js','utf8');
 const snippets=source.split('\n').filter(line=>line.includes('const showPosted='));assert.equal(snippets.length,2);
 for(const [index,code] of snippets.entries()){
  const dom=new JSDOM('<div id="host"><button class="tt-window-close">Close</button><div id="body"></div></div><form><input name="invoiceNo" value="INV-1"><input id="ttShipmentBillVendor" value="Fixture Provider"></form>',{runScripts:'outside-only'}),w=dom.window;
  const bill={id:index?'SVB-1':'TRB-1',entity:'TTI',vendor:'Fixture Provider',invoiceNo:'INV-1',shipmentIds:['SHIP1'],postingJournalIds:['POST-2026-00042']};let edited,printed,closed=0;
  w.q=(selector,root=w.document)=>root.querySelector(selector);w.body=w.q('#body');w.host=w.q('#host');w.form=w.q('form');w.entries=[{shipment:{id:'SHIP1'}}];w.company='TTI';w.saved=null;w.calculate=w.total=()=>100;w.money=String;w.esc=value=>String(value??'');w.displayRef=x=>x;w.printVoucher=x=>printed=x;w.openSavedBill=async x=>{edited=x};w.openShipmentKind=()=>{};w.kind=index?'clearing':'transport';w.alert=message=>{throw Error(message)};w.q('.tt-window-close').onclick=()=>closed++;
  w.eval(code+';window.posted=showPosted;');w.posted(bill);
  const edit=w.body.querySelector(index?'[data-edit-bill]':'#ttShipmentBillEdit');assert.equal(edit.textContent,'Edit');edit.click();await Promise.resolve();assert.equal(edited,bill);assert.equal(closed,1);
  w.body.querySelector(index?'[data-print]':'#ttShipmentBillPrint').click();assert.equal(printed.data.journalId,'POST-2026-00042');assert.equal(printed.data.entity,'TTI');assert.equal(bill.postingJournalIds.length,1);
  w.close();
 }
 const dom=new JSDOM('<div id="host"><div class="tt-window-body"></div></div>',{runScripts:'outside-only'}),w=dom.window;let fail=false,writes=0;
 w.q=(s,r=w.document)=>r.querySelector(s);w.layer=()=>w.q('#host');w.esc=String;w.today=()=> '2026-10-17';w.entity=()=> 'TTI';w.access={csrf:'fixture'};
 w.json=async(url,options)=>{if(options)writes++;if(fail)throw Error('Lookup unavailable');return url.includes('&customer=')?{rows:[{contract:'C1',plannedContainers:10,agreedContainers:3,remainingContainers:7}]}:{customers:['Buyer']};};
 const start=source.indexOf('  async function openFreightAgreement()'),end=source.indexOf('  function openTransportBillForm',start);w.eval(source.slice(start,end)+';window.openAgreement=openFreightAgreement;');await w.openAgreement();await Promise.resolve();
 const customer=w.q('#ttFreightCustomer'),select=w.q('#ttFreightShipment');customer.value='Buyer';await customer.onchange();select.value='0';select.onchange();assert.match(w.q('#ttFreightAgreementInfo').textContent,/Remaining: 7/);assert.equal(w.q('#ttFreightAgreementInfo').getAttribute('role'),'status');assert.equal(w.q('#ttFreightAgreementError').textContent,'');
 select.value='';select.onchange();assert.equal(w.q('#ttFreightAgreementInfo').textContent,'');fail=true;await customer.onchange();assert.equal(w.q('#ttFreightAgreementError').textContent,'Lookup unavailable');customer.oninput();assert.equal(w.q('#ttFreightAgreementError').textContent,'');assert.equal(writes,0);w.close();
 // Mixed-currency freight uses the actual posting form, including additions and amendment restoration.
 const freightDom=new JSDOM('<div id="host"><div class="tt-window-body"></div></div>',{runScripts:'outside-only'}),f=freightDom.window;let payload;
 f.q=(s,r=f.document)=>r.querySelector(s);f.qa=(s,r=f.document)=>[...r.querySelectorAll(s)];f.layer=()=>f.q('#host');f.entity=()=> 'TTI';f.today=()=> '2026-10-20';f.esc=x=>String(x??'');f.money=x=>Number(x).toFixed(2);f.displayRef=x=>x;f.access={csrf:'fixture'};
 f.json=async(url,options)=>{if(options){payload=JSON.parse(options.body);return {bill:{id:'FRB-1',invoiceNo:'MIXED',postingJournalIds:['POST-1']}};}return {agreements:[]};};
 const formStart=source.indexOf('  function openShipmentBillForm('),formEnd=source.indexOf('\n  }',formStart)+4;f.eval(source.slice(formStart,formEnd)+';window.openFreightForm=openShipmentBillForm;');
 const shipment={id:'SHIP1',customer:'Buyer',contract:'CONTRACT1',lot:'LOT1',containers:[1,2,3],bl:'BL1'};
 f.openFreightForm('freight',shipment);let form=f.q('form');form.elements.exchangeRate.value=280;form.elements.invoiceNo.value='MIXED';f.q('#ttShipmentBillVendor').value='Forwarder';
 let rows=f.qa('.tt-shipment-charge'),rate=(row,value)=>f.q('[data-amount]',row).value=value,currency=(row,value)=>{f.q('[data-currency]',row).value=value;f.q('[data-currency]',row).dispatchEvent(new f.Event('change',{bubbles:true}));};
 rate(rows[0],1000);rate(rows[1],6500);currency(rows[1],'PKR');assert.equal(f.q('[data-rate-label]',rows[1]).textContent,'Rate (PKR)');assert.equal(f.q('[data-accepted-label]',rows[1]).textContent,'Accepted rate (PKR)');
 f.q('[data-add]').click();rows=f.qa('.tt-shipment-charge');f.q('[data-description]',rows[2]).value='Handling';rate(rows[2],100);currency(rows[2],'PKR');
 f.q('[data-deduct]').click();rows=f.qa('.tt-shipment-charge');f.q('[data-description]',rows[3]).value='Discount';rate(rows[3],50);currency(rows[3],'PKR');f.q('[data-basis]',rows[3]).value='PER_BL';form.dispatchEvent(new f.Event('input',{bubbles:true}));
 assert.equal(f.q('#ttShipmentBillTotal').textContent,'846750.00');assert.equal(f.q('[data-pkr]',rows[1]).value,'6500.00');assert.equal(f.q('[data-pkr]',rows[2]).value,'300.00');
 currency(rows[1],'USD');assert.equal(f.q('[data-pkr]',rows[1]).value,'1820000.00');currency(rows[1],'PKR');assert.equal(f.q('[data-amount]',rows[1]).value,'6500');
 await form.onsubmit({preventDefault(){}});assert.deepEqual(Array.from(payload.shipmentSections[0].charges,c=>c.currency),['USD','PKR','PKR','PKR']);assert.equal(payload.shipmentSections[0].charges[3].billedRate,-50);
 const saved={id:'FRB-1',invoiceNo:'MIXED',billDate:'2026-10-20',exchangeRate:280,vendor:'Forwarder',shipmentSections:payload.shipmentSections.map(s=>({...s,billLines:s.charges}))};
 f.openFreightForm('freight',shipment,saved,[shipment]);rows=f.qa('.tt-shipment-charge');assert.equal(f.q('#ttShipmentBillTotal').textContent,'846750.00');assert.equal(f.q('[data-currency]',rows[1]).value,'PKR');assert.equal(f.q('[data-rate-label]',rows[1]).textContent,'Rate (PKR)');assert.equal(f.q('[data-basis]',rows[1]).value,'PER_BL');assert.equal(f.q('[data-currency]',rows[0]).value,'USD');assert.equal(f.q('[data-type]',rows[3]).value,'DEDUCT');f.close();
 // Fully billed search results link to the original bill; partial freight remains selectable.
 const lookupDom=new JSDOM('<div id="host"><button class="tt-window-close">Close</button><div class="tt-window-body"></div></div>',{runScripts:'outside-only'}),l=lookupDom.window;let opened,lookupUrl,closes=0;
 l.q=(s,r=l.document)=>r.querySelector(s);l.qa=(s,r=l.document)=>[...r.querySelectorAll(s)];l.layer=()=>l.q('#host');l.esc=x=>String(x??'');l.entity=()=> 'TTI';l.q('.tt-window-close').onclick=()=>closes++;l.openSavedBill=async record=>{opened=record.id};
 l.json=async url=>{lookupUrl=url;return {rows:[{...shipment,billing:{fullyBilled:true,bills:[{id:'FRB-OLD',invoiceNo:'PAKLINK-OLD'}]}},{...shipment,id:'SHIP2',billing:{fullyBilled:false,billedContainers:2,remainingContainers:1}}]};};
 const lookupStart=source.indexOf('  async function openShipmentKind('),lookupEnd=source.indexOf('\n  }',lookupStart)+4;l.eval(source.slice(lookupStart,lookupEnd)+';window.openLookup=openShipmentKind;');await l.openLookup('freight');l.q('#ttBillShipmentQuery').value='SHIP';await l.q('#ttBillShipmentGo').onclick();
 assert.equal(l.qa('[data-tt-pick-shipment]').length,1);assert.equal(l.qa('[data-open-billed]').length,1);assert(l.q('#ttBillShipmentHits').textContent.includes('2 billed · 1 remaining'));assert(lookupUrl.includes('billKind=FREIGHT'));l.q('[data-open-billed]').click();await new Promise(r=>setTimeout(r,0));assert.equal(opened,'FRB-OLD');assert.equal(closes,1);l.close();
 console.log('PASS billed shipment original-invoice link and remaining freight selection');
 console.log('PASS mixed USD/PKR freight additions, B/L, deductions, totals, payload and amendment currencies');
 console.log('PASS transport and service/freight result Edit uses original bill amendment, with separate Print and stable journal identity');
})().catch(error=>{console.error(error);process.exitCode=1});
