(() => {
  'use strict';
  const access = window.TT_ACCOUNT_ACCESS || {};
  const api = '../api/bank_accounts.php';
  const q = selector => document.querySelector(selector);
  const qa = selector => [...document.querySelectorAll(selector)];
  const entity = () => localStorage.getItem('tt_accounts_entity') || 'TTI';
  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
  const fmt = value => Number(value || 0).toLocaleString('en-PK', {maximumFractionDigits:2});
  let data = null;

  function toast(message, ok = true) {
    let el = q('#ttBankToast');
    if (!el) { el = document.createElement('div'); el.id = 'ttBankToast'; el.style.cssText = 'position:fixed;left:50%;bottom:22px;transform:translateX(-50%);z-index:100020;padding:11px 15px;border-radius:10px;color:#fff;font:700 12px Arial;box-shadow:0 8px 25px #0003'; document.body.appendChild(el); }
    el.style.background = ok ? '#147a5b' : '#a93a34'; el.textContent = message; el.hidden = false;
    clearTimeout(el._t); el._t = setTimeout(() => { el.hidden = true; }, 3400);
  }
  function style() {
    if (q('#ttBankStyle')) return;
    const sheet = document.createElement('style'); sheet.id = 'ttBankStyle';
    sheet.textContent = `.ttbk{padding:18px}.ttbk-kpis,.ttbk-grid,.ttbk-checks{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px;margin-top:12px}.ttbk-kpis{grid-template-columns:repeat(4,1fr)}.ttbk-kpi,.ttbk-card{border:1px solid #dfe6ec;border-radius:11px;background:#fff;padding:12px;margin-top:10px}.ttbk-kpi span,.ttbk-note{font-size:10px;color:#6f7a89}.ttbk-kpi b{display:block;font-size:17px;margin-top:4px}.ttbk-head{display:flex;gap:11px;align-items:center}.ttbk-head .sp{flex:1}.ttbk-icon{width:38px;height:38px;border-radius:10px;background:#edf3f8;display:grid;place-items:center;font-weight:900}.ttbk-pill{display:inline-block;padding:4px 7px;border-radius:999px;background:#eef3f7;font-size:10px;font-weight:800}.ttbk-pill.on{background:#e6f5ee;color:#14694f}.ttbk-detail{border-top:1px solid #edf0f3;margin-top:11px;padding-top:2px}.ttbk-grid input{width:100%;margin-top:5px;padding:9px;border:1px solid #cfd8e1;border-radius:8px}.ttbk-check{border:1px solid #e2e7ec;border-radius:9px;padding:9px;font-size:11px}.ttbk-check input{width:auto;margin-right:6px}.ttbk-actions{display:flex;justify-content:flex-end;margin-top:11px}.ttbk-alert{padding:10px;border-radius:9px;background:#fff6df;border:1px solid #ead6a2;margin-top:11px}#ws-bank[data-simple-receipt] .subGrid,#ws-bank[data-simple-receipt] #ttBrOpen,#ws-bank[data-simple-receipt] #ttBrBox{display:none!important}@media(max-width:800px){.ttbk-kpis,.ttbk-grid,.ttbk-checks{grid-template-columns:1fr 1fr}}@media(max-width:520px){.ttbk-kpis,.ttbk-grid,.ttbk-checks{grid-template-columns:1fr}}`;
    document.head.appendChild(sheet);
  }
  async function load(force = false) {
    if (data && !force && data.entity === entity()) return;
    const response = await fetch(`${api}?entity=${encodeURIComponent(entity())}`, {credentials:'same-origin', headers:{Accept:'application/json'}});
    let body = {}; try { body = await response.json(); } catch (_) {}
    if (!response.ok || !body.ok) throw new Error(body.error || 'Could not load bank accounts.');
    data = body;
  }
  function label(account) {
    const custom = String(account.settings?.displayName || '').trim();
    return esc(custom || `${account.bankName || account.accountTitle || 'Bank'}${account.accountLast5 ? ` · •••${account.accountLast5}` : ''}`);
  }
  function badge(setting, key, text) { return setting[key] ? `<span class="ttbk-pill on">${text}</span>` : ''; }
  function check(setting, account, key, text, disabled = false) {
    return `<label class="ttbk-check"><input type="checkbox" data-bank-check="${key}" data-id="${esc(account.id)}" ${setting[key] ? 'checked' : ''} ${disabled ? 'disabled' : ''}>${text}</label>`;
  }
  function card(account, cash = false) {
    const setting = account.settings || {}, planning = String(data.paymentPlanningCurrency || 'PKR').toUpperCase(), currency = String(account.currency || planning).toUpperCase();
    const canPlan = currency === planning;
    return `<article class="ttbk-card"><div class="ttbk-head"><div class="ttbk-icon">${cash ? '¤' : '▰'}</div><div><b>${cash ? esc(account.accountTitle) : label(account)}</b><div>${setting.active ? '<span class="ttbk-pill on">Active</span>' : '<span class="ttbk-pill">Inactive</span>'} ${badge(setting,'allowPayments','Payments')} ${badge(setting,'allowReceipts','Receipts')} ${badge(setting,'defaultReceiptAccount','Default Receipt')} ${badge(setting,'retentionAccount','Foreign Retention')}</div></div><div class="sp"></div><b>${esc(currency)} ${fmt(account.bookBalance)}</b></div>
      <details class="ttbk-detail"><summary style="cursor:pointer;padding-top:11px;font-weight:800">Settings & Details ▾</summary>
        ${cash ? '' : `<div class="ttbk-grid"><label>Account Title<input readonly value="${esc(account.accountTitle || '')}"></label><label>Bank / Branch<input readonly value="${esc((account.bankName || '') + (account.branch ? ` · ${account.branch}` : ''))}"></label><label>Account / IBAN<input readonly value="${esc(account.accountNumber || account.iban || '')}"></label></div>`}
        <div class="ttbk-grid"><label>Display Name<input data-bank-field="displayName" data-id="${esc(account.id)}" value="${esc(setting.displayName || '')}"></label><label>Accounts Notes<input data-bank-field="notes" data-id="${esc(account.id)}" value="${esc(setting.notes || '')}"></label></div>
        <div class="ttbk-checks">${check(setting,account,'active','Active')}${check(setting,account,'allowPayments','Allow Payments',account.needsCompletion)}${check(setting,account,'allowReceipts','Allow Receipts',account.needsCompletion)}${cash ? '' : check(setting,account,'defaultReceiptAccount','Default Receipt Account',account.needsCompletion)}${check(setting,account,'includeInPaymentPlanning',`Include in ${planning} Payment Planning`,!canPlan)}${check(setting,account,'visibleToMill','Visible to Mill')}${check(setting,account,'reconciliationEnabled','Bank/Cash Reconciliation')}${setting.retentionAccount ? '<span class="ttbk-pill on">Retention account · Company Master</span>' : ''}</div>
        ${account.needsCompletion ? '<div class="ttbk-alert">Complete the account number or IBAN in Super Admin before enabling it.</div>' : ''}
        <div class="ttbk-actions"><button class="btn primary" data-bank-save="${esc(account.id)}">Save Account Settings</button></div>
      </details></article>`;
  }
  function body() {
    if (!data) return '<div class="ttbk">Loading…</div>';
    const balances = Object.entries(data.balancesByCurrency || {}).map(([currency,value]) => `${esc(currency)} ${fmt(value)}`).join(' · ') || '—';
    return `<div class="ttbk"><div class="formCard"><h3>Banks & Cash</h3><div class="helper">Select one active receipt account as the default for each currency. Other enabled company accounts remain selectable.</div><div class="ttbk-kpis"><div class="ttbk-kpi"><span>Balances</span><b>${balances}</b></div><div class="ttbk-kpi"><span>Planning Funds</span><b>${esc(data.paymentPlanningCurrency || 'PKR')} ${fmt(data.paymentPlanningFunds)}</b></div><div class="ttbk-kpi"><span>Unassigned Old Entries</span><b>${fmt(data.unassignedBankBalance)}</b></div><div class="ttbk-kpi"><span>Master Accounts</span><b>${(data.accounts || []).length}</b></div></div></div>${(data.accounts || []).map(account => card(account)).join('')}${data.cash ? card({...data.cash, needsCompletion:false}, true) : ''}</div>`;
  }
  function bind() { qa('[data-bank-save]').forEach(button => { button.onclick = () => save(button.dataset.bankSave); }); }
  function renderMasters() { const root = q('#masterBody'); if (root) { root.innerHTML = body(); bind(); } }
  function renderBank() { const workspace = q('#ws-bank'); if (!workspace) return; const title=workspace.querySelector('.panelHead h2'),description=workspace.querySelector('.panelHead p'); if (['TTI','BRM'].includes(entity())) { workspace.dataset.simpleReceipt='1'; if(title)title.textContent='Bank Receipt / Credit Advice'; if(description)description.textContent='Record a linked receipt and any amount retained in the company foreign currency account.'; workspace.querySelector('[data-tt-bank-root]')?.remove(); return; } delete workspace.dataset.simpleReceipt; if(title)title.textContent='Cash & Bank'; if(description)description.textContent='Receipts, payments, transfers and cheque activity.'; let root = workspace.querySelector('[data-tt-bank-root]'); if (!root) { root = document.createElement('div'); root.dataset.ttBankRoot = '1'; workspace.querySelector('.panelHead')?.insertAdjacentElement('afterend', root); } root.innerHTML = body(); bind(); }
  async function save(id) {
    const pick = (key, field = false) => { const el = q(`[data-bank-${field ? 'field' : 'check'}="${key}"][data-id="${CSS.escape(id)}"]`); return field ? String(el?.value || '').trim() : !!el?.checked; };
    const payload = {action:'save_settings',csrf:access.csrf,entity:entity(),accountId:id,active:pick('active'),allowPayments:pick('allowPayments'),allowReceipts:pick('allowReceipts'),defaultReceiptAccount:pick('defaultReceiptAccount'),includeInPaymentPlanning:pick('includeInPaymentPlanning'),visibleToMill:pick('visibleToMill'),reconciliationEnabled:pick('reconciliationEnabled'),displayName:pick('displayName',true),notes:pick('notes',true)};
    try {
      const response = await fetch(api,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify(payload)}); let result = {}; try { result = await response.json(); } catch (_) {}
      if (!response.ok || !result.ok) throw new Error(result.error || 'Bank settings could not be saved.');
      data = {...result, entity:entity()}; toast('Bank / cash settings saved.'); renderMasters(); renderBank();
    } catch (error) { toast(String(error.message || error), false); }
  }
  async function mount(target) { try { style(); await load(); target(); } catch (error) { toast(String(error.message || error), false); } }
  document.addEventListener('click', event => { if (event.target.closest?.('.appCard[data-key="bank"]')) setTimeout(() => mount(renderBank),60); if (event.target.closest?.('.tab[data-master="banks"]')) setTimeout(() => mount(renderMasters),30); if (event.target.closest?.('[data-tt-entity]')) data = null; });
  window.TT_BANK_ACCOUNTS_UI = {mountBank:() => mount(renderBank), mountMaster:() => mount(renderMasters), reload:() => load(true).then(() => { renderBank(); renderMasters(); })};
})();
