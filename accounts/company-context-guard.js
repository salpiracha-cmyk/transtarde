(() => {
  'use strict';
  const names={TTI:'Transtrade International',BRM:'Buksh Rice Mills',TG:'Trans Grains'};
  const shownCompany=()=>document.querySelector('.entityBtn.active')?.dataset.entity||'';
  const changed=()=>!!shownCompany()&&localStorage.getItem('tt_accounts_entity')!==shownCompany();
  let dialog;
  function check(){
    if(!changed()){if(dialog){dialog.close();dialog.remove();dialog=null;}return false;}
    if(!dialog){
      dialog=document.createElement('dialog');dialog.id='ttCompanyChanged';
      dialog.setAttribute('aria-labelledby','ttCompanyChangedTitle');
      dialog.style.cssText='box-sizing:border-box;width:min(500px,calc(100vw - 32px));max-height:calc(100vh - 32px);overflow:auto;border:2px solid #4f7650;border-radius:14px;padding:24px;color:#172b24;background:white';
      dialog.innerHTML='<h2 id="ttCompanyChangedTitle">Company changed in another tab</h2><p data-company-message></p><p>Actions are paused. Your unsaved entries remain here; reloading discards them.</p><button type="button">Reload Accounts</button>';
      dialog.querySelector('button').style.cssText='min-height:44px;padding:10px 16px;border:0;border-radius:8px;background:#315c47;color:white;font:inherit;font-weight:700;cursor:pointer';
      dialog.querySelector('button').onclick=()=>location.reload();
      dialog.addEventListener('cancel',event=>event.preventDefault());
      document.body.append(dialog);dialog.showModal();
    }
    const target=names[localStorage.getItem('tt_accounts_entity')]||'the current company';
    dialog.querySelector('[data-company-message]').textContent='This tab shows '+(names[shownCompany()]||'another company')+'. Accounts is now set to '+target+'.';
    return true;
  }
  // Storage changes come from other tabs. Do not rewrite or discard this tab's draft.
  window.addEventListener('storage',event=>{if(event.storageArea===localStorage&&(event.key==='tt_accounts_entity'||event.key===null))check();});
  window.addEventListener('focus',check);
  for(const type of ['click','submit'])document.addEventListener(type,event=>{
    if(dialog?.contains(event.target))return;
    if(check()){event.preventDefault();event.stopImmediatePropagation();}
  },true);
  // Also cover asynchronous handlers that submit after an external company change.
  const originalFetch=window.fetch;
  window.fetch=function(input,options){
    const url=new URL(typeof Request!=='undefined'&&input instanceof Request?input.url:String(input),location.href);
    if(url.origin===location.origin&&url.pathname.startsWith('/api/')&&url.pathname!=='/api/session_activity.php'&&check()){
      return Promise.reject(new Error('Company changed in another tab. Reload Accounts before continuing.'));
    }
    return originalFetch.call(this,input,options);
  };
  check();
})();
