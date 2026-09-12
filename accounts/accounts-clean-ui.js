(() => {
  'use strict';

  if (window.TT_ACCOUNTS_CLEAN_UI?.installed) return;

  const q = (selector, root = document) => root.querySelector(selector);
  const qa = (selector, root = document) => [...root.querySelectorAll(selector)];
  const sleep = ms => new Promise(resolve => window.setTimeout(resolve, ms));
  const nativeCards = new Map();
  let groupDialog = null;
  let masterMode = '';

  const groups = [
    {key:'purchases', icon:'◉', title:'Purchases', items:[
      {icon:'◎', title:'Sodas', hint:'Search or create commodity Sodas.', native:'purchases', soda:true},
      {icon:'▤', title:'Arrival Bill Posting', hint:'Select the Soda; arrivals, quantity, rate and party fill automatically.', native:'purchases', then:'[data-purchase="commodity"]'}
    ]},
    {key:'ledgers', icon:'▥', title:'Ledgers', items:[
      {icon:'↙', title:'Customer Ledger', hint:'Choose the customer whose ledger you want to see.', native:'receivables'},
      {icon:'↗', title:'Supplier Ledger', hint:'Choose the supplier whose ledger you want to see.', native:'payables'},
      {icon:'≡', title:'General Ledger', hint:'Open the General Ledger inside Reports.', native:'reports', text:'General Ledger'}
    ]},
    {key:'bags', icon:'▧', title:'Bags', items:[
      {icon:'▧', title:'Bag Bill', hint:'Account-linked bag form with automatic sales-tax working.', native:'purchases', then:'[data-purchase="bags"]'},
      {icon:'%', title:'Bag Sales Tax', hint:'Sales-tax and bag report from the same records.', native:'purchases', then:'[data-purchase="bags"]', bagMode:'report'}
    ]},
    {key:'local', icon:'⌂', title:'Local Sales', items:[
      {icon:'✓', title:'Sale Approvals', hint:'Approve local and export-linked sale Sodas.', native:'receivables'},
      {icon:'₹', title:'Payment Approvals', hint:'Approve received payments before posting.', native:'receivables'}
    ]},
    {key:'export', icon:'▣', title:'Export Bills', items:[
      {icon:'⚓', title:'Freight', hint:'Search by shipment, lot, B/L or container.', native:'freight'},
      {icon:'▰', title:'Transport', hint:'Search the Loading Programme and post the bill.', native:'transport'},
      {icon:'◫', title:'Clearing', hint:'Search the shipment, then enter the bill amount.', native:'services', service:'CLEARING'},
      {icon:'◇', title:'Fumigation', hint:'Search the shipment, then enter the bill amount.', native:'services', service:'FUMIGATION'},
      {icon:'⌕', title:'Inspection', hint:'Search the shipment or lot and post the inspection bill.', native:'services', service:'INSPECTION'}
    ]},
    {key:'expenses', icon:'▤', title:'Expenses & Overheads', native:'expenses'},
    {key:'reports', icon:'▥', title:'Reports', native:'reports'},
    {key:'masters', icon:'M', title:'Masters', items:[
      {icon:'♙', title:'Salary Master', hint:'Permanent salary details only; no monthly posting here.', native:'expenses', then:'[data-expense="salary"]', master:'salary'},
      {icon:'⌂', title:'Rent & Recurring Master', hint:'Add a recurring item once, then reuse it.', native:'expenses', then:'[data-expense="rent"]', master:'rent'},
      {icon:'⚙', title:'Accounts Masters', hint:'Use approved canonical masters; do not duplicate them.', native:'masters'}
    ]}
  ];

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
      .workspace.tt-clean-modal .infoCard,.workspace.tt-clean-modal .accountPreview{display:none!important}
      .workspace.tt-clean-modal .formCard,.workspace.tt-clean-modal .ttv-form,.workspace.tt-clean-modal .ttrs-box{box-shadow:none!important;border-color:#e2e8ee!important}
      .workspace.tt-clean-modal .grid2,.workspace.tt-clean-modal .grid3,.workspace.tt-clean-modal .ttv-grid,.workspace.tt-clean-modal .ttrs-grid{grid-template-columns:repeat(3,minmax(150px,1fr))!important;gap:10px!important}
      .workspace.tt-clean-modal textarea{min-height:48px}
      .workspace.tt-clean-modal input,.workspace.tt-clean-modal select,.workspace.tt-clean-modal textarea{padding:8px 9px}
      .workspace.tt-clean-modal .tt-editor-bar{position:sticky;top:0;z-index:14;background:#fff;padding:14px 72px 12px 18px!important;border-radius:18px 18px 0 0}
      .workspace.tt-clean-modal .tt-editor-bar [data-editor-back]{position:absolute;right:15px;top:12px;color:#8f2d28}
      #ttQuickDialog{position:fixed;inset:0;z-index:220;display:grid;place-items:center;padding:18px;background:rgba(10,25,43,.46)}
      #ttQuickDialog[hidden]{display:none}
      .tt-quick-window{width:min(760px,94vw);max-height:88vh;overflow:auto;background:#f7f9fb;border-radius:18px;box-shadow:0 28px 80px rgba(0,0,0,.3)}
      .tt-quick-head{display:flex;align-items:center;gap:12px;padding:16px 18px;background:#fff;border-bottom:1px solid #e4e9ee;position:sticky;top:0;z-index:2}
      .tt-quick-head h2{margin:0;font-size:20px}.tt-quick-head span{flex:1}.tt-cancel{border:1px solid #e3c8c6;background:#fff;color:#8f2d28;border-radius:9px;padding:8px 11px;font-weight:800;cursor:pointer}
      .tt-quick-items{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:11px;padding:16px}
      .tt-quick-item{border:1px solid #dfe6ec;background:#fff;border-radius:14px;padding:15px;text-align:left;cursor:pointer;min-height:115px}
      .tt-quick-item:hover{border-color:#173c63;box-shadow:0 6px 18px rgba(19,40,65,.09)}
      .tt-quick-item b{display:block;font-size:14px;margin:9px 0 4px}.tt-quick-item small{color:#6f7a89;line-height:1.35}.tt-quick-icon{font-size:23px}
      .tt-search-select{position:relative;margin-top:5px}.tt-search-select>input{margin:0!important;padding-right:30px!important}.tt-search-select:after{content:"⌄";position:absolute;right:10px;top:8px;color:#687686;pointer-events:none}.tt-native-select{position:absolute!important;opacity:0!important;pointer-events:none!important;width:1px!important;height:1px!important;margin:0!important}.tt-select-menu{position:absolute;z-index:500;left:0;right:0;top:calc(100% + 4px);max-height:220px;overflow:auto;background:#fff;border:1px solid #cdd7e0;border-radius:9px;box-shadow:0 12px 28px rgba(17,37,59,.18)}
      .tt-select-menu button{display:block;width:100%;border:0;background:#fff;padding:9px 10px;text-align:left;cursor:pointer}.tt-select-menu button:hover,.tt-select-menu button:focus{background:#edf3f8}.tt-select-empty{padding:9px;color:#6f7a89;font-size:11px}
      .tt-optional-details{grid-column:1/-1;border:1px solid #e2e8ee;border-radius:10px;padding:9px 11px;background:#fafbfd}.tt-optional-details summary{cursor:pointer;font-weight:800;color:#42566a}.tt-optional-grid{display:grid;grid-template-columns:repeat(3,minmax(150px,1fr));gap:10px;margin-top:10px}
      .tt-master-only .ttrs-head,.tt-master-only .ttrs-pay,.tt-master-only .ttrs-box:has(table tbody [data-rs-salpay]),.tt-master-only .ttrs-box:has(table tbody [data-rs-rentdue]){display:none!important}
      .tt-entry-only .ttrs-head{display:flex!important}
      .tt-entry-only .ttrs>div:nth-child(2),.tt-entry-only .ttrs>div:nth-child(3){display:none!important}
      .tt-clean-hidden{display:none!important}.tt-master-add{margin:10px 0 12px}.tt-salary-batch{border:1px solid #dfe6ec;border-radius:13px;background:#fff;padding:14px;margin:12px 0}.tt-salary-batch h3{margin:0 0 5px}.tt-salary-batch .tt-batch-grid{display:grid;grid-template-columns:1fr 1.4fr 1fr auto;gap:10px;align-items:end;margin-top:10px}.tt-salary-batch .tt-batch-total{font-size:18px;font-weight:900;padding:8px 0}
      @media(max-width:800px){#homeGrid.tt-clean-grid{grid-template-columns:repeat(2,1fr)!important}.tt-quick-items{grid-template-columns:1fr 1fr}.workspace.tt-clean-modal .grid2,.workspace.tt-clean-modal .grid3,.workspace.tt-clean-modal .ttv-grid,.workspace.tt-clean-modal .ttrs-grid,.tt-optional-grid{grid-template-columns:1fr!important}.workspace.tt-clean-modal>.subGrid{grid-template-columns:1fr 1fr}}
      @media(max-width:500px){.tt-quick-items,.workspace.tt-clean-modal>.subGrid{grid-template-columns:1fr}.workspace.active.tt-clean-modal{top:1vh;max-height:98vh;width:98vw}}
    `;
    document.head.appendChild(style);
  }

  function buildDialog() {
    groupDialog = document.createElement('div');
    groupDialog.id = 'ttQuickDialog';
    groupDialog.hidden = true;
    groupDialog.innerHTML = '<div class="tt-quick-window" role="dialog" aria-modal="true"><div class="tt-quick-head"><h2></h2><span></span><button class="tt-cancel" type="button">× Cancel</button></div><div class="tt-quick-items"></div></div>';
    q('.tt-cancel', groupDialog).onclick = closeGroup;
    groupDialog.addEventListener('click', event => { if (event.target === groupDialog) closeGroup(); });
    document.body.appendChild(groupDialog);
  }

  function closeGroup() { if (groupDialog) groupDialog.hidden = true; }

  function openGroup(group) {
    if (group.native) return launch({native:group.native});
    q('h2', groupDialog).textContent = group.title;
    const items = q('.tt-quick-items', groupDialog);
    items.replaceChildren(...group.items.map(item => {
      const button = document.createElement('button');
      button.className = 'tt-quick-item';
      button.type = 'button';
      button.innerHTML = `<span class="tt-quick-icon">${item.icon}</span><b>${item.title}</b><small>${item.hint}</small>`;
      button.onclick = () => launch(item);
      return button;
    }));
    groupDialog.hidden = false;
  }

  function findTextButton(root, text) {
    return qa('button', root).find(button => button.textContent.trim().toLowerCase().includes(text.toLowerCase()));
  }

  async function launch(item) {
    closeGroup();
    masterMode = item.master || '';
    qa('.workspace').forEach(workspace => workspace.classList.remove('active', 'tt-clean-modal', 'tt-editor-open', 'tt-master-only', 'tt-entry-only'));
    qa('.tt-editor-stage').forEach(editor => editor.classList.remove('tt-editor-stage'));
    document.body.classList.remove('tt-modal-open');
    q('#entityHome').style.display = 'block';
    if (item.soda) sessionStorage.setItem('tt_purchase_focus', 'ALL');
    const card = nativeCards.get(item.native) || q(`#ttNativeLaunchers .appCard[data-key="${item.native}"]`);
    if (!card) return;
    card.click();
    if (item.soda) {
      await sleep(180);
      document.dispatchEvent(new CustomEvent('tt:purchase-focus', {detail:{focus:'ALL'}}));
      stageEditor(q('#purchaseEditor'), 'Sodas');
    }
    if (item.then) {
      let target = null;
      for (let tries = 0; tries < 30 && !target; tries += 1) { target = q(item.then); if (!target) await sleep(35); }
      target?.click();
    }
    if (item.text) {
      await sleep(30);
      findTextButton(q('.workspace.active') || document, item.text)?.click();
    }
    if (item.service) {
      for (let tries = 0; tries < 30 && !q('#svKind'); tries += 1) await sleep(35);
      const kind = q('#svKind');
      if (kind) {
        const option = qa('option', kind).find(x => x.value === item.service || x.textContent.toUpperCase().includes(item.service));
        if (option) { kind.value = option.value; kind.dispatchEvent(new Event('change', {bubbles:true})); }
      }
    }
    if (item.bagMode) {
      for (let tries = 0; tries < 30 && !q(`[data-bg-mode="${item.bagMode}"]`); tries += 1) await sleep(35);
      q(`[data-bg-mode="${item.bagMode}"]`)?.click();
    }
    await sleep(20);
    prepareModal();
    scan(q('.workspace.active') || document);
  }

  function stageEditor(editor, title) {
    const workspace = editor?.closest('.workspace');
    if (!workspace) return;
    let bar = q(':scope > .tt-editor-bar', workspace);
    if (!bar) {
      bar = document.createElement('div');
      bar.className = 'tt-editor-bar';
      bar.innerHTML = '<button class="backBtn tt-clean-close" type="button">× Cancel</button><h2></h2>';
      workspace.insertBefore(bar, editor);
    }
    makeCloseButton(q('button', bar), workspace);
    q('h2', bar).textContent = title;
    editor.classList.add('tt-editor-stage');
    workspace.classList.add('tt-editor-open');
  }

  function closeModal(workspace) {
    workspace.classList.remove('active', 'tt-clean-modal', 'tt-editor-open', 'tt-master-only', 'tt-entry-only');
    qa('.tt-editor-stage', workspace).forEach(editor => editor.classList.remove('tt-editor-stage'));
    document.body.classList.remove('tt-modal-open');
    q('#entityHome').style.display = 'block';
    masterMode = '';
  }

  function makeCloseButton(button, workspace) {
    if (!button) return;
    button.removeAttribute('data-back');
    button.removeAttribute('data-editor-back');
    button.classList.add('tt-clean-close');
    button.textContent = '× Cancel';
    button.onclick = event => { event.preventDefault(); event.stopPropagation(); closeModal(workspace); };
  }

  function prepareModal() {
    const workspace = q('.workspace.active');
    if (!workspace) return;
    workspace.classList.add('tt-clean-modal');
    workspace.classList.toggle('tt-master-only', !!masterMode);
    workspace.classList.toggle('tt-entry-only', !masterMode && /salary|rent/.test(workspace.textContent.toLowerCase()));
    makeCloseButton(q(':scope > .panelHead [data-back], :scope > .panelHead .tt-clean-close', workspace), workspace);
    qa('[data-editor-back], .tt-editor-bar .tt-clean-close', workspace).forEach(button => makeCloseButton(button, workspace));
    document.body.classList.add('tt-modal-open');
    q('#entityHome').style.display = 'block';
  }

  function searchable(select) {
    if (select.dataset.ttSearchable || select.multiple || select.options.length < 2 || select.closest('.entitySwitcher')) return;
    select.dataset.ttSearchable = '1';
    select.classList.add('tt-native-select');
    const wrap = document.createElement('div');
    wrap.className = 'tt-search-select';
    const input = document.createElement('input');
    input.type = 'text';
    input.autocomplete = 'off';
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.placeholder = 'Type 1 or 2 letters to search';
    const menu = document.createElement('div');
    menu.className = 'tt-select-menu';
    menu.hidden = true;
    select.parentNode.insertBefore(wrap, select);
    wrap.append(input, select, menu);
    const selectedText = () => select.selectedOptions[0]?.textContent.trim() || '';
    input.value = selectedText();
    const render = () => {
      const term = input.value.trim().toLowerCase();
      let options = qa('option', select).filter(option => !option.disabled && option.value !== '');
      const starts = options.filter(option => option.textContent.trim().toLowerCase().startsWith(term));
      const contains = options.filter(option => option.textContent.trim().toLowerCase().includes(term));
      options = (starts.length ? starts : contains).slice(0, 15);
      menu.replaceChildren();
      if (!options.length) { const empty = document.createElement('div'); empty.className = 'tt-select-empty'; empty.textContent = 'No matching option'; menu.appendChild(empty); }
      options.forEach(option => {
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = option.textContent.trim();
        button.onclick = () => { select.value = option.value; input.value = option.textContent.trim(); menu.hidden = true; select.dispatchEvent(new Event('input', {bubbles:true})); select.dispatchEvent(new Event('change', {bubbles:true})); };
        menu.appendChild(button);
      });
      menu.hidden = false;
    };
    input.onfocus = render;
    input.oninput = render;
    input.onkeydown = event => { if (event.key === 'Escape') { menu.hidden = true; input.value = selectedText(); } };
    select.addEventListener('change', () => { input.value = selectedText(); });
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
    const workspace = editor.closest('.workspace');
    if (!workspace) return;
    workspace.classList.add('active', 'tt-clean-modal');
    document.body.classList.add('tt-modal-open');
    workspace.classList.toggle('tt-master-only', !!masterMode);
    workspace.classList.toggle('tt-entry-only', !masterMode);
    const masterBoxes = qa('.ttrs-box', editor).filter(box => q('#rsSaveSal,#rsSaveRent,[data-rs-edit],[data-rs-remove],[data-rs-rentedit],[data-rs-rentremove]', box));
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
      installSalaryBatch(editor);
    }
    qa('[data-editor-back], .tt-editor-bar .tt-clean-close', workspace).forEach(button => makeCloseButton(button, workspace));
  }

  function numberFrom(text) { return Number(String(text || '').replace(/[^0-9.-]/g, '')) || 0; }

  function installSalaryBatch(editor) {
    const rows = qa('[data-rs-salpay]', editor);
    const oldPay = q('.ttrs-pay', editor);
    if (!rows.length || !oldPay || q('.tt-salary-batch', editor)) return;
    oldPay.classList.add('tt-clean-hidden');
    const source = q('#rsPaySalBank', oldPay);
    const total = rows.reduce((sum, button) => sum + numberFrom(button.closest('tr')?.children[7]?.textContent), 0);
    const box = document.createElement('div');
    box.className = 'tt-salary-batch';
    box.innerHTML = `<h3>Pay Prepared Salaries</h3><div class="helper">One selection and one click. Transtrade posts the complete prepared salary payment and generates its voucher number.</div><div class="tt-batch-grid"><label>Date<input id="ttBatchSalaryDate" type="date" value="${new Date().toISOString().slice(0,10)}"></label><label>Pay From<select id="ttBatchSalaryAccount">${source?.innerHTML || ''}</select></label><label>Cheque No. <input id="ttBatchSalaryCheque" placeholder="Required for bank payment"></label><button class="btn green" id="ttBatchSalaryPay" type="button">Pay All · Rs ${total.toLocaleString('en-PK')}</button></div></div>`;
    oldPay.insertAdjacentElement('beforebegin', box);
    const account = q('#ttBatchSalaryAccount', box);
    const cheque = q('#ttBatchSalaryCheque', box);
    const toggleCheque = () => { const cash = String(account.value).startsWith('CASH|'); cheque.closest('label').hidden = cash; if (cash) cheque.value = ''; };
    account.addEventListener('change', toggleCheque);
    toggleCheque();
    q('#ttBatchSalaryPay', box).onclick = async event => {
      const button = event.currentTarget;
      if (!account.value) return alert('Select Cash or the bank account.');
      if (!String(account.value).startsWith('CASH|') && !cheque.value.trim()) return alert('Enter the cheque number for this bank payment.');
      if (!confirm(`Pay all prepared salary balances totaling Rs ${total.toLocaleString('en-PK')}?`)) return;
      button.disabled = true;
      try {
        const key = 'SALBATCH-'+Date.now().toString(36)+'-'+crypto.getRandomValues(new Uint32Array(1))[0].toString(36);
        const response = await fetch('../api/rent_salary.php', {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','Accept':'application/json'}, body:JSON.stringify({action:'salary_batch_payment', csrf:window.TT_ACCOUNT_ACCESS?.csrf, entity:localStorage.getItem('tt_accounts_entity')||'TTI', month:q('#rsMonth', editor)?.value||'', date:q('#ttBatchSalaryDate', box).value, paymentAccountId:account.value, chequeNo:cheque.value.trim(), requestKey:key})});
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.error || 'Salary payment could not be posted.');
        alert(`Salary payment posted. Voucher ${data.result?.journalId || 'generated'}.`);
        q('[data-expense="salary"]')?.click();
      } catch (error) { alert(error.message || error); button.disabled = false; }
    };
    searchable(account);
  }

  function scan(root = document) {
    captureLateLaunchers();
    qa('select', root).forEach(searchable);
    simplifyCommodity(root);
    markGenerated(root);
    salaryView(root);
    if (q('.workspace.active')) prepareModal();
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

  function rebuildHome() {
    const home = q('#homeGrid');
    if (!home || q('#ttNativeLaunchers')) return false;
    const source = document.createElement('div');
    source.id = 'ttNativeLaunchers';
    qa(':scope > .appCard', home).forEach(card => { nativeCards.set(card.dataset.key, card); source.appendChild(card); });
    home.parentNode.insertBefore(source, home.nextSibling);
    home.classList.add('tt-clean-grid');
    groups.forEach(group => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'appCard tt-clean-card';
      button.dataset.cleanKey = group.key;
      button.innerHTML = `<div class="appIcon">${group.icon}</div><h3>${group.title}</h3>`;
      button.onclick = () => openGroup(group);
      home.appendChild(button);
    });
    return true;
  }

  function init() {
    installStyle();
    buildDialog();
    if (!rebuildHome()) return;
    document.addEventListener('click', event => {
      if (event.target.closest('.entityBtn')) window.setTimeout(() => { q('#entityHome').style.display = 'block'; }, 0);
      if (event.target.closest('[data-back]')) window.setTimeout(() => { document.body.classList.remove('tt-modal-open'); masterMode = ''; }, 0);
      if (event.target.closest('[data-expense="salary"],[data-expense="rent"]')) void settleSalaryView(masterMode);
    }, true);
    document.addEventListener('click', event => {
      if (!event.target.closest('.tt-search-select')) qa('.tt-select-menu').forEach(menu => { menu.hidden = true; });
    });
    const observer = new MutationObserver(records => {
      records.forEach(record => record.addedNodes.forEach(node => { if (node.nodeType === 1) scan(node); }));
    });
    observer.observe(document.body, {childList:true, subtree:true});
    scan();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once:true}); else init();

  window.TT_ACCOUNTS_CLEAN_UI = {
    installed: true,
    cleanIconHub: true,
    popupWorkspaces: true,
    searchableSelects: true,
    automaticInternalNumbers: true,
    masterGovernancePreserved: true
  };
})();
