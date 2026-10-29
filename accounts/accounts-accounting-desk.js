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
  const displayRef = value => window.TT_ALL_LEDGERS?.displayRef?.(value) || String(value || '').replace(/^AUTO-(\d{4})-(\d+)$/, (_, year, n) => `POST-${year}-${(n.replace(/^0+/, '') || '0').padStart(5, '0')}`);
  const api = '../api/purchase_sodas.php';
  const searchApi = '../api/accounts_search.php';
  let sodaData = {sodas:[], nextSodaNo:'Generated automatically'};
  let activeSoda = null, launchGeneration = 0;
  document.addEventListener('click',event=>{if(event.target.closest?.('.entityBtn,[data-tt-entity],[data-tt-area],.tt-back-areas,[data-back],.tt-clean-close')){launchGeneration++;transitionGeneration++;q('#ttDeskTransition')?.remove();document.body.classList.remove('tt-workspace-opening','tt-arrival-opening');}},true);

  const pakistanAreas = [
    {key:'exports', glyph:'⇄', title:'Export Receipts & Payments', note:'Every export receipt, document and shipment expense', actions:[
      {title:'Customer / Export Receivables', note:'Group customer invoices, receipts and outstanding balances', special:'customer-receivables'},
      {title:'Bank Receipt / Credit Advice', note:'One linked form for the advice, outstanding item, bank charges, WHT and Advance WHT; FI remains in Exports', special:'export-receipt'},
      {title:'Freight Forwarder / Shipping', note:'Agreed freight and shipment-linked invoice', special:'freight-desk'},
      {title:'Clearing Agent', note:'GD, job and shipment-linked clearing bill', special:'supplier-bills', billKind:'clearing'},
      {title:'Bags Bill', note:'Export bag purchases, receipts, stock and sales-tax working', special:'supplier-bills',billKind:'bags',native:'purchases', then:'[data-purchase="bags"]', bagSync:true},
      {title:'Transport Bill', note:'Loading Programme and route-linked transport bill', special:'supplier-bills', billKind:'transport'},
      {title:'Fumigation Bill', note:'Shipment-linked fumigation and treatment bill', special:'supplier-bills', billKind:'fumigation'},
      {title:'Inspection Bill', note:'Shipment and certificate-linked inspection bill', special:'supplier-bills', billKind:'inspection'},
      {title:'Other Export Expense', note:'Export supplies and costs not charged to one shipment', special:'other-export-expense'}
    ]},
    {key:'commodity', glyph:'▣', title:'Local Purchases & Sales', note:'Soda through final bill, payment, sale and receipt', actions:[
      {title:'Soda Centre', note:'Create, search, amend or delete an unlinked Soda', special:'soda'},
      {title:'Bill Posting', note:'Broker or Supplier → Soda → saved/printed Pohanch → final bill', special:'arrival-bills', native:'purchases', then:'[data-purchase="commodity"]'},
      {title:'Supplier/Broker Payment', note:'Plan commodity payments or post an on-account payment', special:'payment-plan'},
      {title:'Pay Broker', note:'Independent brokerage outside purchase bills', special:'broker-payment-plan'},
      {title:'Fellow-exporter SODAs & Accounts', note:'Local ex-mill sales, customer advances and shipment partnership settlements', special:'exporter-sodas'},
      {title:'Local Sales & Receipts', note:'Mill sale approvals and linked receipts awaiting Accounts action', native:'receivables', find:'Local'}
    ]},
    {key:'bank', glyph:'▦', title:'Bank & Cash', note:'Bank payments, receipts, transfers and reconciliation', actions:[
      {title:'Forward Booking',note:'USD/PKR calendar-day contracts, utilisation and expiry settlement',special:'forward-booking'},
      {title:'Bank Entry', note:'Bank payment first; pay parties or expenses, and record other bank entries', special:'bank-entry'},
      {title:'Pay WHT',note:'Rent, brokerage or other withholding against a challan',special:'wht-payment'},
      {title:'Inter Account Transfer', note:'Move PKR between company accounts or to a personal account with a reason', special:'internal-bank-transfer'},
      {title:'Foreign Retention Account', note:'Settle foreign commissions and other linked outward remittances', special:'retention-remittance'},
      {title:'Bank Finance', note:'Drawdown, principal repayment and markup', special:'bank-finance'},
      {title:'Bank Ledgers by Party',special:'all-ledgers',ledgerCategory:'bank'},{title:'Bank Accounts & Balances', native:'bank'}, {title:'Bank Reconciliation', native:'reconciliation'}
    ]},
    {key:'petty-cash',title:'Petty Cash',actions:[{title:'Petty Cash',special:'petty-cash'}]},
    {key:'routine', glyph:'◇', title:'Expenses', note:'Pay expenses, utilities, cards or salaries', actions:[
      {title:'Pay Expense', note:'Pay anyone; combine several expenses in one cheque or payment', native:'expenses', special:'expense-pay'},

      {title:'Mill Bill Review', note:'Record verified Mill liabilities and follow linked supplier payments', native:'expenses', special:'mill-liabilities'},
      {title:'Salaries & Staff', note:'Salary advance and monthly salary preparation', native:'expenses', special:'expense-salary'}
    ]},
    {key:'ledgers', glyph:'L', title:'Ledgers', note:'Account and party ledgers, and every posted voucher', actions:[{title:'Ledger',special:'all-ledgers',ledgerCategory:'party'},{title:'Post ID Register',special:'post-ledger'},{title:'Trial Balance',native:'reports',find:'Trial Balance'},{title:'Chart of Accounts & Details',special:'account-heads'},{title:'Subaccounts & Details',special:'subaccounts'},{title:'Forward Booking Register',special:'forward-register'}]},
    {key:'reports', glyph:'▤', title:'Reports', note:'Financial, tax, party, commodity and shipment reports', actions:[
      {title:'Production & Recovery',special:'production-recovery'}, {title:'Bag Orders, Receipts & Tax',special:'bag-report'},
      {title:'Sales Tax & Refund', note:'Monthly return and refund files with payment tracing', special:'sales-tax'},
      {title:'Expenses by Type / Recipient',special:'expense-report'}, {title:'Vehicle / Asset Costs',special:'vehicle-report'}, {title:'Withholding Tax Report', special:'withholding-report'}, {title:'Trial Balance', native:'reports', find:'Trial Balance'}, {title:'Profit & Loss', native:'reports', find:'Profit'},
      {title:'Balance Sheet', native:'reports', find:'Balance Sheet'}, {title:'Receivables / Payables', native:'reports', find:'Receivables'},
      {title:'Shipment Profitability', native:'reports', find:'Shipment'},
      {title:'Commodity & Local Sales', native:'reports', find:'Commodity'}
    ]}
  ];
  pakistanAreas.find(area=>area.key==='commodity').actions.push({title:'Other Purchases',note:'Assets and consumables outside commodity Sodas',special:'supplier-bills',billKind:'other',native:'purchases',then:'[data-purchase="other"]'});
  const tgAreas = [
    {key:'tg-receipts', glyph:'↓', title:'Customer Receipts', note:'Receive money and allocate it to the correct TG customer', actions:[
      {title:'Customer Receipt', special:'tg-customer-receipt'}, {title:'Customer / Export Receivables', special:'customer-receivables'}
    ]},
    {key:'tg-payments', glyph:'↑', title:'Party Payments', note:'Party liabilities and payments', actions:[
      {title:'Post Bill', native:'payables'}, {title:'Payment', special:'bill-payment'}
    ]},
    {key:'tg-bank', glyph:'▦', title:'Bank & Local Expenses', note:'Bank transfers, payments and local operating expense', actions:[
      {title:'Inter Account Transfer', note:'Choose the source and destination; currency direction and the TG Master rate are automatic', special:'internal-bank-transfer'},
      {title:'Bank Entry', special:'bank-entry'}, {title:'Review TG Remittances', special:'tg-remittances'}, {title:'Bank Finance', special:'bank-finance'}, {title:'Bank Ledgers by Party',special:'all-ledgers',ledgerCategory:'bank'},{title:'Bank Accounts & Balances', native:'bank'}, {title:'Pay Expense', special:'expense-pay'},
      {title:'Bank Reconciliation', native:'reconciliation'}
    ]},
    {key:'tg-ledgers', glyph:'L', title:'Ledgers', note:'Account and party ledgers, and every posted voucher', actions:[{title:'Ledger',special:'all-ledgers',ledgerCategory:'party'},{title:'Post ID Register',special:'post-ledger'},{title:'Trial Balance',native:'reports',find:'Trial Balance'},{title:'Chart of Accounts & Details',special:'account-heads'},{title:'Subaccounts & Details',special:'subaccounts'}]},
    {key:'tg-reports', glyph:'▤', title:'Reports', note:'TG balances and financial reports', actions:[
      {title:'Expenses by Type / Recipient',special:'expense-report'}, {title:'Vehicle / Asset Costs',special:'vehicle-report'}, {title:'Withholding Tax Report', special:'withholding-report'}, {title:'Trial Balance', native:'reports', find:'Trial Balance'}, {title:'Profit & Loss', native:'reports', find:'Profit'},
      {title:'Balance Sheet', native:'reports', find:'Balance Sheet'}, {title:'Receivables / Payables', native:'reports', find:'Receivables'}
    ]}
  ];
  if(access.canInventoryReconciliation) pakistanAreas.find(area=>area.key==='reports').actions.push({title:'Ghati & Stock Reconciliation',note:'Accounts / Directors only; never adds stock or another purchase',special:'stock-reconciliation'});
  for(const areas of [pakistanAreas,tgAreas])for(const area of areas)if(area.key.endsWith('reports'))area.actions=area.actions.filter(a=>!(a.native==='reports'&&a.find==='Trial Balance')&&!(areas===tgAreas&&a.special==='withholding-report'));
  if(access.canGroupDaily)for(const areas of [pakistanAreas,tgAreas])areas.find(area=>area.key.endsWith('reports')).actions.unshift({title:'ARP Morning Overview',note:'Group balances, SODAs, exports, loading and importer receivables',special:'arp-overview'},{title:'Shipment Profit & Loss',note:'Contract realization, linked expenses and optional goods cost',special:'shipment-statement'},{title:'Estimated Bills',note:'Shipment costs, issued Pohanch and completed-loading freight awaiting invoices',special:'estimated-bills'},{title:'Cash Flow',note:'Cash, balances and previous-position comparison',special:'cash-flow'},{title:'Group Daily Position',note:'Shared bank balances, stock and original purchase/sale commitments',special:'group-daily'});
  if(access.canGroupZakat)for(const areas of [pakistanAreas,tgAreas])areas.find(area=>area.key.endsWith('reports')).actions.push({title:'Annual Zakat',note:'Yearly assessment, TTI control ledger, actual payments and carry-forward',special:'group-zakat'});

  const assetPermission=access.super||access.permissions==='all'||(Array.isArray(access.permissions)?access.permissions.includes('View'):(access.permissions?.assets||[]).includes('View'));
  if(assetPermission)for(const areas of [pakistanAreas,tgAreas])areas.splice(3,0,{key:'assets',title:'Assets & Investments',note:'Properties, vehicles, company stockbrokers and share investments',actions:[]});

  const areaLabels = {
    bank:['BANK',''], 'petty-cash':['PETTY CASH',''], commodity:['PURCHASES','local sales'],
    routine:['EXPENSES','salary/utility/credit cards'], exports:['EXPORT','receipts & expenses'],
    assets:['ASSETS & INVESTMENTS',''], ledgers:['LEDGERS',''], reports:['REPORTS',''],
    'tg-bank':['BANK',''], 'tg-routine':['EXPENSES',''], 'tg-receipts':['EXPORT','receipts & expenses'],
    'tg-payments':['PURCHASES','party bills & payments'], 'tg-ledgers':['LEDGERS',''], 'tg-reports':['REPORTS','']
  };
  for (const areas of [pakistanAreas,tgAreas]) {
    for (const area of areas) {
      if (area.key==='exports'||area.key==='tg-payments') area.actions=area.actions.filter(a=>a.special!=='carry-forward'&&a.special!=='customer-receivables');
      if (area.key==='bank'||area.key==='tg-bank') {
        const order=['bank-entry','wht-payment','internal-bank-transfer','bank-finance','forward-booking','retention-remittance','reconciliation'];
        area.actions=order.map(key=>area.actions.find(a=>(a.special||a.native)===key)).filter(Boolean);
        const names=['BANK ENTRY','PAY WHT','INTER ACCOUNT TRANSFER','BANK FINANCING','FORWARD BOOKING','RETENTION ACCOUNT','BANK RECONCILIATION'];
        area.actions.forEach(a=>a.title=names[order.indexOf(a.special||a.native)]);
      }
      if (area.key.endsWith('reports')) area.actions.push({title:'CHANGE LOGS',special:'change-logs'});
      if (areaLabels[area.key]) area.title=areaLabels[area.key][0]+(areaLabels[area.key][1]?' ('+areaLabels[area.key][1]+')':'');
    }
  }
  // Keep TG expense and remittance workflows accessible in their relevant menus.
  tgAreas.push({key:'tg-routine',title:'EXPENSES',actions:[{title:'Pay Expense',special:'expense-pay'}]});
  tgAreas.find(a=>a.key==='tg-receipts').actions=tgAreas.find(a=>a.key==='tg-receipts').actions.filter(a=>a.special!=='customer-receivables');
  tgAreas.find(a=>a.key==='tg-receipts').actions.push({title:'Review TG Remittances',special:'tg-remittances'});
  const currentAreas = () => {
    const areas=entity()==='TG'?tgAreas:pakistanAreas;
    const order=entity()==='TG'?['tg-bank','tg-payments','tg-routine','tg-receipts','jv','assets','tg-ledgers','tg-reports']:['bank','petty-cash','commodity','routine','exports','jv','assets','ledgers','reports'];
    return order.map(key=>key==='jv'?{key:'jv',title:'J.V',actions:[]}:areas.find(a=>a.key===key)).filter(Boolean);
  };

  for(const [areas,key] of [[pakistanAreas,'bank'],[tgAreas,'tg-bank']]) areas.find(a=>a.key===key)?.actions.push({title:'Third-party Payments Register',note:'Information only: received from, paid to, date and amount',special:'third-party-register'});
  function installStyle() {
    if (q('#ttAccountingDeskStyle')) return;
    const style = document.createElement('style');
    style.id = 'ttAccountingDeskStyle';
    style.textContent = `
      .tt-shipment-bill-header{grid-template-columns:repeat(3,minmax(0,1fr))!important;align-items:end!important}.tt-shipment-bill-header .tt-shipment-vendor{grid-column:1/-1}.tt-shipment-bill-header label input{height:44px!important;min-height:44px!important;margin:0!important;box-sizing:border-box}.tt-shipment-bill-header label{display:grid;grid-template-rows:minmax(24px,auto) 44px;gap:6px}@media(max-width:600px){.tt-shipment-bill-header{grid-template-columns:1fr!important}}
      body{background:#edf1f4!important;color:#172433}
      body.tt-workspace-opening .workspace.active{visibility:hidden!important}
      #ttExpenseFilters[hidden]{display:none!important}
      body.tt-arrival-opening .workspace.active{visibility:hidden!important}body.tt-arrival-opening:after{content:'Loading Bill Posting…';position:fixed;inset:62px 0 0;display:grid;place-items:center;background:#edf1f4;color:#173c63;font-weight:800;z-index:450}
      .topbar{height:62px!important;padding:0 22px!important}.brand{min-width:210px!important}.crumb{opacity:.72}
      #ttConsoleTop,#ttMasterTop,#ttChangeCompanyDesk{border:1px solid #ffffff32;background:#ffffff12;color:#fff;border-radius:9px;height:38px;padding:0 13px;font-weight:800;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center}
      #ttMasterTop{flex:0 0 38px;width:38px;min-width:38px;max-width:38px;height:38px;min-height:38px;max-height:38px;padding:0;display:inline-flex;align-items:center;justify-content:center;line-height:1;font-size:18px;font-weight:900;border-radius:9px;white-space:nowrap}
      #ttCompanyMenu{position:fixed;right:66px;top:57px;z-index:510;width:min(580px,calc(100vw - 24px));display:grid;grid-template-columns:repeat(3,1fr);gap:8px;padding:10px;background:#fff;border:1px solid #dbe2ea;border-radius:12px;box-shadow:0 18px 48px #0b203840}
      #ttCompanyMenu[hidden],.tt-layer [hidden]{display:none!important}.tt-company-choice{border:1px solid #dbe2ea;background:#fff;border-radius:10px;padding:11px;text-align:left;cursor:pointer}.tt-company-choice.active{border-color:#173c63;box-shadow:inset 3px 0 #173c63}.tt-company-choice b,.tt-company-choice small{display:block}.tt-company-choice small{margin-top:3px;color:#6b7887}
      .shell{max-width:1500px!important;padding:18px 22px!important}#entityHome>.entityHero,#entityHome>.notice,#entityHome>#homeGrid,#entityHome>.panel{display:none!important}
      #ttAccountingDesk{min-height:calc(100vh - 98px)}
      .tt-desk-main>section{background:#fff;border:1px solid #dfe5ea;border-radius:12px;box-shadow:0 3px 14px rgba(18,40,62,.05)}
      .tt-desk-main{min-width:0}.tt-desk-main>section{margin-bottom:14px}.tt-desk-heading{padding:18px 20px;display:flex;gap:14px;align-items:center}.tt-desk-heading>div{flex:1}.tt-desk-heading h1{font-size:22px;margin:0}.tt-desk-heading p{margin:4px 0 0;color:#697686;font-size:16px}.tt-search-main{width:min(360px,42vw);border:1px solid #cbd5df;border-radius:9px;padding:10px 12px;background:#f8fafb}
      .tt-position{display:grid;grid-template-columns:repeat(4,1fr);border-top:1px solid #e7ebef}.tt-summary{position:relative;border:0;border-right:1px solid #e7ebef;background:#fff;padding:14px 18px;text-align:left;cursor:pointer;min-width:0}.tt-summary:last-child{border-right:0}.tt-summary small,.tt-summary b,.tt-summary em{display:block}.tt-summary small{color:#75818e;font-size:14px;text-transform:uppercase;letter-spacing:.5px}.tt-summary b{font-size:15px;margin-top:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.tt-summary em{color:#87919b;font-size:14px;font-style:normal;margin-top:2px}.tt-summary-pop{display:none;position:absolute;z-index:25;top:calc(100% - 3px);left:10px;width:300px;max-height:260px;overflow:auto;background:#fff;border:1px solid #ccd7e0;border-radius:10px;padding:10px;box-shadow:0 16px 38px #14283e35;font-size:15px;white-space:normal}.tt-summary:hover .tt-summary-pop,.tt-summary:focus .tt-summary-pop{display:block}.tt-summary-pop div{padding:6px 3px;border-bottom:1px solid #edf0f2}.tt-summary-pop div:last-child{border:0}
      .tt-area-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;padding:18px}.tt-area-card{min-height:154px;border:1px solid #d9e2e9;border-radius:13px;background:#fff;padding:18px;text-align:left;cursor:pointer;box-shadow:0 5px 18px #11283d0a}.tt-area-card:hover{border-color:#749c89;background:#fbfefc;transform:translateY(-1px)}.tt-area-glyph{width:45px;height:45px;border-radius:12px;background:#e9f1ec;color:#28523d;display:grid;place-items:center;font-size:22px;font-weight:900;margin-bottom:16px}.tt-area-card b{display:block;font-size:21px}.tt-area-card small{display:block;color:#6f7c88;line-height:1.45;margin-top:6px}.tt-home-head{padding:17px 19px;border-bottom:1px solid #e6eaee}.tt-home-head h2{margin:0;font-size:21px}.tt-home-head p{margin:4px 0 0;color:#74808d;font-size:15px}.tt-back-areas{border:1px solid var(--tt-brand-main,#4f7650);background:var(--tt-brand-main,#4f7650);color:#fff;border-radius:8px;min-height:34px;padding:6px 12px;font-size:16px;line-height:1;font-weight:900;letter-spacing:.04em;cursor:pointer;margin-right:12px;white-space:nowrap}.tt-back-areas:hover,.tt-back-areas:focus-visible{background:var(--tt-brand-deep,#2e4527);border-color:var(--tt-brand-deep,#2e4527);color:#fff}
      .tt-work-head{display:flex;align-items:center;padding:15px 18px;border-bottom:1px solid #e6eaee}.tt-work-head h2{margin:0;font-size:16px}.tt-work-head p{margin:3px 0 0;color:#74808d;font-size:15px}.tt-work-head .tt-back-areas{margin-left:0;margin-right:14px}
      .tt-action-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));padding:8px}.tt-action{border:0;background:#fff;padding:13px;border-radius:8px;text-align:left;cursor:pointer;display:flex;gap:12px;align-items:flex-start}.tt-action:hover{background:#f2f6f8}.tt-action-mark{width:30px;height:30px;flex:0 0 30px;border-radius:7px;background:#e8eef3;display:grid;place-items:center;color:#173c63;font-weight:900}.tt-action b,.tt-action small{display:block}.tt-action b{font-size:18px}.tt-action small{color:#75818d;margin-top:3px;line-height:1.35}
      .tt-queue{padding:8px 17px 16px}.tt-queue-row{display:grid;grid-template-columns:110px 1fr auto;gap:14px;padding:10px 0;border-bottom:1px solid #edf0f2;align-items:center}.tt-queue-row:last-child{border:0}.tt-queue-row span{font-size:15px;color:#6e7b87}.tt-queue-row b{font-size:16px}.tt-queue-row button{border:0;background:#edf3f7;color:#173c63;border-radius:7px;padding:7px 10px;font-weight:750;cursor:pointer}
      .workspace.active.tt-clean-modal{top:3vh!important;max-height:94vh!important;border-radius:13px!important;background:#f5f7f9!important}.workspace.tt-clean-modal>.panelHead{border-radius:13px 13px 0 0!important}.workspace.tt-clean-modal .accountPreview{display:block!important;background:#eef5f8!important;border:1px solid #bfd0dc!important}.workspace.tt-clean-modal .infoCard{display:block!important}.workspace.tt-clean-modal .split{display:grid!important;grid-template-columns:minmax(0,1fr) 330px!important}.workspace.tt-clean-modal .formCard{background:#fff}
      .tt-prev-search{margin-left:auto!important;white-space:nowrap}.workspace.tt-clean-modal>.panelHead{align-items:center!important}.workspace.tt-clean-modal>.panelHead>div{flex:1}
      .tt-layer{position:fixed;inset:0;z-index:700;background:rgba(9,25,42,.54);display:grid;place-items:center;padding:18px}.tt-layer[hidden]{display:none}.tt-window{width:min(1080px,96vw);max-height:94vh;overflow:auto;background:#f5f7f9;border-radius:14px;box-shadow:0 28px 80px #0006}.tt-window-head{position:sticky;top:0;z-index:3;display:flex;align-items:center;gap:12px;padding:15px 18px;background:#fff;border-bottom:1px solid #dfe5ea}.tt-window-head h2{margin:0;font-size:18px}.tt-window-head span{flex:1}.tt-window-close{border:1px solid #dfc2c0;background:#fff;color:#8c2f2a;border-radius:8px;padding:8px 11px;font-weight:800;cursor:pointer}.tt-window-body{padding:16px}
      .tt-master-control{display:grid;grid-template-columns:minmax(0,1fr) 34px 34px;gap:6px;align-items:end}.tt-master-control>.btn{height:38px;padding:0;font-size:16px}.tt-master-control .tt-search-select{min-width:0}
      .tt-modebar{display:flex;gap:12px;margin-bottom:14px}.tt-modebar button{flex:1;min-height:54px;font-size:18px;border:1px solid #ccd6df;background:#fff;border-radius:8px;padding:9px 13px;font-weight:800;cursor:pointer}.tt-modebar button.active{background:#102a46;color:#fff;border-color:#102a46}
      .tt-form{background:#fff;border:1px solid #dfe5ea;border-radius:11px;padding:15px}.tt-form-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.tt-form-grid .wide{grid-column:span 2}.tt-transport-grid{grid-template-columns:repeat(3,minmax(0,1fr));align-items:end}.tt-transport-grid>label{display:flex;flex-direction:column;gap:5px}.tt-transport-grid input{width:100%;box-sizing:border-box}.tt-form label{font-size:16px;font-weight:700}.tt-form input,.tt-form select,.tt-form textarea{font-size:16px;min-height:42px}.tt-money{text-align:right!important;white-space:nowrap}.tt-records th,.tt-records td{padding:12px;font-size:16px}.tt-bill-grand b{font-size:26px}.tt-form-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:13px}.tt-treatment{margin-top:13px;border:1px solid #b9cedb;background:#eef6fa;border-radius:9px;padding:12px}.tt-treatment>strong{display:block;margin-bottom:7px}.tt-treatment-row{display:grid;grid-template-columns:50px 1fr auto;gap:8px;font-size:16px;padding:4px 0}.tt-treatment-total{border-top:1px solid #cbdbe4;margin-top:5px;padding-top:7px;font-weight:800}.tt-note{font-size:15px;color:#687686;margin-top:7px}
      .tt-records{margin-top:12px;background:#fff;border:1px solid #dfe5ea;border-radius:11px;overflow:hidden}.tt-records table{width:100%}.tt-records button{padding:6px 8px}.tt-searchbar{display:grid;grid-template-columns:1fr auto;gap:8px}.tt-searchbar input{margin:0;padding:11px 12px}.tt-searchbar button{border:0;border-radius:8px;background:#102a46;color:#fff;padding:0 16px;font-weight:800}.tt-record-card{background:#fff;border:1px solid #dfe5ea;border-radius:10px;padding:13px;margin-top:10px}.tt-record-card pre{white-space:pre-wrap;word-break:break-word;background:#f4f6f8;border-radius:8px;padding:10px;max-height:280px;overflow:auto;font-size:15px}.tt-record-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:9px}.tt-tax-filters{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.tt-tax-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:12px}.tt-tax-docs{display:flex;flex-wrap:wrap;gap:6px}.tt-tax-doc{display:inline-flex;align-items:center;gap:5px;border:1px solid #cdd8e1;border-radius:7px;padding:6px 8px;background:#fff;text-decoration:none;color:#173c63;font-size:15px;font-weight:700}.tt-tax-doc.missing{color:#7d8790;background:#f5f7f8}.tt-tax-row td{vertical-align:top}
      @media(max-width:900px){.tt-position{grid-template-columns:repeat(2,1fr)}.tt-area-grid{grid-template-columns:repeat(2,1fr)}.tt-action-list{grid-template-columns:1fr}.workspace.tt-clean-modal .split{grid-template-columns:1fr!important}.tt-form-grid,.tt-tax-filters{grid-template-columns:repeat(2,1fr)}#ttCompanyMenu{grid-template-columns:1fr;right:10px}}
      @media(max-width:560px){.tt-desk-heading{display:block}.tt-search-main{width:100%;margin-top:12px}.tt-position,.tt-form-grid,.tt-tax-filters,.tt-area-grid{grid-template-columns:1fr}.tt-form-grid .wide{grid-column:auto}.tt-queue-row{grid-template-columns:1fr auto}.tt-queue-row span{display:none}.topbar{padding:0 10px!important}.brand{min-width:0!important}.brand>div:last-child{display:none}#ttChangeCompanyDesk{max-width:130px;overflow:hidden;text-overflow:ellipsis}.tt-window-body{padding:10px}}
    `;
    style.textContent += `.tt-area-card,.tt-action{transition:transform .18s ease,box-shadow .18s ease,background .18s ease}.tt-area-card{border-color:var(--tile-border,#d9e2e9);background:linear-gradient(145deg,var(--tile-tint,#fff),#fff 76%)}.tt-area-card .tt-area-glyph{background:var(--tile-icon,#e9f1ec);color:var(--tile-color,#28523d)}.tt-area-card:nth-child(6n+1){--tile-tint:#fff5ef;--tile-icon:#ffe3d0;--tile-color:#ad5d37;--tile-border:#f1d9c8}.tt-area-card:nth-child(6n+2){--tile-tint:#f0f9f5;--tile-icon:#d9f1e3;--tile-color:#287455;--tile-border:#d3e8d9}.tt-area-card:nth-child(6n+3){--tile-tint:#f1f6ff;--tile-icon:#deeaff;--tile-color:#375fa6;--tile-border:#d6e1f4}.tt-area-card:nth-child(6n+4){--tile-tint:#fff9e9;--tile-icon:#ffedbc;--tile-color:#9b7126;--tile-border:#f0e4bf}.tt-area-card:nth-child(6n+5){--tile-tint:#f7f2ff;--tile-icon:#eadffc;--tile-color:#6a50a0;--tile-border:#e5daf3}.tt-area-card:nth-child(6n){--tile-tint:#ecfafb;--tile-icon:#d3f1f4;--tile-color:#237b8c;--tile-border:#cfe9ec}.tt-action-list[data-area-palette] .tt-action{margin:5px;border:1px solid #dfe8eb;background:linear-gradient(100deg,var(--action-tint,#f8fcfd),#fff 62%);border-radius:12px;min-height:88px;align-items:center}.tt-action-list[data-area-palette] .tt-action:hover{box-shadow:0 7px 20px #162f4317;transform:translateY(-1px)}.tt-action-list[data-area-palette] .tt-action-mark{width:44px;height:44px;flex-basis:44px;background:var(--action-icon,#dff2ed);color:var(--action-color,#286e5f);border-radius:12px}.tt-action:nth-child(5n+1){--action-tint:#f0f9f5;--action-icon:#d9f1e3;--action-color:#287455}.tt-action:nth-child(5n+2){--action-tint:#f1f6ff;--action-icon:#deeaff;--action-color:#375fa6}.tt-action:nth-child(5n+3){--action-tint:#fff9ed;--action-icon:#ffedc8;--action-color:#97702a}.tt-action:nth-child(5n+4){--action-tint:#f8f3ff;--action-icon:#eadffc;--action-color:#6a50a0}.tt-action:nth-child(5n){--action-tint:#eefafb;--action-icon:#d3f1f4;--action-color:#237b8c}.tt-action-mark svg{width:24px;height:24px}`;
    style.textContent+='@keyframes ttDuePulse{50%{box-shadow:0 0 0 3px #e5a63788}}.tt-due-alert{animation:ttDuePulse 1.8s ease-in-out infinite}.tt-summary[data-summary="due"] b{font-size:18px;line-height:1.4}';
    style.textContent+=`.tt-inside-area #ttSummaryCards,.tt-inside-area #ttRecentSection,.tt-inside-area #ttPendingWork{display:none!important}.tt-desk-heading{padding:10px 16px;justify-content:flex-end}.tt-recent-scroll{max-height:340px;overflow:auto}.tt-recent-row{display:grid;grid-template-columns:100px 155px minmax(180px,1fr) 150px;align-items:center;gap:12px;width:100%;height:68px;overflow:hidden;border:0;border-bottom:1px solid #e3e9ed;background:white;padding:10px 16px;text-align:left;cursor:pointer}.tt-recent-row:hover,.tt-recent-row:focus-visible{background:#edf5f1}.tt-recent-row small{display:block;color:#657582;white-space:nowrap;text-overflow:ellipsis;overflow:hidden;max-width:100%}.tt-recent-row>span{min-width:0}.tt-recent-row>b:last-child{text-align:right}#ttPendingWork>summary{padding:14px 18px;cursor:pointer;font-weight:800}#ttRecentRefresh{margin-left:auto}@media(max-width:700px){.tt-recent-scroll{max-height:430px}.tt-recent-row{grid-template-columns:90px 1fr;gap:6px;height:86px}.tt-recent-row>b:last-child{text-align:left}}`;
    style.textContent+=`
      #ttAccountingDesk .tt-area-grid{gap:9px;padding:12px}
      #ttAccountingDesk .tt-area-card{min-height:74px;padding:11px 14px;display:flex;align-items:center;gap:12px}
      #ttAccountingDesk .tt-area-glyph{width:34px;height:34px;flex:0 0 34px;margin:0;border-radius:9px}
      #ttAccountingDesk .tt-area-glyph svg{width:23px;height:23px}
      #ttAccountingDesk .tt-area-card b{font-size:16px;line-height:1.25}
      #ttAccountingDesk .tt-area-card small{font-size:12px;margin-top:3px;color:#425561}
      #ttAccountingDesk .tt-action-list[data-area-palette] .tt-action{min-height:66px;padding:10px}
      #ttAccountingDesk .tt-action b{font-size:16px}
      #ttAccountingDesk .tt-action-list[data-area-palette] .tt-action-mark{width:34px;height:34px;flex-basis:34px}
      #ttAccountingDesk .tt-summary{padding:10px 14px}
      #ttAccountingDesk .tt-summary small{font-size:12px}
      #ttAccountingDesk .tt-desk-main>section{margin-bottom:10px}
      .tt-home-activity{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:12px}
      .tt-home-activity>section{min-width:0;background:#fff;border:1px solid #dfe5ea;border-radius:12px;overflow:hidden}
      .tt-home-activity .tt-work-head{min-height:46px;padding:8px 12px}
      .tt-home-activity .tt-recent-scroll,.tt-home-activity .tt-queue{height:clamp(160px,24vh,260px);overflow:auto;overscroll-behavior:contain}
      .tt-home-activity .tt-queue{padding:0 12px}
      .tt-home-activity .tt-recent-row{grid-template-columns:78px 116px minmax(90px,1fr) 110px;height:66px;gap:8px;padding:8px;font-size:12px}
      .tt-home-activity .tt-queue-row{grid-template-columns:72px minmax(0,1fr) auto;gap:8px}
      .tt-home-activity .tt-queue-row b,.tt-home-activity .tt-queue-row span{font-size:13px;overflow-wrap:anywhere}
      .tt-inside-area .tt-home-activity{display:none}
      @media(max-width:1000px){.tt-home-activity .tt-recent-row{grid-template-columns:78px minmax(0,1fr);height:auto;min-height:86px}.tt-home-activity .tt-recent-row>b:last-child{text-align:left}}
      @media(max-width:700px){.tt-home-activity{grid-template-columns:1fr}#ttAccountingDesk .tt-area-grid{grid-template-columns:repeat(2,minmax(0,1fr))}#ttAccountingDesk .tt-area-card{padding:10px;gap:8px}#ttAccountingDesk .tt-area-card b{font-size:14px}.tt-home-activity .tt-recent-scroll,.tt-home-activity .tt-queue{height:240px}}
      @media(max-width:400px){#ttAccountingDesk .tt-area-grid{grid-template-columns:1fr}}
`;
    document.head.appendChild(style);
  }

  function nativeCard(key) {
    return q(`#ttNativeLaunchers .appCard[data-key="${key}"], #homeGrid .appCard[data-key="${key}"]`);
  }

  let transitionGeneration=0;
  async function launch(action) {
    const transition=++transitionGeneration;
    q('#ttDeskTransition')?.remove();
    // Cover retired editors synchronously; their asynchronous replacements own the next paint.
    const loading=document.createElement('div');loading.id='ttDeskTransition';
    loading.setAttribute('role','status');loading.setAttribute('aria-live','polite');
    loading.style.cssText='position:fixed;inset:60px 0 0;z-index:100300;background:#f5f8fa;display:grid;place-items:center;color:#173c31;font:700 16px Arial';
    loading.textContent='Loading '+(action.title||'Accounts form')+'…';document.body.appendChild(loading);
    for(const id of ['amWindow','ttExpenseDesk','ttBankAccountsDesk','ibtDialog','tgReceiptDialog','tgTransferDialog','tgLiabilityDialog'])q('#'+id)?.remove();
    try { return await launchAction(action); }
    finally { if(transition===transitionGeneration)loading.remove(); }
  }

  async function launchAction(action) {
    window.TT_ACCOUNTS_NAVIGATION?.captureOrigin?.();
    const token=++launchGeneration,company=entity();
    const current=()=>token===launchGeneration&&company===entity();
    window.TT_POST_CONFIRMATION?.dismiss?.();
    if(action.special==='production-recovery'){sessionStorage.setItem('tt_accounts_production_report_return',JSON.stringify({company:entity(),area:sessionStorage.getItem('tt_accounts_current_area')||'reports',scroll:window.scrollY}));location.href='/accounts/production-reports.php?entity='+encodeURIComponent(entity());return;}
    if(action.special==='bag-report'||action.special==='bag-files'){sessionStorage.setItem('tt_accounts_bag_report_return',JSON.stringify({company:entity(),area:sessionStorage.getItem('tt_accounts_current_area')||'reports',scroll:window.scrollY}));location.href='/accounts/bag-reports.php?view='+(action.special==='bag-report'?'report':'print')+'&entity='+encodeURIComponent(entity());return;}
    // One desk owns navigation. Retire the previous workspace before opening another form.
    qa('.workspace.active').forEach(w=>w.classList.remove('active','tt-clean-modal','tt-editor-open'));
    qa('.tt-editor-stage').forEach(e=>e.classList.remove('tt-editor-stage'));
    document.body.classList.remove('tt-modal-open');
    window.TT_EXPENSE_EDITOR?.claim('');
    if(action.special==='third-party-register'){location.href='/accounts/third-party-register.php?entity='+encodeURIComponent(entity());return;}
    if(action.special==='exporter-sodas'){location.href='/directors/exporter-trading.php?origin=accounts&entity='+encodeURIComponent(entity());return;}
    if(action.special==='customer-receivables'){const company=entity();try{const data=await json(`../api/accounts_dashboard.php?entity=${encodeURIComponent(company)}`);if(current())openCustomerReceivables(data.customerReceivables);}catch(error){alert(error.message);}return;}
    if(action.native==='reports'&&['Receivables','Commodity','Shipment'].includes(action.find))return window.TT_REPORTS.open({Receivables:'balances',Commodity:'commodity',Shipment:'shipment'}[action.find]);
    if(action.native==='reports'&&['Trial Balance','Profit','Balance Sheet'].includes(action.find))return window.TT_REPORTS.open(({Trial:'tb',Profit:'pl',Balance:'bs'})[String(action.find||'').split(' ')[0]]||'tb');
    if(entity()==='TG'&&action.native==='payables'&&!action.special)return window.TT_TG_LIABILITIES_UI.open();
    if(action.native==='bank'&&!action.special)return window.TT_BANK_ACCOUNTS_UI.open();
    if(action.special==='expense-report')return window.TT_REPORTS.open('expenses');
    if(action.special==='vehicle-report')return window.TT_REPORTS.open('vehicles');
    if(action.native==='reconciliation')return window.TT_BANK_RECONCILIATION.open();
    if(action.special==='forward-booking')return window.TT_FORWARD_BOOKINGS.open();
    if(action.special==='estimated-bills'){window.location.href='/accounts/cash-flow.php?view=estimates';return;}
    if(action.special==='cash-flow'){window.location.href='/accounts/cash-flow.php';return;}
    if(action.special==='arp-overview'||action.special==='shipment-statement'){window.location.href='/accounts/business-reports.php?report='+(action.special==='arp-overview'?'arp':'shipment');return;}
    if(action.special==='group-daily'){window.location.href='/accounts/group-management.php?kind=DAILY';return;}
    if(action.special==='group-zakat'){window.location.href='/accounts/group-management.php?kind=ZAKAT';return;}
    if(action.special==='forward-register')return window.TT_FORWARD_BOOKINGS.open(true);
    if(action.special==='bank-entry')return window.TT_BANK_ENTRIES.open();
    if(action.special==='bank-finance')return window.TT_BANK_ENTRIES.open('','FINANCE');
    if(action.special==='subaccounts')return window.TT_SUBACCOUNTS.open('manage');
    if(action.special==='change-logs')return openChangeLogs();
    if(action.special==='account-heads')return window.TT_SUBACCOUNTS.open('heads');
    if(action.special==='expense-recipients')return window.TT_ACCOUNT_PAYEES.open('EXPENSE');
    if(action.special==='withholding-report')return window.TT_SUBACCOUNTS.open('tax');
    if(action.special==='mill-liabilities')return window.TT_MILL_LIABILITIES.open();
    if(action.special==='wht-payment')return window.TT_EXPENSE_DESK.openWithholding({source:'BANK'});
    if(action.special==='petty-cash')return window.TT_PETTY_CASH.open();
    if(action.special==='expense-pay')return window.TT_EXPENSE_DESK.open();
    if(action.special==='expense-bills')return window.TT_EXPENSE_DESK.bills();
    if(action.special==='expense-salary')return window.TT_EXPENSE_DESK.openNative('salary','Salaries & Staff');
    if(action.native==='expenses'&&action.then){const mode=action.then.match(/data-expense="([^"]+)/)?.[1];if(mode&&['utility','card','salary','rent','general','reimburse','donations'].includes(mode))return window.TT_EXPENSE_DESK.openNative(mode,action.title||'Expenses');}
    if(action.special==='payment-plan')return window.TT_PAYMENT_PLANS?.choose?.();
    if(action.special==='broker-payment-plan')return window.TT_PAYMENT_PLANS?.open?.('BROKER');
    if(action.special==='tg-remittances')return window.TT_TG_REMITTANCES.open();
    if(action.special==='all-ledgers')return window.TT_ALL_LEDGERS?.open?.('',action.ledgerCategory||'party');
    if(action.special==='bill-registers')return openSearch();
    if(action.special==='supplier-bills')return openBillDesk(action.billKind,action);
    if(action.special==='bill-payment')return window.TT_SUPPLIER_SETTLEMENT_UI?.openBills?.(action.category||'');
    if(action.special==='post-ledger')return window.TT_ALL_LEDGERS?.open?.('POSTS');
    if(action.special==='little-master')return openLittleMaster();
    if(action.special==='other-export-expense')return openOtherExportExpense();
    if (action.special === 'stock-reconciliation' && access.canInventoryReconciliation) { location.href='/stock-reconciliation.php?origin=accounts&entity='+encodeURIComponent(entity()); return; }
    if (action.special === 'soda') return openSoda();
    if (action.special === 'search') return openSearch();
    if (action.special === 'sales-tax') return window.TT_MONTHLY_SALES_TAX.open();
    if (action.special === 'export-receipt') return window.TT_EXPORT_RECEIPTS_UI?.openForm?.();
    if (action.special === 'tg-customer-receipt') return window.TT_TG_CUSTOMER_RECEIPTS?.open?.();
    if (action.special === 'internal-bank-transfer') return window.TT_INTERNAL_BANK_TRANSFERS_UI?.open?.();
    if (action.special === 'tg-currency-transfer') { q('#ws-bank')?.classList.add('active'); return window.TT_TG_BANK_TRANSFER_UI?.open?.(); }
    if (action.special === 'retention-remittance') { const w=q('#ws-bank');if(w){w.classList.add('active');q('#entityHome').style.display='none';w.querySelectorAll(':scope > :not(.panelHead)').forEach(x=>x.hidden=true);} return window.TT_RETENTION_REMITTANCE_UI?.open?.(); }
    if (action.special === 'shipment') return openShipmentChooser();
    if (action.special === 'freight-desk') return openFreightDesk();
    if (action.special === 'shipment-kind') return openShipmentKind(action.shipmentKind);
    const arrivalOpening = action.special === 'arrival-bills';
    const purchaseEditor = q('#purchaseEditor');
    if(action.native==='purchases'&&purchaseEditor)purchaseEditor.replaceChildren();
    if (purchaseEditor && arrivalOpening) purchaseEditor.dataset.ttPurchaseMode = 'arrival';
    if (purchaseEditor && action.bagSync) purchaseEditor.dataset.ttPurchaseMode = 'bags';
    document.body.classList.add('tt-workspace-opening');
    if (arrivalOpening) document.body.classList.add('tt-arrival-opening');
    const card = nativeCard(action.native);
    if (!card) { document.body.classList.remove('tt-arrival-opening','tt-workspace-opening'); return alert('This Accounts area is temporarily unavailable.'); }
    try {
    card.click();
    if (action.then) {
      let button = null;
      for (let i = 0; i < 40 && !button && current(); i += 1) {
        button = q(action.then);
        if (!button) await new Promise(resolve => setTimeout(resolve, 40));
      }
      if(!current())return;
      button?.click();
      if(action.bagSync)await window.TT_BAG_PURCHASES_UI?.mount?.();
      if(!current())return;
      if (action.bagSync) document.dispatchEvent(new CustomEvent('tt:bag-workspace-open'));
    }
    if (arrivalOpening) {
      try { await window.TT_SMART_COMMODITY_BILLS_V2?.mount?.(); }
      finally { if(current())document.body.classList.remove('tt-arrival-opening'); }
    }
    if (action.find) {
      await new Promise(resolve => setTimeout(resolve, 90));
      if(!current())return;
      const root = q('.workspace.active') || document;
      qa('button', root).find(button => button.textContent.toLowerCase().includes(action.find.toLowerCase()))?.click();
    }
    } finally { if(current())document.body.classList.remove('tt-workspace-opening','tt-arrival-opening'); }
    if(!current())return;
    document.dispatchEvent(new CustomEvent('tt:accounts-desk-form-opened', {
      detail: {title: action.title || ''}
    }));
  }

  function buildTopbar() {
    const top = q('.topbar');
    if (!top || q('#ttChangeCompanyDesk')) return;
    const power = q('.power', top);
    const consoleLink = (access.super||(access.workspaces||[]).length>1) ? document.createElement('a') : null;
    if (consoleLink) {
      consoleLink.id = 'ttConsoleTop'; consoleLink.href = access.super?'/index.php':'/staff-home.php';
      consoleLink.title = access.super?'Return to Control Centre':'Your authorised workspaces'; consoleLink.textContent = access.super?'Console':'Home';
    }
    const master = document.createElement('button');
    master.id = 'ttMasterTop'; master.type = 'button'; master.title = 'Master Records';
    master.setAttribute('aria-label', 'Open Master Records'); master.textContent = 'M';
    master.onclick = () => {const p=access.permissions,rights=Array.isArray(p)?p:Object.values(p||{}).flat(),canTax=access.super||p==='all'||rights.some(right=>['Create','Edit','Approve'].includes(right));if(!canTax){window.location.href='/index.php?view=masters&from=accounts';return;}const host=layer('ttAccountsMasterMenu','Master Records'),body=q('.tt-window-body',host);body.innerHTML=`<div class="tt-action-list">${access.super||access.masterAccess?'<a class="tt-action" href="/index.php?view=masters&from=accounts">Company, party and other master records</a>':''}${access.super||access.masterAccess?'<a class="tt-action" href="/accounts/fellow-exporters.php?from=accounts">Fellow Exporter Parties</a>':''}<a class="tt-action" id="ttBagMasterLaunch" href="/accounts/bag-reports.php?view=master&amp;entity=${encodeURIComponent(entity())}">Bag GST Rates</a></div>`;q('#ttBagMasterLaunch',body).onclick=()=>sessionStorage.setItem('tt_accounts_bag_master_return',JSON.stringify({company:entity(),area:sessionStorage.getItem('tt_accounts_current_area')||'',scroll:window.scrollY})); };
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
    if (consoleLink) top.insertBefore(consoleLink, power);
    top.insertBefore(master, power);
    top.insertBefore(company, power);
    top.insertBefore(menu, power);
    document.addEventListener('click', () => { menu.hidden = true; });
    q('#ttChangeEntity')?.remove();
  }

  function deskMarkup() {
    return `<div id="ttAccountingDesk">
      <div class="tt-desk-main">
        <section id="ttDeskOverview"><div class="tt-desk-heading"><input class="tt-search-main" aria-label="Search previous records" placeholder="Search transactions"></div>
          <div class="tt-position" id="ttSummaryCards"><button class="tt-summary"><small>Bank Balance</small><b>Loading…</b></button><button class="tt-summary"><small>Commodity Bills Due</small><b>Loading…</b></button><button class="tt-summary"><small>Local Receivables</small><b>Loading…</b></button><button class="tt-summary"><small>Customer / Export Receivables</small><b>Loading…</b></button></div>
        </section>
        <section id="ttDeskWork"></section>
        <div class="tt-home-activity"><section id="ttRecentSection"><div class="tt-work-head"><h2>Recent Transactions</h2><button type="button" class="btn" id="ttRecentRefresh">Refresh</button></div><div id="ttRecentTransactions" class="tt-recent-scroll" role="region" aria-label="Recent transactions" tabindex="0">Loading transactions…</div></section><section id="ttPendingWork"><div class="tt-work-head"><h2 id="ttPendingCount">Pending Work</h2></div><div class="tt-queue" id="ttAttentionQueue" role="region" aria-label="Pending work" tabindex="0"></div></section></div>
      </div>
    </div>`;

  }

  function iconPicture(kind) {
    const paths={
      jv:'<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M8 7h8M8 11h8M8 15h3m4 0h1"/>',
      exports:'<path d="M3 19h18M5 19V9l5-4 5 4v10M15 10h6v9M9 12v3m9-1h1"/><path d="M14 3l3 2-3 2"/>',
      commodity:'<path d="M3 9l9-5 9 5v11H3zM3 9l9 5 9-5M12 14v6M6 12v5m12-5v5"/>',
      'petty-cash':'<rect x="3" y="6" width="18" height="14" rx="2"/><path d="M7 6V3h10v3M3 11h18M15 15h3"/>',
      routine:'<path d="M4 7h16v13H4zM4 10h16M7 4h10v3M8 15h4m3 0h2"/>',
      ledgers:'<path d="M5 3h12a2 2 0 012 2v16H7a2 2 0 01-2-2zM5 18a2 2 0 012-2h12M9 8h6m-6 4h6"/>',
      reports:'<path d="M4 20V4h16v16zM8 16v-4m4 4V8m4 8v-6"/>',
      receipt:'<rect x="4" y="4" width="16" height="16" rx="2"/><path d="M12 7v9m-3-3l3 3 3-3M7 7h2m6 0h2"/>',
      payment:'<rect x="4" y="5" width="16" height="14" rx="2"/><path d="M8 14l4-4 4 4M12 10v7"/>',
      shipment:'<path d="M3 17h18l-3 4H6zM6 17V6h12v11M9 6V3h6v3M9 11h6"/>',
      bill:'<path d="M6 2h12v20l-3-2-3 2-3-2-3 2zM9 7h6M9 11h6M9 15h4"/>',
      bank:'<path d="M2 9l10-6 10 6M3 10h18M5 10v9m5-9v9m4-9v9m5-9v9M2 20h20"/>',
      search:'<circle cx="10" cy="10" r="6"/><path d="M14.5 14.5L21 21"/>',
      soda:'<path d="M4 8h16v13H4zM8 8V3h8v5M8 13h8m-8 4h5"/>',
      master:'<circle cx="12" cy="12" r="8"/><path d="M12 8v8m-4-4h8"/>'
    };
    return `<svg viewBox="0 0 24 24" width="27" height="27" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths[kind]||paths.bill}</svg>`;
  }

  function showAreasHome() {bankMenuGeneration++;sessionStorage.removeItem('tt_accounts_current_area');
    const work = q('#ttDeskWork'); if (!work) return;
    const items = currentAreas();
    q('#ttAccountingDesk')?.classList.remove('tt-inside-area');
    work.innerHTML = `<div class="tt-area-grid">${items.map(area=>{const [title,subtitle]=areaLabels[area.key]||[area.title,''];return `<button type="button" class="tt-area-card" ${area.key==='jv'?'id="ttMainJV"':`data-tt-area="${area.key}"`}><span class="tt-area-glyph">${iconPicture(area.key.startsWith('tg-')?area.key.slice(3):area.key)}</span><span><b>${esc(title)}</b>${subtitle?`<small><strong>(${esc(subtitle)})</strong></small>`:''}</span></button>`;}).join('')}</div>`;
    q('#ttMainJV',work).onclick=()=>launch({native:'jv'});
    qa('[data-tt-area]', work).forEach(button=>button.onclick=()=>button.dataset.ttArea==='petty-cash'?launch({special:'petty-cash'}):button.dataset.ttArea==='assets'?window.TT_ASSETS_UI?.open?.():showArea(button.dataset.ttArea));
    window.TT_ACCOUNT_BADGE_REFRESH?.();
    void loadDashboardSummary();
  }

  let bankMenuGeneration=0;
  async function showArea(key) {
    const list=currentAreas();
    const area = list.find(item => item.key === key) || list[0];
    sessionStorage.setItem('tt_accounts_current_area',area.key);
    q('#ttAccountingDesk')?.classList.add('tt-inside-area');
    const work = q('#ttDeskWork');
    if (!work) return;
    const menuToken=++bankMenuGeneration,menuCompany=entity(),isBankArea=area.key==='bank'||area.key==='tg-bank';
    const conditionalSpecials=new Set(isBankArea?['bank-finance','retention-remittance','tg-remittances']:[]);
    const actionGlyph = action => {
      const label=action.title.toLowerCase();
      if(action.special==='export-receipt'||label.includes('receipt')||label.includes('advice'))return iconPicture('receipt');
      if(label.includes('payment'))return iconPicture('payment');
      if(label.includes('freight')||label.includes('transport')||label.includes('shipment')||label.includes('fumigation')||label.includes('inspection'))return iconPicture('shipment');
      if(label.includes('ledger')||label.includes('journal'))return iconPicture('ledgers');
      if(label.includes('search')||label.includes('history'))return iconPicture('search');
      if(label.includes('bank'))return iconPicture('bank');
      if(label.includes('soda'))return iconPicture('soda');
      if(label.includes('master'))return iconPicture('master');
      if(label.includes('report')||label.includes('balance')||label.includes('profit'))return iconPicture('reports');
      return iconPicture('bill');
    };
    const actionMarkup=(action,index)=>`<button type="button" class="tt-action" data-tt-action="${index}"><span class="tt-action-mark">${actionGlyph(action)}</span><span><b>${esc(action.title)}</b></span></button>`;
    work.innerHTML = `<div class="tt-work-head"><button class="tt-back-areas" type="button" aria-label="Back to main Accounts">BACK</button><div><h2>${esc(area.title)}</h2></div></div><div class="tt-action-list" data-area-palette="${esc(area.key)}">${area.actions.map((action,index)=>conditionalSpecials.has(action.special)?`<span hidden data-pending-bank-action="${index}"></span>`:actionMarkup(action,index)).join('')}</div>`;
    q('.tt-back-areas',work).onclick=showAreasHome;
    qa('[data-tt-action]', work).forEach(button => button.onclick = () => launch(area.actions[Number(button.dataset.ttAction)]));
    if(isBankArea){
      // Optional tool eligibility must never hold the main Bank screen behind a network wait.
      const urls=['../api/bank_entries.php?entity='+menuCompany,'../api/bank_accounts.php?entity='+menuCompany,menuCompany==='TG'?'../api/tg_remittances.php?entity=TG':'../api/export_receipts.php?entity='+menuCompany];
      const alive=()=>menuToken===bankMenuGeneration&&menuCompany===entity()&&work.isConnected&&document.querySelector('#ttDeskWork')===work;
      const reveal=special=>{if(!alive())return;const index=area.actions.findIndex(action=>action.special===special),slot=q('[data-pending-bank-action="'+index+'"]',work);if(!slot)return;slot.outerHTML=actionMarkup(area.actions[index],index);const button=q('[data-tt-action="'+index+'"]',work);if(button)button.onclick=()=>launch(area.actions[index]);};
      let failed=false;
      await Promise.allSettled(urls.map(async(url,index)=>{try{const response=await fetch(url,{credentials:'same-origin'});if(!response.ok)throw new Error('Bank option unavailable');const data=await response.json();if(!data.ok)throw new Error('Bank option unavailable');if(index===0&&(data.canCreate||(data.facilities||[]).some(f=>f.active)))reveal('bank-finance');if(index===1&&data.accounts?.some(a=>a.settings?.retentionAccount))reveal('retention-remittance');if(index===2&&(menuCompany==='TG'?(data.items||[]).length:((data.pendingTg||[]).length||(data.sources?.tgPackInvoices||[]).length||(data.receipts||[]).some(r=>r.remitter==='TG'))))reveal('tg-remittances');}catch{failed=true;}}));
      if(failed&&alive()){const note=document.createElement('p');note.setAttribute('role','status');note.textContent='Some optional bank tools could not be checked. Reopen BANK to retry.';work.append(note);}
    }
  }

  function openLittleMaster(){
    const host=layer('ttLittleMaster','Accounts Setup'),body=q('.tt-window-body',host);
    const masters=[{name:'Expense Recipients',special:'expense-recipients'},{name:'Credit Cards',native:'expenses',then:'[data-expense="card"]'},{name:'Utility & Club Bills',native:'expenses',then:'[data-expense="utility"]'},{name:'Rent & Recurring',native:'expenses',then:'[data-expense="rent"]'},{name:'Salary & Staff',native:'expenses',then:'[data-expense="salary"]'}];
    body.innerHTML=`<div class="tt-action-list">${masters.map((item,i)=>`<button class="tt-action" data-little="${i}"><span class="tt-action-mark">✎</span><span><b>${esc(item.name)}</b><small>Open saved details and recurring setup</small></span></button>`).join('')}</div>`;
    qa('[data-little]',body).forEach(button=>button.onclick=()=>{q('.tt-window-close',host).click();launch(masters[Number(button.dataset.little)])});
  }

  async function openDuePayments(){
    await launch({native:'payables'});
    window.TT_SUPPLIER_PAYMENT_PLANNING_UI?.mount?.();
  }

  function buildDesk() {
    const home = q('#entityHome');
    if (!home || q('#ttAccountingDesk')) return;
    home.insertAdjacentHTML('afterbegin', deskMarkup());
    q('#ttRecentRefresh').onclick=loadRecentTransactions;
    q('.tt-search-main').addEventListener('keydown', event => { if (event.key === 'Enter') openSearch(event.currentTarget.value); });
    showAreasHome();
    refreshEntityLabels();
  }

  function refreshEntityLabels() {
    const name = q('.entityBtn.active strong')?.textContent || entity();
    qa('[data-tt-entity-name]').forEach(node => { node.textContent = name; });
    showAreasHome();
    loadRecentTransactions();
  }

  let dashboardLoad=0;
  async function loadDashboardSummary() {
    const host=q('#ttSummaryCards'), queue=q('#ttAttentionQueue'); if(!host)return;
    const company=entity(),request=++dashboardLoad;
    host.innerHTML='<div class="tt-summary" role="status"><b>Loading company balances…</b></div>';
    if(queue)queue.innerHTML='<div class="tt-queue-row" role="status">Loading company attention items…</div>';
    try{
      const data=await json(`../api/accounts_dashboard.php?entity=${encodeURIComponent(company)}`);
      if(request!==dashboardLoad||company!==entity())return;
      const definitions=entity()==='TG'
        ? [{key:'bank',label:'Bank Balance',note:'Default account · hover for all accounts'},{key:'export',label:'Customer / Export Receivables',note:'Group total · customer invoices'},{key:'commodity',label:'Supplier Bills Due',note:'Due-date detail'}]
        : [{key:'bank',label:'Bank Balance',note:'Default account · hover for all accounts'},{key:'commodity',label:'Commodity Bills Due',note:'Due-date detail'},{key:'local',label:'Local Receivables',note:'Customer detail'},{key:'export',label:'Customer / Export Receivables',note:'Group total · customer invoices'}];
      host.style.gridTemplateColumns=`repeat(${definitions.length},1fr)`;
      host.innerHTML=definitions.map(def=>{
        const rows=data.summaries?.[def.key]||[], totals={};rows.forEach(row=>{const cur=row.currency||'PKR';totals[cur]=(totals[cur]||0)+Number(row.amount||0)});
        const first=rows[0],defaultBank=rows.find(row=>row.isDefault&&row.currency===(entity()==='TG'?'AED':'PKR'))||rows.find(row=>row.isDefault);
        const headline=def.key==='bank'?(defaultBank?`${esc(defaultBank.currency||'PKR')} ${money(defaultBank.amount)}`:'No default account'):def.key==='due'?(first?`${esc(first.label)} · ${esc(first.currency||'PKR')} ${money(first.amount)} · ${esc(first.dateDisplay||first.date||'')}`:'No due payments'):Object.keys(totals).length?Object.entries(totals).map(([cur,value])=>`${esc(cur)} ${money(value)}`).join(' · '):(company==='TG'?'AED':'PKR')+' 0.00';
        let detail=rows.length?rows.slice(0,20).map(row=>`<div><b>${esc(row.entity?row.entity+' · ':'')}${esc(row.label||row.reference||'Account')}</b><br>${esc(row.reference||'')}${row.dateDisplay?' · '+esc(row.dateDisplay):''} · ${esc(row.currency||'PKR')} ${money(row.amount)}</div>`).join(''):'<div>No open balance.</div>';
        if(def.key==='export'){detail+=`<div>Companies: ${esc((data.customerReceivables?.entities||[]).join(', '))}</div>`;if(data.customerReceivables?.drafts?.length)detail+=`<div>${data.customerReceivables.drafts.length} draft invoice(s) excluded from the due total.</div>`;}
        return `<button type="button" class="tt-summary ${def.key==='due'&&first&&first.date<=today()?'tt-due-alert':''}" data-summary="${def.key}"><small>${esc(def.label)}</small><b>${headline}</b><span class="tt-summary-pop">${detail}</span></button>`;
      }).join('');
       qa('[data-summary]',host).forEach(button=>button.onclick=()=>{const key=button.dataset.summary;if(key==='bank')launch({native:'bank'});else if(key==='due')showArea('routine');else if(key==='commodity'){if(entity()!=='TG')window.TT_PAYMENT_PLANS?.choose?.();else launch({native:'payables'});}else if(key==='export')openCustomerReceivables(data.customerReceivables);else launch({native:'receivables'});});
      if(queue){const rows=data.attention||[];q('#ttPendingWork').hidden=false;q('#ttPendingCount').textContent='Pending Work ('+rows.length+')';queue.innerHTML=rows.length?rows.map((row,i)=>`<div class="tt-queue-row"><span>${esc(row.type)}</span><b>${esc(row.message)}${row.reference?' · '+esc(row.reference):''}</b><button type="button" data-attention-review="${i}">Review</button></div>`).join(''):'<div class="tt-queue-row"><span>Current</span><b>No held or incomplete entries need attention.</b></div>';qa('[data-attention-review]',queue).forEach(button=>button.onclick=()=>reviewAttention(rows[Number(button.dataset.attentionReview)]));}
    }catch(error){if(request!==dashboardLoad||company!==entity())return;qa('.tt-summary b',host).forEach(node=>node.textContent='Unavailable');if(queue){q('#ttPendingWork').hidden=false;q('#ttPendingCount').textContent='Pending Work · unavailable';queue.innerHTML='<div class="tt-queue-row" role="alert"><b>Refresh to load pending work.</b></div>';}console.warn('Accounts dashboard summary',error);}
  }

  let recentLoad=0;
  async function loadRecentTransactions(){
    const box=q('#ttRecentTransactions');if(!box)return;
    const company=entity(),request=++recentLoad;box.textContent='Loading transactions…';
    try{
      const result=await json('../api/accounts_ledger_browser.php?'+new URLSearchParams({entity:company,account:'POSTS',from:'0001-01-01',to:today()}));
      if(request!==recentLoad||company!==entity()||!box.isConnected)return;
      const grouped=new Map();for(const row of result.rows||[]){const id=row.publicPostId||row.voucher;if(!grouped.has(id))grouped.set(id,{...row,amounts:[]});grouped.get(id).amounts.push((row.currency||row.bookCurrency||(company==='TG'?'AED':'PKR'))+' '+(row.nativeMissing?'Unavailable':money(Math.max(Number(row.debit||0),Number(row.credit||0)))));}const rows=[...grouped.values()];
      box.innerHTML=rows.length?rows.map(row=>`<button type="button" class="tt-recent-row" data-recent-post="${esc(row.voucher)}"><span>${esc(window.TT_DATE?.display(row.date)||row.date)}</span><b>${esc(displayRef(row.publicPostId||row.voucher))}</b><span><strong>${esc(row.party)}</strong><small>${esc(row.narration||row.reference)}</small></span><b>${esc(row.amounts.join(' · '))}</b></button>`).join(''):'<p>No posted transactions.</p>';
      qa('[data-recent-post]',box).forEach(button=>button.onclick=()=>window.TT_ALL_LEDGERS?.openPosting?.(button.dataset.recentPost));
    }catch(error){if(request===recentLoad&&company===entity()&&box.isConnected)box.textContent='Transactions unavailable: '+error.message;}
  }

  function openCustomerReceivables(register){
    const host=layer('ttCustomerReceivables','Customer / Export Receivables'),body=q('.tt-window-body',host);
    const rows=register?.rows||[],drafts=register?.drafts||[];
    const table=items=>`<div class="tableWrap"><table><thead><tr><th>Company</th><th>Customer</th><th>Invoice</th><th>Date</th><th>Currency</th><th>Invoice amount</th><th>Received / applied</th><th>Outstanding</th><th>Status</th></tr></thead><tbody>${items.map(r=>`<tr><td>${esc(r.entity)}</td><td>${esc(r.label)}</td><td>${esc(r.reference)}</td><td>${esc(/^\d{4}-\d{2}-\d{2}$/.test(r.date||'')?r.date.split('-').reverse().join('-'):r.date||'')}</td><td>${esc(r.currency)}</td><td>${money(r.invoiceAmount)}</td><td>${money(r.received)}</td><td>${money(r.amount)}</td><td>${esc(r.status)}</td></tr>`).join('')||'<tr><td colspan="9">No outstanding issued customer invoices.</td></tr>'}</tbody></table></div>`;
    body.innerHTML=`<div class="actions"><button class="btn green" id="ttReceivableRecognition">Invoice recognition · ${esc(entity())}</button></div><p>Group customer invoices · ${esc((register?.entities||[]).join(', '))}. Totals stay in each invoice currency. TG settlement packs are excluded.</p>${table(rows)}${drafts.length?`<details><summary>Draft invoices (${drafts.length}) — excluded from due total</summary>${table(drafts)}</details>`:''}`;
    q('#ttReceivableRecognition',body).onclick=()=>{q('.tt-window-close',host).click();launch({native:'receivables'});};
  }

  function reviewAttention(row) {
    if(row.kind==='REMINDER')return launch({native:'expenses',then:'[data-expense="'+row.target.expense+'"]'});
    if(row.kind==='REMITTANCE')return window.TT_TG_REMITTANCES.open(row.target.remittanceId);
    const company=entity(),host=layer('ttReviewLayer','Review · '+row.type),body=q('.tt-window-body',host);
    body.innerHTML=`<div class="tt-form"><h3>${esc(row.reference||row.type)}</h3><p>${esc(row.message)}</p><p>Discard removes this review from the list. Approve opens the approval or correction form.</p><p id="ttReviewError"></p><button class="btn" id="ttReviewDiscard">Discard</button> <button class="btn green" id="ttReviewApprove">Approve</button></div>`;
    const action=async task=>{const buttons=qa('button',body);buttons.forEach(b=>b.disabled=true);try{if(company!==entity())throw Error('Company changed. Reopen the review.');await task();q('.tt-window-close',host).click();await loadDashboardSummary()}catch(e){q('#ttReviewError',body).textContent=e.message}finally{buttons.forEach(b=>b.disabled=false)}};
    q('#ttReviewDiscard',body).onclick=()=>action(()=>json('../api/accounts_reviews.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'discard',entity:company,csrf:access.csrf,id:row.id,fingerprint:row.fingerprint})}));
    q('#ttReviewApprove',body).onclick=()=>action(async()=>{
      if(row.kind==='SALE')return window.TT_LOCAL_SALES_CONTROL_UI.review(row.target.candidateId);
      if(row.kind==='PAYMENT')return window.TT_LOCAL_SALES_PAYMENT_UI.review(row.target.paymentId);
      if(row.kind==='HOLD')return openHeldReview(row);
      if(row.kind==='FREIGHT'){const state=await json('../api/accounts_workflows_v1.php?entity='+encodeURIComponent(company)+'&section=freight'),bill=(state.bills||[]).find(x=>x.id===row.target.billId);if(!bill)throw Error('Bill no longer available. Refresh the list.');return bill.shipmentId||bill.shipmentIds?.length?openSavedBill(bill):window.TT_ACCOUNTS_V1_WORKFLOW.reviewFreight(bill.id);}
      if(row.target.sodaNo){const sodaHost=layer('ttSodaLayer','Amend Soda');await loadSodas();const soda=(sodaData.sodas||[]).find(x=>x.sodaNo===row.target.sodaNo);if(!soda)throw Error('Soda no longer available. Refresh the list.');activeSoda=soda;q('.tt-window-body',sodaHost).innerHTML=sodaForm(soda);bindSodaForm(sodaHost,soda);return;}
      window.TT_ACCOUNTS_V1_WORKFLOW.open('due');
    });
  }

  function openHeldReview(row) {
    const company=entity(),host=layer('ttHeldReviewLayer','Held Payment · '+row.reference),body=q('.tt-window-body',host);
    body.innerHTML=`<form class="tt-form"><p>${esc(row.message)}</p><label>Hold reason<input name="reason" value="${esc(row.target.reason||'')}"></label><label><input name="active" type="checkbox" checked> Keep payment on hold</label><p id="ttHoldError"></p><button type="submit" class="btn green">Save Changes</button>${row.target.sodaNo?'<button type="button" class="btn" id="ttHoldSoda">Amend Soda</button>':''}</form>`;
    q('form',body).onsubmit=async e=>{e.preventDefault();const form=e.currentTarget,button=q('[type=submit]',form);button.disabled=true;try{if(company!==entity())throw Error('Company changed. Reopen the entry.');if(!form.elements.reason.value.trim())throw Error('Enter a reason for the change.');await json('../api/payables_planning.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'set_hold',entity:company,csrf:access.csrf,sourceKey:row.target.sourceKey,active:form.elements.active.checked,reason:form.elements.reason.value.trim()})});q('.tt-window-close',host).click();loadDashboardSummary()}catch(error){q('#ttHoldError',body).textContent=error.message}finally{button.disabled=false}};
    if(row.target.sodaNo)q('#ttHoldSoda',body).onclick=async()=>{try{if(company!==entity())throw Error('Company changed.');await loadSodas();activeSoda=(sodaData.sodas||[]).find(x=>x.sodaNo===row.target.sodaNo);if(!activeSoda)throw Error('Soda no longer available.');const sodaHost=layer('ttSodaLayer','Amend Soda');q('.tt-window-body',sodaHost).innerHTML=sodaForm(activeSoda);bindSodaForm(sodaHost,activeSoda)}catch(e){q('#ttHoldError',body).textContent=e.message}};
  }

  function layer(id, title) {
    let host = q('#' + id);
    if (!host) {
      host = document.createElement('div');
      host.id = id;
      host.className = 'tt-layer';
      host.hidden = true;
      host.innerHTML = `<div class="tt-window" role="dialog" aria-modal="true"><div class="tt-window-head"><h2></h2><span></span><button class="tt-window-close" type="button">× Close</button></div><div class="tt-window-body"></div></div>`;
      q('.tt-window-close', host).onclick = () => { host.hidden = true; document.body.classList.remove('tt-desk-layer-open'); };
      host.onclick = event => { if (event.target === host) q('.tt-window-close', host).click(); };
      document.body.appendChild(host);
    }
    q('.tt-window-head h2', host).textContent = title;
    qa('.tt-layer').forEach(other=>{if(other!==host)other.hidden=true;});
    document.body.appendChild(host);host.hidden = false;
    window.TT_FORM_VIEWPORT?.open(host);
    return host;
  }

  async function openChangeLogs(){
    const company=entity(),host=layer('ttChangeLogs','Change Logs'),body=q('.tt-window-body',host);
    body.innerHTML=`<div class="tt-action-list"><button type="button" class="tt-action" data-log-posts><b>Post ID Register &amp; Amendment History</b></button>${company!=='TG'?'<button type="button" class="tt-action" data-log-sodas><b>SODA Change History</b></button>':''}${access.super?'<button type="button" class="tt-action" data-log-admin><b>System &amp; Master Audit</b></button>':''}</div><p>Open a posting or SODA to see its recorded changes.</p><div data-log-content></div>`;
    q('[data-log-posts]',body).onclick=()=>{q('.tt-window-close',host).click();window.TT_ALL_LEDGERS?.open?.('POSTS');};
    const sodas=q('[data-log-sodas]',body);if(sodas)sodas.onclick=()=>{q('.tt-window-close',host).click();launch({special:'soda'});};
    const admin=q('[data-log-admin]',body);if(admin)admin.onclick=async()=>{
      const content=q('[data-log-content]',body);content.textContent='Loading audit…';admin.disabled=true;
      try{const data=await json('../api/admin_audit.php');if(host.hidden||company!==entity())return;
        content.innerHTML='<label>Search audit<input type="search" data-log-search></label><div class="tableWrap" style="max-height:55vh;overflow:auto"><table><thead><tr><th>Date</th><th>User</th><th>Area</th><th>Change</th></tr></thead><tbody>'+ (data.entries||[]).map(row=>'<tr data-log-row><td>'+esc(row.date)+'</td><td>'+esc(row.user)+'</td><td>'+esc(row.area)+'</td><td>'+esc(row.detail)+'</td></tr>').join('')+'</tbody></table></div>'+(data.entries?.length?'':'<p>No recorded administrative changes.</p>');
        q('[data-log-search]',content).oninput=event=>qa('[data-log-row]',content).forEach(row=>row.hidden=!row.textContent.toLowerCase().includes(event.target.value.toLowerCase()));
      }catch(error){if(!host.hidden&&company===entity())content.textContent=error.message;}finally{admin.disabled=false;}
    };
  }

  async function json(url, options) {
    const response = await fetch(url, {credentials:'same-origin', headers:{Accept:'application/json', ...(options?.headers || {})}, ...options});
    let data = {};
    try { data = await response.json(); } catch (_) {}
    if (!response.ok || !data.ok) { const error = new Error(data.error || 'Accounts request could not be completed.'); error.rejected = !!data.error && response.status >= 400 && response.status < 500; throw error; }
    return data;
  }

  async function loadSodas() {
    sodaData = await json(`${api}?entity=${encodeURIComponent(entity())}`);
    try { const targets=await json(`${api}?entity=${encodeURIComponent(entity())}&action=buying_for`);sodaData.buyingForTargets=targets.buyingForTargets||[]; }
    catch(error){sodaData.buyingForError=error.message;}
    return sodaData;
  }

  function sodaMode(id,items,value){return `<div class="tt-account-choices" role="radiogroup" aria-label="${id==='ttSdStage'?'Purchase type':'Purchase route'}">${items.map(([key,label])=>`<label><input type="radio" name="${id}Choice" value="${key}" data-soda-mode="${id}" ${key===value?'checked':''}>${esc(label)}</label>`).join('')}</div><select id="${id}" hidden data-master-ignore data-tt-native="1"><option value=""></option>${items.map(([key,label])=>`<option value="${key}" ${key===value?'selected':''}>${esc(label)}</option>`).join('')}</select>`;}
  function sodaForm(record = null) {
    const value = (key, fallback = '') => esc(record?.[key] ?? fallback);
    const credit = String(record?.paymentTermType || (Number(record?.creditDays || 0) ? 'CREDIT' : 'CASH')).toUpperCase();
    const products = sodaData.purchaseProducts || [];
    const inferredProduct = products.find(item => item.id === record?.purchaseProductId) || products.find(item => String(item.commodity) === String(record?.commodity || '') && String(item.baseVariety).toLowerCase() === String(record?.baseVariety || record?.variety || '').toLowerCase() && String(item.riceType || '').toLowerCase() === String(record?.riceType || '').toLowerCase() && String(item.brokenGrade || '').toLowerCase() === String(record?.brokenGrade || '').toLowerCase() && String(item.productStage) === String(record?.productStage || ''));
    const productId = inferredProduct?.id || (!record ? sodaData.defaults?.rawPurchaseProductId : '');
    const stage = inferredProduct?.productStage || String(record?.productStage || 'RAW').toUpperCase();
    const routeChoice = stage === 'READY' || inferredProduct?.commodity === 'CORN';
    const route = routeChoice ? String(record?.readyRoute || '') : 'DELIVER_TO_STOCK';
    const locations = sodaData.locations || [];
    const legacyLocation = locations.find(item => String(item.name).toLowerCase() === String(record?.locationName || record?.location || '').toLowerCase());
    const locationId = record?.locationId || legacyLocation?.id || (!record && stage !== 'READY' ? sodaData.defaults?.rawLocationId : '');
    const option = (item, selected, label) => `<option value="${esc(item.id)}"${item.id===selected?' selected':''}>${esc(label)}</option>`;
    const productStage = products.find(item => item.id === productId)?.productStage || stage;
    const productVariety = products.find(item => item.id === productId)?.baseVariety || 'IRRI-6';
    const varieties = [...new Set(products.filter(item => item.productStage === productStage).map(item => item.baseVariety))].sort((a,b) => a.localeCompare(b));
    if (!varieties.some(item => item.toLowerCase() === productVariety.toLowerCase())) varieties.unshift(productVariety);
    const productOptions = products.filter(item => item.productStage === productStage && item.baseVariety.toLowerCase() === productVariety.toLowerCase()).map(item => option(item, productId, item.displayName)).join('');
    const brokerOptions = (sodaData.brokers || []).map(item => `<option value="${esc(item.name)}"${String(item.name).toLowerCase()===String(record?.broker||'').toLowerCase()?' selected':''}>${esc(item.name)}</option>`).join('');
    const supplierOptions = (sodaData.suppliers || []).map(item => option(item, record?.supplierId || '', item.name)).join('');
    const receiving = locations.filter(item => ['Own Mill','Reprocessing Mill','Warehouse','Stock Location'].includes(item.type));
    const external = locations.filter(item => item.type === 'External Mill');
    return `<form class="tt-form" id="ttSodaForm">
      <div class="tt-form-grid">
        <label>Soda No.<input value="${value('sodaNo', sodaData.nextSodaNo || 'Generated automatically')}" readonly tabindex="-1"></label>
        <label>Soda Date<input id="ttSdDate" type="date" value="${value('sodaDate', today())}" required></label>
        <div><span>Type</span>${sodaMode('ttSdStage',[['RAW','RAW'],['READY','READY']],productStage)}</div>
        <label>Variety<select id="ttSdVariety">${varieties.map(name=>`<option value="${esc(name)}"${name.toLowerCase()===productVariety.toLowerCase()?' selected':''}>${esc(name)}</option>`).join('')}</select></label>
        <label class="wide">Purchase Product<span class="tt-master-control"><select id="ttSdProduct" required><option value="">Choose product for this variety</option>${productOptions}</select><button type="button" class="btn" id="ttSdAddProduct" title="Add Purchase Product"${sodaData.permissions?.canAddProduct?'':' hidden'}>+</button><button type="button" class="btn" id="ttSdRemoveProduct" title="Remove selected Purchase Product"${sodaData.permissions?.canRemoveProduct?'':' hidden'}>−</button></span></label>
        <label>Broker (optional)<span class="tt-master-control"><select id="ttSdBroker"><option value="">No broker — direct purchase</option>${brokerOptions}</select><button type="button" class="btn" id="ttSdAddBroker" title="Add Broker"${sodaData.permissions?.canAddParty?'':' hidden'}>+</button><button type="button" class="btn" id="ttSdRemoveBroker" title="Remove selected Broker"${sodaData.permissions?.canRemoveParty?'':' hidden'}>−</button></span></label>
        <label>Supplier<span class="tt-master-control"><select id="ttSdSupplier"><option value=""${record?.party&&!record?.supplierId?' selected':''}>${record?.party?esc(record.party):'Select supplier if applicable'}</option>${supplierOptions}</select><button type="button" class="btn" id="ttSdAddSupplier" title="Add Supplier"${sodaData.permissions?.canAddParty?'':' hidden'}>+</button><button type="button" class="btn" id="ttSdRemoveSupplier" title="Remove selected Supplier"${sodaData.permissions?.canRemoveParty?'':' hidden'}>−</button></span></label>
        <div id="ttSdRouteWrap" class="wide"${routeChoice?'':' hidden'}><span>Purchase Route</span>${route?'':'<small>Choose route — no default</small>'}${sodaMode('ttSdRoute',[['EX_MILL','EX-MILL — load containers at outside mill'],['DELIVER_TO_STOCK','DELIVER TO OUR MILL / STOCK LOCATION']],route)}<small id="ttSdRouteHelp">${route==='EX_MILL'?'No Pohanch or KAT. Each saved container weighbridge weight posts the purchase liability.':'Delivery to our location uses arrival Pohanch and the approved KAT profile.'}</small></div>
        <label id="ttSdStockWrap" class="wide"${route==='EX_MILL'?' hidden':''}>Mill / Stock Location<span class="tt-master-control"><select id="ttSdStock"><option value="">Select receiving stock location</option>${receiving.map(item=>option(item,locationId,`${item.name} · ${item.type}`)).join('')}</select><button type="button" class="btn" id="ttSdAddStock" title="Add Mill / Stock Location"${sodaData.permissions?.canAddLocation?'':' hidden'}>+</button><button type="button" class="btn" id="ttSdRemoveStock" title="Remove selected Mill / Stock Location"${sodaData.permissions?.canRemoveLocation?'':' hidden'}>−</button></span></label>
        <div id="ttSdExMillWrap" class="wide"${route==='EX_MILL'?'':' hidden'}><label>Ex-Mill Name<span class="tt-master-control"><select id="ttSdExMill"><option value="">Type to search External Mills</option>${external.map(item=>option(item,locationId,item.name)).join('')}</select><button type="button" class="btn" id="ttSdAddMill" title="Add External Mill"${sodaData.permissions?.canAddLocation?'':' hidden'}>+</button><button type="button" class="btn" id="ttSdRemoveMill" title="Remove selected External Mill"${sodaData.permissions?.canRemoveLocation?'':' hidden'}>−</button></span></label><div class="tt-form-actions" style="justify-content:flex-start"><button type="button" class="btn" id="ttSdLinkMill"${sodaData.permissions?.canLinkSupplierMill?'':' hidden'}>Link supplier to selected Ex-Mill</button></div></div>
        <label><span id="ttSdTrucksLabel">${route==='EX_MILL'?'Truck / Containers':'Expected Trucks'}</span><input id="ttSdTrucks" inputmode="numeric" value="${value('expectedTrucks')}"></label>
        <label id="ttSdTargetWrap"${route==='EX_MILL'?'':' hidden'}>Contracted Quantity (MT)<input id="ttSdTarget" inputmode="decimal" value="${record ? value('qtyToMT', Number(record.qtyToKg || record.qtyFromKg || 0) / 1000 || '') : ''}"><small>Actual loaded may vary by up to 5%.</small></label>
        <label id="ttSdMinWrap"${route==='EX_MILL'?' hidden':''}>Minimum Quantity (MT)<input id="ttSdMin" inputmode="decimal" value="${record ? value('qtyFromMT', Number(record.qtyFromKg || 0) / 1000 || '') : ''}"></label>
        <label id="ttSdMaxWrap"${route==='EX_MILL'?' hidden':''}>Maximum Quantity (MT)<input id="ttSdMax" inputmode="decimal" value="${record ? value('qtyToMT', Number(record.qtyToKg || 0) / 1000 || '') : ''}"></label>
        <section class="wide" id="ttSdBuyingWrap"${stage==='RAW'?' hidden':''}><strong>Buying for · internal only</strong><p>Leave blank for General stock. Arrivals fill the listed allocations in order; the balance stays in General stock. This is never printed on the SODA.</p><div id="ttSdBuyingRows"></div><button type="button" class="btn" id="ttSdBuyingAdd">Add contract allocation</button><p id="ttSdBuyingBalance"></p><p role="alert">${esc(sodaData.buyingForError||'')}</p></section>
        <label>Rate<input id="ttSdRate" inputmode="decimal" value="${value('rate', record?.ratePerKg || '')}" required></label>
        <label>Rate Unit<select id="ttSdUnit"><option value="KG"${(record?.rateUnit||'KG')==='KG'?' selected':''}>Per kg</option><option value="MAUND"${record?.rateUnit==='MAUND'?' selected':''}>Per maund (40 kg)</option></select></label>
        <label>PAYMENT TERM<select id="ttSdTerms"><option value="CASH"${credit==='CASH'?' selected':''}>Cash</option><option value="CREDIT"${credit==='CREDIT'?' selected':''}>Credit</option></select></label>
        <label id="ttSdCreditWrap"${credit==='CASH'?' hidden':''}>Credit Days<input id="ttSdCredit" inputmode="numeric" min="1" max="365" value="${value('creditDays', credit==='CREDIT'?'':'')}" ${credit==='CASH'?'disabled':''}></label>
        <label>Expected Arrival / Delivery Date<input id="ttSdDue" type="date" value="${value('arrivalDueDate', record?.deliveryDeadline || '')}" required></label>
        <label class="wide">Terms / Conditions<input id="ttSdConditions" value="${value('terms')}"></label>
        <label class="wide">Remarks<textarea id="ttSdRemarks">${value('remarks')}</textarea></label>
        ${record ? '<label class="wide">Reason for Amendment<textarea id="ttSdReason" placeholder="Required. This is stored with the old and new values in the audit log." required></textarea></label>' : ''}
      </div>
      <div class="tt-treatment"><strong>Accounting treatment before posting</strong><div class="tt-note">A SODA records the purchase commitment. The bill is posted separately.</div><div class="tt-note" id="ttSdPaymentHelp"></div><div class="tt-treatment-row"><span>Later</span><span>Approved arrival bill: Inventory / Purchase</span><b>Debit</b></div><div class="tt-treatment-row"><span>Later</span><span>Supplier Payable</span><b>Credit</b></div></div>
      <div class="tt-form-actions"><button type="button" class="btn" id="ttSdReset">Cancel</button><button type="submit" class="btn green">${record ? 'Save Amendment' : 'Save Soda'}</button></div>
    </form>`;
  }

  function bindSodaForm(host, record = null) {
    const form = q('#ttSodaForm', host);
    const createRequestKey = record ? '' : (crypto.randomUUID?.() || `soda-${Date.now()}-${Math.random().toString(16).slice(2)}`);
    const terms=q('#ttSdTerms',form),days=q('#ttSdCredit',form),daysWrap=q('#ttSdCreditWrap',form),stageSelect=q('#ttSdStage',form),varietySelect=q('#ttSdVariety',form),productSelect=q('#ttSdProduct',form),route=q('#ttSdRoute',form),routeWrap=q('#ttSdRouteWrap',form),stock=q('#ttSdStock',form),stockWrap=q('#ttSdStockWrap',form),exMill=q('#ttSdExMill',form),exMillWrap=q('#ttSdExMillWrap',form),supplier=q('#ttSdSupplier',form);
    const buyingWrap=q('#ttSdBuyingWrap',form),buyingRows=q('#ttSdBuyingRows',form);
    const buyingTargets=sodaData.buyingForTargets||[];
    const buyingLabel=t=>`${t.kind==='EXPORT'?'Export sale':'Sale to exporter'} · ${t.reference} · ${t.customer||''} · ${t.product||''} · ${money(t.quantityKg/1000)} MT`;
    const buyingBalance=()=>{const capacity=Number(q(route.value==='EX_MILL'?'#ttSdTarget':'#ttSdMax',form).value)||0;const allocated=qa('[data-buying-kg]',buyingRows).reduce((sum,input)=>sum+(Number(input.value)||0),0);q('#ttSdBuyingBalance',form).textContent=`Allocated: ${money(allocated)} MT · General stock: ${money(Math.max(0,capacity-allocated))} MT${allocated>capacity?' · Allocation exceeds SODA quantity':''}`;};
    const addBuying=(a={})=>{const row=document.createElement('div');row.className='tt-form-grid';row.dataset.buyingRow='';const targets=[...buyingTargets];if(a.targetId&&!targets.some(t=>t.id===a.targetId))targets.push({id:a.targetId,reference:a.targetId,kind:'EXPORT',product:'Saved allocation',quantityKg:0});row.innerHTML=`<label>Sales contract<select data-buying-target data-master-ignore data-tt-native="1"><option value="">Choose contract</option>${targets.map(t=>`<option value="${esc(t.id)}"${t.id===a.targetId?' selected':''}>${esc(buyingLabel(t))}</option>`).join('')}</select></label><label>Quantity (MT)<input inputmode="decimal" data-buying-kg value="${a.quantityKg?esc(a.quantityKg/1000):''}"></label><button type="button" class="btn" data-buying-remove>Remove allocation</button>`;q('[data-buying-remove]',row).onclick=()=>{row.remove();buyingBalance();};q('[data-buying-kg]',row).oninput=buyingBalance;buyingRows.append(row);buyingBalance();};
    q('#ttSdBuyingAdd',form).onclick=()=>addBuying();
    (record?.buyingForAllocations||[]).forEach(addBuying);
    for(const id of ['ttSdTarget','ttSdMax'])q('#'+id,form).addEventListener('input',buyingBalance);
    let locationTouched=!!record?.locationId;
    const setSelect=(control,value)=>{control.value=value;const input=control.closest('.tt-search-select')?.querySelector(':scope>input');if(input)input.value=control.selectedOptions[0]?.textContent.trim()||'';};
    const selectedProduct=()=> (sodaData.purchaseProducts||[]).find(item=>item.id===productSelect.value)||null;
    const sameVariety=(item,name)=>String(item.baseVariety||'').toLowerCase()===String(name||'').toLowerCase();
    const refreshProducts=(preferred='')=>{const rows=(sodaData.purchaseProducts||[]).filter(item=>item.productStage===stageSelect.value&&sameVariety(item,varietySelect.value));productSelect.replaceChildren(new Option('Choose product for this variety',''),...rows.map(item=>new Option(item.displayName,item.id)));setSelect(productSelect,rows.some(item=>item.id===preferred)?preferred:rows.length===1?rows[0].id:'');productSelect.dispatchEvent(new Event('change',{bubbles:true}));};
    const refreshVarieties=(preferred='')=>{const names=[...new Set((sodaData.purchaseProducts||[]).filter(item=>item.productStage===stageSelect.value).map(item=>item.baseVariety))].sort((a,b)=>a.localeCompare(b));varietySelect.replaceChildren(...names.map(name=>new Option(name,name)));setSelect(varietySelect,names.find(name=>name.toLowerCase()===preferred.toLowerCase())||names.find(name=>name.toLowerCase()==='irri-6')||names[0]||'');refreshProducts();};
    const paymentState=()=>{const isCredit=terms.value==='CREDIT';daysWrap.hidden=!isCredit;days.disabled=!isCredit;days.required=isCredit;if(!isCredit)days.value='';q('#ttSdPaymentHelp',form).textContent=isCredit?`Payment becomes due on Arrival / Pohanch date + ${days.value||'agreed'} day(s).`:'Cash payment becomes due on Arrival / Pohanch date + 2 days.';};
    terms.onchange=paymentState;days.oninput=paymentState;paymentState();
    const syncRoute=(initial=false)=>{const product=selectedProduct(),ready=product?.productStage==='READY'||product?.commodity==='CORN';buyingWrap.hidden=product?.productStage!=='READY';qa('input,select',buyingRows).forEach(input=>input.disabled=buyingWrap.hidden);buyingBalance();routeWrap.hidden=!ready;if(!ready){setSelect(route,'DELIVER_TO_STOCK');setSelect(exMill,'');stockWrap.hidden=false;exMillWrap.hidden=true;if(!record&&!stock.value)setSelect(stock,sodaData.defaults?.rawLocationId||'');}else{if(!initial){setSelect(route,'');setSelect(stock,'');setSelect(exMill,'');locationTouched=false;}stockWrap.hidden=route.value!=='DELIVER_TO_STOCK';exMillWrap.hidden=route.value!=='EX_MILL';}const ex=ready&&route.value==='EX_MILL',target=q('#ttSdTarget',form),min=q('#ttSdMin',form),max=q('#ttSdMax',form);q('#ttSdTargetWrap',form).hidden=!ex;q('#ttSdMinWrap',form).hidden=ex;q('#ttSdMaxWrap',form).hidden=ex;q('#ttSdTrucksLabel',form).textContent=ex?'Truck / Containers':'Expected Trucks';q('#ttSdRouteHelp',form).textContent=ex?(product?.commodity==='CORN'?'Saved container net weights post the provisional liability. Accounts verifies Corn KAT when billing.':'No Pohanch or KAT. Each saved container weighbridge weight posts the purchase liability.'):'Delivery to our location uses arrival Pohanch and the approved KAT profile.';if(ex&&!target.value)target.value=max.value||min.value;if(!ex&&target.value&&!min.value){min.value=target.value;max.value=target.value;}supplier.required=ex;};
    productSelect.onchange=()=>syncRoute(false);
    stageSelect.onchange=()=>refreshVarieties(varietySelect.value);
    varietySelect.onchange=()=>refreshProducts();
    route.onchange=()=>{setSelect(stock,'');setSelect(exMill,'');locationTouched=false;syncRoute(true);if(route.value==='EX_MILL')suggestSupplierMill();};
    stock.onchange=()=>{locationTouched=true;};exMill.onchange=()=>{locationTouched=true;};
    const suggestSupplierMill=()=>{if(route.value!=='EX_MILL'||locationTouched)return;const row=(sodaData.suppliers||[]).find(item=>item.id===supplier.value);if(row?.linkedExternalMillId&&[...exMill.options].some(option=>option.value===row.linkedExternalMillId)){setSelect(exMill,row.linkedExternalMillId);locationTouched=false;}};
    supplier.onchange=suggestSupplierMill;
    // Entry offers authorised Add inside its dropdown. Full maintenance stays in Master Records.
    for(const [selectId,addId,removeId,label] of [['ttSdProduct','ttSdAddProduct','ttSdRemoveProduct','Purchase Product'],['ttSdBroker','ttSdAddBroker','ttSdRemoveBroker','Broker'],['ttSdSupplier','ttSdAddSupplier','ttSdRemoveSupplier','Supplier'],['ttSdStock','ttSdAddStock','ttSdRemoveStock','Stock Location'],['ttSdExMill','ttSdAddMill','ttSdRemoveMill','External Mill']]){
      const select=q('#'+selectId,form),add=q('#'+addId,form),remove=q('#'+removeId,form),allowed=!!add&&!add.hidden;
      if(select&&add&&window.TT_ACCOUNTS_CLEAN_UI){window.TT_ACCOUNTS_CLEAN_UI.manageSelect(select,{label,add:()=>add.click(),canAdd:()=>allowed});add.classList.add('tt-dropdown-master-launcher');}
      if(remove)remove.hidden=true;
    }
    for(const id of ['ttSdStage','ttSdRoute']){const source=q('#'+id,form),radios=qa('[data-soda-mode="'+id+'"]',form);radios.forEach(radio=>radio.onchange=()=>{if(radio.checked){source.value=radio.value;source.dispatchEvent(new Event('change'));}});source.addEventListener('change',()=>radios.forEach(radio=>radio.checked=radio.value===source.value));}
    const masterPost=body=>json('../api/masters.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:access.csrf,...body})});
    const addParty=async(category,control)=>{const name=prompt(`New ${category} name`,'');if(!name?.trim())return;try{const result=await json(api,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'add_party_category',csrf:access.csrf,category,name:name.trim()})});Object.assign(sodaData,result);const rows=category==='Broker'?sodaData.brokers:sodaData.suppliers,row=(rows||[]).find(item=>item.id===result.partyId);if(row){control.add(new Option(row.name,category==='Broker'?row.name:row.id));setSelect(control,category==='Broker'?row.name:row.id);control.dispatchEvent(new Event('change',{bubbles:true}));}}catch(error){alert(error.message);}};
    const removeParty=async(category,control)=>{const selected=category==='Broker'?(sodaData.brokers||[]).find(item=>item.name===control.value):(sodaData.suppliers||[]).find(item=>item.id===control.value);if(!selected)return alert(`Select the ${category} to remove from future Sodas.`);if(!confirm(`Remove ${selected.name} from the ${category} dropdown? Historical Sodas remain unchanged.`))return;try{const result=await json(api,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'remove_party_category',csrf:access.csrf,category,id:selected.id})});Object.assign(sodaData,result);control.selectedOptions[0]?.remove();setSelect(control,'');}catch(error){alert(error.message);}};
    q('#ttSdAddBroker',form)?.addEventListener('click',()=>addParty('Broker',q('#ttSdBroker',form)));
    q('#ttSdRemoveBroker',form)?.addEventListener('click',()=>removeParty('Broker',q('#ttSdBroker',form)));
    q('#ttSdAddSupplier',form)?.addEventListener('click',()=>addParty('Supplier',supplier));
    q('#ttSdRemoveSupplier',form)?.addEventListener('click',()=>removeParty('Supplier',supplier));
    q('#ttSdAddProduct',form)?.addEventListener('click',async()=>{const newBase=prompt('Variety / product name (edit to add a new variety)',varietySelect.value||'IRRI-6');if(newBase===null||!newBase.trim())return;const base=newBase.trim();const commodity=String(prompt('Commodity: RICE, CORN or SESAME',/corn|makai/i.test(base)?'CORN':/sesame/i.test(base)?'SESAME':'RICE')||'').trim().toUpperCase();if(!['RICE','CORN','SESAME'].includes(commodity))return alert('Commodity must be RICE, CORN or SESAME.');const riceType=commodity==='RICE'?(prompt('Rice type: White, Parboiled / Sella or Steam','White')||'').trim():'';if(commodity==='RICE'&&!riceType)return;const grade=commodity==='RICE'?prompt('Broken percentage — enter only the number (optional)',''):'';if(grade===null)return;if(grade!==''&&(!/^(?:\d+(?:\.\d+)?)$/.test(grade.trim())||Number(grade)<0||Number(grade)>100))return alert('Broken percentage must be a number from 0 to 100.');const stage=stageSelect.value,unit=commodity==='RICE'?'KG':'MAUND';try{const saved=await masterPost({action:'create',type:'purchase_products',values:[commodity,base,riceType,stage,unit,'','','','Active','Added from Accounts Soda Centre.',grade.trim()]});await loadSodas();const row=(sodaData.purchaseProducts||[]).find(item=>item.id===saved.id);if(row){refreshVarieties(row.baseVariety);refreshProducts(row.id);}else alert('Product was saved, but did not appear in the active Soda list. Check its stage and status in Purchase Commodities & KAT.');}catch(error){alert(error.message);}});
    q('#ttSdRemoveProduct',form)?.addEventListener('click',async()=>{if(!productSelect.value)return alert('Select the Purchase Product to remove.');const label=productSelect.selectedOptions[0]?.textContent||'this product';if(!confirm(`Remove ${label} from future Sodas? Historical Sodas remain unchanged.`))return;try{await masterPost({action:'delete',type:'purchase_products',id:productSelect.value});await loadSodas();refreshVarieties(varietySelect.value);}catch(error){alert(error.message);}});
    const addLocation=async(type,control)=>{const name=prompt(type==='External Mill'?'External Mill name':'Mill / Stock Location name','');if(!name?.trim())return;let chosen=type;if(type!=='External Mill'){chosen=String(prompt('Location type: Own Mill, Reprocessing Mill, Warehouse or Stock Location','Stock Location')||'').trim();if(!['Own Mill','Reprocessing Mill','Warehouse','Stock Location'].includes(chosen))return alert('Select a stock-holding mill or location type.');}try{const result=await json(api,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'add_location',csrf:access.csrf,name:name.trim(),type:chosen})});Object.assign(sodaData,result);const row=(sodaData.locations||[]).find(item=>item.id===result.locationId);if(row){control.add(new Option(type==='External Mill'?row.name:`${row.name} · ${row.type}`,row.id));setSelect(control,row.id);control.dispatchEvent(new Event('change',{bubbles:true}));locationTouched=true;}}catch(error){alert(error.message);}};
    const removeLocation=async control=>{if(!control.value)return alert('Select the mill / location to remove.');const label=control.selectedOptions[0]?.textContent||'this location';if(!confirm(`Remove ${label} from future dropdowns? Historical Sodas remain unchanged.`))return;try{await json('../api/location-master.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'deactivate',csrf:access.csrf,id:control.value})});control.selectedOptions[0]?.remove();setSelect(control,'');locationTouched=false;}catch(error){alert(error.message);}};
    q('#ttSdAddMill',form)?.addEventListener('click',()=>addLocation('External Mill',exMill));
    q('#ttSdRemoveMill',form)?.addEventListener('click',()=>removeLocation(exMill));
    q('#ttSdAddStock',form)?.addEventListener('click',()=>addLocation('Stock Location',stock));
    q('#ttSdRemoveStock',form)?.addEventListener('click',()=>removeLocation(stock));
    q('#ttSdLinkMill',form)?.addEventListener('click',async()=>{if(!supplier.value||!exMill.value)return alert('Select a supplier and Ex-Mill first.');try{const result=await json(api,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'link_supplier_mill',csrf:access.csrf,supplierId:supplier.value,millId:exMill.value})});Object.assign(sodaData,result);alert('Supplier linked to the selected External Mill.');}catch(error){alert(error.message);}});
    syncRoute(true);suggestSupplierMill();
    q('#ttSdReset', form).onclick = () => record ? renderSodaSearch(host) : q('.tt-window-close', host).click();
    form.onsubmit = async event => {
      event.preventDefault();
      const payload = {
        action: record ? 'amend' : 'create', id:record?.id || '', requestKey:createRequestKey, csrf:access.csrf, entity:entity(),
        sodaDate:q('#ttSdDate', form).value,purchaseProductId:productSelect.value,
        broker:q('#ttSdBroker', form).value,supplierId:supplier.value,party:supplier.value?(supplier.selectedOptions?.[0]?.textContent||''):'',readyRoute:route.value,locationId:route.value==='EX_MILL'?exMill.value:stock.value,
        expectedTrucks:q('#ttSdTrucks', form).value.trim(), qtyFromMT:route.value==='EX_MILL'?q('#ttSdTarget',form).value.trim():q('#ttSdMin', form).value.trim(), qtyToMT:route.value==='EX_MILL'?q('#ttSdTarget',form).value.trim():q('#ttSdMax', form).value.trim(),
        rate:q('#ttSdRate', form).value.trim(), rateUnit:q('#ttSdUnit', form).value, paymentTermType:terms.value, creditDays:days.value.trim(), arrivalDueDate:q('#ttSdDue', form).value,
        buyingForAllocations:buyingWrap.hidden?[]:qa('[data-buying-row]',buyingRows).map(row=>({targetId:q('[data-buying-target]',row).value,quantityKg:Number(q('[data-buying-kg]',row).value)*1000})),
        terms:q('#ttSdConditions', form).value.trim(), remarks:q('#ttSdRemarks', form).value.trim(), reason:q('#ttSdReason', form)?.value.trim() || ''
      };
      const button = q('[type="submit"]', form); button.disabled = true;
      try {
        const result = await json(api, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload)});
        sodaData = {...sodaData,...result};
        alert(record ? `Soda ${record.sodaNo} amended. The change and reason were added to its audit history.` : `Soda ${result.created?.sodaNo || 'number'} saved. No ledger entry was posted.`);
        if (record) renderSodaSearch(host); else renderSodaNew(host);
      } catch (error) { alert(error.message); button.disabled = false; }
    };
  }

  const sodaDate = value => window.TT_DATE?.display(value) || String(value || '').replace(/^(\d{4})-(\d{2})-(\d{2})$/, '$3-$2-$1');
  const sodaStatus = row => row.calculatedStatus === 'Completed' ? 'SODA COMPLETE AND CLOSED' : row.calculatedStatus || row.status || 'Open';
  function sodaFields(row) {
    return [['SODA number',row.sodaNo],['Date',sodaDate(row.sodaDate)],['Broker',row.broker||'Direct purchase'],['Supplier',row.party||'—'],['Product',row.displayName||[row.commodity,row.variety,row.riceType,row.brokenGrade,row.productStage].filter(Boolean).join(' · ')],['Quantity',`${money(Number(row.qtyFromKg||0)/1000)}${Number(row.qtyToKg)>Number(row.qtyFromKg)?' – '+money(Number(row.qtyToKg)/1000):''} MT${row.expectedTrucks?' · '+row.expectedTrucks+' trucks / containers':''}`],['Rate',`PKR ${money(row.rate??row.ratePerKg)} per ${row.rateUnit==='MAUND'?'maund (40 kg)':'kg'}`],['Delivery location',row.locationName||row.location||'—'],['Route',row.readyRoute==='EX_MILL'?'Ex-Mill container loading':'Delivery to mill / stock'],['Delivery by',sodaDate(row.arrivalDueDate||row.deliveryDeadline)||'—'],['Payment terms',row.paymentTermType==='CREDIT'?`Credit · ${row.creditDays||0} days`:'Cash · arrival + 2 days'],['Terms / conditions',row.terms||'—'],['Agreed remarks',row.remarks||'—']];
  }
  function sodaPrintFields(row) {
    const identity = value => String(value || '').trim().toLowerCase().replace(/\s+/g, ' ');
    const savedName = String(row.locationName || row.location || '').trim();
    const locationId = String(row.locationId || row.exMillId || '');
    const locations = sodaData?.locations || [];
    const location = locationId ? locations.find(item => String(item.id) === locationId) : locations.find(item => savedName && identity(item.name) === identity(savedName));
    const name = savedName || location?.name || '';
    const ownMill = row.entity === 'TTI' && ['tti rice mills', 'tti rice mill', 'trans trade rice mill', 'transtrade rice mill'].includes(identity(name));
    const address = String(row.locationAddress || location?.address || (ownMill ? 'S-94/1, Estate Avenue, S.I.T.E., Karachi-Pakistan' : '')).trim();
    const exMill = row.readyRoute === 'EX_MILL' || (!row.readyRoute && row.movementRole === 'LIFT_FROM');
    const label = exMill ? 'TO BE LOADED FROM:' : 'DELIVERY TO:';
    const details = [ownMill ? 'TRANSTRADE RICE MILL' : name || 'Mill / location not recorded', address].filter(Boolean).join('\n');
    return sodaFields(row).slice(2).filter(([field]) => field !== 'Route').map(([field, value]) => field === 'Delivery location' ? [label, details] : [field, value]);
  }
  function sodaContract(row) {
    const companies={TTI:'Transtrade International',BRM:'Buksh Rice Mills'};
    return `<article class="soda-contract"><header><strong>${esc(companies[row.entity]||row.entity)}</strong><span>PURCHASE SODA / CONTRACT</span></header><div class="contract-ref"><b>SODA ${esc(row.sodaNo)}</b><b>${esc(sodaDate(row.sodaDate))}</b></div><dl>${sodaPrintFields(row).map(([label,value])=>`<div${label.endsWith(':')?' class="soda-location"':''}><dt>${esc(label)}</dt><dd>${esc(value)}</dd></div>`).join('')}</dl><footer>Broker / Supplier confirmation ____________________</footer></article>`;
  }
  function printSodas(rows, summary = false, label = '') {
    if(!rows.length)return alert('No SODAs match these print filters.');
    const legacyFilename=(summary?`SODA Register - ${label}`:rows.length===1?`${rows[0].broker||rows[0].party||'Direct purchase'} - ${rows[0].sodaNo}`:`SODA Contracts - ${label}`).replace(/[<>:"/\\|?*\x00-\x1f]/g,' ').replace(/\s+/g,' ').trim();
    const parties=[...new Set(rows.map(row=>row.broker||row.party).filter(Boolean))];
    const dates=rows.map(row=>row.sodaDate).filter(Boolean).sort();
    const filename=window.TT_DOCUMENT_NAME?.name({party:parties.length===1?parties[0]:'',company:rows[0].entity||entity(),title:summary?'SODA Register':'SODA',reference:rows.length===1?rows[0].sodaNo:'',from:rows.length>1?dates[0]:'',to:rows.length>1?dates.at(-1):''})||legacyFilename;
    const popup=window.TT_PRINT.create();
    const originalTitle=document.title;document.title=filename;
    popup.document.open();
    popup.document.write(`<!doctype html><html><head><meta charset="utf-8"><title data-tt-filename>${esc(filename)}</title><style>@page{size:A4 portrait;margin:10mm}*{box-sizing:border-box}body{font:11px Arial,sans-serif;color:#20352e;margin:0}header{text-align:center;border-bottom:2px solid #22624c;padding-bottom:6px}header strong{display:block;font-size:22px}header span{display:block;font-size:12px;letter-spacing:1px;margin-top:4px}.soda-contract{font-size:13px;font-weight:700;line-height:1.3;width:100%;min-height:126mm;padding:6mm 3mm;break-inside:avoid;page-break-inside:avoid}.soda-contract:nth-of-type(2n){break-after:page;page-break-after:always}.soda-contract:last-child{break-after:auto;page-break-after:auto}.contract-ref{display:flex;justify-content:space-between;padding:9px 0;font-size:13px}dl{margin:0;display:grid;grid-template-columns:1fr 1fr;border-top:1px solid #d4dfd9}dl div{display:flex;gap:8px;border-bottom:1px solid #d4dfd9;padding:5px 3px;min-width:0}dl div:nth-last-child(-n+2){grid-column:1/-1}dt{font-weight:bold;flex:0 0 90px}.soda-location{grid-column:1/-1}.soda-location dt{flex-basis:145px}dd{margin:0;overflow-wrap:anywhere;white-space:pre-wrap}footer{padding-top:14px;font-size:12px}table{width:100%;border-collapse:collapse;font-size:10px}th,td{border:1px solid #cbd8d1;padding:6px;text-align:left;overflow-wrap:anywhere}th{background:#edf5ef}thead{display:table-header-group}tr{break-inside:avoid}h1{font-size:18px}p{white-space:pre-wrap}</style></head><body>${summary?`<h1>SODA Register · ${esc(rows[0].entity||entity())}</h1><p>${esc(label)} · ${rows.length} SODA(s)</p><table><thead><tr>${['Date','SODA','Broker / Supplier','Product','Quantity MT','Rate / basis','Status'].map(v=>`<th>${v}</th>`).join('')}</tr></thead><tbody>${rows.map(row=>`<tr><td>${esc(sodaDate(row.sodaDate))}</td><td>${esc(row.sodaNo)}</td><td>${esc(row.broker||'Direct')}<br>${esc(row.party||'')}</td><td>${esc(row.displayName||row.variety||row.commodity)}</td><td>${money(Number(row.qtyToKg||row.qtyFromKg||0)/1000)}</td><td>${money(row.rate??row.ratePerKg)} / ${esc(row.rateUnit||'KG')}</td><td>${esc(sodaStatus(row))}</td></tr>`).join('')}</tbody></table>`:rows.map(sodaContract).join('')}</body></html>`);
    popup.document.close();popup.addEventListener('afterprint',()=>{if(document.title===filename)document.title=originalTitle;},{once:true});popup.print();
  }
  function printSoda(row) { printSodas([row]); }
  function renderSodaSearch(host, term = '') { renderSodaNew(host, term); }

  function bindSodaRows(host, back) {
    const body=q('.tt-window-body',host);
    qa('[data-soda-print]',body).forEach(button=>button.onclick=event=>{event.stopPropagation();const row=sodaData.sodas.find(item=>item.id===button.dataset.sodaPrint);if(row)printSoda(row);});
    qa('[data-soda-view]',body).forEach(element=>{
      const open=()=>{
        const record=sodaData.sodas.find(row=>row.id===element.dataset.sodaView);if(!record)return;
        activeSoda=record;
        // Detach, rather than recreate, the originating form: unfinished values and listeners survive.
        const scroll=body.scrollTop;
        const windowScroll=q('.tt-window',host)?.scrollTop||0;
        const saved=document.createDocumentFragment();while(body.firstChild)saved.appendChild(body.firstChild);
        const restore=()=>{body.replaceChildren(saved);body.scrollTop=scroll;if(q('.tt-window',host))q('.tt-window',host).scrollTop=windowScroll;};
        const details=()=>{
          body.innerHTML=`<div class="tt-modebar"><button data-back-soda>← SODAs</button><button type="button" data-pencil-soda aria-label="Edit SODA ${esc(record.sodaNo)}" title="Edit SODA" style="background:#e5a126;flex:0 0 38px;padding:8px">✎</button><button type="button" data-print-contract style="background:#22624c;color:white">Print Contract</button>${access.super?'<button type="button" data-delete-soda style="color:#a22">Delete unused SODA</button>':''}</div><div class="tt-record-card"><h3>SODA ${esc(record.sodaNo)} · ${esc(sodaStatus(record))}</h3><dl class="tt-soda-details">${sodaFields(record).map(([label,value])=>`<div><dt>${esc(label)}</dt><dd>${esc(value)}</dd></div>`).join('')}</dl><h4>Buying for · internal only</h4><p>${record.productStage==='RAW'&&(record.commodity||'RICE')==='RICE'?'Automatic FIFO':(record.buyingForAllocations||[]).map(a=>`${esc((sodaData.buyingForTargets||[]).find(t=>t.id===a.targetId)?.reference||a.targetId)}: ${money(a.quantityKg/1000)} MT`).join(' · ')||'General stock'}</p><h4>Completion checks</h4><p>Received / lifted: ${money(Number(record.receivedKg||0)/1000)} MT · Billed: PKR ${money(record.billedAmount)} · Settled: PKR ${money(record.paidAmount)} · Outstanding: PKR ${money(record.payableOutstanding)}</p><p>${esc((record.completionBlockers||[]).join(' · ')||sodaStatus(record))}</p>${record.receipts?.length?`<details><summary>Linked receipts / liftings (${record.receipts.length})</summary>${record.receipts.map(item=>`<p>${esc(sodaDate(item.date))} · ${esc(item.truck||item.id||'')} · ${money(item.kg)} kg · ${esc(item.billId||'Bill pending')}</p>`).join('')}</details>`:''}${record.audit?.length?`<details><summary>Amendment history</summary>${record.audit.map(item=>`<details><summary>${esc(sodaDate(item.at))} · ${esc(item.by)} · ${esc(item.reason)}</summary>${Object.entries(item.changes||{}).map(([field,change])=>`<p><b>${esc(field)}</b>: ${esc(typeof change.from==='object'?JSON.stringify(change.from):change.from)} → ${esc(typeof change.to==='object'?JSON.stringify(change.to):change.to)}</p>`).join('')}</details>`).join('')}</details>`:''}</div>`;
          q('[data-back-soda]',body).onclick=restore;
          q('[data-print-contract]',body).onclick=()=>printSoda(record);
          if(q('[data-delete-soda]',body))q('[data-delete-soda]',body).onclick=async()=>{if(!confirm(`Permanently delete unused SODA ${record.sodaNo}? Linked records cannot be deleted.`))return;const reason=prompt('Reason for permanent deletion:','Entered in error');if(!reason?.trim())return;try{sodaData=await json(api,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete',csrf:access.csrf,entity:entity(),id:record.id,reason:reason.trim()})});renderSodaNew(host);}catch(error){alert(error.message);}};
          q('[data-pencil-soda]',body).onclick=()=>{
            body.innerHTML=`<div class="tt-modebar"><button data-back-detail>← SODA details</button></div>${sodaForm(record)}`;
            q('[data-back-detail]',body).onclick=details;bindSodaForm(host,record);q('#ttSdReset',body).onclick=details;body.scrollTop=0;window.TT_FORM_VIEWPORT?.open(host);
          };
          body.scrollTop=0;
          window.TT_FORM_VIEWPORT?.open(host);
        };details();
      };
      element.onclick=open;element.onkeydown=event=>{if(event.target===element&&['Enter',' '].includes(event.key)){event.preventDefault();open();}};
    });
  }

  function renderSodaNew(host, term = '') {
    const body=q('.tt-window-body',host);
    if(!q('#ttSodaPresentationStyle')){const style=document.createElement('style');style.id='ttSodaPresentationStyle';style.textContent='.tt-soda-table{table-layout:fixed;width:100%;min-width:0!important}.tt-soda-table th,.tt-soda-table td{white-space:normal!important;overflow-wrap:anywhere;padding:7px!important}.tt-soda-table tr[data-soda-view]{cursor:pointer}.tt-soda-table tr[data-soda-view]:hover,.tt-soda-table tr[data-soda-view]:focus{background:#edf5f1;outline:2px solid #8cae9e}.tt-soda-toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.tt-soda-toolbar input{flex:1;min-width:180px}.tt-soda-details{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px}.tt-soda-details dt{font-weight:bold}.tt-soda-details dd{margin:3px 0;white-space:pre-wrap}.tt-soda-print-filters{display:flex;gap:12px;flex-wrap:wrap;padding:12px 0}.tt-soda-print-filters label{display:grid;gap:5px}.tt-soda-print-filters select{max-width:240px}';document.head.appendChild(style);}
    body.innerHTML=`<div class="tt-modebar"><button class="active" data-soda-new>New SODA</button></div>${sodaForm()}<section class="tt-records"><h3>Recent SODAs</h3><div class="tt-soda-toolbar"><input id="ttSodaSearch" data-master-ignore aria-label="Search all SODAs" placeholder="Search SODA, broker, supplier, product or date" value="${esc(term)}"><button type="button" data-print-sodas style="background:#22624c;color:white">Print SODAs</button></div><form class="tt-soda-print-filters" hidden><label>From date<input name="from" type="date" required value="${today()}"></label><label>To date<input name="to" type="date" required value="${today()}"></label><label>Broker<select name="broker"><option value="">All brokers</option>${[...new Set(sodaData.sodas.map(row=>row.broker).filter(Boolean))].sort().map(name=>`<option value="${esc(name)}">${esc(name)}</option>`).join('')}</select></label><label>Format<select name="format"><option value="summary">Summary Register</option><option value="contracts">Individual Contracts</option></select></label><button type="submit">Print</button><p data-print-error role="alert"></p></form><div class="tableWrap"><table class="tt-soda-table"><colgroup><col style="width:12%"><col style="width:9%"><col style="width:21%"><col style="width:21%"><col style="width:11%"><col style="width:19%"><col style="width:7%"></colgroup><thead><tr><th>Date</th><th>SODA</th><th>Supplier / Broker</th><th>Product</th><th>Quantity</th><th>Status</th><th>Print</th></tr></thead><tbody data-soda-results></tbody></table></div></section>`;
    const results=q('[data-soda-results]',body);
    const filter=()=>{
      const needle=q('#ttSodaSearch',body).value.trim().toLowerCase();
      const rows=sodaData.sodas.filter(row=>!needle||[row.sodaNo,row.broker,row.party,row.commodity,row.variety,row.displayName,row.location,sodaStatus(row),row.sodaDate,sodaDate(row.sodaDate)].some(value=>String(value||'').toLowerCase().includes(needle)));
      results.innerHTML=rows.length?rows.map(row=>`<tr tabindex="0" data-soda-view="${esc(row.id)}" aria-label="Open SODA ${esc(row.sodaNo)}"><td>${esc(sodaDate(row.sodaDate))}</td><td><b>${esc(row.sodaNo)}</b></td><td>${esc(row.party||'—')}<br><small>${esc(row.broker||'Direct purchase')}</small></td><td>${esc(row.displayName||row.variety||row.commodity)}</td><td>${money(Number(row.qtyToKg||row.qtyFromKg||0)/1000)} MT</td><td>${esc(sodaStatus(row))}</td><td><button type="button" data-soda-print="${esc(row.id)}" aria-label="Print SODA ${esc(row.sodaNo)}" title="Print Contract">⎙</button></td></tr>`).join(''):'<tr><td colspan="7">No matching SODAs.</td></tr>';
      bindSodaRows(host,()=>renderSodaNew(host,needle));
    };
    q('#ttSodaSearch',body).oninput=filter;
    q('[data-soda-new]',body).onclick=()=>{q('#ttSodaForm',body).scrollIntoView({block:'start'});q('#ttSdDate',body).focus();};
    const printForm=q('.tt-soda-print-filters',body);
    q('[data-print-sodas]',body).onclick=()=>{printForm.hidden=!printForm.hidden;};
    printForm.onsubmit=event=>{event.preventDefault();const {from,to,broker,format}=printForm.elements;const error=q('[data-print-error]',printForm);if(from.value>to.value){error.textContent='From date must be on or before To date.';return;}const rows=sodaData.sodas.filter(row=>row.sodaDate>=from.value&&row.sodaDate<=to.value&&(!broker.value||row.broker===broker.value));if(!rows.length){error.textContent='No SODAs match these filters.';return;}error.textContent='';printSodas(rows,format.value==='summary',`${broker.value||'All brokers'} · ${sodaDate(from.value)} to ${sodaDate(to.value)}`);};
    bindSodaForm(host);filter();
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
      const detailHtml = record => {
        const lines = Array.isArray(record.data?.lines) ? record.data.lines : [];
        if (!lines.length) return `<p class="tt-note">${esc(record.data?.narration || record.data?.remarks || 'Open the linked record to see its saved details.')}</p>`;
        return `<div class="tableWrap"><table class="tt-entry-lines"><thead><tr><th>Account</th><th>Details</th><th class="tt-money">Debit</th><th class="tt-money">Credit</th></tr></thead><tbody>${lines.map(line => `<tr><td><b>${esc(line.account || '')}</b><br>${esc(line.accountName || '')}</td><td>${esc([line.bankName, line.counterparty || line.party, line.bankReference, line.paymentNarration].filter(Boolean).join(' · '))}</td><td class="tt-money">${money(line.debit)}</td><td class="tt-money">${money(line.credit)}</td></tr>`).join('')}</tbody></table></div><p class="tt-note">${esc(record.data?.narration || '')}</p>`;
      };
      results.innerHTML = data.results.length ? data.results.map((record, i) => {
        const ref = record.reference && record.reference !== record.title ? ` · Ref ${esc(displayRef(record.reference))}` : '';
        const heading = record.postId ? `Post ID ${esc(displayRef(record.postId))}` : `${esc(record.type)} ${esc(record.title)}`;
        return `<article class="tt-record-card"><b>${heading}</b><div class="tt-note">${esc(record.type)} · ${esc(record.date || '')}${ref} · ${esc(record.party || '')} · PKR ${esc(money(record.amount || record.data?.totalDebit || 0))}</div><div class="tt-record-actions"><button class="btn" data-view-record="${i}">View entries</button>${record.printable ? `<button class="btn" data-print-record="${i}">Print Voucher</button>` : ''}${record.amendRecord?`<button class="btn" data-amend-record="${i}">AMEND BILL</button>`:''}</div><div data-record-detail="${i}" hidden>${detailHtml(record)}</div></article>`;
      }).join('') : '<div class="tt-record-card">No matching previous record.</div>';
      qa('[data-view-record]', results).forEach(button => button.onclick = () => { const detail=q(`[data-record-detail="${button.dataset.viewRecord}"]`, results); detail.hidden=!detail.hidden; button.textContent=detail.hidden?'View entries':'Hide entries'; });
      qa('[data-amend-record]',results).forEach(button=>button.onclick=()=>openSavedBill(data.results[Number(button.dataset.amendRecord)]).catch(error=>alert(error.message)));
      qa('[data-print-record]', results).forEach(button => button.onclick = () => printVoucher(data.results[Number(button.dataset.printRecord)]));
    } catch (error) { results.innerHTML = `<div class="tt-record-card">${esc(error.message)}</div>`; }
  }

  function openSearch(initial = '') {
    const host = layer('ttSearchLayer', 'Search Previous Accounts Entry');
    q('.tt-window-body', host).innerHTML = `<div class="tt-searchbar"><input id="ttUniversalSearch" data-master-ignore value="${esc(typeof initial === 'string' ? initial : '')}" autofocus placeholder="Voucher, bill, Soda, truck, cheque, shipment, container, B/L, party, date or amount"><button type="button">Search</button></div><div id="ttSearchResults"></div>`;
    const go = () => runSearch(host, q('#ttUniversalSearch', host).value);
    q('.tt-searchbar button', host).onclick = go;
    q('#ttUniversalSearch', host).onkeydown = event => { if (event.key === 'Enter') go(); };
    if (typeof initial === 'string' && initial.trim()) go(); else runSearch(host, '');
  }

  async function printVoucher(record) {
    const details=record?.data||{};
    const id=String(details.journalId||details.chequeIssueJournalId||details.journal?.id||details.postingJournalIds?.[0]||(Array.isArray(details.lines)?details.id:'')||'').trim();
    if(!id)return alert('A saved journal is required to print this voucher. Open the linked Post ID in the ledger.');

    try{
      const response=await fetch('../api/accounts_ledger_browser.php?'+new URLSearchParams({entity:details.entity||entity(),account:'POSTS',postId:id,to:today()}),{credentials:'same-origin'});
      const result=await response.json();if(!response.ok||!result.ok)throw Error(result.error||'Saved posting unavailable.');
      const journal=result.post,lines=Array.isArray(journal.lines)?journal.lines:[];
      if(!lines.length)throw Error('This posting has no accounting lines to print.');
      const html=window.TT_VOUCHER_HTML(journal,{masters:access.masters,party:details.party||record?.party,allocations:details.allocations});
      window.TT_PRINT.html(html);
    }catch(error){alert('Voucher could not be printed: '+error.message)}
  }

  function printSalesTaxRows(rows) {
    const w=window.TT_PRINT.create();if(!w)return alert('Allow popups to print the Sales Tax document index.');
    w.document.write(`<!doctype html><title data-tt-filename>${esc(window.TT_DOCUMENT_NAME?.name({party:[...new Set(rows.map(r=>r.customer||r.party).filter(Boolean))].length===1?(rows[0]?.customer||rows[0]?.party):'',company:entity(),title:'Sales Tax Documents',from:q('#ttTaxFrom')?.value,to:q('#ttTaxTo')?.value})||'Sales Tax Documents')}</title><style>body{font:12px Arial;color:#172433;padding:28px}h1{text-align:center}table{width:100%;border-collapse:collapse}th,td{border:1px solid #9ca9b4;padding:7px;vertical-align:top}small{color:#5e6b76}@media print{button{display:none}}</style><h1>Sales Tax — ${esc(q('.entityBtn.active strong')?.textContent||entity())}</h1><table><thead><tr><th>Date</th><th>Customer / Contract</th><th>Invoice / GD / B/L</th><th>Documents and Bank Advices</th></tr></thead><tbody>${rows.map(row=>`<tr><td>${esc(row.dateDisplay||row.date||'')}</td><td><b>${esc(row.customer)}</b><br>${esc(row.contractRef)} · ${esc(row.lotRef)}</td><td>${esc(row.commercialInvoice||row.customsInvoice||'')}<br>GD ${esc((row.gdRefs||[]).join(', ')||'—')}<br>B/L ${esc(row.blNo||'—')}</td><td>${(row.documents||[]).map(doc=>esc(doc.name)).join('<br>')||'No stored export file'}${(row.advices||[]).map(a=>`<br><b>${esc(a.receiptNo)}</b> · ${esc(a.bankAdviceRef)} · ${esc(a.currency)} ${money(a.foreignAmount)}${a.paperRef?' · '+esc(a.paperRef):''}${a.downloadUrl?' · Credit advice uploaded':''}`).join('')}</td></tr>`).join('')}</tbody></table><button onclick="print()">Print</button>`);w.document.close();w.print();
  }

  async function openSalesTax() {
    if(entity()==='TG')return alert('Pakistan Sales Tax documents belong to TTI or BRM, not TG books.');
    const host=layer('ttSalesTaxLayer','Sales Tax');const body=q('.tt-window-body',host);let rows=[],requestSequence=0,loadedKey='';
    body.innerHTML=`<div class="tt-form"><div class="tt-note" style="margin:0 0 12px">Search saved Exports documents, dated archive packs and linked Accounts bank / tax advices. Archive packs retain the documents available when prepared; review their date against the current invoice.</div><div class="tt-tax-filters"><label>From Date<input id="ttTaxFrom" type="date"></label><label>To Date<input id="ttTaxTo" type="date"></label><label class="wide">Customer, contract, invoice, GD, B/L or shipment<input id="ttTaxQuery" placeholder="Type any known reference"></label></div><div class="tt-tax-actions"><button class="btn" type="button" id="ttTaxPrint" disabled>Print selected / results</button><button class="btn" type="button" id="ttTaxDownload" disabled>Download selected files</button><button class="btn primary" type="button" id="ttTaxSearch">Search</button></div></div><div id="ttTaxResults"><div class="tt-record-card">Choose a date range or reference, then search.</div></div>`;
    const results=q('#ttTaxResults',body),print=q('#ttTaxPrint',body),download=q('#ttTaxDownload',body);
    const selectedRows=()=>{const chosen=new Set(qa('[data-tax-row]:checked',results).map(x=>Number(x.dataset.taxRow)));return rows.filter((_,i)=>chosen.has(i));};
    const currentKey=()=>JSON.stringify([entity(),q('#ttTaxFrom',body).value,q('#ttTaxTo',body).value,q('#ttTaxQuery',body).value.trim()]);
    const run=async()=>{const sequence=++requestSequence,key=currentKey();rows=[];loadedKey='';print.disabled=true;download.disabled=true;results.innerHTML='<div class="tt-record-card">Searching export and Accounts records…</div>';try{const params=new URLSearchParams({entity:entity(),from:q('#ttTaxFrom',body).value,to:q('#ttTaxTo',body).value,q:q('#ttTaxQuery',body).value.trim()});const data=await json('../api/accounts_sales_tax.php?'+params);if(sequence!==requestSequence||key!==currentKey()||!body.isConnected)return;loadedKey=key;rows=data.rows||[];rows.forEach(row=>{row.dateDisplay=/^(\d{4})-(\d{2})-(\d{2})$/.test(row.date||'')?row.date.replace(/^(\d{4})-(\d{2})-(\d{2})$/,'$3-$2-$1'):row.date||'';});print.disabled=!rows.length;download.disabled=!rows.some(row=>(row.documents||[]).length||(row.advices||[]).some(a=>a.downloadUrl));results.innerHTML=rows.length?`<div class="tt-records"><div class="tableWrap"><table><thead><tr><th>Use</th><th>Date</th><th>Customer / Contract</th><th>Invoice / GD / B/L</th><th>Available documents and advices</th></tr></thead><tbody>${rows.map((row,i)=>`<tr class="tt-tax-row"><td><input type="checkbox" data-tax-row="${i}" checked aria-label="Use ${esc(row.commercialInvoice||row.contractRef)}"></td><td>${esc(row.dateDisplay)}</td><td><b>${esc(row.customer||'—')}</b><br><small>${esc(row.contractRef)} · ${esc(row.lotRef)}</small></td><td><b>${esc(row.commercialInvoice||row.customsInvoice||'—')}</b><br><small>GD ${esc((row.gdRefs||[]).join(', ')||'—')} · B/L ${esc(row.blNo||'—')}</small></td><td><div class="tt-tax-docs">${(row.documents||[]).map(doc=>`<a class="tt-tax-doc" href="${esc(doc.downloadUrl)}" target="_blank" rel="noopener" download>${esc(doc.name)}</a>`).join('')}${!(row.documents||[]).length?'<span class="tt-tax-doc missing">No stored export file</span>':''}${(row.advices||[]).map(a=>a.downloadUrl?`<a class="tt-tax-doc" href="${esc(a.downloadUrl)}" target="_blank" rel="noopener" download>Credit Advice ${esc(a.bankAdviceRef||a.receiptNo)}</a>`:`<span class="tt-tax-doc">Advice ${esc(a.bankAdviceRef||a.receiptNo)} · ${esc(a.currency)} ${money(a.foreignAmount)}${a.paperRef?' · '+esc(a.paperRef):''}</span>`).join('')}</div></td></tr>`).join('')}</tbody></table></div></div>`:'<div class="tt-record-card">No matching export shipment or invoice.</div>';}catch(error){if(sequence!==requestSequence||key!==currentKey()||!body.isConnected)return;rows=[];print.disabled=true;download.disabled=true;results.innerHTML=`<div class="tt-record-card">${esc(error.message)}</div>`;}};
    q('#ttTaxSearch',body).onclick=run;q('#ttTaxQuery',body).onkeydown=event=>{if(event.key==='Enter')run();};print.onclick=()=>{if(loadedKey!==currentKey())return alert('Search again for the current company and filters.');const selected=selectedRows();if(!selected.length)return alert('Select at least one result to print.');printSalesTaxRows(selected);};download.onclick=()=>{if(loadedKey!==currentKey())return alert('Search again for the current company and filters.');const seen=new Set(),docs=selectedRows().flatMap(row=>[...(row.documents||[]),...(row.advices||[])]).filter(doc=>{if(!doc.downloadUrl)return false;const key=doc.id?'export:'+doc.id:doc.downloadUrl;if(seen.has(key))return false;seen.add(key);return true;});if(!docs.length)return alert('The selected rows do not have stored files.');docs.forEach((doc,i)=>setTimeout(()=>window.open(doc.downloadUrl,'_blank','noopener,noreferrer'),i*180));};
  }

  async function openOtherExportExpense() {
    const host=layer('ttOtherExportExpense','Other Export Expense'),body=q('.tt-window-body',host);
    body.innerHTML='<div class="tt-form">Loading company payment accounts…</div>';
    try {
      const data=await json('../api/expenses_v1.php?entity='+encodeURIComponent(entity()));
      const accounts=data.paymentAccounts||[];
      body.innerHTML=`<form class="tt-form" id="ttExportExpenseForm"><p>Enter export supplies or shared export costs such as craft paper, silica gel or seals. Shipment-specific supplier bills belong under their shipment icons.</p><div class="tt-form-grid"><label>Date<input type="date" name="paymentDate" value="${esc(today())}" required></label><label>Paid from bank or cash<select id="ttExportExpenseBank" name="paymentAccountId" required><option value="">Choose company account</option>${accounts.map(account=>`<option value="${esc(account.id)}" ${account.isDefault===2?'selected':''}>${esc(account.label)}</option>`).join('')}</select></label><label>Payee<input name="payee" required></label><label>Amount (PKR)<input type="number" min="0.01" step="0.01" name="amount" required></label><label>Supplier bill reference (optional)<input name="reference" placeholder="Supplier bill / invoice reference"></label><label class="wide">Export expense description<input name="description" placeholder="e.g. silica gel for export packing" required></label></div><div role="alert" id="ttExportExpenseError" class="tt-note"></div><div class="tt-form-actions"><button type="submit" class="btn primary">Post export expense</button></div></form>${(data.generalExpenses||[]).filter(row=>row.expenseType==='EXPORT').slice(-12).reverse().map(row=>`<div class="tt-record-card">${esc(row.paymentDate)} · ${esc(row.payee)} · PKR ${money(row.amount)} · ${esc(row.description)} <b>${esc(row.journalId)}</b></div>`).join('')}`;
      const form=q('#ttExportExpenseForm',body);
      window.TT_BANK_PAYMENT_DETAILS?.mount('ttExportExpenseBank',accounts);
      const requestKey=crypto.randomUUID();
      form.onsubmit=async event=>{
        event.preventDefault();const submit=q('[type="submit"]',form);submit.disabled=true;
        const payload=Object.fromEntries(new FormData(form));
        try {const tracking=window.TT_BANK_PAYMENT_DETAILS?.read('ttExportExpenseBank',payload.paymentDate)||{};const result=await json('../api/expenses_v1.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({...payload,...tracking,action:'pay_export_expense',entity:entity(),requestKey,csrf:access.csrf})});body.innerHTML=`<div class="tt-record-card"><h3>Export expense posted</h3><p>Journal voucher <b>${esc(result.result?.journalId||'')}</b></p><button type="button" class="btn" id="ttAnotherExportExpense">Enter another</button></div>`;q('#ttAnotherExportExpense',body).onclick=openOtherExportExpense;}
        catch(error){q('#ttExportExpenseError',form).textContent=error.message||String(error);submit.disabled=false;}
      };
    }catch(error){body.textContent=error.message||String(error);}
  }

  function openBillDesk(kind,action={}) {
    const names={bags:'Bags',other:'Other Purchases',transport:'Transport',clearing:'Clearing Agent',fumigation:'Fumigation',inspection:'Inspection'},host=layer('ttBillDesk',names[kind]||'Supplier Bills'),body=q('.tt-window-body',host);
    body.innerHTML='<div class="tt-modebar"><button class="active" data-post>Post Bill</button><button data-payment>Payment</button></div>';
    q('[data-post]',body).onclick=()=>{q('.tt-window-close',host).click();if(['bags','other'].includes(kind))return launch({...action,special:undefined});return openShipmentKind(kind);};
    q('[data-payment]',body).onclick=()=>{q('.tt-window-close',host).click();window.TT_SUPPLIER_SETTLEMENT_UI?.openBills?.(kind.toUpperCase());};
  }
  function openFreightDesk() {
    const host=layer('ttFreightDesk','Freight'),body=q('.tt-window-body',host);
    body.innerHTML=`<div class="tt-modebar"><button class="active" id="ttFreightAgreementOpen">Freight Agreed</button><button id="ttFreightInvoice">POST BILL</button><button id="ttFreightPayment">Payment</button></div>`;
    q('#ttFreightAgreementOpen',body).onclick=()=>{q('.tt-window-close',host).click();openFreightAgreement();};
    q('#ttFreightInvoice',body).onclick=()=>{q('.tt-window-close',host).click();openShipmentKind('freight');};
    q('#ttFreightPayment',body).onclick=()=>{q('.tt-window-close',host).click();window.TT_SUPPLIER_SETTLEMENT_UI?.openBills?.('FREIGHT');};
  }

  async function openFreightAgreement() {
    const host=layer('ttFreightAgreement','Freight Agreed'),body=q('.tt-window-body',host);
    body.innerHTML=`<form class="tt-form" id="ttFreightAgreementForm"><div class="tt-form-grid"><label>Customer<input id="ttFreightCustomer" list="ttFreightCustomers" required><datalist id="ttFreightCustomers"></datalist></label><label class="wide">Sales contract<select id="ttFreightShipment" required><option value="">Choose customer first</option></select></label><label>Forwarder<input name="forwarder" data-master-role="forwarder" placeholder="Optional for direct shipping line"></label><label>Shipping Line<input name="shippingLine" data-master-role="shipping" required></label><label>Agreed freight per container (USD)<input name="ratePerContainer" type="number" min="0.01" step="0.01" required></label><label>Number of containers<input name="containerCount" type="number" min="1" step="1" required></label><label>Discharge port<input name="destinationPort" required></label><label>Loading port<select name="fromPort" required><option value="">Choose loading port</option><option>Karachi Port, Pakistan</option><option>Port Qasim, Pakistan</option></select></label><label>Loading Programme number<input name="loadingProgrammeNo"></label><label>Date agreed<input name="dateAgreed" type="date" value="${today()}" required></label><label class="wide">Remarks<input name="remarks"></label></div><div id="ttFreightAgreementInfo" class="tt-note" role="status"></div><div id="ttFreightAgreementError" class="tt-note" role="alert"></div><div class="tt-form-actions"><button type="submit" class="btn primary">Save agreement</button></div></form>`;
    const form=q('form',body),customer=q('#ttFreightCustomer',form),select=q('#ttFreightShipment',form);let shipment=null,rows=[],sequence=0;
    const lotLabel=row=>row.contract;const requestKey=crypto.randomUUID();
    json('../api/accounts_shipment_lookup.php?scope=freight_agreed&entity='+encodeURIComponent(entity())).then(result=>{q('#ttFreightCustomers',form).innerHTML=(result.customers||[]).map(name=>`<option value="${esc(name)}"></option>`).join('');}).catch(error=>q('#ttFreightAgreementError',form).textContent=error.message);
    customer.oninput=()=>{q('#ttFreightAgreementInfo',form).textContent='';q('#ttFreightAgreementError',form).textContent='';shipment=null;rows=[];select.innerHTML='<option value="">Choose customer first</option>';sequence++;};
    customer.onchange=async()=>{const name=customer.value.trim(),seq=++sequence;shipment=null;select.innerHTML='<option value="">Loading contracts…</option>';try{const result=await json('../api/accounts_shipment_lookup.php?scope=freight_agreed&entity='+encodeURIComponent(entity())+'&customer='+encodeURIComponent(name)+'&q='+encodeURIComponent(name));if(seq!==sequence)return;rows=result.rows||[];select.innerHTML='<option value="">Choose sales contract</option>'+rows.map((row,i)=>`<option value="${i}">${esc(lotLabel(row))}</option>`).join('');if(!rows.length)select.innerHTML='<option value="">No current contracts for this customer</option>';}catch(error){if(seq===sequence)q('#ttFreightAgreementError',form).textContent=error.message;}};
    select.onchange=()=>{q('#ttFreightAgreementInfo',form).textContent='';q('#ttFreightAgreementError',form).textContent='';shipment=select.value===''?null:rows[Number(select.value)];if(!shipment)return;form.elements.destinationPort.value=shipment.portOfDischarge||'';const port=String(shipment.portOfLoading||'').toLowerCase();form.elements.fromPort.value=port.includes('qasim')?'Port Qasim, Pakistan':port.includes('karachi')?'Karachi Port, Pakistan':'';form.elements.loadingProgrammeNo.value=shipment.loadingProgramme||'';form.elements.containerCount.value=shipment.remainingContainers;form.elements.containerCount.max=shipment.remainingContainers;q('#ttFreightAgreementInfo',form).textContent='Contract containers: '+shipment.plannedContainers+' · Already agreed: '+shipment.agreedContainers+' · Remaining: '+shipment.remainingContainers;form.elements.shippingLine.value=shipment.shippingLine||'';form.elements.forwarder.value=shipment.forwarder||'';};
    window.TT_ACCOUNTS_MASTER_CHOICES?.refresh?.();
    form.onsubmit=async event=>{event.preventDefault();const button=q('[type="submit"]',form);if(!shipment)return;button.disabled=true;try{const payload=Object.fromEntries(new FormData(form));const result=await json('../api/accounts_workflows_v1.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({...payload,contractSelection:true,requestKey,action:'save_freight_agreement',entity:entity(),shipmentId:shipment.id||'',contractRef:shipment.contract,customerName:shipment.customer,currency:'USD',csrf:access.csrf})});body.innerHTML=`<div class="tt-record-card"><h3>Freight agreement saved</h3><p>${esc(result.agreement?.id||'')} · USD ${esc(payload.ratePerContainer)} per container.</p><button class="btn" id="ttFreightAgreementDone">Close</button></div>`;q('#ttFreightAgreementDone',body).onclick=()=>q('.tt-window-close',host).click();}catch(error){q('#ttFreightAgreementError',form).textContent=error.message;button.disabled=false;}};
  }

  function openTransportBillForm(firstShipment,saved=null,sources=[]) {
    const host=layer('ttShipmentBillForm','Transporter Bill'),body=q('.tt-window-body',host),company=entity();
    body.innerHTML=`<form id="ttShipmentBillEntry" class="tt-form"><div class="tt-form-grid"><label>Transporter<input id="ttShipmentBillVendor" data-master-role="transporter" required placeholder="Choose Transporter from Business Parties"></label><label>Bill number<input name="invoiceNo" required></label><label>BILL DATE<input name="billDate" type="date" value="${esc(today())}" required></label></div><div id="ttTransportShipments"></div><button type="button" class="btn" id="ttTransportAddShipment">+ Add another shipment</button><div id="ttTransportSearch" hidden><div class="tt-searchbar"><input id="ttTransportQuery" placeholder="Search customer, contract, invoice or loading programme"><button type="button" class="btn" id="ttTransportFind">Search</button></div><div id="ttTransportHits"></div></div><div class="tt-record-card tt-bill-total tt-bill-grand">TOTAL BILL PAYABLE <b>PKR <span id="ttShipmentBillTotal">0.00</span></b></div><label>Bill narration<input name="remarks" placeholder="Optional bill details"></label><div id="ttShipmentBillError" class="tt-note" role="alert"></div><div class="tt-form-actions"><button type="submit" class="btn primary">POST BILL</button></div></form>`;
    const form=q('#ttShipmentBillEntry',body),sections=q('#ttTransportShipments',form),entries=[];let searchSequence=0;
    window.TT_ACCOUNTS_MASTER_CHOICES?.refresh?.();
    const adjustments=card=>qa('.tt-shipment-charge',card).map(row=>({type:q('[data-type]',row).value,description:q('[data-description]',row).value.trim(),amount:Number(q('[data-amount]',row).value)}));
    const calculate=()=>{let total=0;for(const entry of entries){const card=entry.card,base=Number(q('[name=containers]',card).value)*Number(q('[name=rate]',card).value),payable=adjustments(card).reduce((sum,x)=>sum+(x.type==='ADD'?1:-1)*x.amount,base);q('[data-base]',card).textContent=money(base);q('[data-payable]',card).textContent=money(payable);total+=payable;}for(const charge of saved?.adjustments||[])total+=(charge.type==='DEDUCT'?-1:1)*Number(charge.amount);q('#ttShipmentBillTotal',form).textContent=money(total);return total;};
    const showError=message=>{const el=q('#ttShipmentBillError',form);el.textContent=message;el.scrollIntoView({block:'center',behavior:'smooth'});};
    const addShipment=(shipment,original=null)=>{
      if(entries.some(x=>x.shipment.id===shipment.id)){showError('This shipment is already on this bill. Amend its container count instead.');return;}
      const card=document.createElement('section');card.className='tt-record-card tt-transport-shipment';
      card.innerHTML=`<button type="button" class="btn" data-remove-shipment style="display:block;margin-bottom:10px;background:#b42318;color:#fff;border-color:#b42318">Remove shipment</button><b>${esc(shipment.customer)} · ${esc(shipment.contract)} · ${esc(shipment.lot)}</b><p>Invoice ${esc(shipment.commercialInvoice||shipment.customsInvoice||'—')} · B/L ${esc(shipment.bl||'—')} · ${esc(shipment.shippingLine||'—')}</p><p>${esc(shipment.portOfLoading||'—')} → ${esc(shipment.portOfDischarge||'—')} · Containers ${esc((shipment.containers||[]).join(', ')||'No containers saved')}</p><div class="tt-form-grid tt-transport-grid"><label>Loading programme<input name="loadingProgrammeNo" value="${esc(shipment.loadingProgramme||'')}" readonly required></label><label>Containers on this bill<input name="containers" type="number" min="1" step="1" value="${shipment.containers?.length||''}" required></label><label>Rate per container (PKR)<input name="rate" type="number" min="0.01" step="0.01" required></label></div><div class="tt-bill-total">TOTAL BEFORE OTHER CHARGES <b>PKR <span data-base>0.00</span></b></div><b>Other charges and deductions</b><div data-charges></div><div class="tt-form-actions"><button type="button" class="btn" data-add>+ Addition</button><button type="button" class="btn" data-deduct>− Deduction</button></div><div class="tt-bill-total tt-bill-grand">SHIPMENT BILL PAYABLE <b>PKR <span data-payable>0.00</span></b></div>`;
      const addCharge=type=>{const row=document.createElement('div');row.className='tt-bill-adjustment tt-shipment-charge';row.innerHTML='<button type="button" class="btn" data-remove aria-label="Remove charge">−</button><label>Type<select data-type><option value="ADD">Addition</option><option value="DEDUCT">Deduction</option></select></label><label>Description<input data-description required></label><label>Amount (PKR)<input data-amount type="number" min="0.01" step="0.01" required></label>';q('[data-type]',row).value=type;q('[data-remove]',row).onclick=()=>{row.remove();calculate();};q('[data-charges]',card).appendChild(row);return row;};
      q('[data-add]',card).onclick=()=>addCharge('ADD');q('[data-deduct]',card).onclick=()=>addCharge('DEDUCT');
      if(original){q('[name=containers]',card).value=original.containers;q('[name=rate]',card).value=original.rate;for(const charge of original.adjustments||[]){const row=addCharge(charge.type);q('[data-description]',row).value=charge.description;q('[data-amount]',row).value=charge.amount;}if(Number(original.extras)>0){const row=addCharge('ADD');q('[data-description]',row).value=original.remarks||'Original extra charge';q('[data-amount]',row).value=original.extras;}}const entry={shipment,card};entries.push(entry);sections.appendChild(card);
      if(entries.length===1){q('[data-base]',card).id='ttShipmentBillBase';q('[data-add]',card).id='ttShipmentBillAdd';q('[data-deduct]',card).id='ttShipmentBillDeduct';q('[data-charges]',card).id='ttShipmentBillLines';}
      q('[data-remove-shipment]',card).onclick=()=>{entries.splice(entries.indexOf(entry),1);card.remove();calculate();};
      json('../api/accounts_workflows_v1.php?entity='+encodeURIComponent(company)+'&section=transport').then(state=>{if(!card.isConnected||q('[name=rate]',card).value)return;const route=(state.routes||[]).find(x=>String(x.from||'').toLowerCase()===String(shipment.portOfLoading||'').toLowerCase()&&String(x.to||'').toLowerCase()===String(shipment.portOfDischarge||'').toLowerCase());if(route){q('[name=rate]',card).value=route.rate;calculate();}}).catch(()=>{});
      calculate();
    };
    if(saved){q('#ttShipmentBillVendor',form).value=saved.vendor;form.elements.invoiceNo.value=saved.invoiceNo;form.elements.billDate.value=saved.billDate;form.elements.remarks.value=saved.remarks||'';for(const line of saved.lines)addShipment(sources.find(r=>r.id===line.shipmentId),line);const label=document.createElement('label');label.innerHTML='Amendment reason<input name=reason required>';qa('.tt-form-actions',form).slice(-1)[0].before(label);q('[type=submit]',form).textContent='SAVE AMENDMENT';}else addShipment(firstShipment);form.addEventListener('input',calculate);form.addEventListener('change',calculate);form.addEventListener('invalid',event=>showError('Bill not submitted: '+event.target.validationMessage),true);
    if(saved){q('#ttTransportAddShipment',form).hidden=true;for(const entry of entries)q('[data-remove-shipment]',entry.card).hidden=true;if(saved.adjustments?.length){const note=document.createElement('p');note.textContent='Original bill-level adjustments: '+saved.adjustments.map(x=>x.description+' '+(x.type==='DEDUCT'?'−':'+')+' PKR '+money(x.amount)).join(' · ');q('.tt-bill-grand',form).before(note);}}
    q('#ttTransportAddShipment',form).onclick=()=>{for(const entry of entries){entry.card.style.background='#eaf2ff';entry.card.style.borderColor='#8fb6e8';}const searchBox=q('#ttTransportSearch',form);sections.appendChild(searchBox);searchBox.hidden=false;searchBox.scrollIntoView({block:'start',behavior:'smooth'});q('#ttTransportQuery',form).focus({preventScroll:true});};
    const search=async()=>{const term=q('#ttTransportQuery',form).value.trim(),hits=q('#ttTransportHits',form),seq=++searchSequence;if(term.length<2){hits.textContent='Enter at least two characters.';return;}hits.textContent='Searching Exports…';try{const result=await json('../api/accounts_shipment_lookup.php?billKind=TRANSPORT&entity='+encodeURIComponent(company)+'&q='+encodeURIComponent(term));if(seq!==searchSequence)return;const candidates=(result.rows||[]).filter(row=>!entries.some(entry=>entry.shipment.id===row.id));hits.innerHTML=candidates.map((row,i)=>`<div class="tt-record-card"><b>${esc(row.customer)} · ${esc(row.contract)} · ${esc(row.lot)}</b><p>${esc(row.loadingProgramme)} · ${esc(row.portOfDischarge)} · ${row.containers.length} containers</p>${row.billing?.fullyBilled?`<p>Already billed</p>${row.billing.bills.map(b=>`<button type="button" class="btn" data-open-billed="${esc(b.id)}">Open / Edit bill ${esc(b.invoiceNo)} · ${esc(displayRef(b.postId))}</button>`).join('')}`:row.seller==='TG'&&!row.pakistanExporter?'<p>Select Pakistan exporter in Exports before posting.</p>':`<button type="button" class="btn" data-pick="${i}">Add this shipment</button>`}</div>`).join('')||'No linked shipment matched.';qa('[data-pick]',hits).forEach(button=>button.onclick=()=>{addShipment(candidates[Number(button.dataset.pick)]);hits.textContent='';q('#ttTransportSearch',form).hidden=true;const newest=entries[entries.length-1].card;newest.scrollIntoView({block:'start',behavior:'smooth'});q('[name=rate]',newest).focus({preventScroll:true});});}catch(error){if(seq===searchSequence)hits.textContent=error.message;}};
    q('#ttTransportFind',form).onclick=search;q('#ttTransportQuery',form).onkeydown=event=>{if(event.key==='Enter'){event.preventDefault();search();}};
    const showPosted=(bill,existing=false)=>{const amount=calculate(),invoice=form.elements.invoiceNo.value,vendor=bill.vendor||q('#ttShipmentBillVendor',form).value,postId=(bill.postingJournalIds||[]).slice(-1)[0]||'';body.innerHTML=`<div class="tt-record-card tt-bill-confirmation" role="status"><h3>${existing?'BILL ALREADY RECORDED':saved?'BILL AMENDED':'BILL POSTED'}</h3><p>POST ID <b>${esc(displayRef(postId)||'Available in the ledger')}</b> · Bill No. <b>${esc(invoice)}</b></p><p>${esc(vendor)} · PKR <b>${money(amount)}</b> · ${entries.length} shipment(s)</p><p>Supplier bill record ${esc(bill.id||'')}</p><button type="button" class="btn primary" id="ttShipmentBillPay">Pay this bill</button><button type="button" class="btn" id="ttShipmentBillAnother">Post another bill</button><button type="button" class="btn" style="background:#e5a126;color:#172433" id="ttShipmentBillEdit">Edit</button><button type="button" class="btn" id="ttShipmentBillPrint">PRINT VOUCHER</button><button type="button" class="btn" id="ttShipmentBillDone">Close</button></div>`;q('#ttShipmentBillEdit',body).onclick=()=>{q('.tt-window-close',host).click();openSavedBill(bill).catch(error=>alert(error.message));};q('#ttShipmentBillPrint',body).onclick=()=>printVoucher({data:{entity:company,journalId:postId}});q('#ttShipmentBillDone',body).onclick=()=>q('.tt-window-close',host).click();q('#ttShipmentBillPay',body).onclick=()=>{q('.tt-window-close',host).click();window.TT_SUPPLIER_SETTLEMENT_UI?.openBills?.('TRANSPORT',vendor,bill.id);};q('#ttShipmentBillAnother',body).onclick=()=>{q('.tt-window-close',host).click();openShipmentKind('transport');};};
    form.onsubmit=async event=>{event.preventDefault();q('#ttShipmentBillError',form).textContent='';if(company!==entity())return showError('Company changed. Reopen this bill in the correct company.');if(!entries.length||calculate()<=0)return showError('Add a shipment and check the positive bill total.');const vendor=q('#ttShipmentBillVendor',form).value.trim(),invoice=form.elements.invoiceNo.value.trim();const payload={...(saved||{}),id:saved?.id||'',amendment:!!saved,newEntry:!saved,reason:form.elements.reason?.value||'',action:'save_transport_bill',entity:company,vendor,invoiceNo:invoice,billDate:form.elements.billDate.value,shipmentId:entries[0].shipment.id,remarks:form.elements.remarks.value.trim(),lines:entries.map(({shipment,card})=>({shipmentId:shipment.id,loadingProgrammeNo:shipment.loadingProgramme,containers:Number(q('[name=containers]',card).value),from:shipment.portOfLoading,to:shipment.portOfDischarge,rate:Number(q('[name=rate]',card).value),extras:0,remarks:form.elements.remarks.value.trim(),adjustments:adjustments(card)})),adjustments:saved?.adjustments||[],csrf:access.csrf};const button=q('[type=submit]',form);button.disabled=true;try{const saved=await json('../api/accounts_workflows_v1.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});showPosted(saved.bill);}catch(error){if(error.rejected){showError('Bill not posted. '+error.message);button.disabled=false;return;}try{const state=await json('../api/accounts_workflows_v1.php?entity='+encodeURIComponent(company)+'&section=transport'),existing=(state.bills||[]).find(x=>String(x.vendor||'').toLowerCase()===vendor.toLowerCase()&&String(x.invoiceNo||'').toLowerCase()===invoice.toLowerCase());if(existing)return showPosted(existing,true);showError('Bill not recorded. '+error.message);button.disabled=false;}catch(_){showError('Posting status could not be confirmed. Check this supplier invoice in the bill register before trying again. '+error.message);}}};
  }

  function openShipmentBillForm(kind,firstShipment,saved=null,sources=[]) {
    if(kind==='transport')return openTransportBillForm(firstShipment,saved,sources);
    const names={freight:'Freight Forwarder / Shipping',clearing:'Clearing Agent',fumigation:'Fumigation',inspection:'Inspection'},company=entity(),freight=kind==='freight',host=layer('ttShipmentBillForm',names[kind]+' Bill'),body=q('.tt-window-body',host),entries=[];
    body.innerHTML=`<form id="ttShipmentBillEntry" class="tt-form"><div class="tt-form-grid tt-shipment-bill-header"><label class="tt-shipment-vendor">${names[kind]}<input id="ttShipmentBillVendor" data-master-role="${kind}" required></label><label>Bill number<input name="invoiceNo" required></label><label>BILL DATE<input name="billDate" type="date" value="${today()}" required></label>${freight?'<label data-shipping-fx>Shipping line invoice exchange rate<input name="exchangeRate" type="number" min="0.000001" step="any" placeholder="PKR per USD" required></label>':''}</div><div id="ttServiceShipments"></div><div class="tt-bill-total tt-bill-grand">${freight?'TOTAL INVOICE VALUE':'TOTAL BILL PAYABLE'} <b>PKR <span id="ttShipmentBillTotal">0.00</span></b></div><label>Bill narration<input name="remarks"></label><p id="ttShipmentBillError" role="alert" style="color:#a32929"></p><div class="tt-form-actions"><button class="btn primary" type="submit">POST BILL</button></div></form>`;
    const form=q('form',body),sections=q('#ttServiceShipments',form);;
    window.TT_ACCOUNTS_MASTER_CHOICES?.refresh?.();const freightState=freight?json('../api/accounts_workflows_v1.php?entity='+encodeURIComponent(company)+'&section=freight').catch(()=>null):Promise.resolve(null);
    const readCharge=row=>({description:q('[data-description]',row).value.trim(),type:q('[data-type]',row).value,amount:Number(q('[data-amount]',row).value),acceptedAmount:q('[data-accepted]',row)?.value===''?Number(q('[data-amount]',row).value):Number(q('[data-accepted]',row)?.value||q('[data-amount]',row).value),basis:q('[data-basis]',row)?.value||'FIXED',currency:q('[data-currency]',row)?.value||'PKR'});
    const charges=card=>qa('.tt-shipment-charge',card).map(readCharge);
    const chargeCurrency=row=>{const currency=q('[data-currency]',row).value;q('[data-rate-label]',row).textContent='Rate ('+currency+')';q('[data-accepted-label]',row).textContent='Accepted rate ('+currency+')';};
    // PKR charge rates are already in book currency; only USD rates use the invoice exchange rate.
    const roundPkr=value=>Math.sign(value)*Math.round((Math.abs(value)+Number.EPSILON)*100)/100;
    const total=()=>{const usd=freight&&entries.some(({card})=>qa('[data-currency]',card).some(select=>select.value==='USD'));const fxLabel=q('[data-shipping-fx]',form);if(fxLabel){fxLabel.hidden=!usd;form.elements.exchangeRate.required=usd;}let overall=0;for(const {card} of entries){const n=Number(q('[data-containers]',card)?.value||1),fx=Number(form.elements.exchangeRate?.value||0);let amount=0,accepted=0;for(const row of qa('.tt-shipment-charge',card)){const c=readCharge(row),mult=(c.basis==='PER_CONTAINER'?n:1)*(c.currency==='USD'?fx:1),sign=c.type==='DEDUCT'?-1:1;const billed=roundPkr(sign*c.amount*mult);amount+=billed;accepted+=roundPkr(sign*c.acceptedAmount*mult);if(q('[data-pkr]',row))q('[data-pkr]',row).value=money(billed);}q('[data-total]',card).textContent=money(amount);const note=q('[data-dispute-total]',card);if(note)note.textContent=Math.abs(amount-accepted)>.005?'Accepted payable PKR '+money(accepted)+' · Disputed PKR '+money(amount-accepted):'';overall+=amount;}q('#ttShipmentBillTotal',form).textContent=money(overall);return overall};
    const add=(shipment,original=null)=>{if(entries.some(e=>e.shipment.id===shipment.id))return;for(const e of entries){e.card.style.background='#eaf2ff';e.card.style.borderColor='#8fb6e8';}const card=document.createElement('section');card.className='tt-record-card';card.innerHTML=`<div style="display:flex;gap:12px;align-items:center"><h3 style="flex:1">${esc(shipment.customer)} · ${esc(shipment.contract)} · ${esc(shipment.lot)}</h3></div><p>${esc(shipment.loadingProgramme)} · ${esc(shipment.portOfDischarge)} · GD ${esc(shipment.gd||'—')}</p>${freight?`<div class="tt-form-grid"><label>Invoice containers<input data-containers type="number" min="1" step="1" value="${shipment.billing?.remainingContainers??shipment.containerCount??shipment.containers.length??''}" required></label><label>Actual B/L number<input data-bl value="${esc(shipment.bl)}" required></label></div>`:''}<div data-charges></div><div class="tt-form-actions" style="justify-content:flex-start"><button type="button" class="btn" data-add aria-label="Add charge">+</button>${kind==='clearing'?'':'<button type="button" class="btn" data-deduct>− Deduction</button>'}</div><div class="tt-bill-total">${freight?'SHIPMENT INVOICE VALUE':'SHIPMENT BILL PAYABLE'} <b>PKR <span data-total>0.00</span></b></div><p data-dispute-total></p>`;
      const addCharge=(type='ADD',description='',permanent=false)=>{const row=document.createElement('div');row.className='tt-bill-adjustment tt-shipment-charge'+(freight?' tt-bill-freight':'');
        row.innerHTML=freight?`<div>${permanent?'': '<button type="button" class="btn" data-remove aria-label="Remove charge">−</button>'}<input data-type type="hidden" value="${type}"></div><label>${type==='DEDUCT'?'Deduction':'Charge name'}<input data-description required ${permanent?'readonly':''}></label><label>Currency<select data-currency><option value="USD">USD</option><option value="PKR">PKR</option></select></label><label class="ttRowField"><span class="ttRowLabel" data-rate-label>Rate (USD)</span><input data-amount type="number" min="${description==='B/L charges'?'0':'0.01'}" step="0.01" ${description==='B/L charges'?'value="0"':'required'}></label><label>Basis<select data-basis ${permanent?'disabled':''}><option value="PER_CONTAINER">Per container</option><option value="PER_BL">Per B/L</option></select></label><label>Amount (PKR)<input data-pkr readonly value="0.00"></label><details class="tt-freight-dispute"><summary>Disputed amount, if any</summary><label><span data-accepted-label>Accepted rate (USD)</span><input data-accepted type="number" min="0" step="0.01" placeholder="Same as billed"></label></details>`:`<button type="button" class="btn" data-remove aria-label="Remove charge">−</button><label>Type<select data-type><option value="ADD">Addition</option><option value="DEDUCT">Deduction</option></select></label><label>Description<input data-description required></label><label>Amount<input data-amount type="number" min="0.01" step="0.01" required></label>`;
        q('[data-type]',row).value=type;q('[data-description]',row).value=description;if(freight)q('[data-currency]',row).onchange=()=>{chargeCurrency(row);total();};if(description==='B/L charges')q('[data-basis]',row).value='PER_BL';if(q('[data-remove]',row))q('[data-remove]',row).onclick=()=>{row.remove();total()};q('[data-charges]',card).appendChild(row);return row;};
      q('[data-add]',card).onclick=()=>addCharge();if(q('[data-deduct]',card))q('[data-deduct]',card).onclick=()=>addCharge('DEDUCT');sections.appendChild(card);entries.push({shipment,card});if(original){if(freight){q('[data-containers]',card).value=original.containerCount;q('[data-bl]',card).value=original.actualBlNo||saved.actualBlNo||shipment.bl;}(original.billLines||[]).forEach(c=>{const billed=freight?Number(c.billedRate):Number(c.amount),row=addCharge(freight?(billed<0?'DEDUCT':'ADD'):c.type,c.charge||c.description||'',freight&&['Freight','B/L charges'].includes(c.charge));q('[data-amount]',row).value=Math.abs(billed);if(freight){q('[data-basis]',row).value=c.basis==='PER_CONTAINER'?'PER_CONTAINER':'PER_BL';q('[data-currency]',row).value=c.currency||'PKR';q('[data-accepted]',row).value=Number(c.acceptedRate)===Number(c.billedRate)?'':Math.abs(Number(c.acceptedRate));chargeCurrency(row);}});}else{addCharge('ADD',freight?'Freight':names[kind],freight);if(freight)addCharge('ADD','B/L charges',true);}if(freight)freightState.then(state=>{if(!card.isConnected)return;const agreement=(state?.agreements||[]).filter(r=>r.shipmentId===shipment.id||(!r.shipmentId&&r.contractRef===shipment.contract)).sort((a,b)=>String(b.updatedAt||'').localeCompare(String(a.updatedAt||'')))[0];if(agreement){const rate=q('[data-amount]',card);if(!saved&&!rate.value){rate.value=agreement.ratePerContainer;total();}const note=document.createElement('p');note.className='tt-treatment';note.textContent='Freight Agreed · USD '+money(agreement.ratePerContainer)+' per container · '+agreement.destinationPort+' · '+(agreement.forwarder||agreement.shippingLine);card.querySelector('[data-charges]').before(note);}});total();return card;
    };
    if(saved){form.elements.invoiceNo.value=saved.invoiceNo;form.elements.billDate.value=saved.billDate;form.elements.remarks.value=saved.remarks||'';q('#ttShipmentBillVendor',form).value=saved.vendor;if(freight)form.elements.exchangeRate.value=saved.exchangeRate;const original=saved.shipmentSections?.length?saved.shipmentSections:[{shipmentId:saved.shipmentId,containerCount:saved.containerCount,billLines:freight?saved.charges:saved.billLines}];original.forEach((section,index)=>{const source=sources.find(r=>r.id===section.shipmentId),bls=String(saved.actualBlNo||'').split(',').map(x=>x.trim()).filter(Boolean);add(source,{...section,actualBlNo:section.actualBlNo||(bls.length===original.length?bls[index]:source.bl||saved.actualBlNo)});});const label=document.createElement('label');label.textContent='Amendment reason';label.innerHTML+='<input name=reason required>';qa('.tt-form-actions',form).slice(-1)[0].before(label);q('[type=submit]',form).textContent='SAVE AMENDMENT';}else add(firstShipment);form.oninput=total;form.onchange=total;total();
    form.addEventListener('invalid',e=>q('#ttShipmentBillError',form).textContent=e.target.validationMessage,true);
    const showPosted=(bill)=>{const vendor=bill.vendor||q('#ttShipmentBillVendor',form).value,amount=total(),postId=(bill.postingJournalIds||[]).slice(-1)[0]||'';body.innerHTML=`<div class="tt-record-card tt-bill-confirmation" role="status"><h2>${saved?'BILL AMENDED':'BILL POSTED'}</h2><p>POST ID <b>${esc(displayRef(postId)||'Available in the ledger')}</b> · Bill No. <b>${esc(bill.invoiceNo||form.elements.invoiceNo.value)}</b></p><p>${esc(vendor)} · PKR <b>${money(amount)}</b></p><p>${entries.length} shipment(s) · Supplier bill record ${esc(bill.id||'')}</p><div class="tt-form-actions"><button class="btn primary" data-pay>Pay this bill</button><button class="btn" data-another>Post another bill</button><button type="button" class="btn" style="background:#e5a126;color:#172433" data-edit-bill>Edit</button><button type="button" class="btn" data-print>PRINT VOUCHER</button></div></div>`;q('[data-edit-bill]',body).onclick=()=>{q('.tt-window-close',host).click();openSavedBill(bill).catch(error=>alert(error.message));};q('[data-print]',body).onclick=()=>printVoucher({data:{entity:company,journalId:postId}});q('[data-pay]',body).onclick=()=>{q('.tt-window-close',host).click();window.TT_SUPPLIER_SETTLEMENT_UI?.openBills?.(kind.toUpperCase(),vendor,bill.id)};q('[data-another]',body).onclick=()=>{q('.tt-window-close',host).click();openShipmentKind(kind)}};
    form.onsubmit=async e=>{e.preventDefault();const error=q('#ttShipmentBillError',form),button=q('[type=submit]',form),vendor=q('#ttShipmentBillVendor',form).value.trim(),invoiceNo=form.elements.invoiceNo.value.trim();error.textContent='';if(company!==entity()||!entries.length||total()<=0)return error.textContent='Check the company, shipments and positive invoice total.';const payload={...(saved||{}),id:saved?.id||'',amendment:!!saved,newEntry:!saved,reason:form.elements.reason?.value||'',action:freight?'save_freight_bill':'save_service_bill',kind:kind.toUpperCase(),entity:company,vendor,invoiceNo,billDate:form.elements.billDate.value,exchangeRate:Number(form.elements.exchangeRate?.value||0),shippingLine:[...new Set(entries.map(e=>e.shipment.shippingLine).filter(Boolean))].join(', '),actualBlNo:entries.map(e=>q('[data-bl]',e.card)?.value.trim()).filter(Boolean).join(', '),remarks:form.elements.remarks.value.trim(),shipmentSections:entries.map(({shipment,card})=>({shipmentId:shipment.id,actualBlNo:q('[data-bl]',card)?.value.trim()||'',containerCount:Number(q('[data-containers]',card)?.value||shipment.containers.length),billLines:freight?undefined:charges(card),charges:freight?charges(card).filter(c=>c.amount!==0||c.acceptedAmount!==0).map(c=>({charge:c.description,basis:c.basis,currency:c.currency,billedRate:(c.type==='DEDUCT'?-1:1)*c.amount,acceptedRate:(c.type==='DEDUCT'?-1:1)*c.acceptedAmount})):undefined})),csrf:access.csrf};button.disabled=true;try{const saved=await json('../api/accounts_workflows_v1.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});showPosted(saved.bill)}catch(e){if(!e.rejected){try{const state=await json('../api/accounts_workflows_v1.php?entity='+company+'&section='+(freight?'freight':'services')),existing=(state.bills||[]).find(b=>String(b.vendor).toLowerCase()===vendor.toLowerCase()&&String(b.invoiceNo).toLowerCase()===invoiceNo.toLowerCase()&&(freight||b.kind===kind.toUpperCase()));if(existing)return showPosted(existing)}catch(_){error.textContent='Posting status unconfirmed. Check the bill register before retrying.';return}}error.textContent='Bill not posted. '+e.message;button.disabled=false}};
  }


  async function openSavedBill(record) {
    let saved=record?.amendRecord||record?.data||record;
    if(saved?.id&&!saved.editVersion){const current=await json('../api/accounts_search.php?entity='+encodeURIComponent(entity())+'&q='+encodeURIComponent(saved.id));const found=(current.results||[]).find(r=>r.amendRecord?.id===saved.id);if(found)saved=found.amendRecord;}
    if(!saved?.id||saved.entity!==entity())throw Error('Open this bill in its original company.');
    const kind=saved.billKind||saved.kind||(/^FRB-/.test(saved.id)?'FREIGHT':/^TRB-/.test(saved.id)?'TRANSPORT':'');
    const ids=[...new Set([...(saved.shipmentIds||[]),...(saved.shipmentSections||saved.lines||[]).map(r=>r.shipmentId),saved.shipmentId].filter(Boolean))];
    if(!ids.length)throw Error('This historical bill has no shipment link. Open its original bill register to amend it.');
    const sources=await Promise.all(ids.map(async id=>{const result=await json('../api/accounts_shipment_lookup.php?entity='+encodeURIComponent(entity())+'&q='+encodeURIComponent(id));const row=(result.rows||[]).find(r=>r.id===id);if(!row)throw Error('Original shipment '+id+' is unavailable. Its link must be restored before amending this bill.');return row;}));
    q('#ttSearchLayer .tt-window-close')?.click();
    openShipmentBillForm(String(kind).toLowerCase(),sources[0],saved,sources);
  }

  async function openShipmentKind(kind) {
    if(!['freight','transport','clearing','fumigation','inspection'].includes(String(kind||'').toLowerCase()))return openShipmentChooser();
    const host=layer('ttBillShipmentSearch',`Find Shipment · ${kind}`),body=q('.tt-window-body',host);
    body.innerHTML='<div class="tt-form"><p>Search by customer, contract, invoice, Customs invoice, container, B/L, loading programme, shipping line, vessel, port, brand, GD, FI or Bag PO.</p><div class="tt-searchbar"><input id="ttBillShipmentQuery" autofocus placeholder="Enter any shipment reference"><button id="ttBillShipmentGo">Search</button></div><label style="display:block;margin:12px 0"><input type="checkbox" id="ttShowBilledShipments"> Show already billed shipments</label><div id="ttBillShipmentHits" style="margin-top:12px"></div></div>';
    const search=async()=>{const term=q('#ttBillShipmentQuery',host).value.trim(),hits=q('#ttBillShipmentHits',host);if(term.length<2){hits.textContent='Enter at least two characters.';return}hits.textContent='Searching Exports…';try{const result=await json('../api/accounts_shipment_lookup.php?includeBilled='+(q('#ttShowBilledShipments',host).checked?'1':'0')+'&billKind='+encodeURIComponent(kind.toUpperCase())+'&entity='+encodeURIComponent(entity())+'&q='+encodeURIComponent(term));hits.innerHTML=result.rows.length?result.rows.map((row,index)=>`<article class="tt-record-card"><b>${esc(row.customer)} · ${esc(row.contract)} · ${esc(row.lot)}</b><div class="tt-note">Commercial invoice ${esc(row.commercialInvoice||'—')} · Customs ${esc(row.customsInvoice||'—')} · B/L ${esc(row.bl||'—')} · ${esc(row.vessel||'')} ${esc(row.voyage||'')}</div><div class="tt-note">${esc(row.loadingProgramme||'')} · ${esc(row.shippingLine||'')} · ${esc(row.portOfLoading||'')} → ${esc(row.portOfDischarge||'')} · Containers ${esc(row.containers.join(', ')||'—')} · PO ${esc(row.po||'—')}</div>${row.billing?.fullyBilled?`<div class="tt-note">Already billed for this charge type.</div>${row.billing.bills.map(bill=>`<button class="btn" data-open-billed="${esc(bill.id)}">Open / Edit ${esc(bill.invoiceNo||bill.postId||bill.id)}</button>`).join('')}`:row.seller==='TG'&&!row.pakistanExporter?'<div class="tt-note">TG reference is visible in both companies. Select the Pakistan exporter in Exports before posting a company supplier bill.</div>':`<div class="tt-note">${row.billing?.billedContainers?`${row.billing.billedContainers} billed · ${row.billing.remainingContainers} remaining containers`:''}</div><button class="btn" data-tt-pick-shipment="${index}">Confirm this shipment</button>`}</article>`).join(''):'No linked shipment matches. Check the selected company and reference.';qa('[data-open-billed]',hits).forEach(button=>button.onclick=()=>openSavedBill({id:button.dataset.openBilled,entity:entity()}).then(()=>q('.tt-window-close',host)?.click()).catch(error=>{hits.textContent=error.message;}));qa('[data-tt-pick-shipment]',hits).forEach(button=>button.onclick=async()=>{const row=result.rows[Number(button.dataset.ttPickShipment)];q('.tt-window-close',host).click();openShipmentBillForm(kind,row);});}catch(error){hits.textContent=error.message}};
    q('#ttShowBilledShipments',host).onchange=search;q('#ttBillShipmentGo',host).onclick=search;q('#ttBillShipmentQuery',host).onkeydown=event=>{if(event.key==='Enter')search()};
  }

  function openShipmentChooser() {
    const host = layer('ttShipmentLayer', 'Export Shipment Bills');
    const body = q('.tt-window-body', host);
    const choices = ['freight','transport','clearing','fumigation','inspection'];
    const glyphs = ['⚓','▣','◇','✦','✓'];
    body.innerHTML = `<div class="tt-form"><div class="tt-note">Search the export shipment first, then confirm its supplier bill.</div><div class="tt-action-list">${choices.map((kind,i)=>`<button class="tt-action" data-shipment-kind="${i}"><span class="tt-action-mark">${glyphs[i]}</span><span><b>${esc(kind)}</b><small>Find and confirm a linked shipment.</small></span></button>`).join('')}</div></div>`;
    qa('[data-shipment-kind]', body).forEach(button => button.onclick = async () => {
      q('.tt-window-close', host).click();
      await openShipmentKind(choices[Number(button.dataset.shipmentKind)]);
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
    new MutationObserver(records=>{if(records.some(r=>r.type==='attributes'&&r.target.matches?.('.workspace')||r.type==='childList'&&[...r.addedNodes].some(n=>n.nodeType===1&&(n.matches?.('.workspace,input,select,button')||n.querySelector?.('#evPayAmount')))))refresh();}).observe(document.body, {subtree:true, childList:true, attributes:true, attributeFilter:['class']});
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
      const invoice = q('#evInvoiceMode')?.value === 'INVOICE';
      const credit = invoice ? 'Supplier payable · '+(q('#evPayee')?.value || 'Choose supplier') : account.selectedOptions?.[0]?.textContent || 'Select bank or cash account';
      box.innerHTML = `<strong>Accounting treatment before posting</strong><div class="tt-treatment-row"><span>Debit</span><span>${esc(debit)}</span><b>Rs ${money(value)}</b></div><div class="tt-treatment-row"><span>Credit</span><span>${esc(credit)}</span><b>Rs ${money(value)}</b></div><div class="tt-treatment-row tt-treatment-total"><span>Total</span><span>Debit Rs ${money(value)} · Credit Rs ${money(value)}</span><b>${value > 0 && (invoice || account.value) ? 'Balanced' : 'Complete form'}</b></div>`;
    };
    [amount, account, treatment, q('#evPerson'), q('#evPayType'), q('#evInvoiceMode'), q('#evPayee')].filter(Boolean).forEach(control => { control.addEventListener('input', render); control.addEventListener('change', render); });
    render();
  }

  async function init() {
    installStyle();
    buildTopbar();
    buildDesk();
    const bagReturn=new URLSearchParams(location.search).get('return');if(['bag-report','bag-master','production-report'].includes(bagReturn)){const key=bagReturn==='production-report'?'tt_accounts_production_report_return':bagReturn==='bag-master'?'tt_accounts_bag_master_return':'tt_accounts_bag_report_return';try{const saved=JSON.parse(sessionStorage.getItem(key)||'null');sessionStorage.removeItem(key);if(saved?.company===entity()){if(saved.area&&currentAreas().some(area=>area.key===saved.area))await showArea(saved.area);window.scrollTo(0,Number(saved.scroll)||0);if(bagReturn==='bag-master')q('#ttMasterTop')?.click();}}catch{}}
    if(new URLSearchParams(location.search).get('area')==='reports')await showArea('reports');
    if(new URLSearchParams(location.search).get('return')==='carry-forward'){try{const saved=JSON.parse(sessionStorage.getItem('tt_accounts_carry_return')||'null');sessionStorage.removeItem('tt_accounts_carry_return');if(saved?.company===entity()&&currentAreas().some(area=>area.key===saved.area)){await showArea(saved.area);window.scrollTo(0,Number(saved.scroll)||0);}}catch{}}
    installPreviousSearch();
    ensureLiveTreatments();
    document.addEventListener('click', event => { if (event.target.closest('.entityBtn')) setTimeout(refreshEntityLabels, 0); }, true);
    document.documentElement.classList.remove('tt-accounts-boot');
    q('#tt-accounts-boot-style')?.remove();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once:true}); else init();
  window.TT_ACCOUNTING_DESK = {installed:true,openSalesTaxSearch:openSalesTax,openHistoricalBagBill:async id=>{await launch({title:'Historical Bag Bill',native:'purchases',then:'[data-purchase="bags"]',bagSync:true});await window.TT_BAG_PURCHASES_UI.mount();window.TT_BAG_PURCHASES_UI.editHistorical(id);}, openSoda, openSearch, showArea, printVoucher, openSavedBill, refreshAttention:async()=>{await Promise.all([loadDashboardSummary(),loadRecentTransactions()]);}};
})();

