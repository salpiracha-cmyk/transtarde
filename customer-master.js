(()=>{
  'use strict';
  const ROOT='transtrade_export_v3_operational';
  const API='/api/export_customers.php';
  const OPS='/api/operations.mysql.php';
  const access=window.TT_MODULE_ACCESS||window.TT_SESSION||{};
  const csrf=access.csrf||'';
  const isExports=window.TT_MODULE_ACCESS?.module==='Exports';
  const isAdmin=window.TT_SESSION?.role==='Super Admin'&&!window.TT_MODULE_ACCESS;
  if(!isExports&&!isAdmin)return;

  let writing=false, listCache=[], managerOpen=false, syncTimer=0;
  const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const parseRoot=()=>{try{return JSON.parse(localStorage.getItem(ROOT)||'null')}catch{return null}};
  const getNotifiesText=c=>(c.notifies||[]).map(n=>[n.name,n.address].filter(Boolean).join(' | ')).join('\n');
  const parseNotifies=text=>String(text||'').split(/\r?\n/).map(x=>x.trim()).filter(Boolean).map(line=>{const [name,...rest]=line.split('|');return{name:String(name||'').trim(),address:rest.join('|').trim()}});
  const canonical=c=>({masterId:c.masterId||'',name:c.name||'',code:c.code||'',roles:c.roles||'Export Buyer',address:c.address||'',country:c.country||'',email:c.email||'',phone:c.phone||'',tax:c.tax||'',packingDefault:c.packingDefault||'KG',notifies:Array.isArray(c.notifies)?c.notifies:[],status:c.status||'Active',notes:c.notes||''});

  function injectStyle(){
    if(document.getElementById('ttCustomerMasterStyle'))return;
    const s=document.createElement('style');s.id='ttCustomerMasterStyle';s.textContent=`
      .tt-cm-overlay{position:fixed;inset:0;background:rgba(15,23,42,.52);z-index:100000;display:flex;align-items:flex-start;justify-content:center;padding:4vh 16px;overflow:auto}
      .tt-cm-panel{width:min(1120px,96vw);background:#fff;border-radius:18px;box-shadow:0 24px 70px rgba(15,23,42,.28);overflow:hidden}
      .tt-cm-head{display:flex;align-items:center;gap:12px;padding:18px 20px;border-bottom:1px solid #e5e7eb}.tt-cm-head h2{margin:0}.tt-cm-spacer{flex:1}
      .tt-cm-body{padding:18px 20px}.tt-cm-toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px}.tt-cm-toolbar input{min-width:260px;flex:1}
      .tt-cm-table{width:100%;border-collapse:collapse}.tt-cm-table th,.tt-cm-table td{padding:10px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:top}.tt-cm-table th{font-size:12px;text-transform:uppercase;color:#64748b}
      .tt-cm-btn{border:0;border-radius:9px;padding:9px 12px;cursor:pointer;font-weight:700;background:#e2e8f0}.tt-cm-btn.green{background:#16815a;color:#fff}.tt-cm-btn.red{background:#b91c1c;color:#fff}.tt-cm-btn.navy{background:#17324d;color:#fff}.tt-cm-btn:disabled{opacity:.45;cursor:not-allowed}
      .tt-cm-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.tt-cm-field{display:flex;flex-direction:column;gap:5px}.tt-cm-field.full{grid-column:1/-1}.tt-cm-field label{font-size:12px;font-weight:800;color:#475569}.tt-cm-field input,.tt-cm-field select,.tt-cm-field textarea,.tt-cm-toolbar input{border:1px solid #cbd5e1;border-radius:9px;padding:10px;font:inherit}.tt-cm-field textarea{min-height:84px;resize:vertical}
      .tt-cm-status{display:inline-block;padding:4px 8px;border-radius:999px;font-size:12px;font-weight:800;background:#dcfce7;color:#166534}.tt-cm-status.inactive{background:#f1f5f9;color:#64748b}.tt-cm-note{font-size:12px;color:#64748b}.tt-cm-error{background:#fee2e2;color:#991b1b;padding:10px;border-radius:9px;margin-bottom:12px}.tt-cm-success{background:#dcfce7;color:#166534;padding:10px;border-radius:9px;margin-bottom:12px}
      .tt-cm-home-button{white-space:nowrap}
      @media(max-width:720px){.tt-cm-grid{grid-template-columns:1fr}.tt-cm-field.full{grid-column:auto}.tt-cm-table{font-size:13px}.tt-cm-table th:nth-child(3),.tt-cm-table td:nth-child(3){display:none}}
    `;document.head.appendChild(s);
  }

  async function request(action,payload={}){
    const r=await fetch(API,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf,action,...payload})});
    const j=await r.json().catch(()=>({ok:false,error:'Customer master returned an invalid response.'}));
    if(!r.ok||!j.ok)throw new Error(j.error||'Customer master action failed.');
    if(Array.isArray(j.customers))listCache=j.customers;
    return j;
  }
  async function loadList(){const r=await fetch(API,{credentials:'same-origin'}),j=await r.json();if(!r.ok||!j.ok)throw new Error(j.error||'Could not load customers.');listCache=j.customers||[];return listCache}

  function usedByExports(master){
    const root=parseRoot();if(!root)return false;
    const local=(root.customers||[]).find(c=>c.masterId===master.masterId||String(c.name||'').toLowerCase()===String(master.name||'').toLowerCase());
    const id=local?.id;
    return (root.contracts||[]).some(c=>(id&&c.customerId===id)||String(c.customer||'').toLowerCase()===String(master.name||'').toLowerCase())
      ||(root.shipments||[]).some(s=>String(s.buyer||'').toLowerCase()===String(master.name||'').toLowerCase());
  }

  function mergeMastersIntoLocal(masters,removeDeleted=false){
    const root=parseRoot();if(!root||!Array.isArray(root.customers))return false;
    let changed=false;
    for(const m of masters){
      let l=root.customers.find(c=>c.masterId===m.masterId)||root.customers.find(c=>String(c.name||'').toLowerCase()===String(m.name||'').toLowerCase())||root.customers.find(c=>m.code&&String(c.code||'').toUpperCase()===String(m.code).toUpperCase());
      if(!l){
        if(String(m.status||'Active').toLowerCase()==='inactive')continue;
        l={id:`M-${m.masterId}`,nextSeq:1};root.customers.push(l);changed=true;
      }
      const before=JSON.stringify(l);
      Object.assign(l,{masterId:m.masterId,name:m.name,code:m.code,address:m.address,country:m.country,email:m.email,phone:m.phone,tax:m.tax,notifies:m.notifies||[],packingDefault:m.packingDefault||'KG',inactive:String(m.status||'Active').toLowerCase()==='inactive'});
      if(JSON.stringify(l)!==before)changed=true;
    }
    if(removeDeleted){
      const ids=new Set(masters.map(x=>x.masterId));
      root.customers=root.customers.filter(c=>!c.masterId||ids.has(c.masterId)||usedByExports({masterId:c.masterId,name:c.name}));
    }
    root.customers.sort((a,b)=>(Number(!!a.inactive)-Number(!!b.inactive))||String(a.name||'').localeCompare(String(b.name||'')));
    if(changed){writing=true;localStorage.setItem(ROOT,JSON.stringify(root));writing=false}
    return changed;
  }

  async function syncLocalCustomers(){
    if(!isExports)return;
    const root=parseRoot();if(!root?.customers?.length)return;
    let touched=false;
    for(const local of root.customers){
      if(!local?.name||!local?.address)continue;
      const master=listCache.find(m=>m.masterId===local.masterId)||listCache.find(m=>String(m.name||'').toLowerCase()===String(local.name||'').toLowerCase())||listCache.find(m=>local.code&&String(m.code||'').toUpperCase()===String(local.code).toUpperCase());
      if(master){if(!local.masterId){local.masterId=master.masterId;touched=true}continue}
      try{
        const j=await request('upsert',{mode:'sync',customer:{masterId:local.masterId||'',name:local.name,code:local.code,address:local.address,country:local.country||'',email:local.email||'',phone:local.phone||'',tax:local.tax||'',packingDefault:local.packingDefault||'KG',notifies:local.notifies||[],roles:'Export Buyer',status:local.inactive?'Inactive':'Active'}});
        if(j.customer?.masterId){local.masterId=j.customer.masterId;touched=true}
      }catch(e){console.warn('Customer master sync:',e)}
    }
    if(touched){writing=true;localStorage.setItem(ROOT,JSON.stringify(root));writing=false}
  }

  function queueSync(){clearTimeout(syncTimer);syncTimer=setTimeout(()=>syncLocalCustomers(),180)}
  function hookStorage(){
    if(!isExports||window.__TT_CUSTOMER_STORAGE_HOOK)return;window.__TT_CUSTOMER_STORAGE_HOOK=true;
    const prior=Storage.prototype.setItem;
    Storage.prototype.setItem=function(k,v){const out=prior.apply(this,arguments);if(this===localStorage&&String(k)===ROOT&&!writing)queueSync();return out};
  }

  function overlayBase(title){
    document.getElementById('ttCustomerMasterOverlay')?.remove();
    const o=document.createElement('div');o.id='ttCustomerMasterOverlay';o.className='tt-cm-overlay';o.innerHTML=`<div class="tt-cm-panel"><div class="tt-cm-head"><h2>${esc(title)}</h2><div class="tt-cm-spacer"></div><button class="tt-cm-btn" data-cm-close>Close</button></div><div class="tt-cm-body" id="ttCustomerMasterBody"></div></div>`;document.body.appendChild(o);o.querySelector('[data-cm-close]').onclick=()=>{managerOpen=false;o.remove()};o.onclick=e=>{if(e.target===o){managerOpen=false;o.remove()}};managerOpen=true;return o;
  }

  async function openManager(){
    injectStyle();const o=overlayBase('Customer Management');const body=o.querySelector('#ttCustomerMasterBody');body.innerHTML='<div class="tt-cm-note">Loading shared customer master…</div>';
    try{await loadList();renderManager(body)}catch(e){body.innerHTML=`<div class="tt-cm-error">${esc(e.message)}</div>`}
  }
  function renderManager(body){
    body.innerHTML=`<div class="tt-cm-toolbar"><input id="ttCmSearch" placeholder="Search customer, code, country"><button class="tt-cm-btn green" id="ttCmAdd">+ ADD CUSTOMER</button></div><div class="tt-cm-note" style="margin-bottom:10px">This is the same customer master used by Export Sales Contracts and Super Admin → Parties. Customers already used in history are archived instead of erased.</div><div id="ttCmRows"></div>`;
    const draw=()=>{const q=String(body.querySelector('#ttCmSearch').value||'').toLowerCase(),rows=listCache.filter(c=>[c.name,c.code,c.country,c.roles,c.status].join(' ').toLowerCase().includes(q));body.querySelector('#ttCmRows').innerHTML=`<div style="overflow:auto"><table class="tt-cm-table"><thead><tr><th>Customer</th><th>Code</th><th>Country</th><th>Role</th><th>Status</th><th>Actions</th></tr></thead><tbody>${rows.length?rows.map(c=>`<tr><td><b>${esc(c.name)}</b><div class="tt-cm-note">${esc(c.address||'Address incomplete')}</div></td><td>${esc(c.code)}</td><td>${esc(c.country||'—')}</td><td>${esc(c.roles||'Export Buyer')}</td><td><span class="tt-cm-status ${String(c.status).toLowerCase()==='inactive'?'inactive':''}">${esc(c.status||'Active')}</span></td><td><button class="tt-cm-btn" data-cm-edit="${esc(c.masterId)}">Edit</button> <button class="tt-cm-btn red" data-cm-delete="${esc(c.masterId)}">Delete</button></td></tr>`).join(''):'<tr><td colspan="6">No customers found.</td></tr>'}</tbody></table></div>`;body.querySelectorAll('[data-cm-edit]').forEach(b=>b.onclick=()=>openEditor(listCache.find(x=>x.masterId===b.dataset.cmEdit)));body.querySelectorAll('[data-cm-delete]').forEach(b=>b.onclick=()=>deleteCustomer(listCache.find(x=>x.masterId===b.dataset.cmDelete),body))};
    body.querySelector('#ttCmSearch').oninput=draw;body.querySelector('#ttCmAdd').onclick=()=>openEditor(null);draw();
  }

  function openEditor(c){
    const o=overlayBase(c?'Amend Customer':'Add Customer'),body=o.querySelector('#ttCustomerMasterBody'),x=canonical(c||{});body.innerHTML=`<div id="ttCmMessage"></div><div class="tt-cm-grid"><div class="tt-cm-field"><label>Customer Name *</label><input id="ttCName" value="${esc(x.name)}"></div><div class="tt-cm-field"><label>Code / Reference</label><input id="ttCCode" value="${esc(x.code)}"></div><div class="tt-cm-field full"><label>Full Address *</label><textarea id="ttCAddress">${esc(x.address)}</textarea></div><div class="tt-cm-field"><label>Country</label><input id="ttCCountry" value="${esc(x.country)}"></div><div class="tt-cm-field"><label>Role(s)</label><input id="ttCRoles" value="${esc(x.roles||'Export Buyer')}" list="ttCustomerRoles"><datalist id="ttCustomerRoles"><option value="Export Buyer"><option value="Export Buyer; Notify Party"><option value="Buyer"><option value="Supplier"><option value="Broker"></datalist></div><div class="tt-cm-field"><label>Email</label><input id="ttCEmail" type="email" value="${esc(x.email)}"></div><div class="tt-cm-field"><label>Phone</label><input id="ttCPhone" value="${esc(x.phone)}"></div><div class="tt-cm-field"><label>VAT / Tax / Registration</label><input id="ttCTax" value="${esc(x.tax)}"></div><div class="tt-cm-field"><label>Default Packing Unit</label><select id="ttCPack"><option ${x.packingDefault==='KG'?'selected':''}>KG</option><option ${x.packingDefault==='LB'?'selected':''}>LB</option></select></div><div class="tt-cm-field"><label>Status</label><select id="ttCStatus"><option ${x.status!=='Inactive'?'selected':''}>Active</option><option ${x.status==='Inactive'?'selected':''}>Inactive</option></select></div><div class="tt-cm-field full"><label>Notify Parties — one per line as Name | Full Address</label><textarea id="ttCNotify">${esc(getNotifiesText(x))}</textarea></div><div class="tt-cm-field full"><label>Notes</label><textarea id="ttCNotes">${esc(x.notes)}</textarea></div></div><div class="tt-cm-toolbar" style="margin-top:16px"><button class="tt-cm-btn" id="ttCBack">Back</button><div class="tt-cm-spacer"></div><button class="tt-cm-btn green" id="ttCSave">${c?'SAVE CHANGES':'SAVE CUSTOMER'}</button></div>`;
    body.querySelector('#ttCBack').onclick=openManager;
    body.querySelector('#ttCSave').onclick=async()=>{const q=id=>body.querySelector('#'+id),saveButton=q('ttCSave'),message=q('ttCmMessage');const customer={masterId:c?.masterId||'',name:q('ttCName').value.trim(),code:q('ttCCode').value.trim(),address:q('ttCAddress').value.trim(),country:q('ttCCountry').value.trim(),roles:q('ttCRoles').value.trim()||'Export Buyer',email:q('ttCEmail').value.trim(),phone:q('ttCPhone').value.trim(),tax:q('ttCTax').value.trim(),packingDefault:q('ttCPack').value,status:q('ttCStatus').value,notifies:parseNotifies(q('ttCNotify').value),notes:q('ttCNotes').value.trim()};if(!customer.name||!customer.address){message.innerHTML='<div class="tt-cm-error">Customer name and full address are required.</div>';return}try{saveButton.disabled=true;const j=await request('upsert',{mode:'edit',customer});mergeMastersIntoLocal(j.customers||[],true);if(isExports){sessionStorage.setItem('tt-cm-reopen','1');location.reload();return}await openManager()}catch(e){message.innerHTML=`<div class="tt-cm-error">${esc(e.message)}</div>`;saveButton.disabled=false}};
  }

  async function deleteCustomer(c,body){
    if(!c)return;const used=usedByExports(c),message=used?`This customer is already used in Export history. Delete will ARCHIVE it so old contracts remain intact. Continue?`:`Delete ${c.name}? This customer is not used in the Export data currently available to this session.`;if(!confirm(message))return;
    try{const j=await request('delete',{masterId:c.masterId,used});mergeMastersIntoLocal(j.customers||[],true);if(isExports){location.reload();return}renderManager(body);alert(j.message||'Customer updated.')}catch(e){alert(e.message)}
  }

  function addExportButtons(){
    if(!isExports)return;
    const nav=document.getElementById('nav');if(nav&&!nav.querySelector('[data-tt-customers]')){const b=document.createElement('button');b.textContent='Customers';b.dataset.ttCustomers='1';b.onclick=openManager;nav.appendChild(b)}
    const actions=document.querySelector('.homeActions');if(actions&&!actions.querySelector('.tt-cm-home-button')){const b=document.createElement('button');b.className='btn navy tt-cm-home-button';b.textContent='CUSTOMERS';b.title='Add, amend, archive or delete customers';b.onclick=openManager;actions.insertBefore(b,actions.firstChild)}
    const root=parseRoot();if(root?.customers){const inactive=new Set(root.customers.filter(c=>c.inactive).map(c=>c.id));document.querySelectorAll('select#cCustomer option').forEach(o=>{if(inactive.has(o.value)){o.disabled=true;if(!/Inactive/.test(o.textContent))o.textContent+=' (Inactive)'}})}
  }

  function addAdminButton(){
    if(!isAdmin)return;const title=document.getElementById('masterTitle');if(!title||title.textContent.trim()!=='Parties')return;const add=document.getElementById('addMasterRecord');if(!add||document.getElementById('ttAdminCustomerManager'))return;const b=document.createElement('button');b.id='ttAdminCustomerManager';b.className=add.className||'';b.textContent='CUSTOMER MANAGEMENT';b.onclick=e=>{e.preventDefault();e.stopPropagation();openManager()};add.parentNode?.insertBefore(b,add)
  }

  async function migrateSharedCustomers(){
    if(!isAdmin)return false;try{const r=await fetch(OPS+'?r='+Date.now(),{credentials:'same-origin'}),j=await r.json();if(!r.ok||!j.ok)return false;const raw=j.values?.[ROOT];if(typeof raw!=='string')return false;const root=JSON.parse(raw),customers=Array.isArray(root?.customers)?root.customers:[];let created=0;await loadList();for(const c of customers){if(!c?.name||!c?.address)continue;const exists=listCache.find(m=>m.masterId===c.masterId)||listCache.find(m=>String(m.name||'').toLowerCase()===String(c.name||'').toLowerCase());if(exists)continue;const x=await request('upsert',{mode:'sync',customer:{name:c.name,code:c.code||'',address:c.address,country:c.country||'',email:c.email||'',phone:c.phone||'',tax:c.tax||'',packingDefault:c.packingDefault||'KG',notifies:c.notifies||[],roles:'Export Buyer',status:c.inactive?'Inactive':'Active'}});if(x.created)created++}return created>0}catch(e){console.warn('Customer master migration:',e);return false}
  }

  async function init(){
    injectStyle();hookStorage();
    try{await loadList();if(isExports){const changed=mergeMastersIntoLocal(listCache,true);await syncLocalCustomers();if(changed&&!sessionStorage.getItem('tt-cm-initial-sync')){sessionStorage.setItem('tt-cm-initial-sync','1');location.reload();return}}else{const migrated=await migrateSharedCustomers();if(migrated&&!sessionStorage.getItem('tt-cm-admin-migrated')){sessionStorage.setItem('tt-cm-admin-migrated','1');location.reload();return}}}catch(e){console.warn('Customer master init:',e)}
    const observer=new MutationObserver(()=>{addExportButtons();addAdminButton()});observer.observe(document.documentElement,{childList:true,subtree:true});addExportButtons();addAdminButton();
    if(isExports&&sessionStorage.getItem('tt-cm-reopen')){sessionStorage.removeItem('tt-cm-reopen');setTimeout(openManager,80)}
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();
