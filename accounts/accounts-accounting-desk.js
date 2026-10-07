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
  let activeSoda = null;

  const pakistanAreas = [
    {key:'exports', glyph:'⇄', title:'Export Receipts & Payments', note:'Every export receipt, document and shipment expense', actions:[
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
      {title:'Local Sales & Receipts', note:'Mill sale approvals and linked receipts awaiting Accounts action', native:'receivables', find:'Local'}
    ]},
    {key:'bank', glyph:'▦', title:'Bank & Cash', note:'Internal transfers, foreign retention and bank reconciliation', actions:[
      {title:'Review TG Remittances', special:'tg-remittances'}, {title:'Inter Account Transfer', note:'Move PKR between company accounts or to a personal account with a reason', special:'internal-bank-transfer'},
      {title:'Foreign Retention Account', note:'Settle foreign commissions and other linked outward remittances', special:'retention-remittance'},
      {title:'Bank Accounts & Balances', native:'bank'}, {title:'Bank Reconciliation', native:'reconciliation'}
    ]},
    {key:'routine', glyph:'◇', title:'Expenses', note:'Pay expenses, utilities, cards or salaries', actions:[
      {title:'Pay Expense', note:'Pay anyone; combine several expenses in one cheque or payment', native:'expenses', special:'expense-pay'},
      {title:'Bills & Credit Cards', note:'Choose Utilities or Credit Cards', native:'expenses', special:'expense-bills'},
      {title:'Salaries & Staff', note:'Salary advance and monthly salary preparation', native:'expenses', special:'expense-salary'}
    ]},
    {key:'ledgers', glyph:'L', title:'Ledgers', note:'Choose a party or account, view balances, print or download Excel', actions:[
      {title:'Party Ledgers', note:'Type a party name to open its ledger', special:'all-ledgers', ledgerCategory:'party'},
      {title:'Supplier / Broker', special:'all-ledgers', ledgerCategory:'supplier'},
      {title:'Customer', special:'all-ledgers', ledgerCategory:'customer'},
      {title:'Bank / Cash', special:'all-ledgers', ledgerCategory:'bank'},
      {title:'Account Ledgers', note:'Search by account head', special:'all-ledgers', ledgerCategory:'other'}
    ]},
    {key:'registers', glyph:'▣', title:'Registers & Corrections', note:'Posting records and controlled journal corrections', actions:[
      {title:'Post ID Register', special:'post-ledger'}, {title:'Journal Voucher', native:'jv'},
      {title:'Bill & Invoice Registers', special:'bill-registers'}, {title:'Soda Register', special:'soda'}
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
  pakistanAreas.find(area=>area.key==='routine').actions.push({title:'Other Purchases',note:'Assets and consumables outside commodity Sodas',special:'supplier-bills',billKind:'other',native:'purchases',then:'[data-purchase="other"]'});
  const tgAreas = [
    {key:'tg-receipts', glyph:'↓', title:'Customer Receipts', note:'Receive money and allocate it to the correct TG customer', actions:[
      {title:'Customer Receipt', special:'tg-customer-receipt'}, {title:'Customer Receivables', native:'receivables'}
    ]},
    {key:'tg-payments', glyph:'↑', title:'Supplier Payments', note:'Supplier liabilities and payments only', actions:[
      {title:'Post Bill', native:'payables'}, {title:'Payment', special:'bill-payment'}
    ]},
    {key:'tg-bank', glyph:'▦', title:'Bank & Local Expenses', note:'Bank transfers, payments and local operating expense', actions:[
      {title:'Inter Account Transfer', note:'Choose the source and destination; currency direction and the TG Master rate are automatic', special:'internal-bank-transfer'},
      {title:'Bank Receipt / Payment', native:'bank'}, {title:'Local Expense', native:'expenses', then:'[data-expense="general"]'},
      {title:'Utilities', native:'expenses', then:'[data-expense="utility"]'}, {title:'Bank Reconciliation', native:'reconciliation'}
    ]},
    {key:'tg-ledgers', glyph:'L', title:'Ledgers', note:'TG party and account statements with print and Excel', actions:[
      {title:'Party Ledgers', note:'Type a party name to open its ledger', special:'all-ledgers', ledgerCategory:'party'},
      {title:'Supplier / Broker', special:'all-ledgers', ledgerCategory:'supplier'},
      {title:'Customer', special:'all-ledgers', ledgerCategory:'customer'},
      {title:'Bank / Cash', special:'all-ledgers', ledgerCategory:'bank'},
      {title:'Account Ledgers', note:'Search by account head', special:'all-ledgers', ledgerCategory:'other'}
    ]},
    {key:'tg-registers', glyph:'▣', title:'Registers & Corrections', note:'Posting records and journal corrections', actions:[
      {title:'Post ID Register', special:'post-ledger'}, {title:'Journal Voucher', native:'jv'},
      {title:'Bill & Invoice Registers', special:'bill-registers'}
    ]},
    {key:'tg-reports', glyph:'▤', title:'Reports', note:'TG balances and financial reports', actions:[
      {title:'Trial Balance', native:'reports', find:'Trial Balance'}, {title:'Profit & Loss', native:'reports', find:'Profit'},
      {title:'Balance Sheet', native:'reports', find:'Balance Sheet'}, {title:'Receivables / Payables', native:'reports', find:'Receivables'}
    ]}
  ];
  if(access.canInventoryReconciliation) pakistanAreas.find(area=>area.key==='reports').actions.push({title:'Ghati & Stock Reconciliation',note:'Accounts / Directors only; never adds stock or another purchase',special:'stock-reconciliation'});
  const currentAreas = () => entity() === 'TG' ? tgAreas : pakistanAreas;

  const assetPermission=access.super||access.permissions==='all'||(Array.isArray(access.permissions)?access.permissions.includes('View'):(access.permissions?.assets||[]).includes('View'));
  if(assetPermission)for(const areas of [pakistanAreas,tgAreas])areas.splice(3,0,{key:'assets',title:'Assets / Properties',note:'Register properties, vehicles and instalment payments',actions:[]});

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
    document.head.appendChild(style);
  }

  function nativeCard(key) {
    return q(`#ttNativeLaunchers .appCard[data-key="${key}"], #homeGrid .appCard[data-key="${key}"]`);
  }

  async function launch(action) {
    if(action.special==='expense-pay')return window.TT_EXPENSE_DESK.open();
    if(action.special==='expense-bills')return window.TT_EXPENSE_DESK.bills();
    if(action.special==='expense-salary')return window.TT_EXPENSE_DESK.openNative('salary','Salaries & Staff');
    if(action.native==='expenses'&&action.then){const mode=action.then.match(/data-expense="([^"]+)/)?.[1];if(mode&&['utility','card','salary','rent','general','reimburse','donations'].includes(mode))return window.TT_EXPENSE_DESK.openNative(mode,action.title||'Expenses');}
    if(action.special==='payment-plan')return window.TT_PAYMENT_PLANS?.choose?.();
    if(action.special==='broker-payment-plan')return window.TT_PAYMENT_PLANS?.open?.('BROKER');
    if(action.special==='tg-remittances')return window.TT_TG_REMITTANCES.open();
    if(action.special==='all-ledgers')return window.TT_ALL_LEDGERS?.open?.('',action.ledgerCategory||'other');
    if(action.special==='bill-registers')return openSearch();
    if(action.special==='supplier-bills')return openBillDesk(action.billKind,action);
    if(action.special==='bill-payment')return window.TT_SUPPLIER_SETTLEMENT_UI?.openBills?.(action.category||'');
    if(action.special==='post-ledger')return window.TT_ALL_LEDGERS?.open?.('POSTS');
    if(action.special==='little-master')return openLittleMaster();
    if(action.special==='other-export-expense')return openOtherExportExpense();
    if (action.special === 'stock-reconciliation' && access.canInventoryReconciliation) { location.href='/stock-reconciliation.php?origin=accounts&entity='+encodeURIComponent(entity()); return; }
    if (action.special === 'soda') return openSoda();
    if (action.special === 'search') return openSearch();
    if (action.special === 'sales-tax') return openSalesTax();
    if (action.special === 'export-receipt') return window.TT_EXPORT_RECEIPTS_UI?.openForm?.();
    if (action.special === 'tg-customer-receipt') return window.TT_TG_CUSTOMER_RECEIPTS?.open?.();
    if (action.special === 'internal-bank-transfer') return window.TT_INTERNAL_BANK_TRANSFERS_UI?.open?.();
    if (action.special === 'tg-currency-transfer') { await launch({native:'bank'}); return window.TT_TG_BANK_TRANSFER_UI?.open?.(); }
    if (action.special === 'retention-remittance') { await launch({native:'bank'}); return window.TT_RETENTION_REMITTANCE_UI?.open?.(); }
    if (action.special === 'shipment') return openShipmentChooser();
    if (action.special === 'freight-desk') return openFreightDesk();
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
    document.dispatchEvent(new CustomEvent('tt:accounts-desk-form-opened', {
      detail: {title: action.title || ''}
    }));
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
          <div class="tt-position" id="ttSummaryCards"><button class="tt-summary"><small>Bank Balance</small><b>Loading…</b><em>Hover for accounts</em></button><button class="tt-summary"><small>Commodity Bills Due</small><b>Loading…</b><em>Due-date detail</em></button><button class="tt-summary"><small>Local Receivables</small><b>Loading…</b><em>Customer detail</em></button><button class="tt-summary"><small>Export Receivables</small><b>Loading…</b><em>Currency detail</em></button></div>
        </section>
        <section id="ttDeskWork"></section>
        <section><div class="tt-work-head"><div><h2>Held / Incomplete Entries</h2><p>Only entries that need review before posting appear here.</p></div></div><div class="tt-queue" id="ttAttentionQueue"><div class="tt-queue-row"><span>Status</span><b>Loading current work…</b></div></div></section>
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
    qa('[data-tt-area]', work).forEach(button=>button.onclick=()=>button.dataset.ttArea==='assets'?window.TT_ASSETS_UI?.open?.():showArea(button.dataset.ttArea));
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
        ? [{key:'bank',label:'Bank Balance',note:'Default account · hover for all accounts'},{key:'local',label:'Customer Receivables',note:'Customer detail'},{key:'commodity',label:'Supplier Bills Due',note:'Due-date detail'}]
        : [{key:'bank',label:'Bank Balance',note:'Default account · hover for all accounts'},{key:'commodity',label:'Commodity Bills Due',note:'Due-date detail'},{key:'local',label:'Local Receivables',note:'Customer detail'},{key:'export',label:'Export Receivables',note:'Customer / currency detail'}];
      host.style.gridTemplateColumns=`repeat(${definitions.length},1fr)`;
      host.innerHTML=definitions.map(def=>{
        const rows=data.summaries?.[def.key]||[], totals={};rows.forEach(row=>{const cur=row.currency||'PKR';totals[cur]=(totals[cur]||0)+Number(row.amount||0)});
        const first=rows[0],defaultBank=rows.find(row=>row.isDefault&&row.currency===(entity()==='TG'?'AED':'PKR'))||rows.find(row=>row.isDefault);
        const headline=def.key==='bank'?(defaultBank?`${esc(defaultBank.currency||'PKR')} ${money(defaultBank.amount)}`:'No default account'):def.key==='due'?(first?`${esc(first.label)} · ${esc(first.currency||'PKR')} ${money(first.amount)} · ${esc(first.dateDisplay||first.date||'')}`:'No due payments'):Object.keys(totals).length?Object.entries(totals).map(([cur,value])=>`${esc(cur)} ${money(value)}`).join(' · '):'PKR 0.00';
        const detail=rows.length?rows.slice(0,20).map(row=>`<div><b>${esc(row.label||row.reference||'Account')}</b><br>${esc(row.reference||'')}${row.dateDisplay?' · '+esc(row.dateDisplay):''} · ${esc(row.currency||'PKR')} ${money(row.amount)}</div>`).join(''):'<div>No open balance.</div>';
        return `<button type="button" class="tt-summary ${def.key==='due'&&first&&first.date<=today()?'tt-due-alert':''}" data-summary="${def.key}"><small>${esc(def.label)}</small><b>${headline}</b><em>${esc(def.note)}</em><span class="tt-summary-pop">${detail}</span></button>`;
      }).join('');
       qa('[data-summary]',host).forEach(button=>button.onclick=()=>{const key=button.dataset.summary;if(key==='bank')launch({native:'bank'});else if(key==='due')showArea('routine');else if(key==='commodity'){if(entity()!=='TG')window.TT_PAYMENT_PLANS?.choose?.();else launch({native:'payables'});}else launch({native:'receivables'});});
      if(queue){const rows=data.attention||[];queue.innerHTML=rows.length?rows.slice(0,12).map((row,i)=>`<div class="tt-queue-row"><span>${esc(row.type)}</span><b>${esc(row.message)}${row.reference?' · '+esc(row.reference):''}</b><button type="button" data-attention-review="${i}">Review</button></div>`).join(''):'<div class="tt-queue-row"><span>Current</span><b>No held or incomplete entries need attention.</b></div>';qa('[data-attention-review]',queue).forEach(button=>button.onclick=()=>reviewAttention(rows[Number(button.dataset.attentionReview)]));}
    }catch(error){qa('.tt-summary b',host).forEach(node=>node.textContent='Unavailable');if(queue)queue.innerHTML='<div class="tt-queue-row"><span>Status</span><b>Refresh to load current Accounts attention items.</b></div>';console.warn('Accounts dashboard summary',error);}
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

  async function json(url, options) {
    const response = await fetch(url, {credentials:'same-origin', headers:{Accept:'application/json', ...(options?.headers || {})}, ...options});
    let data = {};
    try { data = await response.json(); } catch (_) {}
    if (!response.ok || !data.ok) { const error = new Error(data.error || 'Accounts request could not be completed.'); error.rejected = !!data.error && response.status >= 400 && response.status < 500; throw error; }
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
        <label>Type<select id="ttSdStage"><option value="RAW"${productStage==='RAW'?' selected':''}>RAW</option><option value="READY"${productStage==='READY'?' selected':''}>READY</option></select></label>
        <label>Variety<select id="ttSdVariety">${varieties.map(name=>`<option value="${esc(name)}"${name.toLowerCase()===productVariety.toLowerCase()?' selected':''}>${esc(name)}</option>`).join('')}</select></label>
        <label class="wide">Purchase Product<span class="tt-master-control"><select id="ttSdProduct" required><option value="">Choose product for this variety</option>${productOptions}</select><button type="button" class="btn" id="ttSdAddProduct" title="Add Purchase Product"${sodaData.permissions?.canAddProduct?'':' hidden'}>+</button><button type="button" class="btn" id="ttSdRemoveProduct" title="Remove selected Purchase Product"${sodaData.permissions?.canRemoveProduct?'':' hidden'}>−</button></span></label>
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
    const terms=q('#ttSdTerms',form),days=q('#ttSdCredit',form),daysWrap=q('#ttSdCreditWrap',form),stageSelect=q('#ttSdStage',form),varietySelect=q('#ttSdVariety',form),productSelect=q('#ttSdProduct',form),route=q('#ttSdRoute',form),routeWrap=q('#ttSdRouteWrap',form),stock=q('#ttSdStock',form),stockWrap=q('#ttSdStockWrap',form),exMill=q('#ttSdExMill',form),exMillWrap=q('#ttSdExMillWrap',form),supplier=q('#ttSdSupplier',form);
    let locationTouched=!!record?.locationId;
    const setSelect=(control,value)=>{control.value=value;const input=control.closest('.tt-search-select')?.querySelector(':scope>input');if(input)input.value=control.selectedOptions[0]?.textContent.trim()||'';};
    const selectedProduct=()=> (sodaData.purchaseProducts||[]).find(item=>item.id===productSelect.value)||null;
    const sameVariety=(item,name)=>String(item.baseVariety||'').toLowerCase()===String(name||'').toLowerCase();
    const refreshProducts=(preferred='')=>{const rows=(sodaData.purchaseProducts||[]).filter(item=>item.productStage===stageSelect.value&&sameVariety(item,varietySelect.value));productSelect.replaceChildren(new Option('Choose product for this variety',''),...rows.map(item=>new Option(item.displayName,item.id)));setSelect(productSelect,rows.some(item=>item.id===preferred)?preferred:rows.length===1?rows[0].id:'');productSelect.dispatchEvent(new Event('change',{bubbles:true}));};
    const refreshVarieties=(preferred='')=>{const names=[...new Set((sodaData.purchaseProducts||[]).filter(item=>item.productStage===stageSelect.value).map(item=>item.baseVariety))].sort((a,b)=>a.localeCompare(b));varietySelect.replaceChildren(...names.map(name=>new Option(name,name)));setSelect(varietySelect,names.find(name=>name.toLowerCase()===preferred.toLowerCase())||names.find(name=>name.toLowerCase()==='irri-6')||names[0]||'');refreshProducts();};
    const paymentState=()=>{const isCredit=terms.value==='CREDIT';daysWrap.hidden=!isCredit;days.disabled=!isCredit;days.required=isCredit;if(!isCredit)days.value='';q('#ttSdPaymentHelp',form).textContent=isCredit?`Payment becomes due on Arrival / Pohanch date + ${days.value||'agreed'} day(s).`:'Cash payment becomes due on Arrival / Pohanch date + 2 days.';};
    terms.onchange=paymentState;days.oninput=paymentState;paymentState();
    const syncRoute=(initial=false)=>{const ready=selectedProduct()?.productStage==='READY';routeWrap.hidden=!ready;if(!ready){setSelect(route,'DELIVER_TO_STOCK');setSelect(exMill,'');stockWrap.hidden=false;exMillWrap.hidden=true;if(!record&&!stock.value)setSelect(stock,sodaData.defaults?.rawLocationId||'');}else{if(!initial){setSelect(route,'');setSelect(stock,'');setSelect(exMill,'');locationTouched=false;}stockWrap.hidden=route.value!=='DELIVER_TO_STOCK';exMillWrap.hidden=route.value!=='EX_MILL';}const ex=ready&&route.value==='EX_MILL',target=q('#ttSdTarget',form),min=q('#ttSdMin',form),max=q('#ttSdMax',form);q('#ttSdTargetWrap',form).hidden=!ex;q('#ttSdMinWrap',form).hidden=ex;q('#ttSdMaxWrap',form).hidden=ex;q('#ttSdTrucksLabel',form).textContent=ex?'Truck / Containers':'Expected Trucks';q('#ttSdRouteHelp',form).textContent=ex?'No Pohanch or KAT. Each saved container weighbridge weight posts the purchase liability.':'Delivery to our location uses arrival Pohanch and the approved KAT profile.';if(ex&&!target.value)target.value=max.value||min.value;if(!ex&&target.value&&!min.value){min.value=target.value;max.value=target.value;}supplier.required=ex;};
    productSelect.onchange=()=>syncRoute(false);
    stageSelect.onchange=()=>refreshVarieties(varietySelect.value);
    varietySelect.onchange=()=>refreshProducts();
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
        terms:q('#ttSdConditions', form).value.trim(), remarks:q('#ttSdRemarks', form).value.trim(), reason:q('#ttSdReason', form)?.value.trim() || ''
      };
      const button = q('[type="submit"]', form); button.disabled = true;
      try {
        const result = await json(api, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload)});
        sodaData = result;
        alert(record ? `Soda ${record.sodaNo} amended. The change and reason were added to its audit history.` : `Soda ${result.created?.sodaNo || 'number'} saved. No ledger entry was posted.`);
        if (record) renderSodaSearch(host); else renderSodaNew(host);
      } catch (error) { alert(error.message); button.disabled = false; }
    };
  }

  function renderSodaSearch(host, term = '') {
    const body = q('.tt-window-body', host);
    const needle = term.trim().toLowerCase();
    const rows = (sodaData.sodas || []).filter(row => !needle || [row.sodaNo,row.broker,row.party,row.commodity,row.variety,row.location,row.status,row.sodaDate].some(value => String(value || '').toLowerCase().includes(needle)));
    body.innerHTML = `<div class="tt-modebar"><button data-soda-mode="new">New Soda</button><button class="active" data-soda-mode="search">Search / Amend Soda</button></div><div class="tt-searchbar"><input id="ttSodaSearch" value="${esc(term)}" placeholder="Soda no, broker, supplier, commodity, variety, mill or date"><button type="button">Search</button></div><div class="tt-records"><div class="tableWrap"><table><thead><tr><th>Soda</th><th>Date</th><th>Commodity</th><th>Broker / Supplier</th><th>Quantity</th><th>Rate</th><th>Status</th><th></th></tr></thead><tbody>${rows.length ? rows.map(row => `<tr><td><b>${esc(row.sodaNo)}</b></td><td>${esc(row.sodaDate)}</td><td>${esc(row.commodity)}<br><small>${esc(row.variety)}</small></td><td>${esc(row.broker)}<br><small>${esc(row.party || '')}</small></td><td>${Number(row.qtyFromKg||0)?money(Number(row.qtyFromKg)/1000)+'–'+money(Number(row.qtyToKg||row.qtyFromKg)/1000)+' MT':''}${row.expectedTrucks?'<br>'+esc(row.expectedTrucks)+' trucks':''}</td><td>${money(row.rate ?? row.ratePerKg)} / ${esc(row.rateUnit || 'KG')}</td><td>${esc(row.calculatedStatus || row.status)}</td><td><button type="button" class="btn" data-soda-edit="${esc(row.id)}">View / Amend</button> <button type="button" class="btn" data-soda-print="${esc(row.id)}">Print</button>${access.super?` <button type="button" class="btn danger" data-soda-delete="${esc(row.id)}">Delete</button>`:''}</td></tr>`).join('') : '<tr><td colspan="8">No matching Soda.</td></tr>'}</tbody></table></div></div>`;
    q('[data-soda-mode="new"]', body).onclick = () => renderSodaNew(host);
    const search = () => renderSodaSearch(host, q('#ttSodaSearch', body).value);
    q('.tt-searchbar button', body).onclick = search;
    q('#ttSodaSearch', body).onkeydown = event => { if (event.key === 'Enter') search(); };
    bindSodaRows(host, () => renderSodaSearch(host, term));
    qa('[data-soda-delete]', body).forEach(button => button.onclick = async () => {
      const record=(sodaData.sodas||[]).find(row=>row.id===button.dataset.sodaDelete);if(!record)return;
      if(!confirm(`Permanently delete Soda ${record.sodaNo}? This is allowed only before any Pohanch, bill or settlement is linked.`))return;
      const reason=prompt('Reason for permanent deletion:','Entered in error');if(!reason?.trim())return;
      try{sodaData=await json(api,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete',csrf:access.csrf,entity:entity(),id:record.id,reason:reason.trim()})});renderSodaSearch(host,term)}catch(error){alert(error.message)}
    });
  }

  function printSoda(row) {
    const popup=record?.popup||window.open('','_blank');if(!popup)return alert('Allow popups to print the Soda.');
    const fields=[['Soda number',row.sodaNo],['Date',row.sodaDate],['Company',row.entity],['Commodity / product',[row.commodity,row.productStage,row.displayName||row.variety,row.riceType,row.brokenGrade].filter(Boolean).join(' · ')],['Broker',row.broker||'—'],['Supplier',row.party||'—'],['Purchase route',row.readyRoute||row.movementRole||'—'],['Mill / stock location',row.locationName||row.location||'—'],['Quantity',`${money(Number(row.qtyFromKg||0)/1000)} to ${money(Number(row.qtyToKg||0)/1000)} MT`],['Expected trucks / containers',row.expectedTrucks||'—'],['Rate',`${money(row.rate??row.ratePerKg)} per ${row.rateUnit||'KG'}`],['Payment term',`${row.paymentTermType||'CASH'}${row.paymentTermType==='CREDIT'?' · '+(row.creditDays||'')+' days':''}`],['Expected arrival / delivery',row.arrivalDueDate||row.deliveryDeadline||'—'],['Terms / conditions',row.terms||'—'],['Remarks',row.remarks||'—'],['Status',row.calculatedStatus||row.status||'—']];
    popup.document.write(`<!doctype html><meta charset="utf-8"><title>Soda ${esc(row.sodaNo)}</title><style>@page{size:A4;margin:16mm}body{font:12px Arial;color:#1b2a34}h1{text-align:center;color:#165848;font-size:19px}h2{text-align:center;font-size:15px}table{width:100%;border-collapse:collapse;margin-top:24px}th,td{border:1px solid #cddbd7;padding:9px;text-align:left;vertical-align:top}th{width:32%;background:#edf5f1}button{float:right}@media print{button{display:none}}</style><button onclick="print()">Print Soda</button><h1>${esc(row.entity||entity())}</h1><h2>PURCHASE SODA · ${esc(row.sodaNo)}</h2><table>${fields.map(([label,value])=>`<tr><th>${esc(label)}</th><td>${esc(value)}</td></tr>`).join('')}</table>`);popup.document.close();
  }

  function bindSodaRows(host, back) {
    const body=q('.tt-window-body',host);
    qa('[data-soda-print]',body).forEach(button=>button.onclick=()=>{const row=(sodaData.sodas||[]).find(item=>item.id===button.dataset.sodaPrint);if(row)printSoda(row)});
    qa('[data-soda-edit]',body).forEach(button=>button.onclick=()=>{
      activeSoda=(sodaData.sodas||[]).find(row=>row.id===button.dataset.sodaEdit);if(!activeSoda)return;
      body.innerHTML=`<div class="tt-modebar"><button data-back-soda>← Sodas</button><button type="button" class="btn" data-soda-print="${esc(activeSoda.id)}">Print Soda</button></div>${sodaForm(activeSoda)}${activeSoda.audit?.length?`<div class="tt-record-card"><b>Amendment history</b><pre>${esc(JSON.stringify(activeSoda.audit,null,2))}</pre></div>`:''}`;
      q('[data-back-soda]',body).onclick=back;
      q('[data-soda-print]',body).onclick=()=>printSoda(activeSoda);
      bindSodaForm(host,activeSoda);
    });
  }

  function renderSodaNew(host) {
    const body = q('.tt-window-body', host);
    const cutoff=new Date(`${today()}T12:00:00`);cutoff.setMonth(cutoff.getMonth()-2);
    const dateFloor=cutoff.getFullYear()+'-'+String(cutoff.getMonth()+1).padStart(2,'0')+'-'+String(cutoff.getDate()).padStart(2,'0');
    const recent=(sodaData.sodas||[]).filter(row=>String(row.sodaDate||'')>=dateFloor).sort((a,b)=>String(b.sodaDate||'').localeCompare(String(a.sodaDate||''))||String(b.sodaNo||'').localeCompare(String(a.sodaNo||'')));
    body.innerHTML = `<div class="tt-modebar"><button class="active" data-soda-mode="new">New Soda</button><button data-soda-mode="search">Search / Amend Soda</button></div>${sodaForm()}<div class="tt-records"><h3>Recent Sodas · last two months</h3><div class="tableWrap"><table><thead><tr><th>Date</th><th>Soda</th><th>Supplier / Broker</th><th>Product</th><th>Quantity</th><th>Status</th><th>Action</th></tr></thead><tbody>${recent.length?recent.map(row=>`<tr><td>${esc(row.sodaDate)}</td><td><b>${esc(row.sodaNo)}</b></td><td>${esc(row.party||'—')}<br><small>${esc(row.broker||'')}</small></td><td>${esc(row.displayName||row.variety||row.commodity)}</td><td>${money(Number(row.qtyToKg||row.qtyFromKg||0)/1000)} MT</td><td>${esc(row.calculatedStatus||row.status)}</td><td><button type="button" class="btn" data-soda-edit="${esc(row.id)}">View / Amend</button> <button type="button" class="btn" data-soda-print="${esc(row.id)}">Print</button></td></tr>`).join(''):'<tr><td colspan="7">No Sodas dated within the last two months. Search previous Sodas for older records.</td></tr>'}</tbody></table></div></div>`;
    q('[data-soda-mode="search"]', body).onclick = () => renderSodaSearch(host);
    bindSodaRows(host,()=>renderSodaNew(host));
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
    q('.tt-window-body', host).innerHTML = `<div class="tt-searchbar"><input id="ttUniversalSearch" value="${esc(typeof initial === 'string' ? initial : '')}" autofocus placeholder="Voucher, bill, Soda, truck, cheque, shipment, container, B/L, party, date or amount"><button type="button">Search</button></div><div id="ttSearchResults"></div>`;
    const go = () => runSearch(host, q('#ttUniversalSearch', host).value);
    q('.tt-searchbar button', host).onclick = go;
    q('#ttUniversalSearch', host).onkeydown = event => { if (event.key === 'Enter') go(); };
    if (typeof initial === 'string' && initial.trim()) go(); else runSearch(host, '');
  }

  async function printVoucher(record) {
    const details=record?.data||{};
    const id=String(details.journalId||details.chequeIssueJournalId||details.journal?.id||details.postingJournalIds?.[0]||(Array.isArray(details.lines)?details.id:'')||'').trim();
    if(!id)return alert('A saved journal is required to print this voucher. Open the linked Post ID in the ledger.');
    const popup=record?.popup||window.open('','_blank');if(!popup)return alert('Allow popups to print the voucher.');
    popup.document.write('<!doctype html><meta charset="utf-8"><title>Loading voucher...</title><body style="font:14px Arial;padding:24px">Loading saved voucher...</body>');
    try{
      const response=await fetch('../api/accounts_ledger_browser.php?'+new URLSearchParams({entity:details.entity||entity(),account:'POSTS',postId:id,to:today()}),{credentials:'same-origin'});
      const result=await response.json();if(!response.ok||!result.ok)throw Error(result.error||'Saved posting unavailable.');
      const journal=result.post,lines=Array.isArray(journal.lines)?journal.lines:[];
      if(!lines.length)throw Error('This posting has no accounting lines to print.');
      const allocations=Array.isArray(details.allocations)?details.allocations:(Array.isArray(journal.meta?.allocations)?journal.meta.allocations:[]);
      const supplier=/supplier.payment|supplier.cheque/i.test(String(journal.sourceType||''))||/supplier payment/i.test(String(record?.type||''));
      const receipt=/receipt|customer.advance/i.test(String(journal.sourceType||'')+' '+String(record?.type||''));
      const transfer=/transfer/i.test(String(journal.sourceType||'')+' '+String(record?.type||''));
      const heading=supplier?'SUPPLIER PAYMENT VOUCHER':transfer?'TRANSFER VOUCHER':receipt?'RECEIPT VOUCHER':'PAYMENT VOUCHER';
      const pageSize=supplier&&(allocations.length>5||lines.length>7)?'A4':'A5';
      const companyName={TTI:'TRANSTRADE INTERNATIONAL',BRM:'BUKSH RICE MILLS',TG:'TRANS GRAINS FOODSTUFF TRADING L.L.C.'}[journal.entity]||journal.entity;
      const date=String(journal.date||'').split('-').reverse().join('-');
      const accountRows=lines.map(line=>`<tr><td>${esc(line.accountName||line.account)}${line.subledger||line.counterparty||line.party?`<small>${esc(line.subledger||line.counterparty||line.party)}</small>`:''}</td><td class="amount">${Number(line.debit||0)?money(line.debit):'—'}</td><td class="amount">${Number(line.credit||0)?money(line.credit):'—'}</td></tr>`).join('');
      const allocationRows=allocations.map(row=>`<tr><td>${esc(row.billNo||row.billId||'—')}<small>${esc([row.soda,row.truck,row.pohanch].filter(Boolean).join(' · '))}</small></td><td class="amount">${money(row.amount)}</td><td class="amount">${row.balanceAfter==null?'—':money(row.balanceAfter)}</td></tr>`).join('');
      const bankLines=lines.filter(line=>['1110','1120'].includes(String(line.account||''))||line.bankAccountId||line.cashAccountId);
      const bankAmount=bankLines.reduce((sum,line)=>sum+Number(receipt?line.debit:line.credit||0),0);
      const paid=Number(details.netPayment||details.foreignAmount||details.amount||journal.meta?.netPayment||bankAmount||journal.totalDebit||0);
      const currency=String(details.currency||journal.meta?.currency||bankLines.find(line=>line.currency)?.currency||'PKR');
      const party=details.broker||details.party||record?.party||journal.meta?.broker||journal.meta?.customer||journal.meta?.payee||'—';
      const bank=details.bankName||journal.meta?.bankName||lines.find(line=>line.bankName)?.bankName||'—';
      const ref=details.reference||details.bankReference||journal.reference||'—';
      const html=`<!doctype html><html><head><meta charset="utf-8"><title>${esc(heading)} ${esc(journal.id)}</title><style>@page{size:${pageSize};margin:12mm}body{font:10px Arial,sans-serif;color:#1b2a34;margin:0}header{border-top:5px solid #165848;padding-top:13px;display:flex;justify-content:space-between;align-items:center;gap:8px}header b{color:#165848;font-size:14px}header strong{font-size:11px;text-align:right}.meta{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;background:#edf5f1;margin:15px 0;padding:9px}.meta small,.pair small{display:block;color:#61727b;font-size:8px;text-transform:uppercase;margin-bottom:4px}.pairs{display:grid;grid-template-columns:1fr 1fr;gap:7px 15px;margin:12px 0}.pair{border-bottom:1px solid #cddbd7;padding-bottom:6px;overflow-wrap:anywhere}h3{color:#165848;font-size:10px;margin:16px 0 7px}table{width:100%;border-collapse:collapse;font-size:9px}th{background:#edf5f1;color:#165848;text-align:left}th,td{padding:6px;border-bottom:1px solid #cddbd7}td small{display:block;color:#61727b;margin-top:3px}.amount{text-align:right;white-space:nowrap}tfoot td{background:#edf5f1;font-weight:bold}.narration{margin-top:13px;border-top:1px solid #cddbd7;padding-top:8px;min-height:26px}.narration b{display:block;font-size:8px;color:#61727b;margin-bottom:5px}.sign{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-top:48px}.sign span{border-top:1px solid #1b2a34;padding-top:6px;font-size:8px}button{margin:0 0 8px auto;display:block}@media print{button{display:none}}${pageSize==='A5'?'body{font-size:9px}header b{font-size:12px}header strong{font-size:9px}.meta{margin:10px 0}.sign{margin-top:35px}th,td{padding:5px}':''}</style></head><body><button onclick="print()">Print voucher</button><header><b>${esc(companyName)}</b><strong>${esc(heading)}</strong></header><div class="meta"><div><small>Post ID</small><b>${esc(window.TT_ALL_LEDGERS?.displayRef?.(journal.id)||journal.id)}</b></div><div><small>Date</small>${esc(date)}</div><div><small>Currency</small>${esc(currency)}</div></div><div class="pairs"><div class="pair"><small>${receipt?'Received from':'Paid to / party'}</small>${esc(party)}</div><div class="pair"><small>Bank / cash account</small>${esc(bank)}</div><div class="pair"><small>${journal.meta?.bankPaymentMethod==='CHEQUE'?'Cheque number':'Reference'}</small>${esc(journal.meta?.chequeNo||journal.meta?.bankReference||ref)}${journal.meta?.chequeDate?' · '+esc(journal.meta.chequeDate):''}</div><div class="pair"><small>Amount ${receipt?'received':'paid'}</small><b>${esc(currency)} ${money(paid)}</b></div>${journal.meta?.bankPaymentMethod?`<div class="pair"><small>Bank payment method</small>${esc(journal.meta.bankPaymentMethod.replaceAll('_',' '))}</div><div class="pair"><small>Bill / invoice reference</small>${esc(window.TT_ALL_LEDGERS?.displayRef?.(journal.reference)||journal.reference)}</div>`:''}</div>${journal.meta?.directExpense?`<h3>EXPENSE DETAILS</h3><table><thead><tr><th>Category</th><th>Purpose</th><th class="amount">Amount</th></tr></thead><tbody>${(journal.meta.expenseLines||[]).map(row=>`<tr><td>${esc(row.category)}${row.medicalFor?' · '+esc(row.medicalFor.replaceAll('_',' ')):''}${row.rentFor?' · '+esc(row.rentFor):''}${row.donationType?' · '+esc(row.donationType.replaceAll('_',' ')):''}</td><td>${esc(row.purpose)}</td><td class="amount">${money(row.amount)}</td></tr>`).join('')}</tbody></table>`:''}${supplier&&allocationRows?`<h3>BILLS SETTLED BY THIS PAYMENT</h3><table><thead><tr><th>Bill / SODA / Truck / Pohanch</th><th class="amount">Paid now</th><th class="amount">Balance</th></tr></thead><tbody>${allocationRows}</tbody></table>`:''}<h3>ACCOUNTING ENTRY</h3><table><thead><tr><th>Account / details</th><th class="amount">Debit</th><th class="amount">Credit</th></tr></thead><tbody>${accountRows}</tbody><tfoot><tr><td>TOTAL</td><td class="amount">${money(journal.totalDebit)}</td><td class="amount">${money(journal.totalCredit)}</td></tr></tfoot></table><div class="narration"><b>NARRATION</b>${esc(journal.narration||'—')}${journal.fiTagText?'<br>'+esc(journal.fiTagText):''}</div><div class="sign"><span>Prepared By</span><span>Checked By</span><span>Receiver's Signature</span></div></body></html>`;
      popup.document.open();popup.document.write(html);popup.document.close();
    }catch(error){popup.document.body.textContent='Voucher could not be printed: '+error.message}
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
    body.innerHTML=`<form class="tt-form" id="ttFreightAgreementForm"><div class="tt-form-grid"><label>Customer<input id="ttFreightCustomer" list="ttFreightCustomers" required><datalist id="ttFreightCustomers"></datalist></label><label class="wide">Sales contract<select id="ttFreightShipment" required><option value="">Choose customer first</option></select></label><label>Forwarder<input name="forwarder" data-master-role="forwarder" placeholder="Optional for direct shipping line"></label><label>Shipping Line<input name="shippingLine" data-master-role="shipping" required></label><label>Agreed freight per container (USD)<input name="ratePerContainer" type="number" min="0.01" step="0.01" required></label><label>Number of containers<input name="containerCount" type="number" min="1" step="1" required></label><label>Discharge port<input name="destinationPort" required></label><label>Loading port<select name="fromPort" required><option value="">Choose loading port</option><option>Karachi Port, Pakistan</option><option>Port Qasim, Pakistan</option></select></label><label>Loading Programme number<input name="loadingProgrammeNo"></label><label>Date agreed<input name="dateAgreed" type="date" value="${today()}" required></label><label class="wide">Remarks<input name="remarks"></label></div><div id="ttFreightAgreementError" class="tt-note" role="alert"></div><div class="tt-form-actions"><button type="submit" class="btn primary">Save agreement</button></div></form>`;
    const form=q('form',body),customer=q('#ttFreightCustomer',form),select=q('#ttFreightShipment',form);let shipment=null,rows=[],sequence=0;
    const lotLabel=row=>row.contract;const requestKey=crypto.randomUUID();
    json('../api/accounts_shipment_lookup.php?scope=freight_agreed&entity='+encodeURIComponent(entity())).then(result=>{q('#ttFreightCustomers',form).innerHTML=(result.customers||[]).map(name=>`<option value="${esc(name)}"></option>`).join('');}).catch(error=>q('#ttFreightAgreementError',form).textContent=error.message);
    customer.oninput=()=>{shipment=null;rows=[];select.innerHTML='<option value="">Choose customer first</option>';sequence++;};
    customer.onchange=async()=>{const name=customer.value.trim(),seq=++sequence;shipment=null;select.innerHTML='<option value="">Loading contracts…</option>';try{const result=await json('../api/accounts_shipment_lookup.php?scope=freight_agreed&entity='+encodeURIComponent(entity())+'&customer='+encodeURIComponent(name)+'&q='+encodeURIComponent(name));if(seq!==sequence)return;rows=result.rows||[];select.innerHTML='<option value="">Choose sales contract</option>'+rows.map((row,i)=>`<option value="${i}">${esc(lotLabel(row))}</option>`).join('');if(!rows.length)select.innerHTML='<option value="">No current contracts for this customer</option>';}catch(error){if(seq===sequence)q('#ttFreightAgreementError',form).textContent=error.message;}};
    select.onchange=()=>{shipment=select.value===''?null:rows[Number(select.value)];if(!shipment)return;form.elements.destinationPort.value=shipment.portOfDischarge||'';const port=String(shipment.portOfLoading||'').toLowerCase();form.elements.fromPort.value=port.includes('qasim')?'Port Qasim, Pakistan':port.includes('karachi')?'Karachi Port, Pakistan':'';form.elements.loadingProgrammeNo.value=shipment.loadingProgramme||'';form.elements.containerCount.value=shipment.remainingContainers;form.elements.containerCount.max=shipment.remainingContainers;q('#ttFreightAgreementError',form).textContent='Contract containers: '+shipment.plannedContainers+' · Already agreed: '+shipment.agreedContainers+' · Remaining: '+shipment.remainingContainers;form.elements.shippingLine.value=shipment.shippingLine||'';form.elements.forwarder.value=shipment.forwarder||'';};
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
    const search=async()=>{const term=q('#ttTransportQuery',form).value.trim(),hits=q('#ttTransportHits',form),seq=++searchSequence;if(term.length<2){hits.textContent='Enter at least two characters.';return;}hits.textContent='Searching Exports…';try{const result=await json('../api/accounts_shipment_lookup.php?billKind=TRANSPORT&entity='+encodeURIComponent(company)+'&q='+encodeURIComponent(term));if(seq!==searchSequence)return;const candidates=(result.rows||[]).filter(row=>!entries.some(entry=>entry.shipment.id===row.id));hits.innerHTML=candidates.map((row,i)=>`<div class="tt-record-card"><b>${esc(row.customer)} · ${esc(row.contract)} · ${esc(row.lot)}</b><p>${esc(row.loadingProgramme)} · ${esc(row.portOfDischarge)} · ${row.containers.length} containers</p>${row.seller==='TG'&&!row.pakistanExporter?'<p>Select Pakistan exporter in Exports before posting.</p>':`<button type="button" class="btn" data-pick="${i}">Add this shipment</button>`}</div>`).join('')||'No linked shipment matched.';qa('[data-pick]',hits).forEach(button=>button.onclick=()=>{addShipment(candidates[Number(button.dataset.pick)]);hits.textContent='';q('#ttTransportSearch',form).hidden=true;const newest=entries[entries.length-1].card;newest.scrollIntoView({block:'start',behavior:'smooth'});q('[name=rate]',newest).focus({preventScroll:true});});}catch(error){if(seq===searchSequence)hits.textContent=error.message;}};
    q('#ttTransportFind',form).onclick=search;q('#ttTransportQuery',form).onkeydown=event=>{if(event.key==='Enter'){event.preventDefault();search();}};
    const showPosted=(bill,existing=false)=>{const amount=calculate(),invoice=form.elements.invoiceNo.value,vendor=bill.vendor||q('#ttShipmentBillVendor',form).value,postId=(bill.postingJournalIds||[]).slice(-1)[0]||'';body.innerHTML=`<div class="tt-record-card tt-bill-confirmation" role="status"><h3>${existing?'BILL ALREADY RECORDED':saved?'BILL AMENDED':'BILL POSTED'}</h3><p>POST ID <b>${esc(displayRef(postId)||'Available in the ledger')}</b> · Bill No. <b>${esc(invoice)}</b></p><p>${esc(vendor)} · PKR <b>${money(amount)}</b> · ${entries.length} shipment(s)</p><p>Supplier bill record ${esc(bill.id||'')}</p><button type="button" class="btn primary" id="ttShipmentBillPay">Pay this bill</button><button type="button" class="btn" id="ttShipmentBillAnother">Post another bill</button><button type="button" class="btn" id="ttShipmentBillPrint">PRINT VOUCHER</button><button type="button" class="btn" id="ttShipmentBillDone">Close</button></div>`;q('#ttShipmentBillPrint',body).onclick=()=>printVoucher({data:{entity:company,journalId:postId}});q('#ttShipmentBillDone',body).onclick=()=>q('.tt-window-close',host).click();q('#ttShipmentBillPay',body).onclick=()=>{q('.tt-window-close',host).click();window.TT_SUPPLIER_SETTLEMENT_UI?.openBills?.('TRANSPORT',vendor,bill.id);};q('#ttShipmentBillAnother',body).onclick=()=>{q('.tt-window-close',host).click();openShipmentKind('transport');};};
    form.onsubmit=async event=>{event.preventDefault();q('#ttShipmentBillError',form).textContent='';if(company!==entity())return showError('Company changed. Reopen this bill in the correct company.');if(!entries.length||calculate()<=0)return showError('Add a shipment and check the positive bill total.');const vendor=q('#ttShipmentBillVendor',form).value.trim(),invoice=form.elements.invoiceNo.value.trim();const payload={...(saved||{}),id:saved?.id||'',amendment:!!saved,newEntry:!saved,reason:form.elements.reason?.value||'',action:'save_transport_bill',entity:company,vendor,invoiceNo:invoice,billDate:form.elements.billDate.value,shipmentId:entries[0].shipment.id,remarks:form.elements.remarks.value.trim(),lines:entries.map(({shipment,card})=>({shipmentId:shipment.id,loadingProgrammeNo:shipment.loadingProgramme,containers:Number(q('[name=containers]',card).value),from:shipment.portOfLoading,to:shipment.portOfDischarge,rate:Number(q('[name=rate]',card).value),extras:0,remarks:form.elements.remarks.value.trim(),adjustments:adjustments(card)})),adjustments:saved?.adjustments||[],csrf:access.csrf};const button=q('[type=submit]',form);button.disabled=true;try{const saved=await json('../api/accounts_workflows_v1.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});showPosted(saved.bill);}catch(error){if(error.rejected){showError('Bill not posted. '+error.message);button.disabled=false;return;}try{const state=await json('../api/accounts_workflows_v1.php?entity='+encodeURIComponent(company)+'&section=transport'),existing=(state.bills||[]).find(x=>String(x.vendor||'').toLowerCase()===vendor.toLowerCase()&&String(x.invoiceNo||'').toLowerCase()===invoice.toLowerCase());if(existing)return showPosted(existing,true);showError('Bill not recorded. '+error.message);button.disabled=false;}catch(_){showError('Posting status could not be confirmed. Check this supplier invoice in the bill register before trying again. '+error.message);}}};
  }

  function openShipmentBillForm(kind,firstShipment,saved=null,sources=[]) {
    if(kind==='transport')return openTransportBillForm(firstShipment,saved,sources);
    const names={freight:'Freight Forwarder / Shipping',clearing:'Clearing Agent',fumigation:'Fumigation',inspection:'Inspection'},company=entity(),freight=kind==='freight',host=layer('ttShipmentBillForm',names[kind]+' Bill'),body=q('.tt-window-body',host),entries=[];
    body.innerHTML=`<form id="ttShipmentBillEntry" class="tt-form"><div class="tt-form-grid"><label>${names[kind]}<input id="ttShipmentBillVendor" data-master-role="${kind}" required></label><label>Bill number<input name="invoiceNo" required></label><label>BILL DATE<input name="billDate" type="date" value="${today()}" required></label>${freight?'<label>Shipping line invoice exchange rate<input name="exchangeRate" type="number" min="0.000001" step="any" placeholder="PKR per USD" required></label>':''}</div><div id="ttServiceShipments"></div><div class="tt-bill-total tt-bill-grand">${freight?'TOTAL INVOICE VALUE':'TOTAL BILL PAYABLE'} <b>PKR <span id="ttShipmentBillTotal">0.00</span></b></div><label>Bill narration<input name="remarks"></label><p id="ttShipmentBillError" role="alert" style="color:#a32929"></p><div class="tt-form-actions"><button class="btn primary" type="submit">POST BILL</button></div></form>`;
    const form=q('form',body),sections=q('#ttServiceShipments',form);;
    window.TT_ACCOUNTS_MASTER_CHOICES?.refresh?.();const freightState=freight?json('../api/accounts_workflows_v1.php?entity='+encodeURIComponent(company)+'&section=freight').catch(()=>null):Promise.resolve(null);
    const readCharge=row=>({description:q('[data-description]',row).value.trim(),type:q('[data-type]',row).value,amount:Number(q('[data-amount]',row).value),acceptedAmount:q('[data-accepted]',row)?.value===''?Number(q('[data-amount]',row).value):Number(q('[data-accepted]',row)?.value||q('[data-amount]',row).value),basis:q('[data-basis]',row)?.value||'FIXED',currency:q('[data-currency]',row)?.value||'PKR'});
    const charges=card=>qa('.tt-shipment-charge',card).map(readCharge);
    const roundPkr=value=>Math.sign(value)*Math.round((Math.abs(value)+Number.EPSILON)*100)/100;
    const total=()=>{let overall=0;for(const {card} of entries){const n=Number(q('[data-containers]',card)?.value||1),fx=Number(form.elements.exchangeRate?.value||0);let amount=0,accepted=0;for(const row of qa('.tt-shipment-charge',card)){const c=readCharge(row),mult=(c.basis==='PER_CONTAINER'?n:1)*(c.currency==='USD'?fx:1),sign=c.type==='DEDUCT'?-1:1;const billed=roundPkr(sign*c.amount*mult);amount+=billed;accepted+=roundPkr(sign*c.acceptedAmount*mult);if(q('[data-pkr]',row))q('[data-pkr]',row).value=money(billed);}q('[data-total]',card).textContent=money(amount);const note=q('[data-dispute-total]',card);if(note)note.textContent=Math.abs(amount-accepted)>.005?'Accepted payable PKR '+money(accepted)+' · Disputed PKR '+money(amount-accepted):'';overall+=amount;}q('#ttShipmentBillTotal',form).textContent=money(overall);return overall};
    const add=(shipment,original=null)=>{if(entries.some(e=>e.shipment.id===shipment.id))return;for(const e of entries){e.card.style.background='#eaf2ff';e.card.style.borderColor='#8fb6e8';}const card=document.createElement('section');card.className='tt-record-card';card.innerHTML=`<div style="display:flex;gap:12px;align-items:center"><h3 style="flex:1">${esc(shipment.customer)} · ${esc(shipment.contract)} · ${esc(shipment.lot)}</h3></div><p>${esc(shipment.loadingProgramme)} · ${esc(shipment.portOfDischarge)} · GD ${esc(shipment.gd||'—')}</p>${freight?`<div class="tt-form-grid"><label>Invoice containers<input data-containers type="number" min="1" step="1" value="${shipment.containers.length||''}" required></label><label>Actual B/L number<input data-bl value="${esc(shipment.bl)}" required></label></div>`:''}<div data-charges></div><div class="tt-form-actions"><button type="button" class="btn" data-add>+ Addition</button><button type="button" class="btn" data-deduct>− Deduction</button></div><div class="tt-bill-total">${freight?'SHIPMENT INVOICE VALUE':'SHIPMENT BILL PAYABLE'} <b>PKR <span data-total>0.00</span></b></div><p data-dispute-total></p>`;
      const addCharge=(type='ADD',description='',permanent=false)=>{const row=document.createElement('div');row.className='tt-bill-adjustment tt-shipment-charge'+(freight?' tt-bill-freight':'');
        row.innerHTML=freight?`<div>${permanent?'': '<button type="button" class="btn" data-remove aria-label="Remove charge">−</button>'}<input data-type type="hidden" value="${type}"><input data-currency type="hidden" value="USD"></div><label>${type==='DEDUCT'?'Deduction':'Charge name'}<input data-description required ${permanent?'readonly':''}></label><label>Rate (USD)<input data-amount type="number" min="${description==='B/L charges'?'0':'0.01'}" step="0.01" ${description==='B/L charges'?'value="0"':'required'}></label><label>Basis<select data-basis ${permanent?'disabled':''}><option value="PER_CONTAINER">Per container</option><option value="PER_BL">Per B/L</option></select></label><label>Amount (PKR)<input data-pkr readonly value="0.00"></label><details class="tt-freight-dispute"><summary>Disputed amount, if any</summary><label>Accepted rate (USD)<input data-accepted type="number" min="0" step="0.01" placeholder="Same as billed"></label></details>`:`<button type="button" class="btn" data-remove aria-label="Remove charge">−</button><label>Type<select data-type><option value="ADD">Addition</option><option value="DEDUCT">Deduction</option></select></label><label>Description<input data-description required></label><label>Amount<input data-amount type="number" min="0.01" step="0.01" required></label>`;
        q('[data-type]',row).value=type;q('[data-description]',row).value=description;if(description==='B/L charges')q('[data-basis]',row).value='PER_BL';if(q('[data-remove]',row))q('[data-remove]',row).onclick=()=>{row.remove();total()};q('[data-charges]',card).appendChild(row);return row;};
      q('[data-add]',card).onclick=()=>addCharge();q('[data-deduct]',card).onclick=()=>addCharge('DEDUCT');sections.appendChild(card);entries.push({shipment,card});if(original){if(freight){q('[data-containers]',card).value=original.containerCount;q('[data-bl]',card).value=original.actualBlNo||saved.actualBlNo||shipment.bl;}(original.billLines||[]).forEach(c=>{const billed=freight?Number(c.billedRate):Number(c.amount),row=addCharge(freight?(billed<0?'DEDUCT':'ADD'):c.type,c.charge||c.description||'',freight&&['Freight','B/L charges'].includes(c.charge));q('[data-amount]',row).value=Math.abs(billed);if(freight){q('[data-basis]',row).value=c.basis==='PER_CONTAINER'?'PER_CONTAINER':'PER_BL';q('[data-currency]',row).value=c.currency||'PKR';q('[data-accepted]',row).value=Number(c.acceptedRate)===Number(c.billedRate)?'':Math.abs(Number(c.acceptedRate));if(c.currency!=='USD'){q('[data-amount]',row).parentElement.firstChild.textContent='Rate (PKR)';q('[data-accepted]',row).parentElement.firstChild.textContent='Accepted rate (PKR)';}}});}else{addCharge('ADD',freight?'Freight':names[kind],freight);if(freight)addCharge('ADD','B/L charges',true);}if(freight)freightState.then(state=>{if(!card.isConnected)return;const agreement=(state?.agreements||[]).filter(r=>r.shipmentId===shipment.id||(!r.shipmentId&&r.contractRef===shipment.contract)).sort((a,b)=>String(b.updatedAt||'').localeCompare(String(a.updatedAt||'')))[0];if(agreement){const rate=q('[data-amount]',card);if(!saved&&!rate.value){rate.value=agreement.ratePerContainer;total();}const note=document.createElement('p');note.className='tt-treatment';note.textContent='Freight Agreed · USD '+money(agreement.ratePerContainer)+' per container · '+agreement.destinationPort+' · '+(agreement.forwarder||agreement.shippingLine);card.querySelector('[data-charges]').before(note);}});total();return card;
    };
    if(saved){form.elements.invoiceNo.value=saved.invoiceNo;form.elements.billDate.value=saved.billDate;form.elements.remarks.value=saved.remarks||'';q('#ttShipmentBillVendor',form).value=saved.vendor;if(freight)form.elements.exchangeRate.value=saved.exchangeRate;const original=saved.shipmentSections?.length?saved.shipmentSections:[{shipmentId:saved.shipmentId,containerCount:saved.containerCount,billLines:freight?saved.charges:saved.billLines}];original.forEach((section,index)=>{const source=sources.find(r=>r.id===section.shipmentId),bls=String(saved.actualBlNo||'').split(',').map(x=>x.trim()).filter(Boolean);add(source,{...section,actualBlNo:section.actualBlNo||(bls.length===original.length?bls[index]:source.bl||saved.actualBlNo)});});const label=document.createElement('label');label.textContent='Amendment reason';label.innerHTML+='<input name=reason required>';qa('.tt-form-actions',form).slice(-1)[0].before(label);q('[type=submit]',form).textContent='SAVE AMENDMENT';}else add(firstShipment);form.oninput=total;form.onchange=total;total();
    form.addEventListener('invalid',e=>q('#ttShipmentBillError',form).textContent=e.target.validationMessage,true);
    const showPosted=(bill)=>{const vendor=bill.vendor||q('#ttShipmentBillVendor',form).value,amount=total(),postId=(bill.postingJournalIds||[]).slice(-1)[0]||'';body.innerHTML=`<div class="tt-record-card tt-bill-confirmation" role="status"><h2>${saved?'BILL AMENDED':'BILL POSTED'}</h2><p>POST ID <b>${esc(displayRef(postId)||'Available in the ledger')}</b> · Bill No. <b>${esc(bill.invoiceNo||form.elements.invoiceNo.value)}</b></p><p>${esc(vendor)} · PKR <b>${money(amount)}</b></p><p>${entries.length} shipment(s) · Supplier bill record ${esc(bill.id||'')}</p><div class="tt-form-actions"><button class="btn primary" data-pay>Pay this bill</button><button class="btn" data-another>Post another bill</button><button type="button" class="btn" data-print>PRINT VOUCHER</button></div></div>`;q('[data-print]',body).onclick=()=>printVoucher({data:{entity:company,journalId:postId}});q('[data-pay]',body).onclick=()=>{q('.tt-window-close',host).click();window.TT_SUPPLIER_SETTLEMENT_UI?.openBills?.(kind.toUpperCase(),vendor,bill.id)};q('[data-another]',body).onclick=()=>{q('.tt-window-close',host).click();openShipmentKind(kind)}};
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
    body.innerHTML='<div class="tt-form"><p>Search by customer, contract, invoice, Customs invoice, container, B/L, loading programme, shipping line, vessel, port, brand, GD, FI or Bag PO.</p><div class="tt-searchbar"><input id="ttBillShipmentQuery" autofocus placeholder="Enter any shipment reference"><button id="ttBillShipmentGo">Search</button></div><div id="ttBillShipmentHits" style="margin-top:12px"></div></div>';
    const search=async()=>{const term=q('#ttBillShipmentQuery',host).value.trim(),hits=q('#ttBillShipmentHits',host);if(term.length<2){hits.textContent='Enter at least two characters.';return}hits.textContent='Searching Exports…';try{const result=await json('../api/accounts_shipment_lookup.php?billKind='+encodeURIComponent(kind.toUpperCase())+'&entity='+encodeURIComponent(entity())+'&q='+encodeURIComponent(term));hits.innerHTML=result.rows.length?result.rows.map((row,index)=>`<article class="tt-record-card"><b>${esc(row.customer)} · ${esc(row.contract)} · ${esc(row.lot)}</b><div class="tt-note">Commercial invoice ${esc(row.commercialInvoice||'—')} · Customs ${esc(row.customsInvoice||'—')} · B/L ${esc(row.bl||'—')} · ${esc(row.vessel||'')} ${esc(row.voyage||'')}</div><div class="tt-note">${esc(row.loadingProgramme||'')} · ${esc(row.shippingLine||'')} · ${esc(row.portOfLoading||'')} → ${esc(row.portOfDischarge||'')} · Containers ${esc(row.containers.join(', ')||'—')} · PO ${esc(row.po||'—')}</div>${row.seller==='TG'&&!row.pakistanExporter?'<div class="tt-note">TG reference is visible in both companies. Select the Pakistan exporter in Exports before posting a company supplier bill.</div>':`<button class="btn" data-tt-pick-shipment="${index}">Confirm this shipment</button>`}</article>`).join(''):'No linked shipment matches. Check the selected company and reference.';qa('[data-tt-pick-shipment]',hits).forEach(button=>button.onclick=async()=>{const row=result.rows[Number(button.dataset.ttPickShipment)];q('.tt-window-close',host).click();openShipmentBillForm(kind,row);});}catch(error){hits.textContent=error.message}};
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
  window.TT_ACCOUNTING_DESK = {installed:true, openSoda, openSearch, showArea, printVoucher, openSavedBill, refreshAttention:loadDashboardSummary};
})();

