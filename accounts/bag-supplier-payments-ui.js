(() => {
  'use strict';
  // Existing payables navigation delegates to the same payment form as the bill areas.
  function reload() {
    const workspace=document.querySelector('#ws-payables');if(!workspace)return;
    let entry=workspace.querySelector('#ttOtherSupplierPaymentEntry');
    if(!entry){entry=document.createElement('section');entry.id='ttOtherSupplierPaymentEntry';entry.className='formCard';entry.style.margin='16px';workspace.querySelector('.panelHead')?.insertAdjacentElement('afterend',entry);}
    entry.hidden=window.TT_PAYABLES_COMMODITY?.get?.()!=='OTHER';
    entry.innerHTML='<h3>Supplier Payment</h3><p>Choose the supplier, select unpaid bills and enter the payment amount.</p><button type="button" class="btn primary">Payment</button>';
    entry.querySelector('button').onclick=()=>window.TT_SUPPLIER_SETTLEMENT_UI?.openBills?.();
  }
  document.addEventListener('click',event=>{if(event.target.closest('[data-pay-commodity]'))setTimeout(reload,0);});
  window.TT_NON_COMMODITY_PAYMENT_ENTRY={reload};
  window.TT_BAG_SUPPLIER_PAYMENTS={reload};
})();
