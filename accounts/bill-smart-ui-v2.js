(() => {
  'use strict';
  const access = window.TT_ACCOUNT_ACCESS || {};
  const lookupApi = '../api/commodity_lookup.php';
  const billApi = '../api/commodity_bills.php';
  const workflowApi = '../api/accounts_workflows_v1.php';
  const q = selector => document.querySelector(selector);
  const qa = selector => [...document.querySelectorAll(selector)];
  const entity = () => localStorage.getItem('tt_accounts_entity') || 'TTI';
  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
  const fmt = value => Number(value || 0).toLocaleString('en-PK', {maximumFractionDigits:2});
  const today = () => new Intl.DateTimeFormat('en-CA', {timeZone:'Asia/Karachi',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
  const state = {receipts:[], bills:[], loadedEntity:'', broker:'', supplier:'', relationshipType:'', relationshipName:'', soda:'', sourceKey:'', multiple:false, sourceKeys:[], adjustmentLines:[], brokerageRate:'', brokerageBasis:'PER_100_KG', kanta:'', loadingRate:'', filling:'', bags:null, bagWeightGrams:null, whtPercent:15, billDate:'', billNo:'', remarks:''};
  let loading = false;

  function toast(message, ok = true) {
    let el = q('#ttSmartBillToast');
    if (!el) { el = document.createElement('div'); el.id = 'ttSmartBillToast'; el.style.cssText = 'position:fixed;left:50%;bottom:22px;transform:translateX(-50%);z-index:100080;padding:11px 15px;border-radius:10px;color:#fff;font:700 12px Arial;box-shadow:0 8px 25px #0003'; document.body.appendChild(el); }
    el.style.background = ok ? '#147a5b' : '#a93a34'; el.textContent = message; el.hidden = false; clearTimeout(el._t); el._t = setTimeout(() => { el.hidden = true; }, 3500);
  }
  function style() {
    if (q('#ttSmartBillStyleV3')) return;
    const sheet = document.createElement('style'); sheet.id = 'ttSmartBillStyleV3';
    sheet.textContent = `.ttsb-wrap{padding:4px 0 18px}.ttsb-card{border:1px solid #dfe6ec;border-radius:13px;background:#fff;padding:17px;margin-bottom:13px}.ttsb-title{display:flex;gap:12px;align-items:flex-start}.ttsb-title>div{flex:1}.ttsb-title h3{margin:0}.ttsb-title p{margin:4px 0 0;color:#6c7886;font-size:12px}.ttsb-steps{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:15px}.ttsb-step{border:1px solid #dce4eb;border-radius:11px;padding:12px;background:#fbfcfd}.ttsb-step.active{border-color:#6b9981;background:#f7fcf9}.ttsb-step label{font-size:11px;font-weight:800;color:#526173}.ttsb-step select,.ttsb-step input,.ttsb-form input,.ttsb-form textarea,.ttsb-form select{width:100%;margin-top:5px;padding:10px;border:1px solid #cfd8e1;border-radius:8px;background:#fff}.ttsb-form{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.ttsb-form label{font-size:11px;font-weight:800;color:#526173}.ttsb-full{grid-column:1/-1}.ttsb-summary{display:grid;grid-template-columns:repeat(3,1fr);gap:9px;margin:13px 0}.ttsb-kpi{border:1px solid #e1e6eb;border-radius:9px;padding:10px;background:#fafbfc}.ttsb-kpi span{display:block;font-size:9px;text-transform:uppercase;color:#6b7885}.ttsb-kpi b{display:block;margin-top:4px;font-size:16px}.ttsb-disclose{margin-top:12px;border:1px solid #e1e7ed;border-radius:10px;background:#fff}.ttsb-disclose summary{cursor:pointer;padding:11px 12px;font-weight:800}.ttsb-disclose>div{padding:0 12px 12px}.ttsb-lines{border:1px solid #e1e7ed;border-radius:10px;overflow:hidden}.ttsb-line{display:grid;grid-template-columns:.7fr 1.8fr .8fr 36px;gap:8px;padding:9px;border-bottom:1px solid #edf0f3;align-items:end}.ttsb-line:last-child{border-bottom:0}.ttsb-line button{height:36px;border:0;border-radius:8px;background:#fff0f0;color:#9b3333;font-weight:900}.ttsb-actions{display:flex;gap:9px;justify-content:flex-end;margin-top:14px}.ttsb-accounting{margin-top:13px;border:1px solid #bad0dc;background:#eef6fa;border-radius:10px;padding:12px}.ttsb-postline{display:grid;grid-template-columns:60px 1fr auto;gap:8px;padding:4px 0;font-size:12px}.ttsb-posting{position:fixed;inset:0;z-index:100090;background:#0b1b2b88;display:grid;place-items:center;padding:20px}.ttsb-posting>div{width:min(520px,100%);background:#fff;border-radius:18px;padding:26px;text-align:center;box-shadow:0 24px 70px #0005}.ttsb-posting strong{display:block;font-size:64px;color:#28523d;line-height:1;margin:18px}.ttsb-posting h2{margin:0}.ttsb-empty{padding:22px;text-align:center;color:#6c7886}.ttsb-recent table{width:100%;border-collapse:collapse}.ttsb-recent th,.ttsb-recent td{padding:8px;border-bottom:1px solid #edf0f3;text-align:left;font-size:11px}@media(max-width:850px){.ttsb-form,.ttsb-summary{grid-template-columns:1fr 1fr}.ttsb-line{grid-template-columns:1fr 1fr}}@media(max-width:560px){.ttsb-steps,.ttsb-form,.ttsb-summary,.ttsb-line{grid-template-columns:1fr}}`;
    sheet.textContent += `.ttsb-step>select{width:100%;padding:10px;border:1px solid #cfd8e1;border-radius:8px}.ttsb-selector-title{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}.ttsb-combine{display:flex;align-items:center;gap:6px}.ttsb-combine input,.ttsb-truck input{width:16px!important;min-height:16px!important;margin:0!important;padding:0!important;flex:none}.ttsb-trucks{margin-top:8px;border:1px solid #dfe6ec;border-radius:8px}.ttsb-truck{display:grid!important;grid-template-columns:16px 1fr auto auto;align-items:center;gap:8px;padding:8px!important;margin:0!important;border-bottom:1px solid #edf0f3}.ttsb-truck:last-child{border:0}.ttsb-due{font-size:21px!important}.ttsb-calculation{border:1px solid #dfe6ec;border-radius:10px;padding:12px}.ttsb-calc-row{display:grid;grid-template-columns:1.3fr 1fr 1fr 1fr;gap:12px;align-items:center;padding:9px 0;border-bottom:1px solid #edf0f3}.ttsb-calc-row label{font-size:11px;font-weight:700}.ttsb-calc-row input,.ttsb-calc-row select{box-sizing:border-box;width:100%;padding:8px;border:1px solid #cfd8e1;border-radius:6px;margin-top:4px}.ttsb-calc-row>b:last-child{text-align:right}.ttsb-calc-total{grid-template-columns:1fr 1fr;font-size:17px}.ttsb-disclose h3{padding:10px 12px;margin:0;font-size:14px}@media(max-width:650px){.ttsb-calc-row{grid-template-columns:1fr 1fr}.ttsb-truck{grid-template-columns:16px 1fr}.ttsb-due{font-size:17px!important}}`;
    sheet.textContent+=`.ttsb-line{grid-template-columns:36px .7fr 1.8fr .8fr}.ttsb-summary .ttsb-kpi:last-child{text-align:right}.ttsb-calculation .ttsb-line{grid-template-columns:36px calc((100% - 36px - 36px)*.18) 1fr calc((100% - 36px)*.2325);padding:9px 0}.ttsb-calculation [data-line-amount],#ttsbBrokerage,#ttsbWithAmt,#ttsbLoadingTotal{text-align:right;font-weight:800;font-variant-numeric:tabular-nums}.ttsb-calculation input{box-sizing:border-box}.ttsb-calculation .ttsb-calc-row>:last-child:not(:first-child){grid-column:4;text-align:right}.ttsb-calculation .ttsb-calc-row>#ttsbNetWeight{grid-column:2/5;text-align:left}.ttsb-calculation .ttsb-calc-total{grid-template-columns:1.3fr 1fr 1fr 1fr}.ttsb-amount input{width:100%}@media(max-width:650px){.ttsb-calculation .ttsb-line{grid-template-columns:36px 1fr 1fr 1fr}.ttsb-calculation .ttsb-calc-row,.ttsb-calculation .ttsb-calc-total{grid-template-columns:1.3fr 1fr 1fr 1fr;gap:6px}.ttsb-calculation{overflow-x:auto}.ttsb-calc-row,.ttsb-calculation .ttsb-line{min-width:600px}}`;
    document.head.appendChild(sheet);
  }
  async function load(force = false) {
    const company = entity();
    if (loading || (!force && state.loadedEntity === company)) return;
    loading = true;
    try {
      const response = await fetch(`${lookupApi}?entity=${encodeURIComponent(company)}`, {credentials:'same-origin', headers:{Accept:'application/json'}});
      let body = {}; try { body = await response.json(); } catch (_) {}
      if (!response.ok || !body.ok) throw new Error(body.error || 'Could not load saved Pohanch records.');
      state.receipts = Array.isArray(body.receipts) ? body.receipts : [];
      state.bills = Array.isArray(body.bills) ? body.bills : [];
      state.sodas = Array.isArray(body.sodas) ? body.sodas : [];
      state.brokers = Array.isArray(body.brokers) ? body.brokers : [];
      state.loadedEntity = company;
    } finally { loading = false; }
  }
  const openRows = () => state.receipts.filter(row => !row.billed);
  const meaningful = value => { const text = String(value || '').trim(); return text && !/^(select|type to search|no matching)/i.test(text); };
  const unique = values => [...new Set(values.map(value => String(value || '').trim()).filter(Boolean))].sort((a,b) => a.localeCompare(b));
  const brokers = () => unique(openRows().filter(row => !state.supplier || row.party === state.supplier).map(row => meaningful(row.broker) ? row.broker : ''));
  const suppliers = () => unique(openRows().filter(row => !state.broker || row.broker === state.broker).map(row => meaningful(row.party) ? row.party : ''));
  function relatedRows() {
    if (!state.broker && !state.supplier) return [];
    return openRows().filter(row => (!state.broker || row.broker === state.broker) && (!state.supplier || row.party === state.supplier));
  }
  const sodas = () => unique(relatedRows().map(row => row.soda));
  const truckRows = () => relatedRows().filter(row => String(row.soda) === state.soda);
  const selected = () => truckRows().find(row => row.sourceKey === state.sourceKey) || null;
  const selectedRows = () => state.multiple ? truckRows().filter(row => state.sourceKeys.includes(row.sourceKey)) : (selected() ? [selected()] : []);
  const readyRice = () => selected()?.commodity === 'RICE' && String(selected()?.productStage || '').toUpperCase() === 'READY';
  const mayCombine = () => {const row=selected();return row&&(String(row.productStage||'').toUpperCase()==='READY'||(row.commodity==='CORN'&&String(row.sourceKey).startsWith('EXMILL|')));};
  const approvedSoda = () => (state.sodas || []).find(row => String(row.sodaNo) === state.soda);
  const payee = () => { const soda = approvedSoda() || selected() || {}; const supplier = String(soda.party || '').trim(), broker = String(soda.broker || '').trim(); return {type:supplier ? 'SUPPLIER' : 'BROKER', name:supplier || broker, broker}; };
  function option(value, selectedValue, label = value) { return `<option value="${esc(value)}" ${String(value) === String(selectedValue) ? 'selected' : ''}>${esc(label)}</option>`; }
  function resetAfterRelationship() { state.soda = ''; resetAfterSoda(); }
  function resetAfterSoda() { state.sourceKey = ''; state.multiple=false;state.sourceKeys=[];state.adjustmentLines = []; state.brokerageRate=''; state.kanta=''; state.loadingRate=''; state.filling=''; state.bags=null;state.bagWeightGrams=null;state.whtPercent=15;state.billDate='';state.billNo='';state.remarks=''; }
  function searchChoice(id, label, values, value) {
    return `<label>${label}<input id="${id}" list="${id}Choices" value="${esc(value)}" placeholder="Type to search ${label.toLowerCase()}" autocomplete="off"><datalist id="${id}Choices">${values.map(name=>option(name,'')).join('')}</datalist></label>`;
  }
  function relationSteps() {
    return `<div class="ttsb-steps"><div class="ttsb-step">${searchChoice('ttsbBroker','Broker',brokers(),state.broker)}</div><div class="ttsb-step">${searchChoice('ttsbSupplier','Supplier',suppliers(),state.supplier)}</div></div>
      ${state.broker || state.supplier ? `<div class="ttsb-steps"><div class="ttsb-step active"><label>Soda<select id="ttsbSoda"><option value="">Choose Soda</option>${sodas().map(no => option(no,state.soda,`Soda ${no}`)).join('')}</select></label></div>${state.soda ? `<div class="ttsb-step active"><div class="ttsb-selector-title"><b>Truck / Container / Pohanch</b>${mayCombine()?`<label class="ttsb-combine"><input id="ttsbMultiple" type="checkbox" ${state.multiple?'checked':''}> COMBINE THE BILLS IN THIS SODA</label>`:''}</div><select id="ttsbTruck" data-open-receipt><option value="">Choose saved container or Pohanch</option>${truckRows().map(row => option(row.sourceKey,state.sourceKey,`${row.truck || row.container || 'Truck'} — ${row.pohanch || 'Pohanch'}`)).join('')}</select>${state.multiple?`<div class="ttsb-trucks">${truckRows().filter(item=>item.commodity===selected()?.commodity).map(item=>`<label class="ttsb-truck"><input data-bill-source="${esc(item.sourceKey)}" type="checkbox" ${state.sourceKeys.includes(item.sourceKey)?'checked':''}><span>${esc(item.truck||'')} ${esc(item.container||item.pohanch||'')}</span><span>${fmt(item.weighbridgeWeightKg||item.payableWeightKg)} kg</span><b>PKR ${fmt(item.provisionalAmount)}</b></label>`).join('')}</div>`:''}</div>` : ''}</div>` : ''}`;
  }
  function bagDefaults() {
    const rows=selectedRows(), bags=rows.reduce((sum,row)=>sum+Number(row.bags||0),0);
    const emptyKg=rows.reduce((sum,row)=>sum+Number(row.bags||0)*Number(row.emptyBagWeightGrams||0)/1000,0);
    return {bags,grams:bags?emptyKg*1000/bags:0};
  }
  function riceQuantity() {
    const defaults=bagDefaults(), bags=Number(state.bags ?? defaults.bags), grams=Number(state.bagWeightGrams ?? defaults.grams);
    const gross=selectedRows().reduce((sum,row)=>sum+Number(row.weighbridgeWeightKg ?? row.payableWeightKg ?? 0),0), emptyKg=bags*grams/1000;
    return {bags,grams,gross,emptyKg,net:Math.max(0,gross-emptyKg)};
  }
  function paymentSummary() {
    const soda=approvedSoda()||{}, term=String(soda.paymentTermType||'').toUpperCase(),days=term==='CASH'?2:Number(soda.creditDays||0);
    const dates=selectedRows().map(row=>{const d=new Date(row.date+'T00:00:00Z');if(Number.isNaN(d.getTime()))return '';d.setUTCDate(d.getUTCDate()+days);return d.toISOString().slice(0,10);}).filter(Boolean).sort();
    const display=date=>date?.split('-').reverse().join('-')||'—';
    return {term:term==='CASH'?'Cash':term==='CREDIT'?`Credit · ${days} days`:'Payment term unavailable',due:dates.length?display(dates[0])+(dates.at(-1)!==dates[0]?` – ${display(dates.at(-1))}`:''):'—'};
  }
  function lineHtml(line, index) {
    return `<div class="ttsb-line" data-adjustment-line="${index}"><button type="button" data-line-remove title="Delete line">−</button><label>Type<select data-line-kind><option value="ADDITION" ${line.kind === 'ADDITION' ? 'selected' : ''}>Addition</option><option value="DEDUCTION" ${line.kind === 'DEDUCTION' ? 'selected' : ''}>Deduction</option></select></label><label>Description<input data-line-description value="${esc(line.description || '')}" placeholder="Kanta, filling, allowance, quality adjustment..."></label><label>Amount (PKR)<input data-line-amount inputmode="decimal" value="${esc(line.amount || '')}"></label></div>`;
  }
  function totals() {
    const row=selected(), calculated=Number(q('#ttsbFinal')?.value||0),base=readyRice()?riceQuantity().gross*Number(row.grossRatePerKg||0):row&&row.commodity!=='RICE'&&calculated>0?calculated:selectedRows().reduce((sum,item)=>sum+Number(item.provisionalAmount||0),0);
    let add=0,deduct=0;
    state.adjustmentLines.forEach(line=>{const amount=Math.max(0,Number(line.amount||0));if(line.kind==='DEDUCTION')deduct+=amount;else add+=amount;});
    add+=Math.max(0,Number(state.kanta||0))*(readyRice()?selectedRows().length:1);
    if(readyRice())add+=selectedRows().reduce((n,r)=>n+Number(r.bags||0),0)*Math.max(0,Number(state.loadingRate||0));
    deduct+=readyRice()?riceQuantity().emptyKg*Number(row?.grossRatePerKg||0):Math.max(0,Number(state.filling||0));
    return {base,add,deduct,final:Math.round((base+add-deduct)*100)/100};
  }
  function readyCalculation(row) {
    const quantity=riceQuantity();
    return `<div class="ttsb-calculation"><div class="ttsb-calc-row"><b>LESS: EMPTY BAG WEIGHT</b><label>Total Bags<input id="ttsbBags" type="number" min="0" step="1" value="${quantity.bags}"></label><label>Weight of Empty Bag (grams)<input id="ttsbBagWeight" type="number" min="0" step=".001" value="${quantity.grams}"></label><b id="ttsbBagDeduction">PKR ${fmt(quantity.emptyKg*Number(row.grossRatePerKg||0))}</b></div><div class="ttsb-calc-row"><b>NET RICE QUANTITY</b><span id="ttsbNetWeight">${fmt(quantity.gross)} − ${fmt(quantity.emptyKg)} = ${fmt(quantity.net)} kg</span></div>${brokeryFields(row)}<div class="ttsb-calc-row"><b>ADD: KANTA</b><label>Rate per Truck / Container<input id="ttsbKanta" type="number" min="0" step=".01" value="${esc(state.kanta)}"></label><span>${selectedRows().length} truck(s) / container(s)</span><b id="ttsbKantaTotal">PKR 0</b></div><div class="ttsb-calc-row"><b>ADD: LOADING CHARGES</b><label>Number of Bags<input id="ttsbLoadingBags" readonly value="${selectedRows().reduce((n,r)=>n+Number(r.bags||0),0)}"></label><label>Rate per Bag<input id="ttsbLoadingRate" type="number" min="0" step=".01" value="${esc(state.loadingRate)}"></label><label class="ttsb-amount">TOTAL<input id="ttsbLoadingTotal" readonly value="0.00"></label></div><div id="ttsbAdjustmentLines">${state.adjustmentLines.map(lineHtml).join('')}</div><div class="ttsb-actions" style="justify-content:flex-start"><button class="btn" type="button" id="ttsbAddAddition">+ Addition</button><button class="btn" type="button" id="ttsbAddDeduction">− Deduction</button></div><div class="ttsb-calc-row ttsb-calc-total"><b>BILL AMOUNT</b><b id="ttsbBillAmount">PKR 0</b></div><div class="ttsb-calc-row"><b>LESS: WHT ON BROKERY</b><label>WHT %<input id="ttsbWithPct" type="number" min="0" max="100" step=".01" value="${state.whtPercent}"></label><input id="ttsbWithAmt" readonly value="0"></div></div>`;
  }
  function brokeryFields(row) {
    return `<div class="ttsb-calc-row"><b>ADD: BROKERY</b><label>Rate<input id="ttsbRate" type="number" min="0" step=".01" value="${esc(state.brokerageRate)}" ${payee().broker?'':'disabled'}></label><label>Per<select id="ttsbBasis"><option value="PER_100_KG">100 kg</option>${row.commodity==='RICE'?'':'<option value="PER_50_KG_BAG">50 kg</option><option value="PER_MAUND">Maund (40 kg)</option>'}</select></label><input id="ttsbBrokerage" readonly value="0"></div>`;
  }
  function accounting(row) {
    const value = totals().final;
    return `<div class="ttsb-accounting"><strong>Accounting treatment before posting</strong><div class="ttsb-postline"><b>Debit</b><span>Commodity inventory / purchase adjustment</span><strong id="ttsbPreviewValueDr">PKR ${fmt(value)}</strong></div><div class="ttsb-postline"><b>Credit</b><span>${esc(payee().name)} payable</span><strong id="ttsbPreviewValueCr">PKR ${fmt(value)}</strong></div><div class="ttsb-postline" id="ttsbPreviewBrokery" hidden><b>Debit</b><span>Buying brokery</span><strong></strong></div><div class="ttsb-postline" id="ttsbPreviewBrokerPayable" hidden><b>Credit</b><span>${esc(row.broker || 'Broker')} payable after WHT</span><strong></strong></div><div class="ttsb-postline" id="ttsbPreviewWithholding" hidden><b>Credit</b><span>Broker WHT payable</span><strong></strong></div><div class="helper">The bill posts gross brokerage, the broker’s net payable and WHT at the same time.</div></div>`;
  }
  function billForm(row) {
    if (!row) return '';
    const value = totals(), exMill=String(row.sourceKey||'').startsWith('EXMILL|');
    return `<div class="ttsb-card ttsb-bill" data-bill-layout="${readyRice()?'ready':'ordinary'}"><div class="ttsb-title"><div><h3>Complete Bill</h3><p>${exMill?'The approved Soda and saved container weighbridge weight supply the accounting quantity. No Pohanch or KAT applies.':'The selected Soda and Pohanch supply the operational details.'} Enter only the final bill information.</p></div></div><div class="ttsb-form" style="margin-top:14px"><label>Bill Date<input id="ttsbBillDate" type="date" value="${state.billDate||today()}"></label><label>Supplier / Broker Bill No.<input id="ttsbBillNo" value="${esc(state.billNo)}" placeholder="Optional if none printed"></label><label>Soda<input readonly value="${esc(row.soda)}"></label><label>${exMill?'Truck / Container':'Truck / Pohanch'}<input readonly value="${state.multiple?esc(selectedRows().length+' selected'):esc(row.truck+' — '+row.pohanch)}"></label><label>Product<input readonly value="${esc(row.displayName || row.variety || row.commodity)}"></label><label>${exMill?'Container Weighbridge Weight':'Accepted Weight'}<input readonly value="${fmt(selectedRows().reduce((sum,item)=>sum+Number(item.payableWeightKg||0),0))} kg"></label><label>Soda Rate<input readonly value="${fmt(row.grossRatePerKg)} / ${row.commodity==='CORN'?'maund (40 kg)':'kg'}"></label>${exMill?'':`<label>KAT<input readonly value="${fmt(row.katPaisaPerKg)} paisa / kg"></label>`}<label>Calculated Commodity Value<input id="ttsbFinal" readonly value="${value.base.toFixed(2)}"></label></div>
      <div class="ttsb-summary"><div class="ttsb-kpi"><span>Payment Term</span><b>${esc(paymentSummary().term)}</b></div><div class="ttsb-kpi"><span>Due Date</span><b class="ttsb-due">${esc(paymentSummary().due)}</b></div><div class="ttsb-kpi"><span>Total Commodity Value</span><b>PKR ${fmt(value.base)}</b></div></div>
      ${readyRice()?readyCalculation(row):`
      <div class="ttsb-disclose"><h3>Additions / Deductions</h3><div><div class="ttsb-lines" id="ttsbAdjustmentLines">${state.adjustmentLines.length ? state.adjustmentLines.map(lineHtml).join('') : '<div class="ttsb-empty">No additions or deductions. Add a line only when it appears on the bill.</div>'}</div><div class="ttsb-actions" style="justify-content:flex-start"><button class="btn" type="button" id="ttsbAddAddition">+ Addition</button><button class="btn" type="button" id="ttsbAddDeduction">+ Deduction</button></div></div></div>
      <div class="ttsb-disclose"><h3>Buying Brokery</h3>${brokeryFields(row)}<div class="ttsb-form"><label>Brokery WHT %<input id="ttsbWithPct" type="number" min="0" max="100" step=".01" value="${state.whtPercent}"></label><label>WHT payable<input id="ttsbWithAmt" readonly value="0"></label><label>Kanta / Weight Charges (+)<input id="ttsbKanta" type="number" min="0" step=".01" value="${esc(state.kanta)}"></label><label>Filling Charges (−)<input id="ttsbFilling" type="number" min="0" step=".01" value="${esc(state.filling)}"></label></div></div>`}
      <div class="ttsb-kpi" style="margin-top:12px;text-align:right"><span>FINAL BILL PAYABLE</span><b id="ttsbGrandTotal" style="font-size:24px;text-decoration:underline">PKR ${fmt(value.final)}</b></div><div class="ttsb-form" style="margin-top:12px"><label class="ttsb-full">Remarks / Bill Explanation<textarea id="ttsbRemarks">${esc(state.remarks)}</textarea></label></div>${accounting(row)}
      <div class="ttsb-actions"><button class="btn" type="button" id="ttsbClearTruck">Choose Another Truck</button><button class="btn green" type="button" id="ttsbVerify">POST BILL</button></div></div>`;
  }
  function recent() {
    const rows = state.bills.slice().reverse().slice(0,12);
    return `<details class="ttsb-card ttsb-recent"><summary style="cursor:pointer;font-weight:800">Recent Posted Bills</summary>${rows.length ? `<table><thead><tr><th>Posting</th><th>Bill</th><th>Date</th><th>Soda</th><th>Party</th><th>Value</th></tr></thead><tbody>${rows.map(row => `<tr><td><b>${esc(row.postingNumber || '—')}</b></td><td>${esc(row.billNo || row.id)}</td><td>${esc(row.billDate || '')}</td><td>${esc((row.sodas || []).join(', '))}</td><td>${esc(row.relationshipName || row.broker || '')}</td><td>PKR ${fmt(row.finalCommodityValue)}</td></tr>`).join('')}</tbody></table>` : '<div class="ttsb-empty">No posted bills yet.</div>'}</details>`;
  }
  function render() {
    const editor = q('#purchaseEditor'); if (!editor) return; style(); editor.dataset.ttSmartBills = 'v3'; editor.dataset.ttPurchaseMode = 'arrival';
    if (entity() === 'TG') { editor.innerHTML = '<div class="ttsb-card">Pakistan Soda and Pohanch billing is available only in TTI / BRM books.</div>'; return; }
    editor.innerHTML = `<div class="ttsb-wrap"><div class="ttsb-card"><div class="ttsb-title"><div><h3>Bill Posting</h3><p>Search by broker or supplier, then choose the Soda and truck. If a supplier and broker are both present, the supplier receives the commodity bill and the broker receives brokerage separately.</p></div><b>${openRows().length} unposted</b></div>${relationSteps()}</div>${billForm(selected())}${recent()}</div>`;
    bind();
  }
  function syncLines() {
    state.adjustmentLines=qa('[data-adjustment-line]').map(line=>({kind:line.querySelector('[data-line-kind]').value,description:line.querySelector('[data-line-description]').value.trim(),amount:line.querySelector('[data-line-amount]').value}));
    state.brokerageRate=q('#ttsbRate')?.value||'';state.brokerageBasis=q('#ttsbBasis')?.value||'PER_100_KG';state.kanta=q('#ttsbKanta')?.value||'';state.loadingRate=q('#ttsbLoadingRate')?.value||'';state.filling=q('#ttsbFilling')?.value||'';state.whtPercent=Number(q('#ttsbWithPct')?.value??15);
    state.billDate=q('#ttsbBillDate')?.value||today();state.billNo=q('#ttsbBillNo')?.value||'';state.remarks=q('#ttsbRemarks')?.value||'';
    if(readyRice()){state.bags=Number(q('#ttsbBags')?.value||0);state.bagWeightGrams=Number(q('#ttsbBagWeight')?.value||0);}
    const quantity=riceQuantity(),weight=readyRice()?quantity.net:selectedRows().reduce((sum,item)=>sum+Number(item.payableWeightKg||0),0),basis=state.brokerageBasis;
    const units=basis==='PER_MAUND'?weight/40:basis==='PER_50_KG_BAG'?weight/50:weight/100;
    const brokerage=payee().broker?Math.round(Math.max(0,Number(state.brokerageRate||0))*units*100)/100:0,withholding=Math.round(brokerage*state.whtPercent)/100;
    if(q('#ttsbBrokerage'))q('#ttsbBrokerage').value=brokerage.toFixed(2);
    if(q('#ttsbWithAmt'))q('#ttsbWithAmt').value=withholding.toFixed(2);
    if(q('#ttsbLoadingTotal'))q('#ttsbLoadingTotal').value=(selectedRows().reduce((n,r)=>n+Number(r.bags||0),0)*Number(state.loadingRate||0)).toFixed(2);
    const value=totals();
    for(const [id,amount] of [['ttsbAddTotal',value.add],['ttsbDeductTotal',value.deduct],['ttsbFinalTotal',value.final],['ttsbPreviewValueDr',value.final],['ttsbPreviewValueCr',value.final],['ttsbBagDeduction',quantity.emptyKg*Number(selected()?.grossRatePerKg||0)],['ttsbKantaTotal',Number(state.kanta||0)*selectedRows().length],['ttsbBillAmount',value.final+brokerage]])if(q('#'+id))q('#'+id).textContent=`PKR ${fmt(amount)}`;
    if(q('#ttsbNetWeight'))q('#ttsbNetWeight').textContent=`${fmt(quantity.gross)} − ${fmt(quantity.emptyKg)} = ${fmt(quantity.net)} kg`;
    if(q('#ttsbGrandTotal'))q('#ttsbGrandTotal').textContent=`PKR ${fmt(value.final+brokerage-withholding)}`;
    for(const [id,amount] of [['ttsbPreviewBrokery',brokerage],['ttsbPreviewBrokerPayable',brokerage-withholding],['ttsbPreviewWithholding',withholding]]){const line=q('#'+id);if(line){line.hidden=amount<=0;line.querySelector('strong').textContent=`PKR ${fmt(amount)}`;}}
  }
  function initializeSelection(resetRate=false) {
    const defaults=bagDefaults();state.bags=defaults.bags;state.bagWeightGrams=defaults.grams;
    const profile=(state.brokers||[]).find(item=>String(item.name).toLowerCase()===payee().broker.toLowerCase());
    const rate=approvedSoda()?.buyingBrokery||profile?.rate;
    if(rate&&(resetRate||state.brokerageRate==='')){const conversion={PER_100_KG:1,PER_50_KG_BAG:2,PER_MAUND:2.5,PER_TON:.1};state.brokerageRate=String(Number(rate.amount||0)*(selected()?.commodity==='RICE'?(conversion[rate.basis]||0):1));state.brokerageBasis=selected()?.commodity==='RICE'?'PER_100_KG':rate.basis;}
  }
  function bind() {
    const broker=q('#ttsbBroker');if(broker)broker.onchange=()=>{const value=broker.value.trim();if(value&&!brokers().includes(value)){toast('Choose a broker from the linked Soda list.',false);return;}state.broker=value;const choices=suppliers();if(state.supplier&&!choices.includes(state.supplier))state.supplier='';resetAfterRelationship();render();};
    const supplier=q('#ttsbSupplier');if(supplier)supplier.onchange=()=>{const value=supplier.value.trim();if(value&&!suppliers().includes(value)){toast('Choose a supplier from the linked Soda list.',false);return;}state.supplier=value;const choices=brokers();if(state.broker&&!choices.includes(state.broker))state.broker='';if(value&&choices.length===1)state.broker=choices[0];resetAfterRelationship();render();};
    const soda=q('#ttsbSoda');if(soda)soda.onchange=()=>{state.soda=soda.value;resetAfterSoda();render();};
    const truck=q('#ttsbTruck');if(truck)truck.onchange=()=>{resetAfterSoda();state.sourceKey=truck.value;initializeSelection(true);render();};
    q('#ttsbMultiple')?.addEventListener('change',event=>{syncLines();state.multiple=event.target.checked;state.sourceKeys=state.multiple?truckRows().filter(item=>item.commodity===selected()?.commodity).map(item=>item.sourceKey):[];initializeSelection();render();});
    qa('[data-bill-source]').forEach(input=>input.onchange=()=>{syncLines();state.sourceKeys=qa('[data-bill-source]:checked').map(item=>item.dataset.billSource);initializeSelection();render();});
    q('#ttsbAddAddition')?.addEventListener('click', () => { syncLines(); state.adjustmentLines.push({kind:'ADDITION',description:'',amount:''}); render(); });
    q('#ttsbAddDeduction')?.addEventListener('click', () => { syncLines(); state.adjustmentLines.push({kind:'DEDUCTION',description:'',amount:''}); render(); });
    qa('[data-line-kind],[data-line-description],[data-line-amount]').forEach(input => { input.oninput = syncLines; input.onchange = syncLines; });
    qa('#ttsbRate,#ttsbBasis,#ttsbKanta,#ttsbLoadingRate,#ttsbFilling,#ttsbWithPct,#ttsbBags,#ttsbBagWeight,#ttsbBillDate,#ttsbBillNo,#ttsbRemarks').forEach(input=>{input.addEventListener('input',syncLines);input.addEventListener('change',syncLines);});syncLines();
    qa('[data-line-remove]').forEach(button => { button.onclick = () => { syncLines(); state.adjustmentLines.splice(Number(button.closest('[data-adjustment-line]').dataset.adjustmentLine),1); render(); }; });
    q('#ttsbClearTruck')?.addEventListener('click', () => { resetAfterSoda(); render(); });
    q('#ttsbVerify')?.addEventListener('click', postBill);
  }
  async function postBill() {
    syncLines(); const row = selected(), value = totals();
    if (!row) return toast('Choose a saved truck, container or Pohanch.', false);
    if (state.adjustmentLines.some(line => !line.description || !(Number(line.amount) > 0))) return toast('Complete or delete every addition/deduction line.', false);
    if (value.final <= 0) return toast('Final bill value must be greater than zero.', false);
    if(!selectedRows().length)return toast('Select at least one truck or container.',false);
    if(readyRice()&&(!Number.isInteger(state.bags)||state.bags<0||state.bagWeightGrams<0||riceQuantity().emptyKg>=riceQuantity().gross))return toast('Check the bag count and empty-bag weight.',false);
    const billPayee=payee();
    const payload = {action:'verify_bill',csrf:access.csrf,entity:entity(),relationshipType:billPayee.type,relationshipName:billPayee.name,billDate:q('#ttsbBillDate')?.value || today(),billNo:q('#ttsbBillNo')?.value.trim() || '',broker:row.broker || '',sourceKeys:selectedRows().map(item=>item.sourceKey),finalCommodityValue:value.final,brokerageGross:Number(q('#ttsbBrokerage')?.value || 0),brokerageWithholding:Number(q('#ttsbWithAmt')?.value||0),brokerageRate:Number(state.brokerageRate||0),brokerageBasis:state.brokerageBasis,brokerageWhtPercent:Number(q('#ttsbWithPct')?.value||0),adjustmentLines:[...state.adjustmentLines,...(!readyRice()&&Number(state.kanta)>0?[{kind:'ADDITION',description:'Kanta / Weight Charges',amount:Number(state.kanta)}]:[]),...(Number(state.filling)>0?[{kind:'DEDUCTION',description:'Filling Charges',amount:Number(state.filling)}]:[])],readyRiceCalculation:readyRice()?{bags:state.bags,emptyBagWeightGrams:state.bagWeightGrams,kantaRate:Number(state.kanta||0),loadingRatePerBag:Number(state.loadingRate||0)}:null,adjustments:{},remarks:q('#ttsbRemarks')?.value.trim() || ''};
    const button = q('#ttsbVerify'); if (button) { button.disabled = true; button.textContent = 'POSTING…'; }
    try {
      const response = await fetch(billApi,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify(payload)}); let body = {}; try { body = await response.json(); } catch (_) {}
      if (!response.ok || !body.ok) throw new Error(body.error || 'Bill could not be posted.');
      const postingNumber = body.bill?.postingNumber;
      try { await fetch(workflowApi,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify({action:'sync_late_holds',entity:entity(),csrf:access.csrf})}); } catch (_) {}
      resetAfterSoda();
      try{await load(true);render();}catch(error){toast('Bill posted. Refresh to reload the list.',false);}
      showPosting(postingNumber, body.bill?.id || '',body.warning||'');
      window.TT_ACCOUNTS_V1_WORKFLOW?.refreshAttention?.();
    } catch (error) { toast(String(error.message || error), false); if (button) { button.disabled = false; button.textContent = 'POST BILL'; } }
  }
  function showPosting(number, billId, warning) {
    const modal = document.createElement('div'); modal.className = 'ttsb-posting'; modal.innerHTML = `<div><h2>Bill Posted</h2><p>Write this posting number on the original bill.</p><strong>${esc(number || '—')}</strong><p>${esc(billId)}</p>${warning?`<p>${esc(warning)}</p>`:''}<button class="btn green" type="button">OK — POST NEXT BILL</button></div>`;
    modal.querySelector('button').onclick = () => modal.remove(); document.body.appendChild(modal);
  }
  let mounting=null;
  async function mount() {
    const editor=q('#purchaseEditor');if(!editor||editor.dataset.ttPurchaseMode==='bags')return;
    if(mounting)return mounting;
    if(editor.dataset.ttSmartBills==='v3'&&editor.querySelector('.ttsb-wrap')&&state.loadedEntity===entity())return;
    editor.dataset.ttPurchaseMode='arrival';const company=entity();
    mounting=(async()=>{try{await load(true);if(editor.dataset.ttPurchaseMode!=='arrival'||company!==entity())return;render();}
      catch(error){editor.innerHTML=`<div class="ttsb-card"><h3>Bill Posting</h3><div class="note">${esc(error.message||'Could not load saved Pohanch records.')}</div></div>`;}})();
    try{await mounting;}finally{mounting=null;}
  }
  document.addEventListener('click',event=>{if(event.target.closest('[data-tt-entity]')){state.loadedEntity='';state.broker='';state.supplier='';resetAfterRelationship();}});
  window.TT_SMART_COMMODITY_BILLS_V2 = {mount, reload:() => load(true).then(render), selectionRows:() => selectedRows().map(row=>({...row})), refreshTotals:syncLines};
})();

