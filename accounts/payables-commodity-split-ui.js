(()=>{
'use strict';
const q=s=>document.querySelector(s),qa=s=>[...document.querySelectorAll(s)];
const key='tt_payables_focus';
const getFocus=()=>sessionStorage.getItem(key)||'RICE';
const setFocus=v=>sessionStorage.setItem(key,['RICE','CORN','OTHER'].includes(v)?v:'RICE');
const originalFetch=window.fetch.bind(window);
window.fetch=(input,init)=>{
  try{
    const method=String(init?.method||'GET').toUpperCase();
    const raw=typeof input==='string'?input:(input?.url||'');
    if(method==='GET'&&raw.includes('payables_planning.php')){
      const focus=getFocus();
      if(focus==='OTHER')return Promise.resolve(new Response(JSON.stringify({ok:true,entity:localStorage.getItem('tt_accounts_entity')||'TTI',commodity:'OTHER',asOf:new Date().toISOString().slice(0,10),funds:0,ladder:{rows:[],totalEligibleOutstanding:0},ageing:{},brokers:[],payables:[],unpostedReceipts:[]}),{status:200,headers:{'Content-Type':'application/json'}}));
      const u=new URL(raw,location.href);
      if(!u.searchParams.get('commodity'))u.searchParams.set('commodity',focus);
      input=typeof input==='string'?u.href:new Request(u.href,input);
    }
  }catch{}
  return originalFetch(input,init);
};
function style(){if(q('#ttPayCommodityStyle'))return;const s=document.createElement('style');s.id='ttPayCommodityStyle';s.textContent=`.ttpc{display:flex;gap:10px;align-items:center;padding:12px 18px 0;flex-wrap:wrap}.ttpc button{border:1px solid #d6dfe7;background:#fff;border-radius:12px;padding:10px 14px;cursor:pointer;font-weight:800;color:#30465d}.ttpc button.sel{background:#102a46;color:#fff;border-color:#102a46}.ttpc small{display:block;font-weight:400;opacity:.75;margin-top:2px}.ttpc-label{margin-left:auto;font-size:11px;color:#6f7a89}@media(max-width:600px){.ttpc-label{width:100%;margin-left:0}}`;document.head.appendChild(s)}
function title(){const f=getFocus();return f==='CORN'?'Corn / Maize Payments':f==='OTHER'?'Other Supplier / Expense Payables':'Rice Payments'}
function inject(){const ws=q('#ws-payables');if(!ws)return;style();let bar=q('#ttPayCommodityBar');if(!bar){bar=document.createElement('div');bar.id='ttPayCommodityBar';bar.className='ttpc';ws.querySelector('.panelHead')?.insertAdjacentElement('afterend',bar)}const f=getFocus();bar.innerHTML=`<button type="button" data-pay-commodity="RICE" class="${f==='RICE'?'sel':''}">Rice Payments<small>Rice Sodas and finalized bills</small></button><button type="button" data-pay-commodity="CORN" class="${f==='CORN'?'sel':''}">Corn / Maize Payments<small>Corn Sodas and finalized bills</small></button><button type="button" data-pay-commodity="OTHER" class="${f==='OTHER'?'sel':''}">Other Supplier / Expense Payables<small>Bags and other non-commodity supplier bills</small></button><span class="ttpc-label">${title()}</span>`;qa('[data-pay-commodity]').forEach(b=>b.onclick=()=>switchTo(b.dataset.payCommodity||'RICE'))}
function refreshVisible(){const ws=q('#ws-payables');if(!ws?.classList.contains('active'))return;const app=q('.appCard[data-key="payables"]');if(app)app.click()}
function switchTo(v){setFocus(v);try{window.TT_RICE_PAYABLE_LINK?.clear?.()}catch{}q('[data-tt-settlement-box]')?.classList.toggle('hidden',v==='OTHER');inject();if(v==='OTHER'){try{window.TT_BAG_SUPPLIER_PAYMENTS?.reload?.()}catch{}}else refreshVisible()}
const obs=new MutationObserver(()=>{if(q('#ws-payables'))inject()});obs.observe(document.documentElement,{childList:true,subtree:true});
document.addEventListener('click',e=>{if(e.target.closest('.appCard[data-key="payables"]')){if(!sessionStorage.getItem(key))setFocus('RICE');setTimeout(()=>{inject();if(getFocus()==='OTHER'){q('[data-tt-settlement-box]')?.classList.add('hidden');try{window.TT_BAG_SUPPLIER_PAYMENTS?.reload?.()}catch{}}},30)}if(e.target.closest('[data-back]'))q('#ttPayCommodityBar')?.remove()});
setFocus(getFocus());setTimeout(inject,80);
window.TT_PAYABLES_COMMODITY={get:getFocus,set:switchTo};
})();
