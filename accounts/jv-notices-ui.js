(() => {
  'use strict';
  if(window.TT_JV_NOTICES||window.TT_OPENING_ONLY)return;
  const access=window.TT_ACCOUNT_ACCESS||{},permissions=access.permissions;
  if(!access.super&&permissions!=='all'&&!(Array.isArray(permissions)?permissions:permissions?.jv||[]).includes('View'))return;
  const key='tt_jv_notice_cursor_'+String(access.userId||access.user||''),queue=[],seen=new Set();
  let cursor=null,version='',panel,timer,pollTimer,busy=false,stopped=false,backoff=2000;
  try{const saved=sessionStorage.getItem(key);if(saved!==null&&/^\d+$/.test(saved))cursor=Number(saved);}catch{}
  function next(){
    clearTimeout(timer);const item=queue.shift();if(!item){if(panel){if(panel.hidePopover&&panel.matches(':popover-open'))panel.hidePopover();panel.hidden=true;}return;}
    if(!panel){panel=document.createElement('section');panel.id='tt-jv-decision';panel.popover='manual';panel.setAttribute('role','status');panel.setAttribute('aria-live','polite');panel.style.cssText='position:fixed;left:50%;transform:translateX(-50%);z-index:2147483646;margin:0;right:auto;bottom:auto;width:min(740px,calc(100vw - 24px));box-sizing:border-box;padding:18px 50px 18px 20px;border:2px solid;border-radius:12px;box-shadow:0 8px 30px #0004;font:700 18px/1.5 Arial;max-height:45vh;overflow:auto';document.body.appendChild(panel);}
    panel.replaceChildren();panel.dataset.status=item.status;
    const rejected=item.status==='Rejected';panel.style.background=rejected?'#fff1f0':'#e6f5ec';panel.style.color=rejected?'#8d2520':'#12623f';panel.style.borderColor=rejected?'#b43830':'#198254';
    panel.style.top=Math.max(12,(document.querySelector('.topbar')?.getBoundingClientRect().bottom||0)+12)+'px';
    const title=document.createElement('strong');title.style.cssText='display:block;font-size:22px;font-weight:800';title.textContent=rejected?'JV REJECTED':'JV POSTED';panel.appendChild(title);
    const detail=document.createElement('div');detail.textContent=(item.entity?item.entity+' · ':'')+(rejected?item.jvId||'':item.publicPostId||item.postId||'');panel.appendChild(detail);
    if(rejected){const reason=document.createElement('strong');reason.dataset.rejectionReason='';reason.style.cssText='display:block;margin-top:10px;font-size:22px;line-height:1.5;font-weight:900;overflow-wrap:anywhere';reason.textContent=item.reason||'Open the JV history for the recorded decision.';panel.appendChild(reason);}
    const close=document.createElement('button');close.type='button';close.textContent='×';close.setAttribute('aria-label','Dismiss JV decision');close.style.cssText='position:absolute;right:12px;top:8px;background:transparent;border:0;color:inherit;font-size:28px;cursor:pointer';close.onclick=next;panel.appendChild(close);panel.hidden=false;if(panel.showPopover&&!panel.matches(':popover-open'))panel.showPopover();
    if(!rejected)timer=setTimeout(next,30000);
  }
  function accept(item){if(!item||!['Posted','Rejected'].includes(item.status))return;const id=Number(item.id||0);if(id&&seen.has(id))return;if(id)seen.add(id);queue.push(item);if(!panel||panel.hidden)next();}
  async function poll(){
    if(stopped||busy)return;if(document.hidden){pollTimer=setTimeout(poll,2000);return;}
    busy=true;
    try{
      const company=(access.entities||[])[0]||localStorage.getItem('tt_accounts_entity')||'TTI';
      const params=new URLSearchParams({entity:company,notices:'1',version});if(cursor!==null)params.set('after',String(cursor));
      const response=await fetch('../api/journal_vouchers.php?'+params,{credentials:'same-origin',cache:'no-store'});
      if([401,403].includes(response.status)){stopped=true;return;}
      const body=await response.json();if(!response.ok||!body.ok)throw Error('Decision updates unavailable');
      for(const item of body.notices||[])accept(item);
      cursor=Number(body.cursor||0);version=String(body.version||'');try{sessionStorage.setItem(key,String(cursor));}catch{}backoff=2000;
    }catch{backoff=Math.min(30000,backoff*2);}finally{busy=false;if(!stopped)pollTimer=setTimeout(poll,backoff);}
  }
  window.TT_JV_NOTICES={accept,check:poll,stop(){stopped=true;clearTimeout(timer);clearTimeout(pollTimer);}};
  document.addEventListener('visibilitychange',()=>{if(!document.hidden){clearTimeout(pollTimer);void poll();}});
  void poll();
})();
