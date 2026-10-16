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
 console.log('PASS transport and service/freight result Edit uses original bill amendment, with separate Print and stable journal identity');
})().catch(error=>{console.error(error);process.exitCode=1});
