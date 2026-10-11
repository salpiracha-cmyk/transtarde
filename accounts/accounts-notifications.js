(() => {
  'use strict';
  if(window.TT_ACCOUNTS_NOTICE)return;
  let panel;
  function error(message){
    if(!panel){
      panel=document.createElement('section');panel.id='tt-accounts-error';panel.setAttribute('role','alert');panel.setAttribute('aria-live','assertive');
      panel.style.cssText='position:fixed;top:12px;left:50%;transform:translateX(-50%);z-index:2147483647;width:min(760px,calc(100vw - 24px));max-height:40vh;overflow:auto;box-sizing:border-box;padding:16px 48px 16px 18px;background:#fff1f0;color:#8d2520;border:2px solid #b43830;border-radius:10px;box-shadow:0 8px 30px #0004;font:700 16px/1.5 Arial';
      const text=document.createElement('div');text.dataset.errorText='';panel.appendChild(text);
      const close=document.createElement('button');close.type='button';close.textContent='×';close.setAttribute('aria-label','Dismiss message');close.style.cssText='position:absolute;top:8px;right:10px;font-size:26px;background:transparent;border:0;color:inherit;cursor:pointer';close.onclick=()=>panel.hidden=true;panel.appendChild(close);document.body.appendChild(panel);
    }
    panel.querySelector('[data-error-text]').textContent=String(message||'The action could not be completed.');panel.hidden=false;
  }
  window.TT_ACCOUNTS_NOTICE={error};
  // This file is loaded only by the Accounts bundle. Existing validation alerts use the same visible notice.
  window.alert=error;
  const originalFetch=window.fetch.bind(window);
  window.fetch=async function(input,init){
    const url=typeof input==='string'?input:input?.url||'';
    const api=/(?:^|\/)api\//.test(url);
    let response;
    try{response=await originalFetch(input,init);}catch(failure){if(api&&failure?.name!=='AbortError')error('Connection failed. Your action was not confirmed. Check the connection before retrying.');throw failure;}
    if(api){
      try{const body=await response.clone().json();if(!response.ok||body?.ok===false)error(body?.error||body?.message||`Accounts request failed (${response.status}).`);}
      catch(_){if(!response.ok)error(`Accounts request failed (${response.status}). Please try again.`);}
    }
    return response;
  };
})();
