(() => {
  'use strict';
  window.TT_BANK_LABEL=account=>{const raw=String(account.accountNumber||account.number||account.iban||'').replace(/\s/g,'');return [account.bankName||account.bank||account.title||'Bank',account.currency,raw?'…'+raw.slice(-6):''].filter(Boolean).join(' · ');};
  const find=id=>document.getElementById(id);
  const accountFor=(source,accounts)=>accounts.find(account=>String(account.id)===String(source.value));
  const defaultMethod=(source,accounts)=>String(accountFor(source,accounts)?.entity||localStorage.getItem('tt_accounts_entity')||'').toUpperCase()==='TG'?'ONLINE_BANKING':'CHEQUE';
  function orderChoices(source,accounts){
    const saved=source.value,byId=new Map(accounts.map(a=>[String(a.id),a]));
    const rank=o=>{const a=byId.get(o.value);return !o.value?-1:a?(a.isDefault?0:1):2;};
    const options=[...source.options].sort((a,b)=>rank(a)-rank(b)||(rank(a)===1?Number(byId.get(b.value)?.availableBalance??byId.get(b.value)?.bookBalance??byId.get(b.value)?.balance??0)-Number(byId.get(a.value)?.availableBalance??byId.get(a.value)?.bookBalance??byId.get(a.value)?.balance??0):0));
    for(const option of options){const a=byId.get(option.value);if(a){option.dataset.bankBalance=String(Number(a.availableBalance??a.bookBalance??a.balance??0));option.dataset.bankDefault=String(!!a.isDefault);}source.append(option);}source.value=saved;
  }
  function mount(id,accounts=[]) {
    const source=find(id);if(!source||find(id+'Details'))return;orderChoices(source,accounts);
    const preferred=[...accounts].sort((a,b)=>Number(b.isDefault||0)-Number(a.isDefault||0)).find(a=>a.isDefault&&!String(a.id).startsWith('CASH|')&&[...source.options].some(o=>o.value===a.id));
    if(preferred&&[...source.options].some(o=>o.value===preferred.id))source.value=preferred.id;
    const box=document.createElement('div');box.id=id+'Details';box.className='tt-bank-payment-fields';box.style.cssText='grid-column:1/-1;display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;margin:8px 0';
    box.innerHTML='<label>Bank payment method<select data-method><option value="CHEQUE">Cheque</option><option value="ONLINE_BANKING">Online Banking</option></select></label><label><span data-reference-label>Bank transaction reference</span><input data-reference maxlength="180"></label><label data-cheque-date-wrap><span data-payment-date-label>Cheque date</span><input data-cheque-date type="date"></label><details data-narration-override><summary>Separate payment narration (optional)</summary><label>Payment narration<input data-narration maxlength="300" placeholder="Only if different from the expense purpose"></label></details>';
    source.closest('label').after(box);
    const paymentDate=box.querySelector('[data-cheque-date]'),voucher=window.TT_CHEQUE_DATES?.voucherFor(paymentDate)||source.closest('form')?.querySelector('input[type=date]:not([data-cheque-date])');paymentDate.value=voucher?.value||'';
    box.querySelector('[data-method]').value=defaultMethod(source,accounts);
    const update=()=>{const bank=source.value&&!/^(CASH|CARD)\|/.test(source.value);box.hidden=!bank;box.style.display=bank?'grid':'none';const cheque=box.querySelector('[data-method]').value==='CHEQUE';box.querySelector('[data-reference-label]').textContent=cheque?'Cheque number':'Online banking transaction reference (optional)';box.querySelector('[data-reference]').required=!!bank&&cheque;box.querySelector('[data-cheque-date-wrap]').hidden=!bank;box.querySelector('[data-payment-date-label]').textContent=cheque?'Cheque date':'Online Banking payment date';box.querySelector('[data-cheque-date]').required=!!bank;};
    source.addEventListener('change',()=>{box.querySelector('[data-method]').value=defaultMethod(source,accounts);update()});box.querySelector('[data-method]').addEventListener('change',update);update();window.TT_CHEQUE_DATES?.bind(box.querySelector('[data-cheque-date]'));
  }
  function read(id,date) {
    const source=find(id),box=find(id+'Details');if(!source||!box||box.hidden)return {};
    const method=box.querySelector('[data-method]').value,reference=box.querySelector('[data-reference]').value.trim(),narration=box.querySelector('[data-narration]').value.trim();
    if(!['CHEQUE','ONLINE_BANKING'].includes(method))throw Error('Choose the bank payment method.');
    if(method==='CHEQUE'&&!reference)throw Error('Enter the cheque number.');
    return {bankPaymentMethod:method,bankReference:reference,chequeNo:method==='CHEQUE'?reference:'',chequeDate:method==='CHEQUE'?(box.querySelector('[data-cheque-date]').value||date):'',bankPaymentDate:method==='ONLINE_BANKING'?(box.querySelector('[data-cheque-date]').value||date):'',paymentNarration:narration};
  }
  function restore(id,details={},date='') {
    const source=find(id),box=find(id+'Details');if(!source||!box)return;
    source.dispatchEvent(new Event('change',{bubbles:true}));
    const method=box.querySelector('[data-method]');
    const savedMethod=details.bankPaymentMethod==='BANK_TRANSFER'?'ONLINE_BANKING':details.bankPaymentMethod||(details.chequeNo?'CHEQUE':'');
    if(!savedMethod&&![...method.options].some(option=>option.value==='')){
      const option=document.createElement('option');option.value='';option.textContent='Choose payment method';method.prepend(option);
    }
    method.value=savedMethod;
    box.querySelector('[data-reference]').value=savedMethod==='CHEQUE'?(details.chequeNo||details.bankReference||''):(details.bankReference||'');
    box.querySelector('[data-cheque-date]').value=(savedMethod==='CHEQUE'?details.chequeDate:details.bankPaymentDate)||date;
    box.querySelector('[data-narration]').value=details.paymentNarration||'';
    method.dispatchEvent(new Event('change',{bubbles:true}));
    const narration=box.querySelector('[data-narration-override]');if(narration)narration.open=!!details.paymentNarration;
    window.TT_ACCOUNTS_CLEAN_UI?.syncSelects?.(box);
  }
  // Resolve a display label without changing the saved journal's posting account.
  window.TT_VOUCHER_ACCOUNT_LABEL=(line,journal={},masters=window.TT_ACCOUNT_ACCESS?.masters||{})=>{
    const code=String(line.account),m=journal.meta||{};
    if(code==='1120')return line.cashAccountName||line.paymentAccountName||'Petty Cash';
    if(code!=='1110'){
      const named=[line.personName,line.person,line.party,line.counterparty,line.supplier,line.customer,line.vendor,line.broker,line.subledger].find(value=>value&&String(value)!==String(line.accountName)&&!/^\d{4}$/.test(String(value)));
      if(named)return String(named);
      const trade=['1210','1220','1230','1240','1250','1400','2110','2120','2130','2140','2150','2160','2170','2190','2210','2230','2500','2510','2520'];
      const party=trade.includes(code)?m.payee||m.supplier||m.vendor||m.customer||m.broker||m.person||m.party||m.paymentRecipient:'';
      if(party)return String(party);
      const labels={'2230':'Salary / remuneration payable','2300':'Tax withheld','3400':'Opening balance adjustment','4400':'Settlement discount','7195':'Rounding adjustment'};
      return labels[code]||line.expensePurpose||line.subaccountName||m.expensePurpose||((m.directExpense||m.paymentRecipient)?m.payee||m.paymentRecipient:'')||line.accountName||'Party / purpose not recorded';
    }
    const id=line.bankAccountId||journal.meta?.bankAccountId||journal.meta?.paymentAccountId||line.paymentAccountId;
    const bank=(masters.banks||[]).find(b=>String(b.id)===String(id))?.values||[];
    const name=line.bankName||bank[4],number=line.accountNumber||line.iban||bank[8]||bank[9];
    const label=(journal.bankAccounts||[]).find(b=>String(b.id)===String(id))?.label;
    if(name&&number)return name+' · '+number;
    if(label)return label;
    return [name||line.accountName||'Bank',number||id].filter(Boolean).join(' · ');
  };
  window.TT_SALARY_SUMMARY_HTML=summary=>{
    if(!summary)return '';const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])),money=v=>Number(v||0).toLocaleString('en-PK',{minimumFractionDigits:2,maximumFractionDigits:2});
    const rows=[['Total monthly entitlement',summary.entitlement],['Less advance recoveries',summary.advances],['Payments from banks',summary.bank],['Payments from petty cash',summary.cash]];
    if(summary.otherPayments)rows.push(['Other purposes included in these payments',summary.otherPayments]);if(summary.internalAdjustments)rows.push(['Non-cash remuneration adjustments',summary.internalAdjustments]);rows.push(['Remaining monthly payable',summary.remaining]);
    return '<section data-salary-summary><h3>Salary reconciliation · '+esc(summary.month)+'</h3><table style="width:100%;border-collapse:collapse"><thead><tr><th style="text-align:left;padding:8px">Reconciliation</th><th style="text-align:right;padding:8px">PKR</th></tr></thead><tbody>'+rows.map(([label,value])=>'<tr><td style="padding:8px">'+esc(label)+'</td><td style="text-align:right;padding:8px;font-variant-numeric:tabular-nums">'+money(value)+'</td></tr>').join('')+'</tbody></table>'+(summary.people?.length?'<p>Remaining: '+summary.people.map(p=>esc(p.name)+' — PKR '+money(p.amount)).join(' · ')+'</p>':'')+'</section>';
  };
  // A shared compact renderer for saved bank, expense and settlement journals.
  window.TT_VOUCHER_HTML=(j,options={})=>{
    const escape=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])),money=v=>Number(v||0).toLocaleString('en-PK',{minimumFractionDigits:2,maximumFractionDigits:2});
    const m=j.meta||{},lines=j.lines||[],base=j.entity==='TG'?'AED':'PKR',company={TTI:'TRANSTRADE INTERNATIONAL',BRM:'BUKSH RICE MILLS',TG:'TRANS GRAINS FOODSTUFF TRADING L.L.C.'}[j.entity]||j.entity;
    const receipt=/receipt|customer.advance/i.test(j.sourceType||'')||m.bankEntryType==='RECEIPT',transfer=/transfer/i.test(j.sourceType||'')||m.bankEntryType==='TRANSFER';
    const payroll=/^SALARY_(MONTH_COMPLETED|MONTHLY_ACCRUAL|ALLOCATED_PAYMENT|PAYMENT|BATCH_PAYMENT)$/.test(m.originalSourceType||j.sourceType||'');
    const heading=payroll?(lines.some(l=>['1110','1120'].includes(String(l.account)))?'SALARY PAYMENT VOUCHER':'SALARY ENTITLEMENT JOURNAL'):!lines.some(l=>['1110','1120'].includes(String(l.account)))?'JOURNAL VOUCHER':transfer?'TRANSFER VOUCHER':receipt?'RECEIPT VOUCHER':m.bankEntryType&&!['PAYMENT','FINANCE_REPAY','FINANCE_MARKUP'].includes(m.bankEntryType)?'BANK VOUCHER':'PAYMENT VOUCHER';
    const bankDetails=lines.filter(l=>String(l.account)==='1110').map(l=>window.TT_VOUCHER_ACCOUNT_LABEL(l,j,options.masters));
    const party=m.payee||m.paymentRecipient||lines.find(l=>l.paymentRecipient)?.paymentRecipient||(payroll?m.person:'')||m.supplier||m.vendor||m.customer||m.broker||options.party||'';
    const references=[m.chequeNo&&'Cheque '+m.chequeNo,m.chequeDate&&'Cheque date '+m.chequeDate,(m.bankPaymentDate||lines.find(l=>l.bankPaymentDate)?.bankPaymentDate)&&'Online Banking date '+(m.bankPaymentDate||lines.find(l=>l.bankPaymentDate)?.bankPaymentDate),m.bankReference&&m.bankReference!==m.chequeNo&&'Transaction '+m.bankReference,j.reference&&![m.chequeNo,m.bankReference].includes(j.reference)&&'Reference '+j.reference,m.financeReference&&'Finance '+m.financeReference,m.exportReference&&'Export '+m.exportReference].filter(Boolean);
    const info=[payroll&&['Salary month',m.month||m.salarySummary?.month||''],payroll&&bankDetails.length&&['Paid from',bankDetails.join(' · ')],party&&[receipt?'Received from':'Paid to',party],!bankDetails.length&&lines.some(l=>l.account==='1120')&&['Pay from','Cash / Petty Cash'],references.length&&['Reference',references.join(' · ')],m.bankPaymentMethod&&['Method',m.bankPaymentMethod.replaceAll('_',' ')],m.currency&&m.currency!==base&&['Bank amount',m.currency+' '+money(m.amountNative||m.netPayment||0)+' · Rate '+(m.exchangeRate||'')]].filter(Boolean);
    const accountRows=lines.map(l=>{let detail=[l.person||l.party||l.counterparty||l.subledger||'',l.expensePurpose||l.allocationPurpose||l.subaccountName||''].filter((v,i,a)=>v&&a.indexOf(v)===i).join(' · ');if(detail===party)detail='';detail=[detail,l.chequeNo&&'Cheque '+l.chequeNo,l.bankPaymentMethod==='ONLINE_BANKING'&&l.bankReference&&'Online reference '+l.bankReference,l.chequeDate&&'Cheque date '+(window.TT_DATE?.display(l.chequeDate)||l.chequeDate),l.bankPaymentDate&&'Payment date '+(window.TT_DATE?.display(l.bankPaymentDate)||l.bankPaymentDate)].filter(Boolean).join(' · ');return `<tr><td>${escape(window.TT_VOUCHER_ACCOUNT_LABEL(l,j,options.masters))}${detail?'<small>'+escape(detail)+'</small>':''}</td><td class="amount">${l.debit?money(l.debit):'—'}</td><td class="amount">${l.credit?money(l.credit):'—'}</td></tr>`;}).join('');
    const allocations=m.allocations||options.allocations||[],allocationRows=allocations.map(a=>`<tr><td>${escape(a.billNo||a.billId||'')} ${escape([a.soda,a.truck,a.pohanch].filter(Boolean).join(' · '))}</td><td class="amount">${money(a.amount)}</td><td class="amount">${a.balanceAfter==null?'—':money(a.balanceAfter)}</td></tr>`).join('');
    // Direct-expense purpose already appears alongside each debit. Keep only separately entered payment narration.
    const narration=m.directExpense?m.paymentNarration:j.narration,notes=[narration,j.fiTagText,j.amendmentNote||m.amendmentNote].filter(Boolean);
    return `<!doctype html><html><head><meta charset="utf-8"><title>${escape(heading)} ${escape(j.publicPostId||j.meta?.publicPostId||j.id)}</title><style>@page{size:A4;margin:10mm}*{box-sizing:border-box}body{margin:0;color:#182c35;font:11px/1.25 Arial,sans-serif}.voucher{max-width:190mm;margin:0 auto;border:1px solid #a9b9bd;padding:4mm}header{display:grid;text-align:center;gap:4px;border-bottom:2px solid #165848;padding-bottom:2mm}header b{font-size:16px}header strong{font-size:12px}.meta{display:flex;justify-content:space-between;gap:8px;padding:2mm 0}.info{width:100%;margin-bottom:2mm}.info th{width:28mm;background:transparent;white-space:nowrap}.info td,.info th{padding:1mm 2mm;vertical-align:top}table{width:100%;border-collapse:collapse;table-layout:fixed}th{text-align:left;background:#edf4f1}td,th{padding:1.5mm 2mm;border-bottom:1px solid #ccd7d8;overflow-wrap:anywhere;vertical-align:top}.entry th:first-child{width:70%}.amount{text-align:right;white-space:nowrap}small{display:block;font-size:10px;color:#415962;margin-top:1mm}tfoot{font-weight:bold;background:#edf4f1}.notes{padding:2mm 0;overflow-wrap:anywhere}.sign{display:flex;justify-content:space-between;gap:8mm;margin-top:16mm}.sign span{border-top:1px solid #768a90;padding-top:1mm;flex:1;font-size:10px}.allocation{margin-top:2mm}.allocation th:first-child{width:70%}tr{break-inside:avoid}.controls{max-width:190mm;margin:8px auto}@media print{.controls{display:none}.voucher{margin:0}}</style></head><body><div class="controls"><button onclick="print()">Print voucher</button></div><article class="voucher"><header><b>${escape(company)}</b><strong>${escape(heading)}</strong></header><div class="meta"><span>Post ID <b>${escape(j.publicPostId||j.meta?.publicPostId||j.id)}</b></span><span>Date <b>${escape(window.TT_DATE?.display(j.date)||j.date)}</b></span><span>Book currency <b>${base}</b></span></div>${window.TT_SALARY_SUMMARY_HTML(m.salarySummary)}${info.length?'<table class="info">'+info.map(([k,v])=>`<tr><th>${escape(k)}</th><td>${escape(v)}</td></tr>`).join('')+'</table>':''}<table class="entry"><thead><tr><th>Party / payment source</th><th class="amount">Debit</th><th class="amount">Credit</th></tr></thead><tbody>${accountRows}</tbody><tfoot><tr><td>Total</td><td class="amount">${money(j.totalDebit||lines.reduce((n,l)=>n+Number(l.debit||0),0))}</td><td class="amount">${money(j.totalCredit||lines.reduce((n,l)=>n+Number(l.credit||0),0))}</td></tr></tfoot></table>${allocationRows?'<table class="allocation"><thead><tr><th>Bill / shipment</th><th class="amount">Paid</th><th class="amount">Balance</th></tr></thead><tbody>'+allocationRows+'</tbody></table>':''}${notes.length?'<div class="notes"><b>Narration:</b> '+escape(notes.join(' · '))+'</div>':''}<div class="sign"><span>Prepared by</span><span>Checked by</span><span>Receiver signature</span></div></article></body></html>`;
  };
  window.TT_BANK_PAYMENT_DETAILS={mount,read,restore,orderChoices};
})();

