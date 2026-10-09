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
    buyer:customerNames(), supplier:partyNames('Supplier'), bagsupplier:partyNames('Bag Supplier'), labourcontractor:partyNames('Labour Contractor'), broker:partyNames('Broker'),
    forwarder:partyNames('Freight Forwarder'), freight:partyNames('Freight Forwarder','Shipping Line / Carrier'), clearing:partyNames('Clearing Agent'),
    transporter:partyNames('Transporter'), fumigation:partyNames('Fumigation'), inspection:partyNames('Inspection'),
    service:partyNames('Service Provider'), shipping:partyNames('Shipping Line / Carrier'),
    parties:unique([...(masters.business_parties || []).filter(active).map(row => row.values[0]),...customerNames()]),
    locations:unique((masters.mills || []).filter(row => clean(row?.values?.[5] || 'Active').toLowerCase() === 'active').map(row => row.values[0])),
    products:productNames(), commodities:unique((masters.commodities || []).map(row => row?.values?.[0]))
  });
  const roleFor = name => ({bagsupplier:'Bag Supplier',labourcontractor:'Labour Contractor',supplier:'Supplier',broker:'Broker',forwarder:'Freight Forwarder',freight:'Freight Forwarder',clearing:'Clearing Agent',transporter:'Transporter',fumigation:'Fumigation',inspection:'Inspection',service:'Service Provider',shipping:'Shipping Line / Carrier'})[name] || '';
  function openEditor(input,kind) {
    const type = kind === 'buyer' ? 'export_customers' : 'business_parties';
    const name = clean(input.value);
    const row = (masters[type] || []).find(item => clean(item?.values?.[0]).toLowerCase() === name.toLowerCase());
    const canCreate = access.super || (access.masterPermissions?.[type] || []).includes('Create');
    const canEdit = access.super || (access.masterPermissions?.[type] || []).includes('Edit');
    if (!row && !canCreate) { alert('Adding '+(roleFor(kind)||'Customer')+' requires Business Parties Create permission. Ask Super Admin to add this party.'); return false; }
    let dialog = document.getElementById('ttPartyInlineEditor');
    if (!dialog) {
      dialog = document.createElement('dialog'); dialog.id = 'ttPartyInlineEditor';
      dialog.style.cssText = 'border:1px solid #cbd9e1;border-radius:14px;padding:22px;width:min(450px,95vw);box-shadow:0 20px 70px #15263855';
      document.body.appendChild(dialog);
    }
    const values = [...(row?.values || [])];
    const label = kind === 'buyer' ? 'Export Customer' : (roleFor(kind)||'Business Party');
    const partyRoles=['Supplier','Bag Supplier','Labour Contractor','Broker','Indentor','Clearing Agent','Freight Forwarder','Shipping Line / Carrier','Transporter','Inspection','Fumigation','Service Provider','Local Buyer','Agent','Other'];
    const chosenRoles=categories(row);
    const typeFields=kind==='party'&&!row?'<label>Create as<select name="newPartyType"><option value="party">Business Party</option><option value="buyer">Export Customer</option></select></label>':'';
    const roleFields=kind==='party'&&(canEdit||!row)?'<fieldset><legend>Party categories</legend>'+partyRoles.map(role=>'<label style="display:block"><input type="checkbox" name="partyRole" value="'+role+'" '+(chosenRoles.includes(role.toLowerCase())?'checked':'')+'> '+role+'</label>').join('')+'</fieldset>':'';
    dialog.replaceChildren();
    const form = document.createElement('form'); form.method = 'dialog';
    form.innerHTML = `<h3>${row ? 'Edit' : 'Add'} ${label}</h3><p>Saved in Super Admin ${type === 'export_customers' ? 'Export Customers' : 'Business Parties'}.</p>${row&&!canEdit?'<p>Changes to this name require Master Edit permission.</p>':`<label>Name<input name="partyName" required maxlength="180"></label>${type === 'export_customers' ? '<label>Document address<textarea name="address" required rows="3"></textarea></label>' : ''}`}${typeFields}${roleFields}<p class="tt-party-editor-error" role="alert"></p><div style="display:flex;justify-content:flex-end;gap:8px">${row?((access.super||(access.masterPermissions?.[type]||[]).includes('Deactivate'))?'<button type="button" data-delete>Delete</button>':'<button type="button" data-request-removal>Request removal</button>'):''}<button type="button" data-cancel>Cancel</button>${row&&!canEdit?'':'<button type="submit">Save</button>'}</div>`;
    if(form.elements.newPartyType)form.elements.newPartyType.onchange=()=>{if(form.elements.newPartyType.value==='buyer'){input.value=clean(form.elements.partyName.value);openEditor(input,'buyer')}};
    if (form.elements.partyName) form.elements.partyName.value = values[0] || name;
    if (form.elements.address) form.elements.address.value = values[3] || '';
    form.querySelector('[data-cancel]').onclick = () => dialog.close();
    if(row&&form.querySelector('[data-request-removal]'))form.querySelector('[data-request-removal]').onclick = async () => {
      const reason=prompt('Why should Super Admin remove this name from future selections?');
      if(reason===null)return;
      try {
        const response=await fetch('../api/masters.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify({action:'request-deletion',type,id:row.id,reason:reason.trim(),csrf:access.csrf})});
        const result=await response.json();if(!response.ok||!result.ok)throw new Error(result.error||'Could not request removal.');
        dialog.close();alert('Deletion request sent to Super Admin. The name remains active until approved.');
      }catch(error){form.querySelector('.tt-party-editor-error').textContent=error.message||String(error);}
    };
    const remove=form.querySelector('[data-delete]');
    if(remove)remove.onclick=async()=>{
      const reason=prompt('Reason for removing this recipient from future selections? Past postings will remain.');if(reason===null)return;remove.disabled=true;
      try{const response=await fetch('../api/masters.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify({action:'delete',type,id:row.id,reason,csrf:access.csrf})});const result=await response.json();if(!response.ok||!result.ok)throw Error(result.error||'Could not delete recipient.');window.TT_ACCOUNTS_MASTER_CHOICES.refresh(result.masters);input.value='';input.dispatchEvent(new Event('change',{bubbles:true}));dialog.close();}catch(e){form.querySelector('.tt-party-editor-error').textContent=e.message;remove.disabled=false;}
    };
    form.onsubmit = async event => {
      event.preventDefault();
      const newName = clean(form.elements.partyName.value);
      if (!newName) return;
      values[0] = newName;
      if (type === 'export_customers') { values[3] = clean(form.elements.address.value); values[10] = values[10] || 'Active'; }
      else { const roles = new Set(clean(values[2]).split(/[;,]/).filter(Boolean).map(value => value.trim())); if(kind==='party'){roles.clear();form.querySelectorAll('[name=partyRole]:checked').forEach(input=>roles.add(input.value));if(!roles.size){form.querySelector('.tt-party-editor-error').textContent='Select at least one category.';return}}else if(roleFor(kind))roles.add(roleFor(kind)); values[2] = [...roles].join('; '); values[10] = values[10] || 'Active'; }
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
    return true;
  }
  function category(input) {
    if (input?.hasAttribute('data-master-ignore')) return '';
    if (input?.dataset.masterRole) return input.dataset.masterRole;
    if (!input || input.dataset.ttNumericProxy || input.closest('.tt-search-select') || input.matches('[readonly],[disabled],[type="date"],[type="number"],[type="file"]')) return '';
    if (input.dataset.masterRole) return input.dataset.masterRole;
    const text = clean(input.closest('label')?.textContent + ' ' + input.placeholder + ' ' + input.id).toLowerCase();
    // Document identifiers and charge descriptions are not party selectors.
    if (/invoice|bill number|bill no|reference|description|remarks|notes/.test(text)) return '';
    if (input.id === 'svVendor') return ({CLEARING:'clearing',FUMIGATION:'fumigation',INSPECTION:'inspection'})[document.getElementById('svKind')?.value] || 'service';
    if (/customer|buyer|consignee/.test(text)) return 'buyer';
    if (/bag supplier/.test(text)) return 'bagsupplier';
    if (/labour contractor/.test(text)) return 'labourcontractor';
    if (/supplier/.test(text)) return 'supplier';
    if (/broker/.test(text)) return 'broker';
    if (/forwarder/.test(text)) return 'freight';
    if (/clearing/.test(text)) return 'clearing';
    if (/fumigation/.test(text)) return 'fumigation';
    if (/inspection/.test(text)) return 'inspection';
    if (/transporter|transport vendor/.test(text)) return 'transporter';
    if (/shipping line/.test(text)) return 'shipping';
    if (/provider|utility|vendor|service provider/.test(text)) return 'service';
    if (/mill|location|warehouse|from where|to where/.test(text)) return 'locations';
    if (/commodity/.test(text)) return 'commodities';
    if (/product|variety|rice type/.test(text)) return 'products';
    if (/party|from whom|received from|account name|paid to|payee|beneficiary/.test(text)) return 'parties';
    return '';
  }
  function attachPartyMenu(input,initialKind,custom=null){
    const menu=document.createElement('div');menu.className='tt-select-menu tt-party-menu';menu.hidden=true;menu._ttOwner=input;document.body.append(menu);input.removeAttribute('list');input.setAttribute('role','combobox');input.setAttribute('aria-expanded','false');
    const kind=()=>input.id==='svVendor'?({CLEARING:'clearing',FUMIGATION:'fumigation',INSPECTION:'inspection'})[document.getElementById('svKind')?.value]||'service':initialKind;
    const type=()=>kind()==='buyer'?'export_customers':'business_parties';
    const can=action=>custom?(action==='Create'?custom.canAdd?.()??true:custom.canEdit?.()??true):access.super||(access.masterPermissions?.[type()]||[]).includes(action);
    const label=()=>custom?.label||roleFor(kind())||(kind()==='buyer'?'Customer':'Business Party');
    const close=()=>{menu.hidden=true;input.setAttribute('aria-expanded','false')};
    const render=all=>{document.querySelectorAll('.tt-select-menu').forEach(other=>{if(other!==menu)other.hidden=true});menu.replaceChildren();const items=custom?custom.values():lists()[kind()]||[],term=all?'':clean(input.value).toLowerCase();
      items.filter(name=>name.toLowerCase().includes(term)).slice(0,100).forEach(name=>{const line=document.createElement('div');line.className='tt-option-row';const choose=document.createElement('button');choose.type='button';choose.textContent=name;choose.onclick=()=>{input.value=name;close();input.dispatchEvent(new Event('change',{bubbles:true}));};line.append(choose);if(can('Edit')){const edit=document.createElement('button');edit.type='button';edit.className='tt-option-edit';edit.textContent='✎';edit.title='Edit '+name;edit.setAttribute('aria-label',edit.title);edit.onclick=()=>{close();if(custom){custom.edit?.(name);return;}openEditor({value:name,dispatchEvent(){input.value=this.value;input.dispatchEvent(new Event('change',{bubbles:true}))}},kind()==='parties'?'party':kind());};line.append(edit)}menu.append(line)});
      if(can('Create')){const add=document.createElement('button');add.type='button';add.className='tt-option-add';add.textContent='+ Add '+label();add.onclick=()=>{close();if(custom){custom.add?.();return;}openEditor({value:clean(input.value),dispatchEvent(){input.value=this.value;input.dispatchEvent(new Event('change',{bubbles:true}))}},kind()==='parties'?'party':kind());};menu.append(add);}
      if(!menu.children.length){const empty=document.createElement('div');empty.className='tt-select-empty';empty.textContent='No matching '+label();menu.append(empty)}const rect=input.getBoundingClientRect();menu.style.left=Math.max(8,Math.min(rect.left,innerWidth-rect.width-8))+'px';menu.style.width=rect.width+'px';const below=innerHeight-rect.bottom-12,above=rect.top-12,height=Math.min(220,Math.max(below,above));menu.style.maxHeight=height+'px';menu.style.top=(below>=Math.min(120,height)?rect.bottom+4:Math.max(8,rect.top-height-4))+'px';menu.hidden=false;input.setAttribute('aria-expanded','true');};
    input.addEventListener('focus',()=>render(true));input.addEventListener('click',()=>{if(menu.hidden)render(true)});input.addEventListener('input',()=>render(false));input.addEventListener('keydown',event=>{if(event.key==='Escape')close();if(event.key==='Enter'&&!menu.hidden){event.preventDefault();menu.querySelector('.tt-option-row button')?.click()}});input.addEventListener('blur',()=>setTimeout(()=>{if(!menu.contains(document.activeElement))close()},150));
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
      if (!name || (input.list && !input.list.id.startsWith('tt-master-') && !input.dataset.masterRole)) return;
      // Reassigning even the same list dismisses Chromium's open suggestions.
      // Background totals and other DOM updates must leave the field alone.
      const listId = 'tt-master-' + name;
      if (!input.dataset.ttMasterManage && input.getAttribute('list') !== listId) input.setAttribute('list',listId);
      if (input.getAttribute('autocomplete') !== 'off') input.setAttribute('autocomplete','off');
      const type = name === 'buyer' ? 'export_customers' : 'business_parties';
      if ((name === 'buyer' || name === 'parties' || roleFor(name)) && !input.dataset.ttMasterManage && !input.closest('#ttPartyInlineEditor')) {
        input.dataset.masterRole=name;input.dataset.ttMasterManage='1';attachPartyMenu(input,name);
      }
    });
  }
  let queued = false;
  const observer = new MutationObserver(records => { if(!records.some(r=>[...r.addedNodes].some(n=>n.nodeType===1&&(n.matches?.('input,select')||n.querySelector?.('input,select')))))return; if (queued) return; queued = true; requestAnimationFrame(() => { queued = false; refresh(); }); });
  const start = () => { refresh(); observer.observe(document.body,{childList:true,subtree:true}); };
  document.addEventListener('change',event => { if (event.target?.id === 'svKind') refresh(); });
  window.TT_ACCOUNTS_MASTER_CHOICES = {
    refresh: newMasters => { if (newMasters) { masters = newMasters; access.masters = newMasters; } refresh(); },
    partyNames, customerNames,
    manageRole: (kind,name,onSaved) => openEditor({value:clean(name),dispatchEvent(){onSaved?.(this.value)}},kind),
    attachPartyMenu,
    manageInput(input,config){if(input&&!input.dataset.ttMasterManage){input.dataset.ttMasterManage='1';attachPartyMenu(input,'parties',config);}},
    manageParty: (name,onSaved) => openEditor({value:clean(name),dispatchEvent(){onSaved?.(this.value)}},'party'),
    manageCustomer: (name,onSaved) => openEditor({value:clean(name),dispatchEvent(){onSaved?.(this.value)}},'buyer')
  };
  document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded',start,{once:true}) : start();
})();


