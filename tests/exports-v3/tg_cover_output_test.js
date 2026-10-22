'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),{JSDOM}=require('jsdom');
(async()=>{
 const w=new JSDOM('<div id="printRoot"></div>',{url:'https://fixture.test/exports/',runScripts:'outside-only'}).window;
 w.structuredClone=structuredClone;w.setInterval=()=>0;w.alert=()=>{};w.TT_MODULE_ACCESS={user:'Fixture',masters:{}};
 let source=fs.readFileSync('exports/app.js','utf8').replace('mount();restoreContractCheckpoint();','window.test={printShipmentDoc,tgCoverPrintMarkup,printer:fn=>directPrint=fn};');w.eval(source);
 const html='<div class="printDoc"><section class="docPage bankCoveringPage" data-tg-cover="true"><div class="docLetterhead"><img src="assets/TTI_header.png"></div><h1>Covering letter</h1><div class="docAutoSign"><img src="assets/TTI_sign.png"></div></section><section class="docPage"><div class="docLetterhead">Other document header</div></section></div>';
 let print,pdf;w.test.printer((...args)=>print=args);w.TT_SHIPMENT_FILES={pdf:async markup=>{pdf=markup;return new w.Blob(['pdf'])}};w.URL.createObjectURL=()=> 'blob:fixture';w.URL.revokeObjectURL=()=>{};w.HTMLAnchorElement.prototype.click=()=>{};
 w.test.printShipmentDoc('Bank Covering Letter',html,'TTI',true);
 let q=s=>w.document.querySelector(s);assert.ok(q('#tgCoverLetterhead').checked);assert.ok(q('#tgCoverOutputPaper .docAutoSign'));
 q('#tgCoverLetterhead').click();assert.equal(q('#tgCoverOutputPaper .docAutoSign'),null);assert.equal(q('#tgCoverOutputPaper .docLetterhead'),null);
 await q('#tgCoverSavePDF').onclick();assert.equal(pdf,html,'Saved PDF always uses complete original artwork even with print checkbox off');
 q('#tgCoverPrint').click();assert.ok(print);assert.doesNotMatch(print[1],/TTI_header|TTI_sign/);assert.match(print[1],/Other document header/);assert.equal(print[2],'with');assert.equal(print[3],true);
 w.test.printShipmentDoc('Bank Covering Letter',html,'TTI');q('#tgCoverPrint').click();assert.match(print[1],/TTI_header/);assert.match(print[1],/TTI_sign/);
 w.test.printShipmentDoc('Bank Covering Letter',html,'TTI');q('[data-modal-close]').click();assert.equal(q('#tgCoverLetterhead'),null);
 w.close();console.log('PASS TG covering: branded default, header/signature/stamp preview toggle, canonical PDF save, combined-pack isolation, read-only print and cancel');
})().catch(error=>{console.error(error);process.exit(1)});
