(() => {
  'use strict';
  if (window.TT_POST_CONFIRMATION) return;
  const originalFetch = window.fetch.bind(window);
  let banner = null;
  const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  function showJournal(journals) {
    const rows=(Array.isArray(journals)?journals:[journals]).filter(Boolean);if(!rows.length)return;
    banner?.remove();banner=document.createElement('section');banner.id='tt-post-confirmation';banner.setAttribute('role','dialog');banner.setAttribute('aria-modal','true');banner.setAttribute('aria-label','Posting saved');
    banner.style.cssText='position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:100180;width:min(440px,94vw);max-height:75vh;overflow:auto;box-sizing:border-box;padding:16px;background:#fff;color:#153e2b;border:1px solid #b8d3c6;border-radius:12px;box-shadow:0 14px 42px #132d2a40;font:13px Arial';
    const money=n=>Number(n||0).toLocaleString('en-PK',{minimumFractionDigits:2,maximumFractionDigits:2});
    banner.innerHTML='<b>POSTED</b>'+rows.map(j=>`<p><strong style="font-size:19px">${esc(window.TT_ALL_LEDGERS?.displayRef?.(j.publicPostId||j.meta?.publicPostId||j.id)||j.publicPostId||j.meta?.publicPostId||j.id)}</strong><br>${esc(window.TT_DATE?.display(j.date)||j.date)} · ${esc(j.entity)}<br>${esc(j.narration||'')}</p>${(j.meta?.amendmentChanges||[]).map(c=>`<p><b>${esc(c.party||c.account)}</b><br>Debit ${money(c.oldDebit)} → ${money(c.debit)}<br>Credit ${money(c.oldCredit)} → ${money(c.credit)}</p>`).join('')}<table style="width:100%;table-layout:fixed;border-collapse:collapse"><thead><tr><th style="text-align:left;width:58%">Account / party</th><th>Debit</th><th>Credit</th></tr></thead><tbody>${(j.lines||[]).map(l=>`<tr><td style="padding:5px 0;overflow-wrap:anywhere">${esc(window.TT_VOUCHER_ACCOUNT_LABEL?.(l,j)||l.accountName||l.account)}${l.counterparty||l.expensePurpose?'<br><small>'+esc(l.counterparty||l.expensePurpose)+'</small>':''}</td><td style="text-align:right">${l.debit?money(l.debit):'—'}</td><td style="text-align:right">${l.credit?money(l.credit):'—'}</td></tr>`).join('')}</tbody></table>`).join('')+'<div style="display:flex;justify-content:flex-end;gap:8px;margin-top:14px"><button type="button" class="btn primary" data-print>Print</button><button type="button" class="btn" data-close>Close</button></div>';
    const panel=banner;panel.querySelector('[data-close]').onclick=()=>{panel.remove();if(banner===panel)banner=null;};panel.querySelector('[data-print]').onclick=()=>window.TT_PRINT.html(rows.map(j=>window.TT_VOUCHER_HTML(j)).join('<div style="break-after:page"></div>'));document.body.appendChild(panel);
  }
  async function show(ids){try{const journals=await Promise.all(ids.map(async id=>{const r=await originalFetch('../api/accounts_ledger_browser.php?'+new URLSearchParams({entity:'ALL',account:'POSTS',postId:id}),{credentials:'same-origin'}),d=await r.json();if(!r.ok||!d.ok)throw Error(d.error||'Posting details unavailable.');return {...d.post,bankAccounts:d.bankAccounts||[]};}));showJournal(journals);}catch(error){/* The posting response remains authoritative; details can be reopened in the register. */}}
  window.fetch=async function(input,init){const result=await originalFetch(input,init);const url=typeof input==='string'?input:input?.url||'';
    if(result.ok&&/(?:^|\/)api\//.test(url)&&String(init?.method||input?.method||'GET').toUpperCase()==='POST'){
      try{const body=await result.clone().json();if(body?.ok&&!(url.includes('tg_bank_transactions.php')&&JSON.parse(String(init?.body||'{}')).guidedReceipt)){
        const candidates=[body.journal,body.advanceJournal,body.separateChargeJournal,body.result?.journal,body.result?.voucher,...(Array.isArray(body.journals)?body.journals:[]),...(Array.isArray(body.tgMirror)?body.tgMirror:[]).map(r=>r.journal)].filter(j=>Array.isArray(j?.lines));
        if(candidates.length)showJournal([...new Map(candidates.map(j=>[j.id,j])).values()]);else{const ids=[body.result?.journalId,body.result?.replacementPostId,body.journalId].filter(Boolean);if(ids.length)void show([...new Set(ids)]);}
      }}catch(_){} }
    return result;
  };
  function showBill(record, title='BILL POSTED') {
    if(!record?.id)return;
    document.getElementById('tt-bill-posted-dialog')?.remove();
    const panel=document.createElement('div');panel.id='tt-bill-posted-dialog';panel.setAttribute('role','status');
    panel.style.cssText='position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:100151;width:min(520px,90vw);padding:28px;background:#e8f6ed;color:#153e2b;border:2px solid #258454;border-radius:12px;box-shadow:0 14px 42px #132d2a40;text-align:center;font:700 17px Arial';
    const journal=record.journalId||record.postingJournalIds?.[0]||'';
    panel.innerHTML=`<h2 style="font-size:28px;margin-top:0">${esc(title)}</h2><p>POST NUMBER <b>${esc(window.TT_ALL_LEDGERS?.displayRef?.(record.id)||record.id)}</b></p><p>${esc(record.vendor||record.supplier||'')}</p>${journal?`<p>Journal ${esc(window.TT_ALL_LEDGERS?.displayRef?.(journal)||journal)}</p>`:''}<p>${esc(record.description||record.status||'')}</p><button type="button" data-view>VIEW RECORD</button> <button type="button" data-close>CLOSE</button>`;
    panel.querySelector('[data-close]').onclick=()=>panel.remove();panel.querySelector('[data-view]').onclick=()=>{panel.remove();window.TT_ACCOUNTING_DESK?.openSearch(record.id);};document.body.appendChild(panel);
  }
  window.TT_POST_CONFIRMATION = {show,showJournal,showBill};
})();

