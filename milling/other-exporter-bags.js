/* Physical receipts share the canonical bag ledger; no autosave or synthetic stock. */
(() => {
'use strict';
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const norm=v=>String(v??'').trim().replace(/\s+/g,' ').toLowerCase();
let data=null,scopeKey='',sequence=0,busy=false,pending=null,choiceData=null,choiceSequence=0;
const $=id=>document.getElementById(id),access=()=>window.TT_MODULE_ACCESS||{};
function scope(production=false){
 const mill=typeof currentMill==='undefined'?null:currentMill;if(!mill)throw Error('Select the physical mill first.');
 const matches=(access().masters?.mills||[]).filter(r=>norm(r.values?.[0])===norm(mill.name));if(matches.length!==1)throw Error('Resolve the physical mill in Mills & Locations.');
 return {entity:production?($('prodEntity')?.value||mill.entity):stockEntityForView(),locationId:String(matches[0].id),millName:mill.name};
}
const key=s=>s.entity+'|'+s.locationId,storage=s=>'tt_ui_other_bags_'+access().userId+'_'+key(s);
function message(text,error=false){const el=$('otherBagMessage');if(el){el.textContent=text;el.style.color=error?'#b42318':'';}if(error)window.TT_FORM_VIEWPORT?.open(el);}
async function request(body){const r=await fetch('/api/other_exporter_bags.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify({...body,csrf:access().csrf})}),d=await r.json();if(!r.ok||!d.ok)throw Object.assign(Error(d.error||'Bag action could not be confirmed.'),{definitive:r.status>=400&&r.status<500});return d;}
function selected(){return data?.deals.find(d=>d.id===$('otherBagSoda').value);}
function choose(){const d=selected();$('otherBagEntry').hidden=!d;if(!d)return;$('otherBagBrand').value=d.brand||'';$('otherBagBrand').readOnly=!!d.brand;$('otherBagInstruction').textContent=d.party+' · '+(d.reference||d.id)+' · '+(d.packing||'Packing not specified');
 const weights=[...String(d.packing||'').matchAll(/\b([0-9]+(?:\.[0-9]+)?)\s*(?:kgs?|kilograms?)\b/gi)].map(m=>Number(m[1]));$('otherBagPacking').value=weights.length===1?weights[0]:'';$('otherBagQty').value='';$('otherBagInspected').checked=false;history(d.id);window.TT_FORM_VIEWPORT?.open($('otherBagEntry'));}
function history(id){const lots=Object.values(data?.inventory?.lots||{}).filter(l=>l.exporterDealId===id);$('otherBagHistory').innerHTML='<h4>Received, used and remaining bags</h4><table><thead><tr><th>Owner / marking</th><th>Packing / material</th><th>Accepted received</th><th>Used</th><th>Remaining</th><th>Quarantined</th></tr></thead><tbody>'+lots.map(l=>'<tr><td>'+esc(l.externalOwnerName)+' · '+esc(l.brand)+'</td><td>'+esc(l.packingSize)+' · '+esc(l.bagType)+'</td><td>'+esc(l.receivedQty)+'</td><td>'+esc(l.issuedQty)+'</td><td>'+esc(l.hold||l.availableQty)+'</td><td>'+esc(l.quarantinedQty)+'</td></tr>').join('')+'</tbody></table>'+(lots.length?'':'<p>No bags received against this SODA yet.</p>')+'<table><thead><tr><th>Receipt date</th><th>Marking</th><th>Actually received</th><th>Accepted</th><th>Quarantined</th></tr></thead><tbody>'+(data?.receiptHistory||[]).filter(r=>r.sourceSnapshot?.exporterDealId===id).map(r=>'<tr><td>'+esc(r.date)+'</td><td>'+esc(r.brand)+'</td><td>'+esc(r.qty)+'</td><td>'+esc(r.acceptedQty)+'</td><td>'+esc(r.quarantinedQty)+'</td></tr>').join('')+'</tbody></table>'+lots.filter(l=>l.quarantinedQty>0).map(l=>'<button class="sec" type="button" data-other-bag-review="'+esc(l.originBagId)+'">Review quarantined '+esc(l.brand)+' bags</button>').join('');
 $('otherBagHistory').querySelectorAll('[data-other-bag-review]').forEach(button=>button.onclick=()=>{currentBagOrder=Number(button.dataset.otherBagReview);void window.TT_BAG_RECEIPTS?.reviewSelected();});}

function recover(){const s=scope();pending=JSON.parse(localStorage.getItem(storage(s))||'null');$('otherBagRetry').hidden=!pending;}
async function open(){
 let s;try{s=scope();recover();}catch(e){message(e.message,true);return;}const token=++sequence,stamp=key(s),previous=$('otherBagSoda').value;
 try{const r=await fetch('/api/other_exporter_bags.php?'+new URLSearchParams(s),{credentials:'same-origin'}),d=await r.json();if(token!==sequence||key(scope())!==stamp)return;if(!r.ok||!d.ok)throw Error(d.error||'SODAs could not load.');data=d;scopeKey=stamp;
 const selectedNow=$('otherBagSoda').value,previousParty=$('otherBagExporter').value;const parties=[...new Set(d.deals.map(x=>x.party))].sort();$('otherBagExporter').innerHTML='<option value="">All other exporters</option>'+parties.map(p=>'<option>'+esc(p)+'</option>').join('');$('otherBagExporter').value=previousParty;renderSodas(selectedNow||previous,true);message('Select an instructed SODA. Bags remain owned by the other exporter.');
 }catch(e){if(token===sequence)message(e.message,true);}
}
function renderSodas(previous='',preserve=false){const party=$('otherBagExporter').value;$('otherBagSoda').innerHTML='<option value="">Select SODA</option>'+(data?.deals||[]).filter(d=>!party||d.party===party).map(d=>'<option value="'+esc(d.id)+'">'+esc(d.party+' · '+(d.reference||d.id)+' · '+(d.brand||'Brand not entered'))+'</option>').join('');$('otherBagSoda').value=previous;$('otherBagEntry').hidden=!selected();if(selected()){if(preserve)history(selected().id);else choose();}}
async function send(body,recovery=false){
 if(busy)return;const s=scope();if(key(s)!==scopeKey)throw Error('The mill changed. Refresh before saving.');busy=true;const panel=$('otherExporterBags');panel.inert=true;
 try{
  pending={scope:s,body};localStorage.setItem(storage(s),JSON.stringify(pending));$('otherBagRetry').hidden=false;
  const d=body.action==='receive'?await window.TT_SHARED_SYNC.commitOtherBagReceipt(body):await request(body);localStorage.removeItem(storage(s));pending=null;$('otherBagRetry').hidden=true;
  if(body.action==='fill_brand'){const original=selected();if(original&&original.id===d.deal.id){Object.assign(original,d.deal);$('otherBagBrand').value=d.deal.brand;$('otherBagBrand').readOnly=true;}message('Brand saved to the original SODA. Record the actual bags received below.');}
  else{if(!recovery){$('otherBagQty').value='';$('otherBagInspected').checked=false;$('otherBagMismatch').checked=false;}await open();message('Actual receipt saved. Production can select the received marking.');void loadChoices();}return true;
 }catch(e){if(e.definitive){localStorage.removeItem(storage(s));pending=null;$('otherBagRetry').hidden=true;}message(e.message+' Your entry is retained.',true);return false;}
 finally{busy=false;panel.inert=false;}
}
function bodyBase(action){const s=scope(),d=selected();if(!d)throw Error('Select the original SODA.');return {action,...s,sodaId:d.id,version:d.version,requestKey:crypto.randomUUID()};}
async function receive(){try{
 if(pending)throw Error('Confirm the previous action first.');const d=selected();if(!d)throw Error('Select the original SODA.');
 const raw=$('otherBagQty').value.trim(),qty=Number(raw),packing=Number($('otherBagPacking').value),date=$('otherBagDate').value,material=$('otherBagMaterial').value;
 if(!raw||!Number.isSafeInteger(qty)||qty<=0)throw Error('Enter the positive whole number of bags received.');
 if(!Number.isFinite(packing)||packing<=0||!material)throw Error('Enter the actual packing weight and bag material.');
 if(!date||date<d.date||date>today())throw Error('Enter the actual receipt date between the SODA date and today.');
 if(!$('otherBagInspected').checked)throw Error('Confirm the bags were physically counted and inspected.');
 if(!d.brand){const brand=$('otherBagBrand').value.trim();if(!brand)throw Error('Enter the missing brand / bag marking.');if(!await send({...bodyBase('fill_brand'),brand}))return;}
 const saved=await send({...bodyBase('receive'),date,qty,packingKg:String(packing),bagType:material,inspected:true,printingMismatch:$('otherBagMismatch').checked,materialMismatch:false,qualityMismatch:false,tareMismatch:false});
 if(!saved&&selected()?.brand)message(($('otherBagMessage').textContent||'Receipt not confirmed.')+' The SODA brand is saved; the bag receipt still needs confirmation.',true);
 }catch(e){message(e.message,true);}}
function toggle(mode){if(busy)return;$('ourExportBags').hidden=mode!=='our';$('otherExporterBags').hidden=mode!=='other';if(mode==='other')void open();}
function choiceScope(){const s=scope(true);return {...s,asOf:$('prodDate')?.value||today(),purpose:'production'};}
async function loadChoices(){
 const token=++choiceSequence;choiceData=null;try{const s=choiceScope(),stamp=JSON.stringify(s),r=await fetch('/api/other_exporter_bags.php?'+new URLSearchParams(s),{credentials:'same-origin'}),d=await r.json();if(token!==choiceSequence||JSON.stringify(choiceScope())!==stamp)return;if(!r.ok||!d.ok)throw Error(d.error||'Received bag choices could not load.');choiceData={stamp,rows:d.choices};refreshBrandDropdown();const node=$('productionBagChoicesMessage');if(node)node.textContent=d.choices.length?'Select the actual received bag marking and packing.':'No available received bags for current production at this company and mill.';
 const diag=$('bagChoiceDiagnostics');if(diag)diag.innerHTML='<summary>Original bag sources and production eligibility</summary><table><thead><tr><th>Brand / owner</th><th>Original source</th><th>Received</th><th>Used</th><th>Remaining</th><th>Production status</th></tr></thead><tbody>'+d.diagnostics.map(x=>'<tr><td>'+esc(x.brand)+' · '+esc(x.owner)+'</td><td>'+esc(x.reference)+'</td><td>'+esc(x.received)+'</td><td>'+esc(x.used)+'</td><td>'+esc(x.remaining)+'</td><td>'+esc(x.reason)+'</td></tr>').join('')+'</tbody></table>';
 }catch(e){if(token===choiceSequence){refreshBrandDropdown();const node=$('productionBagChoicesMessage');if(node){node.textContent=e.message;node.style.color='#b42318';}}}
}
function choices(){try{return choiceData?.stamp===JSON.stringify(choiceScope())?choiceData.rows:[];}catch{return [];}}
function refresh(){if(!$('brandChoice'))return;const prior=$('brandChoice').value,rows=choices();$('brandChoice').innerHTML='<option value="">Select received bag marking</option>'+($('brandType').value?rows.map(r=>'<option value="'+esc(r.lotId)+'">'+esc(r.brand+' · '+r.packingKg+' kg · '+r.owner+' · '+r.available+' bags')+'</option>').join(''):'');$('brandChoice').value=prior;}
function add(){const c=choices().find(r=>r.lotId===$('brandChoice').value),type=$('brandType').value;if(!type||!c)return alert('Select the type and an available received bag marking first.');const product=(type==='rice'?'Ready Rice — ':'B2 — ')+c.brand;if(productionRows.some(r=>r.product===product&&Number(r.bagWeight)===Number(c.packingKg)))return alert('This brand and packing are already in the production entry.');productionRows.push({id:Date.now(),product,bags:0,bagWeight:c.packingKg,broken:brandBrokenSpec(c.brand),exporterDealId:c.exporterDealId||'',bagSourceLotId:c.lotId});productionGroupDelete[type==='rice'?'ready':'b2']=true;renderProductionTable();calcProduction();refresh();}
function init(){
 const root=$('otherExporterBags');if(!root)return;
 document.querySelectorAll('[name=exportBagOwnership]').forEach(r=>r.onchange=()=>toggle(r.value));$('otherBagExporter').onchange=()=>renderSodas();$('otherBagSoda').onchange=choose;$('otherBagRefresh').onclick=()=>void open();$('otherBagReceive').onclick=()=>void receive();$('otherBagRetry').onclick=()=>{try{recover();if(pending)void send(pending.body,true);}catch(e){message(e.message,true);}};$('otherBagDate').value=today();
 const bar=document.createElement('div');bar.innerHTML='<button type="button" class="sec" id="refreshProductionBags">Refresh received bag choices</button><p id="productionBagChoicesMessage" role="status"></p><details id="bagChoiceDiagnostics" style="overflow-x:auto"></details>';$('brandChoice')?.closest('.r3')?.insertAdjacentElement('afterend',bar);$('refreshProductionBags').onclick=()=>void loadChoices();
 $('prodEntity')?.addEventListener('change',()=>void loadChoices());$('prodDate')?.addEventListener('change',()=>void loadChoices());
 const original=window.openPanel;window.openPanel=function(id){const result=original.apply(this,arguments);if(id==='production')void loadChoices();if(id==='newbags'&&document.querySelector('[name=exportBagOwnership]:checked')?.value==='other')void open();return result;};
}
function addLocal(){if(productionRows.some(r=>r.product==='Ready Rice — Local Bags'))return;productionRows.push({id:Date.now(),product:'Ready Rice — Local Bags',bags:0,bagWeight:50});productionGroupDelete.ready=true;renderProductionTable();calcProduction();}
window.TT_OTHER_EXPORTER_BAGS={open,toggle};window.TT_BAG_CHOICES={load:loadChoices,choices,refresh,add,addLocal};
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
