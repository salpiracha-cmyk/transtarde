(()=>{
  'use strict';
  if(window.__TT_CUSTOMER_CONTRACT_OPTIONS)return;window.__TT_CUSTOMER_CONTRACT_OPTIONS=true;
  const ROOT='transtrade_export_v3_operational';
  let cache=[];
  const nativeFetch=window.fetch.bind(window);
  const isCustomerApi=input=>String(typeof input==='string'?input:input?.url||'').includes('export_customers.php');
  const bool=v=>v===true||v===1||v==='1'||String(v).toLowerCase()==='yes'||String(v).toLowerCase()==='true';
  function localByName(name){try{const root=JSON.parse(localStorage.getItem(ROOT)||'null');return(root?.customers||[]).find(c=>String(c.name||'').toLowerCase()===String(name||'').toLowerCase())||null}catch{return null}}
  function remember(j){if(Array.isArray(j?.customers))cache=j.customers;if(j?.customer){const i=cache.findIndex(x=>x.masterId===j.customer.masterId);if(i>=0)cache[i]=j.customer;else cache.push(j.customer)}}
  window.fetch=async function(input,init){
    if(isCustomerApi(input)&&init?.body&&typeof init.body==='string'){
      try{
        const body=JSON.parse(init.body);
        if(body?.action==='upsert'&&body.customer){
          const editor=document.getElementById('ttCustomerMasterOverlay');
          if(editor){for(const k of ['Country','Email','Phone','Tax']){const el=editor.querySelector('#ttShow'+k);if(el)body.customer['show'+k]=!!el.checked}}
          else{const local=localByName(body.customer.name);if(local)for(const k of ['Country','Email','Phone','Tax'])if(local['show'+k]!==undefined)body.customer['show'+k]=!!local['show'+k]}
          init={...init,body:JSON.stringify(body)};
        }
      }catch{}
    }
    const response=await nativeFetch(input,init);
    if(isCustomerApi(input)){try{const clone=response.clone(),j=await clone.json();remember(j)}catch{}}
    return response;
  };
  function addToggle(input,key,label){
    if(!input||document.getElementById('ttShow'+key))return;
    const wrap=input.closest('.tt-cm-field,.field')||input.parentElement,row=document.createElement('label');row.className='tt-contract-show-toggle';row.style.cssText='display:flex;align-items:center;gap:7px;margin-top:6px;font-size:11px;font-weight:700;color:#47616b';row.innerHTML=`<input id="ttShow${key}" type="checkbox" style="width:auto"> Show ${label} on Sales Contract`;wrap.appendChild(row);
  }
  function hydrateEditor(){
    const overlay=document.getElementById('ttCustomerMasterOverlay');if(!overlay)return;const name=overlay.querySelector('#ttCName')?.value||'';if(!name)return;
    addToggle(overlay.querySelector('#ttCCountry'),'Country','Country');addToggle(overlay.querySelector('#ttCEmail'),'Email','Email');addToggle(overlay.querySelector('#ttCPhone'),'Phone','Phone');addToggle(overlay.querySelector('#ttCTax'),'Tax','VAT / Tax / Registration');
    const row=cache.find(c=>String(c.name||'').toLowerCase()===String(name).toLowerCase())||localByName(name)||{};for(const k of ['Country','Email','Phone','Tax']){const el=overlay.querySelector('#ttShow'+k);if(el&&!el.dataset.hydrated){el.checked=bool(row['show'+k]);el.dataset.hydrated='1'}}
  }
  function enforceApprovedReports(){document.querySelectorAll('[data-report]').forEach(el=>{if(!['fi','containers'].includes(String(el.dataset.report||'')))el.remove()})}
  function releaseDomTweaks(){hydrateEditor();enforceApprovedReports()}
  new MutationObserver(releaseDomTweaks).observe(document.documentElement,{childList:true,subtree:true});
  addEventListener('DOMContentLoaded',async()=>{try{const r=await nativeFetch('/api/export_customers.php',{credentials:'same-origin'});remember(await r.json())}catch{}releaseDomTweaks()});
})();
