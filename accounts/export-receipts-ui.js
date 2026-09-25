(() => {
  'use strict';
  const access = window.TT_ACCOUNT_ACCESS || {};
  const receiptApi = '../api/export_receipts.php';
  const bankApi = '../api/bank_accounts.php';
  const shortfallApi = '../api/export_bank_shortfall.php';
  const tgBankApi = '../api/tg_bank_transactions.php';
  const q = selector => document.querySelector(selector);
  const qa = selector => [...document.querySelectorAll(selector)];
  const entity = () => localStorage.getItem('tt_accounts_entity') || 'TTI';
  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
  const fmt = value => Number(value || 0).toLocaleString('en-PK', {maximumFractionDigits:2});
  const num = value => Math.max(0, Number(String(value ?? '').replace(/,/g,'')) || 0);
  const today = () => new Intl.DateTimeFormat('en-CA', {timeZone:'Asia/Karachi',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
  let data = null, banks = null, tgData = null, bankId = '', tgBankId = '', payerType = '', payer = '', currency = 'USD', chosen = new Map(), deductions = [], shortfallClass = '', shortfallNote = '', selectedTgPayment = null;
  let pendingReceipt = null;
  const pendingKey = () => `tt_export_tg_settlement_${entity()}`;
  function savePending(value) { pendingReceipt = value; if (value) sessionStorage.setItem(pendingKey(),JSON.stringify(value)); else sessionStorage.removeItem(pendingKey()); }
  function restorePending() {
    try {
      pendingReceipt = JSON.parse(sessionStorage.getItem(pendingKey()) || 'null');
      if (pendingReceipt) { payerType = 'TG'; payer = 'TG'; currency = pendingReceipt.currency; tgBankId = pendingReceipt.tgBankId; chosen = new Map(pendingReceipt.items.map(item => [item.key,item])); }
    } catch (_) { pendingReceipt = null; sessionStorage.removeItem(pendingKey()); }
  }

  function toast(message, ok = true) {
    let el = q('#ttExportReceiptToast');
    if (!el) { el = document.createElement('div'); el.id = 'ttExportReceiptToast'; el.style.cssText = 'position:fixed;left:50%;bottom:22px;transform:translateX(-50%);z-index:100090;padding:11px 15px;border-radius:10px;color:#fff;font:700 12px Arial;box-shadow:0 8px 25px #0003'; document.body.appendChild(el); }
    el.style.background = ok ? '#147a5b' : '#a93a34'; el.textContent = message; el.hidden = false; clearTimeout(el._t); el._t = setTimeout(() => { el.hidden = true; }, 3800);
  }
  async function get(url) {
    const response = await fetch(url,{credentials:'same-origin',headers:{Accept:'application/json'}}); let body = {}; try { body = await response.json(); } catch (_) {}
    if (!response.ok || !body.ok) throw new Error(body.error || 'Could not load receipt data.'); return body;
  }
  async function load() { [data,banks] = await Promise.all([get(`${receiptApi}?entity=${encodeURIComponent(entity())}`),get(`${bankApi}?entity=${encodeURIComponent(entity())}`)]); }
  async function loadTg() { tgData = await get(`${tgBankApi}?date=${encodeURIComponent(today())}`); }
  function style() {
    if (q('#ttExportReceiptStyleV2')) return;
    const sheet = document.createElement('style'); sheet.id = 'ttExportReceiptStyleV2';
    sheet.textContent = `.tter-panel{margin:12px 18px 20px;border:1px solid #dfe6ec;border-radius:13px;background:#fff;overflow:hidden}.tter-head{display:flex;gap:10px;align-items:flex-start;padding:14px 15px;border-bottom:1px solid #edf0f3}.tter-head .sp{flex:1}.tter-body{padding:15px}.tter-step{border:1px solid #dfe6ec;border-radius:11px;padding:13px;margin-top:11px;background:#fbfcfd}.tter-step.active{border-color:#77a08a;background:#f7fcf9}.tter-step h3{margin:0 0 10px;font-size:13px}.tter-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.tter-grid label,.tter-item label{font-size:11px;font-weight:800;color:#526173}.tter-grid input,.tter-grid select,.tter-item input,.tter-item select,.tter-step textarea{width:100%;margin-top:5px;padding:9px;border:1px solid #cfd8e1;border-radius:8px;background:#fff}.tter-parties{display:grid;grid-template-columns:1fr 1fr;gap:10px}.tter-party{border:1px solid #dce4eb;background:#fff;border-radius:10px;padding:12px;text-align:left;cursor:pointer}.tter-party.active{border-color:#2f7956;box-shadow:inset 3px 0 #2f7956}.tter-items{border:1px solid #e1e7ed;border-radius:10px;overflow:hidden}.tter-item{display:grid;grid-template-columns:34px minmax(0,1fr) 150px;gap:9px;align-items:center;padding:10px;border-bottom:1px solid #edf0f3}.tter-item:last-child{border-bottom:0}.tter-item input[type=checkbox]{width:auto;margin:0}.tter-item b,.tter-item small{display:block}.tter-item small{color:#6d7885;margin-top:3px}.tter-ded{display:grid;grid-template-columns:1.3fr .7fr .8fr 1fr 36px;gap:8px;align-items:end;margin-top:8px}.tter-ded button{height:36px;border:0;border-radius:8px;background:#fff0f0;color:#963838;font-weight:900}.tter-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:9px;margin-top:12px}.tter-kpi{border:1px solid #e0e6ec;border-radius:9px;padding:10px;background:#fafcfd}.tter-kpi span{display:block;font-size:9px;text-transform:uppercase;color:#6d7885}.tter-kpi b{display:block;margin-top:3px;font-size:15px}.tter-accounting{margin-top:12px;border:1px solid #b9cedb;background:#eef6fa;border-radius:10px;padding:12px}.tter-accounting-row{display:grid;grid-template-columns:55px minmax(0,1fr) auto;gap:8px;padding:4px 0;font-size:12px}.tter-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:13px}.tter-note{font-size:11px;color:#6f7a89;margin-top:6px}.tter-alert{padding:10px;border-radius:9px;background:#fff6df;border:1px solid #ead6a2;color:#705718;margin-top:9px}.tter-ok{color:#147a5b}.tter-bad{color:#a93a34}.tter-history{margin-top:12px;overflow:auto}.tter-history table{width:100%;border-collapse:collapse}.tter-history td,.tter-history th{padding:8px;border-bottom:1px solid #edf0f3;text-align:left;font-size:11px}@media(max-width:850px){.tter-grid,.tter-kpis{grid-template-columns:1fr 1fr}.tter-ded{grid-template-columns:1fr 1fr}.tter-item{grid-template-columns:30px 1fr}}@media(max-width:560px){.tter-grid,.tter-kpis,.tter-parties,.tter-ded{grid-template-columns:1fr}}`;
    sheet.textContent += `.tter-overlay{position:fixed;inset:0;z-index:100070;display:grid;place-items:center;padding:18px;background:#102536aa}.tter-overlay[hidden]{display:none}.tter-dialog{width:min(1100px,98vw);max-height:94vh;overflow:auto;border-radius:16px;background:#f8fbfd;box-shadow:0 26px 75px #10253655}.tter-dialog .tter-panel{margin:0;border:0;border-radius:0}.tter-dialog .tter-head{position:sticky;top:0;z-index:2;background:#fff}@media(max-width:600px){.tter-overlay{padding:5px}.tter-dialog{max-height:99vh}}`;
    sheet.textContent += `.tter-manage-customer{align-self:end;min-height:36px;white-space:nowrap}@media(max-width:560px){.tter-manage-customer{justify-self:start}}`;
    document.head.appendChild(sheet);
  }
  function ensurePanel() {
    style(); let overlay=q('#ttExportReceiptDialog');
    if(!overlay){overlay=document.createElement('div');overlay.id='ttExportReceiptDialog';overlay.className='tter-overlay';overlay.hidden=true;overlay.innerHTML='<div class="tter-dialog" role="dialog" aria-modal="true" aria-label="Bank Receipt and Credit Advice"><div class="tter-panel" data-er-panel></div></div>';document.body.appendChild(overlay);overlay.addEventListener('click',event=>{if(event.target===overlay)closeForm()});}
    overlay.hidden=false;return overlay.querySelector('[data-er-panel]');
  }
  function closeForm(){const overlay=q('#ttExportReceiptDialog');if(overlay)overlay.hidden=true;bankId='';payerType='';payer='';selectedTgPayment=null;chosen.clear();deductions=[];}
  function receiptBanks(currencyCode) { return (banks?.accounts || []).filter(account => !account.needsCompletion && String(account.masterStatus || 'Active').toLowerCase() === 'active' && String(account.currency || '').toUpperCase() === currencyCode); }
  function bankUnavailableReason(account) {
    if (account.needsCompletion) return 'Add its account number or IBAN in Company Master';
    if (String(account.masterStatus || 'Active').toLowerCase() !== 'active') return 'Set the bank to Active in Company Master';
    return 'Check its currency in Company Master';
  }
  function selectedBankCurrency() { return String((banks?.accounts || []).find(account => account.id === bankId)?.currency || 'PKR').toUpperCase(); }
  function bankLabel(account) { return String(account.settings?.displayName || '').trim() || `${account.bankName || account.accountTitle || 'Bank'}${account.accountLast5 ? ` · •••${account.accountLast5}` : ''}`; }
  function bankOptions(currencyCode, selected = '') {
    const rows = [...receiptBanks('PKR'),...receiptBanks(currency)], preferred = rows.find(row => row.settings?.defaultReceiptAccount)?.id || '';
    const choice = selected || preferred;
    return `<option value="">Choose company account</option>${rows.map(row => `<option value="${esc(row.id)}" ${row.id === choice ? 'selected' : ''}>${esc(bankLabel(row))} · ${esc(row.currency)}</option>`).join('')}`;
  }
  function retentionBankOptions(currencyCode, selected = '') {
    const rows = receiptBanks(currencyCode).filter(row => row.settings?.retentionAccount);
    return `<option value="">Choose approved retention account</option>${rows.map(row => `<option value="${esc(row.id)}" ${row.id === selected ? 'selected' : ''}>${esc(bankLabel(row))} · ${esc(row.currency)}</option>`).join('')}`;
  }
  function sourceRows() {
    const rows = [];
    for (const invoice of data?.sources?.invoices || []) {
      // An unposted CAD/L/C invoice is not an advance or a posted receivable.
      if (!invoice.recognized || Number(invoice.outstandingForeign || 0) <= 0) continue;
      rows.push({key:`INV|${invoice.id}`,targetId:invoice.id,targetType:invoice.recognized ? (invoice.candidateType === 'TG_PAKISTAN_INTERCOMPANY' ? 'INTERCOMPANY_RECEIVABLE' : 'EXPORT_RECEIVABLE') : 'UNAPPLIED_ADVANCE',customer:invoice.customer || '',currency:String(invoice.currency || 'USD').toUpperCase(),amount:Number(invoice.outstandingForeign || 0),reference:invoice.contractRef || invoice.reference || '',invoiceRef:invoice.reference || '',contractRef:invoice.contractRef || '',narration:invoice.recognized ? 'Balance' : 'Advance',isTg:invoice.candidateType === 'TG_PAKISTAN_INTERCOMPANY',recognized:!!invoice.recognized,mirrorCandidateId:invoice.mirrorCandidateId || '',fiRefs:Array.isArray(invoice.fiRefs) ? invoice.fiRefs : []});
    }
    for (const contract of data?.sources?.contracts || []) {
      const amount = Number(contract.outstandingAdvance ?? contract.expectedAdvance ?? 0); if (amount <= 0) continue;
      if (String(contract.seller || '').toUpperCase() === entity()) rows.push({key:`CON|${contract.id}`,targetId:'',targetType:'UNAPPLIED_ADVANCE',customer:contract.customer || '',currency:String(contract.currency || 'USD').toUpperCase(),amount,reference:contract.ref || '',invoiceRef:'',contractRef:contract.ref || '',narration:'Advance',isTg:false,recognized:false,mirrorCandidateId:'',fiRefs:[]});
    }
    return rows;
  }
  function payers() {
    if (payerType === 'TG') return [...new Set(sourceRows().filter(row => row.isTg).map(() => 'TG'))];
    const contracts = (data?.sources?.contracts || []).filter(row => String(row.seller || '').toUpperCase() === entity() && !/cancell?ed|deleted/i.test(String(row.status || ''))).map(row => row.customer);
    const outstanding = sourceRows().filter(row => !row.isTg).map(row => row.customer);
    const customers = window.TT_ACCOUNTS_MASTER_CHOICES?.customerNames?.() || [];
    return [...new Set([...customers,...contracts,...outstanding].map(value => String(value || '').trim()).filter(Boolean))].sort((a,b) => a.localeCompare(b));
  }
  function availableItems() { return sourceRows().filter(row => row.currency === currency && (payerType === 'TG' ? row.isTg : !row.isTg && row.customer === payer)); }
  function itemLabel(item) {
    if (item.isTg) return `${item.currency} ${fmt(item.amount)} — ${item.fiRefs.length ? `FI ${item.fiRefs.join(', ')}` : 'FI pending'} — ${item.invoiceRef || item.reference}`;
    return `${item.currency} ${fmt(item.amount)} — ${item.narration} — ${item.reference}`;
  }
  function selectedExpected() { return [...chosen.values()].reduce((sum,item) => sum + Number(item.amount || 0),0); }
  function redistribute() {
    let remaining = num(q('#erForeign')?.value);
    for (const [key,item] of chosen) { item.applied = Math.min(item.amount,remaining); remaining = Math.max(0,remaining - item.applied); chosen.set(key,item); }
  }
  function itemsHtml() {
    const rows = availableItems();
    if (!rows.length) return '<div class="tter-alert">No outstanding Export item matches this payer and currency.</div>';
    return `<div class="tter-items">${rows.map(item => { const selected = chosen.get(item.key); return `<div class="tter-item"><input type="checkbox" data-er-item="${esc(item.key)}" ${selected ? 'checked' : ''}><div><b>${esc(itemLabel(item))}</b><small>${esc(item.isTg ? 'TG payable reference from Exports' : item.customer)}</small></div><label>Apply ${esc(item.currency)}<input data-er-applied="${esc(item.key)}" value="${esc(selected?.applied ?? '')}" ${selected ? '' : 'disabled'}></label></div>`; }).join('')}</div>`;
  }
  function deductionOptions(value = '') { return `<option value="">Choose tax / charge</option>${(data?.deductionMaster || []).map(row => `<option value="${esc(row.code)}" ${row.code === value ? 'selected' : ''}>${esc(row.name)} · ${esc(row.regime || '')}</option>`).join('')}`; }
  function deductionsHtml() { return deductions.map((row,index) => `<div class="tter-ded" data-er-ded="${index}"><label>Tax / Charge<select data-ded-code>${deductionOptions(row.code)}</select></label><label>PKR Amount<input data-ded-amount value="${esc(row.amount || '')}"></label><label>Treatment<select data-ded-mode><option value="DEDUCTED" ${row.mode === 'DEDUCTED' ? 'selected' : ''}>Deducted from receipt</option><option value="SEPARATE_DEBIT" ${row.mode === 'SEPARATE_DEBIT' ? 'selected' : ''}>Debited separately</option></select></label><label>Tax Section / FED Detail<input data-ded-section value="${esc(row.taxSection || '')}"></label><button type="button" data-ded-remove>×</button></div>`).join(''); }
  function historyHtml() { const rows = (data?.receipts || []).filter(row => row.recordType !== 'FOREIGN_BANK_SHORTFALL_ADJUSTMENT').slice(0,12); return rows.length ? `<details class="tter-step"><summary style="font-weight:800;cursor:pointer">Recent Posted Receipts</summary><div class="tter-history"><table><thead><tr><th>Date</th><th>Advice</th><th>From</th><th>Foreign</th><th>Bank Credit</th></tr></thead><tbody>${rows.map(row => `<tr><td>${esc(row.date)}</td><td>${esc(row.bankAdviceRef)}</td><td>${esc(row.remitter || '')}</td><td>${esc(row.transactionCurrency)} ${fmt(row.foreignAmount)}</td><td>${row.nativeBankCredit ? esc(row.transactionCurrency)+' '+fmt(row.nativeBankCredit) : 'PKR '+fmt(row.pkrBankCredit)}</td></tr>`).join('')}</tbody></table></div></details>` : ''; }
  function fullFormHtml() {
    if (!chosen.size && payerType !== 'TG') return '';
    const directForeign = selectedBankCurrency() !== 'PKR';
    return `<section class="tter-step active"><h3>Receipt and Credit Advice</h3><div class="tter-grid"><label>Credit Date<input id="erDate" type="date" value="${today()}"></label><label>Bank Advice / Transaction Ref<input id="erBankRef"></label><label>Currency<input value="${esc(currency)}" readonly></label><label>Foreign Amount Received<input id="erForeign" inputmode="decimal"></label><label>Bank Advice Realization Rate (PKR per ${esc(currency)})<input id="erRate" inputmode="decimal"></label><label>PKR Equivalent<input id="erGross" inputmode="decimal" readonly></label><label>Actual ${directForeign ? esc(currency) : "PKR"} Credited<input id="erBankCredit" inputmode="decimal"></label>${directForeign ? "" : `<label style="grid-column:1/-1"><input id="erUseRetention" type="checkbox" style="width:auto"> Retain part of this ${esc(currency)} receipt in the company retention account</label><div id="erRetentionFields" class="tter-grid" style="grid-column:1/-1;display:none"><label>Amount to retain (${esc(currency)})<input id="erRetention" value="0" inputmode="decimal"></label><label>Company retention account<select id="erRetentionBank">${retentionBankOptions(currency)}</select></label><div id="erRetentionDetails" class="tter-note" style="grid-column:1/-1"></div></div>`}<label class="tter-grid" style="grid-column:1/-1;display:block">Upload Credit Advice<input id="erAdviceFile" type="file" accept="application/pdf,image/jpeg,image/png,image/webp"></label><label style="grid-column:1/-1">Paper File Reference (optional)<input id="erFileRef"></label></div>
      <div class="tter-kpis" hidden><div class="tter-kpi"><span>Expected From Selected Items</span><b id="erExpectedForeign">${currency} ${fmt(selectedExpected())}</b></div><div class="tter-kpi"><span>Received</span><b id="erReceivedForeign">${currency} 0</b></div><div class="tter-kpi"><span>Shortfall</span><b id="erShortfall">${currency} ${fmt(selectedExpected())}</b></div><div class="tter-kpi"><span>Expected Bank Credit</span><b id="erExpectedPkr">${directForeign ? currency : 'PKR'} 0</b></div></div>
      <div id="erShortfallBox"></div></section>
      <details class="tter-step" ${directForeign ? "hidden" : ""}><summary>Bank deductions shown on the credit advice</summary><div class="tter-note">Add each deduction separately from the credit advice so it reaches its correct ledger and tax certificate record.</div><div id="erDeductions">${deductionsHtml()}</div><button type="button" class="btn" id="erAddDeduction">+ Tax / Charge / FED</button></details>
      <div class="tter-accounting" hidden><strong>Accounting treatment before posting</strong><div id="erAccountingRows"><div class="tter-note">Enter receipt amounts to see the Debit / Credit entry.</div></div></div><div class="tter-actions"><button class="btn green" id="erPost">POST RECEIPT</button></div>`;
  }
  function render() {
    const panel = ensurePanel(); if (!panel) return;
    if (pendingReceipt) {
      panel.innerHTML = `<div class="tter-head"><b>Export Payment Receipt / Credit Advice</b><div class="sp"></div><button class="btn" data-er-close>Close</button></div><div class="tter-body"><div class="tter-alert">Pakistan receipt ${esc(pendingReceipt.receipt.id)} is posted. Complete its TG payable settlement before entering another receipt.</div><button class="btn green" id="erRetryTg">Retry TG settlement</button></div>`;
      q('[data-er-close]').onclick=closeForm;
      q('#erRetryTg').onclick = retryTgSettlement;
      return;
    }
    const eligibleBanks = [...receiptBanks('PKR'),...receiptBanks(currency)];
    panel.innerHTML = `<div class="tter-head"><div><b>Export Payment Receipt / Credit Advice</b><div class="tter-note">FI is maintained only in Exports. Accounts selects the linked outstanding item and posts the bank entry.</div></div><div class="sp"></div><button class="btn" data-er-close>Close</button></div><div class="tter-body">
      <section class="tter-step active"><h3>▰ Received into company account</h3><div class="tter-grid"><label>Received Into Account<select id="erPkrBank">${bankOptions('PKR',bankId)}</select></label></div>${(banks?.accounts||[]).filter(row=>!eligibleBanks.some(ready=>ready.id===row.id)).map(row=>`<div class="tter-alert"><b>${esc(bankLabel(row))} · ${esc(row.currency||'currency missing')}</b><br>${esc(bankUnavailableReason(row))}.</div>`).join('')}${!(banks?.accounts||[]).length ? `<div class="tter-alert">No ${esc(entity())} bank account is linked in Company Master. Add the company bank and its account number or IBAN there.</div>` : ''}</section>
      ${bankId ? `<section class="tter-step active" id="erPayerStep"><h3>⇠ Received from</h3><div class="tter-parties"><button type="button" class="tter-party ${payerType === 'TG' ? 'active' : ''}" data-payer-type="TG"><b>Trans Grains (TG)</b><div class="tter-note">Enter the credit advice details; invoice references are linked when available.</div></button><button type="button" class="tter-party ${payerType === 'CUSTOMER' ? 'active' : ''}" data-payer-type="CUSTOMER"><b>Export Customer</b><div class="tter-note">Choose the customer, then its advance or balance items.</div></button></div>${payerType === 'CUSTOMER' ? `<div class="tter-grid" style="margin-top:10px"><label>Customer<select id="erPayer"><option value="">Choose customer</option>${payers().map(name => `<option ${name === payer ? 'selected' : ''}>${esc(name)}</option>`).join('')}</select></label><button type="button" class="btn tter-manage-customer" id="erManageCustomer">+ Add / Amend Customer</button></div>` : ''}</section>` : ''}
      ${payerType === 'TG' && !selectedTgPayment ? `<section class="tter-step active"><h3>Trans Grains receipt details</h3><div class="tter-grid"><label>Receipt Currency<select id="erCurrency">${['USD','AED','EUR','GBP'].map(code => `<option ${code === currency ? 'selected' : ''}>${code}</option>`).join('')}</select></label><label>Invoice / Contract Reference (if known)<input id="erTgReference" placeholder="Enter reference from credit advice"></label></div><div class="tter-note">An exact posted TG invoice reference is matched automatically. Other receipts remain identified as unapplied TG money for later allocation.</div></section>` : ''}
      ${payer && (payerType !== 'TG' || !!selectedTgPayment) ? `<section class="tter-step active"><h3>▤ Outstanding item(s)</h3><div class="tter-grid" style="margin-bottom:10px"><label>Receipt Currency<select id="erCurrency">${['USD','EUR','GBP','AED'].map(code => `<option ${code === currency ? 'selected' : ''}>${code}</option>`).join('')}</select></label></div><div class="tter-note">Multiple items may be selected. Display order is amount → narration/reference → bare contract or internal document number.</div><div id="erItems">${itemsHtml()}</div></section>` : ''}
      ${fullFormHtml()}${historyHtml()}</div>`;
    bind();
    if (selectedTgPayment && q('#erForeign')) {
      q('#erForeign').value=String(selectedTgPayment.amount);
      q('#erBankRef').value=selectedTgPayment.bankReference || '';
      if(selectedBankCurrency()!=='PKR')q('#erBankCredit').value=String(selectedTgPayment.amount);
    }
    updateRetentionDetails();
  }
  function pendingBanner() {
    let host=q('#ttPendingTgPayments');const top=q('.topbar');
    if(!top||!['TTI','BRM'].includes(entity())){host?.remove();return}
    if(!host){host=document.createElement('div');host.id='ttPendingTgPayments';host.style.cssText='display:flex;align-items:center;gap:6px;flex-wrap:wrap';top.appendChild(host)}
    host.innerHTML=(data?.pendingTg||[]).map(row=>`<button type="button" class="btn" data-tg-pending="${esc(row.id)}">TG sent ${esc(row.currency)} ${fmt(row.amount)} · ${esc(row.invoiceRef)}${row.gdRef?' · GD '+esc(row.gdRef):''}${row.fiRef?' · FI '+esc(row.fiRef):''} — Complete receipt</button>`).join('');
    host.querySelectorAll('[data-tg-pending]').forEach(button=>button.onclick=()=>openForm(button.dataset.tgPending));
  }
  function captureDeductions() { deductions = qa('[data-er-ded]').map(row => ({code:row.querySelector('[data-ded-code]').value,amount:row.querySelector('[data-ded-amount]').value,mode:row.querySelector('[data-ded-mode]').value,taxSection:row.querySelector('[data-ded-section]').value.trim()})); }
  function updateRetentionDetails() {
    const enabled=!!q('#erUseRetention')?.checked,fields=q('#erRetentionFields');
    if(fields)fields.style.display=enabled?'grid':'none';
    if(!enabled&&q('#erRetention'))q('#erRetention').value='0';
    const account=(banks?.accounts||[]).find(row=>row.id===q('#erRetentionBank')?.value);
    const details=q('#erRetentionDetails');if(details)details.textContent=account?`${account.bankName||account.accountTitle} · ${account.accountTitle||''} · ${account.currency} · ${account.iban||account.accountNumber||''} ${account.swift?'· SWIFT '+account.swift:''}`:'Select a designated retention account in this company and currency.';
    calc();
  }
  function bind() {
    q('#erUseRetention')?.addEventListener('change',updateRetentionDetails);
    q('#erRetentionBank')?.addEventListener('change',updateRetentionDetails);
    q('[data-er-close]')?.addEventListener('click', closeForm);
    q('#erPkrBank')?.addEventListener('change', event => { bankId = event.target.value; if (!selectedTgPayment) { payerType = ''; payer = ''; chosen.clear(); } render(); });
    qa('[data-payer-type]').forEach(button => { button.onclick = async () => { if(selectedTgPayment)return; payerType = button.dataset.payerType; payer = payerType === 'TG' ? 'TG' : ''; chosen.clear(); render(); }; });
    q('#erPayer')?.addEventListener('change', event => { if(selectedTgPayment)return; payer = event.target.value; chosen.clear(); render(); });
    q('#erManageCustomer')?.addEventListener('click', () => {
      const search = q('#erPayer')?.closest('.tt-search-select')?.querySelector('input')?.value.trim() || '';
      const name = search && search !== q('#erPayer')?.selectedOptions[0]?.textContent.trim() ? search : (payer || search);
      const opened = window.TT_ACCOUNTS_MASTER_CHOICES?.manageCustomer?.(name, async savedName => {
        try { await load(); payer = savedName; chosen.clear(); render(); }
        catch(error) { toast(error.message || 'Customer saved. Refresh this form to see the updated list.',false); }
      });
      if (!opened) toast('Adding or amending customers requires Master Create or Edit permission.',false);
    });
    q('#erTgReference')?.addEventListener('input',calc);
    qa('[data-er-item]').forEach(box => { box.onchange = () => { if(selectedTgPayment){box.checked=true;return}const item = availableItems().find(row => row.key === box.dataset.erItem); if (box.checked && item) chosen.set(item.key,{...item,applied:item.amount}); else chosen.delete(box.dataset.erItem); render(); }; });
    qa('[data-er-applied]').forEach(input => { input.oninput = () => { const item = chosen.get(input.dataset.erApplied); if (item) { item.applied = num(input.value); chosen.set(input.dataset.erApplied,item); } calc(); }; });
    q('#erCurrency')?.addEventListener('change', event => { if(selectedTgPayment)return; currency = event.target.value; tgBankId = ''; chosen.clear(); render(); });
    q('#erAddDeduction')?.addEventListener('click', () => { captureDeductions(); deductions.push({code:'',amount:'',mode:'DEDUCTED',taxSection:''}); render(); });
    qa('[data-ded-code],[data-ded-amount],[data-ded-mode],[data-ded-section]').forEach(input => { input.oninput = () => { captureDeductions(); calc(); }; input.onchange = input.oninput; });
    qa('[data-ded-remove]').forEach(button => { button.onclick = () => { captureDeductions(); deductions.splice(Number(button.closest('[data-er-ded]').dataset.erDed),1); render(); }; });
    ['erForeign','erRate','erBankCredit','erRetention'].forEach(id => q(`#${id}`)?.addEventListener('input', () => { if (id === 'erForeign') { redistribute(); qa('[data-er-applied]').forEach(input => { input.value = chosen.get(input.dataset.erApplied)?.applied || ''; }); } if(id==='erBankCredit')q('#erBankCredit').dataset.edited='1'; calc(); }));
    q('#erShortfallBox')?.addEventListener('input', event => { if (event.target.id === 'erShortfallNote') shortfallNote = event.target.value; });
    q('#erShortfallBox')?.addEventListener('change', event => { if (event.target.id === 'erShortfallClass') shortfallClass = event.target.value; });
    q('#erPost')?.addEventListener('click',postReceipt);
  }
  function calc() {
    if (!q('#erForeign')) return;
    captureDeductions(); const expected = selectedExpected(), received = num(q('#erForeign').value), rate = num(q('#erRate')?.value), gross = Math.round(received*rate*100)/100, retentionPkr = num(q('#erRetention')?.value) * rate;
    if(q('#erGross'))q('#erGross').value=gross?gross.toFixed(2):'';
    const directForeign=selectedBankCurrency()!=='PKR',deducted = deductions.reduce((sum,row) => sum + (row.mode === 'DEDUCTED' ? num(row.amount) : 0),0), bankExpected = directForeign ? received : Math.max(0,gross - retentionPkr - deducted), bankField=q('#erBankCredit');
    if(bankField&&!bankField.dataset.edited)bankField.value=bankExpected?bankExpected.toFixed(2):'';
    const bankCredit = num(bankField?.value), shortfall = Math.max(0,expected - received);
    q('#erExpectedForeign').textContent = `${currency} ${fmt(expected)}`; q('#erReceivedForeign').textContent = `${currency} ${fmt(received)}`; q('#erShortfall').textContent = `${currency} ${fmt(shortfall)}`; q('#erExpectedPkr').textContent = `${directForeign ? currency : 'PKR'} ${fmt(bankExpected)}`;
    shortfallClass = q('#erShortfallClass')?.value ?? shortfallClass; shortfallNote = q('#erShortfallNote')?.value ?? shortfallNote;
    const box = q('#erShortfallBox'); if (box) box.innerHTML = shortfall > .005 ? `<div class="tter-alert"><b>Expected ${currency} ${fmt(expected)}; received ${currency} ${fmt(received)}. Shortfall ${currency} ${fmt(shortfall)}.</b><div class="tter-grid" style="margin-top:8px"><label>Classification<select id="erShortfallClass"><option value="">Choose treatment</option><option value="PARTIAL" ${shortfallClass==='PARTIAL'?'selected':''}>Partial payment — leave balance outstanding</option><option value="CORRESPONDENT" ${shortfallClass==='CORRESPONDENT'?'selected':''}>Correspondent bank charge — close with charge entry</option><option value="OTHER" ${shortfallClass==='OTHER'?'selected':''}>Other — leave outstanding for review</option></select></label><label>Explanation<input id="erShortfallNote" value="${esc(shortfallNote)}" placeholder="Required for Other"></label></div></div>` : '<div class="tter-note tter-ok">No foreign-currency shortfall.</div>';
    const allocation = payerType === 'TG' && !selectedTgPayment ? received : [...chosen.values()].reduce((sum,item) => sum + num(item.applied),0), retentionAmount=num(q('#erRetention')?.value), retentionValid=retentionAmount<=received&&(!retentionAmount||!!q('#erRetentionBank')?.value), balanced = Math.abs(allocation - received) <= .01 && Math.abs(bankCredit - bankExpected) <= 2 && retentionValid;
    const preview = q('#erAccountingRows'); if (preview) preview.innerHTML = `${bankCredit ? `<div class="tter-accounting-row"><b>Debit</b><span>Selected ${directForeign ? currency : 'PKR'} company bank</span><strong>${directForeign ? currency+' '+fmt(bankCredit)+' (PKR '+fmt(gross)+')' : 'PKR '+fmt(bankCredit)}</strong></div>` : ''}${retentionPkr ? `<div class="tter-accounting-row"><b>Debit</b><span>Foreign retention bank</span><strong>PKR ${fmt(retentionPkr)}</strong></div>` : ''}${deducted ? `<div class="tter-accounting-row"><b>Debit</b><span>Tax / charges / FED ledgers</span><strong>PKR ${fmt(deducted)}</strong></div>` : ''}${gross ? `<div class="tter-accounting-row"><b>Credit</b><span>${payerType === 'TG' ? 'TG intercompany receivable' : 'Export receivable / customer advance'}</span><strong>PKR ${fmt(gross)}</strong></div>` : '<div class="tter-note">Enter the advice amounts to see the posting.</div>'}<div class="tter-note ${balanced ? 'tter-ok' : 'tter-bad'}">Applied foreign amount: ${currency} ${fmt(allocation)} · bank difference: ${directForeign ? currency : 'PKR'} ${fmt(bankCredit-bankExpected)}</div>`;
    const post = q('#erPost'); if (post) post.disabled = !(received > 0 && rate > 0 && (chosen.size || payerType === 'TG') && balanced);
  }
  function allocations() {
    if (payerType === 'TG' && !selectedTgPayment) {
      const foreign = num(q('#erForeign')?.value), reference = q('#erTgReference')?.value.trim() || '';
      const matches = reference ? sourceRows().filter(item => item.isTg && item.currency === currency && item.invoiceRef.toLowerCase() === reference.toLowerCase()) : [];
      if (matches.length === 1 && foreign <= matches[0].amount + .005) return [{targetType:'INTERCOMPANY_RECEIVABLE',targetId:matches[0].targetId,contractRef:matches[0].contractRef,invoiceRef:matches[0].invoiceRef,customer:'TG',foreignAmount:foreign}];
      return [{targetType:'UNAPPLIED_TG',targetId:'',contractRef:'',invoiceRef:reference,customer:'TG',foreignAmount:foreign}];
    }
    return [...chosen.values()].filter(item => num(item.applied) > 0).map(item => ({targetType:item.targetType,targetId:item.targetId,contractRef:item.contractRef,invoiceRef:item.invoiceRef,customer:item.customer,foreignAmount:num(item.applied)}));
  }
  function deductionPayload() { return deductions.filter(row => row.code && num(row.amount) > 0).map(row => ({masterCode:row.code,amount:num(row.amount),mode:row.mode,taxSection:row.taxSection})); }
  async function uploadAdvice() {
    const file = q('#erAdviceFile')?.files?.[0]; if (!file) return '';
    const form = new FormData(); form.append('csrf',access.csrf); form.append('adviceFile',file);
    const response = await fetch('../api/accounts_receipt_file.php',{method:'POST',credentials:'same-origin',body:form,headers:{Accept:'application/json'}}); let body = {}; try { body = await response.json(); } catch (_) {}
    if (!response.ok || !body.ok) throw new Error(body.error || 'Credit advice upload failed.'); return body.token;
  }
  async function postShortfalls(receipt, classification, note, evidence) {
    if (classification !== 'CORRESPONDENT') return;
    for (const item of chosen.values()) {
      const gap = Math.max(0,Number(item.amount)-num(item.applied)); if (gap <= .005) continue;
      if (!item.recognized || !item.targetId) throw new Error('A correspondent-bank shortfall can close only a posted invoice/TG receivable. Use Partial for an advance.');
      const payload = {action:'post_shortfall',csrf:access.csrf,entity:entity(),candidateId:item.targetId,linkedReceiptId:receipt.id,foreignShortfall:gap,evidenceRef:evidence,notes:note || 'Confirmed correspondent bank charge from credit advice',confirmedBankCharge:true};
      const response = await fetch(shortfallApi,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify(payload)}); let body = {}; try { body = await response.json(); } catch (_) {}
      if (!response.ok || !body.ok) throw new Error(body.error || 'Receipt posted, but the correspondent-bank shortfall needs Accounts review.');
    }
  }
  function validateTgSettlement(classification) {
    if (payerType !== 'TG') return;
    if (!tgData?.rates) throw new Error('Set an effective TG USD → AED accounting rate in Directors / Super Admin → TG Master → Currency & Closing before posting this TG payment. The bank advice PKR realization rate is entered separately on this form.');
    if (!tgBankId) throw new Error('Choose the TG account from which this payment was made.');
    const bank = (tgData?.banks || []).find(row => row.id === tgBankId);
    if (!bank || !bank.settings?.allowPayments || String(bank.currency || '').toUpperCase() !== currency) throw new Error(`Choose an enabled TG ${currency} payment account.`);
    let total = 0;
    for (const item of chosen.values()) {
      if (!item.recognized || !item.mirrorCandidateId) throw new Error('TG receipts must use a recognized internal invoice linked by Exports.');
      if (!(tgData?.openLiabilities || []).some(liability => liability.id === item.mirrorCandidateId)) throw new Error(`TG payable ${item.invoiceRef || item.reference} is not posted or has no open balance.`);
      total += classification === 'CORRESPONDENT' ? Number(item.amount || 0) : num(item.applied);
    }
    if (total > Number(bank.balance?.native || 0) + .0001) throw new Error(`TG ${currency} bank balance is insufficient for ${currency} ${fmt(total)}.`);
  }
  async function postTgSettlements(receipt, classification) {
    if (payerType !== 'TG') return;
    const rate = currency === 'AED' ? 1 : Number(tgData?.rates?.sellUsdToAed || 0);
    let sequence = 0;
    for (const item of chosen.values()) {
      const amount = classification === 'CORRESPONDENT' ? Number(item.amount || 0) : num(item.applied);
      if (amount <= 0) continue;
      sequence += 1;
      const bankReference = `${receipt.bankAdviceRef}-TG-${sequence}`;
      if ((tgData?.history || []).some(row => row.bankAccountId === tgBankId && row.bankReference === bankReference && row.sourceLiabilityId === item.mirrorCandidateId && Math.abs(Number(row.amountNative)-amount)<.01)) continue;
      const payload = {action:'post_payment',csrf:access.csrf,date:receipt.date || today(),paymentType:'LIABILITY',sourceLiabilityId:item.mirrorCandidateId,bankAccountId:tgBankId,counterparty:entity(),amountNative:amount,bankChargeNative:0,rate,bankReference,rateOverrideNote:'',notes:`Mirrored settlement for Pakistan receipt ${receipt.id}`};
      const response = await fetch(tgBankApi,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify(payload)}); let body = {}; try { body = await response.json(); } catch (_) {}
      if (!response.ok || !body.ok) throw new Error(body.error || `Pakistan receipt ${receipt.id} posted, but its TG payable settlement needs review.`);
      tgData.history = [{bankAccountId:tgBankId,bankReference,sourceLiabilityId:item.mirrorCandidateId,amountNative:amount},...(tgData.history || [])];
    }
  }
  async function retryTgSettlement() {
    if (!pendingReceipt) return;
    try {
      restorePending();
      await loadTg();
      await postTgSettlements(pendingReceipt.receipt,pendingReceipt.classification);
      await postShortfalls(pendingReceipt.receipt,pendingReceipt.classification,pendingReceipt.note,pendingReceipt.evidence);
      const posted = pendingReceipt.receipt;
      savePending(null);
      payerType = ''; payer = ''; chosen.clear(); deductions = [];
      await load(); render(); window.TT_BANK_ACCOUNTS_UI?.reload?.();
      toast(`Receipt ${posted.id} and TG payable settlement posted.`);
    } catch (error) { toast(`Receipt ${pendingReceipt.receipt.id} is already posted. TG settlement still needs review: ${String(error.message || error)}`,false); }
  }
  async function postReceipt() {
    const expected = selectedExpected(), received = num(q('#erForeign')?.value), shortfall = Math.max(0,expected-received), classification = q('#erShortfallClass')?.value || shortfallClass, currentShortfallNote = q('#erShortfallNote')?.value.trim() || shortfallNote;
    if (shortfall > .005 && !classification) return toast('Choose how the shortfall should be treated.',false);
    if (classification === 'OTHER' && !currentShortfallNote) return toast('Explain the other shortfall reason.',false);

    const directForeign=selectedBankCurrency()!=='PKR';
    const body = {action:'post_receipt',csrf:access.csrf,entity:entity(),date:q('#erDate')?.value || today(),bankAdviceRef:q('#erBankRef')?.value.trim() || '',bankAdviceFileRef:'',bankAdvicePaperRef:q('#erFileRef')?.value.trim() || '',remitter:payer,transactionCurrency:currency,foreignAmount:received,realizationRate:num(q('#erRate')?.value),grossPkrEquivalent:num(q('#erGross')?.value),bankAccountId:bankId,pkrBankCredit:directForeign?0:num(q('#erBankCredit')?.value),nativeBankCredit:directForeign?num(q('#erBankCredit')?.value):0,retentionForeignAmount:num(q('#erRetention')?.value),retentionBankAccountId:q('#erRetentionBank')?.value || '',tgPaymentId:selectedTgPayment?.id || '',allocations:allocations(),deductions:directForeign?[]:deductionPayload(),shortfallClassification:classification,shortfallNote:currentShortfallNote};
    if (!body.bankAccountId) return toast('Choose the company bank account first.',false); if (!body.bankAdviceRef) return toast('Enter the bank advice / transaction reference.',false);
    const button = q('#erPost'); button.disabled = true; button.textContent = 'POSTING…';
    try {
      body.bankAdviceFileRef = await uploadAdvice();
      const response = await fetch(receiptApi,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify(body)}); let result = {}; try { result = await response.json(); } catch (_) {}
      if (!response.ok || !result.ok) throw new Error(result.error || 'Export receipt could not be posted.');
      await postShortfalls(result.receipt,classification,currentShortfallNote,body.bankAdviceFileRef || body.bankAdvicePaperRef);
      savePending(null);
      toast(`Receipt ${result.receipt.id} posted.`); payerType = ''; payer = ''; selectedTgPayment=null; chosen.clear(); deductions = []; await load(); pendingBanner(); render(); window.TT_BANK_ACCOUNTS_UI?.reload?.();
    } catch (error) { toast(pendingReceipt ? `Receipt ${pendingReceipt.receipt.id} posted. TG settlement needs review: ${String(error.message || error)}` : String(error.message || error),false); if (pendingReceipt) render(); else { button.disabled = false; button.textContent = 'POST RECEIPT'; } }
  }
  async function openForm(paymentId = '') {
    if (!['TTI','BRM'].includes(entity())) return toast('Pakistan export receipts are available in TTI / BRM books.',false);
    try {
       const waiting=ensurePanel();waiting.innerHTML='<div class="tter-head"><b>Bank Receipt / Credit Advice</b><div class="sp"></div><button class="btn" data-er-close>Close</button></div><div class="tter-body">Loading company accounts and linked export receipts…</div>';waiting.querySelector('[data-er-close]').onclick=closeForm;
       await load(); pendingBanner(); restorePending(); selectedTgPayment=(data?.pendingTg||[]).find(row=>row.id===paymentId)||null;
      if(selectedTgPayment){currency=selectedTgPayment.currency;payerType='TG';payer='TG';const item=sourceRows().find(row=>row.targetId===selectedTgPayment.candidateId&&row.isTg);if(!item)throw new Error('The linked Pakistan receivable is not recognized or has no remaining balance.');chosen=new Map([[item.key,{...item,applied:selectedTgPayment.amount}]]);shortfallClass=selectedTgPayment.amount<item.amount-.005?'PARTIAL':'';bankId=receiptBanks(currency).find(row=>row.settings?.defaultReceiptAccount)?.id || receiptBanks(currency)[0]?.id || receiptBanks('PKR')[0]?.id || '';}
       else bankId = receiptBanks('PKR').find(row => row.settings?.defaultReceiptAccount)?.id || receiptBanks('PKR')[0]?.id || ''; if (pendingReceipt) await loadTg(); ensurePanel(); render(); ensurePanel().scrollIntoView({behavior:'smooth',block:'start'});
    } catch (error) { const panel=q('#ttExportReceiptDialog [data-er-panel]');if(panel)panel.innerHTML='<div class="tter-head"><b>Bank Receipt / Credit Advice</b><div class="sp"></div><button class="btn" data-er-close>Close</button></div><div class="tter-alert">'+esc(error.message||error)+'</div>';panel?.querySelector('[data-er-close]')?.addEventListener('click',closeForm);toast(String(error.message || error),false); }
  }
  window.TT_EXPORT_RECEIPTS_UI={openForm};
  document.addEventListener('keydown',event=>{if(event.key==='Escape'&&!q('#ttExportReceiptDialog')?.hidden)closeForm();});
  const refreshPending=()=>{if(['TTI','BRM'].includes(entity()))load().then(pendingBanner).catch(()=>{});else pendingBanner();};
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',refreshPending,{once:true});else refreshPending();
  document.addEventListener('click',event=>{if(event.target.closest('[data-tt-entity], .entityBtn')){closeForm();setTimeout(refreshPending,100);}});
  setInterval(()=>{if(document.visibilityState==='visible'&&['TTI','BRM'].includes(entity()))refreshPending();},30000);
})();
