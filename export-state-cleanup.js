(()=>{
  'use strict';
  // One-way cleanup of the retired Export V2.6 prototype browser state.
  // Current SOL/V3 state and all Mill/shared keys are intentionally untouched.
  const obsoleteKeys=[
    'transtrade_export_master_v26_final',
    'transtrade_export_master_v26_final_draft_sales_contract'
  ];
  for(const key of obsoleteKeys){
    try{localStorage.removeItem(key)}catch(e){}
  }
})();
