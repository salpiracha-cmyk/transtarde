(() => {
  'use strict';
  // Load account receipts only for a requested document/report view. Never
  // broadcast a form-rebuilding shared-update event after this read completes.
  localStorage.removeItem('tt40exportreceipts');
  const receipts=new Map(),pending=new Map(),views=new WeakMap();
  const normalized=refs=>[...new Set(refs.map(String).filter(Boolean))].sort();
  async function request(refs){
    const key=JSON.stringify(refs);if(pending.has(key))return pending.get(key);
    const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),30000);
    const work=(async()=>{
      const query=new URLSearchParams();refs.forEach(ref=>query.append('contractRefs[]',ref));
      const response=await fetch('../api/tg_export_accounts_receipts.php?'+query,{credentials:'same-origin',signal:controller.signal,headers:{Accept:'application/json'}});
      const payload=await response.json();if(!response.ok||!payload.ok||!Array.isArray(payload.receipts))throw Error(payload.error||'Accounts receipts could not be loaded.');
      return payload.receipts;
    })();pending.set(key,work);try{return await work;}catch(error){if(error.name==='AbortError')throw Error('Accounts receipts took too long to load. Please retry.');throw error;}finally{clearTimeout(timer);if(pending.get(key)===work)pending.delete(key);}
  }
  function cancel(host){views.delete(host);}
  function prepare(host,requested,render){
    const refs=normalized(requested);if(!refs.length)return false;
    const key=JSON.stringify(refs),previous=views.get(host);
    if(previous?.key===key&&previous.ready)return false;
    if(previous?.key===key&&previous.loading)return true;
    const state={key,loading:true,ready:false};views.set(host,state);
    host.innerHTML='<div class="notice" role="status">Loading Accounts receipts…</div>';
    request(refs).then(rows=>{
      if(!host.isConnected||views.get(host)!==state)return;
      for(const ref of refs)receipts.set(ref,rows.filter(row=>row.contractRef===ref));
      state.ready=true;state.loading=false;render();
    }).catch(error=>{
      if(!host.isConnected||views.get(host)!==state)return;
      state.loading=false;host.innerHTML='<div class="notice red" role="alert"><span></span> <button type="button" class="btn soft">Retry</button></div>';
      host.querySelector('span').textContent=error.message;
      host.querySelector('button').onclick=()=>prepare(host,refs,render);
    });return true;
  }
  window.TT_EXPORT_RECEIPTS={prepare,cancel,load:refs=>{const list=normalized(refs);return list.length?request(list):Promise.resolve([]);},rows:ref=>receipts.get(String(ref))||[]};
})();
