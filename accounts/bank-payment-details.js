(() => {
  'use strict';
  const find=id=>document.getElementById(id);
  function mount(id,accounts=[]) {
    const source=find(id);if(!source||find(id+'Details'))return;
    const preferred=accounts.find(a=>a.isDefault&&!String(a.id).startsWith('CASH|')&&[...source.options].some(o=>o.value===a.id));
    if(preferred&&[...source.options].some(o=>o.value===preferred.id))source.value=preferred.id;
    const box=document.createElement('div');box.id=id+'Details';box.style.cssText='grid-column:1/-1;display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;margin:8px 0';
    box.innerHTML='<label>Bank payment method<select data-method><option value="BANK_TRANSFER">Bank Transfer</option><option value="ONLINE_BANKING">Online Banking</option><option value="CHEQUE">Cheque</option></select></label><label><span data-reference-label>Bank transaction reference</span><input data-reference maxlength="180"></label><label>Narration<input data-narration maxlength="300" placeholder="Your payment details"></label>';
    source.closest('label').after(box);
    const update=()=>{const bank=source.value&&!source.value.startsWith('CASH|');box.hidden=!bank;box.style.display=bank?'grid':'none';const cheque=box.querySelector('[data-method]').value==='CHEQUE';box.querySelector('[data-reference-label]').textContent=cheque?'Cheque number':'Bank transaction reference';box.querySelector('[data-reference]').required=!!bank&&cheque;};
    source.addEventListener('change',update);box.querySelector('[data-method]').addEventListener('change',update);update();
  }
  function read(id,date) {
    const source=find(id),box=find(id+'Details');if(!source||!box||box.hidden)return {};
    const method=box.querySelector('[data-method]').value,reference=box.querySelector('[data-reference]').value.trim(),narration=box.querySelector('[data-narration]').value.trim();
    if(method==='CHEQUE'&&!reference)throw Error('Enter the cheque number.');
    return {bankPaymentMethod:method,bankReference:reference,chequeNo:method==='CHEQUE'?reference:'',chequeDate:method==='CHEQUE'?date:'',paymentNarration:narration};
  }
  window.TT_BANK_PAYMENT_DETAILS={mount,read};
})();
