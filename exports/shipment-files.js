/* Package committed shipment documents and enqueue them for the Office Agent. */
(()=>{'use strict';
let busy=false;
function component(value){const name=String(value||'').normalize('NFC').replace(/[<>:"/\\|?*\x00-\x1f]/g,'-').replace(/[. ]+$/g,'').trim().slice(0,120);if(!name||/^\.+$/.test(name))throw new Error('Customer, shipment and lot names are required for saving files.');return /^(con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i.test(name)?'_'+name:name}
function folderParts(customer,contract,lot){return[component(customer),'SHIPMENT #'+component(String(contract).split('/').pop()),'LOT #'+component(String(lot).split('/').pop().replace(/^L(?=\d)/i,''))]}
function officeLocation(platform=navigator.userAgentData?.platform||navigator.platform||navigator.userAgent){const windows=/win/i.test(platform);return{platform:windows?'Windows':'Mac',path:windows?String.raw`\\tti-server\TTI DOCS\Transtrade software shipment documents`:'smb://tti-server/TTI DOCS/Transtrade software shipment documents',connect:'Configure this destination once in Transtrade Office Agent on the permanent office/server PC. Windows users can review the configured folder in File Explorer.'}}
async function choose(){return'Office Agent handles the configured archive destination.'}
async function access(){return null}
async function uploadBlob(doc){if(doc.dataUrl)return(await fetch(doc.dataUrl)).blob();const url=new URL(doc.downloadUrl,location.href);if(url.origin!==location.origin||!url.pathname.endsWith('/api/export_documents.php'))throw new Error('Invalid shipment document download.');const response=await fetch(url.href,{credentials:'same-origin'});if(!response.ok)throw new Error('Could not download '+doc.name+'. Your office folder was not updated.');return response.blob()}
async function libraries(){for(const [ready,path] of [[()=>window.html2canvas,'/api/export_pdf_assets.php?asset=html2canvas&v=1.4.1'],[()=>window.jspdf,'/api/export_pdf_assets.php?asset=jspdf&v=4.2.1'],[()=>window.PDFLib,'/api/export_pdf_assets.php?asset=pdf-lib&v=1.17.1']])if(!ready())await new Promise((resolve,reject)=>{const script=document.createElement('script');script.src=path;script.onload=resolve;script.onerror=()=>reject(new Error('PDF generation could not load. Retry saving the files.'));document.head.appendChild(script)})}
async function pdf(markup,fit){
 await libraries();const root=document.createElement('div');root.className='shipmentPdfRoot printModeWith';root.style.cssText='position:absolute;left:-10000px;top:0;width:210mm;background:white';root.innerHTML=markup;document.body.appendChild(root);
 try{
  await document.fonts.ready;await Promise.all([...root.querySelectorAll('img')].map(image=>image.decode()));
  if(fit&&!fit(root))throw new Error('This document does not fit on its pages. Review the content before saving.');
  const pages=[...root.querySelectorAll('.docPage')];if(!pages.length)throw new Error('No document pages were generated.');
  const output=new window.jspdf.jsPDF({unit:'mm',format:'a4',compress:true});
  for(let i=0;i<pages.length;i++){
   const page=pages[i];page.style.height='297mm';page.style.minHeight='297mm';page.style.margin='0';page.style.boxShadow='none';
   if(page.scrollHeight>page.clientHeight+2||page.scrollWidth>page.clientWidth+2)throw new Error('Document content crosses its page boundary ('+page.className+': '+page.scrollHeight+'/'+page.clientHeight+'). No clipped PDF was saved.');
   if(i)output.addPage();const canvas=await window.html2canvas(page,{scale:2,backgroundColor:'#fff',logging:false,imageTimeout:30000});output.addImage(canvas,'PNG',0,0,210,297,undefined,'FAST');
  }
  return output.output('blob');
 }finally{root.remove()}
}
async function postJob(manifest,files){
 const access=window.TT_MODULE_ACCESS||{};
 if(!access.csrf)throw new Error('Your login session expired. Refresh and retry LOT COMPLETE.');
 const body=new FormData();body.append('csrf',access.csrf);body.append('action','enqueue-shipment');body.append('manifest',JSON.stringify(manifest));
 files.forEach((file,index)=>body.append('files[]',file.blob,file.storedName||('file-'+index)));
 const response=await fetch('/api/office_agent.php',{method:'POST',credentials:'same-origin',headers:{Accept:'application/json'},body});
 const raw=await response.text();let result;try{result=JSON.parse(raw)}catch{throw new Error('Office archive job was not accepted. Please retry LOT COMPLETE.')}
 if(!response.ok||!result.ok)throw new Error(result.error||'Office archive job was not accepted. Please retry LOT COMPLETE.');
 return result.job;
}
// Keep original PDF pages and searchable text when assembling the master file.
async function asPdf(blob){
 await libraries();const bytes=new Uint8Array(await blob.arrayBuffer());
 if(String.fromCharCode(...bytes.slice(0,4))==='%PDF'){
  await window.PDFLib.PDFDocument.load(bytes);return blob;
 }
 const image=await createImageBitmap(blob);try{
  const canvas=document.createElement('canvas');canvas.width=image.width;canvas.height=image.height;canvas.getContext('2d').drawImage(image,0,0);
  const output=await window.PDFLib.PDFDocument.create(),png=await output.embedPng(canvas.toDataURL('image/png'));
  const page=output.addPage([595.28,841.89]),scale=Math.min(555.28/png.width,801.89/png.height),width=png.width*scale,height=png.height*scale;
  page.drawImage(png,{x:(595.28-width)/2,y:(841.89-height)/2,width,height});return new Blob([await output.save()],{type:'application/pdf'});
 }finally{image.close()}
}
async function mergedPdf(blobs){
 await libraries();const output=await window.PDFLib.PDFDocument.create();
 for(const blob of blobs){const input=await window.PDFLib.PDFDocument.load(await blob.arrayBuffer());for(const page of await output.copyPages(input,input.getPageIndices()))output.addPage(page)}
 return new Blob([await output.save()],{type:'application/pdf'});
}
// Small standards-compliant OOXML writer. The covering letter remains editable in Word.
function word(markup){
 const xml=text=>String(text||'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&apos;'}[c])).replace(/[\x00-\x08\x0b\x0c\x0e-\x1f]/g,'');
 const root=document.createElement('div');root.innerHTML=markup;
 const paragraph=(text,bold=false)=>'<w:p><w:pPr><w:spacing w:after="120"/></w:pPr><w:r>'+ (bold?'<w:rPr><w:b/></w:rPr>':'')+'<w:t xml:space="preserve">'+xml(text)+'</w:t></w:r></w:p>';
 const text=node=>{const clone=node.cloneNode(true);clone.querySelectorAll('br').forEach(br=>br.replaceWith('\n'));clone.querySelectorAll('p,div,h1,h2,h3,h4,ol,ul,li').forEach(block=>{block.before(document.createTextNode('\n'));block.after(document.createTextNode('\n'))});return clone.textContent.trim()};
 let body='';for(const page of root.querySelectorAll('.docPage')){
  if(body)body+='<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
  for(const node of page.children){
   if(node.tagName==='TABLE'){
    const rows=[...node.querySelectorAll('tr')],columns=Math.max(...rows.map(row=>row.children.length)),widths=columns===4?[900,6100,1600,1600]:Array(columns).fill(Math.floor(10200/columns));
    body+='<w:tbl><w:tblPr><w:tblW w:w="10200" w:type="dxa"/><w:tblLayout w:type="fixed"/><w:tblBorders>'+['top','left','bottom','right','insideH','insideV'].map(side=>'<w:'+side+' w:val="single" w:sz="4" w:color="999999"/>').join('')+'</w:tblBorders></w:tblPr><w:tblGrid>'+widths.map(width=>'<w:gridCol w:w="'+width+'"/>').join('')+'</w:tblGrid>'+rows.map(row=>'<w:tr>'+[...row.children].map((cell,index)=>'<w:tc><w:tcPr><w:tcW w:w="'+widths[index]+'" w:type="dxa"/></w:tcPr>'+text(cell).split('\n').map(line=>paragraph(line,cell.tagName==='TH')).join('')+'</w:tc>').join('')+'</w:tr>').join('')+'</w:tbl>';
   }else body+=text(node).split('\n').filter(line=>line.trim()).map(line=>paragraph(line,/^H[1-6]$/.test(node.tagName)||node.classList.contains('coverSubject'))).join('');
  }
 }
 const entries={
 '[Content_Types].xml':'<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>',
 '_rels/.rels':'<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
 'word/_rels/document.xml.rels':'<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
 'word/styles.xml':'<?xml version="1.0" encoding="UTF-8"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial"/><w:sz w:val="20"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after="120" w:line="240" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults></w:styles>',
 'word/document.xml':'<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'+body+'<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1700" w:right="850" w:bottom="850" w:left="850"/></w:sectPr></w:body></w:document>'
 };
 return zip(entries,'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
}
function zip(entries,type){
 const encoder=new TextEncoder(),chunks=[],central=[];let offset=0;
 const header=(size)=>{const bytes=new Uint8Array(size);return{bytes,view:new DataView(bytes.buffer)}};
 for(const [name,value]of Object.entries(entries)){
  const filename=encoder.encode(name),data=encoder.encode(value);let crc=0xffffffff;
  for(const byte of data){crc^=byte;for(let i=0;i<8;i++)crc=(crc>>>1)^((crc&1)?0xedb88320:0)}crc=(crc^0xffffffff)>>>0;
  const local=header(30);local.view.setUint32(0,0x04034b50,true);local.view.setUint16(4,20,true);local.view.setUint16(10,0,true);local.view.setUint16(12,33,true);local.view.setUint32(14,crc,true);local.view.setUint32(18,data.length,true);local.view.setUint32(22,data.length,true);local.view.setUint16(26,filename.length,true);chunks.push(local.bytes,filename,data);
  const item=header(46);item.view.setUint32(0,0x02014b50,true);item.view.setUint16(4,20,true);item.view.setUint16(6,20,true);item.view.setUint16(14,33,true);item.view.setUint32(16,crc,true);item.view.setUint32(20,data.length,true);item.view.setUint32(24,data.length,true);item.view.setUint16(28,filename.length,true);item.view.setUint32(42,offset,true);central.push(item.bytes,filename);offset+=30+filename.length+data.length;
 }
 const length=central.reduce((sum,bytes)=>sum+bytes.length,0),end=header(22);end.view.setUint32(0,0x06054b50,true);end.view.setUint16(8,central.length/2,true);end.view.setUint16(10,central.length/2,true);end.view.setUint32(12,length,true);end.view.setUint32(16,offset,true);return new Blob([...chunks,...central,end.bytes],{type});
}
function separate(name,key=''){
 if(/^(cover|tgCover)$/.test(key)||/bank covering letter/i.test(name))return'cover';
 if(/sales contract|customer contract|signed.*contract|contract.*signed|proforma/i.test(name))return'contract';
 if(/goods declaration|^gd\b/i.test(name))return'gd';return'';
}
async function save({customer,contract,lot,rows,uploads,fit,optional=false}){
 if(busy)throw new Error('Shipment files are already being prepared.');busy=true;
 try{
  const parts=folderParts(customer,contract,lot),files=[],master=[],seen=new Set();
  for(const row of rows.filter(row=>row.render&&row.ready)){
   const kind=separate(row.name,row.key),markup=row.render(),folder=row.folder?component(row.folder):'';
   const blob=kind==='cover'?word(markup):await pdf(markup,fit);
   if(!kind)master.push(blob);
   // Keep Customs/TG documents in their respective folders as well as in the master file.
   if(kind||folder||row.key==='commercialDraft')files.push({folder,name:component(row.name)+(kind==='cover'?'.docx':'.pdf'),blob});
  }
  for(const doc of uploads){
   const key=doc.id||doc.downloadUrl||doc.dataUrl;if(!key||seen.has(key))continue;seen.add(key);
   const kind=separate(doc.type||doc.name),folder=doc.folder?component(doc.folder):'',original=await uploadBlob(doc);
   if(kind==='contract')files.push({folder,name:'Signed Sales Contract - '+component(doc.name||'Document'),blob:original});
   else if(kind==='cover')files.push({folder,name:'Uploaded - '+component(doc.name),blob:original});
   else{let converted;try{converted=await asPdf(original)}catch(error){throw new Error('Cannot add '+(doc.name||doc.type||'this upload')+' to the PDF package. Upload a readable PDF or image. '+error.message)}if(kind==='gd')files.push({folder,name:'GD - '+component(String(doc.name||'Document').replace(/\.[^.]+$/,''))+'.pdf',blob:converted});else master.push(converted);
    // Preserve every uploaded original alongside the assembled PDF.
    files.push({folder,name:'Uploaded - '+component(doc.name||'Document'),blob:original});
   }
  }
  if(master.length)files.unshift({folder:'',name:'Master Shipment Documents.pdf',blob:await mergedPdf(master)});
  if(!files.length)throw new Error('No saved documents are available for this lot.');
  // Resolve duplicate filenames without overwriting a different original.
  const used=new Set();for(const file of files){const base=file.name;let index=2;while(used.has(file.folder+'/'+file.name)){const at=base.lastIndexOf('.');file.name=base.slice(0,at)+' ('+index+++')'+base.slice(at)}used.add(file.folder+'/'+file.name)}
  files.forEach((file,index)=>file.storedName=String(index).padStart(3,'0')+'-'+file.name.replace(/[^A-Za-z0-9._-]+/g,'-'));
  const manifest={type:'shipment_archive',customer,contract,lot,folderParts:parts,createdAt:new Date().toISOString(),files:files.map(file=>({folder:file.folder||'',name:file.name,storedName:file.storedName,size:file.blob.size,type:file.blob.type||'application/octet-stream'}))};
  const job=await postJob(manifest,files);
  return{count:files.length,path:parts.join(' / '),jobId:job.id,status:job.status||'PENDING'};
 }finally{busy=false}
}

window.TT_SHIPMENT_FILES={choose,access,save,pdf,word,asPdf,mergedPdf,folderParts,officeLocation};
})();
