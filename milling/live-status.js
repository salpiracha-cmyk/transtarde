/* Refresh saved summaries only. Active entry forms keep their original fields/baselines. */
(()=>{'use strict';
 window.addEventListener('tt:shared-updated',()=>{
  const panel=document.querySelector('.panel.active')?.id;
  const call=name=>{if(typeof window[name]==='function')window[name]();};
  if(panel==='arrival')call('renderUnprinted');
  if(panel==='production')call('renderRecentProduction');
  if(panel==='instructions')call('renderProductionInstructions');
  if(panel==='oldbags')call('renderUsedBags');
  if(panel==='stock')call('renderStock');
  if(panel==='labour')call('renderProcessingExpenses');
  if(panel==='reprocessbill')call('renderReprocessHistory');
  if(panel==='newbags'&&!document.querySelector('#bagOrdersBody .inlineReceive'))call('renderBagOrders');
  // Loading/local-sale editors hold source snapshots; their next explicit action
  // checks the latest server record instead of silently rebasing the open form.
 });
})();
