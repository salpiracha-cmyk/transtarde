(()=>{
  'use strict';
  const q=s=>document.querySelector(s);
  const entity=()=>localStorage.getItem('tt_accounts_entity')||'TTI';
  const currency=()=>entity()==='TG'?'AED':'Rs';
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const fmt=n=>Number(n||0).toLocaleString('en-PK',{minimumFractionDigits:2,maximumFractionDigits:2});
  const today=()=>new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Karachi',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
  const yearStart=(date=today())=>String(Number(date.slice(0,4))-(Number(date.slice(5,7))<7?1:0))+'-07-01';
  const sum=(rows,key)=>rows.reduce((n,row)=>n+Number(row[key]||0),0);
  const table=(heads,rows)=>`<div class="tableWrap"><table><thead><tr>${heads.map(x=>`<th>${esc(x)}</th>`).join('')}</tr></thead><tbody>${rows.join('')||`<tr><td colspan="${heads.length}">No matching entries.</td></tr>`}</tbody></table></div>`;
  let data=null,view='tb';
  const titles={tb:'Trial Balance',gl:'General Ledger',pl:'Profit & Loss',bs:'Balance Sheet',expenses:'Expenses',personal:'Personal Adjustments',vehicles:'Vehicle / Asset Costs',balances:'Receivables / Payables',commodity:'Commodity & Local Sales',shipment:'Shipment Contribution · Provisional'};
  const commodityAccounts=new Set(['1310','1320','1330','2110','2120','2210','4200','4300','5100','5200','5300','5400']);
  const accountQuery=()=>String(q('#ttReportAccount')?.value||'').trim().toLowerCase();
  const textQuery=()=>String(q('#ttReportSearch')?.value||'').trim().toLowerCase();
  const accountMatch=x=>!accountQuery()||String(`${x.account||''} ${x.accountName||x.name||''}`).toLowerCase().includes(accountQuery());
  const rowMatch=x=>accountMatch(x)&&(!textQuery()||Object.values(x).some(v=>String(v??'').toLowerCase().includes(textQuery())));
  const rows=items=>(items||[]).filter(rowMatch);
  function ledger(){const source=q('#ttReportSource')?.value||'';return rows(data?.generalLedger).filter(x=>(view!=='commodity'||commodityAccounts.has(String(x.account)))&&(!source||x.sourceType===source)).sort((a,b)=>String(a.date).localeCompare(String(b.date))||String(a.journalId).localeCompare(String(b.journalId),undefined,{numeric:true}))}
  let loadSequence=0,filterEntity=entity();
  function setReportOptions(select,options,emptyLabel){
    if(!select)return;
    const old=select.value,label=select.selectedOptions?.[0]?.textContent||old;
    if(old&&!options.some(row=>String(row[0])===old))options.push([old,label]);
    select.innerHTML='<option value="">'+esc(emptyLabel)+'</option>'+options.map(([value,text])=>`<option value="${esc(value)}">${esc(text)}</option>`).join('');select.value=old;
  }
  function postButton(label,id=label){return id?`<button type="button" data-report-post="${esc(id)}" aria-label="Open posting ${esc(label)}" style="border:0;background:none;padding:0;color:#146b50;text-decoration:underline;font:inherit">${esc(label)}</button>`:esc(label);}
  function recordRow(record,postColumn=-1,id=''){return '<tr>'+record.map((value,column)=>'<td>'+(column===postColumn?postButton(value,id||value):esc(typeof value==='number'?fmt(value):value))+'</td>').join('')+'</tr>';}

  async function load(){
    const sequence=++loadSequence,requestedEntity=entity(),requestedView=view;
    if(filterEntity!==requestedEntity){for(const id of ['ttReportAccount','ttReportSource','ttReportSearch','ttBalanceKind','ttExpenseFor','ttExpenseRecipient','ttExpenseAsset']){const control=q('#'+id);if(control)control.value='';}filterEntity=requestedEntity;}

    const asOf=q('#ttReportAsOf')?.value||today(),from=['tb','bs','balances'].includes(view)?asOf.slice(0,4)+'-01-01':q('#ttReportFrom')?.value||yearStart();
    const url='../api/accounts_reports.php?'+new URLSearchParams({entity:requestedEntity,from,asOf});
    const response=await fetch(url,{credentials:'same-origin',headers:{Accept:'application/json'}}),result=await response.json();
    if(sequence!==loadSequence||requestedEntity!==entity())return;
    if(!response.ok||!result.ok)throw new Error(result.error||'Could not load reports.');
    if(requestedView==='shipment'){
      const response=await fetch('../api/shipment_profitability.php?entity='+encodeURIComponent(requestedEntity),{credentials:'same-origin',headers:{Accept:'application/json'}}),shipments=await response.json();
      if(sequence!==loadSequence||requestedEntity!==entity()||requestedView!==view)return;
      if(!response.ok||!shipments.ok)throw new Error(shipments.error||'Could not load shipment contribution.');
      result.shipmentRows=shipments.rows||[];
    }
    data=result;
    setReportOptions(q('#ttReportSource'),[...new Set((data.generalLedger||[]).map(x=>x.sourceType).filter(Boolean))].sort().map(x=>[x,x.replaceAll('_',' ')]),'All posting types');
    setReportOptions(q('#ttExpenseFor'),[['FAM-SALMAN','Salman'],['FAM-TALHA','Talha'],['FAM-TAYYAB','Tayyab'],['FAM-ABU','ARP'],['SHARED','Shared–Common']],'Everyone / Shared');
    setReportOptions(q('#ttExpenseRecipient'),[...new Set((data.expenseActivity||[]).map(x=>x.recipient).filter(Boolean))].sort().map(value=>[value,value]),'All recipients');
    const assets=new Map((data.expenseActivity||[]).filter(x=>x.assetId).map(x=>[x.assetId,[x.assetName,x.registrationNo].filter(Boolean).join(' · ')]));
    setReportOptions(q('#ttExpenseAsset'),[...assets],'All vehicles / assets');render();
  }
  function activity(){return (data?.expenseActivity||[]).filter(x=>(view!=='vehicles'||x.assetId)&&rowMatch(x)&&(!q('#ttExpenseFor')?.value||x.expenseFor===q('#ttExpenseFor').value)&&(!q('#ttExpenseArea')?.value||x.expenseArea===q('#ttExpenseArea').value)&&(!q('#ttExpenseRecipient')?.value||x.recipient===q('#ttExpenseRecipient').value)&&(!q('#ttExpenseAsset')?.value||x.assetId===q('#ttExpenseAsset').value)).sort((a,b)=>String(a.date).localeCompare(String(b.date))||String(a.publicPostId||a.journalId).localeCompare(String(b.publicPostId||b.journalId),undefined,{numeric:true}));}
  function activityRecords(){const source=activity(),group=q('#ttExpenseGroup')?.value||'detail';if(group==='detail')return [['Date','Post ID','Expense','Paid to','Expense for','Area','Treatment','Vehicle / asset','Purpose','Amount'],source.map(x=>[x.date,x.publicPostId||x.journalId,x.accountName,x.recipient,x.expenseForName||'Shared–Common',x.expenseArea||'',x.personalTreatment||'COMPANY',[x.assetName,x.registrationNo].filter(Boolean).join(' · '),x.purpose,x.amount])];const grouped=new Map();for(const x of source){const key=group==='month'?x.date.slice(0,7):group==='recipient'?x.recipient:group==='asset'?[x.assetName,x.registrationNo].filter(Boolean).join(' · '):x.accountName;grouped.set(key||'Unassigned',(grouped.get(key||'Unassigned')||0)+x.amount);}return [[group==='month'?'Month':group==='recipient'?'Recipient':group==='asset'?'Vehicle / asset':'Expense','Amount'],[...grouped].sort((a,b)=>a[0].localeCompare(b[0]))];}
  function trialRows(){
    const mode=q('#ttTrialView')?.value||'account';
    if(mode==='party')return rows(data?.trialBalance.partyRows||[]);
    const source=rows(data?.trialBalance.rows);
    if(mode!=='type')return source;
    const grouped=new Map();for(const r of source){const key=r.class||'Other';if(!grouped.has(key))grouped.set(key,{account:'',name:key,class:key,debit:0,credit:0});const item=grouped.get(key);item.debit+=Number(r.debit||0);item.credit+=Number(r.credit||0);}
    return [...grouped.values()].sort((a,b)=>a.name.localeCompare(b.name));
  }
  function syncControls(){if(!q('#ttReportFrom'))return;q('#ttReportFrom').closest('label').hidden=['tb','bs','balances','shipment'].includes(view);q('#ttReportAsOf').closest('label').hidden=view==='shipment';q('#ttReportAccount').closest('label').hidden=view==='shipment';q('#ttTrialViewWrap').hidden=view!=='tb';q('#ttReportSourceWrap').hidden=!['gl','commodity'].includes(view);const title=q('#ttReportsPanel h2');if(title)title.textContent=titles[view]||'Financial Reports';q('#ttBalanceFilterWrap').hidden=view!=='balances';}
  function partyBalances(){const kind=q('#ttBalanceKind')?.value||'';return rows(data?.trialBalance?.partyRows||[]).filter(row=>/^(12|21|22|24|25)/.test(String(row.account))&&(!kind||String(row.account).startsWith(kind==='receivable'?'12':'2'))).sort((a,b)=>String(a.party||'').localeCompare(String(b.party||''))||String(a.account).localeCompare(String(b.account)));}
  function shipmentRows(){return (data?.shipmentRows||[]).filter(row=>!textQuery()||Object.values(row).some(value=>String(value??'').toLowerCase().includes(textQuery())));}

  function reportRows(exporting=false){
    if(!data)return ['Date,Journal,Posting Type,Account,Account Name,Reference,Narration,Debit,Credit',[]];
    if(view==='personal')return [['Original Post ID','Expense for','Treatment','Personal amount','Cash recovered','Cash outstanding'],(data.personalAdjustments||[]).flatMap(link=>(link.rows||[]).map(r=>[link.publicPostId,r.expenseForName,r.personalTreatment,r.amount,r.cashRecovered||0,r.recoverable||0]))];if(['expenses','vehicles'].includes(view))return activityRecords();
    if(view==='balances')return [['Party','Account','Account name','Book currency','Debit balance','Credit balance'],partyBalances().map(row=>[row.party,row.account,row.name,entity()==='TG'?'AED':'PKR',row.debit,row.credit])];
    if(view==='shipment')return [['Contract','Lot','Revenue','Stock cost','Transport','Freight','Clearing','Fumigation','Inspection / Other','Total cost','Provisional contribution','Completeness'],shipmentRows().map(row=>[row.contractRef,row.lotRef||row.key,row.revenue,row.stockCost,row.transport,row.freight,row.clearing,row.fumigation,Number(row.inspection||0)+Number(row.otherPostedCost||0),row.totalCost,row.grossContribution,Number(row.revenue)<=0?'Revenue not recognized':Number(row.stockCost)<=0?'Stock cost not recorded':'Cost completeness unverified'])];
    if(['gl','commodity'].includes(view))return [['Date','Journal','Posting Type','Account','Account Name','Reference','Narration','Debit','Credit'],(exporting?ledger().slice().sort((a,b)=>a.date.localeCompare(b.date)||a.journalId.localeCompare(b.journalId,undefined,{numeric:true})):ledger()).map(x=>[x.date,x.publicPostId||x.journalId,x.sourceType,x.account,x.accountName,x.reference,x.narration,x.debit,x.credit])];
    if(view==='tb')return [['Account','Name','Party','Account type','Debit','Credit'],trialRows().map(x=>[x.account,x.name,x.party||'',x.class,x.debit,x.credit])];
    if(view==='pl')return [['Section','Account','Name','Amount'],[...rows(data.profitAndLoss.income).map(x=>['Income',x.account,x.name,x.amount]),...rows(data.profitAndLoss.expenses).map(x=>['Expense',x.account,x.name,x.amount])]];
    return [['Section','Account','Name','Amount'],[...rows(data.balanceSheet.assets).map(x=>['Asset',x.account,x.name,x.amount]),...rows(data.balanceSheet.liabilities).map(x=>['Liability',x.account,x.name,x.amount]),...rows(data.balanceSheet.equity).map(x=>['Equity',x.account,x.name,x.amount])]];
  }
  function download(){
    if(!data)return;const [heads,records]=reportRows(true);
    const quote=v=>{const value=String(window.TT_DATE?.display(v)??v??'');return '"'+(typeof v==='string'&&/^[=+@\-\t\r]/.test(value)?"'":'')+value.replaceAll('"','""')+'"'};
    const csv=[heads.map(quote).join(','),...records.map(record=>record.map(quote).join(','))].join('\r\n');
    const url=URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8'})),link=document.createElement('a');
    link.href=url;link.download=`${entity()}-${view}-${data.from}-to-${data.asOf}.csv`;link.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
  }
  function print(){if(!data)return;const [heads,records]=reportRows(true);const printable=table(heads,records.map(r=>'<tr>'+r.map(v=>'<td>'+esc(typeof v==='number'?fmt(v):window.TT_DATE?.display(v)||v)+'</td>').join('')+'</tr>'));const box=q('#ttReportsBody');if(!box||!data)return;const win=window.TT_PRINT.create();if(!win)return alert('Allow popups to print the report.');
    win.document.write(`<!doctype html><meta charset="utf-8"><title>${esc(entity())} Accounts Report</title><style>body{font:12px Arial;margin:24px;color:#203042}table{width:100%;border-collapse:collapse}td,th{border:1px solid #bac6cf;padding:6px;text-align:left}th{background:#eef3f5}.tableWrap{overflow:visible}button{margin-bottom:15px}@media print{button{display:none}button[data-report-post]{display:inline!important;color:inherit!important;text-decoration:none!important}}</style><button onclick="print()">Print</button><h2>${esc(entity())} · ${esc(titles[view]||view)}</h2><p>${view==='shipment'?'All saved shipment records · Provisional':(['tb','bs','balances'].includes(view)?'As of '+esc(data.asOf):esc(data.from)+' to '+esc(data.asOf))} · Account ${esc(q('#ttReportAccount')?.value||'All')} · Search ${esc(q('#ttReportSearch')?.value||'All')} · Posting ${esc(q('#ttReportSource')?.value||'All')}</p>${['gl','commodity'].includes(view)?printable:box.innerHTML}`);win.document.close();win.print();
  }
  function render(){syncControls();const box=q('#ttReportsBody');if(!box||!data)return;
    q('#ttExpenseFilters').hidden=!['expenses','vehicles'].includes(view);
    if(['balances','shipment'].includes(view)){const [heads,records]=reportRows();const note=view==='balances'?'Posted balances in company books. Invoice currencies and due dates remain in the party ledger.':'Provisional source summary. Revenue recognition and costs may be incomplete; compare booked results in Profit & Loss.';box.innerHTML='<p role="status">'+esc(note)+'</p>'+table(heads,records.map(record=>'<tr>'+record.map(value=>'<td>'+esc(typeof value==='number'?fmt(value):value)+'</td>').join('')+'</tr>'));return;}
    if(['expenses','vehicles'].includes(view)){const [heads,records]=activityRecords(),source=activity();box.innerHTML=table(heads,records.map((r,index)=>recordRow(r,(q('#ttExpenseGroup')?.value||'detail')==='detail'?1:-1,source[index]?.journalId)))+`<p style="font-size:20px"><b>Total ${currency()} ${fmt(activity().reduce((n,x)=>n+x.amount,0))}</b></p>`;return;}
    if(view==='personal'){const [heads,records]=reportRows();box.innerHTML=table(heads,records.map(r=>recordRow(r,0)));return;}if(['gl','commodity'].includes(view)){box.innerHTML=(view==='commodity'?'<p>Posted commodity and local-sale activity · quantities remain in the source records.</p>':'')+table(['Date','Journal','Type','Account','Reference','Narration','Debit','Credit'],ledger().map(x=>`<tr><td>${esc(x.date)}</td><td>${postButton(x.publicPostId||x.journalId,x.journalId)}</td><td>${esc(x.sourceType)}</td><td>${esc(x.account)} · ${esc(x.accountName)}</td><td>${esc(x.reference)}</td><td>${esc(x.narration)}</td><td>${x.debit?fmt(x.debit):''}</td><td>${x.credit?fmt(x.credit):''}</td></tr>`));return;}
    if(view==='tb'){const selected=trialRows(),filtered=!!(accountQuery()||textQuery()),dr=sum(selected,'debit'),cr=sum(selected,'credit');box.innerHTML=table(['Account / Name','Party','Account type','Debit','Credit'],[...selected.map(a=>`<tr><td>${esc(a.account)} ${esc(a.name)}</td><td>${esc(a.party||'')}</td><td>${esc(a.class)}</td><td>${a.debit?fmt(a.debit):''}</td><td>${a.credit?fmt(a.credit):''}</td></tr>`),`<tr><td colspan="3"><b>${filtered?'Filtered total':'Total · '+(Math.abs(dr-cr)<.005?'Balanced':'OUT OF BALANCE')}</b></td><td><b>${fmt(dr)}</b></td><td><b>${fmt(cr)}</b></td></tr>`]);return;}
    if(view==='pl'){const x=data.profitAndLoss,income=rows(x.income),expenses=rows(x.expenses),filtered=!!(accountQuery()||textQuery());box.innerHTML='<h3>Income</h3>'+table(['Account','Amount'],income.map(a=>`<tr><td>${esc(a.account)} · ${esc(a.name)}</td><td>${fmt(a.amount)}</td></tr>`))+'<h3 style="margin-top:18px">Expenses</h3>'+table(['Account','Amount'],expenses.map(a=>`<tr><td>${esc(a.account)} · ${esc(a.name)}</td><td>${fmt(a.amount)}</td></tr>`))+`<div class="metric" style="margin-top:14px"><span>${filtered?'Filtered income less expenses':'Profit / (Loss)'}</span><strong>${currency()} ${fmt(sum(income,'amount')-sum(expenses,'amount'))}</strong></div>`;return;}
    const x=data.balanceSheet,filtered=!!(accountQuery()||textQuery()),section=(title,items)=>{const selected=rows(items);return `<h3>${title}</h3>`+table(['Account','Amount'],[...selected.map(a=>`<tr><td>${esc(a.account)} · ${esc(a.name)}</td><td>${fmt(a.amount)}</td></tr>`),`<tr><td><b>${filtered?'Filtered':'Total'} ${esc(title)}</b></td><td><b>${fmt(sum(selected,'amount'))}</b></td></tr>`])};
    box.innerHTML=section('Assets',x.assets)+'<div style="height:18px"></div>'+section('Liabilities',x.liabilities)+'<div style="height:18px"></div>'+section('Equity',x.equity)+(filtered?'':`<div class="notice" style="margin-top:14px"><b>Balance check:</b> Assets ${currency()} ${fmt(x.totalAssets)} · Liabilities + Equity ${currency()} ${fmt(x.totalLiabilitiesAndEquity)}</div>`);
  }
  function install(initialLoad=true){const ws=q('#ws-reports');if(!ws||q('#ttReportsPanel'))return;const grid=ws.querySelector('.subGrid');if(grid)grid.style.display='none';const panel=document.createElement('div');panel.id='ttReportsPanel';panel.className='panel';panel.innerHTML=`<div class="panelHead"><div><h2>Financial Reports</h2><p>Posted journals · selected legal entity.</p></div><div style="display:flex;gap:9px;flex-wrap:wrap"><label>From<input id="ttReportFrom" type="date" value="${yearStart()}"></label><label>As of<input id="ttReportAsOf" type="date" value="${today()}"></label><button class="btn primary" id="ttReportRun">Run</button><button class="btn" id="ttReportPrint">Print</button><button class="btn" id="ttReportCsv">Download CSV</button></div></div><div class="masterTabs"><button class="tab active" data-rpt="tb">Trial Balance</button><button class="tab" data-rpt="gl">General Ledger</button><button class="tab" data-rpt="pl">Profit &amp; Loss</button><button class="tab" data-rpt="bs">Balance Sheet</button><button class="tab" data-rpt="expenses">Expenses</button><button class="tab" data-rpt="personal">Personal Adjustments</button><button class="tab" data-rpt="vehicles">Vehicle / Asset Costs</button><button class="tab" data-rpt="balances">Receivables / Payables</button><button class="tab" data-rpt="commodity">Commodity & Local Sales</button><button class="tab" data-rpt="shipment">Shipment Contribution</button></div><div id="ttReportFilters" style="display:flex;gap:9px;padding:12px 18px;flex-wrap:wrap"><label id="ttTrialViewWrap">Trial balance by<select id="ttTrialView"><option value="account">Account name</option><option value="party">Party</option><option value="type">Account type</option></select></label><label id="ttBalanceFilterWrap" hidden>Balance type<select id="ttBalanceKind"><option value="">All receivables / payables</option><option value="receivable">Receivables</option><option value="payable">Payables</option></select></label><label>Account<input id="ttReportAccount" placeholder="Code or account name"></label><label id="ttReportSourceWrap">Posting type<select id="ttReportSource"><option value="">All posting types</option></select></label><label>Search<input id="ttReportSearch" placeholder="Reference, party or narration"></label></div><div id="ttExpenseFilters" hidden style="display:flex;gap:12px;padding:12px 18px;flex-wrap:wrap"><label>Paid to<select id="ttExpenseRecipient"></select></label><label>Expense for<select id="ttExpenseFor"></select></label><label>Area<select id="ttExpenseArea"><option value="">All areas</option><option value="HOME">Home</option><option value="OFFICE">Office</option><option value="MILL">Milling–Production</option><option value="EXPORT">General Export</option></select></label><label>Vehicle / asset<select id="ttExpenseAsset"></select></label><label>Show<select id="ttExpenseGroup"><option value="detail">Each entry</option><option value="expense">By expense type</option><option value="recipient">By recipient</option><option value="asset">By vehicle / asset</option><option value="month">By month</option></select></label></div><div id="ttReportsBody" style="padding:18px">Loading…</div>`;ws.appendChild(panel);
    panel.addEventListener('click',async event=>{const button=event.target.closest('[data-report-post]');if(!button)return;button.disabled=true;try{if(!window.TT_ALL_LEDGERS?.openPosting)throw Error('Posting viewer is unavailable. Reopen Accounts.');await window.TT_ALL_LEDGERS.openPosting(button.dataset.reportPost);}catch(error){alert(error.message);}finally{if(button.isConnected)button.disabled=false;}});
    q('#ttReportRun').onclick=()=>load().catch(e=>q('#ttReportsBody').textContent=e.message);q('#ttReportPrint').onclick=print;q('#ttReportCsv').onclick=download;panel.querySelectorAll('#ttBalanceKind,#ttTrialView,#ttReportAccount,#ttReportSource,#ttReportSearch,#ttExpenseRecipient,#ttExpenseFor,#ttExpenseArea,#ttExpenseAsset,#ttExpenseGroup').forEach(input=>input.addEventListener('input',render));panel.querySelectorAll('[data-rpt]').forEach(button=>button.onclick=()=>open(button.dataset.rpt));syncControls();if(initialLoad)load().catch(e=>q('#ttReportsBody').textContent=e.message);
  }
  async function open(selected='tb'){
    const changed=selected!==view;view=selected;data=null;const ws=q('#ws-reports');if(!ws)return;
    document.querySelectorAll('.workspace.active').forEach(w=>w.classList.remove('active'));
    q('#entityHome').style.display='none';ws.classList.add('active');install(false);if(changed)for(const id of ['ttReportAccount','ttReportSource','ttReportSearch','ttBalanceKind']){const control=q('#'+id);if(control)control.value='';}q('#ttReportsBody').textContent='Loading reports…';syncControls();
    ws.querySelectorAll(':scope > :not(.panelHead):not(#ttReportsPanel)').forEach(x=>x.hidden=true);
    const title=ws.querySelector('.panelHead h2');if(title)title.textContent=titles[view]||'Reports';
    ws.querySelectorAll('[data-rpt]').forEach(b=>b.classList.toggle('active',b.dataset.rpt===view));
    document.dispatchEvent(new CustomEvent('tt:accounts-desk-form-opened',{detail:{title:titles[view]||'Reports'}}));
    window.TT_FORM_VIEWPORT?.open(ws);await load();
  }
  window.TT_REPORTS={open};
  document.addEventListener('click',e=>{if(e.target.closest('.appCard[data-key="reports"]'))setTimeout(install,30);if(e.target.closest('[data-entity]')){data=null;setTimeout(()=>{if(q('#ws-reports.active'))load().catch(error=>q('#ttReportsBody').textContent=error.message)},80)}});
})();
