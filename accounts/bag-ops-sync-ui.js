(()=>{
'use strict';

// Opening the Accounts Bags workspace is read-only. Export and Milling bag
// handoffs are written only after the originating final workflow action is
// acknowledged by accounts/bag-control-bridge.js.
async function refresh(){
  try{
    await window.TT_BAG_PURCHASES_UI?.reload?.();
    document.getElementById('ttBagSyncError')?.remove();
  }catch(error){
    console.error('Bag operations refresh',error);
  }
}

document.addEventListener('click',event=>{if(event.target.closest?.('[data-purchase="bags"]'))refresh()});
document.addEventListener('tt:bag-workspace-open',refresh);
})();
