(()=>{
'use strict';
const q=s=>document.querySelector(s),qa=s=>[...document.querySelectorAll(s)];
const focus=()=>sessionStorage.getItem('tt_payables_focus')||'RICE';
const money=v=>Math.max(0,Number(String(v||'').replace(/[^0-9.]/g,''))||0);
const fmt=n=>Number(n||0).toLocaleString('en-PK',{maximumFractionDigits:2});
function capFor(inp){const bill=inp.closest('.ttbs-bill');if(!bill)return 0;const txt=bill.querySelector('summary .amt')?.textContent||'';return money(txt)}
function validate(show=false){if(focus()!=='RICE')return true;let ok=true,bad=null;qa('[data-ttbs-use]:checked').forEach(cb=>{const id=cb.dataset.ttbsUse||'',inp=q(`[data-ttbs-amount="${CSS.escape(id)}"]`);if(!inp)return;const cap=capFor(inp),entered=money(inp.value);const invalid=entered>cap+0.005;inp.style.borderColor=invalid?'#a93a34':'';inp.style.background=invalid?'#fff3f2':'';if(invalid&&ok){ok=false;bad={inp,cap,entered}}});const btn=q('#ttstPostPay');if(btn)btn.disabled=!ok;if(!ok&&show&&bad){alert(`Rice payment cannot exceed this bill's outstanding amount of Rs ${fmt(bad.cap)}. Reduce the payment amount.`);bad.inp.focus()}return ok}
document.addEventListener('input',e=>{if(e.target.closest?.('[data-ttbs-amount]'))validate(false)},true);
document.addEventListener('change',e=>{if(e.target.closest?.('[data-ttbs-amount],[data-ttbs-use]'))validate(true)},true);
document.addEventListener('click',e=>{if(e.target.closest?.('[data-pay-commodity],#ttstOpen,[data-st-mode],#ttstBroker'))setTimeout(()=>validate(false),120)},false);
new MutationObserver(()=>setTimeout(()=>validate(false),20)).observe(document.documentElement,{childList:true,subtree:true});
})();