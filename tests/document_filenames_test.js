'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),{JSDOM}=require('jsdom');
const dom=new JSDOM('<!doctype html><title>Accounts</title><body><input value="Keep unfinished entry"></body>',{runScripts:'outside-only',url:'https://fixture.test'}),w=dom.window;
w.TextEncoder=TextEncoder;w.setTimeout=()=>0;w.print=()=>{};w.eval(fs.readFileSync('document-filenames.js','utf8'));
const n=w.TT_DOCUMENT_NAME;
assert.equal(n.name({party:'Shams Broker',title:'SODA',reference:'SODA/2026/001',extension:'pdf'}),'Shams Broker - SODA - SODA-2026-001.pdf');
assert.equal(n.name({party:'Meezan Bank',title:'Ledger',from:'2026-07-01',to:'2026-10-22',extension:'xlsx'}),'Meezan Bank - Ledger - 2026-07-01 to 2026-10-22.xlsx');
assert.equal(n.name({party:'ASAS',title:'Commercial Invoice',reference:'TTI/ASAS/63',extension:'pdf'}),'ASAS - Commercial Invoice - TTI-ASAS-63.pdf');
assert.equal(n.name({company:'TG',title:'Sales Tax Return',date:'2026-09',extension:'pdf'}),'TG - Sales Tax Return - 2026-09.pdf');
assert.equal(n.name({party:'../../\r\n"<>|?*\u202eevil',title:'Invoice',reference:'A/B',extension:'PDF'}),'evil - Invoice - A-B.pdf');
assert.equal(n.name({party:'///',title:'***',extension:'pdf'}),'Transtrade - Document.pdf');
assert.ok(Buffer.byteLength(n.name({party:'نام'.repeat(80),title:'Invoice',reference:'INV123',extension:'pdf'}))<=224);
const doc=w.document.implementation.createHTMLDocument('');doc.body.innerHTML='<h2>Labour Bill</h2><p><b>Contractor:</b> Shams Labour</p><p><b>Bill No:</b> BILL-17</p>';
assert.equal(n.infer(doc).party,'Shams Labour');assert.equal(n.infer(doc).reference,'BILL-17');
const restore=n.prepare(doc);assert.equal(doc.title,'Shams Labour - Labour Bill - BILL-17');assert.equal(w.document.title,doc.title);restore();assert.equal(w.document.title,'Accounts');assert.equal(w.document.querySelector('input').value,'Keep unfinished entry');
doc.body.innerHTML='<h2>Supplier report</h2><table><tr><th>Supplier</th><th>Date</th><th>Amount</th></tr><tr><td>First supplier</td><td>2026-10-22</td><td>1</td></tr></table>';
assert.equal(n.infer(doc).party,'','A multi-party report is not named after its first row');
doc.title='ASAS - Commercial Invoice - TTI-ASAS-63';doc.head.querySelector('title').setAttribute('data-tt-filename','');doc.body.innerHTML='<h2>Invoice</h2>';
const undo=n.prepare(doc);assert.equal(doc.title,'ASAS - Commercial Invoice - TTI-ASAS-63');undo();
doc.body.innerHTML='<section data-download-party="Saved Buyer" data-download-title="Packing List" data-download-reference="INV-1"></section>';
assert.equal(n.infer(doc).party,'Saved Buyer');assert.equal(n.infer(doc).reference,'INV-1');
console.log('PASS document filenames: party/title/reference, report periods, Unicode, safe lengths, mixed-party reports, exact PDF titles and restored entry state');w.close();
if(process.env.TT_FILENAME_BROWSER==='1')(async()=>{
 const {chromium}=require('playwright'),browser=await chromium.launch({headless:true});
 try{
  const page=await browser.newPage();await page.route('http://filename.fixture/**',r=>r.fulfill({contentType:'text/html',body:'<!doctype html><title>Accounts entry</title><body><input value="Unfinished entry"></body>'}));await page.goto('http://filename.fixture/');
  await page.evaluate(()=>{const frame=document.createElement('iframe');document.body.append(frame);window.testPopup=frame.contentWindow;window.testPopup.print=()=>{window.rawPrinted={child:window.testPopup.document.title,parent:document.title};};window.open=()=>window.testPopup;});
  await page.addScriptTag({content:fs.readFileSync('document-filenames.js','utf8')});
  await page.evaluate(()=>{const w=open('');w.document.open();w.document.write('<h2>Labour Bill</h2><p><b>Contractor:</b> Shams Labour</p><p><b>Bill No:</b> BILL-17</p>');w.document.close();w.print();});
  const raw=await page.evaluate(()=>window.rawPrinted);assert.equal(raw.child,'Shams Labour - Labour Bill - BILL-17');assert.equal(raw.parent,raw.child);
  await page.evaluate(()=>window.testPopup.dispatchEvent(new Event('afterprint')));assert.equal(await page.title(),'Accounts entry');
  await page.addScriptTag({content:fs.readFileSync('accounts/print-dialog.js','utf8')});
  await page.evaluate(()=>{const w=TT_PRINT.create();w.document.open();w.document.write('<title data-tt-filename>Meezan Bank - Ledger - 2026-07-01 to 2026-10-22</title><h2>Ledger</h2>');w.document.close();w.__nativePrint=()=>{window.ledgerPrinted={child:w.document.title,parent:document.title};w.dispatchEvent(new Event('afterprint'));};w.print();});
  await page.waitForFunction(()=>!!window.ledgerPrinted);const printed=await page.evaluate(()=>window.ledgerPrinted);assert.equal(printed.child,'Meezan Bank - Ledger - 2026-07-01 to 2026-10-22');assert.equal(printed.parent,printed.child);assert.equal(await page.title(),'Accounts entry');
  const event=page.waitForEvent('download');await page.evaluate(()=>{const a=document.createElement('a');a.href=URL.createObjectURL(new Blob(['fixture'],{type:'text/csv'}));a.download=TT_DOCUMENT_NAME.name({party:'Shams Broker',title:'SODA',reference:'SODA/2026/001',extension:'csv'});a.click();});const download=await event;assert.equal(download.suggestedFilename(),'Shams Broker - SODA - SODA-2026-001.csv');
  assert.equal(await page.locator('input').inputValue(),'Unfinished entry');console.log('PASS Chromium filenames: actual suggested download name, raw Milling-style print windows, ledger print frames and restored parent title');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
