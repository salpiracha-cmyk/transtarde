(()=> {
 'use strict';
 const esc=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 function button(collection,id,label='saved entry'){
  return '<button type="button" class="tt-row-remove" data-mill-delete="'+esc(collection)+'" data-source-id="'+esc(id)+'" title="Delete '+esc(label)+'" aria-label="Delete '+esc(label)+'"><svg aria-hidden="true" viewBox="0 0 24 24" width="18" height="18" fill="none"><path d="M5 12h14" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg></button>';
 }
 function dialog(label,submit,onDeleted){
  if(document.querySelector('[data-mill-delete-dialog]'))return;
  const prior=document.activeElement,host=document.createElement('div');
  host.dataset.millDeleteDialog='';host.setAttribute('role','dialog');host.setAttribute('aria-modal','true');host.setAttribute('aria-label','Delete '+label);
  host.style.cssText='position:fixed;inset:0;z-index:100180;background:#102a4688;display:grid;place-items:center;padding:20px';
  host.innerHTML='<section class="card" style="background:white;width:min(540px,100%);max-height:90vh;overflow:auto"><h2>Delete '+esc(label)+'</h2><p role="alert" data-error style="color:#b42318;font-weight:700"></p><label>Reason for deletion<textarea maxlength="300" data-reason></textarea></label><div class="actions"><button type="button" data-delete>Delete entry</button><button type="button" class="sec" data-close>Close</button></div></section>';
  document.body.appendChild(host);let busy=false,intent=null;
  const close=()=>{if(!busy){host.remove();if(prior?.isConnected)prior.focus({preventScroll:true});}};
  host.querySelector('[data-close]').onclick=close;
  host.addEventListener('keydown',event=>{
   if(event.key==='Escape'){event.preventDefault();close();}
   if(event.key==='Tab'){
    const fields=[...host.querySelectorAll('textarea:not(:disabled),button:not(:disabled)')],first=fields[0],last=fields.at(-1);
    if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}
    else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}
   }
  });
  host.querySelector('[data-delete]').onclick=async()=>{
   if(busy)return;
   const reason=host.querySelector('[data-reason]'),error=host.querySelector('[data-error]'),save=host.querySelector('[data-delete]');
   if(!intent){if(reason.value.trim().length<5){error.textContent='Enter a reason of 5 to 300 characters.';return;}intent={reason:reason.value.trim(),requestKey:crypto.randomUUID()};}
   busy=true;error.textContent='';reason.disabled=true;save.disabled=true;save.textContent='DELETING…';
   let confirmed=false;
   try{await submit(intent);confirmed=true;busy=false;close();onDeleted?.();}
   catch(e){if(confirmed){alert('Entry deleted. Reopen saved entries to refresh the list.');return;}error.textContent=e.message;save.textContent='RETRY DELETE';}
   finally{busy=false;save.disabled=false;}
  };
  window.TT_FORM_VIEWPORT?.open(host.querySelector('section'));
  host.tabIndex=-1;host.focus({preventScroll:true});
 }
 function remove(collection,id,onDeleted){
  const rows=typeof get==='function'?get(collection,[]):[];
  const row=rows.find(r=>String(r.id??(collection==='tt35localsales'?r.soda:r.ref))===String(id));
  if(!row)return alert('Reopen the saved entries before deleting.');
  const label=collection==='tt30prod'?'production report':collection==='tt30queue'?'arrival':collection==='tt33bagreceipts'?'bag receipt':'local sale';
  const expected=JSON.parse(JSON.stringify(row));
  dialog(label,intent=>window.TT_SHARED_SYNC.deleteMillRecord({...intent,collection,entity:expected.entity||'TTI',sourceId:String(id),expected}),()=>{
   if(collection==='tt30prod'){renderRecentProduction();searchProductionReports();}
   if(collection==='tt30queue'){renderQueue();refreshArrivalQueue();}
   if(collection==='tt33bagreceipts'){renderBagOrders();window.TT_BAG_RECEIPTS?.reviewSelected();if(document.getElementById('otherBagHistory'))window.TT_OTHER_EXPORTER_BAGS?.open?.();}
   if(collection==='tt35localsales'){lpSales=get(STORE_LOCALSALES,[]);lpSelected='';renderLocalPartySales();}
   if(collection==='tt35exportersale')renderSavedExporterSales();
   onDeleted?.();window.showNonBlockingMessage?.('Entry deleted.');
  });
 }
 function loadingId(r){return r.id||r.bridgeId||('legacy:'+['date','c','t','b','g'].map(k=>String(r[k]??'')).join('|'));}
 function loadingButton(sourceId,loadId){if(!loadId)return '';return '<button type="button" class="tt-row-remove" data-mill-load-delete="'+esc(loadId)+'" data-source-id="'+esc(sourceId)+'" aria-label="Delete loading" title="Delete loading">−</button>';}
 function removeLoading(sourceId,loadId){
  const row=get('tt35exportersale',[]).find(r=>String(r.id??r.ref)===String(sourceId));
  if(!row)return alert('Reopen this SODA before deleting a loading.');
  const expected=JSON.parse(JSON.stringify(row));
  dialog('container / truck loading',intent=>window.TT_SHARED_SYNC.deleteMillRecord({...intent,collection:'tt35exportersale',entity:row.entity||'TTI',sourceId:String(sourceId),loadId:String(loadId),expected}),()=>{window.TT_DIRECTOR_EXPORTER_MILL?.confirmed?.();renderSavedExporterSales();window.showNonBlockingMessage?.('Loading deleted. Stock updated.');});
 }
 document.addEventListener('click',event=>{
  const load=event.target.closest?.('[data-mill-load-delete]');if(load){event.preventDefault();event.stopImmediatePropagation();removeLoading(load.dataset.sourceId,load.dataset.millLoadDelete);return;}
  const target=event.target.closest?.('[data-mill-delete]');
  if(!target)return;
  event.preventDefault();event.stopImmediatePropagation();remove(target.dataset.millDelete,target.dataset.sourceId);
 },true);
 window.TT_MILL_DELETE={button,dialog,remove,loadingButton,removeLoading,loadingId};
})();
