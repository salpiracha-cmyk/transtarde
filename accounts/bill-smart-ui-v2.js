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
  const state = {receipts:[], bills:[], loadedEntity:'', relationshipType:'', relationshipName:'', soda:'', sourceKey:'', adjustmentLines:[], brokerageRate:'', brokerageBasis:'PER_100_KG', kanta:'', filling:''};
  let loading = false;

  function toast(message, ok = true) {
    let el = q('#ttSmartBillToast');
    if (!el) { el = document.createElement('div'); el.id = 'ttSmartBillToast'; el.style.cssText = 'position:fixed;left:50%;bottom:22px;transform:translateX(-50%);z-index:100080;padding:11px 15px;border-radius:10px;color:#fff;font:700 12px Arial;box-shadow:0 8px 25px #0003'; document.body.appendChild(el); }
    el.style.background = ok ? '#147a5b' : '#a93a34'; el.textContent = message; el.hidden = false; clearTimeout(el._t); el._t = setTimeout(() => { el.hidden = true; }, 3500);
  }
  function style() {
    if (q('#ttSmartBillStyleV3')) return;
    const sheet = document.createElement('style'); sheet.id = 'ttSmartBillStyleV3';
    sheet.textContent = `.ttsb-wrap{padding:4px 0 18px}.ttsb-card{border:1px solid #dfe6ec;border-radius:13px;background:#fff;padding:17px;margin-bottom:13px}.ttsb-title{display:flex;gap:12px;align-items:flex-start}.ttsb-title>div{flex:1}.ttsb-title h3{margin:0}.ttsb-title p{margin:4px 0 0;color:#6c7886;font-size:12px}.ttsb-steps{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:15px}.ttsb-step{border:1px solid #dce4eb;border-radius:11px;padding:12px;background:#fbfcfd}.ttsb-step.active{border-color:#6b9981;background:#f7fcf9}.ttsb-step label{font-size:11px;font-weight:800;color:#526173}.ttsb-step select,.ttsb-step input,.ttsb-form input,.ttsb-form textarea,.ttsb-form select{width:100%;margin-top:5px;padding:10px;border:1px solid #cfd8e1;border-radius:8px;background:#fff}.ttsb-form{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.ttsb-form label{font-size:11px;font-weight:800;color:#526173}.ttsb-full{grid-column:1/-1}.ttsb-summary{display:grid;grid-template-columns:repeat(4,1fr);gap:9px;margin:13px 0}.ttsb-kpi{border:1px solid #e1e6eb;border-radius:9px;padding:10px;background:#fafbfc}.ttsb-kpi span{display:block;font-size:9px;text-transform:uppercase;color:#6b7885}.ttsb-kpi b{display:block;margin-top:4px;font-size:16px}.ttsb-disclose{margin-top:12px;border:1px solid #e1e7ed;border-radius:10px;background:#fff}.ttsb-disclose summary{cursor:pointer;padding:11px 12px;font-weight:800}.ttsb-disclose>div{padding:0 12px 12px}.ttsb-lines{border:1px solid #e1e7ed;border-radius:10px;overflow:hidden}.ttsb-line{display:grid;grid-template-columns:.7fr 1.8fr .8fr 36px;gap:8px;padding:9px;border-bottom:1px solid #edf0f3;align-items:end}.ttsb-line:last-child{border-bottom:0}.ttsb-line button{height:36px;border:0;border-radius:8px;background:#fff0f0;color:#9b3333;font-weight:900}.ttsb-actions{display:flex;gap:9px;justify-content:flex-end;margin-top:14px}.ttsb-accounting{margin-top:13px;border:1px solid #bad0dc;background:#eef6fa;border-radius:10px;padding:12px}.ttsb-postline{display:grid;grid-template-columns:60px 1fr auto;gap:8px;padding:4px 0;font-size:12px}.ttsb-posting{position:fixed;inset:0;z-index:100090;background:#0b1b2b88;display:grid;place-items:center;padding:20px}.ttsb-posting>div{width:min(520px,100%);background:#fff;border-radius:18px;padding:26px;text-align:center;box-shadow:0 24px 70px #0005}.ttsb-posting strong{display:block;font-size:64px;color:#28523d;line-height:1;margin:18px}.ttsb-posting h2{margin:0}.ttsb-empty{padding:22px;text-align:center;color:#6c7886}.ttsb-recent table{width:100%;border-collapse:collapse}.ttsb-recent th,.ttsb-recent td{padding:8px;border-bottom:1px solid #edf0f3;text-align:left;font-size:11px}@media(max-width:850px){.ttsb-form,.ttsb-summary{grid-template-columns:1fr 1fr}.ttsb-line{grid-template-columns:1fr 1fr}}@media(max-width:560px){.ttsb-steps,.ttsb-form,.ttsb-summary,.ttsb-line{grid-template-columns:1fr}}`;
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
      state.loadedEntity = company;
    } finally { loading = false; }
  }
  const openRows = () => state.receipts.filter(row => !row.billed);
  const meaningful = value => { const text = String(value || '').trim(); return text && !/^(select|type to search|no matching)/i.test(text); };
  const unique = values => [...new Set(values.map(value => String(value || '').trim()).filter(Boolean))].sort((a,b) => a.localeCompare(b));
  const brokers = () => unique(openRows().map(row => meaningful(row.broker) ? row.broker : ''));
  const suppliers = () => unique(openRows().map(row => meaningful(row.party) ? row.party : ''));
  function relatedRows() {
    if (!state.relationshipName) return [];
    return openRows().filter(row => state.relationshipType === 'BROKER' ? String(row.broker) === state.relationshipName : String(row.party) === state.relationshipName);
  }
  const sodas = () => unique(relatedRows().map(row => row.soda));
  const truckRows = () => relatedRows().filter(row => String(row.soda) === state.soda);
  const selected = () => truckRows().find(row => row.sourceKey === state.sourceKey) || null;
  function option(value, selectedValue, label = value) { return `<option value="${esc(value)}" ${String(value) === String(selectedValue) ? 'selected' : ''}>${esc(label)}</option>`; }
  function resetAfterRelationship() { state.soda = ''; state.sourceKey = ''; state.adjustmentLines = []; state.brokerageRate=''; state.kanta=''; state.filling=''; }
  function resetAfterSoda() { state.sourceKey = ''; state.adjustmentLines = []; state.brokerageRate=''; state.kanta=''; state.filling=''; }
  function relationSteps() {
    const brokerDisabled = state.relationshipType === 'SUPPLIER';
    const supplierDisabled = state.relationshipType === 'BROKER';
    return `<div class="ttsb-steps"><div class="ttsb-step ${state.relationshipType === 'BROKER' ? 'active' : ''}"><label>Broker<select id="ttsbBroker" ${brokerDisabled ? 'disabled' : ''}><option value="">Choose broker</option>${brokers().map(name => option(name,state.relationshipType === 'BROKER' ? state.relationshipName : '')).join('')}</select></label></div><div class="ttsb-step ${state.relationshipType === 'SUPPLIER' ? 'active' : ''}"><label>Supplier<select id="ttsbSupplier" ${supplierDisabled ? 'disabled' : ''}><option value="">Choose supplier</option>${suppliers().map(name => option(name,state.relationshipType === 'SUPPLIER' ? state.relationshipName : '')).join('')}</select></label></div></div>
      ${state.relationshipName ? `<div class="ttsb-steps"><div class="ttsb-step active"><label>Soda<select id="ttsbSoda"><option value="">Choose Soda</option>${sodas().map(no => option(no,state.soda,`Soda ${no}`)).join('')}</select></label></div>${state.soda ? `<div class="ttsb-step active"><label>Truck / Container / Pohanch<select id="ttsbTruck" data-open-receipt><option value="">Choose saved container or Pohanch</option>${truckRows().map(row => option(row.sourceKey,state.sourceKey,`${row.truck || 'Truck'} — ${row.pohanch || 'Pohanch'} — ${row.displayName || row.variety || ''}`)).join('')}</select></label></div>` : ''}</div>` : ''}`;
  }
  function lineHtml(line, index) {
    return `<div class="ttsb-line" data-adjustment-line="${index}"><label>Type<select data-line-kind><option value="ADDITION" ${line.kind === 'ADDITION' ? 'selected' : ''}>Addition</option><option value="DEDUCTION" ${line.kind === 'DEDUCTION' ? 'selected' : ''}>Deduction</option></select></label><label>Description<input data-line-description value="${esc(line.description || '')}" placeholder="Kanta, filling, allowance, quality adjustment..."></label><label>Amount (PKR)<input data-line-amount inputmode="decimal" value="${esc(line.amount || '')}"></label><button type="button" data-line-remove title="Delete line">×</button></div>`;
  }
  function totals() {
    const row = selected(), calculated = Number(q('#ttsbFinal')?.value || 0), base = row && row.commodity !== 'RICE' && calculated > 0 ? calculated : Number(row?.provisionalAmount || 0);
    let add = 0, deduct = 0;
    state.adjustmentLines.forEach(line => { const amount = Math.max(0,Number(line.amount || 0)); if (line.kind === 'DEDUCTION') deduct += amount; else add += amount; }); add += Math.max(0,Number(state.kanta||0)); deduct += Math.max(0,Number(state.filling||0));
    return {base, add, deduct, final:base + add - deduct};
  }
  function accounting(row) {
    const value = totals().final;
    return `<div class="ttsb-accounting"><strong>Accounting treatment before posting</strong><div class="ttsb-postline"><b>Debit</b><span>Commodity inventory / purchase adjustment</span><strong id="ttsbPreviewValueDr">PKR ${fmt(value)}</strong></div><div class="ttsb-postline"><b>Credit</b><span>${esc(state.relationshipName)} payable</span><strong id="ttsbPreviewValueCr">PKR ${fmt(value)}</strong></div><div class="ttsb-postline" id="ttsbPreviewBrokery" hidden><b>Debit</b><span>Buying brokery expense</span><strong></strong></div><div class="ttsb-postline" id="ttsbPreviewBrokerPayable" hidden><b>Credit</b><span>${esc(row.broker || 'Broker')} payable</span><strong></strong></div><div class="ttsb-postline" id="ttsbPreviewWithholding" hidden><b>Credit</b><span>Brokery withholding payable</span><strong></strong></div><div class="helper">Buying brokery posts as a payable. Withholding is deducted when the broker is paid.</div></div>`;
  }
  function billForm(row) {
    if (!row) return '';
    const value = totals(), exMill=String(row.sourceKey||'').startsWith('EXMILL|');
    return `<div class="ttsb-card ttsb-bill"><div class="ttsb-title"><div><h3>Complete Bill</h3><p>${exMill?'The approved Soda and saved container weighbridge weight supply the accounting quantity. No Pohanch or KAT applies.':'The selected Soda and Pohanch supply the operational details.'} Enter only the final bill information.</p></div></div><div class="ttsb-form" style="margin-top:14px"><label>Bill Date<input id="ttsbBillDate" type="date" value="${today()}"></label><label>Supplier / Broker Bill No.<input id="ttsbBillNo" placeholder="Optional if none printed"></label><label>Soda<input readonly value="${esc(row.soda)}"></label><label>${exMill?'Truck / Container':'Truck / Pohanch'}<input readonly value="${esc(row.truck)} — ${esc(row.pohanch)}"></label><label>Product<input readonly value="${esc(row.displayName || row.variety || row.commodity)}"></label><label>${exMill?'Container Weighbridge Weight':'Accepted Weight'}<input readonly value="${fmt(row.payableWeightKg)} kg"></label><label>Soda Rate<input readonly value="${fmt(row.grossRatePerKg)} / kg"></label>${exMill?'':`<label>KAT<input readonly value="${fmt(row.katPaisaPerKg)} paisa / kg"></label>`}<label>Calculated Commodity Value<input id="ttsbFinal" readonly value="${value.base.toFixed(2)}"></label></div>
      <div class="ttsb-summary"><div class="ttsb-kpi"><span>${exMill?'Container Liability':'Pohanch Estimate'}</span><b>PKR ${fmt(value.base)}</b></div><div class="ttsb-kpi"><span>Additions</span><b id="ttsbAddTotal">PKR ${fmt(value.add)}</b></div><div class="ttsb-kpi"><span>Deductions</span><b id="ttsbDeductTotal">PKR ${fmt(value.deduct)}</b></div><div class="ttsb-kpi"><span>Final Commodity Value</span><b id="ttsbFinalTotal">PKR ${fmt(value.final)}</b></div></div>
      <div class="ttsb-disclose"><h3>Additions / Deductions</h3><div><div class="ttsb-lines" id="ttsbAdjustmentLines">${state.adjustmentLines.length ? state.adjustmentLines.map(lineHtml).join('') : '<div class="ttsb-empty">No additions or deductions. Add a line only when it appears on the bill.</div>'}</div><div class="ttsb-actions" style="justify-content:flex-start"><button class="btn" type="button" id="ttsbAddAddition">+ Addition</button><button class="btn" type="button" id="ttsbAddDeduction">+ Deduction</button></div></div></div>
      <div class="ttsb-disclose"><h3>Buying Brokery</h3><div class="ttsb-form"><label>Rate<input id="ttsbRate" type="number" min="0" step=".01" value="${esc(state.brokerageRate)}"></label><label>Per<select id="ttsbBasis"><option value="PER_100_KG" ${state.brokerageBasis==='PER_100_KG'?'selected':''}>100 kg bag</option><option value="PER_50_KG_BAG" ${state.brokerageBasis==='PER_50_KG_BAG'?'selected':''}>50 kg bag</option><option value="PER_MAUND" ${state.brokerageBasis==='PER_MAUND'?'selected':''}>Maund (40 kg)</option></select></label><label>Total Brokery<input id="ttsbBrokerage" readonly value="0"></label><label>Brokery WHT % (payment only)<input id="ttsbWithPct" type="number" min="0" step=".01" value="15"></label><label>WHT information<input id="ttsbWithAmt" readonly value="0"></label></div><div class="ttsb-form"><label>Kanta / Weight Charges (+)<input id="ttsbKanta" type="number" min="0" step=".01" value="${esc(state.kanta)}"></label><label>Filling Charges (−)<input id="ttsbFilling" type="number" min="0" step=".01" value="${esc(state.filling)}"></label></div></div>
      <div class="ttsb-kpi" style="margin-top:12px;text-align:right"><span>FINAL BILL PAYABLE</span><b id="ttsbGrandTotal" style="font-size:24px;text-decoration:underline">PKR ${fmt(value.final)}</b></div><div class="ttsb-form" style="margin-top:12px"><label class="ttsb-full">Remarks / Bill Explanation<textarea id="ttsbRemarks"></textarea></label></div>${accounting(row)}
      <div class="ttsb-actions"><button class="btn" type="button" id="ttsbClearTruck">Choose Another Truck</button><button class="btn green" type="button" id="ttsbVerify">POST BILL</button></div></div>`;
  }
  function recent() {
    const rows = state.bills.slice().reverse().slice(0,12);
    return `<details class="ttsb-card ttsb-recent"><summary style="cursor:pointer;font-weight:800">Recent Posted Bills</summary>${rows.length ? `<table><thead><tr><th>Posting</th><th>Bill</th><th>Date</th><th>Soda</th><th>Party</th><th>Value</th></tr></thead><tbody>${rows.map(row => `<tr><td><b>${esc(row.postingNumber || '—')}</b></td><td>${esc(row.billNo || row.id)}</td><td>${esc(row.billDate || '')}</td><td>${esc((row.sodas || []).join(', '))}</td><td>${esc(row.relationshipName || row.broker || '')}</td><td>PKR ${fmt(row.finalCommodityValue)}</td></tr>`).join('')}</tbody></table>` : '<div class="ttsb-empty">No posted bills yet.</div>'}</details>`;
  }
  function render() {
    const editor = q('#purchaseEditor'); if (!editor) return; style(); editor.dataset.ttSmartBills = 'v3'; editor.dataset.ttPurchaseMode = 'arrival';
    if (entity() === 'TG') { editor.innerHTML = '<div class="ttsb-card">Pakistan Soda and Pohanch billing is available only in TTI / BRM books.</div>'; return; }
    editor.innerHTML = `<div class="ttsb-wrap"><div class="ttsb-card"><div class="ttsb-title"><div><h3>Bill Posting</h3><p>Choose either the Broker or Supplier. Each answer opens only the next related choice.</p></div><b>${openRows().length} unposted</b></div>${relationSteps()}</div>${billForm(selected())}${recent()}</div>`;
    bind();
  }
  function syncLines() {
    state.adjustmentLines = qa('[data-adjustment-line]').map(line => ({kind:line.querySelector('[data-line-kind]').value, description:line.querySelector('[data-line-description]').value.trim(), amount:line.querySelector('[data-line-amount]').value}));
    state.brokerageRate=q('#ttsbRate')?.value||'';state.brokerageBasis=q('#ttsbBasis')?.value||'PER_100_KG';state.kanta=q('#ttsbKanta')?.value||'';state.filling=q('#ttsbFilling')?.value||'';
    const row=selected(),weight=Number(row?.payableWeightKg||0),basis=state.brokerageBasis;
    const units=basis==='PER_MAUND'?weight/40:basis==='PER_50_KG_BAG'?weight/50:weight/100;
    const brokerage=Math.round(Math.max(0,Number(state.brokerageRate||0))*units*100)/100;
    if(q('#ttsbBrokerage'))q('#ttsbBrokerage').value=brokerage.toFixed(2);
    const withholding=Math.round(brokerage*Math.max(0,Number(q('#ttsbWithPct')?.value||0))*100)/100;
    if(q('#ttsbWithAmt'))q('#ttsbWithAmt').value=withholding.toFixed(2);
    const value=totals();
    for(const [id,amount] of [['ttsbAddTotal',value.add],['ttsbDeductTotal',value.deduct],['ttsbFinalTotal',value.final],['ttsbPreviewValueDr',value.final],['ttsbPreviewValueCr',value.final]])if(q('#'+id))q('#'+id).textContent=`PKR ${fmt(amount)}`;
    if(q('#ttsbGrandTotal'))q('#ttsbGrandTotal').textContent=`PKR ${fmt(value.final+brokerage)}`;
    for(const [id,amount] of [['ttsbPreviewBrokery',brokerage],['ttsbPreviewBrokerPayable',brokerage]]){const line=q('#'+id);if(line){line.hidden=amount<=0;line.querySelector('strong').textContent=`PKR ${fmt(amount)}`;}}
    if(q('#ttsbPreviewWithholding'))q('#ttsbPreviewWithholding').hidden=true;
  }
  function bind() {
    const broker = q('#ttsbBroker'); if (broker) broker.onchange = () => { state.relationshipType = broker.value ? 'BROKER' : ''; state.relationshipName = broker.value; resetAfterRelationship(); render(); };
    const supplier = q('#ttsbSupplier'); if (supplier) supplier.onchange = () => { state.relationshipType = supplier.value ? 'SUPPLIER' : ''; state.relationshipName = supplier.value; resetAfterRelationship(); render(); };
    const soda = q('#ttsbSoda'); if (soda) soda.onchange = () => { state.soda = soda.value; resetAfterSoda(); render(); };
    const truck = q('#ttsbTruck'); if (truck) truck.onchange = () => { state.sourceKey = truck.value; state.adjustmentLines = []; render(); };
    q('#ttsbAddAddition')?.addEventListener('click', () => { syncLines(); state.adjustmentLines.push({kind:'ADDITION',description:'',amount:''}); render(); });
    q('#ttsbAddDeduction')?.addEventListener('click', () => { syncLines(); state.adjustmentLines.push({kind:'DEDUCTION',description:'',amount:''}); render(); });
    qa('[data-line-kind],[data-line-description],[data-line-amount]').forEach(input => { input.oninput = syncLines; input.onchange = syncLines; });
    qa('#ttsbRate,#ttsbBasis,#ttsbKanta,#ttsbFilling,#ttsbWithPct').forEach(input=>{input.addEventListener('input',syncLines);input.addEventListener('change',syncLines);});syncLines();
    qa('[data-line-remove]').forEach(button => { button.onclick = () => { syncLines(); state.adjustmentLines.splice(Number(button.closest('[data-adjustment-line]').dataset.adjustmentLine),1); render(); }; });
    q('#ttsbClearTruck')?.addEventListener('click', () => { resetAfterSoda(); render(); });
    q('#ttsbVerify')?.addEventListener('click', postBill);
  }
  async function postBill() {
    syncLines(); const row = selected(), value = totals();
    if (!row) return toast('Choose a saved or printed Pohanch.', false);
    if (state.adjustmentLines.some(line => !line.description || !(Number(line.amount) > 0))) return toast('Complete or delete every addition/deduction line.', false);
    if (value.final <= 0) return toast('Final bill value must be greater than zero.', false);
    const payload = {action:'verify_bill',csrf:access.csrf,entity:entity(),relationshipType:state.relationshipType,relationshipName:state.relationshipName,billDate:q('#ttsbBillDate')?.value || today(),billNo:q('#ttsbBillNo')?.value.trim() || '',broker:row.broker || '',sourceKeys:[row.sourceKey],finalCommodityValue:value.final,brokerageGross:Number(q('#ttsbBrokerage')?.value || 0),brokerageWithholding:0,brokerageRate:Number(state.brokerageRate||0),brokerageBasis:state.brokerageBasis,brokerageWhtPercent:Number(q('#ttsbWithPct')?.value||0),adjustmentLines:[...state.adjustmentLines,...(Number(state.kanta)>0?[{kind:'ADDITION',description:'Kanta / Weight Charges',amount:Number(state.kanta)}]:[]),...(Number(state.filling)>0?[{kind:'DEDUCTION',description:'Filling Charges',amount:Number(state.filling)}]:[])],adjustments:{},remarks:q('#ttsbRemarks')?.value.trim() || ''};
    const button = q('#ttsbVerify'); if (button) { button.disabled = true; button.textContent = 'POSTING…'; }
    try {
      const response = await fetch(billApi,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify(payload)}); let body = {}; try { body = await response.json(); } catch (_) {}
      if (!response.ok || !body.ok) throw new Error(body.error || 'Bill could not be posted.');
      const postingNumber = body.bill?.postingNumber;
      try { await fetch(workflowApi,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify({action:'sync_late_holds',csrf:access.csrf})}); } catch (_) {}
      await load(true); resetAfterSoda(); render(); showPosting(postingNumber, body.bill?.id || '');
      window.TT_ACCOUNTS_V1_WORKFLOW?.refreshAttention?.();
    } catch (error) { toast(String(error.message || error), false); if (button) { button.disabled = false; button.textContent = 'POST BILL'; } }
  }
  function showPosting(number, billId) {
    const modal = document.createElement('div'); modal.className = 'ttsb-posting'; modal.innerHTML = `<div><h2>Bill Posted</h2><p>Write this posting number on the original bill.</p><strong>${esc(number || '—')}</strong><p>${esc(billId)}</p><button class="btn green" type="button">OK — POST NEXT BILL</button></div>`;
    modal.querySelector('button').onclick = () => modal.remove(); document.body.appendChild(modal);
  }
  async function mount() {
    const editor = q('#purchaseEditor'); if (!editor || editor.dataset.ttPurchaseMode==='bags') return;
    editor.dataset.ttPurchaseMode = 'arrival';
    try { await load(true); if (editor.dataset.ttPurchaseMode==='bags') return; render(); }
    catch (error) { editor.innerHTML = `<div class="ttsb-card"><h3>Bill Posting</h3><div class="note">${esc(error.message || 'Could not load saved Pohanch records.')}</div></div>`; }
  }
  document.addEventListener('click', event => { if (event.target.closest('.appCard[data-key="purchases"]') && q('#purchaseEditor')?.dataset.ttPurchaseMode === 'arrival') setTimeout(mount,100); if (event.target.closest('[data-tt-entity]')) { state.loadedEntity = ''; state.relationshipType = ''; state.relationshipName = ''; resetAfterRelationship(); } });
  window.TT_SMART_COMMODITY_BILLS_V2 = {mount, reload:() => load(true).then(render), selectionRows:() => selected() ? [{...selected()}] : [], refreshTotals:syncLines};
})();
