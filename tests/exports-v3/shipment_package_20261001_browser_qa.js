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
	  const rows=[{key:'commercial',name:'Commercial Invoice',ready:true,render:()=>markup('FINAL COMMERCIAL INVOICE')},{key:'packing',name:'Packing List',ready:true,render:()=>markup('FINAL PACKING LIST')},{key:'salesContract',name:'Sales Contract',ready:true,render:()=>markup('SEPARATE SALES CONTRACT')},{key:'cover',name:'Bank Covering Letter',ready:true,render:()=>covering},{key:'customs',name:'Customs Invoice',folder:'Custom documents',ready:true,render:()=>markup('CUSTOMS INVOICE')},{key:'customPacking',name:'Customs Packing List',folder:'Custom documents',ready:true,render:()=>markup('CUSTOMS PACKING')},{key:'phytoInvoice',name:'Phytosanitary Invoice',folder:'Custom documents',ready:true,render:()=>markup('PHYTOSANITARY INVOICE')},{key:'tgInvoice',name:'Pakistan → TG — Commercial Invoice',folder:'TG docs',ready:true,render:()=>markup('TG PACK INVOICE')},{key:'tgPacking',name:'Pakistan → TG — Packing List',folder:'TG docs',ready:true,render:()=>markup('TG PACK PACKING LIST')},{key:'tgRelation',name:'Pakistan → TG — Relationship Letter',folder:'TG docs',ready:true,render:()=>markup('RELATIONSHIP LETTER')}];
	  const uploads=[{...await file('BL','INCLUDED BL'),type:'Final / Original B/L'},{...await file('Origin','INCLUDED ORIGINAL COO',2),type:'Certificate of Origin'},{...await file('Fumi','INCLUDED FUMIGATION'),type:'Fumigation Certificate'},{...await file('Phyto','INCLUDED PHYTO'),type:'Phytosanitary Certificate'},{...await file('GD-1','BANK GD'),type:'Goods Declaration (GD)'},{...await file('Signed','EXCLUDED SIGNED CONTRACT'),type:'Signed Sales Contract'}];
	  const committedSnapshot={contracts:[{ref:'TTI/QA/01',seller:'TG',paymentCode:'ADV_SCAN'}]};
	  const result=await TT_SHIPMENT_FILES.save({customer:'QA Customer',contract:'TTI/QA/01',lot:'TTI/QA/01/L01',rows,uploads,committedSnapshot});window.__args={customer:'QA Customer',contract:'TTI/QA/01',lot:'TTI/QA/01/L01',rows,uploads,committedSnapshot};return result;
	 });
	 assert.match(result.path,/QA Customer \/ SHIPMENT #01 \/ LOT #01/);
	 const files=await page.evaluate(()=>window.__files),prefix='QA Customer/SHIPMENT #01/LOT #01/';
	 for(const name of ['Master Shipment Documents.pdf','TG BANK DOCUMENTS.pdf','Sales Contract.pdf','Bank Covering Letter.docx','GD - GD-1.pdf','Signed Sales Contract - Signed.pdf','CUSTOM DOCUMENTS/Customs Invoice.pdf','CUSTOM DOCUMENTS/Customs Packing List.pdf','CUSTOM DOCUMENTS/Phytosanitary Invoice.pdf','TG docs/Pakistan → TG — Packing List.pdf'])assert.ok(files[prefix+name],name+' must be saved');
	 const master=await page.evaluate(async prefix=>{const doc=await PDFLib.PDFDocument.load(new Uint8Array(window.__files[prefix+'Master Shipment Documents.pdf']));return doc.getPageCount()},prefix);assert.equal(master,7,'master includes one original commercial invoice, B/L, one original packing list, COO pages, fumigation and phyto');
	 const bank=await page.evaluate(async prefix=>{const doc=await PDFLib.PDFDocument.load(new Uint8Array(window.__files[prefix+'TG BANK DOCUMENTS.pdf']));return doc.getPageCount()},prefix);assert.equal(bank,7,'TG bank PDF follows covering, B/L, TG invoice, TG packing, phyto, relationship and GD');
	 for(const name of ['Master Shipment Documents.pdf','TG BANK DOCUMENTS.pdf','Bank Covering Letter.docx','GD - GD-1.pdf','CUSTOM DOCUMENTS/Customs Invoice.pdf'])fs.writeFileSync(path.join(out,name.replace(/[ /]/g,'_')),Buffer.from(files[prefix+name]));
	 const {execFileSync}=require('node:child_process');
	 const masterText=execFileSync('pdftotext',[path.join(out,'Master_Shipment_Documents.pdf'),'-'],{encoding:'utf8'});assert.match(masterText,/INCLUDED ORIGINAL COO/);assert.match(masterText,/INCLUDED PHYTO/);assert.doesNotMatch(masterText,/BANK GD|EXCLUDED SIGNED CONTRACT|CUSTOMS INVOICE|PHYTOSANITARY INVOICE/);
 execFileSync('python3',['-c',`import zipfile,xml.etree.ElementTree as E,sys
z=zipfile.ZipFile(sys.argv[1]);assert z.testzip() is None
root=E.fromstring(z.read('word/document.xml'));ns={'w':'http://schemas.openxmlformats.org/wordprocessingml/2006/main'}
text=' '.join(n.text or '' for n in root.findall('.//w:t',ns));assert 'BANK COVERING LETTER' in text and 'Commercial Invoice' in text
assert root.findall('.//w:tbl',ns);assert not root.findall('.//w:altChunk',ns)`,path.join(out,'Bank_Covering_Letter.docx')]);
		 assert.ok(Buffer.from(files[prefix+'CUSTOM DOCUMENTS/Customs Invoice.pdf']).subarray(0,4).equals(Buffer.from('%PDF')),'custom documents are saved separately, not zipped');
		 await page.evaluate(()=>window.__files={});
		 const strictResult=await page.evaluate(()=>TT_SHIPMENT_FILES.save({...window.__args,strictFinal:true}));
		 assert.equal(strictResult.count,5,'final lot archive should only queue master PDF, bank PDF and three custom documents');
		 const strictFiles=await page.evaluate(()=>Object.keys(window.__files).sort());
		 assert.deepEqual(strictFiles,[
		  prefix+'CUSTOM DOCUMENTS/Customs Invoice.pdf',
		  prefix+'CUSTOM DOCUMENTS/Customs Packing List.pdf',
		  prefix+'CUSTOM DOCUMENTS/Phytosanitary Invoice.pdf',
		  prefix+'Master Shipment Documents.pdf',
		  prefix+'TG BANK DOCUMENTS.pdf'
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
	 const lcBank=await page.evaluate(async prefix=>{const doc=await PDFLib.PDFDocument.load(new Uint8Array(window.__files[prefix+'DOCUMENTS FOR BANK.pdf']));return doc.getPageCount()},lcPrefix);
	 assert.equal(lcBank,6,'LC bank PDF follows Documents to be Presented originals and copies');
	 await page.evaluate(()=>window.__files={});
	 const repeated=await page.evaluate(()=>TT_SHIPMENT_FILES.save(window.__args));assert.equal(repeated.count,result.count);assert.equal(Object.keys(await page.evaluate(()=>window.__files)).length,result.count);
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
 await browser.close();console.log('PASS master PDF page preservation/exclusions, separate GD PDFs, editable Word covering, separate contracts, nested Customs/TG files, retry safety and three-module rows');
})().catch(error=>{console.error(error);process.exit(1)});
