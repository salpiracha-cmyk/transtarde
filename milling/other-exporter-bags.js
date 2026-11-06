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
function choose(){const d=selected();$('otherBagEntry').hidden=!d;if(!d)return;$('otherBagBrand').value=d.brand||'';$('otherBagInstruction').textContent=d.party+' · '+(d.reference&&!/^EXP-/i.test(d.reference)?d.reference:(d.quantityMT==null?'':d.quantityMT+' MT'))+' · '+(d.packing||'Packing not specified')+' · '+(d.bagResponsibility?.arrangementName||'Bag arrangement: Pending Confirmation');
 const weights=[...String(d.packing||'').matchAll(/\b([0-9]+(?:\.[0-9]+)?)\s*(?:kgs?|kilograms?)\b/gi)].map(m=>Number(m[1]));$('otherBagPacking').value=weights.length===1?weights[0]:'';$('otherBagQty').value='';$('otherBagTare').value=Number(d.productionBagTareGrams)>0?d.productionBagTareGrams:'';const packing=String(d.packing||'');$('otherBagMaterial').value=/non[ -]?woven/i.test(packing)?'Non-woven':/bopp/i.test(packing)?'BOPP':/\bpp\b/i.test(packing)?'PP Woven':/jute/i.test(packing)?'Jute':'';history(d.id);window.TT_FORM_VIEWPORT?.open($('otherBagEntry'));}
function history(id){const lots=Object.values(data?.inventory?.lots||{}).filter(l=>l.exporterDealId===id);$('otherBagHistory').innerHTML='<h4>Received, used and remaining bags</h4><table><thead><tr><th>Owner / marking</th><th>Packing / material</th><th>Accepted received</th><th>Used</th><th>Returned to supplier</th><th>Remaining</th><th>Historical quarantine</th></tr></thead><tbody>'+lots.map(l=>'<tr><td>'+esc(l.externalOwnerName)+' · '+esc(l.brand)+'</td><td>'+esc(l.packingSize)+' · '+esc(l.bagType)+'</td><td>'+esc(l.receivedQty)+'</td><td>'+esc(l.issuedQty)+'</td><td>'+esc(l.supplierReturnedQty??0)+'</td><td>'+esc(l.hold||l.availableQty)+'</td><td>'+esc(l.quarantinedQty)+'</td></tr>').join('')+'</tbody></table>'+(lots.length?'':'<p>No bags received against this SODA yet.</p>')+'<table><thead><tr><th>Receipt date</th><th>Marking</th><th>Tare (g/bag)</th><th>Actually received</th><th>Accepted</th><th>Quarantined</th></tr></thead><tbody>'+(data?.receiptHistory||[]).filter(r=>r.sourceSnapshot?.exporterDealId===id).map(r=>'<tr><td>'+esc(r.date)+'</td><td>'+esc(r.brand)+'</td><td>'+esc(r.actualTareGrams??'')+'</td><td>'+esc(r.qty)+'</td><td>'+esc(r.acceptedQty)+'</td><td>'+esc(r.quarantinedQty)+'</td></tr>').join('')+'</tbody></table>'+lots.filter(l=>l.receivedQty>0||l.quarantinedQty>0).map(l=>'<button class="sec" type="button" data-other-bag-review="'+esc(l.originBagId)+'">Receipt history / return · '+esc(l.brand)+'</button>').join('');
 $('otherBagHistory').querySelectorAll('[data-other-bag-review]').forEach(button=>button.onclick=()=>{currentBagOrder=Number(button.dataset.otherBagReview);void window.TT_BAG_RECEIPTS?.reviewSelected();});}

function recover(){const s=scope();pending=JSON.parse(localStorage.getItem(storage(s))||'null');$('otherBagRetry').hidden=!pending;}
async function open(){
 let s;try{s=scope();recover();}catch(e){message(e.message,true);return;}const token=++sequence,stamp=key(s),previous=$('otherBagSoda').value;
 try{const r=await fetch('/api/other_exporter_bags.php?'+new URLSearchParams(s),{credentials:'same-origin'}),d=await r.json();if(token!==sequence||key(scope())!==stamp)return;if(!r.ok||!d.ok)throw Error(d.error||'SODAs could not load.');const sameScope=scopeKey===stamp;data=d;scopeKey=stamp;
 const selectedNow=$('otherBagSoda').value,previousParty=$('otherBagExporter').value;const parties=[...new Set(d.deals.map(x=>x.party))].sort();$('otherBagExporter').innerHTML='<option value="">All other exporters</option>'+parties.map(p=>'<option>'+esc(p)+'</option>').join('');$('otherBagExporter').value=previousParty;renderSodas(sameScope?(selectedNow||previous):'',sameScope);message('Select an instructed SODA. Bags remain owned by the other exporter.');
 }catch(e){if(token===sequence)message(e.message,true);}
}
function renderSodas(previous='',preserve=false){const party=$('otherBagExporter').value;$('otherBagSoda').innerHTML='<option value="">Select SODA</option>'+(data?.deals||[]).filter(d=>!party||d.party===party).map(d=>'<option value="'+esc(d.id)+'">'+esc(d.party+' · '+(d.reference&&!/^EXP-/i.test(d.reference)?d.reference:(d.quantityMT==null?'':d.quantityMT+' MT'))+' · '+(d.brand||'Brand not entered'))+'</option>').join('');$('otherBagSoda').value=previous;$('otherBagEntry').hidden=!selected();if(selected()){if(preserve)history(selected().id);else choose();}}
async function send(body,recovery=false){
 if(busy)return;const s=scope();if(key(s)!==scopeKey)throw Error('The mill changed. Refresh before saving.');busy=true;const panel=$('otherExporterBags');panel.inert=true;
 try{
  pending={scope:s,body};localStorage.setItem(storage(s),JSON.stringify(pending));$('otherBagRetry').hidden=false;
  const d=body.action==='receive'?await window.TT_SHARED_SYNC.commitOtherBagReceipt(body):await request(body);localStorage.removeItem(storage(s));pending=null;$('otherBagRetry').hidden=true;
  if(body.action==='fill_brand'){const original=selected();if(original&&original.id===d.deal.id){Object.assign(original,d.deal);$('otherBagBrand').value=d.deal.brand;}message('Brand saved to the original SODA. Record the actual bags received below.');}
  else{if(!recovery){$('otherBagQty').value='';}await open();message('Actual receipt saved. Production can select the received marking.');void loadChoices();}return true;
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
 const brand=$('otherBagBrand').value.trim(),actualTareGrams=Number($('otherBagTare').value);
 if(!brand)throw Error('Enter the actual brand / bag marking.');
 if(!Number.isFinite(actualTareGrams)||actualTareGrams<=0)throw Error('Enter the actual positive tare weight in grams per bag.');
 const filledBrand=!d.brand;
 if(filledBrand&&!await send({...bodyBase('fill_brand'),brand}))return;
 const saved=await send({...bodyBase('receive'),date,qty,packingKg:String(packing),bagType:material,brand,actualTareGrams,recordActual:true});
 if(!saved&&filledBrand&&selected()?.brand)message(($('otherBagMessage').textContent||'Receipt not confirmed.')+' The SODA brand is saved; the bag receipt still needs confirmation.',true);
 }catch(e){message(e.message,true);}}
function toggle(mode){if(busy)return;$('ourExportBags').hidden=mode!=='our';$('otherExporterBags').hidden=mode!=='other';if(mode==='other')void open();}
function choiceScope(){const s=scope(true);return {...s,asOf:$('prodDate')?.value||today(),purpose:'production'};}
function instructions(){try{return choiceData?.stamp===JSON.stringify(choiceScope())?choiceData.instructions.filter(d=>!d.commodity||d.commodity==='RICE'):[];}catch{return [];}}
function renderInstructions(selectedId=''){
 const host=$('productionFellowInstructions');if(!host)return;const rows=instructions(),prior=selectedId||$('productionFellowSoda')?.value||'';
 host.hidden=!rows.length;if(!rows.length){host.replaceChildren();return;}
 host.innerHTML='<details open><summary>Fellow-exporter production instructions · '+rows.length+'</summary><label for="productionFellowSoda">Fellow-exporter SODA</label><select id="productionFellowSoda">'+rows.map(d=>'<option value="'+esc(d.id)+'">'+esc(d.party+' · '+(d.brand||'Unbranded')+' · '+(d.reference&&!/^EXP-/i.test(d.reference)?d.reference:(d.quantityMT==null?'':d.quantityMT+' MT')))+'</option>').join('')+'</select><div id="productionFellowDetails"></div></details>';
 const select=$('productionFellowSoda');if(rows.some(d=>d.id===prior))select.value=prior;
 select.onchange=()=>{const d=rows.find(d=>d.id===select.value);if(!d)return;const available=choices().filter(l=>l.exporterDealId===d.id).reduce((n,l)=>n+Number(l.available||0),0);$('productionFellowDetails').innerHTML='<div class="production-instruction-grid"><p><span>Brand</span><b>'+esc(d.brand||'Unbranded')+'</b></p><p><span>Product / quality</span><b>'+esc(d.product||'See original SODA')+'</b></p><p><span>Instructed quantity</span><b>'+esc(d.quantityMT===undefined?'—':Number(d.quantityMT).toLocaleString('en-PK')+' MT')+'</b></p><p><span>Packing</span><b>'+esc(d.packing||'Not specified')+'</b></p><p><span>Finished stock name</span><b>'+esc(d.productionStockName||'Not specified')+'</b></p><p><span>Bag arrangement</span><b>'+esc(d.bagResponsibility?.arrangementName||'Pending Confirmation')+'</b></p></div><p class="production-source-status">'+(available?esc(available.toLocaleString('en-PK'))+' received bags available for this SODA.':'No received bag lot is linked to this SODA yet. Record the actual receipt before selecting its printed bags.')+'</p>'+((d.specificationMode==='FULL'&&d.fullSpecification)||d.productionNotes?'<details><summary>Full specification / instructions</summary><p style="white-space:pre-wrap">'+esc([d.specificationMode==='FULL'?d.fullSpecification:'',d.productionNotes].filter(Boolean).join('\n'))+'</p></details>':'');};select.onchange();
}
async function loadChoices(){
 const token=++choiceSequence,wanted=$('brandChoice')?.value||'',wantedInstruction=$('productionFellowSoda')?.value||'';choiceData=null;refreshOrders();refreshBrandDropdown();renderInstructions();window.TT_PRODUCTION_RECOVERY?.refreshReferences?.();if($('bagChoiceDiagnostics'))$('bagChoiceDiagnostics').textContent='';try{const s=choiceScope(),stamp=JSON.stringify(s),r=await fetch('/api/other_exporter_bags.php?'+new URLSearchParams(s),{credentials:'same-origin'}),d=await r.json();if(token!==choiceSequence||JSON.stringify(choiceScope())!==stamp)return;if(!r.ok||!d.ok)throw Error(d.error||'Received bag choices could not load.');choiceData={stamp,rows:d.choices,instructions:d.deals||[],orders:d.productionOrders||[]};refreshBrandDropdown();if(d.choices.some(c=>c.lotId===wanted))$('brandChoice').value=wanted;updateAdd();renderInstructions(wantedInstruction);refreshOrders();window.TT_PRODUCTION_RECOVERY?.refreshReferences?.();const node=$('productionBagChoicesMessage');if(node){node.style.color='';node.textContent=d.choices.length?'Select the received marking and packing to add branded output.':'No available received bags for printed output. Use local / unbranded output, or record the actual bag receipt.';}
 const diag=$('bagChoiceDiagnostics');if(diag)diag.innerHTML='<summary>Bag stock details</summary><table><thead><tr><th>Brand / owner</th><th>Original source</th><th>Received</th><th>Used</th><th>Remaining</th><th>Status</th></tr></thead><tbody>'+d.diagnostics.map(x=>'<tr><td>'+esc(x.brand)+' · '+esc(x.owner)+'</td><td>'+esc(x.reference)+'</td><td>'+esc(x.received)+'</td><td>'+esc(x.used)+'</td><td>'+esc(x.remaining)+'</td><td>'+esc(x.reason)+'</td></tr>').join('')+'</tbody></table>';
 }catch(e){if(token===choiceSequence){refreshBrandDropdown();const node=$('productionBagChoicesMessage');if(node){node.textContent=e.message;node.style.color='#b42318';}}}
}
function choices(){try{return choiceData?.stamp===JSON.stringify(choiceScope())?choiceData.rows:[];}catch{return [];}}
function updateAdd(){const button=$('productionAddBrand');if(button)button.disabled=!$('brandType')?.value||!choices().some(r=>r.lotId===$('brandChoice')?.value);}
function refresh(){if(!$('brandChoice'))return;const prior=$('brandChoice').value,rows=choices();$('brandChoice').innerHTML='<option value="">Select received bag marking</option>'+($('brandType').value?rows.map(r=>'<option value="'+esc(r.lotId)+'">'+esc(r.brand+' · '+r.packingKg+' kg · '+r.owner+' · '+r.available+' bags')+'</option>').join(''):'');$('brandChoice').value=prior;updateAdd();}
function add(){const c=choices().find(r=>r.lotId===$('brandChoice').value),type=$('brandType').value;if(!type||!c)return alert('Select the type and an available received bag marking first.');const product=(type==='rice'?'Ready Rice — ':'B2 — ')+c.brand;if(productionRows.some(r=>r.product===product&&r.bagSourceLotId===c.lotId&&Number(r.bagWeight)===Number(c.packingKg)))return alert('This source lot and packing are already in the production entry.');let id=Date.now();while(productionRows.some(r=>r.id===id))id++;productionRows.push({id,product,bags:0,bagWeight:c.packingKg,broken:brandBrokenSpec(c.brand,c.exporterDealId),exporterDealId:c.exporterDealId||'',bagSourceLotId:c.lotId});productionGroupDelete[type==='rice'?'ready':'b2']=true;renderProductionTable();calcProduction();refresh();}
function orders(){try{return choiceData?.stamp===JSON.stringify(choiceScope())?choiceData.orders||[]:[];}catch{return [];}}
function orderLabel(d){return [d.brand||'Brand not entered',d.historicalClosed?'Historical closed SODA':'',d.entity,d.party,d.quantityMT==null?'':Number(d.quantityMT).toLocaleString('en-PK')+' MT',d.sourceKind==='EXPORT'?d.contractRef:d.sourceKind==='LOCAL'?'Local SODA':d.sourceKind==='BAG'?'Bag marking · SODA link unverified':'Fellow exporter'].filter(Boolean).join(' · ');}
function orderOptions(row){return '<option value="">Choose brand / SODA</option><option value="LOCAL"'+(['Ready Rice — Local Bags','B2 — LOCAL'].includes(row.product)?' selected':'')+'>Local / unbranded</option>'+orders().filter(d=>!d.historicalClosed).map(d=>'<option value="'+esc(d.orderId)+'"'+(row.productionOrderId===d.orderId?' selected':'')+'>'+esc(orderLabel(d))+'</option>').join('')+'<option value="ADD_LINE">＋ Bag / brand row</option><option value="ADD_BRAND">＋ Register missing brand</option>'+(!orders().some(d=>!d.historicalClosed&&d.orderId===row.productionOrderId)&&/^(Ready Rice|B2) — (?!Local(?: Bags)?$)/i.test(row.product)?'<option value="SAVED" selected>'+esc(row.product.replace(/^(Ready Rice|B2) — /,''))+' · saved assignment</option>':'');}
function orderSelect(row){
 const kind=String(row.product).startsWith('B2 — ')?'B2':'Ready Rice';
 return '<label>'+kind+'<select aria-label="'+kind+' brand / SODA" data-production-order="'+esc(row.id)+'" onchange="window.TT_BAG_CHOICES.assign('+Number(row.id)+',this.value)">'+orderOptions(row)+'</select></label>';
}
function refreshOrders(){
 for(const row of productionRows){
  if(!String(row.product).startsWith('Ready Rice')&&!String(row.product).startsWith('B2 — '))continue;
  const cell=document.getElementById('pkg'+row.id)?.closest('tr')?.querySelector('td');
  if(cell&&!cell.querySelector('[data-production-order]'))cell.innerHTML=orderSelect(row);
 }
 document.querySelectorAll('[data-production-order]').forEach(select=>{
  const row=productionRows.find(r=>String(r.id)===select.dataset.productionOrder);
  if(row)select.innerHTML=orderOptions(row);
 });
}
function assign(id,value){const row=productionRows.find(r=>r.id===id);if(!row||value==='SAVED')return;if(value==='ADD_LINE'){String(row.product).startsWith('B2 — ')?addBroken():addReady();return;}if(value==='ADD_BRAND'){refreshOrders();openBrand();return;}const d=orders().find(d=>d.orderId===value);if(value!=='LOCAL'&&!d){refreshOrders();return;}if(d&&!d.brand){alert('Register the missing brand against this SODA first.');refreshOrders();return;}
 const kind=String(row.product).startsWith('B2 — ')?'B2':'Ready Rice',brand=d?.brand||(kind==='B2'?'LOCAL':'Local Bags');row.product=kind+' — '+brand;row.brand=brand;row.productionOrderId=d?.orderId||'';row.productionOrderSource=d?.sourceKind||'LOCAL';row.productionSodaId=d?.sourceKind==='FELLOW'?d.sourceId:'';row.exporterDealId=d?.sourceKind==='FELLOW'?(d.bagResponsibility?.owner==='OUR'?'':d.sourceId):d?.exporterDealId||'';row.contractRef=d?.contractRef||'';row.productionOrderVersion=d?.version??null;delete row.bagSourceLotId;if(d?.bagSourceLotId)row.bagSourceLotId=d.bagSourceLotId;
 const lots=choices().filter(c=>norm(c.brand)===norm(brand)&&(row.exporterDealId?c.exporterDealId===row.exporterDealId:!c.exporterDealId)&&(!row.contractRef||!c.contractRef||c.contractRef===row.contractRef));
 const weights=[...new Set([...String(d?.packing||'').matchAll(/\b([0-9]+(?:\.[0-9]+)?)\s*(?:kgs?|kilograms?)\b/gi)].map(match=>Number(match[1])))];
 if(d&&!Number(row.bags)){
  if(weights.length===1)row.bagWeight=weights[0];
  else if(lots.length===1)row.bagWeight=lots[0].packingKg;
 }
 const matching=lots.filter(lot=>Number(lot.packingKg)===Number(row.bagWeight));
 if(!row.bagSourceLotId&&matching.length===1)row.bagSourceLotId=matching[0].lotId;

 renderProductionTable();calcProduction();
}
function addReady(){let id=Date.now();while(productionRows.some(r=>r.id===id))id++;productionRows.push({id,product:'Ready Rice',bags:0,bagWeight:window.productionWeightDefault?.('Ready Rice')||50});productionGroupDelete.ready=true;renderProductionTable();calcProduction();}
function addBroken(){let id=Date.now();while(productionRows.some(r=>r.id===id))id++;productionRows.push({id,product:'B2 — LOCAL',bags:0,bagWeight:window.productionWeightDefault?.('B2 — LOCAL')||50});productionGroupDelete.b2=true;renderProductionTable();calcProduction();}
function summary(){document.getElementById('productionShiftSummary')?.remove();}
function openBrand(){let host=$('productionRegisterBrand');if(!host){host=document.createElement('section');host.id='productionRegisterBrand';host.className='card';host.innerHTML='<h3>Register brand</h3><div class="r3"><label>Company<select id="productionBrandCompany"></select></label><label>Running SODA<select id="productionBrandOrder"></select></label><label>Actual brand name<input id="productionBrandName" maxlength="200"></label></div><div class="actions"><button type="button" class="ok" id="productionBrandSave">Save brand</button><button type="button" class="sec" id="productionBrandCancel">Cancel</button></div><p role="status" id="productionBrandStatus"></p>';document.querySelector('#production .prod-head')?.closest('.card')?.after(host);$('productionBrandCancel').onclick=()=>{host.hidden=true;};$('productionBrandSave').onclick=()=>void saveBrand();$('productionBrandCompany').onchange=async()=>{const next=$('productionBrandCompany').value;if(next!==$('prodEntity').value&&productionRows.some(r=>Number(r.bags)>0||Number(r.systemKg)>0)){ $('productionBrandCompany').value=$('prodEntity').value;$('productionBrandStatus').textContent='Save the current company production before switching company.';return;}$('prodEntity').value=next;await loadChoices();brandOrderOptions();};}
 host.hidden=false;$('productionBrandCompany').innerHTML=$('prodEntity').innerHTML;$('productionBrandCompany').value=$('prodEntity').value;brandOrderOptions();window.TT_FORM_VIEWPORT?.open(host);
}
function brandOrderOptions(){const select=$('productionBrandOrder');if(select)select.innerHTML='<option value="">Select running SODA</option>'+orders().filter(d=>!d.parentOrderId&&d.sourceKind!=='BAG').map(d=>'<option value="'+esc(d.orderId)+'">'+esc(orderLabel(d))+'</option>').join('');}
let brandIntent=null;
async function saveBrand(){const button=$('productionBrandSave'),status=$('productionBrandStatus');try{const d=orders().find(d=>d.orderId===$('productionBrandOrder').value),brand=$('productionBrandName').value.trim();if(!d||!brand)throw Error('Choose the company, running SODA and actual brand.');const facts={action:'register_production_brand',purpose:'production',...scope(true),asOf:$('prodDate')?.value||today(),orderId:d.orderId,version:d.version,brand};const signature=JSON.stringify(facts);if(!brandIntent||brandIntent.signature!==signature)brandIntent={signature,body:{...facts,requestKey:crypto.randomUUID()}};button.disabled=true;await request(brandIntent.body);brandIntent=null;await loadChoices();$('productionRegisterBrand').hidden=true;window.showNonBlockingMessage?.('Brand saved. It is now available under Ready Rice. No bag stock was created.');}catch(e){status.textContent=e.message;status.style.color='#b42318';}finally{button.disabled=false;}}
function init(){
 const root=$('otherExporterBags');if(!root)return;
 document.querySelectorAll('[name=exportBagOwnership]').forEach(r=>r.onchange=()=>toggle(r.value));$('otherBagExporter').onchange=()=>renderSodas();$('otherBagSoda').onchange=choose;$('otherBagRefresh').onclick=()=>void open();$('otherBagReceive').onclick=()=>void receive();$('otherBagRetry').onclick=()=>{try{recover();if(pending)void send(pending.body,true);}catch(e){message(e.message,true);}};$('otherBagDate').value=today();
 const bar=document.createElement('div');bar.className='production-bag-tools';bar.innerHTML='<details><summary>Bag stock / refresh</summary><button type="button" class="sec" id="refreshProductionBags">Refresh received bags</button><p id="productionBagChoicesMessage" role="status"></p><details id="bagChoiceDiagnostics" style="overflow-x:auto"></details></details>';$('brandChoice')?.closest('.r3')?.insertAdjacentElement('afterend',bar);$('refreshProductionBags').onclick=()=>void loadChoices();$('brandChoice')?.addEventListener('change',updateAdd);
 const instructionHost=document.createElement('section');instructionHost.id='productionFellowInstructions';instructionHost.className='production-instructions';instructionHost.hidden=true;document.querySelector('#production .prod-head')?.closest('.card')?.append(instructionHost);
 let selectedCompany=$('prodEntity')?.value;
 $('prodEntity')?.addEventListener('focus',()=>{selectedCompany=$('prodEntity').value;});
 $('prodEntity')?.addEventListener('change',()=>{if(productionRows.some(r=>Number(r.bags)>0||Number(r.systemKg)>0)){ $('prodEntity').value=selectedCompany||currentMill.entity;window.refreshReprocessingStock?.();alert('Save or clear the current production before changing its company.');return;}selectedCompany=$('prodEntity').value;window.renderRecentProduction?.();window.TT_PRODUCTION_RECOVERY?.reset();window.TT_PRODUCTION_RECOVERY?.outputs(productionRows);void loadChoices();});$('prodDate')?.addEventListener('change',()=>void loadChoices());$('prodVariety')?.addEventListener('change',()=>{for(const row of productionRows)if(!Number(row.bags)&&!Number(row.systemKg)&&!row.productionOrderId)row.bagWeight=window.productionWeightDefault?.(row.product)||50;renderProductionTable();calcProduction();});
 const original=window.openPanel;window.openPanel=function(id){const result=original.apply(this,arguments);if(id==='production'){void loadChoices();window.TT_PRODUCTION_RECOVERY?.offerDeviceDraft?.();}if(id==='newbags')window.TT_FORM_VIEWPORT?.open($('newbags'));if(id==='newbags'&&document.querySelector('[name=exportBagOwnership]:checked')?.value==='other')void open();return result;};
}
function addLocal(){if(productionRows.some(r=>r.product==='Ready Rice — Local Bags'))return;productionRows.push({id:Date.now(),product:'Ready Rice — Local Bags',bags:0,bagWeight:window.productionWeightDefault?.('Ready Rice')||50});productionGroupDelete.ready=true;renderProductionTable();calcProduction();}
window.TT_OTHER_EXPORTER_BAGS={open,toggle};window.TT_BAG_CHOICES={load:loadChoices,choices,instructions,orders,orderSelect,assign,addReady,addBroken,openBrand,summary,refresh,add,addLocal};
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
