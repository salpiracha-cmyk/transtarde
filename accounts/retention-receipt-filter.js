(()=>{
  'use strict';
  const entity=()=>localStorage.getItem('tt_accounts_entity')||'TTI';
  async function apply(){
    const sel=document.querySelector('#erRetentionBank'),cur=document.querySelector('#erCurrency')?.value||'USD';
    if(!sel||!['TTI','BRM'].includes(entity()))return;
    try{
      const r=await fetch('../api/bank_accounts.php?entity='+encodeURIComponent(entity()),{credentials:'same-origin',headers:{Accept:'application/json'}});let d={};try{d=await r.json()}catch{}if(!r.ok||!d.ok)return;
      const rows=(d.accounts||[]).filter(a=>a.settings?.active&&a.settings?.allowReceipts&&a.settings?.retentionAccount&&!a.needsCompletion&&String(a.currency||'').toUpperCase()===String(cur).toUpperCase());
      const old=sel.value;
      sel.innerHTML=rows.length?rows.map(a=>`<option value="${String(a.id).replace(/"/g,'&quot;')}">${String(a.settings?.displayName||a.bankName||a.accountTitle||'Retention Bank').replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]))} · ${String(a.currency||cur)}</option>`).join(''):`<option value="">No enabled ${cur} Foreign Retention Account</option>`;
      if(rows.some(a=>a.id===old))sel.value=old;
    }catch{}
  }
  document.addEventListener('click',e=>{if(e.target.closest('[data-er-foreign]'))setTimeout(apply,80)},false);
  document.addEventListener('change',e=>{if(e.target?.id==='erCurrency')setTimeout(apply,30)},false);
  new MutationObserver(()=>{if(document.querySelector('#erRetentionBank'))apply()}).observe(document.documentElement,{childList:true,subtree:true});
})();
