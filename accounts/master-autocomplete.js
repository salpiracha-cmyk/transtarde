(() => {
  'use strict';
  const access = window.TT_ACCOUNT_ACCESS || {};
  let masters = access.masters || {};
  const clean = value => String(value ?? '').trim();
  const unique = values => [...new Set(values.map(clean).filter(Boolean))].sort((a,b) => a.localeCompare(b));
  const active = row => clean(row?.values?.[10] || 'Active').toLowerCase() !== 'inactive';
  const categories = row => clean(row?.values?.[2]).replace(/Shipping Line\s*\/\s*Carrier/ig,'Shipping Line and Carrier').split(/[;,/|]/).map(value => value.trim().toLowerCase().replace('shipping line and carrier','shipping line / carrier'));
  const partyNames = (...roles) => unique((masters.business_parties || []).filter(row => active(row) && roles.some(role => categories(row).includes(role.toLowerCase()))).map(row => row.values[0]));
  const customerNames = () => unique((masters.export_customers || []).filter(active).map(row => row.values[0]));
  const productNames = () => unique((masters.purchase_products || []).filter(row => clean(row?.values?.[8] || 'Active').toLowerCase() === 'active').map(row => {
    const v = row.values || [], legacy = /^(RAW|READY|FINISHED)$/i.test(clean(v[2]));
    return clean(v[0]).toUpperCase() === 'RICE' ? [v[1],legacy?'':v[2],legacy?v[2]:v[3]].filter(Boolean).join(' ') : [v[1],legacy?v[2]:v[3]].filter(Boolean).join(' ');
  }));
  const lists = () => ({
    buyer:customerNames(), supplier:partyNames('Supplier'), broker:partyNames('Broker'),
    freight:partyNames('Freight Forwarder','Shipping Line / Carrier'), clearing:partyNames('Clearing Agent'),
    transporter:partyNames('Transporter'), fumigation:partyNames('Fumigation'), inspection:partyNames('Inspection'),
    service:partyNames('Service Provider'), shipping:partyNames('Shipping Line / Carrier'),
    parties:unique([...(masters.business_parties || []).filter(active).map(row => row.values[0]),...customerNames()]),
    locations:unique((masters.mills || []).filter(row => clean(row?.values?.[5] || 'Active').toLowerCase() === 'active').map(row => row.values[0])),
    products:productNames(), commodities:unique((masters.commodities || []).map(row => row?.values?.[0]))
  });
  const roleFor = name => ({supplier:'Supplier',broker:'Broker',freight:'Freight Forwarder',clearing:'Clearing Agent',transporter:'Transporter',fumigation:'Fumigation',inspection:'Inspection',service:'Service Provider',shipping:'Shipping Line / Carrier'})[name] || '';
  function openEditor(input,kind) {
    const type = kind === 'buyer' ? 'export_customers' : 'business_parties';
    const name = clean(input.value);
    const row = (masters[type] || []).find(item => clean(item?.values?.[0]).toLowerCase() === name.toLowerCase());
    const canCreate = access.super || (access.masterPermissions?.[type] || []).includes('Create');
    const canEdit = access.super || (access.masterPermissions?.[type] || []).includes('Edit');
    if (!row && !canCreate) return;
    let dialog = document.getElementById('ttPartyInlineEditor');
    if (!dialog) {
      dialog = document.createElement('dialog'); dialog.id = 'ttPartyInlineEditor';
      dialog.style.cssText = 'border:1px solid #cbd9e1;border-radius:14px;padding:22px;width:min(450px,95vw);box-shadow:0 20px 70px #15263855';
      document.body.appendChild(dialog);
    }
    const values = [...(row?.values || [])];
    const label = kind === 'buyer' ? 'Export Customer' : roleFor(kind);
    dialog.replaceChildren();
    const form = document.createElement('form'); form.method = 'dialog';
    form.innerHTML = `<h3>${row ? 'Edit' : 'Add'} ${label}</h3><p>Saved in Super Admin ${type === 'export_customers' ? 'Export Customers' : 'Business Parties'}.</p>${row&&!canEdit?'<p>Changes to this name require Master Edit permission.</p>':`<label>Name<input name="partyName" required maxlength="180"></label>${type === 'export_customers' ? '<label>Document address<textarea name="address" required rows="3"></textarea></label>' : ''}`}<p class="tt-party-editor-error" role="alert"></p><div style="display:flex;justify-content:flex-end;gap:8px">${row?'<button type="button" data-request-removal>Request removal</button>':''}<button type="button" data-cancel>Cancel</button>${row&&!canEdit?'':'<button type="submit">Save</button>'}</div>`;
    if (form.elements.partyName) form.elements.partyName.value = values[0] || name;
    if (form.elements.address) form.elements.address.value = values[3] || '';
    form.querySelector('[data-cancel]').onclick = () => dialog.close();
    if(row)form.querySelector('[data-request-removal]').onclick = async () => {
      const reason=prompt('Why should Super Admin remove this name from future selections?');
      if(reason===null)return;
      try {
        const response=await fetch('../api/masters.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify({action:'request-deletion',type,id:row.id,reason:reason.trim(),csrf:access.csrf})});
        const result=await response.json();if(!response.ok||!result.ok)throw new Error(result.error||'Could not request removal.');
        dialog.close();alert('Deletion request sent to Super Admin. The name remains active until approved.');
      }catch(error){form.querySelector('.tt-party-editor-error').textContent=error.message||String(error);}
    };
    form.onsubmit = async event => {
      event.preventDefault();
      const newName = clean(form.elements.partyName.value);
      if (!newName) return;
      values[0] = newName;
      if (type === 'export_customers') { values[3] = clean(form.elements.address.value); values[10] = values[10] || 'Active'; }
      else { const roles = new Set(clean(values[2]).split(/[;,]/).filter(Boolean).map(value => value.trim())); roles.add(roleFor(kind)); values[2] = [...roles].join('; '); values[10] = values[10] || 'Active'; }
      const save = form.querySelector('[type="submit"]'); save.disabled = true;
      try {
        const response = await fetch('../api/masters.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify({action:row?'update':'create',type,id:row?.id || '',values,csrf:access.csrf})});
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.error || 'Could not save the master record.');
        window.TT_ACCOUNTS_MASTER_CHOICES.refresh(result.masters);
        input.value = newName; input.dispatchEvent(new Event('change',{bubbles:true})); dialog.close();
      } catch (error) { form.querySelector('.tt-party-editor-error').textContent = error.message || String(error); }
      finally { save.disabled = false; }
    };
    dialog.appendChild(form); dialog.showModal();
  }
  function category(input) {
    if (!input || input.closest('.tt-search-select') || input.matches('[readonly],[disabled],[type="date"],[type="number"],[type="file"]')) return '';
    const text = clean(input.closest('label')?.textContent + ' ' + input.placeholder + ' ' + input.id).toLowerCase();
    if (input.id === 'svVendor') return ({CLEARING:'clearing',FUMIGATION:'fumigation',INSPECTION:'inspection'})[document.getElementById('svKind')?.value] || 'service';
    if (/customer|buyer|consignee/.test(text)) return 'buyer';
    if (/bag supplier|supplier/.test(text)) return 'supplier';
    if (/broker/.test(text)) return 'broker';
    if (/forwarder/.test(text)) return 'freight';
    if (/clearing/.test(text)) return 'clearing';
    if (/fumigation/.test(text)) return 'fumigation';
    if (/inspection/.test(text)) return 'inspection';
    if (/transporter|transport vendor/.test(text)) return 'transporter';
    if (/shipping line/.test(text)) return 'shipping';
    if (/vendor|service provider/.test(text)) return 'service';
    if (/mill|location|warehouse|from where|to where/.test(text)) return 'locations';
    if (/commodity/.test(text)) return 'commodities';
    if (/product|variety|rice type/.test(text)) return 'products';
    if (/party|from whom|received from|account name/.test(text)) return 'parties';
    return '';
  }
  function refresh() {
    for (const [name,items] of Object.entries(lists())) {
      let list = document.getElementById('tt-master-' + name);
      if (!list) { list = document.createElement('datalist'); list.id = 'tt-master-' + name; document.body.appendChild(list); }
      if (JSON.stringify([...list.options].map(option => option.value)) !== JSON.stringify(items))
        list.replaceChildren(...items.map(value => { const option = document.createElement('option'); option.value = value; return option; }));
    }
    document.querySelectorAll('input').forEach(input => {
      const name = category(input);
      if (!name || (input.list && !input.list.id.startsWith('tt-master-'))) return;
      input.setAttribute('list','tt-master-' + name);
      input.setAttribute('autocomplete','off');
      const type = name === 'buyer' ? 'export_customers' : 'business_parties';
      if ((name === 'buyer' || roleFor(name)) && !input.dataset.ttMasterManage && !input.closest('#ttPartyInlineEditor')) {
        const button = document.createElement('button'); button.type = 'button'; button.className = 'btn tt-master-inline'; button.textContent = 'Add / Edit';
        button.title = `Manage ${name} in Super Admin masters`; button.style.cssText = 'margin:4px 0 0;padding:5px 8px;font-size:11px';
        button.onclick = event => { event.preventDefault(); openEditor(input,category(input)); };
        input.insertAdjacentElement('afterend',button); input.dataset.ttMasterManage = '1';
      }
    });
  }
  let queued = false;
  const observer = new MutationObserver(() => { if (queued) return; queued = true; requestAnimationFrame(() => { queued = false; refresh(); }); });
  const start = () => { refresh(); observer.observe(document.body,{childList:true,subtree:true}); };
  document.addEventListener('change',event => { if (event.target?.id === 'svKind') refresh(); });
  window.TT_ACCOUNTS_MASTER_CHOICES = { refresh: newMasters => { if (newMasters) { masters = newMasters; access.masters = newMasters; } refresh(); }, partyNames };
  document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded',start,{once:true}) : start();
})();
