(() => {
  'use strict';
  if(window.TT_ACCOUNTS_NOTICE)return;
  let panel, timer, persistent=false;
  function dismiss(){clearTimeout(timer);if(panel){if(panel.hidePopover&&panel.matches(':popover-open'))panel.hidePopover();panel.hidden=true;}persistent=false;}
  function position(){if(panel){const bottom=document.querySelector?.('.topbar')?.getBoundingClientRect?.().bottom||0;panel.style.top=Math.max(12,bottom+12)+'px';}}
  window.addEventListener?.('resize',position);
  function guidance(message){
    const text=String(message||'The action could not be completed.');if(/Next step:/i.test(text))return text;
    const rules=[[/Salary inputs changed|Salary Master changed|This month already has prepared/i,'Close the payment form and open Expenses → Salaries & Staff → Apply Salary Master. Review the current staff and saved allocations, then reopen the person.'],[/Salary balances changed|Salary data changed|stale.*salary/i,'Close the payment form, press Refresh in Salaries & Staff, then reopen the person. Saved payment drafts are retained.'],[/already.*(?:amended|reversed)|replacement Post ID/i,'Open Ledgers → Post IDs, search the public Post ID and use its current entry. The original and reversals remain in audit history.'],[/original workflow.*permission|Company.*permission.*delete|approval permission.*Journal Voucher/i,'Ask a Director or Super Admin to open the same company and original workflow. Director has full business access; staff access follows the assigned permissions.'],[/session expired|signed out|sign in again/i,'Sign in again, reopen this screen and refresh its saved record before retrying.'],[/Accounts storage|temporarily unavailable|network connection/i,'Keep your entry open, check the connection and retry once. If it continues, give Super Admin the screen name and Post ID; do not create a duplicate posting.']];
    const match=rules.find(([pattern])=>pattern.test(text));return match?text+'\n\nNext step: '+match[1]:text;
  }
  function error(message,options={}){
    message=guidance(message);
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
    const selectors='[role="alert"],[data-error],[id$="Error"],[id$="error"],.tt-plan-error,.tt-form-error,.am-error';
    const report=node=>{if(!node?.closest)return;const field=node.closest(selectors);if(field&&!field.closest('#tt-accounts-error')&&!field.closest('[hidden],[aria-hidden="true"]')&&field.getClientRects().length>0&&field.textContent.trim())error(field.textContent.trim(),{persistent:!!field.closest('form')});};
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
      try{const body=await response.clone().json();if(body?.cancelled)return response;if(body?.ok&&mutation&&!Object.hasOwn(body,'previews'))dismiss();if(!response.ok||body?.ok===false)error(body?.error||body?.message||`Accounts request failed (${response.status}).`,{persistent:mutation});}
      catch(_){if(!response.ok)error(`Accounts request failed (${response.status}). Please try again.`,{persistent:mutation});}
    }
    return response;
  };
})();
