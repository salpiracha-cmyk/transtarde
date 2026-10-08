(() => {
  'use strict';
  const find=id=>document.getElementById(id);
  const accountFor=(source,accounts)=>accounts.find(account=>String(account.id)===String(source.value));
  const defaultMethod=(source,accounts)=>String(accountFor(source,accounts)?.entity||localStorage.getItem('tt_accounts_entity')||'').toUpperCase()==='TG'?'ONLINE_BANKING':'CHEQUE';
  function mount(id,accounts=[]) {
    const source=find(id);if(!source||find(id+'Details'))return;
    const preferred=[...accounts].sort((a,b)=>Number(b.isDefault||0)-Number(a.isDefault||0)).find(a=>a.isDefault&&!String(a.id).startsWith('CASH|')&&[...source.options].some(o=>o.value===a.id));
    if(preferred&&[...source.options].some(o=>o.value===preferred.id))source.value=preferred.id;
    const box=document.createElement('div');box.id=id+'Details';box.style.cssText='grid-column:1/-1;display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;margin:8px 0';
    box.innerHTML='<label>Bank payment method<select data-method><option value="CHEQUE">Cheque</option><option value="ONLINE_BANKING">Online Banking</option></select></label><label><span data-reference-label>Bank transaction reference</span><input data-reference maxlength="180"></label><label>Narration<input data-narration maxlength="300" placeholder="Your payment details"></label>';
    source.closest('label').after(box);
    box.querySelector('[data-method]').value=defaultMethod(source,accounts);
    const update=()=>{const bank=source.value&&!source.value.startsWith('CASH|');box.hidden=!bank;box.style.display=bank?'grid':'none';const cheque=box.querySelector('[data-method]').value==='CHEQUE';box.querySelector('[data-reference-label]').textContent=cheque?'Cheque number':'Online banking transaction reference (optional)';box.querySelector('[data-reference]').required=!!bank&&cheque;};
    source.addEventListener('change',()=>{box.querySelector('[data-method]').value=defaultMethod(source,accounts);update()});box.querySelector('[data-method]').addEventListener('change',update);update();
  }
  function read(id,date) {
    const source=find(id),box=find(id+'Details');if(!source||!box||box.hidden)return {};
    const method=box.querySelector('[data-method]').value,reference=box.querySelector('[data-reference]').value.trim(),narration=box.querySelector('[data-narration]').value.trim();
    if(method==='CHEQUE'&&!reference)throw Error('Enter the cheque number.');
    return {bankPaymentMethod:method,bankReference:reference,chequeNo:method==='CHEQUE'?reference:'',chequeDate:method==='CHEQUE'?date:'',paymentNarration:narration};
  }
  // Resolve a display label without changing the saved journal's posting account.
  window.TT_VOUCHER_ACCOUNT_LABEL=(line,journal={},masters=window.TT_ACCOUNT_ACCESS?.masters||{})=>{
    if(String(line.account)!=='1110')return line.subaccountName||line.accountName||line.account;
    const id=line.bankAccountId||journal.meta?.bankAccountId||journal.meta?.paymentAccountId||line.paymentAccountId;
    const bank=(masters.banks||[]).find(b=>String(b.id)===String(id))?.values||[];
    const name=line.bankName||bank[4],number=line.accountNumber||line.iban||bank[8]||bank[9];
    const label=(journal.bankAccounts||[]).find(b=>String(b.id)===String(id))?.label;
    if(name&&number)return name+' · '+number;
    if(label)return label;
    return [name||line.accountName||'Bank',number||id].filter(Boolean).join(' · ');
  };
  // A shared compact renderer for saved bank, expense and settlement journals.
  window.TT_VOUCHER_HTML=(j,options={})=>{
    const escape=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])),money=v=>Number(v||0).toLocaleString('en-PK',{minimumFractionDigits:2,maximumFractionDigits:2});
    const m=j.meta||{},lines=j.lines||[],base=j.entity==='TG'?'AED':'PKR',company={TTI:'TRANSTRADE INTERNATIONAL',BRM:'BUKSH RICE MILLS',TG:'TRANS GRAINS FOODSTUFF TRADING L.L.C.'}[j.entity]||j.entity;
    const receipt=/receipt|customer.advance/i.test(j.sourceType||'')||m.bankEntryType==='RECEIPT',transfer=/transfer/i.test(j.sourceType||'')||m.bankEntryType==='TRANSFER';
    const heading=transfer?'TRANSFER VOUCHER':receipt?'RECEIPT VOUCHER':m.bankEntryType&&!['PAYMENT','FINANCE_REPAY','FINANCE_MARKUP'].includes(m.bankEntryType)?'BANK VOUCHER':'PAYMENT VOUCHER';
    const bankDetails=lines.filter(l=>String(l.account)==='1110').map(l=>window.TT_VOUCHER_ACCOUNT_LABEL(l,j,options.masters));
    const party=m.payee||m.supplier||m.vendor||m.customer||m.broker||options.party||'';
    const references=[m.chequeNo&&'Cheque '+m.chequeNo,m.chequeDate&&'Cheque date '+m.chequeDate,m.bankReference&&m.bankReference!==m.chequeNo&&'Transaction '+m.bankReference,j.reference&&![m.chequeNo,m.bankReference].includes(j.reference)&&'Reference '+j.reference,m.financeReference&&'Finance '+m.financeReference,m.exportReference&&'Export '+m.exportReference].filter(Boolean);
    const info=[party&&[receipt?'Received from':'Paid to',party],!bankDetails.length&&lines.some(l=>l.account==='1120')&&['Pay from','Cash / Petty Cash'],references.length&&['Reference',references.join(' · ')],m.bankPaymentMethod&&['Method',m.bankPaymentMethod.replaceAll('_',' ')],m.currency&&m.currency!==base&&['Bank amount',m.currency+' '+money(m.amountNative||m.netPayment||0)+' · Rate '+(m.exchangeRate||'')]].filter(Boolean);
    const accountRows=lines.map(l=>{let detail=l.expensePurpose||l.subaccountName||l.subledger||l.counterparty||'';if(detail===party)detail='';return `<tr><td>${escape(window.TT_VOUCHER_ACCOUNT_LABEL(l,j,options.masters))}${detail?'<small>'+escape(detail)+'</small>':''}</td><td class="amount">${l.debit?money(l.debit):'—'}</td><td class="amount">${l.credit?money(l.credit):'—'}</td></tr>`;}).join('');
    const allocations=m.allocations||options.allocations||[],allocationRows=allocations.map(a=>`<tr><td>${escape(a.billNo||a.billId||'')} ${escape([a.soda,a.truck,a.pohanch].filter(Boolean).join(' · '))}</td><td class="amount">${money(a.amount)}</td><td class="amount">${a.balanceAfter==null?'—':money(a.balanceAfter)}</td></tr>`).join('');
    // Direct-expense purpose already appears alongside each debit. Keep only separately entered payment narration.
    const narration=m.directExpense?m.paymentNarration:j.narration,notes=[narration,j.fiTagText].filter(Boolean);
    return `<!doctype html><html><head><meta charset="utf-8"><title>${escape(heading)} ${escape(j.publicPostId||j.meta?.publicPostId||j.id)}</title><style>@page{size:A4;margin:10mm}*{box-sizing:border-box}body{margin:0;color:#182c35;font:11px/1.25 Arial,sans-serif}.voucher{max-width:190mm;margin:0 auto;border:1px solid #a9b9bd;padding:4mm}header{display:flex;justify-content:space-between;align-items:start;gap:8px;border-bottom:2px solid #165848;padding-bottom:2mm}header b{font-size:13px}header strong{font-size:12px}.meta{display:flex;justify-content:space-between;gap:8px;padding:2mm 0}.info{width:100%;margin-bottom:2mm}.info th{width:28mm;background:transparent;white-space:nowrap}.info td,.info th{padding:1mm 2mm;vertical-align:top}table{width:100%;border-collapse:collapse;table-layout:fixed}th{text-align:left;background:#edf4f1}td,th{padding:1.5mm 2mm;border-bottom:1px solid #ccd7d8;overflow-wrap:anywhere;vertical-align:top}.entry th:first-child{width:70%}.amount{text-align:right;white-space:nowrap}small{display:block;font-size:10px;color:#415962;margin-top:1mm}tfoot{font-weight:bold;background:#edf4f1}.notes{padding:2mm 0;overflow-wrap:anywhere}.sign{display:flex;justify-content:space-between;gap:8mm;margin-top:7mm}.sign span{border-top:1px solid #768a90;padding-top:1mm;flex:1;font-size:10px}.allocation{margin-top:2mm}.allocation th:first-child{width:70%}tr{break-inside:avoid}.controls{max-width:190mm;margin:8px auto}@media print{.controls{display:none}.voucher{margin:0}}</style></head><body><div class="controls"><button onclick="print()">Print voucher</button></div><article class="voucher"><header><b>${escape(company)}</b><strong>${escape(heading)}</strong></header><div class="meta"><span>Post ID <b>${escape(j.publicPostId||j.meta?.publicPostId||j.id)}</b></span><span>Date <b>${escape(j.date)}</b></span><span>Book currency <b>${base}</b></span></div>${info.length?'<table class="info">'+info.map(([k,v])=>`<tr><th>${escape(k)}</th><td>${escape(v)}</td></tr>`).join('')+'</table>':''}<table class="entry"><thead><tr><th>Account / details</th><th class="amount">Debit</th><th class="amount">Credit</th></tr></thead><tbody>${accountRows}</tbody><tfoot><tr><td>Total</td><td class="amount">${money(j.totalDebit||lines.reduce((n,l)=>n+Number(l.debit||0),0))}</td><td class="amount">${money(j.totalCredit||lines.reduce((n,l)=>n+Number(l.credit||0),0))}</td></tr></tfoot></table>${allocationRows?'<table class="allocation"><thead><tr><th>Bill / shipment</th><th class="amount">Paid</th><th class="amount">Balance</th></tr></thead><tbody>'+allocationRows+'</tbody></table>':''}${notes.length?'<div class="notes"><b>Narration:</b> '+escape(notes.join(' · '))+'</div>':''}<div class="sign"><span>Prepared by</span><span>Checked by</span><span>Receiver signature</span></div></article></body></html>`;
  };
  window.TT_BANK_PAYMENT_DETAILS={mount,read};
})();

