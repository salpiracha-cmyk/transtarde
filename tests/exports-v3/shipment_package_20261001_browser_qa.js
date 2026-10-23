'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require('playwright');
const root=path.resolve(__dirname,'../..'),out=path.join(root,'tmp/qa/package-20261001');fs.mkdirSync(out,{recursive:true});
(async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{})});
 const page=await browser.newPage({viewport:{width:1200,height:1000}});
 await page.route('http://tt.local/**',route=>{const url=new URL(route.request().url());if(url.pathname==='/qa')return route.fulfill({body:'<html><body></body></html>',contentType:'text/html'});if(url.pathname==='/api/export_pdf_assets.php'){const map={html2canvas:'html2canvas-1.4.1.min.js',jspdf:'jspdf-4.2.1.umd.min.js','pdf-lib':'pdf-lib-1.17.1.min.js'};return route.fulfill({path:path.join(root,'exports/vendor',map[url.searchParams.get('asset')]),contentType:'application/javascript'})}return route.abort()});
 await page.goto('http://tt.local/qa');await page.addStyleTag({content:fs.readFileSync(path.join(root,'exports/app.css'),'utf8')});await page.addScriptTag({content:fs.readFileSync(path.join(root,'exports/shipment-files.js'),'utf8')});await page.addScriptTag({content:fs.readFileSync(path.join(root,'exports/vendor/pdf-lib-1.17.1.min.js'),'utf8')});
 await page.evaluate(()=>{
  window.__files={};window.__fail=false;
  const folder=parts=>({name:parts.length?parts.at(-1):'Transtrade software shipment documents',async queryPermission(){return'granted'},async getDirectoryHandle(name){return folder([...parts,name])},async getFileHandle(name){return{async createWritable(){let data;return{async write(blob){if(window.__fail)throw Error('Share offline');data=await blob.arrayBuffer()},async close(){window.__files[[...parts,name].join('/')]=Array.from(new Uint8Array(data))},async abort(){}}}}}});
  window.showDirectoryPicker=async()=>folder([]);
 });
 await page.evaluate(()=>window.TT_SHIPMENT_FILES.choose());
 const result=await page.evaluate(async()=>{
  const file=async(name,marker,pages=1)=>{const doc=await PDFLib.PDFDocument.create();for(let i=0;i<pages;i++){const page=doc.addPage();page.drawText(marker)}const bytes=await doc.save();return{id:name,name:name+'.pdf',dataUrl:'data:application/pdf;base64,'+btoa(String.fromCharCode(...bytes))}};
  const markup=text=>'<div class="printDoc"><section class="docPage"><h1>'+text+'</h1></section></div>';
  const covering='<div class="printDoc"><section class="docPage"><h1>BANK COVERING LETTER</h1><p>TO,<br>MANAGER,<br>QA BANK<br>KARACHI, PAKISTAN</p><div class="coverSubject">EXPORT DOCUMENTS — TTI/QA/01</div><p>Dear Sir/Madam,</p><p>Please find enclosed the following documents.</p><table><tr><th>#</th><th>Document</th><th>Originals</th><th>Copies</th></tr><tr><td>1</td><td>Commercial Invoice</td><td>1</td><td>1</td></tr><tr><td>2</td><td>Goods Declaration GD-1</td><td>0</td><td>1</td></tr></table><p>THANKING YOU.</p></section></div>';
	  const rows=[{key:'commercial',name:'Commercial Invoice',ready:true,render:()=>markup('FINAL COMMERCIAL INVOICE')},{key:'packing',name:'Packing List',ready:true,render:()=>markup('FINAL PACKING LIST')},{key:'salesContract',name:'Sales Contract',ready:true,render:()=>markup('SEPARATE SALES CONTRACT')},{key:'cover',name:'Bank Covering Letter',ready:true,render:()=>covering},{key:'customs',name:'Customs Invoice',folder:'Custom documents',ready:true,render:()=>markup('CUSTOMS INVOICE')},{key:'customPacking',name:'Customs Packing List',folder:'Custom documents',ready:true,render:()=>markup('CUSTOMS PACKING')},{key:'phytoInvoice',name:'Phytosanitary Invoice',folder:'Custom documents',ready:true,render:()=>markup('PHYTOSANITARY INVOICE')},{key:'tgProforma',name:'Pakistan → TG — Sales Contract Proforma',folder:'TG docs',ready:true,render:()=>markup('CUSTOMS BANK PROFORMA')},{key:'tgInvoice',name:'Pakistan → TG — Commercial Invoice',folder:'TG docs',ready:true,render:()=>markup('TG PACK INVOICE')},{key:'tgPacking',name:'Pakistan → TG — Packing List',folder:'TG docs',ready:true,render:()=>markup('TG PACK PACKING LIST')},{key:'tgRelation',name:'Pakistan → TG — Relationship Letter',folder:'TG docs',ready:true,render:()=>markup('RELATIONSHIP LETTER')}];
	  const uploads=[{...await file('BL','INCLUDED BL'),type:'Final / Original B/L'},{...await file('Origin','INCLUDED ORIGINAL COO',2),type:'Certificate of Origin'},{...await file('Fumi','INCLUDED FUMIGATION'),type:'Fumigation Certificate'},{...await file('Phyto','INCLUDED PHYTO'),type:'Phytosanitary Certificate'},{...await file('GD-1','BANK GD'),type:'Goods Declaration (GD)'},{...await file('Signed','EXCLUDED SIGNED CONTRACT'),type:'Signed Sales Contract'}];
	  const committedSnapshot={contracts:[{ref:'TTI/QA/01',seller:'TG',paymentCode:'ADV_SCAN'}],shipments:[{contractRef:'TTI/QA/01',lotId:'TTI/QA/01/L01',tgdocs:{customsInvoiceNo:'INV/TG/0042'}}]};
	  window.__progress=[];const result=await TT_SHIPMENT_FILES.save({customer:'QA Customer',contract:'TTI/QA/01',lot:'TTI/QA/01/L01',rows,uploads,committedSnapshot,onProgress:step=>window.__progress.push(step.stage)});window.__args={customer:'QA Customer',contract:'TTI/QA/01',lot:'TTI/QA/01/L01',rows,uploads,committedSnapshot};return result;
	 });
	 assert.ok((await page.evaluate(()=>window.__progress)).includes('Assembling office package'),'native office copy reports its preparation stages');
	 assert.match(result.path,/QA Customer \/ SHIPMENT #01 \/ LOT #01/);
	 const files=await page.evaluate(()=>window.__files),prefix='QA Customer/SHIPMENT #01/LOT #01/';
	 for(const name of ['Master Shipment Documents.pdf','BANK DOCUMENTS/Bank Shipment Documents INV-TG-0042.pdf','BANK DOCUMENTS/Sales Contract.pdf','GD - GD-1.pdf','Signed Sales Contract - Signed.pdf','CUSTOM DOCUMENTS/Customs Invoice.pdf','CUSTOM DOCUMENTS/Customs Packing List.pdf','CUSTOM DOCUMENTS/Phytosanitary Invoice.pdf','BANK DOCUMENTS/Sales Contract - Proforma INV-TG-0042.pdf','BANK DOCUMENTS/Phyto.pdf'])assert.ok(files[prefix+name],name+' must be saved');
	 const master=await page.evaluate(async prefix=>{const doc=await PDFLib.PDFDocument.load(new Uint8Array(window.__files[prefix+'Master Shipment Documents.pdf']));return doc.getPageCount()},prefix);assert.equal(master,7,'master includes one original commercial invoice, B/L, one original packing list, COO pages, fumigation and phyto');
	 const bank=await page.evaluate(async prefix=>{const doc=await PDFLib.PDFDocument.load(new Uint8Array(window.__files[prefix+'BANK DOCUMENTS/Bank Shipment Documents INV-TG-0042.pdf']));return doc.getPageCount()},prefix);assert.equal(bank,7,'TG bank PDF follows covering, Customs bank proforma, B/L, TG invoice, TG packing, relationship and GD; phyto stays separate');
	 for(const name of ['Master Shipment Documents.pdf','BANK DOCUMENTS/Bank Shipment Documents INV-TG-0042.pdf','GD - GD-1.pdf','CUSTOM DOCUMENTS/Customs Invoice.pdf'])fs.writeFileSync(path.join(out,name.replace(/[ /]/g,'_')),Buffer.from(files[prefix+name]));
	 const {execFileSync}=require('node:child_process');
	 const masterText=execFileSync('pdftotext',[path.join(out,'Master_Shipment_Documents.pdf'),'-'],{encoding:'utf8'});assert.match(masterText,/INCLUDED ORIGINAL COO/);assert.match(masterText,/INCLUDED PHYTO/);assert.doesNotMatch(masterText,/BANK GD|EXCLUDED SIGNED CONTRACT|CUSTOMS INVOICE|PHYTOSANITARY INVOICE/);

		 assert.ok(Buffer.from(files[prefix+'CUSTOM DOCUMENTS/Customs Invoice.pdf']).subarray(0,4).equals(Buffer.from('%PDF')),'custom documents are saved separately, not zipped');
		 await page.evaluate(()=>window.__files={});
		 const strictResult=await page.evaluate(async()=>{
       const native=window.html2canvas;window.__rendered=[];
       window.html2canvas=(element,options)=>{window.__rendered.push(element.textContent);return native(element,options)};
       try{return await TT_SHIPMENT_FILES.save({...window.__args,strictFinal:true})}finally{window.html2canvas=native}
     });
     const renders=await page.evaluate(()=>window.__rendered);
     assert.ok(!renders.some(text=>text.includes('SEPARATE SALES CONTRACT')),'Strict output never renders the unused customer contract');
     assert.equal(renders.filter(text=>text.includes('TG PACK INVOICE')).length,1,'Identical bank invoice markup is rendered once per save');
		 assert.equal(strictResult.count,7,'final lot archive should only queue master PDF, bank PDF and three custom documents');
		 const strictFiles=await page.evaluate(()=>Object.keys(window.__files).sort());
		 assert.deepEqual(strictFiles,[
		  prefix+'CUSTOM DOCUMENTS/Customs Invoice.pdf',
		  prefix+'CUSTOM DOCUMENTS/Customs Packing List.pdf',
		  prefix+'CUSTOM DOCUMENTS/Phytosanitary Invoice.pdf',
		  prefix+'BANK DOCUMENTS/Sales Contract - Proforma INV-TG-0042.pdf',
          prefix+'BANK DOCUMENTS/Phyto.pdf',
		  prefix+'Master Shipment Documents.pdf',
		  prefix+'BANK DOCUMENTS/Bank Shipment Documents INV-TG-0042.pdf'
		 ].sort(),'strict final archive must not leave loose originals, Sales Contract, GD copies, TG docs or Word files');
		 const lcResult=await page.evaluate(async()=>{
		  window.__files={};
	  const args={...window.__args,customer:'LC Customer',contract:'TTI/LC/01',lot:'TTI/LC/01/L01'};
	  args.rows=window.__args.rows.filter(row=>!String(row.key||'').startsWith('tg'));
	  args.committedSnapshot={contracts:[{ref:'TTI/LC/01',seller:'TTI',paymentCode:'LC_SIGHT',documentsPresented:[{sequence:1,name:'Commercial Invoice',original:1,copies:2},{sequence:2,name:'Packing List',original:1,copies:1},{sequence:3,name:'Goods Declaration (GD)',original:0,copies:1}]}]};
	  return TT_SHIPMENT_FILES.save(args);
	 });
	 assert.match(lcResult.path,/LC Customer \/ SHIPMENT #01 \/ LOT #01/);
	 const lcPrefix='LC Customer/SHIPMENT #01/LOT #01/';
	 const lcBank=await page.evaluate(async prefix=>{const doc=await PDFLib.PDFDocument.load(new Uint8Array(window.__files[prefix+'BANK DOCUMENTS/Bank Shipment Documents TTI-LC-01.pdf']));return doc.getPageCount()},lcPrefix);
	 assert.equal(lcBank,7,'LC bank PDF follows Documents to be Presented originals and copies');
	 await page.evaluate(()=>window.__files={});
	 const repeated=await page.evaluate(()=>TT_SHIPMENT_FILES.save(window.__args));assert.equal(repeated.count,result.count);assert.equal(Object.keys(await page.evaluate(()=>window.__files)).length,result.count);
 // A TG L/C pack must include the cover even when the L/C list omits it.
 const tgLcPages=await page.evaluate(async()=>{window.__files={};const args={...window.__args,committedSnapshot:{...window.__args.committedSnapshot,contracts:[{ref:'TTI/QA/01',seller:'TG',paymentCode:'LC_SIGHT',documentsPresented:[{sequence:1,name:'Commercial Invoice',original:1,copies:0}]}]}};await TT_SHIPMENT_FILES.save(args);const key=Object.keys(window.__files).find(k=>k.endsWith('BANK DOCUMENTS/Bank Shipment Documents INV-TG-0042.pdf'));const pdf=await PDFLib.PDFDocument.load(new Uint8Array(window.__files[key]));return pdf.getPageCount()});assert.equal(tgLcPages,3,'Cover and Customs bank proforma first, then L/C commercial invoice');
 const missingCover=await page.evaluate(async()=>{window.__files={};try{await TT_SHIPMENT_FILES.save({...window.__args,rows:window.__args.rows.filter(row=>row.key!=='cover'&&row.key!=='tgCover')});return ''}catch(e){return e.message}});assert.match(missingCover,/Save the TG bank covering letter/);assert.equal(Object.keys(await page.evaluate(()=>window.__files)).length,0,'Do not queue an incomplete bank PDF');
 const wrongCover=await page.evaluate(async()=>{try{await TT_SHIPMENT_FILES.save({...window.__args,rows:[...window.__args.rows,{key:'tgCover',name:'Pakistan → TG — Bank Covering Letter',ready:false,render:()=>''}]});return ''}catch(e){return e.message}});assert.match(wrongCover,/Save the TG bank covering letter/,'Do not substitute the customer bank letter for an unready Pakistan-to-TG letter');
 const partialFiles=await page.evaluate(async()=>{window.__files={};await TT_SHIPMENT_FILES.save({...window.__args,rows:window.__args.rows.filter(r=>r.key==='commercial'),uploads:[]});return Object.keys(window.__files)});assert(partialFiles.some(k=>k.endsWith('Master Shipment Documents.pdf')));assert(!partialFiles.some(k=>k.includes('Bank Shipment Documents')),'An unprepared TG pack does not block ordinary document copies or create a partial bank PDF');
 // TG canonical archive retains branded PDF and editable Word, even in strict final mode.
 const brandedFiles=await page.evaluate(async()=>{
  window.__files={};
  const canvas=document.createElement('canvas');canvas.width=60;canvas.height=20;const ctx=canvas.getContext('2d');ctx.fillStyle='#145b38';ctx.fillRect(0,0,60,20);const image=canvas.toDataURL('image/png');
  const markup='<div class="printDoc"><section class="docPage bankCoveringPage" data-tg-cover="true"><div class="docLetterhead"><img src="'+image+'"></div><h1>BANK COVERING LETTER</h1><table><tr><td>Commercial Invoice</td></tr></table><div class="docAutoSign">For <b>Transtrade International</b><img src="'+image+'"><div>Authorised Signatory</div></div></section></div>';
  await TT_SHIPMENT_FILES.save({...window.__args,strictFinal:true,rows:[{key:'tgCover',name:'Pakistan → TG — Bank Covering Letter',folder:'TG docs',ready:true,render:()=>markup}],uploads:[]});return window.__files;
 });
 const brandedBank=prefix+'BANK DOCUMENTS/Bank Shipment Documents INV-TG-0042.pdf';assert.ok(brandedFiles[brandedBank]);assert.equal(Object.keys(brandedFiles).some(key=>/TG docs|\.docx$/.test(key)),false,'Canonical bank folder contains consolidated PDF, without loose covering copies');
 // Actual covering table controls every enclosure, for TG and direct packs, including FI.
 const ordered=await page.evaluate(async()=>{
  window.__files={};const fi=await PDFLib.PDFDocument.create();fi.addPage().drawText('INCLUDED FI SCREENSHOT');const upload={id:'FI-QA',name:'FI.pdf',type:'FI',dataUrl:'data:application/pdf;base64,'+btoa(String.fromCharCode(...await fi.save()))};
  window.__fi=upload;
  const bankDocumentRows=['Phytosanitary Certificate','Goods Declaration (GD)','Bill of Lading','Certificate of Origin','Commercial Invoice','Commercial Packing List','FI — TEST-123','Relationship Letter','Sales Contract / Proforma Invoice','Fumigation Certificate'].map(name=>({name,originals:0,copies:name==='Commercial Invoice'?2:1}));
  const result=await TT_SHIPMENT_FILES.save({...window.__args,strictFinal:true,uploads:[...window.__args.uploads,upload],bankDocumentRows});
  const file=window.__files[Object.keys(window.__files).find(key=>key.includes('Bank Shipment Documents'))],pdf=await PDFLib.PDFDocument.load(new Uint8Array(file));return{pages:pdf.getPageCount(),file,warnings:result.warnings};
 });
 assert.equal(ordered.pages,11,'Cover first, covering-table enclosures and copy counts, FI included, no TG fumigation');assert.equal(ordered.warnings.length,0);
 fs.writeFileSync(path.join(out,'Ordered_Bank.pdf'),Buffer.from(ordered.file));const orderedText=execFileSync('pdftotext',[path.join(out,'Ordered_Bank.pdf'),'-'],{encoding:'utf8'});
 const markers=['BANK GD','INCLUDED BL','INCLUDED ORIGINAL COO','INCLUDED FI SCREENSHOT'];for(let i=1;i<markers.length;i++)assert.ok(orderedText.indexOf(markers[i-1])<orderedText.indexOf(markers[i]),markers.join(' -> '));assert.doesNotMatch(orderedText,/INCLUDED FUMIGATION|INCLUDED PHYTO/,'Bank PDF excludes both TG fumigation and separately supplied phyto');
 const directOrder=await page.evaluate(async()=>{window.__files={};const args={...window.__args,rows:window.__args.rows.filter(row=>!row.key.startsWith('tg')),uploads:[...window.__args.uploads,window.__fi],committedSnapshot:{contracts:[{ref:'TTI/QA/01',seller:'TTI'}],shipments:[]},bankDocumentRows:[{name:'FI',originals:0,copies:1},{name:'Goods Declaration',originals:0,copies:1}]};const r=await TT_SHIPMENT_FILES.save(args),file=window.__files[Object.keys(window.__files).find(key=>key.includes('Bank Shipment Documents'))],pdf=await PDFLib.PDFDocument.load(new Uint8Array(file));return{pages:pdf.getPageCount(),file,warnings:r.warnings}});
 assert.equal(directOrder.pages,3);assert.equal(directOrder.warnings.length,0);fs.writeFileSync(path.join(out,'Direct_Ordered_Bank.pdf'),Buffer.from(directOrder.file));const directText=execFileSync('pdftotext',[path.join(out,'Direct_Ordered_Bank.pdf'),'-'],{encoding:'utf8'});assert.ok(directText.indexOf('INCLUDED FI SCREENSHOT')<directText.indexOf('BANK GD'),'Direct pack follows its own covering table');
 // Phyto originals always remain standalone, protected or readable, without bank merging or warnings.
 const protectedPdf=fs.readFileSync(path.join(root,'tests/exports-v3/fixtures/protected-phyto.pdf')).toString('base64');
 const protectedResult=await page.evaluate(async encoded=>{window.__files={};const uploads=window.__args.uploads.map(doc=>doc.type==='Phytosanitary Certificate'?{...doc,dataUrl:'data:application/pdf;base64,'+encoded}:doc);const result=await TT_SHIPMENT_FILES.save({...window.__args,uploads,strictFinal:true});const files=window.__files,bankKey=Object.keys(files).find(k=>k.endsWith('BANK DOCUMENTS/Bank Shipment Documents INV-TG-0042.pdf')),bank=await PDFLib.PDFDocument.load(new Uint8Array(files[bankKey]));return{warnings:result.warnings,pages:bank.getPageCount(),files};},protectedPdf);
 assert.equal(protectedResult.pages,7,'Protected phytosanitary pages are not merged as blank pages');assert.equal(protectedResult.warnings.length,0,'Protected standalone phyto is expected, not a merge error');
 const standalone=Object.keys(protectedResult.files).find(k=>k.endsWith('BANK DOCUMENTS/Phyto.pdf'));assert.ok(standalone);assert.equal(Buffer.from(protectedResult.files[standalone]).toString('base64'),protectedPdf,'Protected original preserved byte for byte');assert.equal(Object.keys(protectedResult.files).some(k=>k.endsWith('Bank documents - attention required.pdf')),false,'No phyto merge-warning PDF');
 await page.evaluate(()=>window.__fail=true);const failure=await page.evaluate(async()=>{try{await TT_SHIPMENT_FILES.save(window.__args);return''}catch(error){return error.message}});assert.match(failure,/0 of \d+ files saved/);
 // All module form families: control tops align, names fit their cells, and print styles are untouched.
 const shared=fs.readFileSync(path.join(root,'brand-theme.css'),'utf8');
 for(const [module,file,family]of [['Exports','exports/index.html','grid3'],['Milling','milling/Transtrade_Master_Milling_V3_3_2_AUDITED.html','row'],['Accounts','accounts/Transtrade_Accounts_Master_V1.html','grid3']]){
  const html=fs.readFileSync(path.join(root,file),'utf8'),styles=module==='Exports'?fs.readFileSync(path.join(root,'exports/app.css'),'utf8'):(html.match(/<style[^>]*>([\s\S]*?)<\/style>/)||[])[1];
  await page.setContent('<style>'+styles+shared+'</style><div style="width:760px"><div class="'+family+'"><div class="field"><label>Presented To — Bank Name</label><input value="QA Bank"></div><div class="field"><label>Presented To — Complete Branch / Address</label><textarea>QA Branch</textarea></div><div class="field"><label>Date</label><input type="date"></div></div></div>');
  await page.addScriptTag({content:fs.readFileSync(path.join(root,'brand-theme.js'),'utf8')});await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));await page.screenshot({path:path.join(out,module+'-rows.png')});
  const controls=await page.locator('.field > input,.field > textarea').evaluateAll(nodes=>nodes.map(n=>({top:n.getBoundingClientRect().top,width:n.getBoundingClientRect().width,parent:n.parentElement.getBoundingClientRect().width})));assert.ok(Math.max(...controls.map(x=>x.top))-Math.min(...controls.map(x=>x.top))<=1,module+' controls must align: '+JSON.stringify(controls));assert.ok(controls.every(x=>x.width<=x.parent+1),module+' controls must fit');await page.screenshot({path:path.join(out,module+'-rows.png')});
 }
 const accountStyle=(fs.readFileSync(path.join(root,'accounts/accounts-v1-workflow-ui.js'),'utf8').match(/s.textContent=`([\s\S]*?)`/)||[])[1]||'';
 await page.setContent('<style>'+accountStyle+shared+'</style><div class="ttv-grid" style="width:760px"><label>Bank<input></label><label>Complete Branch / Address<textarea></textarea></label><label>Date<input type="date"></label></div>');await page.addScriptTag({content:fs.readFileSync(path.join(root,'brand-theme.js'),'utf8')});
 await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
 const direct=await page.locator('.ttv-grid input,.ttv-grid textarea').evaluateAll(nodes=>nodes.map(node=>node.getBoundingClientRect().top));assert.ok(Math.max(...direct)-Math.min(...direct)<=1,'Accounts direct-label fields align');
 await browser.close();console.log('PASS unchanged master contents, clean bank folder, TG/direct table order, FI screenshot, counts, protected phyto, retry safety and three-module rows');
})().catch(error=>{console.error(error);process.exit(1)});
