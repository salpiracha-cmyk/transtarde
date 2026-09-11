(()=>{
'use strict';
const q=s=>document.querySelector(s);
const fmt=n=>Number(n||0).toLocaleString('en-PK',{maximumFractionDigits:2});
const today=()=>new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Karachi',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
const entity=()=>localStorage.getItem('tt_accounts_entity')||'TTI';
const commodity=()=>window.TT_PAYABLES_COMMODITY?.get?.()||sessionStorage.getItem('tt_payables_focus')||'RICE';
let timer=0;
function style(){if(q('#ttBrokerPaySummaryStyle'))return;const s=document.createElement('style');s.id='ttBrokerPaySummaryStyle';s.textContent=`.ttbps{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px;margin:12px 0}.ttbps>div{border:1px solid #e0e6ec;border-radius:10px;padding:10px 11px;background:#fafbfc}.ttbps span{display:block;font-size:10px;text-transform:uppercase;color:#6f7a89}.ttbps b{display:block;margin-top:4px;font-size:16px;color:#142033}@media(max-width:650px){.ttbps{grid-template-columns:1fr}}`;document.head.appendChild(s)}
async function loadSummary(){const broker=q('#ttstBroker')?.value||'';const body=q('.ttst-body');if(!body)return;let box=q('#ttBrokerPaySummary');if(!broker){box?.remove();return}style();try{const u=new URL('../api/payables_planning.php',location.href);u.searchParams.set('entity',entity());u.searchParams.set('asOf',today());u.searchParams.set('funds','0');u.searchParams.set('commodity',commodity());const r=await fetch(u.href,{credentials:'same-origin',headers:{Accept:'application/json'}});const d=await r.json();if(!r.ok||!d.ok)throw new Error(d.error||'Could not load broker totals.');const rows=(d.payables||[]).filter(x=>x.broker===broker&&!x.held&&Number(x.outstanding||0)>0);let due=0,notDue=0;for(const x of rows){const amt=Number(x.outstanding||0),date=String(x.dueDate||'');if(date&&date<=today())due+=amt;else notDue+=amt}const total=due+notDue;if(!box){box=document.createElement('div');box.id='ttBrokerPaySummary';const list=q('.ttst-list');(list||body.firstElementChild)?.insertAdjacentElement(list?'beforebegin':'afterend',box)}box.className='ttbps';box.innerHTML=`<div><span>Total Outstanding — ${broker}</span><b>Rs ${fmt(total)}</b></div><div><span>Due / Overdue</span><b>Rs ${fmt(due)}</b></div><div><span>Not Yet Due</span><b>Rs ${fmt(notDue)}</b></div>`}catch{box?.remove()}}
function schedule(){clearTimeout(timer);timer=setTimeout(loadSummary,80)}
document.addEventListener('change',e=>{if(e.target?.id==='ttstBroker')schedule()});
document.addEventListener('click',e=>{if(e.target.closest?.('#ttstOpen,[data-st-mode],[data-pay-commodity]'))setTimeout(schedule,120)});
new MutationObserver(()=>{if(q('#ttstBroker'))schedule();else q('#ttBrokerPaySummary')?.remove()}).observe(document.documentElement,{childList:true,subtree:true});
})();
