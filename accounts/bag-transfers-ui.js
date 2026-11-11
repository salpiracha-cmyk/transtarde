(() => {
'use strict';
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])),norm=v=>String(v??'').trim().replace(/\s+/g,' ').toLowerCase();
const dateText=value=>String(value??'').replace(/^(\d{4})-(\d{2})-(\d{2})$/,'$3-$2-$1');
const today=()=>new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Karachi',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
let root=null,active=null,data=null,sequence=0,busy=false;const drafts=new Map();
function place(entity){
 const mill=typeof currentMill==='undefined'?null:currentMill;if(!mill)throw Error('Select the physical mill first.');
 const matches=(window.TT_MODULE_ACCESS?.masters?.mills||[]).filter(row=>norm(row.values?.[0])===norm(mill.name));if(matches.length!==1)throw Error('Resolve this physical mill in Mills & Locations.');
 return {entity,locationId:String(matches[0].id),millName:matches[0].values[0],key:entity+'|'+String(matches[0].id)};
}
const pendingKey=scope=>'tt_ui_bag_pending_'+window.TT_MODULE_ACCESS?.userId+'_'+scope.entity+'_'+scope.locationId;
const q=selector=>root?.querySelector(selector),message=text=>{if(q('[data-transfer-message]'))q('[data-transfer-message]').textContent=text;};
function quantities(selector){return Object.fromEntries([...(root?.querySelectorAll(selector)||[])].map(input=>[input.dataset.sourceLot||input.dataset.ackLine,input.value]));}
function remember(){if(!active||!root||!q('[data-transfer-date]'))return;drafts.set(active.key,{date:q('[data-transfer-date]').value,destination:q('[data-transfer-destination]').value,source:quantities('[data-source-lot]'),ack:q('[data-ack-id]')?{id:q('[data-ack-id]').value,action:q('[data-ack-action]').value,date:q('[data-ack-date]').value,reason:q('[data-ack-reason]').value,accepted:!!q('[data-accepted-inspection]')?.checked,quantities:quantities('[data-ack-line]')}:null});}
function build(scope){
 root?.remove();root=document.createElement('details');root.id='acknowledgedBagTransfers';root.className='card';
 root.innerHTML='<summary><strong>Physical bag stock and transfers</strong></summary><div class="r3"><label>Stock owner<select data-transfer-entity><option>TTI</option><option>BRM</option></select></label><label>Physical location<input readonly data-transfer-location></label><label>Stock / dispatch date<input type="date" data-transfer-date></label></div><button type="button" class="sec" data-transfer-refresh>Review / Refresh stock</button><p role="status" data-transfer-message></p><div data-transfer-pending></div><div data-transfer-stock></div><fieldset data-dispatch-fields hidden disabled><legend>Dispatch actual source bags</legend><label>Destination mill<select data-transfer-destination><option value="">Choose destination</option></select></label><button type="button" class="ok" data-dispatch-bags>Confirm dispatch</button></fieldset><details data-transfer-history-panel><summary>Transfer history</summary><div data-transfer-history></div></details><div data-transfer-ack></div>';
 (document.getElementById('ourExportBags')||document.getElementById('newbags'))?.append(root);q('[data-transfer-entity]').value=scope.entity;q('[data-transfer-location]').value=scope.millName;q('[data-transfer-date]').value=drafts.get(scope.key)?.date||today();
 q('[data-transfer-refresh]').onclick=()=>void refresh();q('[data-transfer-entity]').onchange=()=>void open(q('[data-transfer-entity]').value);q('[data-transfer-date]').onchange=()=>message('Date changed. Review stock before confirming dispatch.');
 q('[data-dispatch-bags]').onclick=()=>void dispatch();return root;
}
function pending(){
 const node=q('[data-transfer-pending]');if(!node||!active)return;node.replaceChildren();let intent;
 try{intent=JSON.parse(localStorage.getItem(pendingKey(active))||'null');}catch{message('The previous movement retry record needs review. Your entry is retained.');return;}
 if(!intent)return;node.innerHTML='<h4>Previous movement awaiting confirmation</h4><p>'+esc(intent.body.action)+' · '+esc(dateText(intent.body.date))+'. '+(intent.editable?'The server refused this entry. Correct its quantities and submit again, or discard the refused request.':'Confirm this movement before recording another.')+'</p><button type="button" class="sec" data-confirm-pending>Confirm previous movement</button>';
 node.querySelector('button').onclick=()=>void send(intent.body,true);if(intent.editable){const discard=document.createElement('button');discard.type='button';discard.className='sec';discard.textContent='Discard refused request';discard.onclick=()=>{localStorage.removeItem(pendingKey(active));pending();message('Refused request discarded. Your current entry is retained.');};node.append(discard);}
}
function renderStock(saved={}){
 const inventory=data.inventory,lots=Object.values(inventory.lots||{}).filter(lot=>{
  const available=lot.committableQty??lot.availableQty;
  return lot.sourceKind!=='OTHER_EXPORTER'&&!lot.hold&&Number.isSafeInteger(available)&&available>0;
 });
 q('[data-transfer-stock]').innerHTML=lots.length?'<h4>Source lots at '+esc(dateText(inventory.asOfDate))+'</h4><p>'+esc((inventory.warnings||[]).join(' '))+'</p><table><thead><tr><th>Original PO / line</th><th>Brand / packing / material</th><th>Accepted balance</th><th>Quarantined</th><th>Available for dispatch</th><th>Dispatch bags</th></tr></thead><tbody>'+lots.map(lot=>'<tr><td>'+esc(lot.poNo||'Unverified PO')+' · '+esc(lot.poLineKey||'')+(lot.isTransferStock?'<br>Received transfer':'')+'</td><td>'+esc(lot.brand)+' · '+esc(lot.packingSize)+' · '+esc(lot.bagType)+'</td><td>'+esc(lot.balanceBeforeUnallocated)+'</td><td>'+esc(lot.quarantinedQty??0)+'</td><td>'+esc(lot.hold||(lot.committableQty??lot.availableQty??'Unavailable'))+(lot.warnings?.length?'<br>'+lot.warnings.map(esc).join('<br>'):'')+'</td><td><input inputmode="numeric" aria-label="Dispatch bags from '+esc(lot.poNo||lot.id)+'" data-source-lot="'+esc(lot.id)+'" value="'+esc(saved[lot.id]??'')+'" '+(lot.hold||!data.canCreate?'disabled':'')+'></td></tr>').join('')+'</tbody></table>': '<p>No bags available for transfer.</p>';
 const dispatchFields=q('[data-dispatch-fields]');dispatchFields.hidden=!lots.length;dispatchFields.disabled=!data.canCreate||!lots.length;const destination=q('[data-transfer-destination]'),prior=destination.value||drafts.get(active.key)?.destination||'';destination.innerHTML='<option value="">Choose destination</option>'+(data.destinations||[]).map(row=>'<option value="'+esc(row.id)+'">'+esc(row.name)+'</option>').join('');destination.value=prior;
}
function remaining(record,bucket='inTransit'){return Object.values(record.quantities||{}).reduce((n,line)=>n+Number(line[bucket]||0),0);}
function history(){
 const evidence=record=>'<details><summary>Movement evidence</summary>'+['receipts','returns','quarantines','quarantineAcceptances','quarantineReturns'].flatMap(kind=>(record[kind]||[]).map(event=>'<p>'+esc(dateText(event.date))+' · '+esc(kind)+' · '+esc(event.by)+' · '+esc(event.lines.reduce((n,l)=>n+Number(l.qty),0))+' bags<br>'+esc(event.reason)+'<br>'+esc(event.id)+'</p>')).join('')+'</details>';
 const usage=record=>(record.shipmentBagUsage||[]).map(row=>'<p>'+esc(row.lotRef)+' · '+esc(row.packedQty)+' packed · '+esc(row.emptyQty)+' shipped empty</p>').join('')+(record.shipmentCandidates||[]).map((row,index)=>'<button type="button" class="sec" data-shipment-transfer="'+esc(record.id)+'" data-shipment-choice="'+index+'">Record bags used · '+esc(row.lotRef)+'</button>').join('');
 const buttons=record=>{const transit=remaining(record),held=remaining(record,'quarantined'),destination=record.canReceiveAtDestination??(data.canCreate&&record.destination.id===active.locationId),source=data.canCreate&&record.source.locationId===active.locationId;
 return (record.canCompleteExMillDispatch?'<button type="button" class="sec" data-complete-exmill="'+esc(record.id)+'">Complete Ex-Mill transfer</button>':'')+(destination&&transit&&!record.canCompleteExMillDispatch?'<button type="button" class="sec" data-receive-transfer="'+esc(record.id)+'">Receive accepted bags</button><button type="button" class="sec" data-hold-transfer="'+esc(record.id)+'">Reject / quarantine delivery</button>':'')+(source&&transit?'<button type="button" class="sec" data-return-transfer="'+esc(record.id)+'">Receive physical return</button>':'')+(destination&&held?'<button type="button" class="sec" data-release-transfer="'+esc(record.id)+'">Accept inspected quarantine</button>':'')+(source&&held?'<button type="button" class="sec" data-held-return="'+esc(record.id)+'">Receive quarantine return</button>':'');};
 q('[data-transfer-history]').innerHTML='<h4>Dispatch, receiving and quarantine</h4><p>Dispatches from our mill to an Ex-Mill post the destination receipt automatically. Other destinations require receipt confirmation.</p><table><thead><tr><th>Dispatch date / reference</th><th>Source → destination</th><th>Original source lines</th><th>Dispatched / accepted / returned / in transit / quarantined</th><th></th></tr></thead><tbody>'+(data.transfers||[]).map(record=>'<tr><td>'+esc(dateText(record.date))+'<br>'+esc(record.id)+'</td><td>'+esc(record.source.millName)+' → '+esc(record.destination.name)+'</td><td>'+record.lines.map(line=>esc(line.sourceSnapshot.poNo||line.originBagId)+' · '+esc(line.sourceSnapshot.brand)+' · '+esc(line.sourceSnapshot.packingSize)).join('<br>')+'</td><td>'+Object.values(record.quantities||{}).map(line=>[line.dispatched,line.received,line.returned,line.inTransit,line.quarantined||0].map(esc).join(' / ')).join('<br>')+'</td><td>'+buttons(record)+usage(record)+evidence(record)+'</td></tr>').join('')+'</tbody></table>';
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
 form.querySelector('[data-close-ack]').onclick=()=>form.replaceChildren();form.querySelector('[data-save-ack]').onclick=()=>void acknowledge();if(root.open)window.TT_FORM_VIEWPORT?.open(form);
}
async function refresh(){
 if(!active||busy)return;const scope=active,node=root,date=q('[data-transfer-date]').value,source=quantities('[data-source-lot]'),request=++sequence;q('[data-transfer-refresh]').disabled=true;message('Checking committed physical stock…');
 try{const response=await fetch('/api/bag_transfers.php?'+new URLSearchParams({entity:scope.entity,locationId:scope.locationId,asOf:date}),{credentials:'same-origin',headers:{Accept:'application/json'}}),next=await response.json();
  if(request!==sequence||root!==node)return;if(q('[data-transfer-date]').value!==date){message('Date changed. Review stock again.');return;}if(!response.ok||!next.ok)throw Error(next.error||'Bag stock could not be reviewed.');
  if(next.entity!==scope.entity||next.locationId!==scope.locationId||next.inventory?.asOfDate!==date)throw Error('The stock review belongs to another company, location or date.');
  const previous=data;
  data=next;
  const counters=result=>Object.values(result?.inventory?.lots||{}).filter(lot=>!lot.isTransferStock&&lot.supplierReceivedQty!=null).map(lot=>[lot.originBagId,lot.supplierReceivedQty]);
  if(JSON.stringify(counters(previous))!==JSON.stringify(counters(next))&&typeof currentBagOrder!=='undefined'&&currentBagOrder==null&&typeof renderBagOrders==='function')renderBagOrders();
  const latest=quantities('[data-source-lot]');renderStock(Object.keys(latest).length?latest:(Object.keys(source).length?source:(drafts.get(scope.key)?.source||{})));history();message('');pending();
 }catch(error){if(request===sequence&&root===node)message(error.message+' Your entry is retained.');}finally{if(request===sequence&&root===node)q('[data-transfer-refresh]').disabled=false;}
}
async function open(entity,expanded=true){
 remember();const owner=entity||(typeof stockEntityForView==='function'?stockEntityForView():'TTI');let scope;try{scope=place(owner);}catch(error){if(q('[data-transfer-message]')){message(error.message+' Previous entry retained; select a valid physical mill before continuing.');data=null;}else{if(!root){root=document.createElement('section');root.className='card';(document.getElementById('ourExportBags')||document.getElementById('newbags'))?.append(root);}root.textContent=error.message;}return;}
 if(!active||active.key!==scope.key||!q('[data-transfer-date]')){sequence++;active=scope;data=null;build(scope);}root.open=expanded;pending();if(expanded)window.TT_FORM_VIEWPORT?.open(root);await refresh();const saved=drafts.get(scope.key)?.ack;if(active?.key===scope.key&&data&&saved&&!q('[data-ack-id]'))ackForm(saved.id,saved.action||(saved.returned?'return_to_source':'receive'),saved);
}
function whole(value){const n=Number(value);if(!String(value).trim()||!Number.isSafeInteger(n)||n<=0)throw Error('Enter positive whole bag quantities.');return n;}
async function dispatch(){
 try{if(!data?.canCreate)throw Error('New Export Bags Create permission is required.');const date=q('[data-transfer-date]').value;if(data.inventory.asOfDate!==date)throw Error('Review source stock for the dispatch date first.');
  const destination=q('[data-transfer-destination]').value;if(!destination)throw Error('Choose the actual destination mill.');
  const allocations=[];for(const input of root.querySelectorAll('[data-source-lot]')){if(!input.value.trim()||Number(input.value)===0)continue;const qty=whole(input.value),lot=data.inventory.lots[input.dataset.sourceLot];if(!lot||lot.hold||!Number.isSafeInteger(lot.committableQty??lot.availableQty)||qty>(lot.committableQty??lot.availableQty))throw Error('A source lot is held or has insufficient bags. Review stock again.');allocations.push({lotId:lot.id,qty});}
  if(!allocations.length)throw Error('Enter bags against the actual original source lots.');await send({action:'dispatch',entity:active.entity,sourceLocationId:active.locationId,destinationId:destination,date,sourceFingerprint:data.inventory.fingerprint,allocations});
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
 if(busy)return;const scope={...active},node=root,storageKey=pendingKey(scope);let pendingIntent;try{pendingIntent=JSON.parse(localStorage.getItem(storageKey)||'null');}catch{message('The previous movement retry record needs review.');return;}
 const ordered=value=>Array.isArray(value)?value.map(ordered):value&&typeof value==='object'?Object.fromEntries(Object.keys(value).filter(key=>key!=='requestKey').sort().map(key=>[key,ordered(value[key])])):value;const compare=value=>JSON.stringify(ordered(value));
 if(pendingIntent?.editable&&!recovery){pendingIntent={body:{...body,requestKey:pendingIntent.body.requestKey}};}if(pendingIntent&&!recovery&&compare(pendingIntent.body)!==compare(body)){pending();message('Confirm the previous movement using its recovery control. Your current entry is retained.');return;}
 const intent=pendingIntent||{body:{...body,requestKey:crypto.randomUUID()}};try{localStorage.setItem(storageKey,JSON.stringify(intent));}catch{message('The movement retry identity could not be retained. No request was sent.');return;}
 busy=true;const focus=document.activeElement,wasInert=node.inert;node.inert=true;let acknowledged=false;
 try{
  const response=await fetch('/api/bag_transfers.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify({...intent.body,csrf:window.TT_MODULE_ACCESS?.csrf})}),saved=await response.json();
  if(!response.ok||!saved.ok){if([403,422].includes(response.status)){intent.editable=true;localStorage.setItem(storageKey,JSON.stringify(intent));}throw Error(saved.error||'The bag movement was not confirmed.');}const record=saved.movement?.record;
  if(!record?.id||record.entity!==intent.body.entity||(intent.body.transferId&&record.id!==intent.body.transferId)||(intent.body.action==='dispatch'&&(record.source?.locationId!==intent.body.sourceLocationId||record.destination?.id!==intent.body.destinationId)))throw Error('The acknowledgement did not identify this movement. Confirm the same request.');
  if(intent.body.action==='shipment_usage'&&!(record.shipmentBagUsage||[]).some(row=>row.shipmentId===intent.body.shipmentId&&row.lineId===intent.body.lineId&&row.emptyQty===intent.body.emptyQty))throw Error('The confirmed shipment usage could not be matched. Retry the same request.');
  acknowledged=true;localStorage.removeItem(storageKey);if(root!==node||active.key!==scope.key)return;pending();
  if(!recovery){if(intent.body.action==='dispatch'){root.querySelectorAll('[data-source-lot]').forEach(input=>input.value='');q('[data-transfer-destination]').value='';}else q('[data-transfer-ack]').replaceChildren();}
  message('Movement '+record.id+' saved.'+(recovery?' Your current entry is retained.':''));
 }catch(error){if(root===node){message(error.message+' Your entry is retained; confirm the same movement before recording another.');pending();}}
 finally{busy=false;node.inert=wasInert;if(!acknowledged&&focus?.isConnected)focus.focus({preventScroll:true});}
 if(acknowledged&&root===node&&active.key===scope.key){await refresh();message('Movement '+(intent.body.transferId||'dispatch')+' saved. '+(q('[data-transfer-message]').textContent.includes('retained')?'Stock refresh could not be confirmed; your current entry is retained.':'Confirmed stock refreshed.'));}
}
function receiptQuantity(order){
 if(!data||!active)return null;
 const lot=data.inventory.lots?.['PO|'+order.id+'|'+active.locationId];
 return lot?.poNo===order.poNo&&lot?.poLineKey===order.poLineKey?lot.supplierReceivedQty:null;
}
window.TT_BAG_TRANSFERS={open,refresh,whole,receiptQuantity};})();
