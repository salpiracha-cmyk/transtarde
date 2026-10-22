(() => {
  'use strict';
  const access=window.TT_ACCOUNT_ACCESS||{}, entity=()=>localStorage.getItem('tt_accounts_entity')||'TTI';
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const money=v=>(v===null||v===''||!Number.isFinite(Number(v)))?'—':Number(v).toLocaleString('en-PK',{minimumFractionDigits:2,maximumFractionDigits:2});
  const date=v=>/^\d{4}-\d{2}-\d{2}$/.test(String(v||''))?String(v).split('-').reverse().join('-'):String(v||'—');
  const today=()=>new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Karachi',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
  async function request(company,body) {
    const r=await fetch('../api/mill_liabilities.php?entity='+encodeURIComponent(company),{method:body?'POST':'GET',credentials:'same-origin',headers:{Accept:'application/json',...(body?{'Content-Type':'application/json'}:{})},...(body?{body:JSON.stringify({...body,entity:company,csrf:access.csrf})}:{})});
    const d=await r.json();if(!r.ok||!d.ok)throw Error(d.error||'Mill bills could not be loaded.');if(!body&&d.entity!==company)throw Error('The company changed while loading Mill bills. Reopen the selected company.');return d;
  }
  async function open() {
    const company=entity();if(!['TTI','BRM'].includes(company))return;
    document.querySelector('#ttMillLiabilities')?.remove();
    const host=document.createElement('div');host.id='ttMillLiabilities';host.className='tt-layer';host.setAttribute('role','dialog');host.setAttribute('aria-modal','true');host.setAttribute('aria-label','Mill Bill Review');
    host.innerHTML='<section class="tt-window"><header class="tt-window-head"><h2>Mill Bill Review</h2><span></span><button class="btn" data-close>× Close</button></header><div class="tt-window-body"><label>Bill type <select data-kind><option value="">All Mill bills</option><option value="RICE_LABOUR">Rice labour</option><option value="MAIZE_LABOUR">Maize / Corn / Makai labour</option><option value="ELECTRICITY">Electricity</option><option value="REPROCESSING">Reprocessing</option><option value="PROCESSING">Other processing</option></select></label> <button class="btn" data-reference-review>Rent / Salary References</button> <button class="btn" data-refresh>Refresh saved bills</button> <button class="btn" data-csv>Export CSV</button> <button class="btn" data-print>Print saved review</button><p data-message role="status">Loading saved Mill bills…</p><div data-list></div><section data-review hidden></section></div></section>';
    document.body.appendChild(host);const q=s=>host.querySelector(s);
    let data={rows:[]},selected='',busy=false,pending=null,reason='',correctionDate=today(),checkedAt='';
    const identity=r=>r.kind+'|'+r.sourceId;
    const commodity=r=>String(r.commodity||'').trim().toUpperCase(),isMaize=r=>['MAIZE','CORN','MAKAI'].includes(commodity(r));
    const billType=r=>r.kind==='LABOUR'?(isMaize(r)?'Maize / Corn / Makai labour':commodity(r)==='RICE'?'Rice labour':'Labour — commodity unverified'):r.kind;
    function close(){if(busy)return;host.remove();document.removeEventListener('keydown',escape,true);}
    function escape(e){if(!host.isConnected){document.removeEventListener('keydown',escape,true);return;}if(e.key==='Escape'&&!busy){e.preventDefault();e.stopImmediatePropagation();close();}}
    document.addEventListener('keydown',escape,true);q('[data-close]').onclick=close;q('[data-reference-review]').onclick=()=>{if(busy)return;close();window.TT_MILL_REFERENCES?.open();};
    function remember(){reason=q('[data-reason]')?.value??reason;correctionDate=q('[data-correction-date]')?.value??correctionDate;}
    function detail(row) {
      const box=q('[data-review]');box.hidden=!row;if(!row)return;
      const posted=row.posted,live=posted&&posted.status==='Posted',changed=live&&posted.fingerprint!==row.fingerprint;
      box.innerHTML=`<hr><h3>${esc(row.reference||'Saved Mill bill')}</h3><dl><dt>Company / mill</dt><dd>${esc(company)} · ${esc(row.millName)}</dd><dt>Payee</dt><dd>${esc(row.party)}</dd><dt>Bill date / cost period</dt><dd>${esc(date(row.date))} · ${esc(date(row.from))} to ${esc(date(row.to))}</dd><dt>Saved source amount</dt><dd>PKR ${money(row.amount)}</dd><dt>Accounts</dt><dd>${posted?esc(posted.status)+' · '+esc(posted.journalId)+' · PKR '+money(posted.amount)+(posted.outstandingAmount!==undefined?' · Outstanding PKR '+money(posted.outstandingAmount):''):'No linked liability has been posted.'}</dd></dl>${row.workLines?.length?'<h4>Saved work and rates</h4><table><tr><th>Task</th><th>Quantity</th><th>Rate basis</th><th>Rate</th></tr>'+row.workLines.map(l=>'<tr><td>'+esc(l.label||l.key)+'</td><td>'+esc(l.qty)+'</td><td>'+esc(l.basis)+'</td><td>'+esc(l.rate)+'</td></tr>').join('')+'</table>':''}${row.reprocessing?'<p>Arrival '+esc(row.reprocessing.arrivalKg)+' kg · Raw removed '+esc(row.reprocessing.rawRemovedKg)+' kg · Billable '+esc(row.reprocessing.billableKg)+' kg · Rate '+esc(row.reprocessing.rate)+' / kg · Loading extras '+money(row.reprocessing.loadingExtra)+' · Other '+money(row.reprocessing.other)+'</p>':''}${row.blocker?'<p role="alert">'+esc(row.blocker)+'</p>':''}${changed?'<p role="alert">The saved bill or its master mapping differs from the posted liability. Payment is held until the changed source and its liability are reconciled.</p>':''}<p>Recording a liability does not pay it. Supplier payments settle the linked bill and retain its balance.</p>${data.canCorrect&&live&&!(posted.settledAmount>0)?`<label>Correction / cancellation reason<input data-reason maxlength="300" value="${esc(reason)}"></label> <label>Correction date<input data-correction-date type="date" value="${esc(correctionDate)}"></label><p><button class="btn" data-reverse>Cancel unpaid liability</button>${changed&&!row.blocker?' <button class="btn green" data-replace>Replace with reviewed source</button>':''}</p>`:''}${live&&posted.settledAmount>0?'<p>Payments have been allocated to this bill. Correct the linked settlement before changing its liability.</p>':''}${data.canPost&&!live&&!row.blocker&&(!posted||posted.fingerprint!==row.fingerprint)?'<button class="btn green" data-post>Record liability</button>':''}${live&&!changed&&!row.blocker&&posted.outstandingAmount!==0?'<button class="btn" data-pay>Open linked supplier payment</button>':''}`;
      box.querySelector('[data-post]')?.addEventListener('click',()=>save('post',row));
      box.querySelector('[data-reverse]')?.addEventListener('click',()=>save('reverse',row));
      box.querySelector('[data-replace]')?.addEventListener('click',()=>save('replace',row));
      box.querySelector('[data-pay]')?.addEventListener('click',()=>{close();window.TT_SUPPLIER_SETTLEMENT_UI?.openBills('',row.party,posted.billId);});
      window.TT_FORM_VIEWPORT?.open(box);
    }
    const filteredRows=()=>data.rows.filter(r=>{const v=q('[data-kind]').value,maize=isMaize(r);return !v||(v==='RICE_LABOUR'?r.kind==='LABOUR'&&commodity(r)==='RICE':v==='MAIZE_LABOUR'?r.kind==='LABOUR'&&maize:r.kind===v);});
    function render() {
      const rows=filteredRows();
      q('[data-list]').innerHTML=rows.length?`<table><thead><tr><th>Bill date</th><th>Bill type</th><th>Reference</th><th>Mill</th><th>Payee</th><th>PKR</th><th>Accounts status</th><th></th></tr></thead><tbody>${rows.map(r=>`<tr><td>${esc(date(r.date))}</td><td>${esc(billType(r))}</td><td>${esc(r.reference)}</td><td>${esc(r.millName)}</td><td>${esc(r.party)}</td><td>${money(r.amount)}</td><td>${esc(r.blocker?'Needs source review':r.posted?.status||'Unposted')}</td><td><button class="btn" data-review-bill="${esc(identity(r))}">Review</button></td></tr>`).join('')}</tbody></table>`:'<p>No saved Mill bills for this selection.</p>';
      q('[data-list]').querySelectorAll('[data-review-bill]').forEach(b=>b.onclick=()=>{remember();selected=b.dataset.reviewBill;reason='';pending=null;detail(data.rows.find(r=>identity(r)===selected));});
      detail(data.rows.find(r=>identity(r)===selected));
    }
    async function load(){
      if(busy)return;remember();busy=true;host.inert=true;
      try{const next=await request(company);if(!host.isConnected||entity()!==company)return;data=next;checkedAt=new Date().toLocaleString('en-GB',{timeZone:'Asia/Karachi'})+' PKT';render();q('[data-message]').textContent='Saved sources only. Review and post each actual bill explicitly; Rice and Maize use their separate saved work and agreed rates.';}
      catch(e){q('[data-message]').textContent=e.message;}
      finally{busy=false;host.inert=false;}
    }
    async function save(action,row){
      if(busy)return;remember();const payload={action,kind:row.kind,sourceId:row.sourceId,sourceKey:row.key,fingerprint:row.fingerprint,postedFingerprint:row.posted?.fingerprint||'',reason,correctionDate};
      if(action!=='post'&&!reason.trim()){q('[data-message]').textContent='Enter the correction or cancellation reason.';q('[data-reason]')?.focus();return;}
      const signature=JSON.stringify(payload);if(!pending||pending.signature!==signature)pending={signature,key:crypto.randomUUID()};
      busy=true;host.inert=true;
      try{const saved=await request(company,{...payload,requestKey:pending.key});if(!host.isConnected||entity()!==company)return;pending=null;row.posted=saved.result.record;render();const acknowledgement='Saved in Accounts · '+saved.result.record.journalId+(saved.result.record.reversalJournalId?' · reversal '+saved.result.record.reversalJournalId:'');q('[data-message]').textContent=acknowledgement;try{data=await request(company);checkedAt=new Date().toLocaleString('en-GB',{timeZone:'Asia/Karachi'})+' PKT';render();q('[data-message]').textContent=acknowledgement;}catch(refreshError){q('[data-message]').textContent=acknowledgement+'. Refresh the saved bills when the connection returns.';}}
      catch(e){q('[data-message]').textContent=e.message+' Your review and correction text are retained.';}
      finally{busy=false;host.inert=false;}
    }
    q('[data-kind]').onchange=()=>{remember();render();};q('[data-refresh]').onclick=load;
    const outputHeaders=['Company','Kind','Commodity','Bill reference','Bill date','Period from','Period to','Mill','Payee','Saved source PKR','Accounts status','Posted PKR','Outstanding PKR','Settled PKR','Post ID','Reversal Post ID','Source review issue'];
    const outputValues=r=>[company,r.kind,r.commodity||'',r.reference,date(r.date),date(r.from),date(r.to),r.millName,r.party,r.amount,r.posted?.status||'Unposted',r.posted?.amount??'',r.posted?.outstandingAmount??'',r.posted?.settledAmount??'',r.posted?.journalId||'',r.posted?.reversalJournalId||'',r.blocker||(r.posted?.status==='Posted'&&r.posted.fingerprint!==r.fingerprint?'Source changed — payment held':'')];
    q('[data-csv]').onclick=()=>{
      const quote=v=>{let text=String(v??'');if(typeof v==='string'&&/^[=+@-]/.test(text))text="'"+text;return '"'+text.replace(/"/g,'""')+'"';},rows=[outputHeaders,...filteredRows().map(outputValues)];
      const url=URL.createObjectURL(new Blob([rows.map(r=>r.map(quote).join(',')).join('\r\n')],{type:'text/csv;charset=utf-8'})),a=document.createElement('a');a.href=url;a.download=(window.TT_DOCUMENT_NAME?.name({company,title:'Mill Bill Review',date:checkedAt?.slice(0,10),extension:'csv'})||(company+'-mill-bill-review.csv'));a.click();URL.revokeObjectURL(url);
    };
    q('[data-print]').onclick=()=>{
      const target=window.open('','_blank');if(!target){q('[data-message]').textContent='Allow pop-ups to print the saved review.';return;}
      const kind=q('[data-kind]').selectedOptions[0]?.textContent||'All Mill bills';
      target.document.write('<!doctype html><html><head><title data-tt-filename>'+esc(window.TT_DOCUMENT_NAME?.name({company,title:'Mill Bill Review',date:checkedAt?.slice(0,10)})||'Mill Bill Review')+'</title><style>body{font:12px Arial}table{border-collapse:collapse;width:100%}th,td{border:1px solid #aaa;padding:5px;text-align:left}@page{size:A4 landscape;margin:10mm}</style></head><body><h1>Mill Bill Review</h1><p>'+esc(company)+' · '+esc(kind)+' · Last checked '+esc(checkedAt||'unavailable')+'</p><p>Saved source and Accounts status only. Unposted operational amounts have no verified liability.</p><table><thead><tr>'+outputHeaders.map(h=>'<th>'+esc(h)+'</th>').join('')+'</tr></thead><tbody>'+filteredRows().map(r=>'<tr>'+outputValues(r).map(v=>'<td>'+esc(v)+'</td>').join('')+'</tr>').join('')+'</tbody></table></body></html>');target.document.close();target.focus();target.print();
    };
    await load();window.TT_FORM_VIEWPORT?.open(q('[data-kind]'));
  }
  window.TT_MILL_LIABILITIES={open};
})();
