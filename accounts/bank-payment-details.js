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
  window.TT_BANK_PAYMENT_DETAILS={mount,read};
})();
