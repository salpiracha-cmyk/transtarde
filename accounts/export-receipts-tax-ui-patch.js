(()=>{
  'use strict';
  function apply(){
    const add=document.querySelector('#erAddDed');if(add)add.textContent='+ Tax / Deduction / Charge';
    const rows=document.querySelector('#erDedRows');if(!rows)return;
    const body=rows.closest('.tter-section-body');if(!body||body.querySelector('[data-tax-multi-note]'))return;
    const n=document.createElement('div');n.dataset.taxMultiNote='1';n.className='notice';n.style.margin='0 0 10px';n.innerHTML='<span>ⓘ</span><div><b>Add each component separately.</b> Example: Fixed WHT 1% and Advance WHT 1% are two rows, not one 2% row. Any rate-based amount shown by the form is only a calculation helper; Accounts can overwrite the PKR amount to the actual bank/tax evidence before posting. The legal calculation base is not hard-coded at this stage.</div>';body.insertBefore(n,rows);
  }
  new MutationObserver(apply).observe(document.documentElement,{childList:true,subtree:true});
  document.addEventListener('click',e=>{if(e.target.closest('[data-er-foreign],#erAddDed'))setTimeout(apply,20)},false);apply();
})();
