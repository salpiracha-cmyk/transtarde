(() => {
'use strict';
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const norm=v=>String(v??'').trim().replace(/\s+/g,' ').toLowerCase(),clone=v=>JSON.parse(JSON.stringify(v));
let host=null,baseline=null,review=null,sequence=0;
const brand=row=>{if(row.noStockPost||row.managedReconciliation||row.systemFixed)return '';const m=String(row.product||'').match(/^(?:Ready Rice|B2)\s*—\s*(.+)$/u);return m&&!['local','local bags'].includes(norm(m[1]))?m[1].trim():'';};
function sourceLabel(lot){
 const ref=String(lot.sodaReference||lot.poNo||'').trim();
 if(lot.externalOwnerName&&(!ref||/^(?:OTHER[|]|EXP-|FELLOW[|]|PO[|]|BT[|])/i.test(ref)))return lot.externalOwnerName+' receipt';
 return ref&&!/^(?:OTHER[|]|EXP-|FELLOW[|]|PO[|]|BT[|])/i.test(ref)?ref:'Bag receipt';
}
function location(record){
 const id=record.locationId||record.bagAllocationScope?.locationId||'',name=record.millName||record.mill||'';
 const matches=(window.TT_MODULE_ACCESS?.masters?.mills||[]).filter(row=>id?String(row.id)===String(id):norm(row.values?.[0])===norm(name));
 if(matches.length!==1)throw Error('Resolve the actual production location in Mills & Locations.');return {id:String(matches[0].id),name:matches[0].values[0]};
}
function usage(record){
 const result=new Map();for(const row of record.rows||[]){const name=brand(row);if(!name)continue;const raw=row.actualBagCount??row.bags??0,quantity=Number(raw),packing=Number(row.bagWeight);
  if((typeof raw==='string'&&raw.trim()==='')||!Number.isSafeInteger(quantity)||quantity<0)throw Error('Enter the actual whole empty-bag count for '+name+'. Food-equivalent bags are not rounded.');
  if(!quantity)continue;if(!Number.isFinite(packing)||packing<=0)throw Error('Enter the actual packing weight for '+name+'.');
  const key=norm(name)+'|'+packing,prior=result.get(key);result.set(key,{brand:name,packingKg:packing,qty:(prior?.qty||0)+quantity});
 }return [...result.entries()].sort(([a],[b])=>a.localeCompare(b));
}
function signature(record){const mill=location(record);return JSON.stringify([record.entity,mill.id,record.date,usage(record),(record.rows||[]).filter(row=>brand(row)).map(row=>[String(row.id),row.productionOrderId||'',row.exporterDealId||''])]);}
function current(){const mill=typeof currentMill==='undefined'?null:currentMill;if(!mill)throw Error('Select the production mill first.');return {id:typeof editingProdId==='undefined'?null:editingProdId,entity:document.querySelector('#prodEntity')?.value||mill.entity,millName:mill.name,date:document.querySelector('#prodDate')?.value,rows:typeof productionRows==='undefined'?[]:productionRows};}
function mount(){
 if(host?.isConnected)return host;const production=document.querySelector('#production'),save=production?.querySelector('[onclick="saveProduction()"]');if(!save)return null;
 host=document.createElement('details');host.className='card';host.id='productionSourceBags';host.innerHTML='<summary>Bag source details (automatic)</summary><p>Production uses available received bags for the selected brand and packing. Review here for a fractional output or an exception.</p><div data-bag-counts></div><button type="button" class="sec" data-review-source-bags>Review source bags</button><p role="status" data-bag-message></p><div data-bag-source-review></div>';
 save.closest('.card').insertAdjacentElement('afterend',host);host.querySelector('[data-review-source-bags]').onclick=()=>void load();return host;
}
function counts(rows){
 if(!mount())return;const entries=rows.filter(row=>brand(row)&&(Number(row.bags)>0||Number(row.actualBagCount)>0));host.hidden=!entries.length;if(entries.some(row=>!Number.isSafeInteger(Number(row.bags))&&row.actualBagCount==null))host.open=true;
 host.querySelector('[data-bag-counts]').innerHTML=entries.length?'<table><thead><tr><th>Brand / packing</th><th>Output bag equivalents</th><th>Actual empty bags used</th></tr></thead><tbody>'+entries.map(row=>'<tr><td>'+esc(brand(row))+' · '+esc(row.bagWeight)+' kg</td><td>'+esc(row.bags)+'</td><td><input inputmode="numeric" data-actual-bags="'+esc(row.id)+'" aria-label="Actual empty bags used for '+esc(brand(row))+'" value="'+esc(row.actualBagCount??(Number.isSafeInteger(Number(row.bags))?row.bags:''))+'"></td></tr>').join('')+'</tbody></table>':'<p>No branded export bags are required by the current output rows.</p>';
 host.querySelectorAll('[data-actual-bags]').forEach(input=>{input.oninput=()=>{const row=rows.find(row=>String(row.id)===input.dataset.actualBags);if(row){const number=Number(input.value);row.actualBagCount=input.value.trim()!==''&&Number.isSafeInteger(number)&&number>=0?number:input.value;}host.querySelector('[data-bag-message]').textContent='Packing count changed. Review source bags again before saving.';};});
}
function sourceTable(inventory,record,previous){
 const required=new Map(usage(record)),candidates=Object.values(inventory.lots||{}).filter(lot=>required.has(norm(lot.brand)+'|'+Number(lot.packingKg)));
 return '<p>'+[...required.values()].map(row=>esc(row.brand)+' · '+esc(row.packingKg)+' kg: '+esc(row.qty)+' actual bags').join(' · ')+'</p><table><thead><tr><th>Original PO / source</th><th>Brand / packing / material</th><th>Available for this entry</th><th>Use bags</th></tr></thead><tbody>'+candidates.map(lot=>'<tr><td>'+esc(sourceLabel(lot))+(lot.isTransferStock?'<br>Received transfer stock':'')+'</td><td>'+esc(lot.brand)+(lot.externalOwnerName?' · Owner: '+esc(lot.externalOwnerName):'')+' · '+esc(lot.packingSize)+' · '+esc(lot.bagType)+'</td><td>'+esc(lot.hold||(lot.committableQty??lot.availableQty??'Unavailable'))+(lot.warnings?.length?'<br>'+lot.warnings.map(esc).join('<br>'):'')+'</td><td><input inputmode="numeric" data-source-lot="'+esc(lot.id)+'" aria-label="Bags used from '+esc(sourceLabel(lot))+'" value="'+esc(previous.get(lot.id)??'')+'" '+(lot.hold?'disabled':'')+'></td></tr>').join('')+'</tbody></table>'+(candidates.length?'':'<p>No matching verified source lots are available. Resolve the original receipt or allocation before saving.</p>');
}
async function load(show=true){
 if(!mount())return;const message=host.querySelector('[data-bag-message]'),button=host.querySelector('[data-review-source-bags]');let record,stamp,mill;
 try{record=current();counts(record.rows);if(show){host.open=true;window.TT_FORM_VIEWPORT?.open(host);}stamp=signature(record);mill=location(record);}catch(error){message.textContent=error.message;return;}
 const entered=[...host.querySelectorAll('[data-source-lot]')],previous=entered.length?new Map(entered.map(input=>[input.dataset.sourceLot,input.value])):new Map((baseline?.bagAllocations||[]).map(a=>[a.lotId,a.qty]));
 const request=++sequence;button.disabled=true;message.textContent='Checking committed source bags…';
 try{
  const query=new URLSearchParams({purpose:'production',entity:record.entity,locationId:mill.id,asOf:record.date,...(record.id!=null?{excludeProductionId:String(record.id)}:{})});
  const response=await fetch('/api/bag_transfers.php?'+query,{credentials:'same-origin',headers:{Accept:'application/json'}}),data=await response.json();
  if(request!==sequence)return;if(stamp!==signature(current())){message.textContent='Production scope or packing changed. Review source bags again.';return;}if(!response.ok||!data.ok)throw Error(data.error||'Source stock could not be reviewed.');
  if(data.entity!==record.entity||data.locationId!==mill.id||data.inventory?.asOfDate!==record.date)throw Error('The source stock response belongs to another review.');
  const latest=[...host.querySelectorAll('[data-source-lot]')];const selection=latest.length?new Map(latest.map(input=>[input.dataset.sourceLot,input.value])):previous;for(const row of record.rows||[])if(row.bagSourceLotId&&!selection.has(row.bagSourceLotId))selection.set(row.bagSourceLotId,row.actualBagCount??row.bags??'');review={signature:stamp,inventory:data.inventory};host.querySelector('[data-bag-source-review]').innerHTML=sourceTable(data.inventory,record,selection);message.textContent='Enter bags against the actual source lots. Save Production commits the selection.'+(data.inventory.warnings?.length?' '+data.inventory.warnings.join(' '):'');
 }catch(error){if(request===sequence)message.textContent=error.message+' Your production entry is retained.';}finally{if(request===sequence)button.disabled=false;}
}
function prepare(record){
 const required=new Map(usage(record));if(!required.size&&!baseline?.bagAllocationVersion&&!review)return record;const stamp=signature(record),mill=location(record);
 if(!review&&baseline?.bagAllocationVersion===1&&signature(baseline)===stamp){const saved={...record,locationId:baseline.locationId||baseline.bagAllocationScope?.locationId||mill.id,millName:baseline.millName};for(const key of ['bagAllocationVersion','bagAllocations','bagStockFingerprint','bagAllocationActor','bagAllocationScope'])saved[key]=clone(baseline[key]??null);return saved;}
 if(!review||review.signature!==stamp)throw Error('Review the actual source bag lots for this production date and packing before saving.');
 const allocations=[],actual=new Map();for(const input of host.querySelectorAll('[data-source-lot]')){if(!input.value.trim())continue;const qty=Number(input.value);if(!Number.isSafeInteger(qty)||qty<0)throw Error('Enter whole source bag quantities.');if(!qty)continue;
  const lot=review.inventory.lots[input.dataset.sourceLot];if(!lot||lot.hold||!Number.isSafeInteger(lot.committableQty??lot.availableQty)||qty>(lot.committableQty??lot.availableQty))throw Error('A chosen source lot is held or has insufficient bags. Retain this entry and review stock again.');
  const key=norm(lot.brand)+'|'+Number(lot.packingKg);actual.set(key,(actual.get(key)||0)+qty);allocations.push({lotId:lot.id,qty});
 }
 if(actual.size!==required.size||[...required].some(([key,row])=>actual.get(key)!==row.qty))throw Error('Source lot quantities must equal the actual bags used for each brand and packing size.');
 return {...record,locationId:mill.id,bagAllocationVersion:1,bagStockFingerprint:review.inventory.fingerprint,bagAllocations:allocations};
}
async function autoReview(record){
 const required=new Map(usage(record));if(!required.size)return;
 if(baseline?.bagAllocationVersion===1&&signature(baseline)===signature(record))return;
 if(review?.signature!==signature(record))await load(false);if(!review||review.signature!==signature(record))throw Error('Bag availability could not be confirmed. Your production entry is retained.');
 const inputs=[...host.querySelectorAll('[data-source-lot]')];
 // Keep an explicitly entered, valid allocation. A review with blank cells
 // must still auto-allocate on Save.
 if(inputs.some(input=>Number(input.value)>0)){
  try{prepare(record);return;}catch{}
 }
 for(const input of inputs)input.value='';
 for(const [group,need] of required){
  const matching=Object.values(review.inventory.lots||{}).filter(lot=>norm(lot.brand)+'|'+Number(lot.packingKg)===group);
  const rows=record.rows.filter(row=>brand(row)&&norm(brand(row))+'|'+Number(row.bagWeight)===group&&Number(row.actualBagCount??row.bags)>0);
  const selected=new Map();
  for(const row of rows){
   const qty=Number(row.actualBagCount??row.bags);
   const owned=matching.filter(lot=>(lot.exporterDealId||'')===(row.exporterDealId||'')&&(!row.contractRef||lot.contractRef===row.contractRef));
   const eligible=owned.filter(lot=>!lot.hold&&(lot.committableQty??lot.availableQty??0)-(selected.get(lot.id)||0)>=qty);
   let lot=eligible.find(item=>item.id===row.bagSourceLotId);
   if(!lot&&eligible.length===1)lot=eligible[0];
   if(!lot){
    host.open=true;
    if(eligible.length>1)throw Error('More than one receipt can supply '+need.brand+'. Select the original receipt in Bag source details.');
    const quarantined=owned.reduce((sum,item)=>sum+Number(item.quarantinedQty||0),0);
    const available=owned.reduce((sum,item)=>sum+(!item.hold?Math.max(0,(item.committableQty??item.availableQty??0)-(selected.get(item.id)||0)):0),0);
    if(available>=qty)throw Error(need.brand+' has enough bags across several receipts. Enter the split in Bag source details.');
    throw Error(need.brand+' · '+need.packingKg+' kg: '+qty+' bags needed, '+available+' available for '+record.date+'.'+(quarantined?' '+quarantined+' received bags are in quarantine.':' Check the accepted receipt and its date.'));
   }
   row.bagSourceLotId=lot.id;
   selected.set(lot.id,(selected.get(lot.id)||0)+qty);
  }
  for(const [id,qty] of selected){const input=inputs.find(node=>node.dataset.sourceLot===id);if(input)input.value=qty;}
 }
}

function restore(record=null){baseline=record?clone(record):null;review=null;sequence++;if(mount()){host.open=false;host.querySelector('[data-review-source-bags]').disabled=false;host.querySelector('[data-bag-source-review]').innerHTML=baseline?.bagAllocationVersion?'<h4>Saved source bags</h4><table><thead><tr><th>Original PO / line</th><th>Brand / packing / material</th><th>Bags used</th></tr></thead><tbody>'+(baseline.bagAllocations||[]).map(a=>'<tr><td>'+esc(sourceLabel(a.sourceSnapshot||{}))+'</td><td>'+esc(a.sourceSnapshot?.brand||'')+' · '+esc(a.sourceSnapshot?.packingSize||'')+' · '+esc(a.sourceSnapshot?.bagType||'')+'</td><td>'+esc(a.qty)+'</td></tr>').join('')+'</tbody></table>':'';host.querySelector('[data-bag-message]').textContent=baseline?.bagAllocationVersion?'Saved source allocations are retained until you explicitly review a replacement.':'';counts(typeof productionRows==='undefined'?[]:productionRows);}}
window.TT_PRODUCTION_BAGS={autoReview,prepare,load,restore,outputs:counts,usage,signature};mount();
})();
