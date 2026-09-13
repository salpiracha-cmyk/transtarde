(()=>{
  const LOGO='/exports/assets/TTI_header.png';
  const moduleNames={exports:'EXPORTS',milling:'MILLING',accounts:'ACCOUNTS'};

  function lockup(moduleName,small){
    const wrap=document.createElement('div');
    wrap.className='ttBrandLockup';
    const img=document.createElement('img');
    img.className='ttBrandLogo';
    img.src=LOGO;
    img.alt='TransTrade International';
    const label=document.createElement('span');
    label.className='ttBrandModule';
    label.textContent=moduleName;
    wrap.append(img,label);
    if(small)wrap.append(small);
    return wrap;
  }

  function applyBrand(){
    const id=String(window.TT_MODULE_ACCESS?.moduleId||(window.TT_ACCOUNT_ACCESS?'accounts':'')).toLowerCase();
    if(!moduleNames[id])return;
    if(id==='exports'){
      const top=document.querySelector('.topbar');
      const old=top?.querySelector(':scope > div:first-child');
      if(top&&old&&!top.querySelector('.ttBrandLockup'))old.replaceWith(lockup(moduleNames[id]));
      return;
    }
    if(id==='accounts'){
      const old=document.querySelector('.topbar .brand');
      if(old&&!old.querySelector('.ttBrandLockup'))old.replaceChildren(lockup(moduleNames[id]));
      return;
    }
    const old=document.querySelector('body > header .brand');
    if(old&&!old.querySelector('.ttBrandLockup')){
      const small=old.querySelector('small');
      if(small)small.remove();
      old.replaceChildren(lockup(moduleNames[id],small));
    }
  }

  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',applyBrand,{once:true});
  else applyBrand();
})();

