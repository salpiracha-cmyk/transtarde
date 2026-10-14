(() => {
  'use strict';
  if(window.TT_ACCOUNTS_NOTICE)return;
  let panel, timer, persistent=false;
  function dismiss(){clearTimeout(timer);if(panel){if(panel.hidePopover&&panel.matches(':popover-open'))panel.hidePopover();panel.hidden=true;}persistent=false;}
  function position(){if(panel){const bottom=document.querySelector?.('.topbar')?.getBoundingClientRect?.().bottom||0;panel.style.top=Math.max(12,bottom+12)+'px';}}
  window.addEventListener?.('resize',position);
  function error(message,options={}){
    const same=panel&&!panel.hidden&&panel.querySelector('[data-error-text]')?.textContent===String(message);
    persistent=options.persistent===true||(same&&persistent);
    clearTimeout(timer);
    if(!panel){
      panel=document.createElement('section');panel.id='tt-accounts-error';panel.setAttribute('role','alert');panel.setAttribute('aria-live','assertive');panel.popover='manual';
      panel.style.cssText='position:fixed;top:12px;left:50%;bottom:auto;right:auto;margin:0;transform:translateX(-50%);z-index:2147483647;width:min(760px,calc(100vw - 24px));max-height:40vh;overflow:auto;box-sizing:border-box;padding:16px 48px 16px 18px;background:#fff1f0;color:#8d2520;border:2px solid #b43830;border-radius:10px;box-shadow:0 8px 30px #0004;font:700 16px/1.5 Arial';
      const text=document.createElement('div');text.dataset.errorText='';panel.appendChild(text);
      const close=document.createElement('button');close.type='button';close.textContent='×';close.setAttribute('aria-label','Dismiss message');close.style.cssText='position:absolute;top:8px;right:10px;font-size:26px;background:transparent;border:0;color:inherit;cursor:pointer';close.onclick=dismiss;panel.appendChild(close);document.body.appendChild(panel);
    }
    panel.querySelector('[data-error-text]').textContent=String(message||'The action could not be completed.');panel.hidden=false;position();if(panel.showPopover&&!panel.matches(':popover-open'))panel.showPopover();
    panel.dataset.persistent=String(persistent);if(!persistent)timer=setTimeout(dismiss,30000);
  }
  window.TT_ACCOUNTS_NOTICE={error,dismiss};
  document.addEventListener('click',event=>{if(!panel||panel.hidden||panel.contains(event.target))return;if(!persistent||event.target.closest?.('[data-close],[data-editor-back],[data-plan-close],.tt-clean-close'))dismiss();},true);
  // This file is loaded only by the Accounts bundle. Existing validation alerts use the same visible notice.
  window.alert=error;
  // Inline preflight validation must be visible even before a request reaches the server.
  const watch=()=>{
    const selectors='[role="alert"],[data-error],[id$="Error"],[id$="error"],.tt-plan-error,.tt-form-error';
    const report=node=>{if(!node?.closest)return;const field=node.closest(selectors);if(field&&!field.closest('#tt-accounts-error')&&!field.hidden&&field.textContent.trim())error(field.textContent.trim());};
    new MutationObserver(records=>{for(const r of records){report(r.target.nodeType===3?r.target.parentElement:r.target);for(const n of r.addedNodes){if(n.nodeType===1){report(n);n.querySelectorAll(selectors).forEach(report);}}}}).observe(document.body,{childList:true,characterData:true,subtree:true});
    document.addEventListener('invalid',event=>{const input=event.target;const label=input.closest('label')?.childNodes[0]?.textContent?.trim()||input.getAttribute('aria-label')||'Required field';error(label+': '+input.validationMessage,{persistent:true});},true);
  };
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',watch,{once:true});else watch();
  const originalFetch=window.fetch.bind(window);
  window.fetch=async function(input,init){
    const url=typeof input==='string'?input:input?.url||'';
    const api=/(?:^|\/)api\//.test(url);
    const mutation=String(init?.method||input?.method||'GET').toUpperCase()!=='GET';
    let response;
    try{response=await originalFetch(input,init);}catch(failure){if(api&&failure?.name!=='AbortError')error('Connection failed. Your action was not confirmed. Check the connection before retrying.',{persistent:mutation});throw failure;}
    if(api){
      try{const body=await response.clone().json();if(body?.cancelled)return response;if(!response.ok||body?.ok===false)error(body?.error||body?.message||`Accounts request failed (${response.status}).`,{persistent:mutation});}
      catch(_){if(!response.ok)error(`Accounts request failed (${response.status}). Please try again.`,{persistent:mutation});}
    }
    return response;
  };
})();
