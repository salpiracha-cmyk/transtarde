(() => {
'use strict';
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])),norm=v=>String(v??'').trim().replace(/\s+/g,' ').toLowerCase();
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
function remember(){if(!active||!root||!q('[data-transfer-date]'))return;drafts.set(active.key,{date:q('[data-transfer-date]').value,destination:q('[data-transfer-destination]').value,source:quantities('[data-source-lot]')});}
function build(scope){
 root?.remove();root=document.createElement('section');root.id='acknowledgedBagTransfers';root.className='card';
 root.innerHTML='<h3>Physical bag stock and transfers</h3><div class="r3"><label>Stock owner<select data-transfer-entity><option>TTI</option><option>BRM</option></select></label><label>Physical location<input readonly data-transfer-location></label><label>Stock / dispatch date<input type="date" data-transfer-date></label></div><button type="button" class="sec" data-transfer-refresh>Review / Refresh stock</button><p role="status" data-transfer-message></p><div data-transfer-pending></div><div data-transfer-stock></div><fieldset data-dispatch-fields><legend>Dispatch actual source bags</legend><label>Destination mill<select data-transfer-destination><option value="">Choose destination</option></select></label><button type="button" class="ok" data-dispatch-bags>Confirm dispatch</button></fieldset><div data-transfer-history></div><div data-transfer-ack></div>';
 document.querySelector('#newbags')?.append(root);q('[data-transfer-entity]').value=scope.entity;q('[data-transfer-location]').value=scope.millName;q('[data-transfer-date]').value=drafts.get(scope.key)?.date||today();
 q('[data-transfer-refresh]').onclick=()=>void refresh();q('[data-transfer-entity]').onchange=()=>void open(q('[data-transfer-entity]').value);q('[data-transfer-date]').onchange=()=>message('Date changed. Review stock before confirming dispatch.');
 q('[data-dispatch-bags]').onclick=()=>void dispatch();return root;
}
function pending(){
 const node=q('[data-transfer-pending]');if(!node||!active)return;node.replaceChildren();let intent;
 try{intent=JSON.parse(localStorage.getItem(pendingKey(active))||'null');}catch{message('The previous movement retry record needs review. Your entry is retained.');return;}
 if(!intent)return;node.innerHTML='<h4>Previous movement awaiting confirmation</h4><p>'+esc(intent.body.action)+' · '+esc(intent.body.date)+'. '+(intent.editable?'The server refused this entry. Correct its quantities and submit again, or discard the refused request.':'Confirm this movement before recording another.')+'</p><button type="button" class="sec" data-confirm-pending>Confirm previous movement</button>';
 node.querySelector('button').onclick=()=>void send(intent.body,true);if(intent.editable){const discard=document.createElement('button');discard.type='button';discard.className='sec';discard.textContent='Discard refused request';discard.onclick=()=>{localStorage.removeItem(pendingKey(active));pending();message('Refused request discarded. Your current entry is retained.');};node.append(discard);}
}
function renderStock(saved={}){
 const inventory=data.inventory,lots=Object.values(inventory.lots||{});q('[data-transfer-stock]').innerHTML='<h4>Source lots at '+esc(inventory.asOfDate)+'</h4><p>'+esc((inventory.warnings||[]).join(' '))+'</p><table><thead><tr><th>Original PO / line</th><th>Brand / packing / material</th><th>Recorded balance</th><th>Available for dispatch</th><th>Dispatch bags</th></tr></thead><tbody>'+lots.map(lot=>'<tr><td>'+esc(lot.poNo||'Unverified PO')+' · '+esc(lot.poLineKey||'')+(lot.isTransferStock?'<br>Received transfer':'')+'</td><td>'+esc(lot.brand)+' · '+esc(lot.packingSize)+' · '+esc(lot.bagType)+'</td><td>'+esc(lot.balanceBeforeUnallocated)+'</td><td>'+esc(lot.hold||(lot.committableQty??lot.availableQty??'Unavailable'))+(lot.warnings?.length?'<br>'+lot.warnings.map(esc).join('<br>'):'')+'</td><td><input inputmode="numeric" aria-label="Dispatch bags from '+esc(lot.poNo||lot.id)+'" data-source-lot="'+esc(lot.id)+'" value="'+esc(saved[lot.id]??'')+'" '+(lot.hold||!data.canCreate?'disabled':'')+'></td></tr>').join('')+'</tbody></table>'+(lots.length?'':'<p>No verified original bag lots exist for this company and location.</p>');
 q('[data-dispatch-fields]').disabled=!data.canCreate;const destination=q('[data-transfer-destination]'),prior=destination.value||drafts.get(active.key)?.destination||'';destination.innerHTML='<option value="">Choose destination</option>'+(data.destinations||[]).map(row=>'<option value="'+esc(row.id)+'">'+esc(row.name)+'</option>').join('');destination.value=prior;
}
function remaining(record){return Object.values(record.quantities||{}).reduce((n,line)=>n+Number(line.inTransit||0),0);}
function history(){
 q('[data-transfer-history]').innerHTML='<h4>Dispatch and accepted receiving</h4><p>A dispatch does not make destination bags available. Only the destination’s confirmed accepted quantities can be used.</p><table><thead><tr><th>Dispatch date / reference</th><th>Source → destination</th><th>Original source lines</th><th>Dispatched / accepted / returned / awaiting acknowledgement</th><th></th></tr></thead><tbody>'+(data.transfers||[]).map(record=>'<tr><td>'+esc(record.date)+'<br>'+esc(record.id)+'</td><td>'+esc(record.source.millName)+' → '+esc(record.destination.name)+'</td><td>'+record.lines.map(line=>esc(line.sourceSnapshot.poNo||line.originBagId)+' · '+esc(line.sourceSnapshot.brand)+' · '+esc(line.sourceSnapshot.packingSize)).join('<br>')+'</td><td>'+Object.values(record.quantities||{}).map(line=>[line.dispatched,line.received,line.returned,line.inTransit].map(esc).join(' / ')).join('<br>')+'</td><td>'+(data.canCreate&&remaining(record)>0?(record.destination.id===active.locationId?'<button type="button" class="sec" data-receive-transfer="'+esc(record.id)+'">Receive accepted bags</button>':'')+(record.source.locationId===active.locationId?'<button type="button" class="sec" data-return-transfer="'+esc(record.id)+'">Receive physical return</button>':''):'')+'</td></tr>').join('')+'</tbody></table>';
 root.querySelectorAll('[data-receive-transfer]').forEach(button=>button.onclick=()=>ackForm(button.dataset.receiveTransfer,false));root.querySelectorAll('[data-return-transfer]').forEach(button=>button.onclick=()=>ackForm(button.dataset.returnTransfer,true));
}
function ackForm(id,returned){
 const record=(data.transfers||[]).find(record=>record.id===id);if(!record)return;const form=q('[data-transfer-ack]');form.innerHTML='<h4>'+(returned?'Bags physically received back at source':'Accepted bags physically received at destination')+'</h4><input type="hidden" data-ack-id value="'+esc(id)+'"><input type="hidden" data-ack-action value="'+(returned?'return_to_source':'receive')+'"><label>Actual receipt date<input type="date" data-ack-date value="'+today()+'"></label><table><thead><tr><th>Original PO / brand / packing</th><th>Awaiting acknowledgement</th><th>Actually received</th></tr></thead><tbody>'+record.lines.filter(line=>record.quantities[line.lineId].inTransit>0).map(line=>'<tr><td>'+esc(line.sourceSnapshot.poNo||line.originBagId)+' · '+esc(line.sourceSnapshot.brand)+' · '+esc(line.sourceSnapshot.packingSize)+'</td><td>'+esc(record.quantities[line.lineId].inTransit)+'</td><td><input inputmode="numeric" data-ack-line="'+esc(line.lineId)+'" aria-label="Actual received bags for '+esc(line.sourceSnapshot.poNo||line.lineId)+'"></td></tr>').join('')+'</tbody></table><label>'+(returned?'Reason for physical return':'Receipt notes (optional)')+'<input data-ack-reason maxlength="500"></label>'+(!returned?'<label><input type="checkbox" data-accepted-inspection> These quantities were physically received, inspected and accepted.</label>':'')+'<button type="button" class="ok" data-save-ack>Confirm actual receipt</button><button type="button" class="sec" data-close-ack>Close receipt entry</button>';
 form.querySelector('[data-close-ack]').onclick=()=>form.replaceChildren();form.querySelector('[data-save-ack]').onclick=()=>void acknowledge();window.TT_FORM_VIEWPORT?.open(form);
}
async function refresh(){
 if(!active||busy)return;const scope=active,node=root,date=q('[data-transfer-date]').value,source=quantities('[data-source-lot]'),request=++sequence;q('[data-transfer-refresh]').disabled=true;message('Checking committed physical stock…');
 try{const response=await fetch('/api/bag_transfers.php?'+new URLSearchParams({entity:scope.entity,locationId:scope.locationId,asOf:date}),{credentials:'same-origin',headers:{Accept:'application/json'}}),next=await response.json();
  if(request!==sequence||root!==node)return;if(q('[data-transfer-date]').value!==date){message('Date changed. Review stock again.');return;}if(!response.ok||!next.ok)throw Error(next.error||'Bag stock could not be reviewed.');
  if(next.entity!==scope.entity||next.locationId!==scope.locationId||next.inventory?.asOfDate!==date)throw Error('The stock review belongs to another company, location or date.');
  data=next;const latest=quantities('[data-source-lot]');renderStock(Object.keys(latest).length?latest:(Object.keys(source).length?source:(drafts.get(scope.key)?.source||{})));history();message('Confirmed source lots shown. Enter actual quantities before dispatch or receiving.');pending();
 }catch(error){if(request===sequence&&root===node)message(error.message+' Your entry is retained.');}finally{if(request===sequence&&root===node)q('[data-transfer-refresh]').disabled=false;}
}
async function open(entity){
 remember();const owner=entity||(typeof stockEntityForView==='function'?stockEntityForView():'TTI');let scope;try{scope=place(owner);}catch(error){if(q('[data-transfer-message]')){message(error.message+' Previous entry retained; select a valid physical mill before continuing.');data=null;}else{if(!root){root=document.createElement('section');root.className='card';document.querySelector('#newbags')?.append(root);}root.textContent=error.message;}return;}
 if(!active||active.key!==scope.key||!q('[data-transfer-date]')){sequence++;active=scope;data=null;build(scope);}pending();await refresh();
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
 try{if(!data?.canCreate)throw Error('New Export Bags Create permission is required.');const action=q('[data-ack-action]').value,id=q('[data-ack-id]').value,record=data.transfers.find(record=>record.id===id);if(!record)throw Error('Review the original transfer before receiving.');
  if(action==='receive'&&!q('[data-accepted-inspection]').checked)throw Error('Confirm actual inspection and acceptance before adding available destination bags.');
  const allocations=[];for(const input of root.querySelectorAll('[data-ack-line]')){if(!input.value.trim()||Number(input.value)===0)continue;const qty=whole(input.value);if(qty>record.quantities[input.dataset.ackLine].inTransit)throw Error('Actual receipt exceeds the remaining unacknowledged bags.');allocations.push({lineId:input.dataset.ackLine,qty});}
  if(!allocations.length)throw Error('Enter actual received quantities.');const reason=q('[data-ack-reason]').value.trim();if(action==='return_to_source'&&reason.length<3)throw Error('Enter the actual reason for this physical return.');
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
  acknowledged=true;localStorage.removeItem(storageKey);if(root!==node||active.key!==scope.key)return;pending();
  if(!recovery){if(intent.body.action==='dispatch'){root.querySelectorAll('[data-source-lot]').forEach(input=>input.value='');q('[data-transfer-destination]').value='';}else q('[data-transfer-ack]').replaceChildren();}
  message('Movement '+record.id+' saved.'+(recovery?' Your current entry is retained.':''));
 }catch(error){if(root===node){message(error.message+' Your entry is retained; confirm the same movement before recording another.');pending();}}
 finally{busy=false;node.inert=wasInert;if(!acknowledged&&focus?.isConnected)focus.focus({preventScroll:true});}
 if(acknowledged&&root===node&&active.key===scope.key){await refresh();message('Movement '+(intent.body.transferId||'dispatch')+' saved. '+(q('[data-transfer-message]').textContent.includes('retained')?'Stock refresh could not be confirmed; your current entry is retained.':'Confirmed stock refreshed.'));}
}
window.TT_BAG_TRANSFERS={open,refresh,whole};})();
