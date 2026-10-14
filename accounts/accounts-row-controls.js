(() => {
 'use strict';
 if(window.TT_ACCOUNTS_ROW_CONTROLS)return;
 const style=document.createElement('style');style.textContent='.tt-account-minus{box-sizing:border-box!important;background:#b42318!important;color:#fff!important;border:1px solid #b42318!important;border-radius:6px!important;width:36px!important;min-width:36px!important;height:36px!important;padding:0!important;font:700 24px/1 Arial!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;flex:none!important;vertical-align:middle!important}.tt-plan-bank .tt-account-minus{order:-1}.tt-account-plus-sign{display:inline-flex;width:28px;height:28px;align-items:center;justify-content:center;background:#16734b;color:#fff;border-radius:5px;font:700 23px Arial;margin-right:7px;vertical-align:middle}.tt-account-plus{display:inline-flex!important;align-items:center!important;justify-content:center!important;gap:4px!important}.tt-account-plus[data-icon-only]{width:36px!important;height:36px!important;padding:0!important}.tt-account-plus[data-icon-only] .tt-account-plus-sign{margin:0}';style.textContent+='@media screen{.tt-account-edit{background:#e5a126!important;color:#172433!important;border-color:#d89319!important}.tt-account-print{background:#147a5b!important;color:#fff!important;border-color:#147a5b!important}.tt-account-reverse{background:#b42318!important;color:#fff!important;border-color:#b42318!important}}';document.head.appendChild(style);
 function prepare(root){
  const buttons=[...(root.matches?.('button')?[root]:[]),...(root.querySelectorAll?.('button')||[])];
  for(const button of buttons){if(button.dataset.ttRowControl)continue;const text=button.textContent.trim(),label=button.getAttribute('aria-label')||button.title||text;
   if(/^Edit\b|^Amend\b/i.test(text))button.classList.add('tt-account-edit');if(/^Print\b/i.test(text))button.classList.add('tt-account-print');if(/^Reverse\b/i.test(text))button.classList.add('tt-account-reverse');
   if(/close|dismiss|logout/i.test(label)||button.closest('#tt-accounts-error'))continue;
   const remove=/^[×✕✖−–-]$/.test(text)||/^(Remove|Delete)\b/i.test(text)||[...button.attributes].some(a=>/^data-(?:remove|delete|ded-remove)(?:-|$)/.test(a.name));
   const add=/^[+＋]/.test(text)||button.hasAttribute('data-add-payer');
   if(remove){button.dataset.ttRowControl='minus';button.classList.add('tt-account-minus');button.setAttribute('aria-label',label||'Remove row');button.title=label||'Remove row';button.textContent='−';}
   else if(add){button.dataset.ttRowControl='plus';button.classList.add('tt-account-plus');button.setAttribute('aria-label',label||'Add row');const description=text.replace(/^[+＋]\s*/,'');button.textContent='';const sign=document.createElement('span');sign.className='tt-account-plus-sign';sign.setAttribute('aria-hidden','true');sign.textContent='+';button.appendChild(sign);if(description)button.appendChild(document.createTextNode(description));else button.dataset.iconOnly='1';}
  }
 }
 const start=()=>{prepare(document.body);new MutationObserver(records=>{for(const r of records)for(const node of r.addedNodes)if(node.nodeType===1)prepare(node);}).observe(document.body,{childList:true,subtree:true});};
 window.TT_ACCOUNTS_ROW_CONTROLS={refresh:()=>prepare(document.body)};if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
})();
