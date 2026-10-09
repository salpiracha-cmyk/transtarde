(() => {
  'use strict';

  if (window.TT_ACCOUNTS_CLEAN_UI?.installed) return;

  const q = (selector, root = document) => root.querySelector(selector);
  const qa = (selector, root = document) => [...root.querySelectorAll(selector)];
  const sleep = ms => new Promise(resolve => window.setTimeout(resolve, ms));
  const nativeCards = new Map();
  let groupDialog = null;
  let masterMode = '';
  let activeSearchMenu = null;
  let activeGroup = null;
  let navigationMode = '';

  function installStyle() {
    if (q('#ttCleanAccountsStyle')) return;
    const style = document.createElement('style');
    style.id = 'ttCleanAccountsStyle';
    style.textContent = `
      #entityHome>.notice,#entityHome>article.panel,.footerNote{display:none!important}
      #entityHome .entityHero{margin:0 0 18px!important}
      #entityHome .entityHero h1{font-size:24px!important}
      #homeGrid.tt-clean-grid{grid-template-columns:repeat(4,minmax(130px,1fr))!important;gap:14px!important;max-width:980px;margin:0 auto}
      #homeGrid.tt-clean-grid .tt-clean-card{min-height:132px!important;padding:18px 12px!important;border:0!important;border-radius:18px!important;background:#fff!important;box-shadow:0 7px 24px rgba(19,40,65,.08)!important}
      #homeGrid.tt-clean-grid .tt-clean-card .appIcon{width:52px;height:52px;margin:0 0 12px!important;border-radius:15px;background:#edf3f8;font-size:24px}
      #homeGrid.tt-clean-grid .tt-clean-card h3{font-size:14px!important}
      #ttNativeLaunchers{display:none!important}
      #ttvAttention.ttv-attn{max-width:980px;margin:0 auto 14px!important;padding:8px 12px!important;border:0!important;background:#fff!important;box-shadow:0 4px 16px rgba(19,40,65,.06)}
      #ttvAttention .ttv-alert{display:none}
      body.tt-modal-open{overflow:hidden}
      body.tt-modal-open:before{content:"";position:fixed;inset:0;background:rgba(10,25,43,.46);z-index:180}
      .workspace.active.tt-clean-modal{display:block!important;position:fixed!important;z-index:190;top:4vh;left:50%;transform:translateX(-50%);width:min(1180px,94vw);max-height:92vh;overflow:auto;background:#f7f9fb;border-radius:18px;box-shadow:0 28px 80px rgba(0,0,0,.3);margin:0!important;padding:0 0 18px!important}
      .workspace.tt-clean-modal>.panelHead{position:sticky;top:0;z-index:12;background:#fff;padding:17px 70px 14px 20px!important;border-radius:18px 18px 0 0}
      .workspace.tt-clean-modal>.panelHead h2{margin:0!important;font-size:19px}
      .workspace.tt-clean-modal>.panelHead p{display:none}
      .workspace.tt-clean-modal>.panelHead [data-back]{position:absolute;right:15px;top:14px;padding:7px 10px;font-size:12px;color:#8f2d28}
      .workspace.tt-clean-modal>.subGrid{grid-template-columns:repeat(4,minmax(140px,1fr));gap:10px;padding:15px}
      .workspace.tt-clean-modal .subCard{padding:13px;min-height:104px;background:#fff}
      .workspace.tt-clean-modal .subCard p{font-size:11px}
      .workspace.tt-clean-modal .split{display:block!important;padding:14px!important}
      .workspace.tt-clean-modal .infoCard,.workspace.tt-clean-modal .accountPreview{display:block!important}
      .workspace.tt-clean-modal .formCard,.workspace.tt-clean-modal .ttv-form,.workspace.tt-clean-modal .ttrs-box{box-shadow:none!important;border-color:#e2e8ee!important}
      .workspace.tt-clean-modal .grid2,.workspace.tt-clean-modal .grid3,.workspace.tt-clean-modal .ttv-grid,.workspace.tt-clean-modal .ttrs-grid{grid-template-columns:repeat(3,minmax(150px,1fr))!important;gap:10px!important}
      .workspace.tt-clean-modal textarea{min-height:48px}
      .workspace.tt-clean-modal input,.workspace.tt-clean-modal select,.workspace.tt-clean-modal textarea{padding:8px 9px}
      .workspace.tt-clean-modal .tt-editor-bar{position:sticky;top:0;z-index:14;background:#fff;padding:14px 72px 12px 18px!important;border-radius:18px 18px 0 0}
      .workspace.tt-clean-modal .tt-editor-bar .tt-clean-close{position:absolute;right:15px;top:12px;color:#8f2d28}
      #ttQuickDialog{position:fixed;inset:0;z-index:220;display:grid;place-items:center;padding:18px;background:rgba(10,25,43,.46)}
      #ttQuickDialog[hidden]{display:none}
      .tt-quick-window{width:min(760px,94vw);max-height:88vh;overflow:auto;background:#f7f9fb;border-radius:18px;box-shadow:0 28px 80px rgba(0,0,0,.3)}
      .tt-quick-head{display:flex;align-items:center;gap:12px;padding:16px 18px;background:#fff;border-bottom:1px solid #e4e9ee;position:sticky;top:0;z-index:2}
      .tt-quick-head h2{margin:0;font-size:20px}.tt-quick-head span{flex:1}.tt-cancel{border:1px solid #d8e0e7;background:#fff;color:#173c63;border-radius:9px;padding:8px 11px;font-weight:800;cursor:pointer}
      .tt-quick-items{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:11px;padding:16px}
      .tt-quick-item{border:1px solid #dfe6ec;background:#fff;border-radius:14px;padding:15px;text-align:left;cursor:pointer;min-height:115px}
      .tt-quick-item:hover{border-color:#173c63;box-shadow:0 6px 18px rgba(19,40,65,.09)}
      .tt-quick-item b{display:block;font-size:14px;margin:9px 0 4px}.tt-quick-item small{color:#6f7a89;line-height:1.35}.tt-quick-icon{font-size:23px}
      .tt-search-select{position:relative;margin-top:5px;width:100%;min-width:0}.tt-search-select>input{display:block;width:100%!important;min-width:0;margin:0!important;padding-right:30px!important}.tt-search-select:after{content:"⌄";position:absolute;right:10px;top:8px;color:#687686;pointer-events:none}.tt-native-select{position:absolute!important;opacity:0!important;pointer-events:none!important;width:1px!important;height:1px!important;margin:0!important}.tt-select-menu{position:fixed;z-index:100400;max-height:220px;overflow:auto;background:#fff;border:1px solid #cdd7e0;border-radius:9px;box-shadow:0 12px 28px rgba(17,37,59,.18)}.tt-select-menu[hidden]{display:none!important}
      .tt-select-menu button{display:block;width:100%;border:0;background:#fff;padding:9px 10px;text-align:left;cursor:pointer}.tt-select-menu button:hover,.tt-select-menu button:focus{background:#edf3f8}.tt-select-empty{padding:9px;color:#6f7a89;font-size:11px}
      .tt-optional-details{grid-column:1/-1;border:1px solid #e2e8ee;border-radius:10px;padding:9px 11px;background:#fafbfd}.tt-optional-details summary{cursor:pointer;font-weight:800;color:#42566a}.tt-optional-grid{display:grid;grid-template-columns:repeat(3,minmax(150px,1fr));gap:10px;margin-top:10px}
      .tt-master-only .ttrs-head,.tt-master-only .ttrs-pay,.tt-master-only .ttrs-box:has(table tbody [data-rs-salpay]),.tt-master-only .ttrs-box:has(table tbody [data-rs-rentdue]){display:none!important}
      .tt-entry-only .ttrs-head{display:flex!important}
      .tt-clean-hidden{display:none!important}.tt-master-add{margin:10px 0 12px}.tt-salary-batch{border:1px solid #dfe6ec;border-radius:13px;background:#fff;padding:14px;margin:12px 0}.tt-salary-batch h3{margin:0 0 5px}.tt-salary-batch .tt-batch-grid{display:grid;grid-template-columns:1fr 1.4fr 1fr auto;gap:10px;align-items:end;margin-top:10px}.tt-salary-batch .tt-batch-total{font-size:18px;font-weight:900;padding:8px 0}
      @media(max-width:800px){#homeGrid.tt-clean-grid{grid-template-columns:repeat(2,1fr)!important}.tt-quick-items{grid-template-columns:1fr 1fr}.workspace.tt-clean-modal .grid2,.workspace.tt-clean-modal .grid3,.workspace.tt-clean-modal .ttv-grid,.workspace.tt-clean-modal .ttrs-grid,.tt-optional-grid{grid-template-columns:1fr!important}.workspace.tt-clean-modal>.subGrid{grid-template-columns:1fr 1fr}}
      @media(max-width:500px){.tt-quick-items,.workspace.tt-clean-modal>.subGrid{grid-template-columns:1fr}.workspace.active.tt-clean-modal{top:1vh;max-height:98vh;width:98vw}}
    `;
    document.head.appendChild(style);
  }

  function closeGroup() { if (groupDialog) groupDialog.hidden = true; }

  function returnToMain() {
    closeGroup();
    navigationMode = '';
    activeGroup = null;
    masterMode = '';
    qa('.workspace').forEach(workspace => workspace.classList.remove('active', 'tt-clean-modal', 'tt-editor-open', 'tt-master-only', 'tt-entry-only'));
    qa('.tt-editor-stage').forEach(editor => editor.classList.remove('tt-editor-stage'));
    document.body.classList.remove('tt-modal-open');
    q('#entityHome').style.display = 'block';
  }

  function stageEditor(editor, title) {
    const workspace = editor?.closest('.workspace');
    if (!workspace) return;
    let bar = q(':scope > .tt-editor-bar', workspace);
    if (!bar) {
      bar = document.createElement('div');
      bar.className = 'tt-editor-bar';
      bar.innerHTML = '<button class="backBtn tt-clean-close" type="button">× Close</button><h2></h2>';
      workspace.insertBefore(bar, editor);
    }
    makeCloseButton(q('button', bar), workspace);
    q('h2', bar).textContent = title;
    editor.classList.add('tt-editor-stage');
    workspace.classList.add('tt-editor-open');
  }

  function closeModal(workspace) {
    qa('.workspace.active').forEach(active => active.classList.remove('active', 'tt-clean-modal', 'tt-editor-open', 'tt-master-only', 'tt-entry-only'));
    qa('.tt-editor-stage', workspace).forEach(editor => editor.classList.remove('tt-editor-stage'));
    document.body.classList.remove('tt-modal-open');
    q('#entityHome').style.display = 'block';
    masterMode = '';
    navigationMode = 'second-tier';
    activeGroup = null;
  }

  function makeCloseButton(button, workspace) {
    if (!button) return;
    button.removeAttribute('data-back');
    // Once a form is in the shared close shell it must not retain either
    // legacy navigation marker; older capture handlers treat those markers as
    // drill-up navigation and can leave the modal backdrop behind.
    button.removeAttribute('data-editor-back');
    button.classList.add('tt-clean-close');
    button.textContent = '× Close';
    button.setAttribute('aria-label', 'Close form and return to previous screen');
    button.onclick = event => { event.preventDefault(); event.stopPropagation(); closeModal(workspace); };
  }

  function prepareModal() {
    const activeWorkspaces = qa('.workspace.active');
    const workspace = activeWorkspaces.find(candidate => candidate.offsetParent !== null)
      || activeWorkspaces[activeWorkspaces.length - 1]
      || null;
    if (!workspace) return;
    workspace.classList.add('tt-clean-modal');
    workspace.classList.toggle('tt-master-only', !!masterMode);
    workspace.classList.toggle('tt-entry-only', !masterMode && /salary|rent/.test(workspace.textContent.toLowerCase()));
    makeCloseButton(q(':scope > .panelHead [data-back], :scope > .panelHead .tt-clean-close', workspace), workspace);
    qa('[data-editor-back], .tt-editor-bar .tt-clean-close', workspace).forEach(button => makeCloseButton(button, workspace));
    document.body.classList.add('tt-modal-open');
    q('#entityHome').style.display = 'block';
  }

  function prepareSecondTier() {
    const activeWorkspaces = qa('.workspace.active');
    const workspace = activeWorkspaces.find(candidate => candidate.offsetParent !== null)
      || activeWorkspaces[activeWorkspaces.length - 1]
      || null;
    if (!workspace) return;
    workspace.classList.remove('tt-clean-modal', 'tt-editor-open', 'tt-master-only', 'tt-entry-only');
    qa('.tt-editor-stage', workspace).forEach(editor => editor.classList.remove('tt-editor-stage'));
    document.body.classList.remove('tt-modal-open');
    const back = q(':scope > .panelHead [data-back], :scope > .panelHead .tt-clean-close', workspace);
    if (back) {
      back.classList.remove('tt-clean-close');
      back.removeAttribute('data-editor-back');
      back.setAttribute('data-back', '');
      back.textContent = '← Go Back to Main';
      back.setAttribute('aria-label', 'Go Back to Accounts Main');
      back.onclick = event => { event.preventDefault(); event.stopPropagation(); returnToMain(); };
    }
  }

  function normalizeHeaderCloseButtons(root = document) {
    const selector = '.panelHead,.tter-head,.tal-head,.ttbr-head,.ttlcl-head,.ttlcr-head,.ttst-head,.ttsl-head,.ttsph-head,.tgm-modal-head,.tt-window-head,.modalHead,.ttmc-card>div:first-child';
    const headers = [...(root.matches?.(selector) ? [root] : []), ...qa(selector, root)];
    headers.forEach(header => qa('button', header).forEach(button => {
      if (!/^([×✕]\s*)?close$/i.test(button.textContent.trim())) return;
      button.textContent = '× Close';
      if (!button.getAttribute('aria-label')) button.setAttribute('aria-label', 'Close form and return to previous screen');
    }));
  }

  function searchable(select) {
    if (select.dataset.ttSearchable || select.dataset.ttNative || select.multiple || (select.options.length < 2 && !select._ttMasterControl) || select.closest('.entitySwitcher')) return;
    if(!select._ttMasterControl){const label=select.closest('label'),add=label?.querySelector('button[id*="Add"],button[data-add-master]');if(add){const name=(label.childNodes[0]?.textContent||'Item').trim();select._ttMasterControl={label:name,add:()=>add.click(),canAdd:()=>!add.hidden&&!add.disabled};add.classList.add('tt-dropdown-master-launcher');}}
    const roleKinds={ttSdSupplier:'supplier',ttSdBroker:'broker'};const kind=roleKinds[select.id];
    if(kind){const manager=select._ttMasterControl||{},access=window.TT_ACCOUNT_ACCESS||{};manager.edit=(value)=>{const row=(access.masters?.business_parties||[]).find(r=>r.id===value);if(!row)return;window.TT_ACCOUNTS_MASTER_CHOICES?.manageRole(kind,row.values[0],name=>{const option=[...select.options].find(o=>o.value===value);if(option)option.textContent=name;select.dispatchEvent(new Event('change',{bubbles:true}));});};manager.canEdit=option=>(access.super||(access.masterPermissions?.business_parties||[]).includes('Edit'))&&(access.masters?.business_parties||[]).some(r=>r.id===option.value);select._ttMasterControl=manager;}
    select.dataset.ttSearchable = '1';
    select.classList.add('tt-native-select');
    const wrap = document.createElement('div');
    wrap.className = 'tt-search-select';
    const input = document.createElement('input');
    input.type = 'text';
    input.autocomplete = 'off';
    input.removeAttribute('list');
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.placeholder = 'Type 1 or 2 letters to search';
    const menu = document.createElement('div');
    menu.className = 'tt-select-menu';
    if (select.id) menu.dataset.ttSelectFor = select.id;
    menu.hidden = true;
    select.parentNode.insertBefore(wrap, select);
    wrap.append(input, select);
    // Keep options outside scrollable dialogs so they are never clipped by the
    // faded backdrop. Position them within the visible dialog when possible.
    menu._ttOwner = wrap;
    document.body.appendChild(menu);
    const positionMenu = () => {
      if (menu.hidden) return;
      if (!wrap.isConnected) { menu.remove(); if (activeSearchMenu === menu) activeSearchMenu = null; return; }
      const rect = input.getBoundingClientRect();
      // The list is portalled to body. Use viewport coordinates, not the
      // scrollable dialog's coordinates, and never impose a minimum height
      // larger than the space actually available at the field.
      const gap = 4, edge = 8;
      const below = Math.max(0, window.innerHeight - edge - rect.bottom - gap);
      const above = Math.max(0, rect.top - edge - gap);
      const desired = Math.min(220, menu.scrollHeight);
      const openAbove = below < Math.min(desired, 120) && above > below;
      const height = Math.min(220, openAbove ? above : below);
      if (height < 32 || rect.bottom < edge || rect.top > window.innerHeight - edge) {
        menu.hidden = true;
        return;
      }
      const width = Math.min(rect.width, window.innerWidth - edge * 2);
      menu.style.left = `${Math.max(edge, Math.min(rect.left, window.innerWidth - edge - width))}px`;
      menu.style.width = `${width}px`;
      menu.style.maxHeight = `${height}px`;
      menu.style.top = `${openAbove ? rect.top - gap - height : rect.bottom + gap}px`;
    };
    menu._ttPosition = positionMenu;
    const selectedText = () => select.selectedOptions[0]?.textContent.trim() || '';
    const closeMenus = () => qa('.tt-select-menu').forEach(candidate => {
      if (candidate !== menu) candidate.hidden = true;
    });
    const syncInput = () => { input.value = select.value === '' ? '' : selectedText(); };
    syncInput();
    let visibleOptions = [];
    const render = (showAll = false) => {
      closeMenus();
      const portal=input.closest('dialog')||document.body;if(menu.parentElement!==portal)portal.append(menu);
      const selected = selectedText().toLowerCase();
      const typed = input.value.trim().toLowerCase();
      // Opening a selector must show every available choice.  Previously the
      // selected label (for example Cash) was used as the search term, which
      // made Credit and the other products appear to be missing.
      const term = showAll || (select.value !== '' && typed === selected) ? '' : typed;
      let options = qa('option', select).filter(option => !option.disabled && option.value !== '');
      const starts = options.filter(option => option.textContent.trim().toLowerCase().startsWith(term));
      const contains = options.filter(option => option.textContent.trim().toLowerCase().includes(term));
      options = (starts.length ? starts : contains).slice(0, 100);
      visibleOptions = options;
      menu.replaceChildren();
      if (!options.length) { const empty = document.createElement('div'); empty.className = 'tt-select-empty'; empty.textContent = 'No matching option'; menu.appendChild(empty); }
      options.forEach(option => {
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = option.textContent.trim();
        button.onclick = () => { select.value = option.value; input.value = option.textContent.trim(); menu.hidden = true; select.dispatchEvent(new Event('input', {bubbles:true})); select.dispatchEvent(new Event('change', {bubbles:true})); };
        const manager=select._ttMasterControl;
        if(manager?.edit&&(!manager.canEdit||manager.canEdit(option))){const line=document.createElement('div');line.className='tt-option-row';const edit=document.createElement('button');edit.type='button';edit.className='tt-option-edit';edit.textContent='✎';edit.title='Edit '+option.textContent.trim();edit.setAttribute('aria-label',edit.title);edit.onclick=()=>{menu.hidden=true;manager.edit(option.value,option.textContent.trim());};line.append(button,edit);menu.append(line);}else menu.appendChild(button);
      });
      const manager=select._ttMasterControl;
      if(manager?.add&&(!manager.canAdd||manager.canAdd())){const add=document.createElement('button');add.type='button';add.className='tt-option-add';add.textContent='+ Add '+manager.label;add.onclick=()=>{menu.hidden=true;manager.add();};menu.append(add);}
      menu.hidden = false;
      activeSearchMenu = menu;
      positionMenu();
    };
    input.onfocus = () => {
      if (select.value === '') input.value = '';
      else input.select();
      render(true);
    };
    // A second click does not fire focus. Reopen after Escape, a selection,
    // or an outside click without requiring the user to leave the field.
    input.onclick = () => { if (menu.hidden) render(true); };
    input.oninput = () => render(false);
    input.onkeydown = event => {
      if (event.key === 'Escape') { menu.hidden = true; syncInput(); return; }
      if (event.key === 'Enter') {
        // A searchable control lives inside operational forms.  Never let
        // Enter accidentally submit/close the whole form or return home.
        event.preventDefault();
        event.stopPropagation();
        const exact=visibleOptions.find(option=>option.textContent.trim().toLowerCase()===input.value.trim().toLowerCase());
        const option=exact||visibleOptions[0];
        if(option){select.value=option.value;syncInput();menu.hidden=true;select.dispatchEvent(new Event('input',{bubbles:true}));select.dispatchEvent(new Event('change',{bubbles:true}));}
      }
    };
    input.onblur = () => setTimeout(() => { if (menu.hidden || !menu.contains(document.activeElement)) syncInput(); }, 120);
    select.addEventListener('change', syncInput);
  }

  function simplifyCommodity(root) {
    const anchor = q('#cbSoda', root);
    if (!anchor || q('.tt-optional-details', root)) return;
    const ids = ['cbLessWeight','cbWeightValue','cbRateAdj','cbAllowance','cbKanta','cbFilling','cbOtherAdd','cbOtherDeduct','cbBrokerage','cbWithPct','cbWithAmt','cbRemarks'];
    const labels = ids.map(id => q('#'+id, root)?.closest('label')).filter(Boolean);
    if (!labels.length) return;
    const details = document.createElement('details');
    details.className = 'tt-optional-details';
    details.innerHTML = '<summary>Bill adjustments (only when required)</summary><div class="tt-optional-grid"></div>';
    const grid = q('.tt-optional-grid', details);
    labels.forEach(label => grid.appendChild(label));
    anchor.closest('.grid2,.grid3,.ttv-grid')?.appendChild(details);
  }

  function markGenerated(root) {
    qa('label', root).forEach(label => {
      const text = (label.childNodes[0]?.textContent || '').trim().toLowerCase();
      if (!/^(memo|voucher|journal)\s*(no\.?|number)$/.test(text)) return;
      const control = q('input', label);
      if (!control) return;
      control.value = 'Generated automatically when saved';
      control.readOnly = true;
      control.tabIndex = -1;
    });
  }

  function salaryView(root) {
    const editor = (root.matches?.('#expenseEditor') ? root : root.closest?.('#expenseEditor')) || q('#expenseEditor', root);
    if (!editor || !q('.ttrs', editor)) return;
    if(q('#rsMasterSetup',editor))return; // Salary renderer owns its setup popup and visibility.
    const workspace = editor.closest('.workspace');
    if (!workspace?.classList.contains('active')) return;
    if (navigationMode !== 'form') return;
    workspace.classList.add('active', 'tt-clean-modal');
    document.body.classList.add('tt-modal-open');
    workspace.classList.toggle('tt-master-only', !!masterMode);
    workspace.classList.toggle('tt-entry-only', !masterMode);
    const masterBoxes = qa('.ttrs-box', editor).filter(box => q('#rsSaveSal,#rsSaveRent,[data-rs-edit],[data-rs-remove],[data-rs-rentedit],[data-rs-rentremove]', box));
    q('.ttrs-entry-flow', editor)?.classList.toggle('tt-clean-hidden', !!masterMode);
    const allBoxes = qa('.ttrs-box', editor);
    allBoxes.forEach(box => box.classList.toggle('tt-clean-hidden', masterMode ? !masterBoxes.includes(box) : masterBoxes.includes(box)));
    if (masterMode) {
      const formBox = masterBoxes.find(box => q('#rsSaveSal,#rsSaveRent', box));
      const listBox = masterBoxes.find(box => box !== formBox) || editor;
      const editing = /^edit\b/i.test((formBox?.textContent || '').trim());
      if (formBox && !editing && !workspace.dataset.ttMasterAdd) formBox.classList.add('tt-clean-hidden');
      if (!q('.tt-master-add', editor)) {
        const add = document.createElement('button');
        add.type = 'button';
        add.className = 'btn primary tt-master-add';
        add.textContent = masterMode === 'salary' ? '+ Add Salary Master Record' : '+ Add Rent / Recurring Record';
        add.onclick = () => { workspace.dataset.ttMasterAdd = '1'; formBox?.classList.remove('tt-clean-hidden'); formBox?.scrollIntoView({block:'start'}); q('input', formBox)?.focus(); };
        listBox.insertAdjacentElement('beforebegin', add);
      }
    } else {
      delete workspace.dataset.ttMasterAdd;

    }
    qa('[data-editor-back], .tt-editor-bar .tt-clean-close', workspace).forEach(button => makeCloseButton(button, workspace));
  }

  function normalizeRowControls(root=document){
    const buttons=[...(root.matches?.('button')?[root]:[]),...qa('button',root)];
    buttons.forEach(button=>{
      if(button.closest('.tt-select-menu,dialog form')||button.dataset.ttControlStyle)return;
      const label=button.textContent.trim();
      if(button.matches('[data-add],[data-add-debit],#jvwAddLine')||/^(?:\+\s*)?Add (?:another |a )?(?:row|line|charge|deduction|addition|allocation|expense line|debit|item)\b/i.test(label)){button.dataset.ttActionLabel=label;button.classList.add('tt-row-add');button.textContent='+';button.title=label;button.setAttribute('aria-label',label);button.dataset.ttControlStyle='add';}
      else if(/^(?:Remove|Delete|−|✕|×)$/i.test(label)&&button.closest('tr,.dex-row,.jvw-line,.am-debit-row,[data-charge-row]')){button.dataset.ttActionLabel=label;button.classList.add('tt-row-remove');button.textContent='−';button.title=label+' row';button.setAttribute('aria-label',label+' row');button.dataset.ttControlStyle='remove';const row=button.closest('tr,.dex-row,.jvw-line,.am-debit-row,[data-charge-row]');if(row.tagName==='TR')row.firstElementChild?.prepend(button);else row.prepend(button);}
      else if(/^Edit$/i.test(label)){button.dataset.ttActionLabel=label;button.classList.add('tt-row-edit');button.textContent='✎';button.title='Edit';button.setAttribute('aria-label','Edit');button.dataset.ttControlStyle='edit';}
    });
  }

  function scan(root = document) {
    captureLateLaunchers();
    qa('select', root).forEach(searchable);
    normalizeRowControls(root);
    simplifyCommodity(root);
    markGenerated(root);
    normalizeHeaderCloseButtons(root);
    salaryView(root);
    if (q('.workspace.active')) {
      // The accounting desk can open a native form without passing through
      // this icon-group launcher. Treat that active workspace as a form;
      // explicit second-tier launches set their mode before the click.
      if (!navigationMode) navigationMode = 'form';
      if (navigationMode === 'form') prepareModal();
      else if (navigationMode === 'second-tier') prepareSecondTier();
    }
  }

  async function settleSalaryView(expectedMasterMode) {
    const editor = q('#expenseEditor');
    for (let tries = 0; tries < 80 && editor && !q('.ttrs', editor); tries += 1) await sleep(50);
    const workspace = editor?.closest('.workspace');
    if (!workspace?.classList.contains('active') || masterMode !== expectedMasterMode || !q('.ttrs', editor)) return;
    prepareModal();
    salaryView(editor);
    scan(editor);
  }

  function captureLateLaunchers() {
    const source = q('#ttNativeLaunchers');
    if (!source) return;
    qa('#homeGrid > .appCard[data-key]:not(.tt-clean-card)').forEach(card => {
      nativeCards.set(card.dataset.key, card);
      source.appendChild(card);
    });
  }

  function captureWorkspaceLaunchers() {
    const home = q('#homeGrid');
    if (!home || q('#ttNativeLaunchers')) return false;
    const source = document.createElement('div');
    source.id = 'ttNativeLaunchers';
    qa(':scope > .appCard', home).forEach(card => { nativeCards.set(card.dataset.key, card); source.appendChild(card); });
    home.parentNode.insertBefore(source, home.nextSibling);
    return true;
  }

  function init() {
    installStyle();
    if (!captureWorkspaceLaunchers()) return;
    document.addEventListener('click', event => {
      if (event.target.closest('.entityBtn')) window.setTimeout(() => { q('#entityHome').style.display = 'block'; }, 0);
      if (event.target.closest('[data-back]')) window.setTimeout(() => { document.body.classList.remove('tt-modal-open'); masterMode = ''; navigationMode = ''; activeGroup = null; }, 0);
      if (event.target.closest('[data-expense="salary"],[data-expense="rent"]')) void settleSalaryView(masterMode);
    }, true);
    document.addEventListener('click', event => {
      const close = event.target.closest?.('.workspace .tt-clean-close');
      if (!close) return;
      const workspace = close.closest('.workspace');
      if (!workspace) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      closeModal(workspace);
    }, true);
    document.addEventListener('tt:accounts-desk-form-opened', event => {
      navigationMode = 'form';
      activeGroup = null;
      const workspace = q('.workspace.active');
      const editor = workspace && q('#purchaseEditor,#expenseEditor', workspace);
      if (editor?.children.length) {
        const heading = event.detail?.title || q(':scope > .panelHead h2', workspace)?.textContent?.trim() || 'Accounts Entry';
        stageEditor(editor, heading);
      }
      prepareModal();
      scan(workspace || document);
    });
    document.addEventListener('click', event => {
      if (!event.target.closest('.tt-search-select,.tt-select-menu')) qa('.tt-select-menu').forEach(menu => { menu.hidden = true; });
    });
    document.addEventListener('scroll', () => activeSearchMenu?._ttPosition?.(), true);
    window.addEventListener('resize', () => activeSearchMenu?._ttPosition?.());
    const observer = new MutationObserver(records => {
      records.forEach(record => record.addedNodes.forEach(node => { if (node.nodeType === 1) scan(node); }));
      qa('.tt-select-menu').forEach(menu => { if (menu._ttOwner && !menu._ttOwner.isConnected) menu.remove(); });
    });
    observer.observe(document.body, {childList:true, subtree:true});
    scan();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once:true}); else init();

  window.TT_ACCOUNTS_CLEAN_UI = {
    manageSelect(select,config){if(!select)return;select._ttMasterControl=config;searchable(select);},
    normalizeRowControls,
    installed: true,
    cleanIconHub: true,
    popupWorkspaces: true,
    searchableSelects: true,
    automaticInternalNumbers: true,
    masterGovernancePreserved: true
  };
})();
