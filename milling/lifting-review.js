/* Explicit accounting review only. No draft persistence or background work. */
(()=>{'use strict';
 if(window.TT_LIFTING_REVIEW)return;let active=false;
 const esc=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const money=value=>Number(value||0).toLocaleString('en-PK',{minimumFractionDigits:2,maximumFractionDigits:2});
 function confirm(journals){
  if(active)return Promise.resolve(false);
  if(!Array.isArray(journals)||!journals.length)throw Error('The accounting review is unavailable. No posting was made.');
  active=true;const prior=document.activeElement,overlay=document.createElement('div');overlay.dataset.liftingReview='';Object.assign(overlay.style,{position:'fixed',inset:'0',zIndex:'100180',backgroundColor:'#102a4680',display:'grid',placeItems:'center',padding:'16px'});
  overlay.innerHTML=`<section role="dialog" aria-modal="true" aria-label="Review lifting accounting" style="width:100%;max-width:760px;max-height:85vh;overflow:auto;padding:22px;background:#e8f6ed;color:#153e2b;border:1px solid #b8d3c6;border-radius:12px"><h2>Review accounting entries</h2>${journals.map(j=>`<p>${esc(j.entity)} · ${esc(j.date)}<br>${esc(j.narration)}</p><div style="overflow:auto"><table style="width:100%;min-width:420px;border-collapse:collapse"><thead><tr><th style="text-align:left">Account</th><th style="text-align:right">Debit</th><th style="text-align:right">Credit</th></tr></thead><tbody>${(j.lines||[]).map(l=>`<tr><td style="padding:8px 12px 8px 0">${esc(l.accountName||l.account)}</td><td style="text-align:right">${money(l.debit)}</td><td style="text-align:right">${money(l.credit)}</td></tr>`).join('')}</tbody></table></div>`).join('')}<div style="display:flex;gap:12px;margin-top:18px"><button type="button" data-cancel>Cancel</button><button type="button" data-confirm>Confirm posting</button></div></section>`;
  Object.assign(overlay.querySelector('section').style,{width:'100%',maxWidth:'760px',maxHeight:'85vh',overflow:'auto',padding:'22px',backgroundColor:'#e8f6ed',color:'#153e2b',border:'1px solid #b8d3c6',borderRadius:'12px'});
  document.body.appendChild(overlay);
  return new Promise(resolve=>{
   const close=result=>{active=false;document.removeEventListener('keydown',keys,true);overlay.remove();if(prior?.isConnected)prior.focus({preventScroll:true});resolve(result)};
   const buttons=[...overlay.querySelectorAll('button')];
   const keys=event=>{if(event.key==='Escape'){event.preventDefault();close(false)}else if(event.key==='Tab'){event.preventDefault();buttons[document.activeElement===buttons[0]?1:0].focus({preventScroll:true})}};
   overlay.querySelector('[data-cancel]').onclick=()=>close(false);overlay.querySelector('[data-confirm]').onclick=()=>close(true);document.addEventListener('keydown',keys,true);buttons[0].focus({preventScroll:true});
  });
 }
 window.TT_LIFTING_REVIEW={confirm};
})();
