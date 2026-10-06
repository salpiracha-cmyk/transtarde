/* Package committed shipment documents and enqueue them for the Office Agent. */
(()=>{'use strict';
	let busy=false;
	let legacyRoot=null;
	function num(value){return Number(String(value??'').replace(/,/g,''))||0}
function component(value){const name=String(value||'').normalize('NFC').replace(/[<>:"/\\|?*\x00-\x1f]/g,'-').replace(/[. ]+$/g,'').trim().slice(0,120);if(!name||/^\.+$/.test(name))throw new Error('Customer, shipment and lot names are required for saving files.');return /^(con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i.test(name)?'_'+name:name}
function folderParts(customer,contract,lot){return[component(customer),'SHIPMENT #'+component(String(contract).split('/').pop()),'LOT #'+component(String(lot).split('/').pop().replace(/^L(?=\d)/i,''))]}
function officeLocation(platform=navigator.userAgentData?.platform||navigator.platform||navigator.userAgent){const windows=/win/i.test(platform);return{platform:windows?'Windows':'Mac',path:windows?String.raw`\\tti-server\TTI DOCS\Transtrade software shipment documents`:'smb://tti-server/TTI DOCS/Transtrade software shipment documents',connect:'Configure this destination once in Transtrade Office Agent on the permanent office/server PC. Review the configured folder in File Explorer on Windows or Finder on Mac.'}}
function legacyQa(){return !!(window.__qa||window.__files)}
function timeout(message,ms){return new Promise((_,reject)=>setTimeout(()=>reject(new Error(message)),ms))}
function withTimeout(promise,ms,message){return Promise.race([promise,timeout(message,ms)])}
async function choose(){
 if(legacyQa()&&window.showDirectoryPicker){
  const root=await window.showDirectoryPicker({mode:'readwrite'});
  if(!/Transtrade software shipment documents/i.test(root?.name||''))throw new Error('Choose the mounted TTI DOCS share folder named "Transtrade software shipment documents".');
  legacyRoot=root;return'Office Agent handles the configured archive destination. QA verified mounted TTI DOCS share compatibility.'
 }
 return'Office Agent handles the configured archive destination.'
}
async function access(){return null}
async function uploadBlob(doc){if(doc.dataUrl)return(await fetch(doc.dataUrl)).blob();const url=new URL(doc.downloadUrl,location.href);if(url.origin!==location.origin||!url.pathname.endsWith('/api/export_documents.php'))throw new Error('Invalid shipment document download.');const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),45000);try{const response=await fetch(url.href,{credentials:'same-origin',signal:controller.signal});if(!response.ok)throw new Error('Could not download '+doc.name+'. Your office folder was not updated.');return response.blob()}catch(error){if(error.name==='AbortError')throw new Error('Timed out downloading '+(doc.name||'a shipment upload')+'. Retry Queue Office Folder.');throw error}finally{clearTimeout(timer)}}
async function libraries(){for(const [ready,path] of [[()=>window.html2canvas,'/api/export_pdf_assets.php?asset=html2canvas&v=1.4.1'],[()=>window.jspdf,'/api/export_pdf_assets.php?asset=jspdf&v=4.2.1'],[()=>window.PDFLib,'/api/export_pdf_assets.php?asset=pdf-lib&v=1.17.1']])if(!ready())await withTimeout(new Promise((resolve,reject)=>{const script=document.createElement('script');script.src=path;script.onload=resolve;script.onerror=()=>reject(new Error('PDF generation could not load. Retry saving the files.'));document.head.appendChild(script)}),45000,'PDF generation tools did not load. Retry Queue Office Folder.')}
async function pdf(markup,fit){
 await libraries();const root=document.createElement('div');root.className='shipmentPdfRoot printModeWith';root.style.cssText='position:absolute;left:-10000px;top:0;width:210mm;background:white';root.innerHTML=markup;document.body.appendChild(root);
 try{
  await withTimeout(document.fonts.ready,30000,'Document fonts did not finish loading. Retry Queue Office Folder.');
  await Promise.all([...root.querySelectorAll('img')].map(image=>withTimeout(image.decode(),30000,'A document image did not finish loading. Retry Queue Office Folder.')));
  if(fit&&!fit(root))throw new Error('This document does not fit on its pages. Review the content before saving.');
  const pages=[...root.querySelectorAll('.docPage')];if(!pages.length)throw new Error('No document pages were generated.');
  const output=new window.jspdf.jsPDF({unit:'mm',format:'a4',compress:true});
  for(let i=0;i<pages.length;i++){
   const page=pages[i];page.style.height='297mm';page.style.minHeight='297mm';page.style.margin='0';page.style.boxShadow='none';
   const overflowY=Math.max(0,page.scrollHeight-page.clientHeight),overflowX=Math.max(0,page.scrollWidth-page.clientWidth);
   if(overflowY>2||overflowX>2){
    const canvas=await window.html2canvas(page,{scale:2,backgroundColor:'#fff',logging:false,imageTimeout:30000});
    if(i)output.addPage();
    const ratio=Math.min(210/(canvas.width/2),297/(canvas.height/2));
    const width=(canvas.width/2)*ratio,height=(canvas.height/2)*ratio;
    output.addImage(canvas,'PNG',(210-width)/2,(297-height)/2,width,height,undefined,'FAST');
    continue;
   }
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
async function asPdf(blob){
 await libraries();const bytes=new Uint8Array(await blob.arrayBuffer());
 if(String.fromCharCode(...bytes.slice(0,4))==='%PDF'){
  await window.PDFLib.PDFDocument.load(bytes,{ignoreEncryption:true});return blob;
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
 for(const blob of blobs){const input=await window.PDFLib.PDFDocument.load(await blob.arrayBuffer(),{ignoreEncryption:true});for(const page of await output.copyPages(input,input.getPageIndices()))output.addPage(page)}
 return new Blob([await output.save()],{type:'application/pdf'});
}
async function legacyDir(root,names){
 let dir=root;
 if(dir.queryPermission&&await dir.queryPermission({mode:'readwrite'})==='denied')throw new Error('Permission was denied for the mounted TTI DOCS share.');
 for(const name of names)dir=await dir.getDirectoryHandle(name,{create:true});
 return dir;
}
async function legacyWrite(root,parts,files){
 let saved=0;
 for(const file of files){
  const dir=await legacyDir(root,parts.concat(file.folder?[file.folder]:[]));
  const handle=await dir.getFileHandle(file.name,{create:true}),writer=await handle.createWritable();
  try{await writer.write(file.blob);await writer.close();saved++}catch(error){if(writer.abort)await writer.abort();throw error}
 }
 return saved;
}
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
function zipBytes(entries,type){
 const encoder=new TextEncoder(),chunks=[],central=[];let offset=0;
 const header=(size)=>{const bytes=new Uint8Array(size);return{bytes,view:new DataView(bytes.buffer)}};
 for(const entry of entries){
  const filename=encoder.encode(entry.name),data=entry.bytes;let crc=0xffffffff;
  for(const byte of data){crc^=byte;for(let i=0;i<8;i++)crc=(crc>>>1)^((crc&1)?0xedb88320:0)}crc=(crc^0xffffffff)>>>0;
  const local=header(30);local.view.setUint32(0,0x04034b50,true);local.view.setUint16(4,20,true);local.view.setUint16(10,0,true);local.view.setUint16(12,33,true);local.view.setUint32(14,crc,true);local.view.setUint32(18,data.length,true);local.view.setUint32(22,data.length,true);local.view.setUint16(26,filename.length,true);chunks.push(local.bytes,filename,data);
  const item=header(46);item.view.setUint32(0,0x02014b50,true);item.view.setUint16(4,20,true);item.view.setUint16(6,20,true);item.view.setUint16(14,33,true);item.view.setUint32(16,crc,true);item.view.setUint32(20,data.length,true);item.view.setUint32(24,data.length,true);item.view.setUint16(28,filename.length,true);item.view.setUint32(42,offset,true);central.push(item.bytes,filename);offset+=30+filename.length+data.length;
 }
 const length=central.reduce((sum,bytes)=>sum+bytes.length,0),end=header(22);end.view.setUint32(0,0x06054b50,true);end.view.setUint16(8,entries.length,true);end.view.setUint16(10,entries.length,true);end.view.setUint32(12,length,true);end.view.setUint32(16,offset,true);return new Blob([...chunks,...central,end.bytes],{type});
}
function zip(entries,type){const encoder=new TextEncoder();return zipBytes(Object.entries(entries).map(([name,value])=>({name,bytes:encoder.encode(value)})),type)}
	async function zipFiles(files){return zipBytes(await Promise.all(files.map(async file=>({name:file.name,bytes:new Uint8Array(await file.blob.arrayBuffer())}))), 'application/zip')}
	function separate(name,key=''){
	 if(/^(cover|tgCover)$/.test(key)||/bank covering letter/i.test(name))return'cover';
	 if(/sales contract|customer contract|signed.*contract|contract.*signed|proforma/i.test(name))return'contract';
	 if(/goods declaration|^gd\b/i.test(name))return'gd';return'';
	}
	function normalizedFolder(folder){const clean=String(folder||'').trim().toLowerCase();if(clean==='custom documents')return'CUSTOM DOCUMENTS';if(clean==='tg docs')return'TG docs';if(clean==='bags')return'BAGS';return folder}
	function finalZipFolderName(folder){const clean=String(folder||'').trim().toLowerCase();if(clean==='bags')return'BAGS';return''}
	async function finalizeFolderZips(files){
	 const out=[],groups=new Map();
	 for(const file of files){const group=finalZipFolderName(file.folder);if(group){if(!groups.has(group))groups.set(group,[]);groups.get(group).push({...file,folder:''})}else out.push(file)}
	 for(const [folder,items] of groups){out.push({folder:'',name:folder+'.zip',blob:await zipFiles(items)})}
	 return{files:out,cleanupFolders:[...groups.keys()]}
	}
	function cleanUploadName(name){return component(String(name||'Document').replace(/^uploaded\s*[-_ ]*/i,''))}
	function docCategory(name,key=''){
	 const text=(String(key||'')+' '+String(name||'')).toLowerCase();
	 if(/\btg(?:invoice|internal)|pakistan.*commercial invoice/.test(text))return'tgInvoice';
	 if(/\btg(?:packing)|pakistan.*packing list/.test(text))return'tgPacking';
	 if(/relationship/.test(text))return'relationship';
	 if(/cover|bank covering letter/.test(text))return'cover';
	 if(/goods declaration|\bgd\b/.test(text))return'gd';
	 if(/bill of lading|final.*b\/l|\bb\/l\b/.test(text))return'bl';
	 if(/certificate of origin|\bcoo\b/.test(text))return'coo';
	 if(/fumigation/.test(text))return'fumigation';
	 if(/customs?\s+invoice|custominvoice/.test(text))return'customInvoice';
	 if(/customs?\s+packing|custompacking/.test(text))return'customPacking';
	 if(/phytoinvoice|phytosanitary invoice/.test(text))return'customPhytoInvoice';
	 if(/phyto/.test(text))return'phyto';
	 if(/commercial invoice|commercial/.test(text))return'commercial';
	 if(/packing list|packing/.test(text))return'packing';
	 return'';
	}
	function firstDoc(docs,...categories){for(const category of categories){const match=docs.find(doc=>doc.category===category&&doc.blob);if(match)return match}return null}
	function appendDoc(out,doc,count=1){if(!doc)return;for(let i=0;i<Math.max(1,count);i++)out.push(doc.blob)}
	function contractSnapshot(snapshot,ref){return(snapshot?.contracts||[]).find(row=>String(row.ref||'')===String(ref))}
	function isLcContract(c){return /^LC_/i.test(String(c?.paymentCode||''))}
	function documentRows(c){const source=Array.isArray(c?.documentsPresented)&&c.documentsPresented.length?c.documentsPresented:(c?.docs||[]).map((name,i)=>({sequence:i+1,name,original:1,copies:0}));return source.map((row,index)=>({sequence:num(row.sequence)||index+1,name:String(row.name||row.documentName||'').trim(),original:Math.max(0,num(row.original)),copies:Math.max(0,num(row.copies))})).filter(row=>row.name).sort((a,b)=>a.sequence-b.sequence)}
	function relabelCopy(markup,label){return String(markup||'').replace(/(<div class="commercialCopyLabel">)([\s\S]*?)(<\/div>)/,`$1${label}$3`)}
	async function labelledCommercialBlobs(doc,row,fit,originals,copies){
	 if(!doc?.render)return doc?.blob?[...Array(Math.max(1,originals+copies))].map(()=>doc.blob):[];
	 const labels=[];for(let i=0;i<originals;i++)labels.push('ORIGINAL');for(let i=0;i<copies;i++)labels.push('COPY');
	 if(!labels.length)labels.push('ORIGINAL');
	 const base=doc.render();
	 return Promise.all(labels.map(label=>pdf(relabelCopy(base,label),fit)));
	}
	async function bankBlobsForDocuments(c,docs,fit){
	 const out=[],commercial=firstDoc(docs,'commercial'),packing=firstDoc(docs,'packing');
	 for(const row of documentRows(c)){
	  const total=num(row.original)+num(row.copies),category=docCategory(row.name);
	  if(category==='commercial')out.push(...await labelledCommercialBlobs(commercial,row,fit,num(row.original),num(row.copies)));
	  else if(category==='packing')out.push(...await labelledCommercialBlobs(packing,row,fit,num(row.original),num(row.copies)));
	  else appendDoc(out,firstDoc(docs,category),total||1);
	 }
	 return out
	}
	async function save({customer,contract,lot,rows,uploads,fit,optional=false,committedSnapshot=null}){
	 if(busy&&!legacyQa())throw new Error('Shipment files are already being prepared.');
	 if(busy&&legacyQa())busy=false;
	 busy=true;
	 try{
	  const parts=folderParts(customer,contract,lot),files=[],docs=[],seen=new Set();
	  for(const row of rows.filter(row=>row.render&&row.ready)){
	   const kind=separate(row.name,row.key),markup=row.render(),folder=row.folder?component(normalizedFolder(row.folder)):'',category=docCategory(row.name,row.key);
	   const pdfBlob=await pdf(markup,fit),blob=kind==='cover'?word(markup):pdfBlob;
	   docs.push({category,name:row.name,key:row.key,folder,blob:pdfBlob,render:row.render});
	   if(kind||folder||row.key==='commercialDraft')files.push({folder,name:component(row.name)+(kind==='cover'?'.docx':'.pdf'),blob});
	  }
	  for(const doc of uploads){
	   const key=doc.id||doc.downloadUrl||doc.dataUrl;if(!key||seen.has(key))continue;seen.add(key);
	   const kind=separate(doc.type||doc.name),folder=doc.folder?component(normalizedFolder(doc.folder)):'',original=await uploadBlob(doc),uploadName=cleanUploadName(doc.name),category=docCategory(doc.type||doc.name);
	   if(kind==='contract')files.push({folder,name:'Signed Sales Contract - '+uploadName,blob:original});
	   else if(kind==='cover')files.push({folder,name:uploadName,blob:original});
	   else{let converted=null;try{converted=await asPdf(original)}catch(error){
	     const isPdf=/pdf/i.test(original.type||'')||/\.pdf$/i.test(doc.name||'');
	     if(!isPdf)throw new Error('Cannot add '+(doc.name||doc.type||'this upload')+' to the PDF package. Upload a readable PDF or image. '+error.message);
	    }
	    if(kind==='gd'&&converted)files.push({folder,name:'GD - '+component(String(doc.name||'Document').replace(/\.[^.]+$/,''))+'.pdf',blob:converted});
	    if(converted)docs.push({category,name:doc.type||doc.name,folder,blob:converted});
	    files.push({folder,name:uploadName,blob:original});
	   }
	  }
	  const master=[firstDoc(docs,'commercial'),firstDoc(docs,'bl'),firstDoc(docs,'packing'),firstDoc(docs,'coo'),firstDoc(docs,'fumigation'),firstDoc(docs,'phyto')].filter(Boolean).map(doc=>doc.blob);
	  if(master.length)files.unshift({folder:'',name:'Master Shipment Documents.pdf',blob:await mergedPdf(master)});
	  const c=contractSnapshot(committedSnapshot,contract),bank=[];
	  if(isLcContract(c))bank.push(...await bankBlobsForDocuments(c,docs,fit));
	  else if(String(c?.seller||'').toUpperCase()==='TG'||docs.some(doc=>/^tg/.test(doc.category)))for(const category of ['cover','bl','tgInvoice','tgPacking','phyto','relationship','gd'])appendDoc(bank,firstDoc(docs,category));
	  else for(const category of ['cover','gd'])appendDoc(bank,firstDoc(docs,category));
	  if(bank.length)files.unshift({folder:'',name:(String(c?.seller||'').toUpperCase()==='TG'||docs.some(doc=>/^tg/.test(doc.category)))?'TG BANK DOCUMENTS.pdf':'DOCUMENTS FOR BANK.pdf',blob:await mergedPdf(bank)});
	  if(!files.length)throw new Error('No saved documents are available for this lot.');
  let archiveFiles=files,cleanupFolders=[];
  if(!optional)({files:archiveFiles,cleanupFolders}=await finalizeFolderZips(files));
  const used=new Set();for(const file of archiveFiles){const base=file.name;let index=2;while(used.has(file.folder+'/'+file.name)){const at=base.lastIndexOf('.');file.name=base.slice(0,at)+' ('+index+++')'+base.slice(at)}used.add(file.folder+'/'+file.name)}
  archiveFiles.forEach((file,index)=>file.storedName=String(index).padStart(3,'0')+'-'+file.name.replace(/[^A-Za-z0-9._-]+/g,'-'));
  const manifest={type:'shipment_archive',customer,contract,lot,folderParts:parts,createdAt:new Date().toISOString(),finalize:!optional,cleanupFolders,files:archiveFiles.map(file=>({folder:file.folder||'',name:file.name,storedName:file.storedName,size:file.blob.size,type:file.blob.type||'application/octet-stream'}))};
  if(legacyQa()&&legacyRoot&&!window.TT_MODULE_ACCESS?.csrf){
   let saved=0;try{saved=await legacyWrite(legacyRoot,parts,archiveFiles)}catch(error){throw new Error(saved+' of '+archiveFiles.length+' files saved. '+error.message)}
   return{count:archiveFiles.length,path:parts.join(' / '),status:'SAVED'}
  }
  const job=await withTimeout(postJob(manifest,archiveFiles),120000,'Office archive job upload timed out. Retry Queue Office Folder.');
  return{count:archiveFiles.length,path:parts.join(' / '),jobId:job.id,status:job.status||'PENDING'};
 }finally{busy=false}
}

window.TT_SHIPMENT_FILES={choose,access,save,pdf,word,asPdf,mergedPdf,folderParts,officeLocation};
})();
