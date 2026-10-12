(()=>{
  'use strict';
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const fmt=v=>Number(v||0).toLocaleString('en-PK',{maximumFractionDigits:2});
  const heads=['2110','2120','2130','2140','2190'];
  const entity=()=>localStorage.getItem('tt_accounts_entity')||'TTI';
  async function json(url,options){const r=await fetch(url,{credentials:'same-origin',...options});const d=await r.json();if(!r.ok||!d.ok)throw Error(d.error||'Opening balance could not be loaded.');return d;}
  async function open({company,party,onSaved}){
    document.querySelector('#ttOpeningReview')?.remove();
    const host=document.createElement('div');host.id='ttOpeningReview';host.className='tt-layer';host.style.zIndex='100010';
    host.innerHTML='<div class="tt-window"><div class="tt-window-head"><h2>Review Balance B/F</h2><span></span><button type="button" class="btn" data-close>× Close</button></div><div class="tt-window-body" role="status">Loading saved openings…</div></div>';
    document.body.appendChild(host);host.querySelector('[data-close]').onclick=()=>host.remove();const root=host.querySelector('.tt-window-body');
    const fail=e=>{window.TT_ACCOUNTS_NOTICE?.error(e.message);const box=root.querySelector('[data-error]');if(box)box.textContent=e.message;else root.textContent=e.message;};
    try{
      const d=await json('../api/journal_vouchers.php?entity='+encodeURIComponent(company));if(!host.isConnected||company!==entity())return host.remove();
      const o=d.opening;if(!o?.allowed)throw Error('Opening corrections require Super Admin or authorised Directors access.');
      const records=(o.entries||[]).filter(j=>j.status==='Posted'&&!j.reversalOf&&!j.openingReversalId&&!j.amendedByPostId&&!j.reversedByPostId&&!j.meta?.deletedFromBooks&&j.lines?.length===2&&j.lines.some(l=>l.account==='3400')&&j.lines.some(l=>heads.includes(l.account)));
      const line=j=>j.lines.find(l=>heads.includes(l.account));
      const targets=o.targets.filter(t=>t.kind==='party'&&heads.includes(t.account));
      const same=(a,b)=>String(a||'').trim().toLocaleLowerCase()===String(b||'').trim().toLocaleLowerCase();
      // Only an exact current name can preselect a record. Old names require explicit review.
      const matches=records.filter(j=>same(line(j).subledger||line(j).counterparty||j.meta?.openingParty,party));
      let selected=matches.length===1?matches[0].id:'';
      root.innerHTML=`<form class="tt-form"><p>Review the original opening and choose its correct party and treatment. The original remains in audit history. Your payment draft stays open.</p><label>Saved opening<select data-record required><option value="">Choose the original opening</option>${records.map(j=>{const l=line(j);return `<option value="${esc(j.id)}" ${selected===j.id?'selected':''}>${esc(l.subledger||l.counterparty||j.meta?.openingParty||'Unnamed party')} · ${esc(j.date)} · ${Number(l.credit)>0?'Credit':'Debit'} ${esc(o.currency)} ${fmt(Number(l.debit)+Number(l.credit))} · ${esc(j.meta?.publicPostId||j.id)}</option>`;}).join('')}</select></label><p data-original></p><div class="tt-form-grid"><label>Correct party<select data-target required></select></label><label>Treatment<select data-side><option value="Credit">Credit — Company owes party</option><option value="Debit">Debit — Advance to party</option></select></label><label>Opening amount · ${esc(o.currency)}<input data-opening-amount type="text" inputmode="decimal" required></label><label>Opening date<input type="date" value="${esc(o.date)}" readonly></label><label class="wide">Correction reason<input data-reason maxlength="300" required value="Correct opening treatment and party linkage"></label></div><p data-proposal></p><p data-error role="alert" style="color:#a32929"></p><div class="tt-form-actions"><button class="btn primary" type="submit">Post Opening Correction</button></div></form>`;
      const q=s=>root.querySelector(s);
      const proposal=()=>{const t=targets.find(t=>t.key===q('[data-target]').value),amount=Number(q('[data-opening-amount]').value.replace(/,/g,''));q('[data-proposal]').textContent=t?`${q('[data-side]').value} ${t.party}: ${o.currency} ${fmt(amount)}. Opening Balance Clearing receives the opposite entry.`:'';};
      const select=()=>{selected=q('[data-record]').value;const j=records.find(j=>j.id===selected);const l=j&&line(j);const choices=l?targets.filter(t=>t.account===l.account):[];const exact=choices.filter(t=>same(t.party,party));q('[data-target]').innerHTML='<option value="">Choose the correct party</option>'+choices.map(t=>`<option value="${esc(t.key)}" ${exact.length===1&&t.key===exact[0].key?'selected':''}>${esc(t.label)}</option>`).join('');q('[data-opening-amount]').value=l?fmt(Number(l.debit)+Number(l.credit)):'';q('[data-original]').textContent=l?`Original: ${l.subledger||l.counterparty||j.meta?.openingParty||'Unnamed party'} · ${Number(l.credit)>0?'Credit':'Debit'} ${o.currency} ${fmt(Number(l.debit)+Number(l.credit))}`:'';proposal();};
      q('[data-record]').onchange=select;for(const selector of ['[data-target]','[data-side]','[data-opening-amount]'])q(selector).addEventListener('input',proposal);select();
      q('form').onsubmit=async e=>{
        e.preventDefault();const button=q('[type=submit]');
        try{
          if(company!==entity())throw Error('Company changed. Reopen the opening review.');
          const j=records.find(j=>j.id===selected),target=targets.find(t=>t.key===q('[data-target]').value),amount=Number(q('[data-opening-amount]').value.replace(/,/g,''));
          if(!j||!target||!Number.isFinite(amount)||amount<=0||target.account!==line(j).account)throw Error('Choose a saved opening, its correct party and a positive amount.');
          const credit=q('[data-side]').value==='Credit';const lines=j.lines.map(l=>({account:l.account,subledger:l.account==='3400'?(l.subledger||''):target.party,debit:(l.account==='3400'?credit:!credit)?amount:0,credit:(l.account==='3400'?!credit:credit)?amount:0}));
          button.disabled=true;
          const saved=await json('../api/accounts_post_amend.php',{method:'POST',ttConfirmation:'workflow',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:window.TT_ACCOUNT_ACCESS?.csrf,postId:j.id,date:o.date,reason:q('[data-reason]').value.trim(),openingPartyTargetKey:target.key,lines})});
          host.remove();await onSaved?.(target.party);await window.TT_POST_CONFIRMATION?.show([saved.result.replacementPostId]);
        }catch(e){if(host.isConnected){button.disabled=false;fail(e);}else window.TT_ACCOUNTS_NOTICE?.error('Opening correction saved. Reopen Party Payments to load the updated balance.');}
      };
      window.TT_FORM_VIEWPORT?.open(host);
    }catch(e){if(host.isConnected)fail(e);}
  }
  window.TT_OPENING_BALANCE_REVIEW={open};
})();
