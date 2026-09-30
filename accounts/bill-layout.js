(() => {
  'use strict';
  if (document.getElementById('ttAccountsBillLayout')) return;
  const style = document.createElement('style');
  style.id = 'ttAccountsBillLayout';
  style.textContent = `
    .tt-bill-adjustment{display:grid!important;grid-template-columns:36px .7fr 1.8fr .8fr!important;gap:10px;align-items:end;margin:10px 0}
    .tt-bill-adjustment.tt-bill-freight{grid-template-columns:36px minmax(0,1.8fr) minmax(0,1fr) minmax(0,1fr) minmax(0,1fr)!important}
    .tt-freight-dispute{grid-column:2/-1;font-size:13px}.tt-freight-dispute label{max-width:240px}.tt-bill-freight [data-pkr]{font-weight:800;text-align:right;font-variant-numeric:tabular-nums}
    .ttv-chargegrid{grid-template-columns:36px .65fr 1.4fr .7fr .4fr .5fr .7fr .7fr!important}.ttv-rowgrid{grid-template-columns:36px repeat(6,minmax(0,1fr))!important}
    .tt-bill-adjustment label{min-width:0}.tt-bill-adjustment input,.tt-bill-adjustment select{width:100%;box-sizing:border-box}
    .tt-bill-adjustment [data-amount],.tt-bill-amount,#ttShipmentBillEntry [name=rate],#opAmount,#tglAmount,#nwRate,#bgRate,#svAmt,.fcBill,.fcAcc,.trate,.textra{font-weight:800!important;text-align:right!important;font-variant-numeric:tabular-nums}
    .tt-bill-total{display:flex;justify-content:space-between;align-items:baseline;gap:18px;padding:12px 9px;border-bottom:1px solid #dbe2ea;font-weight:800}
    .tt-bill-total b{margin-left:auto;text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
    .tt-bill-grand{margin-top:12px;border-top:2px solid #243d52;border-bottom:3px double #243d52;font-size:16px}.tt-bill-grand b{font-size:24px}
    .tt-bill-confirmation{text-align:center;border:2px solid #258454!important;background:#e8f6ed!important;padding:24px!important}.tt-bill-confirmation h3{color:#153e2b}
    #ttShipmentBillError:not(:empty){border:2px solid #bb3434;color:#932222;background:#fff0ef;padding:14px;margin:12px 0;font-weight:700}
    #nwTotal{font-size:24px;font-weight:800;text-align:right}#nwTotalLabel{grid-column:1/-1;text-align:right}
    @media(max-width:650px){.ttv-chargegrid,.ttv-rowgrid{grid-template-columns:36px repeat(2,minmax(0,1fr))!important}.tt-bill-adjustment,.tt-bill-adjustment.tt-bill-freight{grid-template-columns:36px minmax(0,1fr) minmax(0,1fr)!important}.tt-bill-adjustment label:last-child{grid-column:2/-1}.tt-bill-grand b{font-size:20px}}
  `;
  document.head.appendChild(style);
})();
