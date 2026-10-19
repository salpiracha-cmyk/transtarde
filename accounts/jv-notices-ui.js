(() => {
  'use strict';
  if(window.TT_JV_NOTICES||window.TT_OPENING_ONLY)return;
  const access=window.TT_JV_NOTICE_ACCESS||window.TT_ACCOUNT_ACCESS||{},permissions=access.permissions;
  if(!access.super&&permissions!=='all'&&!(Array.isArray(permissions)?permissions:permissions?.jv||[]).includes('View'))return;
  const queue=[],seen=new Set();let active,panel,pollTimer,busy=false,stopped=false,backoff=10000,dismissing=false;
  function atHome(){
    const home=document.querySelector(access.homeSelector||'#entityHome');
    if(access.homeSelector&&!home)return false;
    if(home&&(home.hidden||getComputedStyle(home).display==='none'||home.classList.contains('hidden')||home.matches('.view:not(.active)')))return false;
    return ![...document.querySelectorAll('dialog[open],[role="dialog"],.am-mask,.tt-layer,.workspace.active')].some(e=>!e.hidden&&getComputedStyle(e).display!=='none');
  }
  function hide(){if(panel&&!panel.hidden){if(panel.hidePopover&&panel.matches(':popover-open'))panel.hidePopover();panel.hidden=true;}}
  function render(){
    if(stopped||!atHome()){hide();return;}
    if(!active)active=queue.shift();if(!active){hide();return;}
    if(!panel){panel=document.createElement('section');panel.id='tt-jv-decision';panel.hidden=true;panel.popover='manual';panel.setAttribute('role','status');panel.setAttribute('aria-live','polite');panel.style.cssText='position:fixed;left:50%;transform:translateX(-50%);z-index:2147483646;margin:0;right:auto;bottom:auto;width:min(740px,calc(100vw - 24px));box-sizing:border-box;padding:18px 50px 18px 20px;border:2px solid;border-radius:12px;box-shadow:0 8px 30px #0004;font:700 18px/1.5 Arial;max-height:45vh;overflow:auto';document.body.appendChild(panel);}
    if(panel.dataset.notice!==String(active.id||active.postId||active.jvId)){
      panel.replaceChildren();panel.dataset.notice=String(active.id||active.postId||active.jvId);panel.dataset.status=active.status;
      const rejected=active.status==='Rejected';panel.style.background=rejected?'#fff1f0':'#e6f5ec';panel.style.color=rejected?'#8d2520':'#12623f';panel.style.borderColor=rejected?'#b43830':'#198254';
      const title=document.createElement('strong');title.style.cssText='display:block;font-size:22px;font-weight:800';title.textContent=rejected?'JV REJECTED':'JV APPROVED & POSTED';panel.appendChild(title);
      const detail=document.createElement('div');detail.textContent=(active.entity?active.entity+' · ':'')+(rejected?active.jvId||'':active.publicPostId||active.postId||'');panel.appendChild(detail);
      if(rejected){const reason=document.createElement('strong');reason.dataset.rejectionReason='';reason.style.cssText='display:block;margin-top:10px;font-size:22px;line-height:1.5;font-weight:900;overflow-wrap:anywhere';reason.textContent=active.reason||'Open the JV history for the recorded decision.';panel.appendChild(reason);}
      const message=document.createElement('div');message.dataset.noticeError='';panel.appendChild(message);
      const close=document.createElement('button');close.type='button';close.textContent='×';close.setAttribute('aria-label','Dismiss JV decision');close.style.cssText='position:absolute;right:12px;top:8px;background:transparent;border:0;color:inherit;font-size:28px;cursor:pointer';close.onclick=dismiss;panel.appendChild(close);
    }
    panel.style.top=Math.max(12,(document.querySelector('.topbar,.top,.tt-staff-header')?.getBoundingClientRect().bottom||0)+12)+'px';
    if(panel.hidden)panel.hidden=false;if(panel.showPopover&&!panel.matches(':popover-open'))panel.showPopover();
  }
  function accept(item){if(!item||!['Posted','Rejected'].includes(item.status))return;const id=Number(item.id||0);if(id&&seen.has(id))return;if(id)seen.add(id);queue.push(item);render();}
  async function dismiss(){
    if(!active||dismissing)return;dismissing=true;const item=active,button=panel.querySelector('button');button.disabled=true;
    try{
      if(item.id){const response=await fetch('/api/journal_vouchers.php?entity='+encodeURIComponent(item.entity),{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify({csrf:access.csrf,entity:item.entity,action:'dismiss_jv_notice',noticeId:item.id})});const body=await response.json();if(!response.ok||!body.ok||!body.result?.dismissed)throw Error('Dismissal was not saved. Use X again when the connection returns.');}
      active=null;hide();render();
    }catch(e){panel.querySelector('[data-notice-error]').textContent=e.message||'Dismissal was not saved. Use X again.';}
    finally{button.disabled=false;dismissing=false;}
  }
  async function poll(){
    clearTimeout(pollTimer);if(stopped||busy)return;render();if(document.hidden||!atHome()){pollTimer=setTimeout(poll,backoff);return;}busy=true;
    try{
      const company=(access.entities||[])[0]||localStorage.getItem('tt_accounts_entity')||'TTI';
      const response=await fetch('/api/journal_vouchers.php?'+new URLSearchParams({entity:company,notices:'1',pending:'1'}),{credentials:'same-origin',cache:'no-store'});
      if([401,403].includes(response.status)){stopped=true;hide();return;}
      const body=await response.json();if(!response.ok||!body.ok)throw Error('Decision updates unavailable');
      for(const item of body.notices||[])accept(item);backoff=10000;
    }catch{backoff=Math.min(60000,backoff*2);}finally{busy=false;if(!stopped)pollTimer=setTimeout(poll,backoff);}
  }
  window.TT_JV_NOTICES={accept,check:poll,stop(){stopped=true;clearTimeout(pollTimer);hide();}};
  // Reads only notification metadata. Never refresh transactions or rebuild an unfinished form.
  const home=document.querySelector(access.homeSelector||'#entityHome');if(home)new MutationObserver(()=>{render();if(atHome())void poll();}).observe(home,{attributes:true,attributeFilter:['class','style','hidden']});
  document.addEventListener('click',()=>setTimeout(render,0));
  document.addEventListener('visibilitychange',()=>{if(!document.hidden)void poll();});
  void poll();
})();
