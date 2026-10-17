(() => {
  'use strict';
  const access=window.TT_ACCOUNT_ACCESS||{}, entity=()=>localStorage.getItem('tt_accounts_entity')||'TTI';
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const money=v=>(v===null||v===''||!Number.isFinite(Number(v)))?'—':Number(v).toLocaleString('en-PK',{minimumFractionDigits:2,maximumFractionDigits:2});
  const today=()=>new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Karachi',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
  async function request(company,body) {
    const r=await fetch('../api/mill_liabilities.php?entity='+encodeURIComponent(company),{method:body?'POST':'GET',credentials:'same-origin',headers:{Accept:'application/json',...(body?{'Content-Type':'application/json'}:{})},...(body?{body:JSON.stringify({...body,entity:company,csrf:access.csrf})}:{})});
    const d=await r.json();if(!r.ok||!d.ok)throw Error(d.error||'Mill bills could not be loaded.');return d;
  }
  async function open() {
    const company=entity();if(!['TTI','BRM'].includes(company))return;
    document.querySelector('#ttMillLiabilities')?.remove();
    const host=document.createElement('div');host.id='ttMillLiabilities';host.className='tt-layer';host.setAttribute('role','dialog');host.setAttribute('aria-modal','true');host.setAttribute('aria-label','Mill Bill Review');
    host.innerHTML='<section class="tt-window"><header class="tt-window-head"><h2>Mill Bill Review</h2><span></span><button class="btn" data-close>× Close</button></header><div class="tt-window-body"><label>Bill type <select data-kind><option value="">All Mill bills</option><option value="LABOUR">Rice labour</option><option value="ELECTRICITY">Electricity</option><option value="REPROCESSING">Reprocessing</option></select></label> <button class="btn" data-refresh>Refresh saved bills</button> <button class="btn" data-csv>Export CSV</button><p data-message role="status">Loading saved Mill bills…</p><div data-list></div><section data-review hidden></section></div></section>';
    document.body.appendChild(host);const q=s=>host.querySelector(s);
    let data={rows:[]},selected='',busy=false,pending=null,reason='',correctionDate=today();
    const identity=r=>r.kind+'|'+r.sourceId;
    function close(){if(busy)return;host.remove();document.removeEventListener('keydown',escape,true);}
    function escape(e){if(!host.isConnected){document.removeEventListener('keydown',escape,true);return;}if(e.key==='Escape'&&!busy){e.preventDefault();e.stopImmediatePropagation();close();}}
    document.addEventListener('keydown',escape,true);q('[data-close]').onclick=close;
    function remember(){reason=q('[data-reason]')?.value??reason;correctionDate=q('[data-correction-date]')?.value??correctionDate;}
    function detail(row) {
      const box=q('[data-review]');box.hidden=!row;if(!row)return;
      const posted=row.posted,live=posted&&posted.status==='Posted',changed=live&&posted.fingerprint!==row.fingerprint;
      box.innerHTML=`<hr><h3>${esc(row.reference||'Saved Mill bill')}</h3><dl><dt>Company / mill</dt><dd>${esc(company)} · ${esc(row.millName)}</dd><dt>Payee</dt><dd>${esc(row.party)}</dd><dt>Bill date / cost period</dt><dd>${esc(row.date)} · ${esc(row.from||'—')} to ${esc(row.to||'—')}</dd><dt>Saved source amount</dt><dd>PKR ${money(row.amount)}</dd><dt>Accounts</dt><dd>${posted?esc(posted.status)+' · '+esc(posted.journalId)+' · PKR '+money(posted.amount):'No linked liability has been posted.'}</dd></dl>${row.blocker?'<p role="alert">'+esc(row.blocker)+'</p>':''}${changed?'<p role="alert">The saved bill or its master mapping differs from the posted liability. Payments still use the posted amount until an explicit correction succeeds.</p>':''}<p>Recording a liability does not pay it. Supplier payments settle the linked bill and retain its balance.</p>${data.canCorrect&&live?`<label>Correction / cancellation reason<input data-reason maxlength="300" value="${esc(reason)}"></label> <label>Correction date<input data-correction-date type="date" value="${esc(correctionDate)}"></label><p><button class="btn" data-reverse>Cancel unpaid liability</button>${changed&&!row.blocker?' <button class="btn green" data-replace>Replace with reviewed source</button>':''}</p>`:''}${data.canPost&&!live&&!row.blocker&&!posted?'<button class="btn green" data-post>Record liability</button>':''}${live?'<button class="btn" data-pay>Open linked supplier payment</button>':''}`;
      box.querySelector('[data-post]')?.addEventListener('click',()=>save('post',row));
      box.querySelector('[data-reverse]')?.addEventListener('click',()=>save('reverse',row));
      box.querySelector('[data-replace]')?.addEventListener('click',()=>save('replace',row));
      box.querySelector('[data-pay]')?.addEventListener('click',()=>{close();window.TT_SUPPLIER_SETTLEMENT_UI?.openBills('',row.party,posted.billId);});
      window.TT_FORM_VIEWPORT?.open(box);
    }
    function render() {
      const rows=data.rows.filter(r=>!q('[data-kind]').value||r.kind===q('[data-kind]').value);
      q('[data-list]').innerHTML=rows.length?`<table><thead><tr><th>Bill date</th><th>Reference</th><th>Mill</th><th>Payee</th><th>PKR</th><th>Accounts status</th><th></th></tr></thead><tbody>${rows.map(r=>`<tr><td>${esc(r.date)}</td><td>${esc(r.reference)}</td><td>${esc(r.millName)}</td><td>${esc(r.party)}</td><td>${money(r.amount)}</td><td>${esc(r.blocker?'Needs source review':r.posted?.status||'Unposted')}</td><td><button class="btn" data-review-bill="${esc(identity(r))}">Review</button></td></tr>`).join('')}</tbody></table>`:'<p>No saved Mill bills for this selection.</p>';
      q('[data-list]').querySelectorAll('[data-review-bill]').forEach(b=>b.onclick=()=>{remember();selected=b.dataset.reviewBill;reason='';pending=null;detail(data.rows.find(r=>identity(r)===selected));});
      detail(data.rows.find(r=>identity(r)===selected));
    }
    async function load(){
      if(busy)return;remember();busy=true;host.inert=true;
      try{const next=await request(company);if(!host.isConnected||entity()!==company)return;data=next;render();q('[data-message]').textContent='Saved sources only. Review and post each actual bill explicitly; Maize labour remains deferred.';}
      catch(e){q('[data-message]').textContent=e.message;}
      finally{busy=false;host.inert=false;}
    }
    async function save(action,row){
      if(busy)return;remember();const payload={action,kind:row.kind,sourceId:row.sourceId,sourceKey:row.key,fingerprint:row.fingerprint,postedFingerprint:row.posted?.fingerprint||'',reason,correctionDate};
      if(action!=='post'&&!reason.trim()){q('[data-message]').textContent='Enter the correction or cancellation reason.';q('[data-reason]')?.focus();return;}
      const signature=JSON.stringify(payload);if(!pending||pending.signature!==signature)pending={signature,key:crypto.randomUUID()};
      busy=true;host.inert=true;
      try{const saved=await request(company,{...payload,requestKey:pending.key});if(!host.isConnected||entity()!==company)return;pending=null;row.posted=saved.result.record;render();const acknowledgement='Saved in Accounts · '+saved.result.record.journalId+(saved.result.record.reversalJournalId?' · reversal '+saved.result.record.reversalJournalId:'');q('[data-message]').textContent=acknowledgement;try{data=await request(company);render();q('[data-message]').textContent=acknowledgement;}catch(refreshError){q('[data-message]').textContent=acknowledgement+'. Refresh the saved bills when the connection returns.';}}
      catch(e){q('[data-message]').textContent=e.message+' Your review and correction text are retained.';}
      finally{busy=false;host.inert=false;}
    }
    q('[data-kind]').onchange=()=>{remember();render();};q('[data-refresh]').onclick=load;
    q('[data-csv]').onclick=()=>{
      const quote=v=>{let text=String(v??'');if(typeof v==='string'&&/^[=+@-]/.test(text))text="'"+text;return '"'+text.replace(/"/g,'""')+'"';},rows=[['Company','Kind','Bill reference','Bill date','Mill','Payee','Saved source PKR','Accounts status','Posted PKR','Post ID','Source review issue'],...data.rows.map(r=>[company,r.kind,r.reference,r.date,r.millName,r.party,r.amount,r.posted?.status||'Unposted',r.posted?.amount??'',r.posted?.journalId||'',r.blocker])];
      const url=URL.createObjectURL(new Blob([rows.map(r=>r.map(quote).join(',')).join('\r\n')],{type:'text/csv;charset=utf-8'})),a=document.createElement('a');a.href=url;a.download=company+'-mill-bill-review.csv';a.click();URL.revokeObjectURL(url);
    };
    await load();window.TT_FORM_VIEWPORT?.open(q('[data-kind]'));
  }
  window.TT_MILL_LIABILITIES={open};
})();
