(()=>{
  const openAccounts=e=>{
    const target=e.target.closest('[data-module-link="accounts"],[data-open-module="accounts"]');
    if(!target)return;
    e.preventDefault();e.stopImmediatePropagation();
    window.open('accounts/','_blank','noopener');
  };
  document.addEventListener('click',openAccounts,true);
  function markConnected(){
    document.querySelectorAll('[data-open-module="accounts"]').forEach(btn=>{
      btn.textContent='Open module →';
      const card=btn.closest('.module-card');
      if(!card)return;
      const pill=card.querySelector('.state-pill');if(pill)pill.textContent='Foundation branch';
      const meta=card.querySelector('.module-meta span');if(meta)meta.textContent='Accounts V1 Foundation';
    });
  }
  new MutationObserver(markConnected).observe(document.documentElement,{subtree:true,childList:true});
  addEventListener('DOMContentLoaded',markConnected);
})();