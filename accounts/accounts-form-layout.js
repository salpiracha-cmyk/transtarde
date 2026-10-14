(() => {
  'use strict';
  // Screen controls only: printed vouchers and document geometry retain their own styles.
  const style=document.createElement('style');style.id='ttAccountsFormLayout';
  style.textContent=`@media screen {
    :is(.workspace,.tt-window,.tt-layer,.tt-plan-window,.tte,.ttrs,.ttst-box,.am-box,.ttbr-box) :is(.tt-form-grid,.ttv-grid,.ttst-grid,.tte-grid,.ttrs-grid,.ttdon-form,.ttbk-grid,.ttbag-grid,.tt-plan-actions,.grid2,.grid3)>label{min-width:0;box-sizing:border-box;line-height:1.45}
    :is(.workspace,.tt-window,.tt-layer,.tt-plan-window,.tte,.ttrs,.ttst-box,.am-box,.ttbr-box) label :is(input:not([type=checkbox]):not([type=radio]),select,textarea){box-sizing:border-box;max-width:100%}
    :is(.tt-form-grid,.ttv-grid,.ttst-grid,.tte-grid,.ttrs-grid,.ttdon-form,.ttbk-grid,.ttbag-grid,.tt-plan-actions)>label :is(input:not([type=checkbox]):not([type=radio]),select){width:100%;min-height:42px;font-size:15px}
    .ttplan button[data-account-edit],.tal-amend{background:#e5a126!important;color:#172433!important;border-color:#d89319!important}
    .ttplan button[data-account-correct],.jvw button[data-jvw-reverse]{background:#b42318!important;color:#fff!important;border-color:#b42318!important}
    .ttplan button:is([data-account-voucher],[data-account-print],[data-voucher]){background:#147a5b!important;color:#fff!important;border-color:#147a5b!important}
    .tt-account-choices{display:flex;flex-wrap:wrap;gap:12px 22px;padding:14px;border:1px solid #d5e1e6;border-radius:10px;background:white;grid-column:1/-1;min-width:0}
    .tt-account-choices legend{font-size:14px;font-weight:700;padding:0 6px}
    .tt-account-choices label,.dex-mode{display:flex!important;align-items:center;gap:8px;cursor:pointer;font-size:15px}
    .tt-account-choices input,.dex-mode input{width:18px!important;height:18px!important;margin:0!important;accent-color:#147a5b}
    #ttExpenseDesk [data-expense-modes]{display:flex;gap:12px 24px;flex-wrap:wrap;padding:14px 0 18px;border-bottom:1px solid #d5e1e6;margin-bottom:18px}
    :is(.ttbag-grid,.am-grid,.ttv-grid,.ttst-grid,.tte-grid,.ttrs-grid,.ttdon-form,.grid2,.grid3){gap:16px!important}
    :is(.formCard,.tte-box,.tt-plan-card){padding:20px!important;border-color:#d5e1e6!important;border-radius:12px!important}
    :is(.am-head,.tte-head,.tt-window-head,.ttst-head,.panelHead,.tt-editor-bar){position:relative}
    .tt-popup-cross{font-size:25px!important;min-width:36px;background:transparent!important;border:0!important;color:#173747!important;align-self:flex-start;margin-left:auto}
    .tt-clean-close{margin-left:auto!important;order:99;width:38px;min-height:38px;font-size:26px!important}
    button.tt-action-print{background:#147a5b!important;color:white!important;border-color:#147a5b!important}
    button.tt-action-delete{background:#b42318!important;color:white!important;border-color:#b42318!important}
    button.tt-action-edit{background:#e5a126!important;color:#172433!important;border-color:#d89319!important}
    label.tt-account-tick{display:flex!important;flex-direction:row!important;align-items:center!important;justify-content:flex-start!important;gap:8px!important;min-height:32px;line-height:1.4!important}
    label.tt-account-tick>input:is([type=checkbox],[type=radio]){width:18px!important;min-width:18px!important;height:18px!important;min-height:18px!important;margin:0!important;padding:0!important;flex:none!important}
    :is(.tt-window-head,.ttst-head,.panelHead) button:is([data-close],.tt-clean-close){flex:none;white-space:nowrap;align-self:flex-start}
    :is(.tt-form-grid,.ttv-grid,.ttst-grid,.tte-grid,.ttrs-grid,.ttdon-form,.ttbag-grid){align-items:start}
    @media(max-width:600px){:is(.tt-form-grid,.ttv-grid,.ttst-grid,.tte-grid,.ttrs-grid,.ttdon-form,.ttbk-grid,.ttbag-grid,.tt-plan-actions){grid-template-columns:minmax(0,1fr)!important} :is(.tt-form-grid,.ttv-grid,.ttst-grid,.tte-grid,.ttrs-grid)>.wide{grid-column:1/-1!important}}
  }`;
  document.head.append(style);
  function prepare(root){for(const button of [...(root?.matches?.('button')?[root]:[]),...(root?.querySelectorAll?.('button')||[])]){const label=button.textContent.trim();if(/^(?:Print|PRINT)(?:\s|$)/.test(label))button.classList.add('tt-action-print');else if(/^(?:Delete|DELETE|Reverse|Remove)(?:\s|$)/.test(label)&&!button.matches('.tt-row-remove,[data-remove-debit]'))button.classList.add('tt-action-delete');else if(/^Edit(?:\s|$)/.test(label))button.classList.add('tt-action-edit');if(/^[×✕]?\s*close$/i.test(label)){button.setAttribute('aria-label',button.getAttribute('aria-label')||'Close and return to previous screen');button.textContent='×';button.classList.add('tt-popup-cross');}}for(const input of root?.querySelectorAll?.('label>input[type=checkbox],label>input[type=radio]')||[])input.parentElement.classList.add('tt-account-tick');}
  const start=()=>{prepare(document.body);new MutationObserver(records=>{for(const r of records)for(const n of r.addedNodes)if(n.nodeType===1){if(n.matches?.('label'))prepare(n.parentElement);else prepare(n);}}).observe(document.body,{childList:true,subtree:true});};
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
})();
