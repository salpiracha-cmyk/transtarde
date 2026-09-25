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

  const pakistanAreas = [
    {key:'exports', glyph:'⇄', title:'Export Receipts & Payments', note:'Every export receipt, document and shipment expense', actions:[
      {title:'Bank Receipt / Credit Advice', note:'One linked form for the advice, outstanding item, bank charges, WHT and Advance WHT; FI remains in Exports', special:'export-receipt'},
      {title:'Freight Forwarder / Shipping', note:'Shipment-linked freight invoice and accepted liability', special:'shipment-kind', shipmentKind:'freight'},
      {title:'Clearing Agent', note:'GD, job and shipment-linked clearing bill', special:'shipment-kind', shipmentKind:'clearing'},
      {title:'Bags Bill', note:'Export bag purchases, receipts, stock and sales-tax working', native:'purchases', then:'[data-purchase="bags"]', bagSync:true},
      {title:'Transport Bill', note:'Loading Programme and route-linked transport bill', special:'shipment-kind', shipmentKind:'transport'},
      {title:'Fumigation Bill', note:'Shipment-linked fumigation and treatment bill', special:'shipment-kind', shipmentKind:'fumigation'},
      {title:'Inspection Bill', note:'Shipment and certificate-linked inspection bill', special:'shipment-kind', shipmentKind:'inspection'},
      {title:'Other Export Expense', note:'Export supplies and costs not charged to one shipment', special:'other-export-expense'}
    ]},
    {key:'commodity', glyph:'▣', title:'Commodity Purchases & Local Sales', note:'Soda through final bill, payment, sale and receipt', actions:[
      {title:'Soda Centre', note:'Create, search, amend or delete an unlinked Soda', special:'soda'},
      {title:'Bill Posting', note:'Broker or Supplier → Soda → saved/printed Pohanch → final bill', special:'arrival-bills', native:'purchases', then:'[data-purchase="commodity"]'},
      {title:'Due Payment Working', note:'Amounts due by selected date, bank balances and bill allocation', special:'due-payments'},
      {title:'Local Sales & Receipts', note:'Mill sale approvals and linked receipts awaiting Accounts action', native:'receivables', find:'Local'}
    ]},
    {key:'routine', glyph:'◇', title:'Routine Expenses', note:'Simple forms for regular business spending', actions:[
      {title:'Credit Cards', note:'Statement payment with company and personal allocation', native:'expenses', then:'[data-expense="card"]'},
      {title:'Utilities', note:'Electricity, internet, telephone, gas and water', native:'expenses', then:'[data-expense="utility"]'},
      {title:'Repairs & Maintenance', note:'Office, vehicle, equipment and mill repairs', native:'expenses', then:'[data-expense="general"]'},
      {title:'Office / Mill Use', note:'Fuel, stationery, travel and small operating expense', native:'expenses', then:'[data-expense="general"]'},
      {title:'Rent & Recurring', note:'Saved recurring commitments and reminders', native:'expenses', then:'[data-expense="rent"]'},
      {title:'Salaries & Staff', note:'Prepare and post from Salary Master', native:'expenses', then:'[data-expense="salary"]'},
      {title:'Reimburse Someone', note:'Business cost paid personally and reimbursed once', native:'expenses', then:'[data-expense="reimburse"]'},
      {title:'Donations', note:'Zakat, Sadqa and Fi Sabilillah remain separate', native:'expenses', then:'[data-expense="donations"]'}
    ]},
    {key:'ledgers', glyph:'L', title:'Ledgers & Accounting', note:'Party, bank and general ledgers with controlled JV', actions:[
      {title:'All Ledgers', note:'Search any posting account; select a date range, print or export', special:'all-ledgers'},
      {title:'Post ID Register', note:'Every Post ID in date and number order with its debit and credit lines', special:'post-ledger'},
      {title:'Customer Ledgers', native:'receivables'}, {title:'Supplier / Broker Ledgers', native:'payables'},
      {title:'Bank / Cash Ledgers', special:'all-ledgers'}, {title:'General Ledger', native:'reports', find:'General Ledger'},
      {title:'Journal Voucher', note:'The only manual Debit / Credit entry screen', native:'jv'},
      {title:'Bank Reconciliation', native:'reconciliation'}, {title:'Search All Entries', special:'search'},
      {title:'Recent Audit Activity', special:'search'}
    ]},
    {key:'reports', glyph:'▤', title:'Reports', note:'Financial, tax, party, commodity and shipment reports', actions:[
      {title:'Sales Tax', note:'Search export documents and bank/tax advices by period and reference', special:'sales-tax'},
      {title:'Trial Balance', native:'reports', find:'Trial Balance'}, {title:'Profit & Loss', native:'reports', find:'Profit'},
      {title:'Balance Sheet', native:'reports', find:'Balance Sheet'}, {title:'Receivables / Payables', native:'reports', find:'Receivables'},
      {title:'Shipment Profitability', native:'reports', find:'Shipment'}, {title:'Bank & Cash Report', native:'bank'},
      {title:'Commodity & Local Sales', native:'reports', find:'Commodity'}
    ]}
  ];
  pakistanAreas.find(area=>area.key==='routine').actions.push({title:'Routine Expense Masters',note:'Save card, utility, rent and salary details in one place',special:'little-master'});
  pakistanAreas.find(area=>area.key==='routine').actions.push({title:'Other Purchases',note:'Assets and consumables outside commodity Sodas',native:'purchases',then:'[data-purchase="other"]'});
  const tgAreas = [
    {key:'tg-receipts', glyph:'↓', title:'Customer Receipts', note:'Receive money and allocate it to the correct TG customer', actions:[
      {title:'Customer Receipt', native:'bank', find:'Receive'}, {title:'Customer Receivables', native:'receivables'}
    ]},
    {key:'tg-payments', glyph:'↑', title:'Supplier Payments', note:'Supplier liabilities and payments only', actions:[
      {title:'Supplier Bills', native:'payables'}, {title:'Make Supplier Payment', native:'bank'}
    ]},
    {key:'tg-bank', glyph:'▦', title:'Bank & Local Expenses', note:'Bank activity and minor local operating expense', actions:[
      {title:'Bank Receipt / Payment', native:'bank'}, {title:'Local Expense', native:'expenses', then:'[data-expense="general"]'},
      {title:'Utilities', native:'expenses', then:'[data-expense="utility"]'}, {title:'Bank Reconciliation', native:'reconciliation'}
    ]},
    {key:'tg-ledgers', glyph:'L', title:'Ledgers & JV', note:'TG customer, supplier, bank and general ledgers', actions:[
      {title:'Post ID Register', special:'post-ledger'}, {title:'All Ledgers', special:'all-ledgers'}, {title:'Customer Ledger', native:'receivables'}, {title:'Supplier Ledger', native:'payables'}, {title:'Bank / Cash Ledger', special:'all-ledgers'},
      {title:'General Ledger', native:'reports', find:'General Ledger'}, {title:'Journal Voucher', native:'jv'}, {title:'Search All Entries', special:'search'}
    ]},
    {key:'tg-reports', glyph:'▤', title:'Reports', note:'TG balances and financial reports', actions:[
      {title:'Trial Balance', native:'reports', find:'Trial Balance'}, {title:'Profit & Loss', native:'reports', find:'Profit'},
      {title:'Balance Sheet', native:'reports', find:'Balance Sheet'}, {title:'Receivables / Payables', native:'reports', find:'Receivables'}
    ]}
  ];
  if(access.canInventoryReconciliation) pakistanAreas.find(area=>area.key==='reports').actions.push({title:'Ghati & Stock Reconciliation',note:'Accounts / Directors only; never adds stock or another purchase',special:'stock-reconciliation'});
  const currentAreas = () => entity() === 'TG' ? tgAreas : pakistanAreas;

  function installStyle() {
    if (q('#ttAccountingDeskStyle')) return;
    const style = document.createElement('style');
    style.id = 'ttAccountingDeskStyle';
    style.textContent = `
      body{background:#edf1f4!important;color:#172433}
      body.tt-arrival-opening .workspace.active{visibility:hidden!important}body.tt-arrival-opening:after{content:'Loading Bill Posting…';position:fixed;inset:62px 0 0;display:grid;place-items:center;background:#edf1f4;color:#173c63;font-weight:800;z-index:450}
      .topbar{height:62px!important;padding:0 22px!important}.brand{min-width:210px!important}.crumb{opacity:.72}
      #ttConsoleTop,#ttMasterTop,#ttChangeCompanyDesk{border:1px solid #ffffff32;background:#ffffff12;color:#fff;border-radius:9px;height:38px;padding:0 13px;font-weight:800;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center}
      #ttMasterTop{flex:0 0 38px;width:38px;min-width:38px;max-width:38px;height:38px;min-height:38px;max-height:38px;padding:0;display:inline-flex;align-items:center;justify-content:center;line-height:1;font-size:18px;font-weight:900;border-radius:9px;white-space:nowrap}
      #ttCompanyMenu{position:fixed;right:66px;top:57px;z-index:510;width:min(580px,calc(100vw - 24px));display:grid;grid-template-columns:repeat(3,1fr);gap:8px;padding:10px;background:#fff;border:1px solid #dbe2ea;border-radius:12px;box-shadow:0 18px 48px #0b203840}
      #ttCompanyMenu[hidden],.tt-layer [hidden]{display:none!important}.tt-company-choice{border:1px solid #dbe2ea;background:#fff;border-radius:10px;padding:11px;text-align:left;cursor:pointer}.tt-company-choice.active{border-color:#173c63;box-shadow:inset 3px 0 #173c63}.tt-company-choice b,.tt-company-choice small{display:block}.tt-company-choice small{margin-top:3px;color:#6b7887}
      .shell{max-width:1500px!important;padding:18px 22px!important}#entityHome>.entityHero,#entityHome>.notice,#entityHome>#homeGrid,#entityHome>.panel{display:none!important}
      #ttAccountingDesk{min-height:calc(100vh - 98px)}
      .tt-desk-main>section{background:#fff;border:1px solid #dfe5ea;border-radius:12px;box-shadow:0 3px 14px rgba(18,40,62,.05)}
      .tt-desk-main{min-width:0}.tt-desk-main>section{margin-bottom:14px}.tt-desk-heading{padding:18px 20px;display:flex;gap:14px;align-items:center}.tt-desk-heading>div{flex:1}.tt-desk-heading h1{font-size:22px;margin:0}.tt-desk-heading p{margin:4px 0 0;color:#697686;font-size:12px}.tt-search-main{width:min(360px,42vw);border:1px solid #cbd5df;border-radius:9px;padding:10px 12px;background:#f8fafb}
      .tt-position{display:grid;grid-template-columns:repeat(5,1fr);border-top:1px solid #e7ebef}.tt-summary{position:relative;border:0;border-right:1px solid #e7ebef;background:#fff;padding:14px 18px;text-align:left;cursor:pointer;min-width:0}.tt-summary:last-child{border-right:0}.tt-summary small,.tt-summary b,.tt-summary em{display:block}.tt-summary small{color:#75818e;font-size:9px;text-transform:uppercase;letter-spacing:.5px}.tt-summary b{font-size:15px;margin-top:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.tt-summary em{color:#87919b;font-size:10px;font-style:normal;margin-top:2px}.tt-summary-pop{display:none;position:absolute;z-index:25;top:calc(100% - 3px);left:10px;width:300px;max-height:260px;overflow:auto;background:#fff;border:1px solid #ccd7e0;border-radius:10px;padding:10px;box-shadow:0 16px 38px #14283e35;font-size:11px;white-space:normal}.tt-summary:hover .tt-summary-pop,.tt-summary:focus .tt-summary-pop{display:block}.tt-summary-pop div{padding:6px 3px;border-bottom:1px solid #edf0f2}.tt-summary-pop div:last-child{border:0}
      .tt-area-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;padding:18px}.tt-area-card{min-height:154px;border:1px solid #d9e2e9;border-radius:13px;background:#fff;padding:18px;text-align:left;cursor:pointer;box-shadow:0 5px 18px #11283d0a}.tt-area-card:hover{border-color:#749c89;background:#fbfefc;transform:translateY(-1px)}.tt-area-glyph{width:45px;height:45px;border-radius:12px;background:#e9f1ec;color:#28523d;display:grid;place-items:center;font-size:22px;font-weight:900;margin-bottom:16px}.tt-area-card b{display:block;font-size:17px}.tt-area-card small{display:block;color:#6f7c88;line-height:1.45;margin-top:6px}.tt-home-head{padding:17px 19px;border-bottom:1px solid #e6eaee}.tt-home-head h2{margin:0;font-size:17px}.tt-home-head p{margin:4px 0 0;color:#74808d;font-size:11px}.tt-back-areas{border:1px solid var(--tt-brand-main,#4f7650);background:var(--tt-brand-main,#4f7650);color:#fff;border-radius:8px;min-height:34px;padding:6px 12px;font-size:12px;line-height:1;font-weight:900;letter-spacing:.04em;cursor:pointer;margin-right:12px;white-space:nowrap}.tt-back-areas:hover,.tt-back-areas:focus-visible{background:var(--tt-brand-deep,#2e4527);border-color:var(--tt-brand-deep,#2e4527);color:#fff}
      .tt-work-head{display:flex;align-items:center;padding:15px 18px;border-bottom:1px solid #e6eaee}.tt-work-head h2{margin:0;font-size:16px}.tt-work-head p{margin:3px 0 0;color:#74808d;font-size:11px}.tt-work-head .tt-back-areas{margin-left:0;margin-right:14px}
      .tt-action-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));padding:8px}.tt-action{border:0;background:#fff;padding:13px;border-radius:8px;text-align:left;cursor:pointer;display:flex;gap:12px;align-items:flex-start}.tt-action:hover{background:#f2f6f8}.tt-action-mark{width:30px;height:30px;flex:0 0 30px;border-radius:7px;background:#e8eef3;display:grid;place-items:center;color:#173c63;font-weight:900}.tt-action b,.tt-action small{display:block}.tt-action b{font-size:13px}.tt-action small{color:#75818d;margin-top:3px;line-height:1.35}
      .tt-queue{padding:8px 17px 16px}.tt-queue-row{display:grid;grid-template-columns:110px 1fr auto;gap:14px;padding:10px 0;border-bottom:1px solid #edf0f2;align-items:center}.tt-queue-row:last-child{border:0}.tt-queue-row span{font-size:11px;color:#6e7b87}.tt-queue-row b{font-size:12px}.tt-queue-row button{border:0;background:#edf3f7;color:#173c63;border-radius:7px;padding:7px 10px;font-weight:750;cursor:pointer}
      .workspace.active.tt-clean-modal{top:3vh!important;max-height:94vh!important;border-radius:13px!important;background:#f5f7f9!important}.workspace.tt-clean-modal>.panelHead{border-radius:13px 13px 0 0!important}.workspace.tt-clean-modal .accountPreview{display:block!important;background:#eef5f8!important;border:1px solid #bfd0dc!important}.workspace.tt-clean-modal .infoCard{display:block!important}.workspace.tt-clean-modal .split{display:grid!important;grid-template-columns:minmax(0,1fr) 330px!important}.workspace.tt-clean-modal .formCard{background:#fff}
      .tt-prev-search{margin-left:auto!important;white-space:nowrap}.workspace.tt-clean-modal>.panelHead{align-items:center!important}.workspace.tt-clean-modal>.panelHead>div{flex:1}
      .tt-layer{position:fixed;inset:0;z-index:700;background:rgba(9,25,42,.54);display:grid;place-items:center;padding:18px}.tt-layer[hidden]{display:none}.tt-window{width:min(1080px,96vw);max-height:94vh;overflow:auto;background:#f5f7f9;border-radius:14px;box-shadow:0 28px 80px #0006}.tt-window-head{position:sticky;top:0;z-index:3;display:flex;align-items:center;gap:12px;padding:15px 18px;background:#fff;border-bottom:1px solid #dfe5ea}.tt-window-head h2{margin:0;font-size:18px}.tt-window-head span{flex:1}.tt-window-close{border:1px solid #dfc2c0;background:#fff;color:#8c2f2a;border-radius:8px;padding:8px 11px;font-weight:800;cursor:pointer}.tt-window-body{padding:16px}
      .tt-master-control{display:grid;grid-template-columns:minmax(0,1fr) 34px 34px;gap:6px;align-items:end}.tt-master-control>.btn{height:38px;padding:0;font-size:16px}.tt-master-control .tt-search-select{min-width:0}
      .tt-modebar{display:flex;gap:7px;margin-bottom:14px}.tt-modebar button{border:1px solid #ccd6df;background:#fff;border-radius:8px;padding:9px 13px;font-weight:800;cursor:pointer}.tt-modebar button.active{background:#102a46;color:#fff;border-color:#102a46}
      .tt-form{background:#fff;border:1px solid #dfe5ea;border-radius:11px;padding:15px}.tt-form-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.tt-form-grid .wide{grid-column:span 2}.tt-form label{font-size:10px}.tt-form-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:13px}.tt-treatment{margin-top:13px;border:1px solid #b9cedb;background:#eef6fa;border-radius:9px;padding:12px}.tt-treatment>strong{display:block;margin-bottom:7px}.tt-treatment-row{display:grid;grid-template-columns:50px 1fr auto;gap:8px;font-size:12px;padding:4px 0}.tt-treatment-total{border-top:1px solid #cbdbe4;margin-top:5px;padding-top:7px;font-weight:800}.tt-note{font-size:11px;color:#687686;margin-top:7px}
      .tt-records{margin-top:12px;background:#fff;border:1px solid #dfe5ea;border-radius:11px;overflow:hidden}.tt-records table{width:100%}.tt-records button{padding:6px 8px}.tt-searchbar{display:grid;grid-template-columns:1fr auto;gap:8px}.tt-searchbar input{margin:0;padding:11px 12px}.tt-searchbar button{border:0;border-radius:8px;background:#102a46;color:#fff;padding:0 16px;font-weight:800}.tt-record-card{background:#fff;border:1px solid #dfe5ea;border-radius:10px;padding:13px;margin-top:10px}.tt-record-card pre{white-space:pre-wrap;word-break:break-word;background:#f4f6f8;border-radius:8px;padding:10px;max-height:280px;overflow:auto;font-size:11px}.tt-record-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:9px}.tt-tax-filters{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.tt-tax-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:12px}.tt-tax-docs{display:flex;flex-wrap:wrap;gap:6px}.tt-tax-doc{display:inline-flex;align-items:center;gap:5px;border:1px solid #cdd8e1;border-radius:7px;padding:6px 8px;background:#fff;text-decoration:none;color:#173c63;font-size:11px;font-weight:700}.tt-tax-doc.missing{color:#7d8790;background:#f5f7f8}.tt-tax-row td{vertical-align:top}
      @media(max-width:900px){.tt-position{grid-template-columns:repeat(2,1fr)}.tt-area-grid{grid-template-columns:repeat(2,1fr)}.tt-action-list{grid-template-columns:1fr}.workspace.tt-clean-modal .split{grid-template-columns:1fr!important}.tt-form-grid,.tt-tax-filters{grid-template-columns:repeat(2,1fr)}#ttCompanyMenu{grid-template-columns:1fr;right:10px}}
      @media(max-width:560px){.tt-desk-heading{display:block}.tt-search-main{width:100%;margin-top:12px}.tt-position,.tt-form-grid,.tt-tax-filters,.tt-area-grid{grid-template-columns:1fr}.tt-form-grid .wide{grid-column:auto}.tt-queue-row{grid-template-columns:1fr auto}.tt-queue-row span{display:none}.topbar{padding:0 10px!important}.brand{min-width:0!important}.brand>div:last-child{display:none}#ttChangeCompanyDesk{max-width:130px;overflow:hidden;text-overflow:ellipsis}.tt-window-body{padding:10px}}
    `;
    style.textContent += `.tt-area-card,.tt-action{transition:transform .18s ease,box-shadow .18s ease,background .18s ease}.tt-area-card{border-color:var(--tile-border,#d9e2e9);background:linear-gradient(145deg,var(--tile-tint,#fff),#fff 76%)}.tt-area-card .tt-area-glyph{background:var(--tile-icon,#e9f1ec);color:var(--tile-color,#28523d)}.tt-area-card:nth-child(6n+1){--tile-tint:#fff5ef;--tile-icon:#ffe3d0;--tile-color:#ad5d37;--tile-border:#f1d9c8}.tt-area-card:nth-child(6n+2){--tile-tint:#f0f9f5;--tile-icon:#d9f1e3;--tile-color:#287455;--tile-border:#d3e8d9}.tt-area-card:nth-child(6n+3){--tile-tint:#f1f6ff;--tile-icon:#deeaff;--tile-color:#375fa6;--tile-border:#d6e1f4}.tt-area-card:nth-child(6n+4){--tile-tint:#fff9e9;--tile-icon:#ffedbc;--tile-color:#9b7126;--tile-border:#f0e4bf}.tt-area-card:nth-child(6n+5){--tile-tint:#f7f2ff;--tile-icon:#eadffc;--tile-color:#6a50a0;--tile-border:#e5daf3}.tt-area-card:nth-child(6n){--tile-tint:#ecfafb;--tile-icon:#d3f1f4;--tile-color:#237b8c;--tile-border:#cfe9ec}.tt-action-list[data-area-palette] .tt-action{margin:5px;border:1px solid #dfe8eb;background:linear-gradient(100deg,var(--action-tint,#f8fcfd),#fff 62%);border-radius:12px;min-height:88px;align-items:center}.tt-action-list[data-area-palette] .tt-action:hover{box-shadow:0 7px 20px #162f4317;transform:translateY(-1px)}.tt-action-list[data-area-palette] .tt-action-mark{width:44px;height:44px;flex-basis:44px;background:var(--action-icon,#dff2ed);color:var(--action-color,#286e5f);border-radius:12px}.tt-action:nth-child(5n+1){--action-tint:#f0f9f5;--action-icon:#d9f1e3;--action-color:#287455}.tt-action:nth-child(5n+2){--action-tint:#f1f6ff;--action-icon:#deeaff;--action-color:#375fa6}.tt-action:nth-child(5n+3){--action-tint:#fff9ed;--action-icon:#ffedc8;--action-color:#97702a}.tt-action:nth-child(5n+4){--action-tint:#f8f3ff;--action-icon:#eadffc;--action-color:#6a50a0}.tt-action:nth-child(5n){--action-tint:#eefafb;--action-icon:#d3f1f4;--action-color:#237b8c}.tt-action-mark svg{width:24px;height:24px}`;
    style.textContent+='@keyframes ttDuePulse{50%{box-shadow:0 0 0 3px #e5a63788}}.tt-due-alert{animation:ttDuePulse 1.8s ease-in-out infinite}.tt-summary[data-summary="due"] b{font-size:13px;line-height:1.4}';
    document.head.appendChild(style);
  }

  function nativeCard(key) {
    return q(`#ttNativeLaunchers .appCard[data-key="${key}"], #homeGrid .appCard[data-key="${key}"]`);
  }

  async function launch(action) {
    if(action.special==='all-ledgers')return window.TT_ALL_LEDGERS?.open?.();
    if(action.special==='post-ledger')return window.TT_ALL_LEDGERS?.open?.('POSTS');
    if(action.special==='due-payments')return openDuePayments();
    if(action.special==='little-master')return openLittleMaster();
    if(action.special==='other-export-expense')return openOtherExportExpense();
    if (action.special === 'stock-reconciliation' && access.canInventoryReconciliation) { location.href='/stock-reconciliation.php?origin=accounts&entity='+encodeURIComponent(entity()); return; }
    if (action.special === 'soda') return openSoda();
    if (action.special === 'search') return openSearch();
    if (action.special === 'sales-tax') return openSalesTax();
    if (action.special === 'export-receipt') return window.TT_EXPORT_RECEIPTS_UI?.openForm?.();
    if (action.special === 'shipment') return openShipmentChooser();
    if (action.special === 'shipment-kind') return openShipmentKind(action.shipmentKind);
    const arrivalOpening = action.special === 'arrival-bills';
    const purchaseEditor = q('#purchaseEditor');
    if (purchaseEditor && arrivalOpening) purchaseEditor.dataset.ttPurchaseMode = 'arrival';
    if (purchaseEditor && action.bagSync) purchaseEditor.dataset.ttPurchaseMode = 'bags';
    if (arrivalOpening) document.body.classList.add('tt-arrival-opening');
    const card = nativeCard(action.native);
    if (!card) { document.body.classList.remove('tt-arrival-opening'); return alert('This Accounts area is temporarily unavailable.'); }
    card.click();
    if (action.then) {
      let button = null;
      for (let i = 0; i < 40 && !button; i += 1) {
        button = q(action.then);
        if (!button) await new Promise(resolve => setTimeout(resolve, 40));
      }
      button?.click();
      if (action.bagSync) document.dispatchEvent(new CustomEvent('tt:bag-workspace-open'));
    }
    if (arrivalOpening) {
      try { await window.TT_SMART_COMMODITY_BILLS_V2?.mount?.(); }
      finally { document.body.classList.remove('tt-arrival-opening'); }
    }
    if (action.find) {
      await new Promise(resolve => setTimeout(resolve, 90));
      const root = q('.workspace.active') || document;
      qa('button', root).find(button => button.textContent.toLowerCase().includes(action.find.toLowerCase()))?.click();
    }
  }

  function buildTopbar() {
    const top = q('.topbar');
    if (!top || q('#ttChangeCompanyDesk')) return;
    const power = q('.power', top);
    const consoleLink = access.super ? document.createElement('a') : null;
    if (consoleLink) {
      consoleLink.id = 'ttConsoleTop'; consoleLink.href = '/index.php';
      consoleLink.title = 'Return to Control Centre'; consoleLink.textContent = 'Console';
    }
    const master = document.createElement('button');
    master.id = 'ttMasterTop'; master.type = 'button'; master.title = 'Master Records';
    master.setAttribute('aria-label', 'Open Master Records'); master.textContent = 'M';
    master.onclick = () => { window.location.href='/index.php?view=masters&from=accounts'; };
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
        <section><div class="tt-desk-heading"><div><h1>Accounts · <span data-tt-entity-name></span></h1><p>Choose the job you need. Every entry remains linked to its original operational record.</p></div><input class="tt-search-main" aria-label="Search previous records" placeholder="Search voucher, bill, Soda, truck or shipment"></div>
          <div class="tt-position" id="ttSummaryCards"><button class="tt-summary"><small>Bank Balance</small><b>Loading…</b><em>Hover for accounts</em></button><button class="tt-summary"><small>Commodity Bills Due</small><b>Loading…</b><em>Due-date detail</em></button><button class="tt-summary"><small>Local Receivables</small><b>Loading…</b><em>Customer detail</em></button><button class="tt-summary"><small>Export Receivables</small><b>Loading…</b><em>Currency detail</em></button><button class="tt-summary"><small>Next Payment Due</small><b>Loading…</b><em>Bill, amount and date</em></button></div>
        </section>
        <section id="ttDeskWork"></section>
        <section><div class="tt-work-head"><div><h2>Needs Attention</h2><p>Held and incomplete work stays visible but is never selected silently.</p></div></div><div class="tt-queue" id="ttAttentionQueue"><div class="tt-queue-row"><span>Status</span><b>Loading current work…</b></div></div></section>
      </div>
    </div>`;
  }

  function iconPicture(kind) {
    const paths={
      jv:'<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M8 7h8M8 11h8M8 15h3m4 0h1"/>',
      exports:'<path d="M3 19h18M5 19V9l5-4 5 4v10M15 10h6v9M9 12v3m9-1h1"/><path d="M14 3l3 2-3 2"/>',
      commodity:'<path d="M3 9l9-5 9 5v11H3zM3 9l9 5 9-5M12 14v6M6 12v5m12-5v5"/>',
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

  function showAreasHome() {
    const work = q('#ttDeskWork'); if (!work) return;
    const items = currentAreas();
    work.innerHTML = `<div class="tt-home-head"><h2>${entity()==='TG'?'Trans Grains Accounts':'What do you want to do?'}</h2><p>${entity()==='TG'?'Only customer receipts, supplier payments, bank/local expenses, ledgers and reports are shown.':'Choose a broad area, then choose the exact entry or report.'}</p></div><div class="tt-area-grid"><button type="button" class="tt-area-card" id="ttMainJV"><span class="tt-area-glyph">${iconPicture('jv')}</span><b>Journal Voucher</b><small>Prepare, approve and print a JV</small></button>${items.map(area=>`<button type="button" class="tt-area-card" data-tt-area="${area.key}"><span class="tt-area-glyph">${iconPicture(area.key.startsWith('tg-')?area.key.slice(3):area.key)}</span><b>${esc(area.title)}</b><small>${esc(area.note)}</small></button>`).join('')}</div>`;
    q('#ttMainJV',work).onclick=()=>launch({native:'jv'});
    qa('[data-tt-area]', work).forEach(button=>button.onclick=()=>showArea(button.dataset.ttArea));
    window.TT_ACCOUNT_BADGE_REFRESH?.();
  }

  function showArea(key) {
    const list=currentAreas();
    const area = list.find(item => item.key === key) || list[0];
    const work = q('#ttDeskWork');
    if (!work) return;
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
    work.innerHTML = `<div class="tt-work-head"><button class="tt-back-areas" type="button" aria-label="Back to main Accounts">BACK</button><div><h2>${esc(area.title)}</h2><p>${esc(area.note)}</p></div></div><div class="tt-action-list" data-area-palette="${esc(area.key)}">${area.actions.map((action, index) => `<button type="button" class="tt-action" data-tt-action="${index}"><span class="tt-action-mark">${actionGlyph(action)}</span><span><b>${esc(action.title)}</b><small>${esc(action.note || 'Open report')}</small></span></button>`).join('')}</div>`;
    q('.tt-back-areas',work).onclick=showAreasHome;
    qa('[data-tt-action]', work).forEach(button => button.onclick = () => launch(area.actions[Number(button.dataset.ttAction)]));
  }

  function openLittleMaster(){
    const host=layer('ttLittleMaster','Routine Expense Masters'),body=q('.tt-window-body',host);
    const masters=[{name:'Credit Cards',native:'expenses',then:'[data-expense="card"]'},{name:'Utility & Club Bills',native:'expenses',then:'[data-expense="utility"]'},{name:'Rent & Recurring',native:'expenses',then:'[data-expense="rent"]'},{name:'Salary & Staff',native:'expenses',then:'[data-expense="salary"]'}];
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
    q('.tt-search-main').addEventListener('keydown', event => { if (event.key === 'Enter') openSearch(event.currentTarget.value); });
    showAreasHome();
    refreshEntityLabels();
  }

  function refreshEntityLabels() {
    const name = q('.entityBtn.active strong')?.textContent || entity();
    qa('[data-tt-entity-name]').forEach(node => { node.textContent = name; });
    showAreasHome();
    loadDashboardSummary();
  }

  async function loadDashboardSummary() {
    const host=q('#ttSummaryCards'), queue=q('#ttAttentionQueue'); if(!host)return;
    try{
      const data=await json(`../api/accounts_dashboard.php?entity=${encodeURIComponent(entity())}`);
      const definitions=entity()==='TG'
        ? [{key:'bank',label:'Bank Balance',note:'Hover for accounts'},{key:'local',label:'Customer Receivables',note:'Customer detail'},{key:'commodity',label:'Supplier Bills Due',note:'Due-date detail'},{key:'due',label:'Next Payment Due',note:'Bill, amount and date'}]
        : [{key:'bank',label:'Bank Balance',note:'Hover for accounts'},{key:'commodity',label:'Commodity Bills Due',note:'Due-date detail'},{key:'local',label:'Local Receivables',note:'Customer detail'},{key:'export',label:'Export Receivables',note:'Customer / currency detail'},{key:'due',label:'Next Payment Due',note:'Bill, amount and date'}];
      host.style.gridTemplateColumns=`repeat(${definitions.length},1fr)`;
      host.innerHTML=definitions.map(def=>{
        const rows=data.summaries?.[def.key]||[], totals={};rows.forEach(row=>{const cur=row.currency||'PKR';totals[cur]=(totals[cur]||0)+Number(row.amount||0)});
        const first=rows[0];
        const headline=def.key==='due'?(first?`${esc(first.label)} · ${esc(first.currency||'PKR')} ${money(first.amount)} · ${esc(first.dateDisplay||first.date||'')}`:'No due payments'):Object.keys(totals).length?Object.entries(totals).map(([cur,value])=>`${esc(cur)} ${money(value)}`).join(' · '):'PKR 0.00';
        const detail=rows.length?rows.slice(0,20).map(row=>`<div><b>${esc(row.label||row.reference||'Account')}</b><br>${esc(row.reference||'')}${row.dateDisplay?' · '+esc(row.dateDisplay):''} · ${esc(row.currency||'PKR')} ${money(row.amount)}</div>`).join(''):'<div>No open balance.</div>';
        return `<button type="button" class="tt-summary ${def.key==='due'&&first&&first.date<=today()?'tt-due-alert':''}" data-summary="${def.key}"><small>${esc(def.label)}</small><b>${headline}</b><em>${esc(def.note)}</em><span class="tt-summary-pop">${detail}</span></button>`;
      }).join('');
       qa('[data-summary]',host).forEach(button=>button.onclick=()=>{const key=button.dataset.summary;if(key==='bank')launch({native:'bank'});else if(key==='due')showArea('routine');else if(key==='commodity')openDuePayments();else launch({native:'receivables'});});
      if(queue){const rows=data.attention||[];queue.innerHTML=rows.length?rows.slice(0,12).map(row=>`<div class="tt-queue-row"><span>${esc(row.type)}</span><b>${esc(row.message)}${row.reference?' · '+esc(row.reference):''}</b><button type="button" data-attention-search="${esc(row.reference||'')}">Review</button></div>`).join(''):'<div class="tt-queue-row"><span>Current</span><b>No held or incomplete entries need attention.</b></div>';qa('[data-attention-search]',queue).forEach(button=>button.onclick=()=>openSearch(button.dataset.attentionSearch));}
    }catch(error){qa('.tt-summary b',host).forEach(node=>node.textContent='Unavailable');if(queue)queue.innerHTML='<div class="tt-queue-row"><span>Status</span><b>Refresh to load current Accounts attention items.</b></div>';console.warn('Accounts dashboard summary',error);}
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
    const credit = String(record?.paymentTermType || (Number(record?.creditDays || 0) ? 'CREDIT' : 'CASH')).toUpperCase();
    const products = sodaData.purchaseProducts || [];
    const inferredProduct = products.find(item => item.id === record?.purchaseProductId) || products.find(item => String(item.commodity) === String(record?.commodity || '') && String(item.baseVariety).toLowerCase() === String(record?.baseVariety || record?.variety || '').toLowerCase() && String(item.riceType || '').toLowerCase() === String(record?.riceType || '').toLowerCase() && String(item.brokenGrade || '').toLowerCase() === String(record?.brokenGrade || '').toLowerCase() && String(item.productStage) === String(record?.productStage || ''));
    const productId = inferredProduct?.id || (!record ? sodaData.defaults?.rawPurchaseProductId : '');
    const stage = inferredProduct?.productStage || String(record?.productStage || 'RAW').toUpperCase();
    const route = stage === 'READY' ? String(record?.readyRoute || '') : 'DELIVER_TO_STOCK';
    const locations = sodaData.locations || [];
    const legacyLocation = locations.find(item => String(item.name).toLowerCase() === String(record?.locationName || record?.location || '').toLowerCase());
    const locationId = record?.locationId || legacyLocation?.id || (!record && stage !== 'READY' ? sodaData.defaults?.rawLocationId : '');
    const option = (item, selected, label) => `<option value="${esc(item.id)}"${item.id===selected?' selected':''}>${esc(label)}</option>`;
    const productOptions = products.map(item => option(item, productId, item.displayName)).join('');
    const brokerOptions = (sodaData.brokers || []).map(item => `<option value="${esc(item.name)}"${String(item.name).toLowerCase()===String(record?.broker||'').toLowerCase()?' selected':''}>${esc(item.name)}</option>`).join('');
    const supplierOptions = (sodaData.suppliers || []).map(item => option(item, record?.supplierId || '', item.name)).join('');
    const receiving = locations.filter(item => ['Own Mill','Reprocessing Mill','Warehouse','Stock Location'].includes(item.type));
    const external = locations.filter(item => item.type === 'External Mill');
    return `<form class="tt-form" id="ttSodaForm">
      <div class="tt-form-grid">
        <label>Soda No.<input value="${value('sodaNo', sodaData.nextSodaNo || 'Generated automatically')}" readonly tabindex="-1"></label>
        <label>Soda Date<input id="ttSdDate" type="date" value="${value('sodaDate', today())}" required></label>
        <label class="wide">Purchase Product<span class="tt-master-control"><select id="ttSdProduct" required><option value="">Type to search an approved product</option>${productOptions}</select><button type="button" class="btn" id="ttSdAddProduct" title="Add Purchase Product"${sodaData.permissions?.canAddProduct?'':' hidden'}>+</button><button type="button" class="btn" id="ttSdRemoveProduct" title="Remove selected Purchase Product"${sodaData.permissions?.canRemoveProduct?'':' hidden'}>−</button></span></label>
        <label>Broker (optional)<span class="tt-master-control"><select id="ttSdBroker"><option value="">No broker — direct purchase</option>${brokerOptions}</select><button type="button" class="btn" id="ttSdAddBroker" title="Add Broker"${sodaData.permissions?.canAddParty?'':' hidden'}>+</button><button type="button" class="btn" id="ttSdRemoveBroker" title="Remove selected Broker"${sodaData.permissions?.canRemoveParty?'':' hidden'}>−</button></span></label>
        <label>Supplier<span class="tt-master-control"><select id="ttSdSupplier"><option value=""${record?.party&&!record?.supplierId?' selected':''}>${record?.party?esc(record.party):'Select supplier if applicable'}</option>${supplierOptions}</select><button type="button" class="btn" id="ttSdAddSupplier" title="Add Supplier"${sodaData.permissions?.canAddParty?'':' hidden'}>+</button><button type="button" class="btn" id="ttSdRemoveSupplier" title="Remove selected Supplier"${sodaData.permissions?.canRemoveParty?'':' hidden'}>−</button></span></label>
        <label id="ttSdRouteWrap" class="wide"${stage==='READY'?'':' hidden'}>Ready Rice Route<select id="ttSdRoute"><option value="">Choose route — no default</option><option value="EX_MILL"${route==='EX_MILL'?' selected':''}>EX-MILL — load containers at outside mill</option><option value="DELIVER_TO_STOCK"${route==='DELIVER_TO_STOCK'?' selected':''}>DELIVER TO OUR MILL / STOCK LOCATION</option></select><small id="ttSdRouteHelp">${route==='EX_MILL'?'No Pohanch or KAT. Each saved container weighbridge weight posts the purchase liability.':'Delivery to our location uses arrival Pohanch and the approved KAT profile.'}</small></label>
        <label id="ttSdStockWrap" class="wide"${route==='EX_MILL'?' hidden':''}>Mill / Stock Location<span class="tt-master-control"><select id="ttSdStock"><option value="">Select receiving stock location</option>${receiving.map(item=>option(item,locationId,`${item.name} · ${item.type}`)).join('')}</select><button type="button" class="btn" id="ttSdAddStock" title="Add Mill / Stock Location"${sodaData.permissions?.canAddLocation?'':' hidden'}>+</button><button type="button" class="btn" id="ttSdRemoveStock" title="Remove selected Mill / Stock Location"${sodaData.permissions?.canRemoveLocation?'':' hidden'}>−</button></span></label>
        <div id="ttSdExMillWrap" class="wide"${route==='EX_MILL'?'':' hidden'}><label>Ex-Mill Name<span class="tt-master-control"><select id="ttSdExMill"><option value="">Type to search External Mills</option>${external.map(item=>option(item,locationId,item.name)).join('')}</select><button type="button" class="btn" id="ttSdAddMill" title="Add External Mill"${sodaData.permissions?.canAddLocation?'':' hidden'}>+</button><button type="button" class="btn" id="ttSdRemoveMill" title="Remove selected External Mill"${sodaData.permissions?.canRemoveLocation?'':' hidden'}>−</button></span></label><div class="tt-form-actions" style="justify-content:flex-start"><button type="button" class="btn" id="ttSdLinkMill"${sodaData.permissions?.canLinkSupplierMill?'':' hidden'}>Link supplier to selected Ex-Mill</button></div></div>
        <label><span id="ttSdTrucksLabel">${route==='EX_MILL'?'Truck / Containers':'Expected Trucks'}</span><input id="ttSdTrucks" inputmode="numeric" value="${value('expectedTrucks')}"></label>
        <label id="ttSdTargetWrap"${route==='EX_MILL'?'':' hidden'}>Contracted Quantity (MT)<input id="ttSdTarget" inputmode="decimal" value="${record ? value('qtyToMT', Number(record.qtyToKg || record.qtyFromKg || 0) / 1000 || '') : ''}"><small>Actual loaded may vary by up to 5%.</small></label>
        <label id="ttSdMinWrap"${route==='EX_MILL'?' hidden':''}>Minimum Quantity (MT)<input id="ttSdMin" inputmode="decimal" value="${record ? value('qtyFromMT', Number(record.qtyFromKg || 0) / 1000 || '') : ''}"></label>
        <label id="ttSdMaxWrap"${route==='EX_MILL'?' hidden':''}>Maximum Quantity (MT)<input id="ttSdMax" inputmode="decimal" value="${record ? value('qtyToMT', Number(record.qtyToKg || 0) / 1000 || '') : ''}"></label>
        <label>Rate<input id="ttSdRate" inputmode="decimal" value="${value('rate', record?.ratePerKg || '')}" required></label>
        <label>Rate Unit<select id="ttSdUnit"><option value="KG"${(record?.rateUnit||'KG')==='KG'?' selected':''}>Per kg</option><option value="MAUND"${record?.rateUnit==='MAUND'?' selected':''}>Per maund (40 kg)</option></select></label>
        <label>PAYMENT TERM<select id="ttSdTerms"><option value="CASH"${credit==='CASH'?' selected':''}>Cash</option><option value="CREDIT"${credit==='CREDIT'?' selected':''}>Credit</option></select></label>
        <label id="ttSdCreditWrap"${credit==='CASH'?' hidden':''}>Credit Days<input id="ttSdCredit" inputmode="numeric" min="1" max="365" value="${value('creditDays', credit==='CREDIT'?'':'')}" ${credit==='CASH'?'disabled':''}></label>
        <label>Expected Arrival / Delivery Date<input id="ttSdDue" type="date" value="${value('arrivalDueDate', record?.deliveryDeadline || '')}" required></label>
        <label class="wide">Terms / Conditions<input id="ttSdConditions" value="${value('terms')}"></label>
        <label class="wide">Remarks<textarea id="ttSdRemarks">${value('remarks')}</textarea></label>
        ${record ? '<label class="wide">Reason for Amendment<textarea id="ttSdReason" placeholder="Required. This is stored with the old and new values in the audit log." required></textarea></label>' : ''}
      </div>
      <div class="tt-treatment"><strong>Accounting treatment before posting</strong><div class="tt-note">Saving a Soda creates an open purchase commitment only. It does not create a General Ledger entry.</div><div class="tt-note" id="ttSdPaymentHelp"></div><div class="tt-treatment-row"><span>Later</span><span>Approved arrival bill: Inventory / Purchase</span><b>Debit</b></div><div class="tt-treatment-row"><span>Later</span><span>Supplier Payable</span><b>Credit</b></div></div>
      <div class="tt-form-actions"><button type="button" class="btn" id="ttSdReset">Cancel</button><button type="submit" class="btn green">${record ? 'Save Amendment' : 'Save Soda'}</button></div>
    </form>`;
  }

  function bindSodaForm(host, record = null) {
    const form = q('#ttSodaForm', host);
    const createRequestKey = record ? '' : (crypto.randomUUID?.() || `soda-${Date.now()}-${Math.random().toString(16).slice(2)}`);
    const terms=q('#ttSdTerms',form),days=q('#ttSdCredit',form),daysWrap=q('#ttSdCreditWrap',form),productSelect=q('#ttSdProduct',form),route=q('#ttSdRoute',form),routeWrap=q('#ttSdRouteWrap',form),stock=q('#ttSdStock',form),stockWrap=q('#ttSdStockWrap',form),exMill=q('#ttSdExMill',form),exMillWrap=q('#ttSdExMillWrap',form),supplier=q('#ttSdSupplier',form);
    let locationTouched=!!record?.locationId;
    const setSelect=(control,value)=>{control.value=value;const input=control.closest('.tt-search-select')?.querySelector(':scope>input');if(input)input.value=control.selectedOptions[0]?.textContent.trim()||'';};
    const selectedProduct=()=> (sodaData.purchaseProducts||[]).find(item=>item.id===productSelect.value)||null;
    const paymentState=()=>{const isCredit=terms.value==='CREDIT';daysWrap.hidden=!isCredit;days.disabled=!isCredit;days.required=isCredit;if(!isCredit)days.value='';q('#ttSdPaymentHelp',form).textContent=isCredit?`Payment becomes due on Arrival / Pohanch date + ${days.value||'agreed'} day(s).`:'Cash payment becomes due on Arrival / Pohanch date + 2 days.';};
    terms.onchange=paymentState;days.oninput=paymentState;paymentState();
    const syncRoute=(initial=false)=>{const ready=selectedProduct()?.productStage==='READY';routeWrap.hidden=!ready;if(!ready){setSelect(route,'DELIVER_TO_STOCK');setSelect(exMill,'');stockWrap.hidden=false;exMillWrap.hidden=true;if(!record&&!stock.value)setSelect(stock,sodaData.defaults?.rawLocationId||'');}else{if(!initial){setSelect(route,'');setSelect(stock,'');setSelect(exMill,'');locationTouched=false;}stockWrap.hidden=route.value!=='DELIVER_TO_STOCK';exMillWrap.hidden=route.value!=='EX_MILL';}const ex=ready&&route.value==='EX_MILL',target=q('#ttSdTarget',form),min=q('#ttSdMin',form),max=q('#ttSdMax',form);q('#ttSdTargetWrap',form).hidden=!ex;q('#ttSdMinWrap',form).hidden=ex;q('#ttSdMaxWrap',form).hidden=ex;q('#ttSdTrucksLabel',form).textContent=ex?'Truck / Containers':'Expected Trucks';q('#ttSdRouteHelp',form).textContent=ex?'No Pohanch or KAT. Each saved container weighbridge weight posts the purchase liability.':'Delivery to our location uses arrival Pohanch and the approved KAT profile.';if(ex&&!target.value)target.value=max.value||min.value;if(!ex&&target.value&&!min.value){min.value=target.value;max.value=target.value;}supplier.required=ex;};
    productSelect.onchange=()=>syncRoute(false);
    route.onchange=()=>{setSelect(stock,'');setSelect(exMill,'');locationTouched=false;syncRoute(true);if(route.value==='EX_MILL')suggestSupplierMill();};
    stock.onchange=()=>{locationTouched=true;};exMill.onchange=()=>{locationTouched=true;};
    const suggestSupplierMill=()=>{if(route.value!=='EX_MILL'||locationTouched)return;const row=(sodaData.suppliers||[]).find(item=>item.id===supplier.value);if(row?.linkedExternalMillId&&[...exMill.options].some(option=>option.value===row.linkedExternalMillId)){setSelect(exMill,row.linkedExternalMillId);locationTouched=false;}};
    supplier.onchange=suggestSupplierMill;
    const masterPost=body=>json('../api/masters.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:access.csrf,...body})});
    const addParty=async(category,control)=>{const name=prompt(`New ${category} name`,'');if(!name?.trim())return;try{const result=await json(api,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'add_party_category',csrf:access.csrf,category,name:name.trim()})});Object.assign(sodaData,result);const rows=category==='Broker'?sodaData.brokers:sodaData.suppliers,row=(rows||[]).find(item=>item.id===result.partyId);if(row){control.add(new Option(row.name,category==='Broker'?row.name:row.id));setSelect(control,category==='Broker'?row.name:row.id);control.dispatchEvent(new Event('change',{bubbles:true}));}}catch(error){alert(error.message);}};
    const removeParty=async(category,control)=>{const selected=category==='Broker'?(sodaData.brokers||[]).find(item=>item.name===control.value):(sodaData.suppliers||[]).find(item=>item.id===control.value);if(!selected)return alert(`Select the ${category} to remove from future Sodas.`);if(!confirm(`Remove ${selected.name} from the ${category} dropdown? Historical Sodas remain unchanged.`))return;try{const result=await json(api,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'remove_party_category',csrf:access.csrf,category,id:selected.id})});Object.assign(sodaData,result);control.selectedOptions[0]?.remove();setSelect(control,'');}catch(error){alert(error.message);}};
    q('#ttSdAddBroker',form)?.addEventListener('click',()=>addParty('Broker',q('#ttSdBroker',form)));
    q('#ttSdRemoveBroker',form)?.addEventListener('click',()=>removeParty('Broker',q('#ttSdBroker',form)));
    q('#ttSdAddSupplier',form)?.addEventListener('click',()=>addParty('Supplier',supplier));
    q('#ttSdRemoveSupplier',form)?.addEventListener('click',()=>removeParty('Supplier',supplier));
    q('#ttSdAddProduct',form)?.addEventListener('click',async()=>{const commodity=String(prompt('Commodity: RICE, CORN or SESAME','RICE')||'').trim().toUpperCase();if(!['RICE','CORN','SESAME'].includes(commodity))return alert('Commodity must be RICE, CORN or SESAME.');const base=prompt(commodity==='RICE'?'Base variety, for example PK-386':'Product name','');if(!base?.trim())return;const riceType=commodity==='RICE'?(prompt('Rice type, for example White, Parboiled / Sella or Steam','White')||'').trim():'';if(commodity==='RICE'&&!riceType)return;const grade=commodity==='RICE'?(prompt('Broken grade, for example 100% Broken (leave blank if not applicable)','')||'').trim():'';const stage=String(prompt('Purchase classification: RAW or READY','READY')||'').trim().toUpperCase();if(!['RAW','READY'].includes(stage))return alert('Purchase classification must be RAW or READY.');const unit=commodity==='RICE'?'KG':'MAUND';try{const saved=await masterPost({action:'create',type:'purchase_products',values:[commodity,base.trim(),riceType,stage,unit,'','','','Active','Added from Accounts Soda Centre.',grade]});await loadSodas();const row=(sodaData.purchaseProducts||[]).find(item=>item.id===saved.id);if(row){productSelect.add(new Option(row.displayName,row.id));setSelect(productSelect,row.id);productSelect.dispatchEvent(new Event('change',{bubbles:true}));}else alert('Product was saved, but did not appear in the active Soda list. Check its stage and status in Purchase Commodities & KAT.');}catch(error){alert(error.message);}});
    q('#ttSdRemoveProduct',form)?.addEventListener('click',async()=>{if(!productSelect.value)return alert('Select the Purchase Product to remove.');const label=productSelect.selectedOptions[0]?.textContent||'this product';if(!confirm(`Remove ${label} from future Sodas? Historical Sodas remain unchanged.`))return;try{await masterPost({action:'delete',type:'purchase_products',id:productSelect.value});productSelect.selectedOptions[0]?.remove();setSelect(productSelect,'');syncRoute(false);}catch(error){alert(error.message);}});
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
    body.innerHTML = `<div class="tt-modebar"><button data-soda-mode="new">New Soda</button><button class="active" data-soda-mode="search">Search / Amend Soda</button></div><div class="tt-searchbar"><input id="ttSodaSearch" value="${esc(term)}" placeholder="Soda no, broker, supplier, commodity, variety, mill or date"><button type="button">Search</button></div><div class="tt-records"><div class="tableWrap"><table><thead><tr><th>Soda</th><th>Date</th><th>Commodity</th><th>Broker / Supplier</th><th>Quantity</th><th>Rate</th><th>Status</th><th></th></tr></thead><tbody>${rows.length ? rows.map(row => `<tr><td><b>${esc(row.sodaNo)}</b></td><td>${esc(row.sodaDate)}</td><td>${esc(row.commodity)}<br><small>${esc(row.variety)}</small></td><td>${esc(row.broker)}<br><small>${esc(row.party || '')}</small></td><td>${Number(row.qtyFromKg||0)?money(Number(row.qtyFromKg)/1000)+'–'+money(Number(row.qtyToKg||row.qtyFromKg)/1000)+' MT':''}${row.expectedTrucks?'<br>'+esc(row.expectedTrucks)+' trucks':''}</td><td>${money(row.rate ?? row.ratePerKg)} / ${esc(row.rateUnit || 'KG')}</td><td>${esc(row.calculatedStatus || row.status)}</td><td><button type="button" class="btn" data-soda-edit="${esc(row.id)}">View / Amend</button>${access.super?` <button type="button" class="btn danger" data-soda-delete="${esc(row.id)}">Delete</button>`:''}</td></tr>`).join('') : '<tr><td colspan="8">No matching Soda.</td></tr>'}</tbody></table></div></div>`;
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
    qa('[data-soda-delete]', body).forEach(button => button.onclick = async () => {
      const record=(sodaData.sodas||[]).find(row=>row.id===button.dataset.sodaDelete);if(!record)return;
      if(!confirm(`Permanently delete Soda ${record.sodaNo}? This is allowed only before any Pohanch, bill or settlement is linked.`))return;
      const reason=prompt('Reason for permanent deletion:','Entered in error');if(!reason?.trim())return;
      try{sodaData=await json(api,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete',csrf:access.csrf,entity:entity(),id:record.id,reason:reason.trim()})});renderSodaSearch(host,term)}catch(error){alert(error.message)}
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
    const outgoing=/payment|expense|remittance|supplier settlement|reimbursement/i.test(String(record.type||data.sourceType||''));
    const amount=data.amount||data.paidAmount||data.total||data.totalDebit||record.amount||'';
    const paymentAccount=data.bankName||data.bankAccountTitle||data.paymentAccountName||data.cashAccount||'';
    const reference=data.billNo||data.invoiceNo||data.reference||data.sodaNo||'';
    const cheque=data.chequeNo||data.bankReference||data.transactionReference||'';
    const lines=Array.isArray(data.lines)&&data.lines.length?data.lines.map(line=>`<tr><td>${esc(line.account||'')} · ${esc(line.accountName||'')}</td><td>${esc(line.subledger||line.party||'')}</td><td class="amount">${esc(line.debit||'')}</td><td class="amount">${esc(line.credit||'')}</td></tr>`).join(''):`<tr><td>${esc(record.party||data.party||data.broker||'')}</td><td>${esc(reference)}</td><td class="amount">${esc(amount)}</td><td class="amount"></td></tr>`;
    const w = window.open('', '_blank');
    if (!w) return alert('Allow popups to print the voucher.');
    w.document.write(`<!doctype html><meta charset="utf-8"><title>${esc(number)}</title><style>body{font:12px Arial;max-width:940px;margin:22px auto;padding:18px;color:#1a2836}header{text-align:center;border-bottom:3px solid #1d5748;padding-bottom:12px}h1{margin:0;font-size:20px}h2{margin:5px 0;font-size:16px;text-transform:uppercase;color:#1d5748}.meta{display:grid;grid-template-columns:1fr 1fr;gap:9px;margin:18px 0}.meta div{padding:10px;border:1px solid #d4dde3;border-radius:5px}.meta b{display:block;color:#586979;font-size:10px;text-transform:uppercase;margin-bottom:4px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #c7d4db;padding:8px;text-align:left}th{background:#eaf2ef}.amount{text-align:right}.narration{border:1px solid #c7d4db;margin-top:15px;padding:12px}.sign{display:grid;grid-template-columns:repeat(4,1fr);gap:24px;margin-top:64px}.sign div{border-top:1px solid #333;text-align:center;padding-top:7px}.ack{margin-top:24px;padding:14px;border:1px solid #859e91}.ack p{margin:8px 0 25px}@media print{button{display:none}body{margin:0}}</style><button onclick="print()">Print Voucher</button><header><h1>${esc(q('.entityBtn.active strong')?.textContent||entity())}</h1><h2>${outgoing?'Payment Voucher':record.type||'Accounting Voucher'}</h2></header><div class="meta"><div><b>Posting / Voucher Number</b>${esc(number)}</div><div><b>Date</b>${esc(record.date||data.date||'')}</div><div><b>Paid to / Account</b>${esc(record.party||data.payee||data.vendor||data.party||'—')}</div><div><b>Related invoice / bill</b>${esc(reference||'—')}</div><div><b>Paid from</b>${esc(paymentAccount||'—')}</div><div><b>Cheque / Bank reference</b>${esc(cheque||'—')}</div></div><table><thead><tr><th>Account</th><th>Party / Detail</th><th>Debit</th><th>Credit</th></tr></thead><tbody>${lines}</tbody></table><div class="narration"><b>Amount: ${esc(data.currency||'PKR')} ${esc(amount)}</b><p>${esc(data.narration||data.remarks||record.title)}</p></div>${outgoing?'<div class="ack"><b>Recipient acknowledgement</b><p>Received the stated payment against the invoice / bill referenced above.</p>Name: _________________________ &nbsp; ID / Stamp: _________________________ &nbsp; Date: ________________</div>':''}<div class="sign"><div>Prepared By</div><div>Checked By</div><div>Approved By</div><div>${outgoing?'Received By':'Posted By'}</div></div>`);
    w.document.close();
  }

  function printSalesTaxRows(rows) {
    const w=window.open('','_blank','noopener,noreferrer');if(!w)return alert('Allow popups to print the Sales Tax document index.');
    w.document.write(`<!doctype html><title>Sales Tax Documents</title><style>body{font:12px Arial;color:#172433;padding:28px}h1{text-align:center}table{width:100%;border-collapse:collapse}th,td{border:1px solid #9ca9b4;padding:7px;vertical-align:top}small{color:#5e6b76}@media print{button{display:none}}</style><h1>Sales Tax — ${esc(q('.entityBtn.active strong')?.textContent||entity())}</h1><table><thead><tr><th>Date</th><th>Customer / Contract</th><th>Invoice / GD / B/L</th><th>Documents and Bank Advices</th></tr></thead><tbody>${rows.map(row=>`<tr><td>${esc(row.dateDisplay||row.date||'')}</td><td><b>${esc(row.customer)}</b><br>${esc(row.contractRef)} · ${esc(row.lotRef)}</td><td>${esc(row.commercialInvoice||row.customsInvoice||'')}<br>GD ${esc((row.gdRefs||[]).join(', ')||'—')}<br>B/L ${esc(row.blNo||'—')}</td><td>${(row.documents||[]).map(doc=>esc(doc.name)).join('<br>')||'No uploaded export file'}${(row.advices||[]).map(a=>`<br><b>${esc(a.receiptNo)}</b> · ${esc(a.bankAdviceRef)} · ${esc(a.currency)} ${money(a.foreignAmount)}${a.paperRef?' · '+esc(a.paperRef):''}${a.downloadUrl?' · Credit advice uploaded':''}`).join('')}</td></tr>`).join('')}</tbody></table><button onclick="print()">Print</button>`);w.document.close();
  }

  async function openSalesTax() {
    if(entity()==='TG')return alert('Pakistan Sales Tax documents belong to TTI or BRM, not TG books.');
    const host=layer('ttSalesTaxLayer','Sales Tax');const body=q('.tt-window-body',host);let rows=[];
    body.innerHTML=`<div class="tt-form"><div class="tt-note" style="margin:0 0 12px">Search the documents already stored in Exports and their linked Accounts bank / tax advices. Nothing is copied into another store.</div><div class="tt-tax-filters"><label>From Date<input id="ttTaxFrom" type="date"></label><label>To Date<input id="ttTaxTo" type="date"></label><label class="wide">Customer, contract, invoice, GD, B/L or shipment<input id="ttTaxQuery" placeholder="Type any known reference"></label></div><div class="tt-tax-actions"><button class="btn" type="button" id="ttTaxPrint" disabled>Print selected / results</button><button class="btn" type="button" id="ttTaxDownload" disabled>Download selected files</button><button class="btn primary" type="button" id="ttTaxSearch">Search</button></div></div><div id="ttTaxResults"><div class="tt-record-card">Choose a date range or reference, then search.</div></div>`;
    const results=q('#ttTaxResults',body),print=q('#ttTaxPrint',body),download=q('#ttTaxDownload',body);
    const selectedRows=()=>{const chosen=new Set(qa('[data-tax-row]:checked',results).map(x=>Number(x.dataset.taxRow)));return chosen.size?rows.filter((_,i)=>chosen.has(i)):rows;};
    const run=async()=>{results.innerHTML='<div class="tt-record-card">Searching export and Accounts records…</div>';try{const params=new URLSearchParams({entity:entity(),from:q('#ttTaxFrom',body).value,to:q('#ttTaxTo',body).value,q:q('#ttTaxQuery',body).value.trim()});const data=await json('../api/accounts_sales_tax.php?'+params);rows=data.rows||[];rows.forEach(row=>{row.dateDisplay=/^(\d{4})-(\d{2})-(\d{2})$/.test(row.date||'')?row.date.replace(/^(\d{4})-(\d{2})-(\d{2})$/,'$3-$2-$1'):row.date||'';});print.disabled=!rows.length;download.disabled=!rows.some(row=>(row.documents||[]).length||(row.advices||[]).some(a=>a.downloadUrl));results.innerHTML=rows.length?`<div class="tt-records"><div class="tableWrap"><table><thead><tr><th>Use</th><th>Date</th><th>Customer / Contract</th><th>Invoice / GD / B/L</th><th>Available documents and advices</th></tr></thead><tbody>${rows.map((row,i)=>`<tr class="tt-tax-row"><td><input type="checkbox" data-tax-row="${i}" checked aria-label="Use ${esc(row.commercialInvoice||row.contractRef)}"></td><td>${esc(row.dateDisplay)}</td><td><b>${esc(row.customer||'—')}</b><br><small>${esc(row.contractRef)} · ${esc(row.lotRef)}</small></td><td><b>${esc(row.commercialInvoice||row.customsInvoice||'—')}</b><br><small>GD ${esc((row.gdRefs||[]).join(', ')||'—')} · B/L ${esc(row.blNo||'—')}</small></td><td><div class="tt-tax-docs">${(row.documents||[]).map(doc=>`<a class="tt-tax-doc" href="${esc(doc.downloadUrl)}" target="_blank" rel="noopener" download>${esc(doc.name)}</a>`).join('')}${!(row.documents||[]).length?'<span class="tt-tax-doc missing">No uploaded export file</span>':''}${(row.advices||[]).map(a=>a.downloadUrl?`<a class="tt-tax-doc" href="${esc(a.downloadUrl)}" target="_blank" rel="noopener" download>Credit Advice ${esc(a.bankAdviceRef||a.receiptNo)}</a>`:`<span class="tt-tax-doc">Advice ${esc(a.bankAdviceRef||a.receiptNo)} · ${esc(a.currency)} ${money(a.foreignAmount)}${a.paperRef?' · '+esc(a.paperRef):''}</span>`).join('')}</div></td></tr>`).join('')}</tbody></table></div></div>`:'<div class="tt-record-card">No matching export shipment or invoice.</div>';}catch(error){rows=[];print.disabled=true;download.disabled=true;results.innerHTML=`<div class="tt-record-card">${esc(error.message)}</div>`;}};
    q('#ttTaxSearch',body).onclick=run;q('#ttTaxQuery',body).onkeydown=event=>{if(event.key==='Enter')run();};print.onclick=()=>printSalesTaxRows(selectedRows());download.onclick=()=>{const docs=selectedRows().flatMap(row=>[...(row.documents||[]),...(row.advices||[]).filter(a=>a.downloadUrl)]);if(!docs.length)return alert('The selected rows do not have uploaded files.');docs.forEach((doc,i)=>setTimeout(()=>window.open(doc.downloadUrl,'_blank','noopener,noreferrer'),i*180));};
  }

  async function openOtherExportExpense() {
    const host=layer('ttOtherExportExpense','Other Export Expense'),body=q('.tt-window-body',host);
    body.innerHTML='<div class="tt-form">Loading company payment accounts…</div>';
    try {
      const data=await json('../api/expenses_v1.php?entity='+encodeURIComponent(entity()));
      const accounts=data.paymentAccounts||[];
      body.innerHTML=`<form class="tt-form" id="ttExportExpenseForm"><p>Enter export supplies or shared export costs such as craft paper, silica gel or seals. Shipment-specific supplier bills belong under their shipment icons.</p><div class="tt-form-grid"><label>Date<input type="date" name="paymentDate" value="${esc(today())}" required></label><label>Paid from bank or cash<select name="paymentAccountId" required><option value="">Choose company account</option>${accounts.map(account=>`<option value="${esc(account.id)}">${esc(account.label)} · ${esc(account.currency)}</option>`).join('')}</select></label><label>Payee<input name="payee" required></label><label>Amount (PKR)<input type="number" min="0.01" step="0.01" name="amount" required></label><label>Cheque / payment reference<input name="reference" placeholder="Cheque number or transaction reference"></label><label class="wide">Export expense description<input name="description" placeholder="e.g. silica gel for export packing" required></label></div><div role="alert" id="ttExportExpenseError" class="tt-note"></div><div class="tt-form-actions"><button type="submit" class="btn primary">Post export expense</button></div></form>${(data.generalExpenses||[]).filter(row=>row.expenseType==='EXPORT').slice(-12).reverse().map(row=>`<div class="tt-record-card">${esc(row.paymentDate)} · ${esc(row.payee)} · PKR ${money(row.amount)} · ${esc(row.description)} <b>${esc(row.journalId)}</b></div>`).join('')}`;
      const form=q('#ttExportExpenseForm',body);
      const requestKey=crypto.randomUUID();
      form.onsubmit=async event=>{
        event.preventDefault();const submit=q('[type="submit"]',form);submit.disabled=true;
        const payload=Object.fromEntries(new FormData(form));
        try {const result=await json('../api/expenses_v1.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({...payload,action:'pay_export_expense',entity:entity(),requestKey,csrf:access.csrf})});body.innerHTML=`<div class="tt-record-card"><h3>Export expense posted</h3><p>Journal voucher <b>${esc(result.result?.journalId||'')}</b></p><button type="button" class="btn" id="ttAnotherExportExpense">Enter another</button></div>`;q('#ttAnotherExportExpense',body).onclick=openOtherExportExpense;}
        catch(error){q('#ttExportExpenseError',form).textContent=error.message||String(error);submit.disabled=false;}
      };
    }catch(error){body.textContent=error.message||String(error);}
  }

  function openShipmentBillForm(kind,shipment) {
    const titles={freight:'Freight Forwarder / Shipping',clearing:'Clearing Agent',transport:'Transporter',fumigation:'Fumigation',inspection:'Inspection'};
    const host=layer('ttShipmentBillForm',titles[kind]+' Bill'),body=q('.tt-window-body',host);
    const label=titles[kind];
    const header=`<div class="tt-record-card"><b>${esc(shipment.customer)} · ${esc(shipment.contract)} · ${esc(shipment.lot)}</b><p>Invoice ${esc(shipment.commercialInvoice||shipment.customsInvoice||'—')} · B/L ${esc(shipment.bl||'—')} · Loading programme ${esc(shipment.loadingProgramme||'—')}</p><p>${esc(shipment.shippingLine||'—')} · ${esc(shipment.portOfLoading||'—')} → ${esc(shipment.portOfDischarge||'—')} · ${esc(shipment.containers.join(', ')||'No containers saved')}</p></div>`;
    const transport=kind==='transport',service=!transport&&kind!=='freight';
    body.innerHTML=header+`<form id="ttShipmentBillEntry" class="tt-form"><div class="tt-form-grid"><label>${label}<input id="ttShipmentBillVendor" required placeholder="Choose ${label} from Business Parties"></label><label>Supplier bill number<input name="invoiceNo" required></label><label>Supplier bill date<input name="billDate" type="date" value="${esc(today())}" required></label>${transport?`<label>Loading programme<input name="loadingProgrammeNo" value="${esc(shipment.loadingProgramme)}" required></label><label>Loaded containers<input name="containers" type="number" min="1" value="${shipment.containers.length||''}" required></label><label>Rate per container (PKR)<input name="rate" type="number" min="0.01" step="0.01" required></label>`:''}${kind==='freight'?`<label>Actual B/L number<input name="blNo" value="${esc(shipment.bl)}" required></label>`:''}</div><div class="tt-record-card"><b>${transport?'Other charges and deductions':'Bill charges and deductions'}</b><div id="ttShipmentBillLines"></div><button type="button" class="btn" id="ttShipmentBillAdd">+ Line</button><p><b>FINAL BILL PAYABLE: PKR <span id="ttShipmentBillTotal">0.00</span></b></p></div><label style="display:block">Remarks<input name="remarks" placeholder="Optional bill details"></label><div id="ttShipmentBillError" class="tt-note" role="alert"></div><div class="tt-form-actions"><button type="submit" class="btn primary">Post supplier bill</button></div></form>`;
    const form=q('#ttShipmentBillEntry',body),lines=q('#ttShipmentBillLines',form);
    const addLine=(description='',type='ADD')=>{const line=document.createElement('div');line.className='tt-form-grid tt-shipment-charge';line.innerHTML=`<label>Description<input data-description required></label><label>Type<select data-type><option value="ADD">Addition</option><option value="DEDUCT">Deduction</option></select></label><label>Amount (PKR)<input data-amount type="number" min="0.01" step="0.01" required></label><button type="button" class="btn" data-remove style="align-self:end">Remove</button>`;line.querySelector('[data-description]').value=description;line.querySelector('[data-type]').value=type;line.querySelector('[data-remove]').onclick=()=>{line.remove();calculate()};lines.appendChild(line);};
    const charges=()=>qa('.tt-shipment-charge',lines).map(line=>({description:q('[data-description]',line).value.trim(),type:q('[data-type]',line).value,amount:Number(q('[data-amount]',line).value)}));
    const calculate=()=>{const base=transport?Number(form.elements.containers.value)*Number(form.elements.rate.value):0;const total=charges().reduce((sum,line)=>sum+(line.type==='ADD'?1:-1)*(line.amount||0),base);q('#ttShipmentBillTotal',form).textContent=money(total);return total;};
    if(!transport)addLine(kind==='freight'?'Freight':label);
    q('#ttShipmentBillAdd',form).onclick=()=>addLine();form.addEventListener('input',calculate);form.addEventListener('change',calculate);
    form.onsubmit=async event=>{
      event.preventDefault();const amount=calculate(),items=charges(),vendor=q('#ttShipmentBillVendor',form).value.trim();
      if(amount<=0||items.some(item=>!item.description||!(item.amount>0)))return q('#ttShipmentBillError',form).textContent='Complete each line and enter a positive final bill amount.';
      const common={entity:entity(),shipmentId:shipment.id,vendor,invoiceNo:form.elements.invoiceNo.value.trim(),billDate:form.elements.billDate.value,remarks:[shipment.id,shipment.contract,shipment.commercialInvoice,form.elements.remarks.value.trim()].filter(Boolean).join(' · ')};
      let payload;
      if(transport){payload={...common,action:'save_transport_bill',lines:[{loadingProgrammeNo:form.elements.loadingProgrammeNo.value.trim(),containers:Number(form.elements.containers.value),from:shipment.portOfLoading,to:shipment.portOfDischarge,rate:Number(form.elements.rate.value),extras:0}],adjustments:items};}
      else if(service){payload={...common,action:'save_service_bill',kind:kind.toUpperCase(),shipmentRef:shipment.lot||shipment.id,gdNo:shipment.gd,jobNo:shipment.loadingProgramme,portOfDischarge:shipment.portOfDischarge,containerCount:shipment.containers.length,customerRef:shipment.customer,amount,billLines:items};}
      else {payload={...common,action:'save_freight_bill',actualBlNo:form.elements.blNo.value.trim(),loadingProgrammeNo:shipment.loadingProgramme,shippingLine:shipment.shippingLine,fromPort:shipment.portOfLoading,destinationPort:shipment.portOfDischarge,containerCount:shipment.containers.length,containerSize:'20',exchangeRate:0,charges:items.map(item=>({charge:item.description,basis:'FIXED',qty:1,currency:'PKR',billedRate:(item.type==='ADD'?1:-1)*item.amount,acceptedRate:(item.type==='ADD'?1:-1)*item.amount}))};}
      const button=q('[type="submit"]',form);button.disabled=true;
      try{const saved=await json('../api/accounts_workflows_v1.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({...payload,csrf:access.csrf})});body.innerHTML=`<div class="tt-record-card"><h3>Supplier bill posted</h3><p>Write posting number <b>${esc(saved.bill?.id||'')}</b> on the supplier bill. Journal ${esc((saved.bill?.postingJournalIds||[]).slice(-1)[0]||'')}</p><button class="btn" id="ttShipmentBillDone">Close</button></div>`;q('#ttShipmentBillDone',body).onclick=()=>q('.tt-window-close',host).click();}
      catch(error){q('#ttShipmentBillError',form).textContent=error.message||String(error);button.disabled=false;}
    };
  }

  async function openShipmentKind(kind) {
    if(!['freight','transport','clearing','fumigation','inspection'].includes(String(kind||'').toLowerCase()))return openShipmentChooser();
    const host=layer('ttBillShipmentSearch',`Find Shipment · ${kind}`),body=q('.tt-window-body',host);
    body.innerHTML='<div class="tt-form"><p>Search by customer, contract, invoice, Customs invoice, container, B/L, loading programme, shipping line, vessel, port, brand, GD, FI or Bag PO.</p><div class="tt-searchbar"><input id="ttBillShipmentQuery" autofocus placeholder="Enter any shipment reference"><button id="ttBillShipmentGo">Search</button></div><div id="ttBillShipmentHits" style="margin-top:12px"></div></div>';
    const search=async()=>{const term=q('#ttBillShipmentQuery',host).value.trim(),hits=q('#ttBillShipmentHits',host);if(term.length<2){hits.textContent='Enter at least two characters.';return}hits.textContent='Searching Exports…';try{const result=await json('../api/accounts_shipment_lookup.php?entity='+encodeURIComponent(entity())+'&q='+encodeURIComponent(term));hits.innerHTML=result.rows.length?result.rows.map((row,index)=>`<article class="tt-record-card"><b>${esc(row.customer)} · ${esc(row.contract)} · ${esc(row.lot)}</b><div class="tt-note">Commercial invoice ${esc(row.commercialInvoice||'—')} · Customs ${esc(row.customsInvoice||'—')} · B/L ${esc(row.bl||'—')} · ${esc(row.vessel||'')} ${esc(row.voyage||'')}</div><div class="tt-note">${esc(row.loadingProgramme||'')} · ${esc(row.shippingLine||'')} · ${esc(row.portOfLoading||'')} → ${esc(row.portOfDischarge||'')} · Containers ${esc(row.containers.join(', ')||'—')} · PO ${esc(row.po||'—')}</div><button class="btn" data-tt-pick-shipment="${index}">Confirm this shipment</button></article>`).join(''):'No linked shipment matches. Check the selected company and reference.';qa('[data-tt-pick-shipment]',hits).forEach(button=>button.onclick=async()=>{const row=result.rows[Number(button.dataset.ttPickShipment)];q('.tt-window-close',host).click();openShipmentBillForm(kind,row);});}catch(error){hits.textContent=error.message}};
    q('#ttBillShipmentGo',host).onclick=search;q('#ttBillShipmentQuery',host).onkeydown=event=>{if(event.key==='Enter')search()};
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
    document.documentElement.classList.remove('tt-accounts-boot');
    q('#tt-accounts-boot-style')?.remove();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once:true}); else init();
  window.TT_ACCOUNTING_DESK = {installed:true, openSoda, openSearch, showArea, printVoucher};
})();
