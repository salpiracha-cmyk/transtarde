(()=>{
  'use strict';
  // The base certificate UI creates its Match button lazily when a certificate opens.
  // Remove any prior button before the next edit/add action so its closure can never
  // retain the previously opened certificate id.
  document.addEventListener('click',e=>{
    if(e.target.closest?.('[data-cert-edit]')||e.target.closest?.('#taxCertAdd')){
      document.querySelector('#taxCertDialog .dialog-match')?.remove();
    }
  },true);
})();

