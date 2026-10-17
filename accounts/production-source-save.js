(() => {
  'use strict';
  const key='tt30prod',related=['tt36bagissues','tt30prodaudit'];
  const canonical=v=>JSON.stringify(v&&typeof v==='object'?Array.isArray(v)?v.map(x=>JSON.parse(canonical(x))):Object.fromEntries(Object.keys(v).sort().map(k=>[k,JSON.parse(canonical(v[k]))])):v);
  const clone=v=>JSON.parse(JSON.stringify(v)),same=(a,b)=>canonical(a)===canonical(b);
  const rows=value=>{const result=JSON.parse(value||'[]');if(!Array.isArray(result))throw Error('Saved production records are invalid.');return result;};
  const facts=record=>{const r=clone(record);for(const k of ['createdAt','updatedAt','serverSequence','bagAllocationActor','bagAllocationScope','shiftEntriesComplete'])delete r[k];for(const a of r.bagAllocations||[])delete a.sourceSnapshot;return r;};
  async function commit({endpoint,access,record,before=null}) {
    if(!record?.id||!record.clientSaveRequestId)throw Error('A stable production save identity is required.');
    const response=await fetch(endpoint+'?asset_refs=1&r='+Date.now(),{credentials:'same-origin',headers:{Accept:'application/json'}}),snapshot=await response.json();
    if(!response.ok||!snapshot.ok||(snapshot.restrictedKeys||[]).includes(key))throw Error(snapshot.error||'Saved production could not be checked.');
    const current=rows(snapshot.values?.[key]),matches=current.filter(r=>String(r.id)===String(record.id));
    const confirmation=(record,value,version,replayed,relatedValues)=>({record,value,version,replayed,related:relatedValues});
    const readRelated=Object.fromEntries(related.filter(k=>typeof snapshot.values?.[k]==='string').map(k=>[k,{value:snapshot.values[k],version:Number(snapshot.meta?.[k]?.version||0)}]));
    if(matches.length===1&&matches[0].clientSaveRequestId===record.clientSaveRequestId){
      if(!same(facts(matches[0]),facts(record)))throw Error('This production save identity contains different facts. Review the saved report.');
      return confirmation(matches[0],snapshot.values[key],Number(snapshot.meta?.[key]?.version||0),true,readRelated);
    }
    if(before){if(matches.length!==1||!same(matches[0],before))throw Error('This production report changed since it was opened. Your entry is retained; review the saved report before amending.');}
    else if(matches.length)throw Error('This production identity is already saved. Review the saved report.');
    const candidate={...(before||{}),...clone(record)};
    if(before)current[current.findIndex(r=>String(r.id)===String(record.id))]=candidate;else current.push(candidate);
    const posted=await fetch(endpoint+'?asset_refs=1',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify({csrf:access.csrf,key,value:JSON.stringify(current),baseVersion:Number(snapshot.meta?.[key]?.version||0),sourceModule:access.module})}),saved=await posted.json();
    if(!posted.ok||!saved.ok)throw Object.assign(Error(saved.conflict?'Another production report changed meanwhile. Your entry is retained; retry Save to check the latest records.':saved.error||'Production Save was not confirmed.'),{editable:[403,422].includes(posted.status)});
    if(typeof saved.value!=='string')throw Error('The server did not return the saved production record. Retry the same save.');
    const confirmed=rows(saved.value).filter(r=>String(r.id)===String(candidate.id));
    if(confirmed.length!==1||confirmed[0].clientSaveRequestId!==candidate.clientSaveRequestId||!same(facts(confirmed[0]),facts(candidate)))throw Error('The server did not confirm the intended production details. Retry the same save.');
    return confirmation(confirmed[0],saved.value,Number(saved.keyVersion||0),false,saved.related||{});
  }
  const access=()=>window.TT_MODULE_ACCESS||{},token=r=>'tt_ui_production_pending_'+access().userId+'_'+r.entity+'_'+r.millId;
  let busy=false,baseline=null,generation=0;
  const signature=r=>{const copy=clone(r);for(const k of ['id','clientSaveRequestId','createdAt','updatedAt','serverSequence'])delete copy[k];if(typeof editingProdId!=='undefined'&&editingProdId!==null)copy.editingProductionId=String(editingProdId);return canonical(copy);};
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  function pendingPanel(storageKey,intent){
    const host=document.querySelector('#production');if(!host)return;host.querySelector('[data-production-save-recovery]')?.remove();
    const box=document.createElement('section');box.className='card';box.dataset.productionSaveRecovery='';box.innerHTML='<h3>Production awaiting confirmation</h3><p>'+esc(intent.record.date)+' · '+esc(intent.record.shift)+' · '+esc(intent.record.displayName||intent.record.variety)+'. Confirm this save before creating another report. Your current entry will stay open.</p><button type="button">Confirm previous save</button><p role="status"></p>';
    box.querySelector('button').onclick=async()=>{if(busy)return;busy=true;box.querySelector('button').disabled=true;try{await window.TT_SHARED_SYNC.commitProduction(intent.record,intent.before);localStorage.removeItem(storageKey);box.innerHTML='<p role="status">Production saved. Your current entry is retained.</p>';window.renderRecentProduction?.();}catch(e){box.querySelector('[role="status"]').textContent=e.message;box.querySelector('button').disabled=false;}finally{busy=false;}};
    host.prepend(box);
  }
  function showRecovery(){const mill=typeof currentMill==='undefined'?null:currentMill;if(!mill)return;const entity=typeof stockEntityForView==='function'?stockEntityForView():mill.entity;const storageKey=token({entity,millId:mill.id});try{const intent=JSON.parse(localStorage.getItem(storageKey)||'null');if(intent)pendingPanel(storageKey,intent);}catch{}}
  async function save(record,onSaved){
    if(busy)return;if(!access().userId||!window.TT_SHARED_SYNC?.commitProduction){alert('Reload the current Milling workspace before saving. Your production entry is retained.');return;}
    const storageKey=token(record),sig=signature(record);let intent;
    try{intent=JSON.parse(localStorage.getItem(storageKey)||'null');}catch{alert('The previous production save needs review. Your entry is retained.');return;}
    if(intent&&intent.signature!==sig&&intent.editable&&(intent.before?(typeof editingProdId!=='undefined'&&editingProdId!==null&&String(intent.before.id)===String(record.id)):(typeof editingProdId==='undefined'||editingProdId===null))){intent={...intent,signature:sig,record:{...intent.record,...clone(record),id:intent.record.id,clientSaveRequestId:intent.record.clientSaveRequestId}};}
    if(intent&&intent.signature!==sig){pendingPanel(storageKey,intent);alert('Confirm the previous production save using its recovery panel. Your current entry is retained.');return;}
    if(!intent){const editing=typeof editingProdId!=='undefined'&&editingProdId!==null;if(editing&&(!baseline||String(baseline.id)!==String(record.id))){alert('Reopen the saved production report before amending. Your entry is retained.');return;}intent={signature:sig,before:editing?clone(baseline):null,record:{...(editing?clone(baseline):{}),...clone(record),id:editing?record.id:Date.now()*1000+Math.floor(Math.random()*1000),clientSaveRequestId:crypto.randomUUID()}};}
    try{localStorage.setItem(storageKey,JSON.stringify(intent));}catch{alert('The production retry identity could not be retained. No request was sent.');return;}
    busy=true;const startedGeneration=generation,form=document.querySelector('#production'),focus=document.activeElement,wasInert=form?.inert;if(form)form.inert=true;let acknowledged=false;
    try{const saved=await window.TT_SHARED_SYNC.commitProduction(intent.record,intent.before);acknowledged=true;localStorage.removeItem(storageKey);form?.querySelector('[data-production-save-recovery]')?.remove();try{const mill=typeof currentMill==='undefined'?null:currentMill,company=typeof stockEntityForView==='function'?stockEntityForView():mill?.entity;if(generation===startedGeneration&&(!mill||token({entity:company,millId:mill.id})===storageKey))onSaved?.(saved);else alert('Production saved. Your current entry is retained.');}catch{alert('Production is saved. Reopen the saved report to update its display.');}return saved;}
    catch(error){if(error.editable){intent.editable=true;try{localStorage.setItem(storageKey,JSON.stringify(intent));}catch{}}pendingPanel(storageKey,intent);alert(error.message+' Your production entry is retained.');}
    finally{busy=false;if(form)form.inert=wasInert;if(!acknowledged&&focus?.isConnected)focus.focus({preventScroll:true});}
  }
  window.TT_PRODUCTION_SOURCE_SAVE={commit,save,remember:row=>{baseline=row?clone(row):null;generation++;},showRecovery};
})();
