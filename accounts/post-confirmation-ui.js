(() => {
  'use strict';
  if (window.TT_POST_CONFIRMATION) return;
  const originalFetch = window.fetch.bind(window);
  let banner = null, generation = 0, posting = 0;
  const shown = new Set();
  const key = ids => posting+'|'+ids.map(id=>String(id).replace(/^POST-/,'')).sort().join('|');
  function dismiss(){generation++;banner?.remove();banner=null;document.getElementById('tt-bill-posted-dialog')?.remove();}
  function reserve(ids){const signature=key(ids);if(shown.has(signature))return false;shown.add(signature);if(shown.size>100)shown.delete(shown.values().next().value);return true;}
  const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  function showJournal(journals, options = {}) {
    const rows=(Array.isArray(journals)?journals:[journals]).filter(Boolean);if(!rows.length)return;
    const target=options.target;if(target&&!target.isConnected)return;
    if(!target&&!options.reserved&&!reserve(rows.map(j=>j.id||j.publicPostId)))return;
    if(!target)dismiss();
    const panel=document.createElement('section');
    if(!target){banner=panel;panel.id='tt-post-confirmation';panel.setAttribute('role','dialog');panel.setAttribute('aria-modal','true');}else{panel.setAttribute('role','status');}
    panel.setAttribute('aria-label','Posting saved');
    panel.style.cssText='position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:100180;width:min(760px,94vw);max-height:80vh;overflow:auto;box-sizing:border-box;padding:16px;background:#fff;color:#153e2b;border:1px solid #b8d3c6;border-radius:12px;box-shadow:0 14px 42px #132d2a40;font:13px Arial';
    const money=n=>Number(n||0).toLocaleString('en-PK',{minimumFractionDigits:2,maximumFractionDigits:2});
    if(target)panel.style.cssText='padding:14px;background:#fff;border:1px solid #b8d3c6;border-radius:10px;font:13px Arial;color:#153e2b';
    panel.innerHTML='<b>POSTED</b>'+rows.map(j=>`<p><strong style="font-size:19px">${esc(window.TT_ALL_LEDGERS?.displayRef?.(j.publicPostId||j.meta?.publicPostId||j.id)||j.publicPostId||j.meta?.publicPostId||j.id)}</strong><br>${esc(window.TT_DATE?.display(j.date)||j.date)} · ${esc(j.entity)}<br>${esc(j.narration||'')}</p>${(j.meta?.amendmentChanges||[]).map(c=>`<p><b>${esc(c.party||c.account)}</b><br>Debit ${money(c.oldDebit)} → ${money(c.debit)}<br>Credit ${money(c.oldCredit)} → ${money(c.credit)}</p>`).join('')}<div style="overflow-x:auto"><table style="width:100%;min-width:620px;table-layout:fixed;border-collapse:collapse"><colgroup><col style="width:50%"><col style="width:25%"><col style="width:25%"></colgroup><thead><tr><th style="text-align:left">Account / party</th><th>Debit</th><th>Credit</th></tr></thead><tbody>${(j.lines||[]).map(l=>`<tr><td style="padding:8px 12px 8px 0;white-space:normal!important;overflow-wrap:anywhere">${esc(window.TT_VOUCHER_ACCOUNT_LABEL?.(l,j)||l.accountName||l.account)}${l.counterparty||l.expensePurpose?'<br><small>'+esc(l.counterparty||l.expensePurpose)+'</small>':''}</td><td style="text-align:right;white-space:nowrap;padding:8px 6px;font-size:15px;font-variant-numeric:tabular-nums">${l.debit?money(l.debit):'—'}</td><td style="text-align:right;white-space:nowrap;padding:8px 6px;font-size:15px;font-variant-numeric:tabular-nums">${l.credit?money(l.credit):'—'}</td></tr>`).join('')}</tbody></table></div>`).join('')+'<div style="display:flex;justify-content:flex-end;gap:8px;margin-top:14px"><button type="button" class="btn primary" data-print>Print</button><button type="button" class="btn" data-close>Close</button></div>';
    panel.querySelector('[data-close]').hidden=!!target;panel.querySelector('[data-close]').onclick=()=>{if(banner===panel)dismiss();else panel.remove();};panel.querySelector('[data-print]').onclick=()=>window.TT_PRINT.html(rows.map(j=>window.TT_VOUCHER_HTML(j)).join('<div style="break-after:page"></div>'));if(target)target.replaceChildren(panel);else document.body.appendChild(panel);
  }
  async function show(ids,options={}){const target=options.target,company=localStorage.getItem('tt_accounts_entity');if(!target&&!reserve(ids))return;const token=target?{}:++generation;if(target)target._ttConfirmationRequest=token;try{const journals=await Promise.all(ids.map(async id=>{const r=await originalFetch('../api/accounts_ledger_browser.php?'+new URLSearchParams({entity:'ALL',account:'POSTS',postId:id}),{credentials:'same-origin'}),d=await r.json();if(!r.ok||!d.ok)throw Error(d.error||'Posting details unavailable.');return {...d.post,bankAccounts:d.bankAccounts||[]};}));if(company!==localStorage.getItem('tt_accounts_entity')||(target?(!target.isConnected||target._ttConfirmationRequest!==token):generation!==token))return;showJournal(journals,{...options,reserved:true});}catch(error){if(target?.isConnected&&target._ttConfirmationRequest===token)target.textContent='Payment is posted. Accounting details can be reopened from its Post ID in the ledger.';}}
  window.fetch=async function(input,init){const result=await originalFetch(input,init);const url=typeof input==='string'?input:input?.url||'';
    if(result.ok&&/(?:^|\/)api\//.test(url)&&String(init?.method||input?.method||'GET').toUpperCase()==='POST'){
      try{const body=await result.clone().json();if(body?.ok&&init?.ttConfirmation!=='workflow'&&!(url.includes('tg_bank_transactions.php')&&JSON.parse(String(init?.body||'{}')).guidedReceipt)){
        const candidates=[body.journal,body.advanceJournal,body.separateChargeJournal,body.result?.journal,body.result?.voucher,...(Array.isArray(body.journals)?body.journals:[]),...(Array.isArray(body.tgMirror)?body.tgMirror:[]).map(r=>r.journal)].filter(j=>Array.isArray(j?.lines));
        const ids=[body.result?.journalId,body.result?.replacementPostId,body.journalId].filter(Boolean);if(candidates.length||ids.length)posting++;
        if(candidates.length)showJournal([...new Map(candidates.map(j=>[j.id,j])).values()]);else{if(ids.length)void show([...new Set(ids)]);}
      }}catch(_){} }
    return result;
  };
  function showBill(record, title='BILL POSTED') {
    if(!record?.id)return;
    dismiss();
    const panel=document.createElement('div');panel.id='tt-bill-posted-dialog';banner=panel;panel.setAttribute('role','status');
    panel.style.cssText='position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:100151;width:min(520px,90vw);padding:28px;background:#e8f6ed;color:#153e2b;border:2px solid #258454;border-radius:12px;box-shadow:0 14px 42px #132d2a40;text-align:center;font:700 17px Arial';
    const journal=record.journalId||record.postingJournalIds?.[0]||'';
    panel.innerHTML=`<h2 style="font-size:28px;margin-top:0">${esc(title)}</h2><p>POST NUMBER <b>${esc(window.TT_ALL_LEDGERS?.displayRef?.(record.id)||record.id)}</b></p><p>${esc(record.vendor||record.supplier||'')}</p>${journal?`<p>Journal ${esc(window.TT_ALL_LEDGERS?.displayRef?.(journal)||journal)}</p>`:''}<p>${esc(record.description||record.status||'')}</p><button type="button" data-view>VIEW RECORD</button> <button type="button" data-close>CLOSE</button>`;
    panel.querySelector('[data-close]').onclick=dismiss;panel.querySelector('[data-view]').onclick=()=>{dismiss();window.TT_ACCOUNTING_DESK?.openSearch(record.id);};document.body.appendChild(panel);
  }
  document.addEventListener('click',event=>{if(event.target.closest?.('.entityBtn,[data-tt-entity],[data-tt-area],[data-tt-action],.appCard[data-key],[data-expense],[data-purchase]'))dismiss();},true);
  window.TT_POST_CONFIRMATION = {show,showJournal,showBill,dismiss};
})();

