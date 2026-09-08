(()=>{
  'use strict';
  // A read seeds the shared effective-dated defaults if this installation has
  // never opened the master before. Receipt entry should never depend on a
  // user first visiting Accounts Masters / Super Admin.
  fetch('../api/export_realization_master.php',{credentials:'same-origin',headers:{Accept:'application/json'}}).catch(()=>{});
})();
