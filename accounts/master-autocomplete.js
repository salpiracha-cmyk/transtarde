(() => {
  'use strict';
  const access=window.TT_ACCOUNT_ACCESS||{}, masters=access.masters||{};
  const clean=v=>String(v??'').trim();
  const unique=rows=>[...new Set(rows.map(clean).filter(Boolean))].sort((a,b)=>a.localeCompare(b));
  const values=(type,index=0)=>unique((masters[type]||[]).map(row=>row?.values?.[index]));
  const lists={
    parties:unique([...values('business_parties'),...values('export_customers')]),
    suppliers:unique((masters.business_parties||[]).filter(row=>/supplier|broker|service provider|transporter|freight|clearing|fumigation|inspection/i.test(clean(row?.values?.[2]))).map(row=>row?.values?.[0])),
    customers:values('export_customers'),
    locations:values('mills'),
    products:unique((masters.purchase_products||[]).map(row=>row?.values?.[3]||row?.values?.[1])),
    commodities:values('commodities'),
  };
  function ensureList(name,items){
    let list=document.getElementById(`tt-master-${name}`);
    if(!list){list=document.createElement('datalist');list.id=`tt-master-${name}`;document.body.appendChild(list)}
    list.innerHTML=items.map(value=>{const option=document.createElement('option');option.value=value;return option.outerHTML}).join('');
  }
  Object.entries(lists).forEach(([name,items])=>ensureList(name,items));
  function category(input){
    if(!input||input.matches('[readonly],[disabled],[type="date"],[type="number"],[type="file"]')||input.list)return'';
    const text=clean(input.closest('label')?.textContent+' '+input.placeholder+' '+input.id).toLowerCase();
    if(/customer|buyer|consignee/.test(text))return'customers';
    if(/supplier|broker|vendor|payee|service provider|transporter|shipping line|clearing|fumigation|inspection/.test(text))return'suppliers';
    if(/mill|location|warehouse|from where|to where/.test(text))return'locations';
    if(/commodity/.test(text))return'commodities';
    if(/product|variety|rice type/.test(text))return'products';
    if(/party|from whom|received from|account name/.test(text))return'parties';
    return'';
  }
  function apply(root=document){root.querySelectorAll?.('input').forEach(input=>{const name=category(input);if(name&&lists[name]?.length){input.setAttribute('list',`tt-master-${name}`);input.setAttribute('autocomplete','off')}})}
  let queued=false;
  const observer=new MutationObserver(()=>{if(queued)return;queued=true;requestAnimationFrame(()=>{queued=false;apply()})});
  const start=()=>{apply();observer.observe(document.body,{childList:true,subtree:true})};
  document.readyState==='loading'?document.addEventListener('DOMContentLoaded',start,{once:true}):start();
})();
