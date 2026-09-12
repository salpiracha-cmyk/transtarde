(() => {
  'use strict';

  if (window.TT_ACCOUNTING_DESK?.installed) return;

  const access = window.TT_ACCOUNT_ACCESS || {};
  const q = (selector, root = document) => root.querySelector(selector);
  const qa = (selector, root = document) => [...root.querySelectorAll(selector)];
  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
  const entity = () => localStorage.getItem('tt_accounts_entity') || access.entities?.[0] || 'TTI';
  const today = () => new Intl.DateTimeFormat('en-CA', {timeZone:'Asia/Karachi'}).format(new Date());
  const money = value => Number(value || 0).toLocaleString('en-PK', {minimumFractionDigits:2, maximumFractionDigits:2});
  const api = '../api/purchase_sodas.php';
  const searchApi = '../api/accounts_search.php';
  let sodaData = {sodas:[], nextSodaNo:'Generated automatically'};
  let activeSoda = null;

  const areas = [
    {key:'purchases', title:'Purchases', note:'Sodas, arrivals and bills', actions:[
      {title:'Soda Centre', note:'New Soda or search and amend an earlier Soda', special:'soda'},
      {title:'Arrival / Rice Bill', note:'Select arrival; Soda, truck, weight, rate and party fill automatically', native:'purchases', then:'[data-purchase="commodity"]'},
      {title:'Bags', note:'Bag bill with linked details and automatic sales-tax working', native:'purchases', then:'[data-purchase="bags"]'},
      {title:'Other Purchase', note:'Assets, consumables and services', native:'purchases', then:'[data-purchase="other"]'}
    ]},
    {key:'payments', title:'Bills & Payments', note:'Payables and third-party vouchers', actions:[
      {title:'Supplier / Broker Bills', note:'Bills due, held, disputed and bill-wise settlement', native:'payables'},
      {title:'Export Shipment Bills', note:'Freight, clearing, fumigation, inspection and transport', special:'shipment'},
      {title:'Make Payment', note:'Cash or bank payment with bill allocation and printed voucher', native:'bank'},
      {title:'Payment Planning', note:'Due-day and cumulative supplier position', native:'payables'}
    ]},
    {key:'receipts', title:'Bank & Receipts', note:'Money received and bank activity', actions:[
      {title:'Export Payment Received', note:'Credit-advice matching, exchange rate, taxes and charges', native:'receivables', find:'Export'},
      {title:'Local Sale Payment', note:'Approve mill receipt or record office receipt against Local Soda', native:'receivables', find:'Receipt'},
      {title:'Other Payment Received', note:'From whom, purpose, cash/bank and live accounting treatment', native:'bank', find:'Receive'},
      {title:'Bank Reconciliation', note:'Match statement and ledger activity', native:'reconciliation'}
    ]},
    {key:'expenses', title:'Expenses & Overheads', note:'Prepare, approve and pay', actions:[
      {title:'Due & Recurring', note:'Pay a saved recurring item or add one once', native:'expenses', then:'[data-expense="rent"]'},
      {title:'Prepare Salaries', note:'Prepared from Salary Master; choose cash/bank and post once', native:'expenses', then:'[data-expense="salary"]'},
      {title:'Utilities & Bills', note:'Electricity, internet, gas, water and telephone', native:'expenses', then:'[data-expense="utility"]'},
      {title:'Other Expenses', note:'Cards, reimbursement, donations, office and mill expense', native:'expenses'}
    ]},
    {key:'ledgers', title:'Ledgers', note:'Balances, history and outstanding', actions:[
      {title:'Supplier Ledger', note:'Type one or two letters to select a supplier', native:'payables'},
      {title:'Customer Ledger', note:'Type one or two letters to select a customer', native:'receivables'},
      {title:'General Ledger', note:'Account-wise transactions and drill-down', native:'reports', find:'General Ledger'},
      {title:'Bank / Cash Ledger', note:'Account balance and transaction history', native:'bank'}
    ]},
    {key:'reports', title:'Reports', note:'Financial statements and analysis', actions:[
      {title:'Trial Balance', native:'reports', find:'Trial Balance'},
      {title:'Profit & Loss', native:'reports', find:'Profit'},
      {title:'Balance Sheet', native:'reports', find:'Balance Sheet'},
      {title:'Receivables / Payables', native:'reports', find:'Receivables'}
    ]},
    {key:'control', title:'Accounting Control', note:'JV, reconciliation and audit', actions:[
      {title:'Journal Voucher', note:'Balanced, permissioned and audited', native:'jv'},
      {title:'Reconciliation', note:'Bank, cash and party controls', native:'reconciliation'},
      {title:'Search Previous', note:'Find any voucher, bill, Soda, truck, shipment or reference', special:'search'},
      {title:'Recent Activity', note:'Review posting and amendment audit history', special:'search'}
    ]}
  ];

  function installStyle() {
    if (q('#ttAccountingDeskStyle')) return;
    const style = document.createElement('style');
    style.id = 'ttAccountingDeskStyle';
    style.textContent = `
      body{background:#edf1f4!important;color:#172433}
      .topbar{height:62px!important;padding:0 22px!important}.brand{min-width:210px!important}.crumb{opacity:.72}
      #ttMasterTop,#ttChangeCompanyDesk{border:1px solid #ffffff32;background:#ffffff12;color:#fff;border-radius:9px;height:38px;padding:0 13px;font-weight:800;cursor:pointer}
      #ttMasterTop{width:40px;padding:0;font-size:17px;background:#fff;color:#102a46}
      #ttCompanyMenu{position:fixed;right:66px;top:57px;z-index:510;width:min(580px,calc(100vw - 24px));display:grid;grid-template-columns:repeat(3,1fr);gap:8px;padding:10px;background:#fff;border:1px solid #dbe2ea;border-radius:12px;box-shadow:0 18px 48px #0b203840}
      #ttCompanyMenu[hidden]{display:none}.tt-company-choice{border:1px solid #dbe2ea;background:#fff;border-radius:10px;padding:11px;text-align:left;cursor:pointer}.tt-company-choice.active{border-color:#173c63;box-shadow:inset 3px 0 #173c63}.tt-company-choice b,.tt-company-choice small{display:block}.tt-company-choice small{margin-top:3px;color:#6b7887}
      .shell{max-width:1500px!important;padding:18px 22px!important}#entityHome>.entityHero,#entityHome>.notice,#entityHome>#homeGrid,#entityHome>.panel{display:none!important}
      #ttAccountingDesk{display:grid;grid-template-columns:230px minmax(0,1fr);gap:16px;min-height:calc(100vh - 98px)}
      .tt-desk-nav,.tt-desk-main>section{background:#fff;border:1px solid #dfe5ea;border-radius:12px;box-shadow:0 3px 14px rgba(18,40,62,.05)}
      .tt-desk-nav{padding:10px;align-self:start;position:sticky;top:80px}.tt-desk-identity{padding:12px 11px 14px;border-bottom:1px solid #e5e9ed;margin-bottom:7px}.tt-desk-identity small{display:block;color:#6b7887;text-transform:uppercase;letter-spacing:1px;font-size:9px}.tt-desk-identity b{display:block;margin-top:4px;font-size:15px}
      .tt-area-button{display:block;width:100%;border:0;background:transparent;border-radius:8px;padding:10px 11px;text-align:left;cursor:pointer;color:#3f4d5c;font-weight:750}.tt-area-button:hover,.tt-area-button.active{background:#eaf0f5;color:#102a46}.tt-area-button span{display:block;font-size:10px;color:#7a8691;font-weight:500;margin-top:2px}
      .tt-desk-main{min-width:0}.tt-desk-main>section{margin-bottom:14px}.tt-desk-heading{padding:18px 20px;display:flex;gap:14px;align-items:center}.tt-desk-heading>div{flex:1}.tt-desk-heading h1{font-size:22px;margin:0}.tt-desk-heading p{margin:4px 0 0;color:#697686;font-size:12px}.tt-search-main{width:min(360px,42vw);border:1px solid #cbd5df;border-radius:9px;padding:10px 12px;background:#f8fafb}
      .tt-position{display:grid;grid-template-columns:repeat(4,1fr);border-top:1px solid #e7ebef}.tt-position button{border:0;border-right:1px solid #e7ebef;background:#fff;padding:14px 18px;text-align:left;cursor:pointer}.tt-position button:last-child{border-right:0}.tt-position small,.tt-position b{display:block}.tt-position small{color:#75818e;font-size:10px;text-transform:uppercase;letter-spacing:.6px}.tt-position b{font-size:17px;margin-top:4px}.tt-position em{display:block;color:#87919b;font-size:10px;font-style:normal;margin-top:2px}
      .tt-work-head{display:flex;align-items:center;padding:15px 18px;border-bottom:1px solid #e6eaee}.tt-work-head h2{margin:0;font-size:16px}.tt-work-head p{margin:3px 0 0;color:#74808d;font-size:11px}.tt-work-head button{margin-left:auto}
      .tt-action-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));padding:8px}.tt-action{border:0;background:#fff;padding:13px;border-radius:8px;text-align:left;cursor:pointer;display:flex;gap:12px;align-items:flex-start}.tt-action:hover{background:#f2f6f8}.tt-action-mark{width:30px;height:30px;flex:0 0 30px;border-radius:7px;background:#e8eef3;display:grid;place-items:center;color:#173c63;font-weight:900}.tt-action b,.tt-action small{display:block}.tt-action b{font-size:13px}.tt-action small{color:#75818d;margin-top:3px;line-height:1.35}
      .tt-queue{padding:8px 17px 16px}.tt-queue-row{display:grid;grid-template-columns:110px 1fr auto;gap:14px;padding:10px 0;border-bottom:1px solid #edf0f2;align-items:center}.tt-queue-row:last-child{border:0}.tt-queue-row span{font-size:11px;color:#6e7b87}.tt-queue-row b{font-size:12px}.tt-queue-row button{border:0;background:#edf3f7;color:#173c63;border-radius:7px;padding:7px 10px;font-weight:750;cursor:pointer}
      .workspace.active.tt-clean-modal{top:3vh!important;max-height:94vh!important;border-radius:13px!important;background:#f5f7f9!important}.workspace.tt-clean-modal>.panelHead{border-radius:13px 13px 0 0!important}.workspace.tt-clean-modal .accountPreview{display:block!important;background:#eef5f8!important;border:1px solid #bfd0dc!important}.workspace.tt-clean-modal .infoCard{display:block!important}.workspace.tt-clean-modal .split{display:grid!important;grid-template-columns:minmax(0,1fr) 330px!important}.workspace.tt-clean-modal .formCard{background:#fff}
      .tt-prev-search{margin-left:auto!important;white-space:nowrap}.workspace.tt-clean-modal>.panelHead{align-items:center!important}.workspace.tt-clean-modal>.panelHead>div{flex:1}
      .tt-layer{position:fixed;inset:0;z-index:700;background:rgba(9,25,42,.54);display:grid;place-items:center;padding:18px}.tt-layer[hidden]{display:none}.tt-window{width:min(1080px,96vw);max-height:94vh;overflow:auto;background:#f5f7f9;border-radius:14px;box-shadow:0 28px 80px #0006}.tt-window-head{position:sticky;top:0;z-index:3;display:flex;align-items:center;gap:12px;padding:15px 18px;background:#fff;border-bottom:1px solid #dfe5ea}.tt-window-head h2{margin:0;font-size:18px}.tt-window-head span{flex:1}.tt-window-close{border:1px solid #dfc2c0;background:#fff;color:#8c2f2a;border-radius:8px;padding:8px 11px;font-weight:800;cursor:pointer}.tt-window-body{padding:16px}
      .tt-modebar{display:flex;gap:7px;margin-bottom:14px}.tt-modebar button{border:1px solid #ccd6df;background:#fff;border-radius:8px;padding:9px 13px;font-weight:800;cursor:pointer}.tt-modebar button.active{background:#102a46;color:#fff;border-color:#102a46}
      .tt-form{background:#fff;border:1px solid #dfe5ea;border-radius:11px;padding:15px}.tt-form-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.tt-form-grid .wide{grid-column:span 2}.tt-form label{font-size:10px}.tt-form-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:13px}.tt-treatment{margin-top:13px;border:1px solid #b9cedb;background:#eef6fa;border-radius:9px;padding:12px}.tt-treatment>strong{display:block;margin-bottom:7px}.tt-treatment-row{display:grid;grid-template-columns:50px 1fr auto;gap:8px;font-size:12px;padding:4px 0}.tt-treatment-total{border-top:1px solid #cbdbe4;margin-top:5px;padding-top:7px;font-weight:800}.tt-note{font-size:11px;color:#687686;margin-top:7px}
      .tt-records{margin-top:12px;background:#fff;border:1px solid #dfe5ea;border-radius:11px;overflow:hidden}.tt-records table{width:100%}.tt-records button{padding:6px 8px}.tt-searchbar{display:grid;grid-template-columns:1fr auto;gap:8px}.tt-searchbar input{margin:0;padding:11px 12px}.tt-searchbar button{border:0;border-radius:8px;background:#102a46;color:#fff;padding:0 16px;font-weight:800}.tt-record-card{background:#fff;border:1px solid #dfe5ea;border-radius:10px;padding:13px;margin-top:10px}.tt-record-card pre{white-space:pre-wrap;word-break:break-word;background:#f4f6f8;border-radius:8px;padding:10px;max-height:280px;overflow:auto;font-size:11px}.tt-record-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:9px}
      @media(max-width:900px){#ttAccountingDesk{grid-template-columns:1fr}.tt-desk-nav{position:static;display:flex;overflow:auto}.tt-desk-identity{display:none}.tt-area-button{min-width:145px}.tt-position{grid-template-columns:repeat(2,1fr)}.tt-action-list{grid-template-columns:1fr}.workspace.tt-clean-modal .split{grid-template-columns:1fr!important}.tt-form-grid{grid-template-columns:repeat(2,1fr)}#ttCompanyMenu{grid-template-columns:1fr;right:10px}}
      @media(max-width:560px){.tt-desk-heading{display:block}.tt-search-main{width:100%;margin-top:12px}.tt-position,.tt-form-grid{grid-template-columns:1fr}.tt-form-grid .wide{grid-column:auto}.tt-queue-row{grid-template-columns:1fr auto}.tt-queue-row span{display:none}.topbar{padding:0 10px!important}.brand{min-width:0!important}.brand>div:last-child{display:none}#ttChangeCompanyDesk{max-width:130px;overflow:hidden;text-overflow:ellipsis}.tt-window-body{padding:10px}}
    `;
    document.head.appendChild(style);
  }

  function nativeCard(key) {
    return q(`#ttNativeLaunchers .appCard[data-key="${key}"], #homeGrid .appCard[data-key="${key}"]`);
  }

  async function launch(action) {
    if (action.special === 'soda') return openSoda();
    if (action.special === 'search') return openSearch();
    if (action.special === 'shipment') return openShipmentChooser();
    const card = nativeCard(action.native);
    if (!card) return alert('This Accounts area is temporarily unavailable.');
    card.click();
    if (action.then) {
      let button = null;
      for (let i = 0; i < 40 && !button; i += 1) {
        button = q(action.then);
        if (!button) await new Promise(resolve => setTimeout(resolve, 40));
      }
      button?.click();
    }
    if (action.find) {
      await new Promise(resolve => setTimeout(resolve, 90));
      const root = q('.workspace.active') || document;
      qa('button', root).find(button => button.textContent.toLowerCase().includes(action.find.toLowerCase()))?.click();
    }
  }

  function buildTopbar() {
    const top = q('.topbar');
    if (!top || q('#ttMasterTop')) return;
    const power = q('.power', top);
    const master = document.createElement('button');
    master.id = 'ttMasterTop';
    master.type = 'button';
    master.title = 'Masters';
    master.setAttribute('aria-label', 'Open Masters');
    master.textContent = 'M';
    master.onclick = () => launch({native:'masters'});
    const company = document.createElement('button');
    company.id = 'ttChangeCompanyDesk';
    company.type = 'button';
    company.textContent = q('.entityBtn.active strong')?.textContent || 'Change Company';
    const menu = document.createElement('div');
    menu.id = 'ttCompanyMenu';
    menu.hidden = true;
    for (const source of qa('.entityBtn')) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'tt-company-choice' + (source.classList.contains('active') ? ' active' : '');
      button.dataset.entity = source.dataset.entity;
      button.innerHTML = `<b>${esc(q('strong', source)?.textContent)}</b><small>${esc(q('small', source)?.textContent)}</small>`;
      button.onclick = () => {
        source.click();
        company.textContent = q('strong', source)?.textContent || source.dataset.entity;
        qa('.tt-company-choice', menu).forEach(x => x.classList.toggle('active', x === button));
        menu.hidden = true;
        refreshEntityLabels();
      };
      menu.appendChild(button);
    }
    company.onclick = event => { event.stopPropagation(); menu.hidden = !menu.hidden; };
    menu.onclick = event => event.stopPropagation();
    top.insertBefore(master, power);
    top.insertBefore(company, power);
    top.insertBefore(menu, power);
    document.addEventListener('click', () => { menu.hidden = true; });
    q('#ttChangeEntity')?.remove();
  }

  function deskMarkup() {
    return `<div id="ttAccountingDesk">
      <nav class="tt-desk-nav" aria-label="Accounts work areas">
        <div class="tt-desk-identity"><small>Current books</small><b data-tt-entity-name></b></div>
        ${areas.map((area, i) => `<button type="button" class="tt-area-button${i === 0 ? ' active' : ''}" data-tt-area="${area.key}">${esc(area.title)}<span>${esc(area.note)}</span></button>`).join('')}
      </nav>
      <div class="tt-desk-main">
        <section><div class="tt-desk-heading"><div><h1>Accounts · <span data-tt-entity-name></span></h1><p>Balances, pending work and accounting actions in one place.</p></div><input class="tt-search-main" aria-label="Search previous records" placeholder="Search voucher, bill, Soda, truck or shipment"></div>
          <div class="tt-position"><button data-tt-open="bank"><small>Bank & Cash</small><b>Open ledger</b><em>Current balances and activity</em></button><button data-tt-open="receivables"><small>Receivables</small><b>Review</b><em>Export, local and other</em></button><button data-tt-open="payables"><small>Payables</small><b>Review</b><em>Due, overdue and held</em></button><button data-tt-search><small>Previous Entries</small><b>Search</b><em>Full audit and voucher view</em></button></div>
        </section>
        <section id="ttDeskWork"></section>
        <section><div class="tt-work-head"><div><h2>Needs Attention</h2><p>Work requiring Accounts review; held items remain visible but are not selected for payment.</p></div></div><div class="tt-queue"><div class="tt-queue-row"><span>Purchases</span><b>Arrivals and bills awaiting Accounts review</b><button data-tt-queue="purchases">Review</button></div><div class="tt-queue-row"><span>Payments</span><b>Supplier bills due and recurring payments</b><button data-tt-queue="payments">Review</button></div><div class="tt-queue-row"><span>Receipts</span><b>Credit advices and local sale receipts awaiting confirmation</b><button data-tt-queue="receipts">Review</button></div></div></section>
      </div>
    </div>`;
  }

  function showArea(key) {
    const area = areas.find(item => item.key === key) || areas[0];
    qa('.tt-area-button').forEach(button => button.classList.toggle('active', button.dataset.ttArea === area.key));
    const work = q('#ttDeskWork');
    if (!work) return;
    work.innerHTML = `<div class="tt-work-head"><div><h2>${esc(area.title)}</h2><p>${esc(area.note)}</p></div><button type="button" class="btn tt-prev-direct">Search Previous</button></div><div class="tt-action-list">${area.actions.map((action, index) => `<button type="button" class="tt-action" data-tt-action="${index}"><span class="tt-action-mark">${String(index + 1).padStart(2, '0')}</span><span><b>${esc(action.title)}</b><small>${esc(action.note || 'Open report')}</small></span></button>`).join('')}</div>`;
    qa('[data-tt-action]', work).forEach(button => button.onclick = () => launch(area.actions[Number(button.dataset.ttAction)]));
    q('.tt-prev-direct', work).onclick = openSearch;
  }

  function buildDesk() {
    const home = q('#entityHome');
    if (!home || q('#ttAccountingDesk')) return;
    home.insertAdjacentHTML('afterbegin', deskMarkup());
    qa('.tt-area-button').forEach(button => button.onclick = () => showArea(button.dataset.ttArea));
    qa('[data-tt-open]').forEach(button => button.onclick = () => launch({native:button.dataset.ttOpen}));
    qa('[data-tt-search]').forEach(button => button.onclick = openSearch);
    qa('[data-tt-queue]').forEach(button => button.onclick = () => showArea(button.dataset.ttQueue));
    q('.tt-search-main').addEventListener('keydown', event => { if (event.key === 'Enter') openSearch(event.currentTarget.value); });
    showArea('purchases');
    refreshEntityLabels();
  }

  function refreshEntityLabels() {
    const name = q('.entityBtn.active strong')?.textContent || entity();
    qa('[data-tt-entity-name]').forEach(node => { node.textContent = name; });
  }

  function layer(id, title) {
    let host = q('#' + id);
    if (!host) {
      host = document.createElement('div');
      host.id = id;
      host.className = 'tt-layer';
      host.hidden = true;
      host.innerHTML = `<div class="tt-window" role="dialog" aria-modal="true"><div class="tt-window-head"><h2></h2><span></span><button class="tt-window-close" type="button">× Cancel</button></div><div class="tt-window-body"></div></div>`;
      q('.tt-window-close', host).onclick = () => { host.hidden = true; document.body.classList.remove('tt-desk-layer-open'); };
      host.onclick = event => { if (event.target === host) q('.tt-window-close', host).click(); };
      document.body.appendChild(host);
    }
    q('.tt-window-head h2', host).textContent = title;
    host.hidden = false;
    return host;
  }

  async function json(url, options) {
    const response = await fetch(url, {credentials:'same-origin', headers:{Accept:'application/json', ...(options?.headers || {})}, ...options});
    let data = {};
    try { data = await response.json(); } catch (_) {}
    if (!response.ok || !data.ok) throw new Error(data.error || 'Accounts request could not be completed.');
    return data;
  }

  async function loadSodas() {
    sodaData = await json(`${api}?entity=${encodeURIComponent(entity())}`);
    return sodaData;
  }

  function sodaForm(record = null) {
    const value = (key, fallback = '') => esc(record?.[key] ?? fallback);
    const commodity = String(record?.commodity || 'RICE').toUpperCase();
    const credit = String(record?.paymentTermType || (Number(record?.creditDays || 0) ? 'CREDIT' : 'CASH')).toUpperCase();
    return `<form class="tt-form" id="ttSodaForm">
      <div class="tt-form-grid">
        <label>Soda No.<input value="${value('sodaNo', sodaData.nextSodaNo || 'Generated automatically')}" readonly tabindex="-1"></label>
        <label>Soda Date<input id="ttSdDate" type="date" value="${value('sodaDate', today())}" required></label>
        <label>Commodity<select id="ttSdCommodity"><option value="RICE"${commodity==='RICE'?' selected':''}>Rice</option><option value="CORN"${commodity==='CORN'?' selected':''}>Corn / Makai</option><option value="SESAME"${commodity==='SESAME'?' selected':''}>Sesame</option></select></label>
        <label>Variety / Type<input id="ttSdVariety" value="${value('variety')}" placeholder="Type 1 or 2 letters" required></label>
        <label>Broker<input id="ttSdBroker" value="${value('broker')}" placeholder="Type 1 or 2 letters" required></label>
        <label>Supplier / Party<input id="ttSdParty" value="${value('party')}" placeholder="If different from broker"></label>
        <label>Mill / Location<input id="ttSdLocation" value="${value('location')}" placeholder="From Mills & Locations Master"></label>
        <label>Expected Trucks<input id="ttSdTrucks" inputmode="numeric" value="${value('expectedTrucks')}"></label>
        <label>Minimum Quantity (MT)<input id="ttSdMin" inputmode="decimal" value="${record ? value('qtyFromMT', Number(record.qtyFromKg || 0) / 1000 || '') : ''}"></label>
        <label>Maximum Quantity (MT)<input id="ttSdMax" inputmode="decimal" value="${record ? value('qtyToMT', Number(record.qtyToKg || 0) / 1000 || '') : ''}"></label>
        <label>Rate<input id="ttSdRate" inputmode="decimal" value="${value('rate', record?.ratePerKg || '')}" required></label>
        <label>Rate Unit<select id="ttSdUnit"><option value="KG"${(record?.rateUnit||'KG')==='KG'?' selected':''}>Per kg</option><option value="MAUND"${record?.rateUnit==='MAUND'?' selected':''}>Per maund (40 kg)</option></select></label>
        <label>Terms<select id="ttSdTerms"><option value="CASH"${credit==='CASH'?' selected':''}>Cash</option><option value="CREDIT"${credit==='CREDIT'?' selected':''}>Credit</option></select></label>
        <label>Credit Days<input id="ttSdCredit" inputmode="numeric" value="${value('creditDays', credit==='CREDIT'?'':'')}" ${credit==='CASH'?'disabled':''}></label>
        <label>Arrival Due Date<input id="ttSdDue" type="date" value="${value('arrivalDueDate', record?.deliveryDeadline || '')}" required></label>
        <label class="wide">Terms / Conditions<input id="ttSdConditions" value="${value('terms')}"></label>
        <label class="wide">Remarks<textarea id="ttSdRemarks">${value('remarks')}</textarea></label>
        ${record ? '<label class="wide">Reason for Amendment<textarea id="ttSdReason" placeholder="Required. This is stored with the old and new values in the audit log." required></textarea></label>' : ''}
      </div>
      <div class="tt-treatment"><strong>Accounting treatment before posting</strong><div class="tt-note">Saving a Soda creates an open purchase commitment only. It does not create a General Ledger entry.</div><div class="tt-treatment-row"><span>Later</span><span>Approved arrival bill: Inventory / Purchase</span><b>Debit</b></div><div class="tt-treatment-row"><span>Later</span><span>Broker / Supplier Payable</span><b>Credit</b></div></div>
      <div class="tt-form-actions"><button type="button" class="btn" id="ttSdReset">Cancel</button><button type="submit" class="btn green">${record ? 'Save Amendment' : 'Save Soda'}</button></div>
    </form>`;
  }

  function suggestedDue(commodity, date) {
    const days = commodity === 'RICE' ? 8 : commodity === 'CORN' ? 10 : 0;
    if (!date || !days) return '';
    const d = new Date(date + 'T12:00:00Z'); d.setUTCDate(d.getUTCDate() + days);
    return d.toISOString().slice(0, 10);
  }

  function bindSodaForm(host, record = null) {
    const form = q('#ttSodaForm', host);
    const terms = q('#ttSdTerms', form), days = q('#ttSdCredit', form);
    terms.onchange = () => { days.disabled = terms.value === 'CASH'; if (terms.value === 'CASH') days.value = ''; };
    const setDue = () => { if (!record || !q('#ttSdDue', form).value) q('#ttSdDue', form).value = suggestedDue(q('#ttSdCommodity', form).value, q('#ttSdDate', form).value); };
    q('#ttSdCommodity', form).onchange = setDue; q('#ttSdDate', form).onchange = setDue; setDue();
    q('#ttSdReset', form).onclick = () => record ? renderSodaSearch(host) : q('.tt-window-close', host).click();
    form.onsubmit = async event => {
      event.preventDefault();
      const payload = {
        action: record ? 'amend' : 'create', id:record?.id || '', csrf:access.csrf, entity:entity(),
        sodaDate:q('#ttSdDate', form).value, commodity:q('#ttSdCommodity', form).value,
        variety:q('#ttSdVariety', form).value.trim(), broker:q('#ttSdBroker', form).value.trim(), party:q('#ttSdParty', form).value.trim(), location:q('#ttSdLocation', form).value.trim(),
        expectedTrucks:q('#ttSdTrucks', form).value.trim(), qtyFromMT:q('#ttSdMin', form).value.trim(), qtyToMT:q('#ttSdMax', form).value.trim(),
        rate:q('#ttSdRate', form).value.trim(), rateUnit:q('#ttSdUnit', form).value, paymentTermType:terms.value, creditDays:days.value.trim(), arrivalDueDate:q('#ttSdDue', form).value,
        terms:q('#ttSdConditions', form).value.trim(), remarks:q('#ttSdRemarks', form).value.trim(), reason:q('#ttSdReason', form)?.value.trim() || ''
      };
      const button = q('[type="submit"]', form); button.disabled = true;
      try {
        const result = await json(api, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload)});
        sodaData = result;
        alert(record ? `Soda ${record.sodaNo} amended. The change and reason were added to its audit history.` : `Soda ${result.created?.sodaNo || 'number'} saved. No ledger entry was posted.`);
        if (record) renderSodaSearch(host); else q('.tt-window-close', host).click();
      } catch (error) { alert(error.message); button.disabled = false; }
    };
  }

  function renderSodaSearch(host, term = '') {
    const body = q('.tt-window-body', host);
    const needle = term.trim().toLowerCase();
    const rows = (sodaData.sodas || []).filter(row => !needle || [row.sodaNo,row.broker,row.party,row.commodity,row.variety,row.location,row.status,row.sodaDate].some(value => String(value || '').toLowerCase().includes(needle)));
    body.innerHTML = `<div class="tt-modebar"><button data-soda-mode="new">New Soda</button><button class="active" data-soda-mode="search">Search / Amend Soda</button></div><div class="tt-searchbar"><input id="ttSodaSearch" value="${esc(term)}" placeholder="Soda no, broker, supplier, commodity, variety, mill or date"><button type="button">Search</button></div><div class="tt-records"><div class="tableWrap"><table><thead><tr><th>Soda</th><th>Date</th><th>Commodity</th><th>Broker / Supplier</th><th>Quantity</th><th>Rate</th><th>Status</th><th></th></tr></thead><tbody>${rows.length ? rows.map(row => `<tr><td><b>${esc(row.sodaNo)}</b></td><td>${esc(row.sodaDate)}</td><td>${esc(row.commodity)}<br><small>${esc(row.variety)}</small></td><td>${esc(row.broker)}<br><small>${esc(row.party || '')}</small></td><td>${Number(row.qtyFromKg||0)?money(Number(row.qtyFromKg)/1000)+'–'+money(Number(row.qtyToKg||row.qtyFromKg)/1000)+' MT':''}${row.expectedTrucks?'<br>'+esc(row.expectedTrucks)+' trucks':''}</td><td>${money(row.rate ?? row.ratePerKg)} / ${esc(row.rateUnit || 'KG')}</td><td>${esc(row.calculatedStatus || row.status)}</td><td><button type="button" class="btn" data-soda-edit="${esc(row.id)}">View / Amend</button></td></tr>`).join('') : '<tr><td colspan="8">No matching Soda.</td></tr>'}</tbody></table></div></div>`;
    q('[data-soda-mode="new"]', body).onclick = () => renderSodaNew(host);
    const search = () => renderSodaSearch(host, q('#ttSodaSearch', body).value);
    q('.tt-searchbar button', body).onclick = search;
    q('#ttSodaSearch', body).onkeydown = event => { if (event.key === 'Enter') search(); };
    qa('[data-soda-edit]', body).forEach(button => button.onclick = () => {
      activeSoda = (sodaData.sodas || []).find(row => row.id === button.dataset.sodaEdit);
      body.innerHTML = `<div class="tt-modebar"><button data-back-soda>← Search Sodas</button></div>${sodaForm(activeSoda)}${activeSoda.audit?.length ? `<div class="tt-record-card"><b>Amendment history</b><pre>${esc(JSON.stringify(activeSoda.audit, null, 2))}</pre></div>` : ''}`;
      q('[data-back-soda]', body).onclick = () => renderSodaSearch(host);
      bindSodaForm(host, activeSoda);
    });
  }

  function renderSodaNew(host) {
    const body = q('.tt-window-body', host);
    body.innerHTML = `<div class="tt-modebar"><button class="active" data-soda-mode="new">New Soda</button><button data-soda-mode="search">Search / Amend Soda</button></div>${sodaForm()}`;
    q('[data-soda-mode="search"]', body).onclick = () => renderSodaSearch(host);
    bindSodaForm(host);
  }

  async function openSoda() {
    const host = layer('ttSodaLayer', 'Soda Centre');
    q('.tt-window-body', host).innerHTML = '<div class="tt-form">Loading Sodas…</div>';
    try { await loadSodas(); renderSodaNew(host); } catch (error) { q('.tt-window-body', host).innerHTML = `<div class="tt-form">${esc(error.message)}</div>`; }
  }

  async function runSearch(host, term) {
    const results = q('#ttSearchResults', host);
    if (!term.trim()) { results.innerHTML = '<div class="tt-record-card">Type a voucher, bill, Soda, truck, cheque, shipment, container, B/L, party, date, amount or narration.</div>'; return; }
    results.innerHTML = '<div class="tt-record-card">Searching…</div>';
    try {
      const data = await json(`${searchApi}?entity=${encodeURIComponent(entity())}&q=${encodeURIComponent(term.trim())}`);
      results.innerHTML = data.results.length ? data.results.map((record, i) => `<article class="tt-record-card"><b>${esc(record.title)}</b><div class="tt-note">${esc(record.type)} · ${esc(record.date || '')} · ${esc(record.party || '')} · ${esc(record.amount || '')}</div><div class="tt-record-actions"><button class="btn" data-view-record="${i}">View full record</button>${record.printable ? `<button class="btn" data-print-record="${i}">Print Voucher</button>` : ''}</div><div data-record-detail="${i}" hidden><pre>${esc(JSON.stringify(record.data, null, 2))}</pre></div></article>`).join('') : '<div class="tt-record-card">No matching previous record.</div>';
      qa('[data-view-record]', results).forEach(button => button.onclick = () => { const detail=q(`[data-record-detail="${button.dataset.viewRecord}"]`, results); detail.hidden=!detail.hidden; button.textContent=detail.hidden?'View full record':'Hide details'; });
      qa('[data-print-record]', results).forEach(button => button.onclick = () => printVoucher(data.results[Number(button.dataset.printRecord)]));
    } catch (error) { results.innerHTML = `<div class="tt-record-card">${esc(error.message)}</div>`; }
  }

  function openSearch(initial = '') {
    const host = layer('ttSearchLayer', 'Search Previous Accounts Entry');
    q('.tt-window-body', host).innerHTML = `<div class="tt-searchbar"><input id="ttUniversalSearch" value="${esc(typeof initial === 'string' ? initial : '')}" autofocus placeholder="Voucher, bill, Soda, truck, cheque, shipment, container, B/L, party, date or amount"><button type="button">Search</button></div><div id="ttSearchResults"></div>`;
    const go = () => runSearch(host, q('#ttUniversalSearch', host).value);
    q('.tt-searchbar button', host).onclick = go;
    q('#ttUniversalSearch', host).onkeydown = event => { if (event.key === 'Enter') go(); };
    if (typeof initial === 'string' && initial.trim()) go(); else runSearch(host, '');
  }

  function printVoucher(record) {
    const data = record?.data || {};
    const number = data.voucherNo || data.journalId || data.id || record.title;
    const w = window.open('', '_blank', 'noopener,noreferrer');
    if (!w) return alert('Allow popups to print the voucher.');
    w.document.write(`<!doctype html><title>${esc(number)}</title><style>body{font:12px Arial;padding:32px;color:#111}h1,h2{text-align:center;margin:3px}.meta{display:flex;justify-content:space-between;margin:24px 0 10px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #222;padding:7px;text-align:left}pre{white-space:pre-wrap}.sign{display:grid;grid-template-columns:repeat(4,1fr);gap:35px;margin-top:70px}.sign div{border-top:1px solid #222;text-align:center;padding-top:5px}@media print{button{display:none}}</style><h1>${esc(q('.entityBtn.active strong')?.textContent || entity())}</h1><h2>${esc(record.type || 'ACCOUNTING VOUCHER')}</h2><div class="meta"><b>Voucher No: ${esc(number)}</b><b>Date: ${esc(record.date || data.date || '')}</b></div><table><tr><th>Account / Party</th><th>Reference</th><th>Debit</th><th>Credit</th></tr><tr><td>${esc(record.party || data.party || data.broker || '')}</td><td>${esc(data.billNo || data.reference || data.sodaNo || '')}</td><td>${esc(data.totalDebit || data.amount || '')}</td><td>${esc(data.totalCredit || data.amount || '')}</td></tr></table><h3>Narration</h3><p>${esc(data.narration || data.remarks || record.title)}</p><h3>Linked details</h3><pre>${esc(JSON.stringify(data, null, 2))}</pre><div class="sign"><div>Prepared By</div><div>Checked By</div><div>Approved By</div><div>Received By</div></div><button onclick="print()">Print</button>`);
    w.document.close();
  }

  function openShipmentChooser() {
    const host = layer('ttShipmentLayer', 'Export Shipment Bills');
    const body = q('.tt-window-body', host);
    const choices = [
      {title:'Freight', native:'freight'}, {title:'Transport', native:'transport'}, {title:'Clearing', native:'services', service:'CLEARING'}, {title:'Fumigation', native:'services', service:'FUMIGATION'}, {title:'Inspection', native:'services', service:'INSPECTION'}
    ];
    body.innerHTML = `<div class="tt-form"><div class="tt-note">Each bill starts by searching our invoice, container, B/L, vessel, line, Loading Programme, port of discharge, lot or shipment. The selected shipment supplies the known details for confirmation.</div><div class="tt-action-list">${choices.map((x,i)=>`<button class="tt-action" data-shipment-kind="${i}"><span class="tt-action-mark">${i+1}</span><span><b>${x.title}</b><small>Search shipment, confirm linked details, review Debit/Credit treatment, then post vendor bill.</small></span></button>`).join('')}</div></div>`;
    qa('[data-shipment-kind]', body).forEach(button => button.onclick = async () => {
      q('.tt-window-close', host).click();
      const choice = choices[Number(button.dataset.shipmentKind)];
      await launch(choice);
      if (choice.service) {
        let select = null;
        for (let i=0;i<30&&!select;i+=1) { select=q('#svKind'); if(!select) await new Promise(r=>setTimeout(r,40)); }
        if (select) { select.value=choice.service; select.dispatchEvent(new Event('change',{bubbles:true})); }
      }
    });
  }

  function installPreviousSearch() {
    const add = workspace => {
      if (!workspace?.classList.contains('active')) return;
      const head = q(':scope > .panelHead', workspace);
      if (!head || q('.tt-prev-search', head)) return;
      const button = document.createElement('button');
      button.type = 'button'; button.className = 'btn tt-prev-search'; button.textContent = 'Search Previous'; button.onclick = openSearch;
      head.appendChild(button);
    };
    qa('.workspace.active').forEach(add);
    const refresh = () => { qa('.workspace.active').forEach(add); ensureLiveTreatments(); };
    new MutationObserver(refresh).observe(document.body, {subtree:true, childList:true, attributes:true, attributeFilter:['class']});
  }

  function ensureLiveTreatments() {
    const amount = q('#evPayAmount');
    const account = q('#evPayAccount');
    const treatment = q('#evTreatment');
    const save = q('#evPayUtility');
    if (!amount || !account || !treatment || !save || q('#ttUtilityTreatment')) return;
    const box = document.createElement('div');
    box.id = 'ttUtilityTreatment';
    box.className = 'tt-treatment';
    save.closest('.tte-actions')?.insertAdjacentElement('beforebegin', box);
    const render = () => {
      const value = Number(amount.value || 0);
      const debit = treatment.value === 'FAMILY_ALLOCATION'
        ? (q('#evPerson')?.selectedOptions?.[0]?.textContent || 'Selected personal / family account')
        : (q('#evPayType')?.selectedOptions?.[0]?.textContent || 'Utility Expense');
      const credit = account.selectedOptions?.[0]?.textContent || 'Select bank or cash account';
      box.innerHTML = `<strong>Accounting treatment before posting</strong><div class="tt-treatment-row"><span>Debit</span><span>${esc(debit)}</span><b>Rs ${money(value)}</b></div><div class="tt-treatment-row"><span>Credit</span><span>${esc(credit)}</span><b>Rs ${money(value)}</b></div><div class="tt-treatment-row tt-treatment-total"><span>Total</span><span>Debit Rs ${money(value)} · Credit Rs ${money(value)}</span><b>${value > 0 && account.value ? 'Balanced' : 'Complete form'}</b></div>`;
    };
    [amount, account, treatment, q('#evPerson'), q('#evPayType')].filter(Boolean).forEach(control => { control.addEventListener('input', render); control.addEventListener('change', render); });
    render();
  }

  function init() {
    installStyle();
    buildTopbar();
    buildDesk();
    installPreviousSearch();
    ensureLiveTreatments();
    document.addEventListener('click', event => { if (event.target.closest('.entityBtn')) setTimeout(refreshEntityLabels, 0); }, true);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once:true}); else init();
  window.TT_ACCOUNTING_DESK = {installed:true, openSoda, openSearch, showArea, printVoucher};
})();
