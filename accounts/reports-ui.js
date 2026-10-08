(()=>{
  'use strict';
  const q=s=>document.querySelector(s);
  const entity=()=>localStorage.getItem('tt_accounts_entity')||'TTI';
  const currency=()=>entity()==='TG'?'AED':'Rs';
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const fmt=n=>Number(n||0).toLocaleString('en-PK',{minimumFractionDigits:2,maximumFractionDigits:2});
  const today=()=>new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Karachi',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
  const sum=(rows,key)=>rows.reduce((n,row)=>n+Number(row[key]||0),0);
  const table=(heads,rows)=>`<div class="tableWrap"><table><thead><tr>${heads.map(x=>`<th>${esc(x)}</th>`).join('')}</tr></thead><tbody>${rows.join('')||`<tr><td colspan="${heads.length}">No matching entries.</td></tr>`}</tbody></table></div>`;
  let data=null,view='tb';
  const accountQuery=()=>String(q('#ttReportAccount')?.value||'').trim().toLowerCase();
  const textQuery=()=>String(q('#ttReportSearch')?.value||'').trim().toLowerCase();
  const accountMatch=x=>!accountQuery()||String(`${x.account||''} ${x.accountName||x.name||''}`).toLowerCase().includes(accountQuery());
  const rowMatch=x=>accountMatch(x)&&(!textQuery()||Object.values(x).some(v=>String(v??'').toLowerCase().includes(textQuery())));
  const rows=items=>(items||[]).filter(rowMatch);
  function ledger(){const source=q('#ttReportSource')?.value||'';return rows(data?.generalLedger).filter(x=>!source||x.sourceType===source)}
  let loadSequence=0;
  async function load(){
    const sequence=++loadSequence,requestedEntity=entity();
    const from=q('#ttReportFrom')?.value||today().slice(0,4)+'-01-01',asOf=q('#ttReportAsOf')?.value||today();
    const url='../api/accounts_reports.php?'+new URLSearchParams({entity:requestedEntity,from,asOf});
    const response=await fetch(url,{credentials:'same-origin',headers:{Accept:'application/json'}}),result=await response.json();
    if(sequence!==loadSequence||requestedEntity!==entity())return;
    if(!response.ok||!result.ok)throw new Error(result.error||'Could not load reports.');
    data=result;const select=q('#ttReportSource');if(select){const old=select.value;select.innerHTML='<option value="">All posting types</option>'+[...new Set((data.generalLedger||[]).map(x=>x.sourceType).filter(Boolean))].sort().map(x=>`<option value="${esc(x)}">${esc(x.replaceAll('_',' '))}</option>`).join('');select.value=old;}
    const recipients=q('#ttExpenseRecipient'),assets=q('#ttExpenseAsset');if(recipients)recipients.innerHTML='<option value="">All recipients</option>'+[...new Set((data.expenseActivity||[]).map(x=>x.recipient).filter(Boolean))].sort().map(x=>`<option>${esc(x)}</option>`).join('');if(assets){const list=new Map((data.expenseActivity||[]).filter(x=>x.assetId).map(x=>[x.assetId,[x.assetName,x.registrationNo].filter(Boolean).join(' · ')]));assets.innerHTML='<option value="">All vehicles / assets</option>'+[...list].map(([id,name])=>`<option value="${esc(id)}">${esc(name)}</option>`).join('');}render();
  }
  function activity(){return (data?.expenseActivity||[]).filter(x=>(view!=='vehicles'||x.assetId)&&rowMatch(x)&&(!q('#ttExpenseRecipient')?.value||x.recipient===q('#ttExpenseRecipient').value)&&(!q('#ttExpenseAsset')?.value||x.assetId===q('#ttExpenseAsset').value));}
  function activityRecords(){const source=activity(),group=q('#ttExpenseGroup')?.value||'detail';if(group==='detail')return [['Date','Post ID','Expense','Paid to','Vehicle / asset','Purpose','Amount'],source.map(x=>[x.date,x.journalId,x.accountName,x.recipient,[x.assetName,x.registrationNo].filter(Boolean).join(' · '),x.purpose,x.amount])];const grouped=new Map();for(const x of source){const key=group==='month'?x.date.slice(0,7):group==='recipient'?x.recipient:group==='asset'?[x.assetName,x.registrationNo].filter(Boolean).join(' · '):x.accountName;grouped.set(key||'Unassigned',(grouped.get(key||'Unassigned')||0)+x.amount);}return [[group==='month'?'Month':group==='recipient'?'Recipient':group==='asset'?'Vehicle / asset':'Expense','Amount'],[...grouped].sort((a,b)=>a[0].localeCompare(b[0]))];}
  function reportRows(){
    if(!data)return ['Date,Journal,Posting Type,Account,Account Name,Reference,Narration,Debit,Credit',[]];
    if(['expenses','vehicles'].includes(view))return activityRecords();
    if(view==='gl')return [['Date','Journal','Posting Type','Account','Account Name','Reference','Narration','Debit','Credit'],ledger().map(x=>[x.date,x.journalId,x.sourceType,x.account,x.accountName,x.reference,x.narration,x.debit,x.credit])];
    if(view==='tb')return [['Account','Name','Class','Debit','Credit'],rows(data.trialBalance.rows).map(x=>[x.account,x.name,x.class,x.debit,x.credit])];
    if(view==='pl')return [['Section','Account','Name','Amount'],[...rows(data.profitAndLoss.income).map(x=>['Income',x.account,x.name,x.amount]),...rows(data.profitAndLoss.expenses).map(x=>['Expense',x.account,x.name,x.amount])]];
    return [['Section','Account','Name','Amount'],[...rows(data.balanceSheet.assets).map(x=>['Asset',x.account,x.name,x.amount]),...rows(data.balanceSheet.liabilities).map(x=>['Liability',x.account,x.name,x.amount]),...rows(data.balanceSheet.equity).map(x=>['Equity',x.account,x.name,x.amount])]];
  }
  function download(){
    if(!data)return;const [heads,records]=reportRows();
    const quote=v=>{const value=String(window.TT_DATE?.display(v)??v??'');return '"'+(typeof v==='string'&&/^[=+@\-\t\r]/.test(value)?"'":'')+value.replaceAll('"','""')+'"'};
    const csv=[heads.map(quote).join(','),...records.map(record=>record.map(quote).join(','))].join('\r\n');
    const url=URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8'})),link=document.createElement('a');
    link.href=url;link.download=`${entity()}-${view}-${data.from}-to-${data.asOf}.csv`;link.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
  }
  function print(){const box=q('#ttReportsBody');if(!box||!data)return;const win=window.TT_PRINT.create();if(!win)return alert('Allow popups to print the report.');
    win.document.write(`<!doctype html><meta charset="utf-8"><title>${esc(entity())} Accounts Report</title><style>body{font:12px Arial;margin:24px;color:#203042}table{width:100%;border-collapse:collapse}td,th{border:1px solid #bac6cf;padding:6px;text-align:left}th{background:#eef3f5}.tableWrap{overflow:visible}button{margin-bottom:15px}@media print{button{display:none}}</style><button onclick="print()">Print</button><h2>${esc(entity())} · ${esc(view.toUpperCase())}</h2><p>${esc(data.from)} to ${esc(data.asOf)} · Account ${esc(q('#ttReportAccount')?.value||'All')} · Search ${esc(q('#ttReportSearch')?.value||'All')} · Posting ${esc(q('#ttReportSource')?.value||'All')}</p>${box.innerHTML}`);win.document.close();win.print();
  }
  function render(){const box=q('#ttReportsBody');if(!box||!data)return;
    q('#ttExpenseFilters').hidden=!['expenses','vehicles'].includes(view);
    if(['expenses','vehicles'].includes(view)){const [heads,records]=activityRecords();box.innerHTML=table(heads,records.map(r=>'<tr>'+r.map(v=>'<td>'+esc(typeof v==='number'?fmt(v):v)+'</td>').join('')+'</tr>'))+`<p style="font-size:20px"><b>Total ${currency()} ${fmt(activity().reduce((n,x)=>n+x.amount,0))}</b></p>`;return;}
    if(view==='gl'){box.innerHTML=table(['Date','Journal','Type','Account','Reference','Narration','Debit','Credit'],ledger().map(x=>`<tr><td>${esc(x.date)}</td><td>${esc(x.journalId)}</td><td>${esc(x.sourceType)}</td><td>${esc(x.account)} · ${esc(x.accountName)}</td><td>${esc(x.reference)}</td><td>${esc(x.narration)}</td><td>${x.debit?fmt(x.debit):''}</td><td>${x.credit?fmt(x.credit):''}</td></tr>`));return;}
    if(view==='tb'){const x=data.trialBalance,selected=rows(x.rows),filtered=!!(accountQuery()||textQuery());box.innerHTML=table(['Account','Class','Debit','Credit'],[...selected.map(a=>`<tr><td>${esc(a.account)} · ${esc(a.name)}</td><td>${esc(a.class)}</td><td>${a.debit?fmt(a.debit):''}</td><td>${a.credit?fmt(a.credit):''}</td></tr>`),`<tr><td colspan="2"><b>${filtered?'Filtered total':'Total · '+(x.balanced?'Balanced':'OUT OF BALANCE')}</b></td><td><b>${fmt(sum(selected,'debit'))}</b></td><td><b>${fmt(sum(selected,'credit'))}</b></td></tr>`]);return;}
    if(view==='pl'){const x=data.profitAndLoss,income=rows(x.income),expenses=rows(x.expenses),filtered=!!(accountQuery()||textQuery());box.innerHTML='<h3>Income</h3>'+table(['Account','Amount'],income.map(a=>`<tr><td>${esc(a.account)} · ${esc(a.name)}</td><td>${fmt(a.amount)}</td></tr>`))+'<h3 style="margin-top:18px">Expenses</h3>'+table(['Account','Amount'],expenses.map(a=>`<tr><td>${esc(a.account)} · ${esc(a.name)}</td><td>${fmt(a.amount)}</td></tr>`))+`<div class="metric" style="margin-top:14px"><span>${filtered?'Filtered income less expenses':'Profit / (Loss)'}</span><strong>${currency()} ${fmt(sum(income,'amount')-sum(expenses,'amount'))}</strong></div>`;return;}
    const x=data.balanceSheet,filtered=!!(accountQuery()||textQuery()),section=(title,items)=>{const selected=rows(items);return `<h3>${title}</h3>`+table(['Account','Amount'],[...selected.map(a=>`<tr><td>${esc(a.account)} · ${esc(a.name)}</td><td>${fmt(a.amount)}</td></tr>`),`<tr><td><b>${filtered?'Filtered':'Total'} ${esc(title)}</b></td><td><b>${fmt(sum(selected,'amount'))}</b></td></tr>`])};
    box.innerHTML=section('Assets',x.assets)+'<div style="height:18px"></div>'+section('Liabilities',x.liabilities)+'<div style="height:18px"></div>'+section('Equity',x.equity)+(filtered?'':`<div class="notice" style="margin-top:14px"><b>Balance check:</b> Assets ${currency()} ${fmt(x.totalAssets)} · Liabilities + Equity ${currency()} ${fmt(x.totalLiabilitiesAndEquity)}</div>`);
  }
  function install(initialLoad=true){const ws=q('#ws-reports');if(!ws||q('#ttReportsPanel'))return;const grid=ws.querySelector('.subGrid');if(grid)grid.style.display='none';const panel=document.createElement('div');panel.id='ttReportsPanel';panel.className='panel';panel.innerHTML=`<div class="panelHead"><div><h2>Financial Reports</h2><p>Posted journals · selected legal entity.</p></div><div style="display:flex;gap:9px;flex-wrap:wrap"><label>From<input id="ttReportFrom" type="date" value="${today().slice(0,4)}-01-01"></label><label>As of<input id="ttReportAsOf" type="date" value="${today()}"></label><button class="btn primary" id="ttReportRun">Run</button><button class="btn" id="ttReportPrint">Print</button><button class="btn" id="ttReportCsv">Download CSV</button></div></div><div class="masterTabs"><button class="tab active" data-rpt="tb">Trial Balance</button><button class="tab" data-rpt="gl">General Ledger</button><button class="tab" data-rpt="pl">Profit &amp; Loss</button><button class="tab" data-rpt="bs">Balance Sheet</button><button class="tab" data-rpt="expenses">Expenses</button><button class="tab" data-rpt="vehicles">Vehicle / Asset Costs</button></div><div id="ttReportFilters" style="display:flex;gap:9px;padding:12px 18px;flex-wrap:wrap"><label>Account<input id="ttReportAccount" placeholder="Code or account name"></label><label id="ttReportSourceWrap">Posting type<select id="ttReportSource"><option value="">All posting types</option></select></label><label>Search<input id="ttReportSearch" placeholder="Reference, party or narration"></label></div><div id="ttExpenseFilters" hidden style="display:flex;gap:12px;padding:12px 18px;flex-wrap:wrap"><label>Recipient<select id="ttExpenseRecipient"></select></label><label>Vehicle / asset<select id="ttExpenseAsset"></select></label><label>Show<select id="ttExpenseGroup"><option value="detail">Each entry</option><option value="expense">By expense type</option><option value="recipient">By recipient</option><option value="asset">By vehicle / asset</option><option value="month">By month</option></select></label></div><div id="ttReportsBody" style="padding:18px">Loading…</div>`;ws.appendChild(panel);
    q('#ttReportRun').onclick=()=>load().catch(e=>q('#ttReportsBody').textContent=e.message);q('#ttReportPrint').onclick=print;q('#ttReportCsv').onclick=download;panel.querySelectorAll('#ttReportAccount,#ttReportSource,#ttReportSearch,#ttExpenseRecipient,#ttExpenseAsset,#ttExpenseGroup').forEach(input=>input.addEventListener('input',render));panel.querySelectorAll('[data-rpt]').forEach(button=>button.onclick=()=>{view=button.dataset.rpt;panel.querySelectorAll('[data-rpt]').forEach(x=>x.classList.toggle('active',x===button));q('#ttReportSourceWrap').hidden=view!=='gl';render()});q('#ttReportSourceWrap').hidden=true;if(initialLoad)load().catch(e=>q('#ttReportsBody').textContent=e.message);
  }
  async function open(selected='tb'){
    view=selected;data=null;const ws=q('#ws-reports');if(!ws)return;
    document.querySelectorAll('.workspace.active').forEach(w=>w.classList.remove('active'));
    q('#entityHome').style.display='none';ws.classList.add('active');install(false);q('#ttReportsBody').textContent='Loading reports…';q('#ttReportSourceWrap').hidden=view!=='gl';
    ws.querySelectorAll(':scope > :not(.panelHead):not(#ttReportsPanel)').forEach(x=>x.hidden=true);
    const title=ws.querySelector('.panelHead h2');if(title)title.textContent='Reports';
    ws.querySelectorAll('[data-rpt]').forEach(b=>b.classList.toggle('active',b.dataset.rpt===view));
    document.dispatchEvent(new CustomEvent('tt:accounts-desk-form-opened',{detail:{title:'Reports'}}));
    window.TT_FORM_VIEWPORT?.open(ws);await load();
  }
  window.TT_REPORTS={open};
  document.addEventListener('click',e=>{if(e.target.closest('.appCard[data-key="reports"]'))setTimeout(install,30);if(e.target.closest('[data-entity]')){data=null;setTimeout(()=>{if(q('#ws-reports.active'))load().catch(error=>q('#ttReportsBody').textContent=error.message)},80)}});
})();
