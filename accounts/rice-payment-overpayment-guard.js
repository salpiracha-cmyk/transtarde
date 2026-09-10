(()=>{
'use strict';
const q=s=>document.querySelector(s),qa=s=>[...document.querySelectorAll(s)];
const focus=()=>sessionStorage.getItem('tt_payables_focus')||'RICE';
const money=v=>Math.max(0,Number(String(v??'').replace(/,/g,''))||0);
const fmt=n=>Number(n||0).toLocaleString('en-PK',{maximumFractionDigits:2});
function billCap(billId){const card=q(`.ttbs-bill[data-bill-id="${CSS.escape(billId)}"]`);if(!card)return 0;const text=card.querySelector('summary .amt')?.textContent||'';const m=text.replace(/,/g,'').match(/Rs\s*([0-9.]+)/i);return m?money(m[1]):0}
function validateRiceBillAmounts(show=true){if(focus()!=='RICE')return true;for(const cb of qa('[data-ttbs-use]:checked')){const id=cb.dataset.ttbsUse||'',inp=q(`[data-ttbs-amount="${CSS.escape(id)}"]`),amt=money(inp?.value),cap=billCap(id);if(cap>0&&amt>cap+.005){if(show)alert(`Rice payment cannot exceed the selected bill outstanding.\n\nBill outstanding: Rs ${fmt(cap)}\nEntered: Rs ${fmt(amt)}\n\nReduce the payment amount before posting.`);inp?.focus();return false}}return true}
document.addEventListener('input',e=>{const inp=e.target.closest?.('[data-ttbs-amount]');if(!inp||focus()!=='RICE')return;inp.setCustomValidity('');const id=inp.dataset.ttbsAmount||'',cap=billCap(id),amt=money(inp.value);if(cap>0&&amt>cap+.005)inp.setCustomValidity('Rice payment cannot exceed this bill outstanding.');},true);
document.addEventListener('click',e=>{if(!e.target.closest?.('#ttstPostPay'))return;if(focus()!=='RICE')return;if(!validateRiceBillAmounts(true)){e.preventDefault();e.stopImmediatePropagation();}},true);
})();