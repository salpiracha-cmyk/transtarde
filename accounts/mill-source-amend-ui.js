(() => {
  'use strict';
  const access=window.TT_MODULE_ACCESS||{},esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const money=v=>Number(v||0).toLocaleString('en-PK',{minimumFractionDigits:2,maximumFractionDigits:2});
  const kinds={tt38kebills:'ELECTRICITY',tt38labourbills:'LABOUR',tt38reprocessbills:'REPROCESSING'};
  function canEdit(collection){const p=access.permissions||{},a=p==='all'?['Edit']:Array.isArray(p)?p:p[collection==='tt38reprocessbills'?'reprocessbill':'labour']||[];return access.super||a.includes('Edit');}
  function canView(collection){const p=access.permissions||{},a=p==='all'?['View']:Array.isArray(p)?p:p[collection==='tt38reprocessbills'?'reprocessbill':'labour']||[];return access.super||a.includes('View');}
  async function open(collection,id) {
    if(!kinds[collection])return;
    const existing=document.querySelector('[data-mill-source-amend]');if(existing){existing.querySelector('input,button')?.focus();return;}
    const company=typeof stockEntityForView==='function'?stockEntityForView():'';
    const host=document.createElement('div');host.dataset.millSourceAmend='';host.setAttribute('role','dialog');host.setAttribute('aria-modal','true');host.setAttribute('aria-label','Amend saved Mill bill');
    host.style.cssText='position:fixed;inset:0;z-index:100050;background:#102a4688;display:grid;place-items:center;padding:4vh 4vw';
    host.innerHTML='<section class="card" style="background:white;color:#102a46;width:min(1100px,92vw);max-height:92vh;overflow:auto"><h2>Amend saved Mill bill</h2><button type="button" data-close>Close</button><p role="status" data-message>Loading saved source…</p><div data-form></div></section>';
    const priorFocus=document.activeElement;document.body.appendChild(host);const q=s=>host.querySelector(s);let busy=false,loaded=null,lines=[],preview=null,pending=null;
    const token='tt_ui_mill_amend_'+access.userId+'_'+collection+'_'+id;
    try{pending=JSON.parse(localStorage.getItem(token)||'null');}catch{}
    function close(){if(!busy){host.remove();if(priorFocus?.isConnected)priorFocus.focus({preventScroll:true});}}q('[data-close]').onclick=close;
    host.addEventListener('keydown',event=>{if(event.key==='Escape'){event.preventDefault();close();}if(event.key==='Tab'){const items=[...host.querySelectorAll('button:not(:disabled),input:not(:disabled),select:not(:disabled),textarea:not(:disabled)')],first=items[0],last=items.at(-1);if(event.shiftKey&&document.activeElement===first){event.preventDefault();last?.focus();}else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first?.focus();}}});
    async function read(extra={}) {
      const params=new URLSearchParams({collection,sourceId:String(id),entity:company,...extra});
      const response=await fetch('api/mill_source_amend.php?'+params,{credentials:'same-origin',headers:{Accept:'application/json'}}),data=await response.json();
      if(!response.ok||!data.ok)throw Error(data.error||'The saved source could not be loaded.');
      if(data.collection!==collection||data.entity!==company||String(data.record?.id)!==String(id))throw Error('The saved source identity did not match this review.');
      return data;
    }
    const field=(name,label,type='text',value='')=>'<label style="display:block">'+esc(label)+'<input style="display:block;width:100%" data-field="'+name+'" '+(type==='number'?'inputmode="decimal"':'type="'+type+'"')+' value="'+esc(value)+'"></label>';
    const val=name=>q('[data-field="'+name+'"]')?.value||'';
    function readLines(){return [...q('[data-lines]').querySelectorAll('tr[data-line]')].map((row,i)=>({...lines[i],label:row.querySelector('[data-label]').value,qty:Number(row.querySelector('[data-qty]').value),rate:Number(row.querySelector('[data-rate]').value),basis:row.querySelector('[data-basis]').value}));}
    function total() {
      let amount=0;if(kinds[collection]==='ELECTRICITY')amount=Number(val('amount'));
      if(kinds[collection]==='LABOUR')amount=readLines().reduce((sum,r)=>sum+r.qty/(r.basis.includes('100 kg')?100:r.basis.includes('50 kg')?50:1)*r.rate,0);
      if(kinds[collection]==='REPROCESSING')amount=Math.max(0,Number(preview?.arrivalKg||0)-Number(val('rawRemovedKg')))*Number(val('rate'))+Number(val('loadingExtra'))+Number(val('other'));
      q('[data-total]').textContent='Reviewed source total: PKR '+money(amount);
    }
    function renderLines(){q('[data-lines]').innerHTML='<table><tr><th>Work</th><th>Quantity</th><th>Rate</th><th>Rate basis</th><th></th></tr>'+lines.map((r,i)=>'<tr data-line="'+i+'"><td><input data-label value="'+esc(r.label||r.key)+'"></td><td><input data-qty inputmode="decimal" value="'+esc(r.qty)+'"></td><td><input data-rate inputmode="decimal" value="'+esc(r.rate)+'"></td><td><input data-basis value="'+esc(r.basis)+'"></td><td><button type="button" data-remove="'+i+'">Remove</button></td></tr>').join('')+'</table>';
      q('[data-lines]').querySelectorAll('[data-remove]').forEach(b=>b.onclick=()=>{lines=readLines();lines.splice(Number(b.dataset.remove),1);renderLines();total();});}
    function render() {
      const r=loaded.record,kind=kinds[collection];lines=JSON.parse(JSON.stringify(r.lines||[]));preview=loaded.arrivals;
      const from=kind==='ELECTRICITY'?'readFrom':'from',till=kind==='ELECTRICITY'?'readTo':'till';
      let fields=field('date','Bill date','date',r.date)+field(from,'Period from','date',r[from])+field(till,'Period to','date',r[till]);
      if(kind==='ELECTRICITY')fields+=field('ref','Original electricity reference','text',r.ref)+field('party','Actual service provider','text',r.party)+field('units','Units (optional)','number',r.units||0)+field('amount','Bill amount PKR','number',r.amount);
      if(kind==='LABOUR')fields+=field('contractor','Actual Labour Contractor','text',r.contractor||r.party);
      if(kind==='REPROCESSING')fields+=field('rawRemovedKg','Actual raw rice removed kg','number',r.rawRemovedKg||0)+field('rate','Agreed rate per billable kg','number',r.rate)+field('loadingExtra','Loading / labour extras PKR','number',r.loadingExtra||0)+field('other','Other agreed charges PKR','number',r.other||0);
      const history=r._sourceAmendments||[];
      q('[data-form]').innerHTML='<p>'+esc(company)+' · '+esc(r.millName||r.mill)+' · '+esc(r.ref||r.billNo)+'</p><p>Correct only the actual saved facts. The original source and Accounts journals remain in history; Accounts must review any changed liability separately.</p><fieldset data-fields '+(!loaded.canEdit?'disabled':'')+'><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px">'+fields+'</div>'+(kind==='LABOUR'?'<div data-lines></div><button type="button" data-add>Add work line</button>':'')+(kind==='REPROCESSING'?'<p data-arrival></p><button type="button" data-arrival-review>Review actual arrivals for period</button>':'')+'<p data-total></p><label>Correction reason<input data-reason maxlength="500"></label><p><button type="button" data-save>Save source amendment</button></p></fieldset>'+(pending?'<p>Previous amendment awaiting confirmation. Your current entry will be retained.</p><button type="button" data-retry>Confirm previous amendment</button>':'')+(history.length?'<details><summary>Source amendments ('+history.length+')</summary><table><tr><th>When / who</th><th>Reason</th><th>Previous source PKR</th></tr>'+history.map(h=>'<tr><td>'+esc(h.at)+' · '+esc(h.by)+'</td><td>'+esc(h.reason)+'</td><td>'+money(h.before?.amount)+'</td></tr>').join('')+'</table></details>':'');
      if(kind==='LABOUR'){renderLines();q('[data-add]').onclick=()=>{lines=readLines();lines.push({key:'manual-'+crypto.randomUUID(),label:'',qty:'',rate:'',basis:'per bag',auto:false});renderLines();q('[data-lines] tr:last-child input')?.focus();};}
      if(kind==='REPROCESSING'){
        const paint=()=>{q('[data-arrival]').textContent=preview?'Reviewed committed arrivals: '+preview.arrivalKg+' kg.':'The period changed. Review its committed arrivals before saving.';};paint();
        for(const name of ['from','till'])q('[data-field="'+name+'"]').oninput=()=>{preview=null;paint();total();};
        q('[data-arrival-review]').onclick=async()=>{if(busy)return;busy=true;q('[data-arrival-review]').disabled=true;try{const fresh=await read({from:val('from'),till:val('till')});if(!host.isConnected)return;if(fresh.fingerprint!==loaded.fingerprint)throw Error('This source changed after review. Close and reopen it.');preview=fresh.arrivals;paint();total();}catch(e){q('[data-message]').textContent=e.message;}finally{busy=false;if(q('[data-arrival-review]'))q('[data-arrival-review]').disabled=false;}};
      }
      q('[data-form]').addEventListener('input',total);q('[data-save]').onclick=()=>save(false);q('[data-retry]')?.addEventListener('click',()=>save(true));
      total();q('[data-message]').textContent=loaded.hold||'Review actual facts, then save explicitly.';window.TT_FORM_VIEWPORT?.open(q('[data-form]'));q('[data-field="date"]')?.focus({preventScroll:true});
    }
    async function save(retry) {
      if(busy||!host.isConnected)return;let body;
      if(retry){body=pending;if(!body)return;}
      else{
        const kind=kinds[collection],changes={date:val('date')};
        if(kind==='ELECTRICITY')Object.assign(changes,{readFrom:val('readFrom'),readTo:val('readTo'),ref:val('ref').trim(),party:val('party').trim(),units:Number(val('units')),amount:Number(val('amount'))});
        else Object.assign(changes,{from:val('from'),till:val('till')});
        if(kind==='LABOUR')Object.assign(changes,{contractor:val('contractor').trim(),lines:readLines()});
        if(kind==='REPROCESSING'){
          if(!preview){q('[data-message]').textContent='Review the actual arrivals for this period before saving.';return;}
          Object.assign(changes,{rawRemovedKg:Number(val('rawRemovedKg')),rate:Number(val('rate')),loadingExtra:Number(val('loadingExtra')),other:Number(val('other'))});
        }
        body={collection,sourceId:String(id),entity:company,fingerprint:loaded.fingerprint,reason:q('[data-reason]').value.trim(),changes,...(kind==='REPROCESSING'?{arrivalFingerprint:preview.fingerprint}:{})};
        if(body.reason.length<3){q('[data-message]').textContent='Enter the actual correction reason.';q('[data-reason]').focus();return;}
        if(pending){q('[data-message]').textContent='Confirm the previous amendment before saving another change.';return;}
        body.requestKey=crypto.randomUUID();try{localStorage.setItem(token,JSON.stringify(body));}catch{q('[data-message]').textContent='The amendment retry identity could not be retained. No request was sent.';return;}pending=body;
      }
      busy=true;q('[data-form]').inert=true;
      try{
        const response=await window.TT_SHARED_SYNC.amendMillBill(collection,body);if(!host.isConnected)return;localStorage.removeItem(token);pending=null;
        q('[data-message]').textContent='Source amendment confirmed for '+(response.record.ref||response.record.billNo)+'. Accounts review is required before paying a changed liability.';
        q('[data-save]').disabled=true;q('[data-retry]')?.remove();window.renderProcessingExpenses?.();window.renderReprocessHistory?.();
      }catch(error){if(error.definitive){localStorage.removeItem(token);pending=null;q('[data-retry]')?.remove();q('[data-message]').textContent=error.message+' Your fields are retained for correction.';return;}q('[data-message]').textContent=error.message+' Your amendment is retained. Retry confirms the same amendment.';if(!q('[data-retry]')){const button=document.createElement('button');button.type='button';button.dataset.retry='';button.textContent='Confirm previous amendment';button.onclick=()=>save(true);q('[data-form]').appendChild(button);}}
      finally{busy=false;if(q('[data-form]'))q('[data-form]').inert=false;}
    }
    try{loaded=await read();if(host.isConnected)render();}catch(error){if(host.isConnected)q('[data-message]').textContent=error.message;}
  }
  window.TT_MILL_SOURCE_AMEND={open,canEdit,canView};
})();
