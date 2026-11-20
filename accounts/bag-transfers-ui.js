(() => {
'use strict';
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])),norm=v=>String(v??'').trim().replace(/\s+/g,' ').toLowerCase();
const dateText=value=>String(value??'').replace(/^(\d{4})-(\d{2})-(\d{2})$/,'$3-$2-$1');
const today=()=>new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Karachi',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
let root=null,active=null,data=null,sequence=0,busy=false,transferView=false;const drafts=new Map(),currentReceipts=new Map();
function place(entity){
 const mill=typeof currentMill==='undefined'?null:currentMill;if(!mill)throw Error('Select the physical mill first.');
 const matches=(window.TT_MODULE_ACCESS?.masters?.mills??window.TT_MODULE_ACCESS?.workflowSelectors?.locations??[]).filter(row=>norm(row.values?.[0])===norm(mill.name));if(matches.length!==1)throw Error('Resolve this physical mill in Mills & Locations.');
 return {entity,locationId:String(matches[0].id),millName:matches[0].values[0],key:entity+'|'+String(matches[0].id)};
}
const ownership=()=>document.querySelector('[name=exportBagOwnership]:checked')?.value||'our';
const ADD_DESTINATION='__add_destination__';
const isFellow=lot=>lot.sourceKind==='OTHER_EXPORTER';
function showAction(transfer,moveFocus=true){
 if(busy)return;
 transferView=transfer;
 for(const [selector,on] of [['[data-export-bags-receive]',!transfer],['[data-export-bags-transfer]',transfer]]){
  const button=document.querySelector(selector);
  button?.setAttribute('aria-pressed',String(on));
  button?.classList.toggle('sel',on);
 }
 for(const [id,owner] of [['ourExportBags','our'],['otherExporterBags','other']]){
  const receipt=document.getElementById(id);
  if(receipt)receipt.hidden=transfer||ownership()!==owner;
 }
 if(transfer)void open(undefined,moveFocus);
 else{
  if(root)root.hidden=true;
  if(ownership()==='other')void window.TT_OTHER_EXPORTER_BAGS?.open();
 }
}
function mountActions(){
 const anchor=document.querySelector('#newbags [name=exportBagOwnership]')?.closest('fieldset');
 if(!anchor)return;
 const actions=document.createElement('div');
 actions.className='toggle';
 actions.setAttribute('role','group');
 actions.setAttribute('aria-label','Export bag action');
 actions.innerHTML='<button type="button" data-export-bags-receive class="sel" aria-pressed="true">Receive Bags</button><button type="button" data-export-bags-transfer aria-pressed="false">Transfer Empty Bags</button>';
 actions.firstElementChild.onclick=()=>showAction(false);
 actions.lastElementChild.onclick=()=>showAction(true);
 anchor.after(actions);
}
const pendingKey=scope=>'tt_ui_bag_pending_'+window.TT_MODULE_ACCESS?.userId+'_'+scope.entity+'_'+scope.locationId;
const q=selector=>root?.querySelector(selector),message=text=>{if(q('[data-transfer-message]'))q('[data-transfer-message]').textContent=text;};
function quantities(selector){return Object.fromEntries([...(root?.querySelectorAll(selector)||[])].map(input=>[input.dataset.sourceLot||input.dataset.ackLine,input.value]));}
function remember(){if(!active||!root||!q('[data-transfer-date]'))return;drafts.set(active.key,{date:q('[data-transfer-date]').value,brand:q('[data-transfer-brand]').value,destination:q('[data-transfer-destination]').value,source:quantities('[data-source-lot]'),ack:q('[data-ack-id]')?{id:q('[data-ack-id]').value,action:q('[data-ack-action]').value,date:q('[data-ack-date]').value,reason:q('[data-ack-reason]').value,accepted:!!q('[data-accepted-inspection]')?.checked,quantities:quantities('[data-ack-line]')}:null});}
function build(scope){
 root?.remove();root=document.createElement('section');root.id='acknowledgedBagTransfers';root.className='card';
 root.innerHTML='<h3>Transfer Empty Bags</h3><div data-transfer-pending></div><fieldset data-dispatch-fields disabled><div class="r3"><label>Brand<select data-transfer-brand><option value="">Choose brand</option></select></label><label>From<input readonly data-transfer-location></label><label>To<select data-transfer-destination><option value="">Choose destination</option></select></label><label>Date<input type="date" data-transfer-date></label><label>Bags<input inputmode="numeric" data-transfer-quantity></label><div data-transfer-stock role="status"></div></div><div class="actions"><button type="button" class="ok" data-dispatch-bags disabled>Confirm Transfer</button></div></fieldset><form data-new-destination hidden><h4>Add destination</h4><div class="r3"><label>Physical location name<input data-destination-name maxlength="160" required></label><label>Location type<select data-destination-type required><option value="">Choose type</option><option>Warehouse</option><option>External Mill</option><option>Stock Location</option></select></label></div><div class="actions"><button type="submit" class="ok">Save destination</button><button type="button" class="sec" data-close-destination>Cancel</button></div><p role="status" data-destination-error></p></form><button type="button" class="sec" data-transfer-refresh>Refresh stock</button><p role="status" data-transfer-message></p><div data-transfer-ack></div><details><summary>Recent transfers</summary><div style="max-width:100%;overflow:auto" data-transfer-history></div></details>';
 (document.getElementById('newbags')||document.getElementById('ourExportBags'))?.append(root);
 q('[data-transfer-location]').value=scope.millName;q('[data-transfer-date]').value=drafts.get(scope.key)?.date||today();
 q('[data-transfer-refresh]').onclick=()=>void refresh();
 q('[data-transfer-date]').onchange=()=>{updateDispatch();message('Refresh stock for the selected date.');};
 q('[data-transfer-brand]').onchange=()=>selectStockLot();
 const destination=q('[data-transfer-destination]'),editor=q('[data-new-destination]');
 destination.onchange=()=>{
  if(destination.value===ADD_DESTINATION){
   destination.value=destination.dataset.selected||'';
   if(data?.canAddDestination){
    editor.hidden=false;
    window.TT_FORM_VIEWPORT?.open(editor);
    q('[data-destination-name]').focus({preventScroll:true});
   }
  }
  updateDispatch();
 };
 editor.onsubmit=event=>{event.preventDefault();void addDestination();};
 q('[data-close-destination]').onclick=()=>{
  editor.hidden=true;
  destination.focus({preventScroll:true});
  updateDispatch();
 };
 q('[data-transfer-quantity]').oninput=updateDispatch;
 q('[data-dispatch-bags]').onclick=()=>void dispatch();return root;
}
function pending(){
 const node=q('[data-transfer-pending]');if(!node||!active)return;node.replaceChildren();let intent;
 try{intent=JSON.parse(localStorage.getItem(pendingKey(active))||'null');}catch{message('The previous movement retry record needs review. Your entry is retained.');return;}
 if(!intent)return;node.innerHTML='<h4>Previous movement awaiting confirmation</h4><p>'+esc(intent.body.action)+' · '+esc(dateText(intent.body.date))+'. '+(intent.editable?'The server refused this entry. Correct its quantities and submit again, or discard the refused request.':'Confirm this movement before recording another.')+'</p><button type="button" class="sec" data-confirm-pending>Confirm previous movement</button>';
 node.querySelector('button').onclick=()=>void send(intent.body,true);if(intent.editable){const discard=document.createElement('button');discard.type='button';discard.className='sec';discard.textContent='Discard refused request';discard.onclick=()=>{localStorage.removeItem(pendingKey(active));pending();message('Refused request discarded. Your current entry is retained.');};node.append(discard);}
}
function updateDispatch(){
 const lot=data?.inventory?.lots?.[q('[data-transfer-brand]').value],qty=Number(q('[data-transfer-quantity]').value);
 const available=lot?.committableQty??lot?.availableQty,validQty=Number.isSafeInteger(qty)&&qty>0&&qty<=available;
 const destination=q('[data-transfer-destination]');
 destination.dataset.selected=destination.value;
 if(lot)q('[data-transfer-stock]').textContent=available.toLocaleString()+' bags available'+(validQty?' · '+(available-qty).toLocaleString()+' left after transfer':'');
 q('[data-dispatch-bags]').disabled=!data?.canCreate||!lot||!destination.value||destination.value===ADD_DESTINATION||!q('[data-new-destination]').hidden||!validQty||data.inventory.asOfDate!==q('[data-transfer-date]').value;
}
function selectStockLot(saved={}){
 const id=q('[data-transfer-brand]').value,lot=data.inventory.lots?.[id],input=q('[data-transfer-quantity]');
 if(lot)input.dataset.sourceLot=id;else delete input.dataset.sourceLot;
 input.value=saved[id]??'';input.disabled=!lot||!data.canCreate;
 q('[data-transfer-stock]').textContent=lot?(lot.committableQty??lot.availableQty).toLocaleString()+' bags available':q('[data-transfer-brand]').options.length===1?'No bags available for transfer.':'';
 updateDispatch();
}
function renderStock(saved={}){
 const lots=Object.values(data.inventory.lots||{}).filter(lot=>{
  const available=lot.committableQty??lot.availableQty;
  return isFellow(lot)===(active.ownership==='other')&&!lot.hold&&Number.isSafeInteger(available)&&available>0;
 });
 const brand=q('[data-transfer-brand]'),priorBrand=brand.value||drafts.get(active.key)?.brand;
 brand.replaceChildren(new Option('Choose brand',''),...lots.map(lot=>new Option([lot.brand,lot.packingSize,lot.externalOwnerName,lot.sodaReference||lot.poNo,lot.poLineKey,lot.isTransferStock?lot.id.split('|')[1]:null].filter(Boolean).join(' · '),lot.id)));
 if(lots.some(lot=>lot.id===priorBrand))brand.value=priorBrand;else if(lots.length===1)brand.value=lots[0].id;
 q('[data-dispatch-fields]').disabled=false;
 brand.disabled=!lots.length;
 renderDestinations(q('[data-transfer-destination]').value||drafts.get(active.key)?.destination||'');
 selectStockLot(saved);
}
function renderDestinations(selected){
 const destination=q('[data-transfer-destination]');
 destination.replaceChildren(new Option('Choose destination',''),...(data.destinations||[]).map(row=>new Option(row.name,row.id)));
 if(data.canAddDestination)destination.add(new Option('+ Add destination',ADD_DESTINATION));
 if([...destination.options].some(option=>option.value===selected))destination.value=selected;
}
async function addDestination(){
 if(busy||!data?.canAddDestination)return;
 const node=root,scope=active,editor=q('[data-new-destination]');
 const name=q('[data-destination-name]').value.trim(),type=q('[data-destination-type]').value;
 if(!name){q('[data-destination-error]').textContent='Enter a physical location name.';return;}
 busy=true;
 sequence++;
 q('[data-transfer-refresh]').disabled=false;
 const previousInert=node.inert;
 node.inert=true;
 q('[data-destination-error]').textContent='';
 try{
  const response=await fetch('/api/bag_transfers.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify({
   action:'add_destination',csrf:window.TT_MODULE_ACCESS?.csrf,entity:scope.entity,sourceLocationId:scope.locationId,name,type
  })});
  const saved=await response.json(),place=saved.destination;
  if(!response.ok||!saved.ok||!place?.id||!place?.name)throw Error(saved.error||'Destination could not be saved. Try the same location again.');
  if(root!==node||active!==scope)return;
  data.destinations=[...(data.destinations||[]).filter(row=>row.id!==place.id),place];
  renderDestinations(place.id);
  editor.reset();
  editor.hidden=true;
  message('Destination selected. Confirm Transfer when ready.');
 }catch(error){
  if(root===node&&active===scope)q('[data-destination-error]').textContent=error.message;
 }finally{
  busy=false;
  node.inert=previousInert;
  if(root===node&&active===scope){
   updateDispatch();
   (editor.hidden?q('[data-transfer-destination]'):q('[data-destination-name]')).focus({preventScroll:true});
  }
 }
}
function remaining(record,bucket='inTransit'){return Object.values(record.quantities||{}).reduce((n,line)=>n+Number(line[bucket]||0),0);}
function history(){
 const evidence=record=>'<details><summary>Movement evidence</summary>'+['receipts','returns','quarantines','quarantineAcceptances','quarantineReturns'].flatMap(kind=>(record[kind]||[]).map(event=>'<p>'+esc(dateText(event.date))+' · '+esc(kind)+' · '+esc(event.by)+' · '+esc(event.lines.reduce((n,l)=>n+Number(l.qty),0))+' bags<br>'+esc(event.reason)+'<br>'+esc(event.id)+'</p>')).join('')+'</details>';
 const usage=record=>(record.shipmentBagUsage||[]).map(row=>'<p>'+esc(row.lotRef)+' · '+esc(row.packedQty)+' packed · '+esc(row.emptyQty)+' shipped empty</p>').join('')+(record.shipmentCandidates||[]).map((row,index)=>'<button type="button" class="sec" data-shipment-transfer="'+esc(record.id)+'" data-shipment-choice="'+index+'">Record bags used · '+esc(row.lotRef)+'</button>').join('');
 const buttons=record=>{const transit=remaining(record),held=remaining(record,'quarantined'),destination=record.canReceiveAtDestination??(data.canCreate&&record.destination.id===active.locationId),source=data.canCreate&&record.source.locationId===active.locationId;
 return (record.canCompleteExMillDispatch?'<button type="button" class="sec" data-complete-exmill="'+esc(record.id)+'">Complete Ex-Mill transfer</button>':'')+(destination&&transit&&!record.canCompleteExMillDispatch?'<button type="button" class="sec" data-receive-transfer="'+esc(record.id)+'">Receive accepted bags</button><button type="button" class="sec" data-hold-transfer="'+esc(record.id)+'">Reject / quarantine delivery</button>':'')+(source&&transit?'<button type="button" class="sec" data-return-transfer="'+esc(record.id)+'">Receive physical return</button>':'')+(destination&&held?'<button type="button" class="sec" data-release-transfer="'+esc(record.id)+'">Accept inspected quarantine</button>':'')+(source&&held?'<button type="button" class="sec" data-held-return="'+esc(record.id)+'">Receive quarantine return</button>':'');};
 const rows=(data.transfers||[]).filter(record=>record.lines.some(line=>isFellow(line.sourceSnapshot)===(active.ownership==='other'))).sort((a,b)=>String(b.date).localeCompare(String(a.date)));
 q('[data-transfer-history]').innerHTML=rows.length?'<table><thead><tr><th>Date</th><th>Brand</th><th>Bags</th><th>From</th><th>To</th><th>Status</th></tr></thead><tbody>'+rows.map(record=>{
  const names=[...new Set(record.lines.map(line=>line.sourceSnapshot.brand))];
  const quantity=remaining(record,'dispatched');
  return '<tr><td>'+esc(dateText(record.date))+'</td><td>'+names.map(esc).join(', ')+'</td><td>'+esc(quantity.toLocaleString())+'</td><td>'+esc(record.source.millName)+'</td><td>'+esc(record.destination.name)+'</td><td><details><summary>'+esc(record.status||'View transfer')+'</summary><p>'+esc(record.id)+'</p>'+buttons(record)+usage(record)+evidence(record)+'</details></td></tr>';
 }).join('')+'</tbody></table>':'<p>No transfers recorded here.</p>';

 for(const [attribute,action] of Object.entries({'data-receive-transfer':'receive','data-return-transfer':'return_to_source','data-hold-transfer':'quarantine','data-release-transfer':'accept_quarantine','data-held-return':'return_quarantine'}))root.querySelectorAll('['+attribute+']').forEach(button=>button.onclick=()=>ackForm(button.getAttribute(attribute),action));
 root.querySelectorAll('[data-complete-exmill]').forEach(button=>button.onclick=()=>void send({action:'complete_exmill_dispatch',entity:active.entity,transferId:button.dataset.completeExmill}));
 root.querySelectorAll('[data-shipment-choice]').forEach(button=>button.onclick=()=>shipmentForm(button.dataset.shipmentTransfer,Number(button.dataset.shipmentChoice)));
}
function shipmentForm(id,index){
 const row=data.transfers.find(record=>record.id===id)?.shipmentCandidates?.[index];
 if(!row)return;
 const form=q('[data-transfer-ack]');
 form.innerHTML='<h4>'+esc(row.lotRef)+' · '+esc(row.poNo)+' · '+esc(row.brand)+'</h4><p>'+esc(row.packedQty)+' bags used for packing'+(row.plannedEmptyQty==null?'':' · '+esc(row.plannedEmptyQty)+' empty bags planned')+'</p><label>Actual empty bags shipped<input type="number" min="0" step="1" data-empty-shipped required></label><button type="button" class="ok" data-save-usage>Confirm shipment bag usage</button><button type="button" class="sec" data-close-usage>Cancel</button>';
 form.querySelector('[data-close-usage]').onclick=()=>form.replaceChildren();
 form.querySelector('[data-save-usage]').onclick=()=>{
  const raw=form.querySelector('[data-empty-shipped]').value,emptyQty=Number(raw);
  if(raw===''||!Number.isSafeInteger(emptyQty)||emptyQty<0){message('Enter the actual whole number of empty bags shipped.');return;}
  void send({action:'shipment_usage',entity:active.entity,transferId:id,shipmentId:row.shipmentId,lineId:row.lineId,fingerprint:row.fingerprint,emptyQty});
 };
 window.TT_FORM_VIEWPORT?.open(form);
}
function ackForm(id,action,saved=null){
 if(typeof action==='boolean')action=action?'return_to_source':'receive';const record=(data.transfers||[]).find(record=>record.id===id);if(!record)return;
 const bucket=['accept_quarantine','return_quarantine'].includes(action)?'quarantined':'inTransit',accepted=['receive','accept_quarantine'].includes(action),titles={receive:'Accepted bags physically received at destination',return_to_source:'Bags physically received back at source',quarantine:'Rejected bags physically held at destination',accept_quarantine:'Inspected quarantine now accepted at destination',return_quarantine:'Quarantined bags physically received back at source'};
 const form=q('[data-transfer-ack]');form.innerHTML='<h4>'+titles[action]+'</h4><input type="hidden" data-ack-id value="'+esc(id)+'"><input type="hidden" data-ack-action value="'+action+'"><label>Actual movement date<input type="date" data-ack-date value="'+today()+'"></label><table><thead><tr><th>Original PO / brand / packing</th><th>Remaining physical balance</th><th>Actual bags</th></tr></thead><tbody>'+record.lines.filter(line=>record.quantities[line.lineId][bucket]>0).map(line=>'<tr><td>'+esc(line.sourceSnapshot.poNo||line.originBagId)+' · '+esc(line.sourceSnapshot.brand)+' · '+esc(line.sourceSnapshot.packingSize)+'</td><td>'+esc(record.quantities[line.lineId][bucket])+'</td><td><input inputmode="numeric" data-ack-line="'+esc(line.lineId)+'" aria-label="Actual received bags for '+esc(line.sourceSnapshot.poNo||line.lineId)+'"></td></tr>').join('')+'</tbody></table><label>'+(action==='receive'?'Receipt notes (optional)':'Inspection / rejection / physical return reason')+'<input data-ack-reason maxlength="500"></label>'+(accepted?'<label><input type="checkbox" data-accepted-inspection> These quantities were physically received, inspected and accepted.</label>':'')+'<button type="button" class="ok" data-save-ack>Confirm actual movement</button><button type="button" class="sec" data-close-ack>Close receipt entry</button>';
 if(saved){q('[data-ack-date]').value=saved.date;q('[data-ack-reason]').value=saved.reason;root.querySelectorAll('[data-ack-line]').forEach(input=>input.value=saved.quantities[input.dataset.ackLine]??'');if(q('[data-accepted-inspection]'))q('[data-accepted-inspection]').checked=saved.accepted;}
 form.querySelector('[data-close-ack]').onclick=()=>form.replaceChildren();form.querySelector('[data-save-ack]').onclick=()=>void acknowledge();window.TT_FORM_VIEWPORT?.open(form);
}
async function readStock(scope,date){
 const response=await fetch('/api/bag_transfers.php?'+new URLSearchParams({entity:scope.entity,locationId:scope.locationId,asOf:date}),{credentials:'same-origin',headers:{Accept:'application/json'}});
 const next=await response.json();
 if(!response.ok||!next.ok)throw Error(next.error||'Bag stock could not be reviewed.');
 if(next.entity!==scope.entity||next.locationId!==scope.locationId||next.inventory?.asOfDate!==date)throw Error('The stock review belongs to another company, location or date.');
 return next;
}
async function refresh(){
 if(!active||busy)return;const scope=active,node=root,date=q('[data-transfer-date]').value,source=quantities('[data-source-lot]'),request=++sequence;q('[data-transfer-refresh]').disabled=true;message('Checking committed physical stock…');
 try{
  const next=await readStock(scope,date);
  if(request!==sequence||root!==node)return;if(q('[data-transfer-date]').value!==date){message('Date changed. Review stock again.');return;}
  if(date===today())currentReceipts.set(scope.stockKey,next.inventory.lots);
  const previous=data;
  data=next;
  const counters=result=>Object.values(result?.inventory?.lots||{}).filter(lot=>!lot.isTransferStock&&lot.supplierReceivedQty!=null).map(lot=>[lot.originBagId,lot.supplierReceivedQty]);
  if(JSON.stringify(counters(previous))!==JSON.stringify(counters(next))&&typeof currentBagOrder!=='undefined'&&currentBagOrder==null&&typeof renderBagOrders==='function')renderBagOrders();
  const latest=quantities('[data-source-lot]');renderStock(Object.keys(latest).length?latest:(Object.keys(source).length?source:(drafts.get(scope.key)?.source||{})));history();message('');pending();return true;
 }catch(error){if(request===sequence&&root===node)message(error.message+' Your entry is retained.');}finally{if(request===sequence&&root===node)q('[data-transfer-refresh]').disabled=false;}
}
async function open(entity,expanded=true){
 remember();const owner=entity||(typeof stockEntityForView==='function'?stockEntityForView():'TTI');let scope;try{scope=place(owner);scope.stockKey=scope.key;scope.ownership=ownership();scope.key+='|'+scope.ownership;}catch(error){if(q('[data-transfer-message]')){message(error.message+' Previous entry retained; select a valid physical mill before continuing.');data=null;}else{if(!root){root=document.createElement('section');root.className='card';(document.getElementById('newbags')||document.getElementById('ourExportBags'))?.append(root);}root.textContent=error.message;}return;}
 if(!active||active.key!==scope.key||!q('[data-transfer-date]')){sequence++;active=scope;data=null;build(scope);}root.hidden=false;pending();if(expanded)window.TT_FORM_VIEWPORT?.open(root);const refreshed=await refresh();const saved=drafts.get(scope.key)?.ack;if(active?.key===scope.key&&data&&saved&&!q('[data-ack-id]'))ackForm(saved.id,saved.action||(saved.returned?'return_to_source':'receive'),saved);return refreshed;
}
function whole(value){const n=Number(value);if(!String(value).trim()||!Number.isSafeInteger(n)||n<=0)throw Error('Enter positive whole bag quantities.');return n;}
async function dispatch(){
 try{if(!data?.canCreate)throw Error('New Export Bags Create permission is required.');const date=q('[data-transfer-date]').value;if(data.inventory.asOfDate!==date)throw Error('Review source stock for the dispatch date first.');
  const destination=q('[data-transfer-destination]').value;if(!destination||destination===ADD_DESTINATION||!q('[data-new-destination]').hidden)throw Error('Choose the actual transfer destination.');
  const allocations=[];for(const input of root.querySelectorAll('[data-source-lot]')){if(!input.value.trim()||Number(input.value)===0)continue;const qty=whole(input.value),lot=data.inventory.lots[input.dataset.sourceLot];if(!lot||lot.hold||!Number.isSafeInteger(lot.committableQty??lot.availableQty)||qty>(lot.committableQty??lot.availableQty))throw Error('A source lot is held or has insufficient bags. Review stock again.');allocations.push({lotId:lot.id,qty});}
  if(!allocations.length)throw Error('Enter bags against the actual original source lots.');await send({action:'dispatch',entity:active.entity,sourceLocationId:active.locationId,destinationId:destination,date,sourceFingerprint:data.inventory.fingerprint,ownership:active.ownership,allocations});
 }catch(error){message(error.message);}
}
async function acknowledge(){
 try{const action=q('[data-ack-action]').value,id=q('[data-ack-id]').value,record=data.transfers.find(record=>record.id===id);if(!record)throw Error('Review the original transfer before receiving.');
  const destinationAction=['receive','accept_quarantine','quarantine'].includes(action);
  if(destinationAction?!(record.canReceiveAtDestination??(data.canCreate&&record.destination.id===active.locationId)):!data.canCreate)throw Error('New Export Bags Create permission is required at the physical movement location.');
  if(['receive','accept_quarantine'].includes(action)&&!q('[data-accepted-inspection]').checked)throw Error('Confirm actual inspection and acceptance before adding available destination bags.');
  const allocations=[];for(const input of root.querySelectorAll('[data-ack-line]')){if(!input.value.trim()||Number(input.value)===0)continue;const qty=whole(input.value);if(qty>record.quantities[input.dataset.ackLine][['accept_quarantine','return_quarantine'].includes(action)?'quarantined':'inTransit'])throw Error('Actual receipt exceeds the remaining unacknowledged bags.');allocations.push({lineId:input.dataset.ackLine,qty});}
  if(!allocations.length)throw Error('Enter actual received quantities.');const reason=q('[data-ack-reason]').value.trim();if(action!=='receive'&&reason.length<3)throw Error('Enter the actual reason for this physical return.');
  await send({action,entity:active.entity,transferId:id,date:q('[data-ack-date]').value,reason,allocations});
 }catch(error){message(error.message);}
}
async function send(body,recovery=false){
 if(busy)return;const scope={...active},node=root,storageKey=pendingKey(scope);let pendingIntent,savedId;try{pendingIntent=JSON.parse(localStorage.getItem(storageKey)||'null');}catch{message('The previous movement retry record needs review.');return;}
 const ordered=value=>Array.isArray(value)?value.map(ordered):value&&typeof value==='object'?Object.fromEntries(Object.keys(value).filter(key=>key!=='requestKey').sort().map(key=>[key,ordered(value[key])])):value;const compare=value=>JSON.stringify(ordered(value));
 if(pendingIntent?.editable&&!recovery){pendingIntent={body:{...body,requestKey:pendingIntent.body.requestKey}};}if(pendingIntent&&!recovery&&compare(pendingIntent.body)!==compare(body)){pending();message('Confirm the previous movement using its recovery control. Your current entry is retained.');return;}
 const intent=pendingIntent||{body:{...body,requestKey:crypto.randomUUID()}};try{localStorage.setItem(storageKey,JSON.stringify(intent));}catch{message('The movement retry identity could not be retained. No request was sent.');return;}
 busy=true;const focus=document.activeElement,wasInert=node.inert;node.inert=true;let acknowledged=false;
 try{
  const response=await fetch('/api/bag_transfers.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify({...intent.body,csrf:window.TT_MODULE_ACCESS?.csrf})}),saved=await response.json();
  if(!response.ok||!saved.ok){if([403,422].includes(response.status)){intent.editable=true;localStorage.setItem(storageKey,JSON.stringify(intent));}throw Error(saved.error||'The bag movement was not confirmed.');}const record=saved.movement?.record;
  if(!record?.id||record.entity!==intent.body.entity||(intent.body.transferId&&record.id!==intent.body.transferId)||(intent.body.action==='dispatch'&&(record.source?.locationId!==intent.body.sourceLocationId||record.destination?.id!==intent.body.destinationId)))throw Error('The acknowledgement did not identify this movement. Confirm the same request.');
  if(intent.body.action==='shipment_usage'&&!(record.shipmentBagUsage||[]).some(row=>row.shipmentId===intent.body.shipmentId&&row.lineId===intent.body.lineId&&row.emptyQty===intent.body.emptyQty))throw Error('The confirmed shipment usage could not be matched. Retry the same request.');
  acknowledged=true;savedId=record.id;localStorage.removeItem(storageKey);if(root!==node||active.key!==scope.key)return;pending();
  if(!recovery){if(intent.body.action==='dispatch'){root.querySelectorAll('[data-source-lot]').forEach(input=>input.value='');q('[data-transfer-destination]').value='';drafts.delete(scope.key);updateDispatch();}else q('[data-transfer-ack]').replaceChildren();}
  message('Movement '+record.id+' saved.'+(recovery?' Your current entry is retained.':''));
 }catch(error){if(root===node){message(error.message+' Your entry is retained; confirm the same movement before recording another.');pending();}}
 finally{busy=false;node.inert=wasInert;if(!acknowledged&&focus?.isConnected)focus.focus({preventScroll:true});}
 if(acknowledged&&root===node&&active.key===scope.key){await refresh();message((intent.body.action==='dispatch'?'Transfer ':'Movement ')+savedId+(intent.body.action==='dispatch'?' completed. ':' saved. ')+(q('[data-transfer-message]').textContent.includes('retained')?'Stock refresh could not be confirmed; your current entry is retained.':'Confirmed stock refreshed.'));}
}
async function loadCurrentReceipts(){
 const scope=place(typeof stockEntityForView==='function'?stockEntityForView():'TTI');
 const next=await readStock(scope,today());
 currentReceipts.set(scope.key,next.inventory.lots);
 if(typeof currentBagOrder!=='undefined'&&currentBagOrder==null&&typeof renderBagOrders==='function'&&norm(currentMill?.name)===norm(scope.millName)&&stockEntityForView()===scope.entity)renderBagOrders();
 return true;
}
function receiptQuantity(order){
 try{
  const scope=place(String(order.entity||'TTI').toUpperCase());
  const lot=currentReceipts.get(scope.key)?.['PO|'+order.id+'|'+scope.locationId];
  return lot?.poNo===order.poNo&&lot?.poLineKey===order.poLineKey?lot.supplierReceivedQty:null;
 }catch{return null;}
}
window.TT_BAG_TRANSFERS={open,refresh,whole,receiptQuantity,loadCurrentReceipts,showStockError:error=>message(error.message),
 ownershipChanged:()=>showAction(transferView,false),isTransferView:()=>transferView};
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',mountActions);
else mountActions();
})();
