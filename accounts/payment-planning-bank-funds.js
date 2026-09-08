(()=>{
  'use strict';
  const api='../api/bank_accounts.php';
  const currentEntity=()=>localStorage.getItem('tt_accounts_entity')||'TTI';
  let busy=false,lastEntity='';
  async function apply(){const input=document.querySelector('#ttppFunds');if(!input||busy)return;if(input.value.trim()!=='')return;const entity=currentEntity();busy=true;try{const r=await fetch(api+'?entity='+encodeURIComponent(entity),{credentials:'same-origin',headers:{Accept:'application/json'}});let d={};try{d=await r.json()}catch{}if(!r.ok||!d.ok)return;const funds=Math.max(0,Number(d.paymentPlanningFunds||0));input.placeholder=funds>0?'Auto bank/cash funds available: '+funds.toLocaleString('en-PK'):'No bank/cash account included in payment funds';if(funds>0){input.value=String(funds);input.dataset.autoBankFunds='1';lastEntity=entity;const help=input.closest('label')?.querySelector('.tt-auto-funds-note');if(!help){const n=document.createElement('div');n.className='tt-auto-funds-note';n.style.cssText='font-size:10px;color:#147a5b;margin-top:4px;font-weight:700';n.textContent='Auto-filled from bank/cash accounts marked “Include in Payment Planning”. You can overwrite this amount.';input.closest('label')?.appendChild(n)}document.querySelector('#ttppApply')?.click()}}finally{busy=false}}
  document.addEventListener('click',e=>{if(e.target.closest?.('.appCard[data-key="payables"]'))setTimeout(apply,180);if(e.target.closest?.('[data-tt-entity]'))lastEntity=''},false);
  const mo=new MutationObserver(()=>{if(document.querySelector('#ws-payables.workspace.active #ttppFunds'))setTimeout(apply,30)});mo.observe(document.documentElement,{childList:true,subtree:true});
  window.TT_PAYMENT_PLANNING_BANK_FUNDS={apply};
})();
