(()=>{'use strict';
const STORE='transtrade_export_v3_operational';
const MARKERS='transtrade_office_archive_markers_v1';
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const num=v=>Number(v||0);
const fmt=d=>{const m=String(d||'').match(/^(\d{4})-(\d{2})-(\d{2})/);return m?`${m[3]}-${m[2]}-${m[1]}`:String(d||'')};
const readJSON=(key,fallback)=>{try{return JSON.parse(localStorage.getItem(key)||'')||fallback}catch{return fallback}};
const state=()=>readJSON(STORE,{contracts:[],shipments:[],customers:[]});
const customer=(data,id)=>data.customers?.find(row=>row.id===id)||{};
const contract=(data,ref)=>data.contracts?.find(row=>row.ref===ref)||{};
const buyerName=(data,c,s)=>customer(data,c.customerId).name||s.buyer||'';
const shipmentKey=s=>String(s?.id||s?.contractRef||'');
const folderLot=s=>String(s?.lotId||'PRE-SHIPMENT').trim()||'PRE-SHIPMENT';
const activeShipments=data=>(data.shipments||[]).filter(s=>s&&!s.cancelled&&s.kind!=='lot');
function marker(data){
 const out={};
 for(const s of activeShipments(data)){
  const c=contract(data,s.contractRef);
  out[shipmentKey(s)]={contractRef:s.contractRef,received:!!c.received,bags:(s.bagOrders||[]).filter(po=>!po.cancelled).map(po=>[po.poNo,po.issuedAt,(po.lines||[]).map(line=>[line.artworkDocument?.id||line.artworkDocument?.downloadUrl||'',line.masterBag?.artworkDocument?.id||line.masterBag?.artworkDocument?.downloadUrl||'']).flat().join('|')].join('|')).join('||')}
 }
 return out
}
function page(title,body){return`<div class="printDoc"><section class="docPage branded"><h1 class="docTitle">${esc(title)}</h1>${body}<div class="docPageNo">Generated ${fmt(new Date().toISOString())}</div></section></div>`}
function salesContractRow(data,c){
 const cu=customer(data,c.customerId),packs=c.packings||[],docs=c.documentsPresented||[];
 return{key:'officeHookSalesContract',name:'Sales Contract',ready:true,render:()=>page('SALES CONTRACT',`<table class="docMeta"><tr><td class="lbl">Contract Reference</td><td>${esc(c.ref)}</td><td class="lbl">Date</td><td>${fmt(c.date)}</td></tr><tr><td class="lbl">Buyer</td><td>${esc(cu.name||'')}</td><td class="lbl">Quantity</td><td>${num(c.qty).toFixed(3)} M.TONS</td></tr></table><h3 class="docSection">Buyer</h3><p>${esc(cu.name||'')}<br>${esc(cu.address||'')}</p><h3 class="docSection">Quality</h3><p>${esc(c.quality||c.product||'')}</p><h3 class="docSection">Packing / Brand</h3>${packs.map((p,i)=>`<p>${i+1}. ${esc(p.brand||'')} - ${num(p.size)} ${esc(c.packingUnit||'KG')} ${esc(p.type||'Bags')} - ${num(p.containers)} container(s)</p>`).join('')}<h3 class="docSection">Price</h3><p>${esc(c.currency||'')} ${packs.map(p=>num(p.contractRate||p.price).toFixed(2)).filter(Boolean).join(' / ')} ${esc(c.incoterm||'')}</p>${docs.length?`<h3 class="docSection">Documents</h3><table class="docTable"><tbody>${docs.map((doc,i)=>`<tr><td>${i+1}</td><td>${esc(doc.name)}</td><td>${num(doc.original)}</td><td>${num(doc.copies)}</td></tr>`).join('')}</tbody></table>`:''}`)}
}
function bagPORow(po){
 return{key:'officeHookBagPO-'+(po.poNo||po.id||''),name:'Bag PO - '+(po.poNo||'Bag Order'),ready:true,folder:'BAGS',render:()=>page('PURCHASE ORDER',`<table class="docMeta"><tr><td class="lbl">P.O. Number</td><td>${esc(po.poNo||'')}</td><td class="lbl">Supplier</td><td>${esc(po.supplier||'')}</td></tr><tr><td class="lbl">Required Delivery</td><td>${fmt(po.requiredDate)||'To Be Advised Later'}</td><td class="lbl">Deliver To</td><td>${esc(po.deliverTo||'')}</td></tr></table><table class="docTable"><thead><tr><th>#</th><th>Brand</th><th>Bag Type</th><th>Size</th><th>Tare</th><th>Total Order</th></tr></thead><tbody>${(po.lines||[]).map((line,i)=>`<tr><td>${i+1}</td><td>${esc(line.brand||'')}</td><td>${esc(line.type||'')}</td><td>${num(line.size)} ${esc(line.unit||'KG')}</td><td>${num(line.tare)} g</td><td>${num(line.totalBags).toLocaleString()}</td></tr>`).join('')}</tbody></table>${(po.lines||[]).map(line=>`${line.artworkData?`<h3 class="docSection">Approved Bag Marking - ${esc(line.brand||'')}</h3><img class="poMarking" src="${esc(line.artworkData)}" alt="Bag marking">`:''}${line.masterBag?.artworkData?`<h3 class="docSection">Approved Master Bag Marking - ${esc(line.brand||'')}</h3><img class="poMarking" src="${esc(line.masterBag.artworkData)}" alt="Master bag marking">`:''}`).join('')}`)}
}
function bagUploads(s){
 const out=[];
 for(const po of s.bagOrders||[])for(const line of po.lines||[]){
  if(line.artworkDocument)out.push({...line.artworkDocument,type:'Bag Marking - '+(line.brand||po.poNo||'Bag'),folder:'BAGS'});
  if(line.masterBag?.artworkDocument)out.push({...line.masterBag.artworkDocument,type:'Master Bag Marking - '+(line.brand||po.poNo||'Bag'),folder:'BAGS'})
 }
 return out
}
function findShipmentForOptions(data,options){
 const contractRef=String(options?.contract||''),lot=String(options?.lot||'');
 return(data.shipments||[]).find(s=>s.contractRef===contractRef&&(String(s.lotId||'')===lot||(!s.lotId&&lot==='PRE-SHIPMENT')))||activeShipments(data).find(s=>s.contractRef===contractRef)
}
function augmentOptions(options){
 const data=state(),s=findShipmentForOptions(data,options);if(!s)return options;
 const existing=new Set((options.rows||[]).map(row=>String(row.key||row.name||'')));
 const rows=[...(options.rows||[])],uploads=[...(options.uploads||[])];
 for(const po of s.bagOrders||[])if(!po.cancelled&&!existing.has('officeHookBagPO-'+(po.poNo||po.id||'')))rows.push(bagPORow(po));
 uploads.push(...bagUploads(s));
 return{...options,rows,uploads}
}
async function officeSaveShipment(s,reason){
 if(!window.TT_SHIPMENT_FILES?.save)return;
 const data=state(),c=contract(data,s.contractRef);if(!c.ref)return;
 const rows=[salesContractRow(data,c),...(s.bagOrders||[]).filter(po=>!po.cancelled).map(bagPORow)];
 const uploads=bagUploads(s);
 if(!rows.length&&!uploads.length)return;
 try{await window.TT_SHIPMENT_FILES.save({customer:buyerName(data,c,s),contract:s.contractRef,lot:folderLot(s),rows,uploads,optional:true})}
 catch(error){console.warn('Office Agent shipment archive hook failed:',reason,error)}
}
function changedShipments(before,after){
 const old=marker(before),now=marker(after),out=[];
 for(const s of activeShipments(after)){const key=shipmentKey(s),a=now[key],b=old[key]||{};if((a.received&&!b.received)||(a.bags&&a.bags!==b.bags))out.push(s)}
 return out
}
function installSaveWrapper(){
 const files=window.TT_SHIPMENT_FILES;if(!files?.save||files.__officeHooked)return false;
 const original=files.save.bind(files);files.save=options=>original(augmentOptions(options));files.__officeHooked=true;
 return true
}
function installSyncWrapper(){
 const sync=window.TT_SHARED_SYNC;if(!sync?.saveNow||sync.__officeHooked)return false;
 const original=sync.saveNow.bind(sync);sync.saveNow=async(...args)=>{const before=state(),result=await original(...args),after=state();const last=readJSON(MARKERS,null);if(!last)localStorage.setItem(MARKERS,JSON.stringify(marker(after)));else for(const s of changedShipments(before,after))officeSaveShipment(s,'confirmed-change');localStorage.setItem(MARKERS,JSON.stringify(marker(after)));return result};sync.__officeHooked=true;
 return true
}
function boot(){installSaveWrapper();installSyncWrapper();if(!localStorage.getItem(MARKERS))localStorage.setItem(MARKERS,JSON.stringify(marker(state())))}
boot();let tries=0;const timer=setInterval(()=>{boot();if(++tries>40||(window.TT_SHIPMENT_FILES?.__officeHooked&&window.TT_SHARED_SYNC?.__officeHooked))clearInterval(timer)},250);
})();
