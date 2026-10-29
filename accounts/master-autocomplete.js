(() => {
  'use strict';
  const access = window.TT_ACCOUNT_ACCESS || {};
  let masters = access.masters || {};
  const clean = value => String(value ?? '').trim();
  const identity=value=>clean(value).toLowerCase().replace(/[^\p{L}\p{N}]/gu,'');
  const unique = values => [...new Map(values.map(clean).filter(Boolean).map(name=>[identity(name),name])).values()].sort((a,b)=>a.localeCompare(b));
  const active = row => clean(row?.values?.[10] || 'Active').toLowerCase() !== 'inactive';
  const categories = row => clean(row?.values?.[2]).replace(/Shipping Line\s*\/\s*Carrier/ig,'Shipping Line and Carrier').split(/[;,/|]/).map(value => value.trim().toLowerCase().replace('shipping line and carrier','shipping line / carrier'));
  const scopeCache=new Map(),scopePending=new Set();
  const selectedCompany=()=>localStorage.getItem('tt_accounts_entity')||'TTI';
  function companyParty(row){let profile={};try{profile=JSON.parse(row?.values?.[12]||'{}');}catch(_){}const company=selectedCompany();if(Array.isArray(profile.accountingCompanies))return profile.accountingCompanies.includes(company);return company!=='TG'||scopeCache.get(company)?.has(String(row.id));}
  function loadCompanyScope(){const company=selectedCompany();if(company!=='TG'||scopeCache.has(company)||scopePending.has(company))return;scopePending.add(company);fetch('../api/accounts_party_choices.php?entity='+encodeURIComponent(company),{credentials:'same-origin',headers:{Accept:'application/json'}}).then(r=>r.json()).then(data=>{if(data.ok&&data.entity===company)scopeCache.set(company,new Set(data.businessPartyIds.map(String)));}).catch(()=>{}).finally(()=>scopePending.delete(company));}
  const partyNames = (...roles) => unique((masters.business_parties || []).filter(row => active(row) && companyParty(row) && roles.some(role => categories(row).includes(role.toLowerCase()))).map(row => row.values[0]));
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
    parties:unique([...(masters.business_parties || []).filter(row=>active(row)&&companyParty(row)).map(row => row.values[0]),...customerNames()]),
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
    const partyRoles=['Supplier','Bag Supplier','Labour Contractor','Broker','Indentor','Clearing Agent','Freight Forwarder','Shipping Line / Carrier','Transporter','Inspection','Fumigation','Service Provider','Local Buyer','Fellow Exporter','Agent','Other'];
    const chosenRoles=categories(row);
    const typeFields=kind==='party'&&!row?'<label>Create as<select name="newPartyType"><option value="party">Business Party</option><option value="buyer">Export Customer</option></select></label>':'';
    const roleFields=kind==='party'&&(canEdit||!row)?'<fieldset class="tt-party-roles"><legend>Party categories</legend>'+partyRoles.map(role=>'<label class="tt-party-role"><input type="checkbox" name="partyRole" value="'+role+'" '+(chosenRoles.includes(role.toLowerCase())?'checked':'')+'> '+role+'</label>').join('')+'</fieldset>':'';
    let serviceProfile={};try{serviceProfile=JSON.parse(values[12]||'{}').service||{};}catch(_){}
    const serviceFields=type==='business_parties'?'<fieldset data-service-accounting hidden><legend>Service provider accounting</legend><label>Service / profession<input name="serviceType" maxlength="120" data-master-ignore></label><label>Default expense head<select name="serviceExpense"><option value="">Choose expense head</option></select></label><p>New expenses use this head. Existing invoices are paid against their outstanding balance.</p></fieldset>':'';
    const currentCompany=window.TT_BANK_WORKSPACE?.entity?.()||localStorage.getItem('tt_accounts_entity')||'TTI';
    let companyProfile={};try{companyProfile=JSON.parse(values[12]||'{}');}catch(_){}
    const companyChoices=companyProfile.accountingCompanies||(row?['TTI','BRM',...(currentCompany==='TG'?['TG']:[])]:[currentCompany]);
    const companyFields=type==='business_parties'&&(canEdit||!row)?'<fieldset><legend>Available in Accounts</legend>'+['TTI','BRM','TG'].map(code=>'<label style="display:inline-flex;align-items:center;gap:6px;margin-right:14px"><input type="checkbox" name="accountingCompany" value="'+code+'" '+(companyChoices.includes(code)?'checked':'')+'> '+code+'</label>').join('')+'</fieldset>':'';
    dialog.replaceChildren();
    const form = document.createElement('form'); form.method = 'dialog';
    form.innerHTML = `<h3>${row ? 'Edit' : 'Add'} ${label}</h3><p>Saved in Super Admin ${type === 'export_customers' ? 'Export Customers' : 'Business Parties'}.</p>${row&&!canEdit?'<p>Changes to this name require Master Edit permission.</p>':`<label>Name<input name="partyName" required maxlength="180"></label>${type === 'export_customers' ? '<label>Document address<textarea name="address" required rows="3"></textarea></label>' : ''}`}${typeFields}${roleFields}${companyFields}${serviceFields}<p class="tt-party-editor-error" role="alert"></p><div style="display:flex;justify-content:flex-end;gap:8px">${row?((access.super||(access.masterPermissions?.[type]||[]).includes('Deactivate'))?'<button type="button" data-delete>Delete</button>':'<button type="button" data-request-removal>Request removal</button>'):''}<button type="button" data-cancel>Cancel</button>${row&&!canEdit?'':'<button type="submit">Save</button>'}</div>`;
    if(form.elements.serviceExpense){
      form.elements.serviceType.value=serviceProfile.type||'';
      const syncService=()=>{const selected=kind==='service'||(kind==='party'?Array.from(form.querySelectorAll('[name=partyRole]:checked')).some(i=>i.value==='Service Provider'):chosenRoles.includes('service provider'));form.querySelector('[data-service-accounting]').hidden=!selected;form.elements.serviceExpense.required=selected;};
      form.querySelectorAll('[name=partyRole]').forEach(i=>i.addEventListener('change',syncService));syncService();
      fetch('../api/masters.php',{credentials:'same-origin',headers:{Accept:'application/json'}}).then(r=>r.json()).then(result=>{if(!result.ok)throw Error(result.error);form.elements.serviceExpense.replaceChildren(new Option('Choose expense head',''));for(const a of result.options?.service_expense_heads||[])form.elements.serviceExpense.add(new Option(a.code+' · '+a.name,a.code));form.elements.serviceExpense.value=serviceProfile.expenseAccountCode||'';}).catch(()=>{form.querySelector('.tt-party-editor-error').textContent='Expense heads could not be loaded. Reopen this form.';});
    }
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
      if(type==='business_parties'&&form.elements.serviceExpense){let profile={};try{profile=JSON.parse(values[12]||'{}');}catch(_){}const service=values[2].split('; ').includes('Service Provider');profile.service={type:clean(form.elements.serviceType.value),expenseAccountCode:service?form.elements.serviceExpense.value:''};values[12]=JSON.stringify(profile);}
      if(type==='business_parties'&&form.querySelector('[name=accountingCompany]')){let profile={};try{profile=JSON.parse(values[12]||'{}');}catch(_){}profile.accountingCompanies=Array.from(form.querySelectorAll('[name=accountingCompany]:checked')).map(i=>i.value);if(!profile.accountingCompanies.length){form.querySelector('.tt-party-editor-error').textContent='Choose at least one company.';return;}values[12]=JSON.stringify(profile);}
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
    dialog.appendChild(form); dialog.showModal();window.TT_FORM_VIEWPORT?.open(dialog);
    return true;
  }
  function category(input) {
    // Questions and mode choices retain their native controls; they are never party fields.
    if(input?.matches?.('[type="radio"],[type="checkbox"],[type="hidden"]'))return '';
    if (input?.dataset.ttNumericProxy || input?.hasAttribute('data-master-ignore') || input?.matches('[inputmode=decimal],[inputmode=numeric],#subName,[name=accountName],[name=subaccountName]')) return '';
    if (input?.dataset.masterRole) return input.dataset.masterRole==='party'?'parties':input.dataset.masterRole;
    if (!input || input.dataset.ttNumericProxy || input.closest('.tt-search-select') || input.matches('[readonly],[disabled],[type="date"],[type="number"],[type="file"]')) return '';
    if (input.dataset.masterRole) return input.dataset.masterRole==='party'?'parties':input.dataset.masterRole;
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
    // Keep the input focused until selection: native change-on-blur can rebuild the form before click.
    menu.addEventListener('mousedown',event=>{if(event.target.closest('button'))event.preventDefault();});
    const kind=()=>input.id==='svVendor'?({CLEARING:'clearing',FUMIGATION:'fumigation',INSPECTION:'inspection'})[document.getElementById('svKind')?.value]||'service':initialKind;
    const type=()=>kind()==='buyer'?'export_customers':'business_parties';
    const can=action=>custom?(action==='Create'?custom.canAdd?.()??true:custom.canEdit?.()??true):access.super||(access.masterPermissions?.[type()]||[]).includes(action);
    const label=()=>custom?.label||roleFor(kind())||(kind()==='buyer'?'Customer':'Business Party');
    let activeIndex=-1;const close=()=>{menu.hidden=true;input.setAttribute('aria-expanded','false');input.removeAttribute('aria-activedescendant')};
    const render=all=>{const portal=input.closest('dialog')||document.body;if(menu.parentElement!==portal)portal.append(menu);document.querySelectorAll('.tt-select-menu').forEach(other=>{if(other!==menu)other.hidden=true});menu.replaceChildren();activeIndex=-1;input.removeAttribute('aria-activedescendant');const items=custom?custom.values():lists()[kind()]||[],term=all?'':clean(input.value).toLowerCase();
      items.filter(name=>clean(custom?.searchText?.(name)??name).toLowerCase().includes(term)||(!custom&&(masters[type()]||[]).some(row=>identity(row.values?.[0])===identity(name)&&clean(row.values?.[1]).toLowerCase().includes(term)))).slice(0,100).forEach(name=>{const line=document.createElement('div');line.className='tt-option-row';const choose=document.createElement('button');choose.type='button';choose.id='tt-party-choice-'+Math.random().toString(36).slice(2);choose.textContent=name;choose.onclick=()=>{input.value=name;close();input.dispatchEvent(new Event('change',{bubbles:true}));};line.append(choose);if(can('Edit')){const edit=document.createElement('button');edit.type='button';edit.className='tt-option-edit';edit.textContent='✎';edit.title='Edit '+name;edit.setAttribute('aria-label',edit.title);edit.onclick=()=>{close();if(custom){custom.edit?.(name);return;}openEditor({value:name,dispatchEvent(){input.value=this.value;input.dispatchEvent(new Event('change',{bubbles:true}))}},kind()==='parties'?'party':kind());};line.append(edit)}menu.append(line)});
      if(can('Create')){const add=document.createElement('button');add.type='button';add.className='tt-option-add';add.textContent='+ Add '+label();add.onclick=()=>{close();if(custom){custom.add?.();return;}openEditor({value:clean(input.value),dispatchEvent(){input.value=this.value;input.dispatchEvent(new Event('change',{bubbles:true}))}},kind()==='parties'?'party':kind());};menu.append(add);}
      if(!menu.children.length){const empty=document.createElement('div');empty.className='tt-select-empty';empty.textContent='No matching '+label();menu.append(empty)}const rect=input.getBoundingClientRect();menu.style.left=Math.max(8,Math.min(rect.left,innerWidth-rect.width-8))+'px';menu.style.width=rect.width+'px';const below=innerHeight-rect.bottom-12,above=rect.top-12,height=Math.min(220,Math.max(below,above));menu.style.maxHeight=height+'px';menu.style.top=(below>=Math.min(120,height)?rect.bottom+4:Math.max(8,rect.top-height-4))+'px';menu.hidden=false;input.setAttribute('aria-expanded','true');};
    const isPayee=()=>/pay to|paid to|payee|who to pay|recipient|beneficiary/i.test([custom?.label,input.id,input.name,input.closest('label')?.textContent].join(' '));
    const resolveUnique=(notify=true)=>{if(!isPayee())return false;const term=clean(input.value).toLowerCase();if(!term)return false;const items=custom?custom.values():lists()[kind()]||[],matches=items.filter(name=>clean(custom?.searchText?.(name)??name).toLowerCase().includes(term)||(!custom&&(masters[type()]||[]).some(row=>identity(row.values?.[0])===identity(name)&&clean(row.values?.[1]).toLowerCase().includes(term)))),exact=items.filter(name=>clean(name).toLowerCase()===term);const candidates=exact.length?exact:(term.length?matches:[]);if(candidates.length!==1)return false;const name=candidates[0];if(input.value===name)return true;input.value=name;close();if(notify)input.dispatchEvent(new Event('change',{bubbles:true}));return true;};
    input._ttResolveUnique=resolveUnique;input.addEventListener('change',()=>resolveUnique(false),true);input.addEventListener('blur',()=>resolveUnique());
    input.addEventListener('focus',()=>render(true));input.addEventListener('click',()=>{if(menu.hidden)render(true)});input.addEventListener('input',()=>render(false));input.addEventListener('keydown',event=>{if(event.key==='Escape'&&!menu.hidden){event.preventDefault();event.stopPropagation();close();return;}if(['ArrowDown','ArrowUp'].includes(event.key)){event.preventDefault();event.stopPropagation();if(menu.hidden)render(true);const choices=[...menu.querySelectorAll('.tt-option-row>button:first-child')];if(choices.length){activeIndex=activeIndex<0?(event.key==='ArrowDown'?0:choices.length-1):(activeIndex+(event.key==='ArrowDown'?1:-1)+choices.length)%choices.length;choices.forEach((b,i)=>{b.classList.toggle('tt-keyboard-active',i===activeIndex);b.setAttribute('aria-current',String(i===activeIndex));});const active=choices[activeIndex];input.setAttribute('aria-activedescendant',active.id);active.scrollIntoView({block:'nearest'});}return;}if(event.key==='Enter'&&!menu.hidden){event.preventDefault();event.stopPropagation();const choices=menu.querySelectorAll('.tt-option-row>button:first-child');(choices[activeIndex]||choices[0])?.click()}});input.addEventListener('blur',()=>setTimeout(()=>{if(!menu.contains(document.activeElement))close()},150));
  }
  function refresh() {
    loadCompanyScope();
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
  const start = () => { loadCompanyScope(); refresh(); observer.observe(document.body,{childList:true,subtree:true}); };
  document.addEventListener('change',event => { if (event.target?.id === 'svKind') refresh(); });
  window.TT_ACCOUNTS_MASTER_CHOICES = {
    refresh: newMasters => { if (newMasters) { masters = newMasters; access.masters = newMasters; } refresh(); },
    partyNames, customerNames,resolveInput:input=>input?._ttResolveUnique?.(),
    namesForRole: kind => lists()[kind] || [],
    manageRoleInput(input,kind,extraValues=()=>[]){
      if(!input||input.dataset.ttMasterManage)return;
      input.dataset.masterRole=kind;
      this.manageInput(input,{
        values:()=>unique([...(lists()[kind]||[]),...extraValues()]),
        label:roleFor(kind)||'Business Party',
        canAdd:()=>access.super||(access.masterPermissions?.business_parties||[]).includes('Create'),
        canEdit:()=>access.super||(access.masterPermissions?.business_parties||[]).includes('Edit'),
        add:()=>this.manageRole(kind==='parties'?'party':kind,input.value,name=>{input.value=name;input.dispatchEvent(new Event('change',{bubbles:true}));}),
        edit:name=>this.manageRole(kind==='parties'?'party':kind,name,saved=>{input.value=saved;input.dispatchEvent(new Event('change',{bubbles:true}));})
      });
    },
    manageRole: (kind,name,onSaved) => openEditor({value:clean(name),dispatchEvent(){onSaved?.(this.value)}},kind),
    attachPartyMenu,
    manageInput(input,config){if(input&&!input.dataset.ttMasterManage){input.dataset.ttMasterManage='1';attachPartyMenu(input,'parties',config);}},
    manageServiceProvider: (name,onSaved) => openEditor({value:clean(name),dispatchEvent(){onSaved?.(this.value)}},'service'),
    manageParty: (name,onSaved) => openEditor({value:clean(name),dispatchEvent(){onSaved?.(this.value)}},'party'),
    manageCustomer: (name,onSaved) => openEditor({value:clean(name),dispatchEvent(){onSaved?.(this.value)}},'buyer')
  };
  document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded',start,{once:true}) : start();
})();


