/* Save committed shipment documents to a user-selected mounted office share. */
(()=>{'use strict';
let directory=null,busy=false;
const storeName='shipment-folder';
function database(){return new Promise((resolve,reject)=>{const request=indexedDB.open('TranstradeShipmentFiles',1);request.onupgradeneeded=()=>request.result.createObjectStore(storeName);request.onsuccess=()=>resolve(request.result);request.onerror=()=>reject(request.error)})}
async function remembered(write){const db=await database();try{return await new Promise((resolve,reject)=>{const tx=db.transaction(storeName,write?'readwrite':'readonly'),store=tx.objectStore(storeName),request=write?store.put(write,'root'):store.get('root');tx.oncomplete=()=>resolve(request.result);tx.onerror=()=>reject(tx.error);tx.onabort=()=>reject(tx.error)})}finally{db.close()}}
if(window.indexedDB)remembered().then(handle=>{directory=handle||null}).catch(()=>{});
function component(value){const name=String(value||'').normalize('NFC').replace(/[<>:"/\\|?*\x00-\x1f]/g,'-').replace(/[. ]+$/g,'').trim().slice(0,120);if(!name||/^\.+$/.test(name))throw new Error('Customer, shipment and lot names are required for saving files.');return /^(con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i.test(name)?'_'+name:name}
function folderParts(customer,contract,lot){return[component(customer),'SHIPMENT #'+component(String(contract).split('/').pop()),'LOT #'+component(String(lot).split('/').pop().replace(/^L(?=\d)/i,''))]}
async function choose(){if(!window.showDirectoryPicker)throw new Error('Open this page in desktop Chrome or Edge and mount the TTI DOCS share first.');directory=await window.showDirectoryPicker({id:'transtrade-shipments',mode:'readwrite'});await remembered(directory);return directory.name}
async function access(optional=false){if(!directory&&window.indexedDB)directory=await remembered().catch(()=>null);if(!directory){if(optional)return null;await choose()}if(await directory.queryPermission({mode:'readwrite'})!=='granted'){if(optional)return null;if(await directory.requestPermission({mode:'readwrite'})!=='granted')throw new Error('Folder access was not granted. Your files remain saved in Transtrade.')}return directory}
async function uploadBlob(doc){if(doc.dataUrl)return(await fetch(doc.dataUrl)).blob();const url=new URL(doc.downloadUrl,location.href);if(url.origin!==location.origin||!url.pathname.endsWith('/api/export_documents.php'))throw new Error('Invalid shipment document download.');const response=await fetch(url.href,{credentials:'same-origin'});if(!response.ok)throw new Error('Could not download '+doc.name+'. Your office folder was not updated.');return response.blob()}
async function libraries(){for(const [ready,path] of [[()=>window.html2canvas,'/api/export_pdf_assets.php?asset=html2canvas&v=1.4.1'],[()=>window.jspdf,'/api/export_pdf_assets.php?asset=jspdf&v=4.2.1']])if(!ready())await new Promise((resolve,reject)=>{const script=document.createElement('script');script.src=path;script.onload=resolve;script.onerror=()=>reject(new Error('PDF generation could not load. Retry saving the files.'));document.head.appendChild(script)})}
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
async function write(folder,name,blob){const handle=await folder.getFileHandle(name,{create:true}),stream=await handle.createWritable();try{await stream.write(blob);await stream.close()}catch(error){await stream.abort().catch(()=>{});throw error}}
async function save({customer,contract,lot,rows,uploads,fit,optional=false}){
 if(busy)throw new Error('Shipment files are already being saved.');const root=await access(optional);if(!root)return{skipped:true};busy=true;
 try{
  const parts=folderParts(customer,contract,lot),files=[],seen=new Set();
  for(const row of rows.filter(row=>row.render&&row.ready))files.push({folder:row.folder?component(row.folder):'',name:component(row.name)+'.pdf',blob:await pdf(row.render(),fit)});
  for(const doc of uploads){const key=doc.id||doc.downloadUrl||doc.dataUrl;if(!key||seen.has(key))continue;seen.add(key);const folder=doc.folder?component(doc.folder):'';let name='Uploaded - '+component(doc.name||'Document');if(files.some(file=>file.folder===folder&&file.name===name)){const at=name.lastIndexOf('.'),suffix=' - '+component(doc.id||String(files.length));name=at>0?name.slice(0,at)+suffix+name.slice(at):name+suffix}files.push({folder,name,blob:await uploadBlob(doc)})}
  if(!files.length)throw new Error('No saved documents are available for this lot.');
  let folder=root;for(const part of parts)folder=await folder.getDirectoryHandle(part,{create:true});
  const subfolders=new Map();let count=0;try{for(const file of files){let target=folder;if(file.folder){if(!subfolders.has(file.folder))subfolders.set(file.folder,await folder.getDirectoryHandle(file.folder,{create:true}));target=subfolders.get(file.folder)}await write(target,file.name,file.blob);count++}}catch(error){throw new Error(count+' of '+files.length+' files saved. Retry Save to finish. '+error.message)}
  return{count,path:[root.name,...parts].join(' / ')};
 }finally{busy=false}
}
window.TT_SHIPMENT_FILES={choose,save,pdf,folderParts};
})();
