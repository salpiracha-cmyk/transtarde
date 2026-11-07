(()=>{
  const route=e=>{
    const target=e.target.closest('[data-module-link],[data-open-module]');
    if(!target)return;
    const id=target.dataset.moduleLink||target.dataset.openModule||'';
    if(id==='accounts'){
      e.preventDefault();e.stopImmediatePropagation();window.open('accounts/','_blank','noopener');return;
    }
    if(id==='milling'||id==='exports'){
      e.preventDefault();e.stopImmediatePropagation();window.open('module-accounts.php?id='+encodeURIComponent(id),'_blank','noopener');
    }
  };
  document.addEventListener('click',route,true);
  function markConnected(){
    document.querySelectorAll('[data-open-module="accounts"]').forEach(btn=>{
      btn.textContent='Open module →';
      const card=btn.closest('.module-card');if(!card)return;
      const pill=card.querySelector('.state-pill');if(pill)pill.textContent='Foundation branch';
      const meta=card.querySelector('.module-meta span');if(meta)meta.textContent='Accounts V1 Foundation';
    });
  }
  new MutationObserver(markConnected).observe(document.documentElement,{subtree:true,childList:true});
  addEventListener('DOMContentLoaded',markConnected);
})();
