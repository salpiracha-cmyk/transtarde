(() => {
  'use strict';
  let revision=0, active=null;
  const company=()=>localStorage.getItem('tt_accounts_entity')||'TTI';
  function cancel(){revision++;if(active?.root)active.root.hidden=true;active=null;}
  function claim(view,root){
    const ws=document.getElementById('ws-bank');
    const token={view,root,workspace:ws,company:company(),revision:++revision};active=token;
    if(ws)for(const child of ws.children)if(!child.matches('.panelHead'))child.hidden=child!==root;
    if(root){root.hidden=false;root.classList.remove('hidden');root.innerHTML='<p role="status">Loading bank form…</p>';}
    return token;
  }
  const current=token=>active===token&&token.revision===revision&&token.company===company()&&!!token.workspace?.isConnected&&(!token.root||token.root.isConnected);
  document.addEventListener('click',event=>{if(event.target.closest?.('.entityBtn,[data-tt-entity],[data-tt-area],[data-tt-action],.tt-back-areas,[data-back],.tt-clean-close,.appCard[data-key]'))cancel();},true);
  window.TT_BANK_WORKSPACE={claim,current,cancel,revision:()=>revision,snapshot:()=>active};
})();
