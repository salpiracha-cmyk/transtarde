(() => {
  'use strict';
  const collections=new Set(['tt38labourbills','tt38kebills','tt38reprocessbills']);
  const canonical=v=>JSON.stringify(v&&typeof v==='object'?Array.isArray(v)?v.map(x=>JSON.parse(canonical(x))):Object.fromEntries(Object.keys(v).sort().map(k=>[k,JSON.parse(canonical(v[k]))])):v);
  const same=(a,b)=>canonical(a)===canonical(b);
  const rows=value=>{const parsed=JSON.parse(value||'[]');if(!Array.isArray(parsed))throw Error('Saved bill collection is invalid.');return parsed;};
  async function append({endpoint,access,key,record,prefix}) {
    if(!collections.has(key)||!record.id)throw Error('A supported bill and stable identity are required.');
    const read=await fetch(endpoint+'?asset_refs=1&r='+Date.now(),{credentials:'same-origin',headers:{Accept:'application/json'}}),snapshot=await read.json();
    if(!read.ok||!snapshot.ok||(snapshot.restrictedKeys||[]).includes(key))throw Error(snapshot.error||'Saved Mill bills could not be checked.');
    const current=rows(snapshot.values?.[key]),matches=current.filter(r=>String(r.id)===String(record.id));
    const comparable=r=>{const copy={...r};if(prefix){delete copy.billNo;delete copy.ref}return copy;};
    if(matches.length){
      if(matches.length!==1||!same(comparable(matches[0]),comparable(record)))throw Error('This saved bill identity contains different details. Review the saved bill.');
      return {record:matches[0],value:JSON.stringify(current),version:Number(snapshot.meta?.[key]?.version||0),replayed:true};
    }
    if(prefix&&current.some(r=>signature(r)===signature(record)))throw Object.assign(Error('An identical bill is already saved. Review its saved reference before creating another bill.'),{editable:true});
    if(!prefix&&current.some(r=>r.entity===record.entity&&String(r.millId)===String(record.millId)&&String(r.ref).trim().toLowerCase()===String(record.ref).trim().toLowerCase()))throw Object.assign(Error('This bill reference already exists at this company and mill. Review the saved bill.'),{editable:true});
    const candidate=JSON.parse(JSON.stringify(record));
    if(prefix){const highest=current.reduce((max,r)=>{const ref=String(r.billNo||r.ref||'');return ref.startsWith(prefix)?Math.max(max,Number(ref.slice(prefix.length))||0):max},0);candidate.billNo=candidate.ref=prefix+String(highest+1).padStart(5,'0');}
    current.push(candidate);
    const response=await fetch(endpoint+'?asset_refs=1',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify({csrf:access.csrf,key,value:JSON.stringify(current),baseVersion:Number(snapshot.meta?.[key]?.version||0),sourceModule:access.module})}),saved=await response.json();
    if(!response.ok||!saved.ok)throw Object.assign(Error(saved.conflict?'Another bill was saved meanwhile. Your entry is retained; Save again to review the latest records.':saved.error||'The save was not confirmed.'),{editable:[403,422].includes(response.status)});
    const value=typeof saved.value==='string'?saved.value:JSON.stringify(current),confirmed=rows(value).filter(r=>String(r.id)===String(candidate.id));
    if(confirmed.length!==1||!same(confirmed[0],candidate))throw Error('The server did not confirm the intended bill details. Retry the same save.');
    return {record:confirmed[0],value,version:Number(saved.keyVersion||0)};
  }
  const access=()=>window.TT_MODULE_ACCESS||{};
  const pendingKey=(key,rec)=>'tt_ui_mill_bill_pending_'+access().userId+'_'+key+'_'+rec.entity+'_'+rec.millId;
  const signature=rec=>{const value={...rec};for(const key of ['id','time','clientRequestId'])delete value[key];if(rec.billNo){delete value.billNo;delete value.ref}return canonical(value)};
  let busy=false;
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  function showPending(token,intent) {
    const host=document.querySelector(token.includes('tt38reprocessbills')?'#reprocessBillWork':'#processingWork');if(!host)return;
    [...host.querySelectorAll('[data-mill-save-recovery]')].find(x=>x.dataset.millSaveRecovery===token)?.remove();
    const box=document.createElement('section');box.dataset.millSaveRecovery=token;box.className='card';
    box.innerHTML='<h3>Previous bill awaiting confirmation</h3><p>'+esc(intent.record.ref||intent.record.billNo||'Bill')+' · '+esc(intent.record.date)+' · PKR '+esc(intent.record.amount)+'. Confirm this save before entering another bill. Your current form will stay open.</p><button type="button">Confirm previous save</button><p role="status"></p>';
    box.querySelector('button').onclick=async()=>{
      if(busy)return;busy=true;box.querySelector('button').disabled=true;
      try{const key=token.split('_').find(v=>collections.has(v)),prefix=key==='tt38labourbills'?'LB-':key==='tt38reprocessbills'?'RPB-':'';
        const saved=await window.TT_SHARED_SYNC.appendMillBill(key,intent.record,prefix);localStorage.removeItem(token);
        box.innerHTML='<p role="status">Bill '+esc(saved.ref||saved.billNo)+' saved. Accounts review is pending. Your current draft has been retained.</p>';
        window.renderProcessingExpenses?.();window.renderReprocessHistory?.();
      }catch(error){box.querySelector('[role="status"]').textContent=error.message;box.querySelector('button').disabled=false}finally{busy=false}
    };
    host.prepend(box);
  }
  function showRecovery() {
    const mill=typeof currentMill==='undefined'?null:currentMill;if(!mill)return;
    const company=typeof stockEntityForView==='function'?stockEntityForView():mill.entity;
    for(const key of collections){const token=pendingKey(key,{entity:company,millId:mill.id});try{const intent=JSON.parse(localStorage.getItem(token)||'null');if(intent)showPending(token,intent)}catch{}}
  }

  async function save(key,rec,{prefix='',printAfter=false,print,onSaved}={}) {
    if(busy)return;
    if(printAfter&&typeof canPrintMillBill==='function'&&!canPrintMillBill(key)){alert('Print permission is required. Use Save without Print to record the bill.');return;}
    if(!access().userId||!window.TT_SHARED_SYNC?.appendMillBill){alert('Reload the current Milling workspace before saving. Your entry is retained.');return;}
    const token=pendingKey(key,rec),sig=signature(rec);let intent;
    try{intent=JSON.parse(localStorage.getItem(token)||'null');}catch{alert('The previous save needs review. Your current entry is retained.');return;}
    if(intent&&intent.signature!==sig&&intent.editable){intent={signature:sig,record:{...rec,id:intent.record.id,time:intent.record.time,clientRequestId:intent.record.clientRequestId}};try{localStorage.setItem(token,JSON.stringify(intent));}catch{alert('The corrected retry identity could not be retained. No request was sent.');return;}}
    if(intent&&intent.signature!==sig){
      showPending(token,intent);alert('Confirm the previous bill save using the recovery panel. Your current entry is retained.');return;
    }
    if(!intent){intent={signature:sig,record:{...rec,id:Date.now()*1000+Math.floor(Math.random()*1000),clientRequestId:crypto.randomUUID()}};try{localStorage.setItem(token,JSON.stringify(intent));}catch{alert('The bill retry record could not be retained. Your entry has not been sent.');return;}}
    busy=true;const form=document.querySelector(key==='tt38reprocessbills'?'#reprocessBillWork':'#processingWork'),focus=document.activeElement;const wasInert=form?.inert;if(form)form.inert=true;let acknowledged=false,target=null;
    try{if(printAfter)target=open('','_blank');if(target)target.document.write('<p>Waiting for the saved bill confirmation…</p>');}catch{target=null;}
    try{
      const saved=await window.TT_SHARED_SYNC.appendMillBill(key,intent.record,prefix);
      acknowledged=true;localStorage.removeItem(token);[...document.querySelectorAll('[data-mill-save-recovery]')].find(x=>x.dataset.millSaveRecovery===token)?.remove();
      // Only an acknowledged source may clear its form or produce a printout.
      try{onSaved?.(saved);}catch{alert('Bill '+(saved.ref||saved.billNo)+' is saved. Reopen the bill history to update its display.');}
      if(printAfter){try{if(target)print(saved,target);else alert('Bill saved. Allow pop-ups and use the saved bill Print action.');}catch{target?.close();alert('Bill is saved. Printing failed; use Print in the saved bill history.');}}
      window.showNonBlockingMessage?.('Bill '+(saved.ref||saved.billNo)+' saved. Accounts review is pending.');
      return saved;
    }catch(error){target?.close();if(error.editable){intent.editable=true;try{localStorage.setItem(token,JSON.stringify(intent));}catch{}}showPending(token,intent);alert(error.message+' Your bill entry is retained. Retry Save to confirm the same bill.');}
    finally{busy=false;if(form)form.inert=wasInert;if(!acknowledged&&focus?.isConnected)focus.focus({preventScroll:true});if(acknowledged&&form)window.TT_FORM_VIEWPORT?.open(form);}
  }
  window.TT_MILL_SOURCE_SAVE={append,save};
  for(const name of ['processingTab','renderReprocessingBill']){const original=window[name];if(typeof original==='function')window[name]=function(...args){const result=original.apply(this,args);showRecovery();return result;};}
})();
