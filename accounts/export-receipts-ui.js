(() => {
  'use strict';
  const access = window.TT_ACCOUNT_ACCESS || {};
  const receiptApi = '../api/export_receipts.php';
  const bankApi = '../api/bank_accounts.php';
  const chargeApi = '../api/export_realization_master.php';
  const shortfallApi = '../api/export_bank_shortfall.php';
  const tgBankApi = '../api/tg_bank_transactions.php';
  const q = selector => document.querySelector(selector);
  const qa = selector => [...document.querySelectorAll(selector)];
  const entity = () => localStorage.getItem('tt_accounts_entity') || 'TTI';
  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
  const fmt = value => Number(value || 0).toLocaleString('en-PK', {maximumFractionDigits:2});
  const num = value => Math.max(0, Number(String(value ?? '').replace(/,/g,'')) || 0);
  const today = () => new Intl.DateTimeFormat('en-CA', {timeZone:'Asia/Karachi',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
  let partialReceipt=false,formGeneration=0;
  let data = null, banks = null, tgData = null, bankId = '', tgBankId = '', payerType = '', payer = '', currency = 'USD', chosen = new Map(), deductions = [], shortfallClass = '', shortfallNote = '', selectedTgPayment = null;
  const regularCodes = ['EXP-AWT-NTR','EXP-BANK-COMM','EXP-FED-BANK'];
  let pendingReceipt = null, amendmentOf = '', amendmentReason = '', advanceEntry = false;
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
    let response;
    try { response = await fetch(url,{credentials:'same-origin',headers:{Accept:'application/json'}}); }
    catch (_) { await new Promise(resolve=>setTimeout(resolve,400)); response = await fetch(url,{credentials:'same-origin',headers:{Accept:'application/json'}}); }
    let body = {}; try { body = await response.json(); } catch (_) {}
    if (!response.ok || !body.ok) throw new Error(body.error || 'Could not load receipt data.'); return body;
  }
  async function load() { const results=await Promise.all([get(`${receiptApi}?entity=${encodeURIComponent(entity())}`),get(`${bankApi}?entity=${encodeURIComponent(entity())}`),get(chargeApi)]);[data,banks]=results; }
  async function loadTg() { tgData = await get(`${tgBankApi}?date=${encodeURIComponent(today())}`); }
  function style() {
    if (q('#ttExportReceiptStyleV2')) return;
    const sheet = document.createElement('style'); sheet.id = 'ttExportReceiptStyleV2';
    sheet.textContent = `.tter-panel{margin:12px 18px 20px;border:1px solid #dfe6ec;border-radius:13px;background:#fff;overflow:hidden}.tter-head{display:flex;gap:10px;align-items:flex-start;padding:14px 15px;border-bottom:1px solid #edf0f3}.tter-head .sp{flex:1}.tter-body{padding:15px}.tter-step{border:1px solid #dfe6ec;border-radius:11px;padding:13px;margin-top:11px;background:#fbfcfd}.tter-step.active{border-color:#77a08a;background:#f7fcf9}.tter-step h3{margin:0 0 10px;font-size:13px}.tter-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.tter-grid label,.tter-item label{font-size:11px;font-weight:800;color:#526173}.tter-grid input,.tter-grid select,.tter-item input,.tter-item select,.tter-step textarea{width:100%;margin-top:5px;padding:9px;border:1px solid #cfd8e1;border-radius:8px;background:#fff}.tter-parties{display:grid;grid-template-columns:1fr 1fr;gap:10px}.tter-party{border:1px solid #dce4eb;background:#fff;border-radius:10px;padding:12px;text-align:left;cursor:pointer}.tter-party.active{border-color:#2f7956;box-shadow:inset 3px 0 #2f7956}.tter-items{border:1px solid #e1e7ed;border-radius:10px;overflow:hidden}.tter-item{display:grid;grid-template-columns:34px minmax(0,1fr) 150px;gap:9px;align-items:center;padding:10px;border-bottom:1px solid #edf0f3}.tter-item:last-child{border-bottom:0}.tter-item input[type=checkbox]{width:auto;margin:0}.tter-item b,.tter-item small{display:block}.tter-item small{color:#6d7885;margin-top:3px}.tter-ded{display:grid;grid-template-columns:1.3fr .7fr .8fr 1fr 36px;gap:8px;align-items:end;margin-top:8px}.tter-ded button{height:36px;border:0;border-radius:8px;background:#fff0f0;color:#963838;font-weight:900}.tter-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:9px;margin-top:12px}.tter-kpi{border:1px solid #e0e6ec;border-radius:9px;padding:10px;background:#fafcfd}.tter-kpi span{display:block;font-size:9px;text-transform:uppercase;color:#6d7885}.tter-kpi b{display:block;margin-top:3px;font-size:15px}.tter-accounting{margin-top:12px;border:1px solid #b9cedb;background:#eef6fa;border-radius:10px;padding:12px}.tter-accounting-row{display:grid;grid-template-columns:55px minmax(0,1fr) auto;gap:8px;padding:4px 0;font-size:14px}.tter-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:13px}.tter-note{font-size:11px;color:#6f7a89;margin-top:6px}.tter-alert{padding:10px;border-radius:9px;background:#fff6df;border:1px solid #ead6a2;color:#705718;margin-top:9px}.tter-ok{color:#147a5b}.tter-bad{color:#a93a34}.tter-history{margin-top:12px;overflow:auto}.tter-history table{width:100%;border-collapse:collapse}.tter-history td,.tter-history th{padding:8px;border-bottom:1px solid #edf0f3;text-align:left;font-size:11px}@media(max-width:850px){.tter-grid,.tter-kpis{grid-template-columns:1fr 1fr}.tter-ded{grid-template-columns:1fr 1fr}.tter-item{grid-template-columns:30px 1fr}}@media(max-width:560px){.tter-grid,.tter-kpis,.tter-parties,.tter-ded{grid-template-columns:1fr}}`;
    sheet.textContent += `.tter-minus{width:26px;height:26px;border:0;border-radius:50%;background:#bd3339;color:white;font-weight:900;cursor:pointer;padding:0}.tter-overlay{position:fixed;inset:0;z-index:100070;display:grid;place-items:center;padding:18px;background:#102536aa}.tter-overlay[hidden]{display:none}.tter-dialog{width:min(1100px,98vw);max-height:94vh;overflow:auto;border-radius:16px;background:#f8fbfd;box-shadow:0 26px 75px #10253655}.tter-dialog .tter-panel{margin:0;border:0;border-radius:0}.tter-dialog .tter-head{position:sticky;top:0;z-index:2;background:#fff}@media(max-width:600px){.tter-overlay{padding:5px}.tter-dialog{max-height:99vh}}`;
    sheet.textContent += `.tter-manage-customer{align-self:end;min-height:36px;white-space:nowrap}@media(max-width:560px){.tter-manage-customer{justify-self:start}}`;
    sheet.textContent += `.tter-panel input[inputmode="decimal"],.tter-panel .tt-number-display{font-size:22px!important;font-weight:800;text-align:right}.tter-accounting-row strong{font-size:16px;font-variant-numeric:tabular-nums}.tter-ded{grid-template-columns:minmax(0,1.5fr) minmax(90px,.6fr) minmax(120px,.9fr) 36px}.tter-ded-head{font-size:11px;font-weight:800;color:#526173}.tter-ded label{min-width:0}.tter-ded input,.tter-ded select{width:100%;min-width:0;margin-top:5px;padding:9px;border:1px solid #cfd8e1;border-radius:8px;background:#fff}.tter-ded-fixed{background:#f7fcf9;padding:8px;border-radius:8px}.tter-ded-extra{padding:8px;border-top:1px solid #e1e7ed}.tter-ded-base{font-size:10px;color:#687782;margin-top:4px}.tter-ded-action{display:flex;align-items:end}.tter-ded-action button{width:36px}@media(max-width:850px){.tter-ded{grid-template-columns:minmax(0,1fr) 90px 120px 36px}}@media(max-width:560px){.tter-ded{grid-template-columns:minmax(0,1fr) 65px 95px 30px;gap:4px}.tter-ded input,.tter-ded select{padding:6px}}`;
    document.head.appendChild(sheet);
  }
  function ensurePanel() {
    style(); let overlay=q('#ttExportReceiptDialog');
    if(!overlay){overlay=document.createElement('div');overlay.id='ttExportReceiptDialog';overlay.className='tter-overlay';overlay.hidden=true;overlay.innerHTML='<div class="tter-dialog" role="dialog" aria-modal="true" aria-label="Bank Receipt and Credit Advice"><div class="tter-panel" data-er-panel></div></div>';document.body.appendChild(overlay);overlay.addEventListener('click',event=>{if(event.target===overlay)closeForm()});}
    overlay.hidden=false;return overlay.querySelector('[data-er-panel]');
  }
  function closeForm(){formGeneration++;const overlay=q('#ttExportReceiptDialog');if(overlay)overlay.hidden=true;bankId='';payerType='';payer='';selectedTgPayment=null;advanceEntry=false;partialReceipt=false;chosen.clear();deductions=[];amendmentOf='';amendmentReason='';}
  function receiptBanks(currencyCode) { return (banks?.accounts || []).filter(account => !account.needsCompletion && String(account.masterStatus || 'Active').toLowerCase() === 'active' && String(account.currency || '').toUpperCase() === currencyCode); }
  function bankUnavailableReason(account) {
    if (account.needsCompletion) return 'Add its account number or IBAN in Company Master';
    if (String(account.masterStatus || 'Active').toLowerCase() !== 'active') return 'Set the bank to Active in Company Master';
    return 'Check its currency in Company Master';
  }
  function selectedBankCurrency() { return String((banks?.accounts || []).find(account => account.id === bankId)?.currency || 'PKR').toUpperCase(); }
  function bankLabel(account) { return [account.bankName||'Bank',account.accountNumber||account.iban,account.currency,String(account.settings?.displayName||'').trim(),account.accountTitle].filter(Boolean).join(' · '); }
  function defaultReceiptBankId() {return [...receiptBanks('PKR'),...receiptBanks(currency)].find(row=>row.settings?.defaultReceiptAccount)?.id || '';}
  function bankOptions(currencyCode, selected = '') {
    const rows = [...receiptBanks('PKR'),...receiptBanks(currency)];
    return `<option value="">Choose company account</option>${rows.map(row => `<option value="${esc(row.id)}" ${row.id === selected ? 'selected' : ''}>${esc(bankLabel(row))}</option>`).join('')}`;
  }
  function retentionBankOptions(currencyCode, selected = '') {
    const rows = receiptBanks(currencyCode).filter(row => row.settings?.retentionAccount);
    return `<option value="">Choose approved retention account</option>${rows.map(row => `<option value="${esc(row.id)}" ${row.id === selected ? 'selected' : ''}>${esc(bankLabel(row))}</option>`).join('')}`;
  }
  function sourceRows() {
    const rows = [];
    for (const invoice of data?.sources?.invoices || []) {
      // An unposted CAD/L/C invoice is not an advance or a posted receivable.
      if (!invoice.recognized || Number(invoice.outstandingForeign || 0) <= 0) continue;
      if (invoice.candidateType === 'TG_PAKISTAN_INTERCOMPANY') continue;
      rows.push({key:`INV|${invoice.id}`,targetId:invoice.id,targetType:'EXPORT_RECEIVABLE',customer:invoice.customer || '',currency:String(invoice.currency || 'USD').toUpperCase(),amount:Number(invoice.outstandingForeign || 0),reference:invoice.contractRef || invoice.reference || '',invoiceRef:invoice.reference || '',contractRef:invoice.contractRef || '',narration:'Balance',isTg:false,recognized:true,mirrorCandidateId:invoice.mirrorCandidateId || '',fiRefs:Array.isArray(invoice.fiRefs) ? invoice.fiRefs : []});
    }
    for (const invoice of data?.sources?.tgPackInvoices || []) {
      rows.push({key:`TGPACK|${invoice.id}`,targetId:invoice.candidateId||`PACK|${invoice.id}`,targetType:invoice.recognized?'INTERCOMPANY_RECEIVABLE':'UNAPPLIED_TG',customer:'TG',currency:String(invoice.currency||'USD').toUpperCase(),amount:Number(invoice.outstandingForeign||0),invoiceValue:Number(invoice.value||0),reference:invoice.invoiceRef||'',invoiceRef:invoice.invoiceRef||'',contractRef:invoice.contractRef||'',carryForwardDraftId:invoice.carryForwardDraftId||'',carryForwardVersion:invoice.carryForwardVersion,narration:invoice.carryForwardDraftId?'Saved carry-forward — awaiting Post':'TG Pack invoice',isTg:true,recognized:!!invoice.recognized,mirrorCandidateId:invoice.mirrorCandidateId||'',fiRefs:[]});
    }
    for (const contract of data?.sources?.contracts || []) {
      const amount = Number(contract.outstandingAdvance ?? contract.expectedAdvance ?? 0); if (amount <= 0) continue;
      if (String(contract.seller || '').toUpperCase() === entity()) rows.push({key:`CON|${contract.id}`,targetId:'',targetType:'UNAPPLIED_ADVANCE',customer:contract.customer || '',currency:String(contract.currency || 'USD').toUpperCase(),amount,reference:contract.ref || '',invoiceRef:'',contractRef:contract.ref || '',narration:'Advance',isTg:false,recognized:false,mirrorCandidateId:'',fiRefs:[]});
    }
    if(typeof amendmentOf!=='undefined'&&amendmentOf){const original=(data?.receipts||[]).find(row=>row.id===amendmentOf);(original?.allocations||[]).forEach((allocation,index)=>{rows.push({key:`AMEND|${index}`,targetId:allocation.targetId||'',targetType:allocation.targetType,customer:allocation.customer||original.remitter||'',currency:original.transactionCurrency,amount:Number(allocation.foreignAmount||0),invoiceValue:Number(allocation.foreignAmount||0),reference:allocation.invoiceRef||allocation.contractRef||'',invoiceRef:allocation.invoiceRef||'',contractRef:allocation.contractRef||'',narration:allocation.targetType==='UNAPPLIED_TG'?'TG advance':'Original allocation',isTg:original.remitter==='TG',recognized:!!allocation.targetId&&allocation.targetType!=='UNAPPLIED_TG',mirrorCandidateId:(data?.sources?.invoices||[]).find(row=>row.id===allocation.targetId)?.mirrorCandidateId||'',fiRefs:[]});});}
    rows.push({key:'TGADV',targetId:'',targetType:'UNAPPLIED_TG',customer:'TG',currency,amount:0,reference:'',invoiceRef:'',contractRef:'',narration:'TG advance · FI unallocated',isTg:true,recognized:false,fiRefs:[]});
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
    if (item.isTg) return (item.targetType==='UNAPPLIED_TG' && !item.invoiceRef)?'Advance':`${item.currency} ${fmt(item.invoiceValue)} — ${item.invoiceRef}`;
    return `${item.currency} ${fmt(item.amount)} — ${item.narration} — ${item.reference}`;
  }
  function selectedExpected() { return Math.round([...chosen.values()].reduce((sum,item) => sum + (payerType==='TG'||partialReceipt?num(item.applied):Number(item.amount || 0)),0)*100)/100; }
  function redistribute() {
    if(payerType==='TG')return;
    let remaining = num(q('#erForeign')?.value)+(partialReceipt?num(q('#erCorrespondentAmount')?.value):0);
    for (const [key,item] of chosen) { item.applied = item.targetType==='UNAPPLIED_TG' && !item.invoiceRef ? remaining : Math.min(item.amount,remaining); remaining = Math.max(0,remaining - item.applied); chosen.set(key,item); }
  }
  function tgOptionsHtml() {return `<option value="">Select invoice / advance</option>${availableItems().filter(item=>!chosen.has(item.key)).map(item=>`<option value="${esc(item.key)}">${esc(itemLabel(item)+(item.carryForwardDraftId?' · Saved carry-forward':''))}</option>`).join('')}`;}
  function tgBasketHtml(){return `<div id="erTgBasket" class="tter-items">${[...chosen.values()].map(item=>`<div class="tter-item"><button type="button" data-tg-remove="${esc(item.key)}" class="tter-minus" aria-label="Remove ${esc(item.invoiceRef||'advance')}">−</button><div><b>${esc(item.invoiceRef||'ADVANCE')}</b><small>${esc(item.contractRef||'Unallocated advance')}</small></div><label>${esc(currency)}<input data-tg-amount="${esc(item.key)}" inputmode="decimal" value="${esc(item.applied)}" ${selectedTgPayment?'readonly':''}></label></div>`).join('')}</div><div class="tter-accounting" id="erTgTotal">TOTAL PAYMENT RECEIVED ${esc(currency)} ${fmt(selectedExpected())}</div><div class="tter-note" id="erTgCorrespondent"></div>`;}
  function renderTgBasket(){
    const panel=q('#ttExportReceiptDialog'),dialog=panel?.querySelector('.tter-dialog'),scroll=dialog?.scrollTop||0;
    const controls=[...panel.querySelectorAll('input[id]:not([type=file]),textarea[id],select[id]')].filter(el=>!['erTgItem','erTgAdvance'].includes(el.id)).map(el=>({id:el.id,value:el.value,checked:el.checked,edited:el.dataset.edited}));
    const file=q('#erAdviceFile'),files=file?.files;captureDeductions();render();
    for(const saved of controls){const el=document.getElementById(saved.id);if(el){el.value=saved.value;el.checked=saved.checked;if(saved.edited)el.dataset.edited=saved.edited;}}
    if(files?.length&&q('#erAdviceFile'))q('#erAdviceFile').files=files;
    if(q('#erForeign')&&!q('#erForeign').dataset.edited)q('#erForeign').value=String(selectedExpected()||'');
    updateRetentionDetails();calc();if(dialog)dialog.scrollTop=scroll;
  }
  function itemsHtml() {
    const rows = availableItems();
    if (!rows.length) return '<div class="tter-alert">No outstanding Export item matches this payer and currency.</div>';
    return `<div class="tter-items">${rows.map(item => { const selected = chosen.get(item.key); return `<div class="tter-item"><input type="checkbox" data-er-item="${esc(item.key)}" ${selected ? 'checked' : ''}><div><b>${esc(itemLabel(item))}</b><small>${esc(item.isTg ? (item.recognized ? 'Posted TG intercompany invoice' : 'Held as unapplied TG until invoice / FI allocation') : item.customer)}</small></div><label>Apply ${esc(item.currency)}<input inputmode="decimal" data-er-applied="${esc(item.key)}" value="${esc(selected?.applied ?? '')}" ${selected ? '' : 'disabled'}></label></div>`; }).join('')}</div>`;
  }
  function chargeRule(code) { return (data?.deductionMaster || []).find(row => row.code === code); }
  function activeCharge(row) { const date=q('#erDate')?.value || today();return row && !/inactive|historical|draft/i.test(row.status || '') && (!row.effectiveFrom || date >= row.effectiveFrom) && (!row.effectiveTo || date <= row.effectiveTo) && (!row.entities || row.entities.toUpperCase().includes(entity())); }
  function regularRows() { return regularCodes.map(code => {const master=chargeRule(code);return {code,amount:'',percent:master?.rate && Number.isFinite(Number(master.rate)) ? master.rate : '',mode:'DEDUCTED',taxSection:''};}); }
  function deductionOptions(value = '') { return `<option value="">Choose charge</option>${(data?.deductionMaster || []).filter(row => activeCharge(row) && !regularCodes.includes(row.code)).map(row => `<option value="${esc(row.code)}" ${row.code === value ? 'selected' : ''}>${esc(row.name)}</option>`).join('')}`; }
  function deductionRowHtml(row,index) { const fixed=regularCodes.includes(row.code) && index<3, rule=chargeRule(row.code), base=rule?.base === 'PKR_PAYMENT' ? 'On full payment PKR equivalent before correspondent charges' : rule?.base?.startsWith('CHARGE:') ? `On ${chargeRule(rule.base.slice(7))?.name || 'selected charge'}` : 'Actual bank amount';return `<div class="tter-ded ${fixed?'tter-ded-fixed':'tter-ded-extra'}" data-er-ded="${index}"><label>${fixed?`<b>${esc(rule?.name || row.code)}</b><input data-ded-code type="hidden" value="${esc(row.code)}">`:`<span class="tter-ded-head">Additional charge</span><select data-ded-code>${deductionOptions(row.code)}</select>`}<span class="tter-ded-base" data-ded-base>${esc(base)}</span></label><label><span class="tter-ded-head">%</span><input data-ded-percent type="number" min="0" max="100" step="any" value="${esc(row.percent ?? rule?.rate ?? '')}" ${rule?.base && (rule.base==='PKR_PAYMENT'||rule.base.startsWith('CHARGE:'))?'':'disabled'}></label><label><span class="tter-ded-head">PKR amount</span><input data-ded-amount inputmode="decimal" value="${esc(row.amount || '')}" ${row.manual?'data-manual="1"':''}></label><div class="tter-ded-action">${fixed?'':`<button type="button" data-ded-remove aria-label="Remove charge">×</button>`}</div></div>`; }
  function deductionsHtml() { return deductions.map(deductionRowHtml).join(''); }
  function historyHtml() { const rows = (data?.receipts || []).filter(row => row.recordType !== 'FOREIGN_BANK_SHORTFALL_ADJUSTMENT').slice(0,12); return rows.length ? `<details class="tter-step"><summary style="font-weight:800;cursor:pointer">Recent Posted Receipts</summary><div class="tter-history"><table><thead><tr><th>Date</th><th>Advice</th><th>From</th><th>Foreign</th><th>Bank Credit</th><th>Status</th></tr></thead><tbody>${rows.map(row => `<tr><td>${esc(row.date)}</td><td>${esc(row.bankAdviceRef)}${row.partialReceipt?'<br><small>Part '+esc(row.paymentPartRef)+'</small>':''}${row.fiTag?'<br><small>'+esc('FI '+row.fiTag.fiNumber+' · '+row.fiTag.fiDate.split('-').reverse().join('-'))+(Math.abs(Number(row.fiTag.amountDifference))>.001?' · '+esc(row.fiTag.currency)+' '+fmt(row.fiTag.amountDifference)+' DIFFERENCE':'')+'</small>':''}</td><td>${esc(row.remitter || '')}</td><td>${esc(row.transactionCurrency)} ${fmt(row.foreignAmount)}</td><td>${row.nativeBankCredit ? esc(row.transactionCurrency)+' '+fmt(row.nativeBankCredit) : 'PKR '+fmt(row.pkrBankCredit)}</td><td>${esc(row.status||'Posted')}${row.replacementReceiptId?' → '+esc(row.replacementReceiptId):''}</td></tr>`).join('')}</tbody></table></div></details>` : ''; }
  function fullFormHtml() {
    if (!chosen.size) return '';
    const directForeign = selectedBankCurrency() !== 'PKR';
    if (!directForeign && deductions.length < 3) deductions = [...regularRows(),...deductions];
    return `<section class="tter-step active"><h3>Receipt and Credit Advice</h3><div class="tter-grid"><label>Credit Date<input id="erDate" type="date" value="${today()}"></label><label>Bank Advice / Transaction Ref<input id="erBankRef"></label><label>Currency<input value="${esc(currency)}" readonly></label><label style="grid-column:1/-1"><input id="erPartialReceipt" type="checkbox" ${selectedTgPayment?'disabled':''} style="width:auto" ${partialReceipt?'checked':''}> Partial Payment Receipt</label><div class="tter-note">For each split receipt, enter its payment part reference and allocate only this part to the selected invoices. Blank correspondent charges means zero.</div><label id="erPaymentPartField" ${partialReceipt?'':'hidden'}>Payment Part Reference<input id="erPaymentPart" placeholder="1, 2 or bank conversion reference"></label><label>Foreign Amount Received<input id="erForeign" inputmode="decimal"></label><label>Bank Advice Realization Rate (PKR per ${esc(currency)})<input id="erRate" inputmode="decimal"></label><label>PKR Equivalent<input id="erGross" inputmode="decimal" readonly></label><label>Actual ${directForeign ? esc(currency) : "PKR"} Credited<input id="erBankCredit" inputmode="decimal"></label>${directForeign ? "" : `<label style="grid-column:1/-1"><input id="erUseRetention" type="checkbox" style="width:auto"> Retain part of this ${esc(currency)} receipt in the company retention account</label><div id="erRetentionFields" class="tter-grid" style="grid-column:1/-1;display:none"><label>Amount to retain (${esc(currency)})<input id="erRetention" value="0" inputmode="decimal"></label><label>Company retention account<select id="erRetentionBank">${retentionBankOptions(currency)}</select></label><div id="erRetentionDetails" class="tter-note" style="grid-column:1/-1"></div></div>`}</div>
      ${directForeign?'':`<div class="tter-step active" id="erBankDeductions"><h3>Bank deductions</h3><div class="tter-note">Enter percentages or adjust PKR amounts to match the bank advice.</div><div id="erDeductions">${deductionsHtml()}</div><label id="erCorrespondentField" ${partialReceipt?'':'hidden'}>Correspondent Bank Charges (${esc(currency)})<input id="erCorrespondentAmount" inputmode="decimal" placeholder="0.00"></label><button type="button" class="btn" id="erAddDeduction">+ Add charge or tax</button></div>`}
      ${amendmentOf?`<div class="tter-alert"><b>Correcting Post ${esc(amendmentOf)}</b><br>The original and every linked TG/tax posting will be reversed and the corrected entry posted together. Original Post IDs remain in the register.<label>Amendment reason<input id="erAmendReason" value="${esc(amendmentReason)}" placeholder="Explain the correction"></label></div>`:''}<div class="tter-grid" style="margin-top:12px"><label style="grid-column:1/-1">Upload Credit Advice<input id="erAdviceFile" type="file" accept="application/pdf,image/jpeg,image/png,image/webp"></label></div></section>
      <div class="tter-accounting"><strong>Accounting entries before posting</strong><div id="erAccountingRows"><div class="tter-note">Enter receipt amounts to see the Debit / Credit entries.</div></div></div><div class="tter-actions"><button class="btn green" id="erPost">POST RECEIPT</button></div>`;
  }
  function render() {
    const panel = ensurePanel(); if (!panel) return;
    if (pendingReceipt) {
      panel.innerHTML = `<div class="tter-head"><b>Export Payment Receipt / Credit Advice</b><div class="sp"></div><button class="btn" data-er-close>Close</button></div><div class="tter-body"><div class="tter-alert">Pakistan receipt ${esc(pendingReceipt.receipt.id)} is posted. Its TG bank deduction must be reviewed and confirmed in TG Remittances.</div><button class="btn green" id="erRetryTg">Continue to receipt list</button></div>`;
      q('[data-er-close]').onclick=closeForm;
      q('#erRetryTg').onclick = retryTgSettlement;
      return;
    }
    const eligibleBanks = [...receiptBanks('PKR'),...receiptBanks(currency)];
    panel.innerHTML = `<div class="tter-head"><div><b>Export Payment Receipt / Credit Advice</b><div class="tter-note">FI is maintained only in Exports. Accounts selects the linked outstanding item and posts the bank entry.</div></div><div class="sp"></div><button class="btn" data-er-close>Close</button></div><div class="tter-body">
      <section class="tter-step active"><h3>▰ Received into company account</h3><div class="tter-grid"><label>Received Into Account<select id="erPkrBank">${bankOptions('PKR',bankId)}</select></label></div>${(banks?.accounts||[]).filter(row=>!eligibleBanks.some(ready=>ready.id===row.id)).map(row=>`<div class="tter-alert"><b>${esc(bankLabel(row))} · ${esc(row.currency||'currency missing')}</b><br>${esc(bankUnavailableReason(row))}.</div>`).join('')}${!(banks?.accounts||[]).length ? `<div class="tter-alert">No ${esc(entity())} bank account is linked in Company Master. Add the company bank and its account number or IBAN there.</div>` : ''}</section>
      ${bankId ? `<section class="tter-step active" id="erPayerStep"><h3>⇠ Received from</h3><div class="tter-parties"><button type="button" class="tter-party ${payerType === 'TG' ? 'active' : ''}" data-payer-type="TG"><b>Trans Grains (TG)</b><div class="tter-note">Enter the credit advice details; invoice references are linked when available.</div></button><button type="button" class="tter-party ${payerType === 'CUSTOMER' ? 'active' : ''}" data-payer-type="CUSTOMER"><b>Export Customer</b><div class="tter-note">Choose the customer, then its advance or balance items.</div></button></div>${payerType === 'CUSTOMER' ? `<div class="tter-grid" style="margin-top:10px"><label>Customer<select id="erPayer"><option value="">Choose customer</option>${payers().map(name => `<option ${name === payer ? 'selected' : ''}>${esc(name)}</option>`).join('')}</select></label><button type="button" class="btn tter-manage-customer" id="erManageCustomer">+ Add / Amend Customer</button></div>` : ''}</section>` : ''}

      ${payer ? `<section class="tter-step active"><h3>${payerType==='TG'?'TG payment for':'▤ Outstanding item(s)'}</h3><div class="tter-grid" style="margin-bottom:10px"><label>Receipt Currency<select id="erCurrency" ${selectedTgPayment?'disabled':''}>${[...new Set([...(window.TT_CURRENCY_MASTER?.codes?.()||['USD','EUR','GBP','AED','PKR']),currency])].map(code => `<option ${code === currency ? 'selected' : ''}>${esc(code)}</option>`).join('')}</select></label>${payerType==='TG'?`<label>Sender account<select id="erTgBank" ${selectedTgPayment?'disabled':''}><option value="">Select TG bank</option>${(tgData?.banks||[]).filter(bank=>bank.currency===currency).map(bank=>`<option value="${esc(bank.id)}" ${bank.id===tgBankId?'selected':''}>${esc(bank.bank||bank.title)} · ${esc(bank.title)} · ${esc(bank.currency)} ${fmt(bank.balance?.native||0)}</option>`).join('')}</select></label><label>Invoice / Advance<select id="erTgItem" data-tt-native="1" ${selectedTgPayment?'disabled':''}>${tgOptionsHtml()}</select></label>${advanceEntry?`<label>Advance amount (${esc(currency)})<div style="display:flex;gap:8px;align-items:center"><input id="erTgAdvance" inputmode="decimal"><button type="button" id="erAddTgAdvance" class="btn green" aria-label="Add TG advance">＋</button></div></label>`:''}`:''}</div>${payerType==='TG'?`${tgBasketHtml()}${[...chosen.values()].some(item=>item.carryForwardDraftId)?'<div class="tter-alert">This carry-forward shipment was saved as a draft. Post its saved information to link the invoice before recording payment. <button class="btn" id="erPostCarry">Post carry-forward shipment</button></div>':''}<div class="tter-note">${(tgData?.banks||[]).some(bank=>bank.currency===currency)?'Advance remains unallocated in Exports until its FI is entered and used.':'No TG '+esc(currency)+' bank is configured. Choose a currency with a TG bank or add that bank in Company Master before posting.'}${!availableItems().some(item=>!(item.targetType==='UNAPPLIED_TG' && !item.invoiceRef))?' No saved TG Pack invoice is currently available for '+esc(entity())+' in '+esc(currency)+'. An invoice appears after the TG Pack and Customs are saved for this Pakistan exporter with a positive invoice value.':''}</div>`:`<div class="tter-note">Multiple items may be selected. Display order is amount → narration/reference → bare contract or internal document number.</div><div id="erItems">${itemsHtml()}</div>`}</section>` : ''}
      ${(data?.pendingTg||[]).length?`<section class="tter-step active"><h3>New from TG</h3>${(data.pendingTg||[]).map(row=>`<div class="tter-note">${esc(row.date||'')} · ${esc(row.currency)} ${fmt(row.amount)} · ${esc(row.invoiceRef||'Advance')} <button type="button" class="btn" data-er-open-tg="${esc(row.id)}">Open receipt</button></div>`).join('')}</section>`:''}
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
    q('#ttPendingTgPayments')?.remove();
    const icon=q('[data-tt-area="exports"] .tt-area-glyph');if(!icon||!['TTI','BRM'].includes(entity()))return;
    icon.style.position='relative';icon.querySelector('[data-tt-update-badge]')?.remove();
    const key=`tt-updates-${access.user||'Accounts'}-${entity()}-tg`,seen=new Set(JSON.parse(localStorage.getItem(key)||'[]'));
    const unread=(data?.pendingTg||[]).filter(row=>!seen.has(row.id));
    if(unread.length){const badge=document.createElement('span');badge.dataset.ttUpdateBadge='1';badge.textContent=String(unread.length);badge.style.cssText='position:absolute;top:-9px;right:-10px;min-width:21px;height:21px;padding:0 4px;border-radius:12px;background:#168248;color:#fff;display:grid;place-items:center;font:bold 12px Arial;box-sizing:border-box';icon.appendChild(badge)}
    const area=icon.closest('[data-tt-area]');if(area&&!area.dataset.tgBadgeWired){area.dataset.tgBadgeWired='1';area.addEventListener('click',()=>{localStorage.setItem(key,JSON.stringify((data?.pendingTg||[]).map(row=>row.id)));icon.querySelector('[data-tt-update-badge]')?.remove()})}
  }
  function captureDeductions() { deductions = qa('[data-er-ded]').map(row => ({code:row.querySelector('[data-ded-code]').value,amount:row.querySelector('[data-ded-amount]').value,percent:row.querySelector('[data-ded-percent]').value,manual:row.querySelector('[data-ded-amount]').dataset.manual==='1',mode:'DEDUCTED',taxSection:''})); }
  function calculateChargeRows(gross) {
    const rows=qa('[data-er-ded]'),byCode=new Map(rows.map(row=>[row.querySelector('[data-ded-code]').value,row]).filter(([code])=>code)),working=new Set();
    function amount(row){const field=row.querySelector('[data-ded-amount]'),code=row.querySelector('[data-ded-code]').value,rateField=row.querySelector('[data-ded-percent]'),rule=chargeRule(code);if(!rule||field.dataset.manual==='1'||rateField.value==='')return num(field.value);if(working.has(code))return 0;working.add(code);let base=0;if(rule.base==='PKR_PAYMENT')base=gross;else if(rule.base?.startsWith('CHARGE:')){const parent=byCode.get(rule.base.slice(7));if(parent)base=amount(parent);}working.delete(code);const computed=Math.round(base * num(rateField.value))/100;field.value=base>0?computed.toFixed(2):'';return computed;}
    rows.forEach(amount);captureDeductions();
  }
  function bindDeductionRows(){qa('[data-er-ded]').forEach(row=>{const code=row.querySelector('[data-ded-code]'),pct=row.querySelector('[data-ded-percent]'),amount=row.querySelector('[data-ded-amount]');code.onchange=()=>{const rule=chargeRule(code.value);pct.value=rule?.rate && Number.isFinite(Number(rule.rate))?rule.rate:'';pct.disabled=!(rule?.base==='PKR_PAYMENT'||rule?.base?.startsWith('CHARGE:'));amount.value='';delete amount.dataset.manual;row.querySelector('[data-ded-base]').textContent=rule?.base==='PKR_PAYMENT'?'On full payment PKR equivalent before correspondent charges':rule?.base?.startsWith('CHARGE:')?`On ${chargeRule(rule.base.slice(7))?.name||'selected charge'}`:'Actual bank amount';calc();};pct.oninput=()=>{delete amount.dataset.manual;calc();};amount.oninput=()=>{amount.dataset.manual='1';calc();};row.querySelector('[data-ded-remove]')?.addEventListener('click',()=>{row.remove();qa('[data-er-ded]').forEach((item,index)=>item.dataset.erDed=index);calc();});});}
  function updateRetentionDetails() {
    const enabled=!!q('#erUseRetention')?.checked,fields=q('#erRetentionFields');
    if(fields)fields.style.display=enabled?'grid':'none';
    if(!enabled&&q('#erRetention'))q('#erRetention').value='0';
    const account=(banks?.accounts||[]).find(row=>row.id===q('#erRetentionBank')?.value);
    const details=q('#erRetentionDetails');if(details)details.textContent=account?`${account.bankName||'Bank'} · ${account.accountNumber||account.iban||''} · ${account.currency} ${account.swift?'· SWIFT '+account.swift:''} · ${account.accountTitle||''}`:'Select a designated retention account in this company and currency.';
    calc();
  }
  function bind() {
    qa('[data-er-open-tg]').forEach(button=>button.onclick=()=>openForm(button.dataset.erOpenTg));
    q('#erUseRetention')?.addEventListener('change',updateRetentionDetails);
    q('#erRetentionBank')?.addEventListener('change',updateRetentionDetails);
    q('[data-er-close]')?.addEventListener('click', closeForm);
    q('#erPkrBank')?.addEventListener('change', event => { bankId = event.target.value; if (!selectedTgPayment) { payerType = ''; payer = ''; chosen.clear(); } render(); });
    qa('[data-payer-type]').forEach(button => { button.onclick = async () => {const generation=formGeneration,company=entity(); if(selectedTgPayment)return; payerType = button.dataset.payerType; payer = payerType === 'TG' ? 'TG' : ''; advanceEntry=false;chosen.clear(); if(payerType==='TG'){try{await loadTg();tgBankId=(tgData.banks||[]).find(bank=>bank.currency===currency&&bank.settings?.defaultReceiptAccount)?.id||(tgData.banks||[]).find(bank=>bank.currency===currency)?.id||'';}catch(error){return toast(error.message,false)}}if(generation!==formGeneration||company!==entity())return; render(); }; });
    q('#erTgBank')?.addEventListener('change',event=>{tgBankId=event.target.value});
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
    q('#erPostCarry')?.addEventListener('click',async event=>{
      const item=[...chosen.values()].find(row=>row.carryForwardDraftId);if(!item)return;
      if(!confirm('Post this saved carry-forward shipment and make its invoice balances available to Accounts?'))return;
      const button=event.currentTarget;button.disabled=true;item.carryForwardRequestKey ||= crypto.randomUUID();
      const dialog=q('#ttExportReceiptDialog .tter-dialog'),scroll=dialog?.scrollTop||0;
      const fields=[...document.querySelectorAll('#ttExportReceiptDialog input,#ttExportReceiptDialog textarea,#ttExportReceiptDialog select')].map(el=>{
        const deduction=el.closest('[data-er-ded]'),attribute=['data-ded-code','data-ded-percent','data-ded-amount','data-ded-mode','data-ded-tax'].find(name=>el.hasAttribute(name));
        const selector=el.id?`#${el.id}`:(deduction&&attribute?`[data-er-ded="${deduction.dataset.erDed}"] [${attribute}]`:'');
        return {selector,value:el.value,checked:el.checked,manual:el.dataset.manual,edited:el.dataset.edited};
      }).filter(field=>field.selector);
      try{
        const response=await fetch('../api/carry_forward_shipments.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify({action:'publish',entity:entity(),id:item.carryForwardDraftId,version:item.carryForwardVersion,requestKey:item.carryForwardRequestKey,csrf:access.csrf})});
        const result=await response.json();if(!response.ok||!result.ok)throw Error(result.error||'Could not post the carry-forward shipment.');
        data=await get(`${receiptApi}?entity=${encodeURIComponent(entity())}`);await loadTg();
        const linked=sourceRows().find(row=>!row.carryForwardDraftId&&row.recognized&&(row.key===item.key||(row.contractRef===item.contractRef&&row.invoiceRef===item.invoiceRef&&row.currency===item.currency)));if(!linked)throw Error('Shipment posted; reopen the receipt to load its linked invoice.');
        chosen.delete(item.key);chosen.set(linked.key,{...linked,applied:item.applied});render();
        for(const saved of fields){const el=q(saved.selector);if(el){el.value=saved.value;el.checked=saved.checked;if(saved.manual)el.dataset.manual=saved.manual;if(saved.edited)el.dataset.edited=saved.edited;}}if(q('#erTgItem'))q('#erTgItem').value=linked.key;updateRetentionDetails();calc();if(dialog)dialog.scrollTop=scroll;toast('Carry-forward shipment posted. Continue with this payment.');
      }catch(error){toast(error.message,false);button.disabled=false;}
    });
    q('#erTgItem')?.addEventListener('change',event=>{if(selectedTgPayment)return;const item=availableItems().find(row=>row.key===event.target.value);if(!item)return;advanceEntry=item.key==='TGADV';if(!advanceEntry&&!chosen.has(item.key))chosen.set(item.key,{...item,applied:item.amount||0});renderTgBasket();q('#erTgAdvance')?.focus();});
    q('#erAddTgAdvance')?.addEventListener('click',()=>{const amount=num(q('#erTgAdvance')?.value);if(!amount)return toast('Enter a positive advance amount.',false);const item=availableItems().find(row=>row.key==='TGADV');chosen.set(item.key,{...item,amount,applied:amount});advanceEntry=false;renderTgBasket();});
    qa('[data-tg-remove]').forEach(button=>{button.disabled=!!selectedTgPayment;button.onclick=()=>{chosen.delete(button.dataset.tgRemove);renderTgBasket();};});
    qa('[data-tg-amount]').forEach(input=>{input.oninput=()=>{const item=chosen.get(input.dataset.tgAmount);item.applied=num(input.value);if(item.key==='TGADV')item.amount=item.applied;if(q('#erForeign')&&!q('#erForeign').dataset.edited)q('#erForeign').value=String(selectedExpected());calc();};});
    qa('[data-er-item]').forEach(box => { box.onchange = () => { if(selectedTgPayment){box.checked=true;return}const item = availableItems().find(row => row.key === box.dataset.erItem); if (box.checked && item) chosen.set(item.key,{...item,applied:item.amount||0}); else chosen.delete(box.dataset.erItem); renderTgBasket(); }; });
    qa('[data-er-applied]').forEach(input => { input.oninput = () => { const item = chosen.get(input.dataset.erApplied); if (item) { item.applied = num(input.value); chosen.set(input.dataset.erApplied,item); } calc(); }; });
    q('#erCurrency')?.addEventListener('change', event => { if(selectedTgPayment)return; currency = event.target.value; advanceEntry=false;tgBankId = (tgData?.banks||[]).find(bank=>bank.currency===currency&&bank.settings?.defaultReceiptAccount)?.id||(tgData?.banks||[]).find(bank=>bank.currency===currency)?.id||''; chosen.clear(); render(); });
    q('#erAddDeduction')?.addEventListener('click', () => { const host=q('#erDeductions');if(!host)return;host.insertAdjacentHTML('beforeend',deductionRowHtml({code:'',amount:'',percent:'',mode:'DEDUCTED',taxSection:''},qa('[data-er-ded]').length));bindDeductionRows();calc(); });
    bindDeductionRows();
    ['erForeign','erRate','erBankCredit','erRetention'].forEach(id => q(`#${id}`)?.addEventListener('input', () => { if (id === 'erForeign') { q('#erForeign').dataset.edited='1';redistribute(); qa('[data-er-applied]').forEach(input => { input.value = chosen.get(input.dataset.erApplied)?.applied || ''; }); } if(id==='erBankCredit')q('#erBankCredit').dataset.edited='1'; calc(); }));
    q('#erDate')?.addEventListener('change',calc);
    q('#erPartialReceipt')?.addEventListener('change',event=>{partialReceipt=event.target.checked;q('#erPaymentPartField').hidden=!partialReceipt;q('#erCorrespondentField')&&(q('#erCorrespondentField').hidden=!partialReceipt);redistribute();qa('[data-er-applied]').forEach(input=>input.value=chosen.get(input.dataset.erApplied)?.applied||'');calc();});q('#erCorrespondentAmount')?.addEventListener('input',()=>{redistribute();qa('[data-er-applied]').forEach(input=>input.value=chosen.get(input.dataset.erApplied)?.applied||'');calc();});q('#erPaymentPart')?.addEventListener('input',calc);q('#erPost')?.addEventListener('click',postReceipt);q('#erAmendReason')?.addEventListener('input',event=>{amendmentReason=event.target.value});
  }
  function calc() {
    if (!q('#erForeign')) return;
    const expected = selectedExpected(), received = num(q('#erForeign').value), rate = num(q('#erRate')?.value), gross = Math.round(received*rate*100)/100, retentionPkr = num(q('#erRetention')?.value) * rate;
    if(q('#erGross'))q('#erGross').value=gross?gross.toFixed(2):'';
    calculateChargeRows(partialReceipt||payerType==='TG'&&!selectedTgPayment ? Math.round(expected*rate*100)/100 : gross);
    const directForeign=selectedBankCurrency()!=='PKR',deducted = deductions.reduce((sum,row) => sum + (row.mode === 'DEDUCTED' && row.code ? num(row.amount) : 0),0), bankExpected = directForeign ? received : Math.max(0,(partialReceipt||payerType==='TG'&&!selectedTgPayment ? Math.round(expected*rate*100)/100-Math.round((partialReceipt?num(q('#erCorrespondentAmount')?.value):Math.max(0,expected-received))*rate*100)/100 : gross) - retentionPkr - deducted), bankField=q('#erBankCredit');
    if(bankField&&directForeign&&!bankField.dataset.edited)bankField.value=bankExpected?bankExpected.toFixed(2):'';
    const bankCredit = num(bankField?.value), shortfall = partialReceipt?num(q('#erCorrespondentAmount')?.value):Math.max(0,expected - received);
    const allocation = [...chosen.values()].reduce((sum,item) => sum + num(item.applied),0), retentionAmount=num(q('#erRetention')?.value), retentionValid=retentionAmount<=received&&(!retentionAmount||!!q('#erRetentionBank')?.value), balanced = [...chosen.values()].every(item=>num(item.applied)>0&&(!item.invoiceRef||num(item.applied)<=Number(item.amount)+.005)&&!item.carryForwardDraftId) && (partialReceipt?Math.abs(allocation-received-shortfall)<.005:(payerType==='TG'&&!selectedTgPayment?received<=expected+.005&&[...chosen.values()].every(item=>num(item.applied)>0&&(!item.invoiceRef||num(item.applied)<=Number(item.amount)+.005)&&!item.carryForwardDraftId):Math.abs(allocation - received) <= .01)) && (directForeign ? Math.abs(bankCredit-bankExpected) <= .01 : Math.abs(bankCredit-bankExpected) < 1) && retentionValid, incomplete=deductions.some(row => !row.code && num(row.amount)>0),duplicate=new Set(deductions.filter(row=>row.code&&num(row.amount)>0).map(row=>row.code)).size!==deductions.filter(row=>row.code&&num(row.amount)>0).length;
    const preview = q('#erAccountingRows'); const rounding=directForeign?0:Math.round((bankExpected-bankCredit)*100)/100; if (preview) preview.innerHTML = `${Math.abs(rounding)>0.00001&&Math.abs(rounding)<1?`<div class="tter-accounting-row"><b>${rounding>0?'Debit':'Credit'}</b><span>Minor rounding difference</span><strong>PKR ${fmt(Math.abs(rounding))}</strong></div>`:''}${bankCredit ? `<div class="tter-accounting-row"><b>Debit</b><span>Selected ${directForeign ? currency : 'PKR'} company bank</span><strong>${directForeign ? currency+' '+fmt(bankCredit)+' (PKR '+fmt(gross)+')' : 'PKR '+fmt(bankCredit)}</strong></div>` : ''}${retentionPkr ? `<div class="tter-accounting-row"><b>Debit</b><span>Foreign retention bank</span><strong>PKR ${fmt(retentionPkr)}</strong></div>` : ''}${deductions.filter(row=>row.code&&num(row.amount)>0).map(row=>`<div class="tter-accounting-row"><b>Debit</b><span>${esc(chargeRule(row.code)?.name||row.code)} · ${esc(chargeRule(row.code)?.account||'GL')}</span><strong>PKR ${fmt(num(row.amount))}</strong></div>`).join('')}${(partialReceipt||payerType==='TG'&&!selectedTgPayment)&&shortfall>.005?`<div class="tter-accounting-row"><b>Debit</b><span>Correspondent Bank Charges · 6810</span><strong>PKR ${fmt(Math.round(shortfall*rate*100)/100)}</strong></div>`:''}${gross ? [...chosen.values()].filter(item=>num(item.applied)>0).map(item=>`<div class="tter-accounting-row"><b>Credit</b><span>${esc(item.targetType==='INTERCOMPANY_RECEIVABLE'?'TG intercompany receivable':item.targetType==='UNAPPLIED_TG'?'Unapplied TG advance / payment':item.targetType==='EXPORT_RECEIVABLE'?'Export receivable':'Customer advance')}</span><strong>PKR ${fmt(received?gross*num(item.applied)/received:0)}</strong></div>`).join('') : '<div class="tter-note">Enter the advice amounts to see the posting.</div>'}<div class="tter-note ${balanced&&!incomplete&&!duplicate ? 'tter-ok' : 'tter-bad'}">${directForeign ? currency : 'PKR'} balance: ${fmt(bankExpected-bankCredit)}${shortfall>.005?(partialReceipt||payerType==='TG'&&!selectedTgPayment?' · Correspondent bank charges '+currency+' '+fmt(shortfall):' · Remaining foreign amount stays outstanding'):''}${incomplete?' · Choose a type for each additional charge':''}${duplicate?' · Each charge may be entered once':''}</div>`;
    if(q('#erTgTotal'))q('#erTgTotal').textContent=`TOTAL PAYMENT RECEIVED ${currency} ${fmt(expected)}`;if(q('#erTgCorrespondent'))q('#erTgCorrespondent').textContent=shortfall>.005?`CORRESPONDENT BANK CHARGES ${currency} ${fmt(Math.round(shortfall*100)/100)} · PKR ${fmt(Math.round(shortfall*rate*100)/100)}`:'';
    const post = q('#erPost'); if (post) post.disabled = !(received > 0 && rate > 0 && bankField?.value!=='' && chosen.size && balanced && (!partialReceipt||q('#erPaymentPart')?.value.trim()) && !incomplete && !duplicate);
  }
  function allocations() {
    return [...chosen.values()].filter(item => num(item.applied) > 0).map(item => ({targetType:item.targetType,targetId:item.targetId,contractRef:item.contractRef,invoiceRef:item.invoiceRef,customer:item.customer,foreignAmount:num(item.applied)}));
  }
  function deductionPayload() { return deductions.filter(row => row.code && num(row.amount) > 0).map(row => ({masterCode:row.code,amount:num(row.amount),percentage:row.percent,autoCalculate:!row.manual&&row.percent!=='',mode:'DEDUCTED',taxSection:row.taxSection})); }
  async function uploadAdvice() {
    const file = q('#erAdviceFile')?.files?.[0]; if (!file) return '';
    const form = new FormData(); form.append('csrf',access.csrf); form.append('entity',entity()); form.append('adviceFile',file);
    const response = await fetch('../api/accounts_receipt_file.php',{method:'POST',credentials:'same-origin',body:form,headers:{Accept:'application/json'}}); let body = {}; try { body = await response.json(); } catch (_) {}
    if (!response.ok || !body.ok) throw new Error(body.error || 'Credit advice upload failed.'); return body.token;
  }
  async function postShortfalls(receipt, classification, note, evidence) {
    if (partialReceipt||payerType==='TG'||classification !== 'CORRESPONDENT') return;
    for (const item of chosen.values()) {
      const gap = Math.max(0,Number(item.amount)-num(item.applied)); if (gap <= .005) continue;
      if (!item.recognized || !item.targetId) throw new Error('A correspondent-bank shortfall can close only a posted invoice/TG receivable. Use Partial for an advance.');
      const payload = {action:'post_shortfall',csrf:access.csrf,entity:entity(),candidateId:item.targetId,linkedReceiptId:receipt.id,foreignShortfall:gap,evidenceRef:evidence,notes:note || 'Confirmed correspondent bank charge from credit advice',confirmedBankCharge:true};
      const response = await fetch(shortfallApi,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify(payload)}); let body = {}; try { body = await response.json(); } catch (_) {}
      if (!response.ok || !body.ok) throw new Error(body.error || 'Receipt posted, but the correspondent-bank shortfall needs Accounts review.');
    }
  }
  function validateTgSettlement(classification) {
    if (payerType !== 'TG'||selectedTgPayment) return;
    if (!tgBankId) throw new Error('Choose the TG account from which this payment was made.');
    const bank = (tgData?.banks || []).find(row => row.id === tgBankId);
    if (!bank || String(bank.currency || '').toUpperCase() !== currency) throw new Error(`Choose a TG ${currency} payment account.`);
    let total = 0;
    for (const item of chosen.values()) {
      if(!(item.targetType==='UNAPPLIED_TG' && !item.invoiceRef)){
        if(item.carryForwardDraftId)throw new Error('Click Post carry-forward shipment above before posting its payment.');
        if (!item.recognized || !item.mirrorCandidateId) throw new Error('This TG invoice must be recognized in Accounts before settlement; use Advance when it has not been invoiced.');
        if (!amendmentOf && !(tgData?.openLiabilities || []).some(liability => liability.id === item.mirrorCandidateId)) throw new Error(`TG payable ${item.invoiceRef || item.reference} is not posted or has no open balance.`);
      }
      total += !partialReceipt&&classification === 'CORRESPONDENT' ? Number(item.amount || 0) : num(item.applied);
    }
  }
  async function retryTgSettlement() {
    if(!pendingReceipt)return;
    const posted=pendingReceipt.receipt;
    try{
      await load();savePending(null);payerType='';payer='';chosen.clear();deductions=[];render();pendingBanner();
      toast(`Receipt ${posted.id} is already posted. Review its pending payment in TG Remittances before confirming the TG bank deduction.`);
    }catch(error){toast(error.message||'Could not load the posted receipt.',false);}
  }
  function offerReceiptPrint(receipt) {
    const header=q('#ttExportReceiptDialog [data-er-panel] .tter-head');if(!header||!receipt)return;
    const button=document.createElement('button');button.type='button';button.className='btn';button.textContent='Print Receipt Voucher';
    button.onclick=()=>window.TT_ACCOUNTING_DESK?.printVoucher?.({title:receipt.id,type:'Receipt',date:receipt.date,party:receipt.remitter,amount:receipt.foreignAmount,data:{...receipt,amount:receipt.foreignAmount,currency:receipt.transactionCurrency,reference:receipt.bankAdviceRef}});
    header.insertBefore(button,header.querySelector('[data-er-close]'));
  }
  async function postReceipt() {
    const expected = selectedExpected(), received = num(q('#erForeign')?.value), shortfall = partialReceipt?num(q('#erCorrespondentAmount')?.value):Math.max(0,expected-received), classification = shortfall > .005 ? (payerType==='TG'&&!selectedTgPayment?'CORRESPONDENT':'PARTIAL') : '', currentShortfallNote = '';

    const directForeign=selectedBankCurrency()!=='PKR';
    const body = {action:'post_receipt',partialReceipt,paymentPartRef:partialReceipt?q('#erPaymentPart')?.value.trim():'',...(partialReceipt?{correspondentForeignAmount:shortfall}:{}),csrf:access.csrf,entity:entity(),date:q('#erDate')?.value || today(),bankAdviceRef:q('#erBankRef')?.value.trim() || '',bankAdviceFileRef:'',bankAdvicePaperRef:'',remitter:payer,transactionCurrency:currency,foreignAmount:received,realizationRate:num(q('#erRate')?.value),grossPkrEquivalent:num(q('#erGross')?.value),bankAccountId:bankId,pkrBankCredit:directForeign?0:num(q('#erBankCredit')?.value),nativeBankCredit:directForeign?num(q('#erBankCredit')?.value):0,retentionForeignAmount:num(q('#erRetention')?.value),retentionBankAccountId:q('#erRetentionBank')?.value || '',tgPaymentId:selectedTgPayment?.id || '',tgBankAccountId:payerType==='TG'&&!selectedTgPayment?tgBankId:'',...(payerType==='TG'&&!selectedTgPayment?{tgPaymentTotal:expected}:{}),amendmentOf,amendmentReason:q('#erAmendReason')?.value.trim()||'',allocations:allocations(),deductions:directForeign?[]:deductionPayload(),shortfallClassification:classification,shortfallNote:currentShortfallNote};
    if(amendmentOf&&!body.amendmentReason)return toast('Enter an amendment reason.',false); if (!body.bankAccountId) return toast('Choose the company bank account first.',false); if (!body.bankAdviceRef) return toast('Enter the bank advice / transaction reference.',false);
    const button = q('#erPost'); button.disabled = true; button.textContent = 'POSTING…';
    try {
      if(payerType==='TG'&&!selectedTgPayment)validateTgSettlement(classification);
      body.bankAdviceFileRef = await uploadAdvice();
      const response = await fetch(receiptApi,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify(body)}); let result = {}; try { result = await response.json(); } catch (_) {}
      if (!response.ok || !result.ok) throw new Error(result.error || 'Export receipt could not be posted.');
      await postShortfalls(result.receipt,classification,currentShortfallNote,body.bankAdviceFileRef || body.bankAdvicePaperRef);
      savePending(null);amendmentOf='';amendmentReason='';
      toast(`Receipt ${result.receipt.id} posted.${result.ratesSaved===false?' Charge percentages could not be saved to the master; review them there.':''}`,result.ratesSaved!==false); payerType = ''; payer = ''; selectedTgPayment=null; partialReceipt=false; chosen.clear(); deductions = []; await load(); pendingBanner(); if(window.TT_POST_CONFIRMATION){closeForm();}else{render();offerReceiptPrint(result.receipt);} window.TT_BANK_ACCOUNTS_UI?.reload?.();
    } catch (error) { toast(pendingReceipt ? `Receipt ${pendingReceipt.receipt.id} posted. TG settlement needs review: ${String(error.message || error)}` : String(error.message || error),false); if (pendingReceipt) render(); else { button.disabled = false; button.textContent = 'POST RECEIPT'; } }
  }
  async function openForm(paymentId = '', amendId = '') {
    const generation=++formGeneration,company=entity(),current=()=>generation===formGeneration&&company===entity();
    if (!['TTI','BRM'].includes(entity())) return toast('Pakistan export receipts are available in TTI / BRM books.',false);
    try {
       const waiting=ensurePanel();waiting.innerHTML='<div class="tter-head"><b>Bank Receipt / Credit Advice</b><div class="sp"></div><button class="btn" data-er-close>Close</button></div><div class="tter-body">Loading company accounts and linked export receipts…</div>';waiting.querySelector('[data-er-close]').onclick=closeForm;
       await load();if(!current())return; pendingBanner(); restorePending(); selectedTgPayment=(data?.pendingTg||[]).find(row=>row.id===paymentId)||null;
       let previous=null;
       if(amendId){previous=(data?.receipts||[]).find(row=>row.id===amendId);if(!previous||previous.status!=='Accounts Approved / Posted'||previous.replacementReceiptId)throw new Error('This posted credit advice is not available for amendment.');
         amendmentOf=amendId;amendmentReason='';partialReceipt=!!previous.partialReceipt;bankId=previous.bankAccountId;currency=previous.transactionCurrency;payer=previous.remitter||'';payerType=payer==='TG'?'TG':'CUSTOMER';chosen.clear();
         if(payerType==='TG')await loadTg();if(!current())return;
         for(const allocation of previous.allocations||[]){const item=sourceRows().find(row=>row.key===`AMEND|${(previous.allocations||[]).indexOf(allocation)}`);if(item)chosen.set(item.key,{...item,applied:Number(allocation.foreignAmount||0)});}
         if(chosen.size!==(previous.allocations||[]).length)throw new Error('An original invoice or advance is no longer available. Review its linked Exports source before amending.');
         if(previous.tgPaymentId)selectedTgPayment={id:previous.tgPaymentId,amount:previous.foreignAmount,currency:previous.transactionCurrency,bankReference:previous.bankAdviceRef,candidateId:previous.allocations?.[0]?.targetId};
         tgBankId=previous.tgBankAccountId||(tgData?.banks||[]).find(bank=>bank.currency===currency&&bank.settings?.defaultReceiptAccount)?.id||'';
         const oldDeductions=(previous.deductions||[]).map(row=>({code:row.masterCode,amount:String(row.amount),percent:String(row.percentage||''),manual:true,mode:row.mode||'DEDUCTED',taxSection:row.taxSection||''}));deductions=[...regularCodes.map(code=>oldDeductions.find(row=>row.code===code)||{code,amount:'',percent:'',mode:'DEDUCTED',taxSection:''}),...oldDeductions.filter(row=>!regularCodes.includes(row.code))];
       }
      if(selectedTgPayment&&!previous){tgBankId=selectedTgPayment.sourceBankId||tgBankId;currency=selectedTgPayment.currency;payerType='TG';payer='TG';const item=sourceRows().find(row=>row.targetId===selectedTgPayment.candidateId&&row.isTg);if(!item)throw new Error('The linked Pakistan receivable is not recognized or has no remaining balance.');chosen=new Map([[item.key,{...item,applied:selectedTgPayment.amount}]]);shortfallClass=selectedTgPayment.amount<item.amount-.005?'PARTIAL':'';bankId=defaultReceiptBankId();}
       else if(!previous) bankId = defaultReceiptBankId(); if ((pendingReceipt||payerType==='TG')&&!tgData) await loadTg();if(!current())return; ensurePanel(); render();
       if(previous){for(const [id,value] of Object.entries({erPaymentPart:previous.paymentPartRef,erCorrespondentAmount:previous.correspondentForeignAmount,erDate:previous.date,erBankRef:previous.bankAdviceRef,erForeign:previous.foreignAmount,erRate:previous.realizationRate,erBankCredit:previous.nativeBankCredit||previous.pkrBankCredit,erRetention:previous.retentionForeignAmount||0})){const input=q('#'+id);if(input)input.value=String(value??'');}if(previous.retentionForeignAmount&&q('#erUseRetention')){q('#erUseRetention').checked=true;q('#erRetentionBank').value=previous.retentionBankAccountId||'';updateRetentionDetails();}q('#erBankCredit')?.setAttribute('data-edited','1');calc();}
       ensurePanel().scrollIntoView({behavior:'smooth',block:'start'});
    } catch (error) {if(!current())return; const panel=q('#ttExportReceiptDialog [data-er-panel]');if(panel)panel.innerHTML='<div class="tter-head"><b>Bank Receipt / Credit Advice</b><div class="sp"></div><button class="btn" data-er-close>Close</button></div><div class="tter-alert">'+esc(error.message||error)+'</div><button type="button" class="btn" data-er-retry>Retry loading</button>';panel?.querySelector('[data-er-close]')?.addEventListener('click',closeForm);panel?.querySelector('[data-er-retry]')?.addEventListener('click',()=>openForm(paymentId,amendId));toast(String(error.message || error),false); }
  }
  window.TT_EXPORT_RECEIPTS_UI={openForm,amend:receiptId=>openForm('',receiptId)};
  window.TT_ACCOUNT_BADGE_REFRESH=pendingBanner;
  document.addEventListener('keydown',event=>{if(event.key==='Escape'&&!q('#ttExportReceiptDialog')?.hidden)closeForm();});
  const refreshPending=()=>{if(['TTI','BRM'].includes(entity()))load().then(pendingBanner).catch(()=>{});else pendingBanner();};
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',refreshPending,{once:true});else refreshPending();
  document.addEventListener('click',event=>{if(event.target.closest('[data-tt-entity], .entityBtn')){closeForm();setTimeout(refreshPending,100);}});
})();

