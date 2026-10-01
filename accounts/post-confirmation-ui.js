(() => {
  'use strict';
  if (window.TT_POST_CONFIRMATION) return;
  const originalFetch = window.fetch.bind(window);
  let banner = null;
  const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  function show(ids) {
    if (!ids.length) return;
    banner?.remove();
    banner = document.createElement('div');
    banner.id = 'tt-post-confirmation';
    banner.style.cssText = 'position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:100150;min-width:min(460px,94vw);max-width:94vw;padding:18px 22px;background:#e8f6ed;color:#153e2b;border:2px solid #258454;border-radius:12px;box-shadow:0 14px 42px #132d2a40;font:700 15px Arial;text-align:center';
    banner.innerHTML = `<div style="font-size:12px">POSTED · ${ids.length > 1 ? ids.length + ' LINKED POST IDS' : 'POST ID'}</div>${ids.map(id => `<button type="button" data-post-id="${esc(id)}" style="display:block;margin:9px auto 0;background:transparent;border:0;text-decoration:underline;color:inherit;font:bold 23px Arial;cursor:pointer">${esc(window.TT_ALL_LEDGERS?.displayRef?.(id)||id)}</button>`).join('')}<div style="font-size:11px;font-weight:400;margin-top:8px">Select a Post ID to see its ledger entries.</div>`;
    document.body.appendChild(banner);
    banner.querySelectorAll('[data-post-id]').forEach(button => button.onclick = () => {
      const id = button.dataset.postId;
      window.TT_ALL_LEDGERS?.showPost?.(id);
      banner?.remove();banner = null;
    });
    // Keep the confirmation visible until the user intentionally opens another control.
    document.addEventListener('click', function onNext(event) {
      if (banner?.contains(event.target)) return;
      document.removeEventListener('click', onNext, true);
      banner?.remove();banner = null;
    }, true);
  }
  window.fetch = async function(input, init) {
    const result = await originalFetch(input, init);
    const url = typeof input === 'string' ? input : input?.url || '';
    if (result.ok && /(?:^|\/)api\//.test(url) && String(init?.method || input?.method || 'GET').toUpperCase() === 'POST') {
      try {
        const body = await result.clone().json();
        if (body?.ok && !(url.includes('tg_bank_transactions.php') && JSON.parse(String(init?.body||'{}')).guidedReceipt)) {
          const isMoneyMovement = journal => Array.isArray(journal?.lines) && journal.lines.some(line => ['1110','1120'].includes(String(line?.account||'')) || line?.bankAccountId || line?.cashAccountId);
          const journals = [body.journal, body.advanceJournal, body.separateChargeJournal, ...(Array.isArray(body.journals)?body.journals:[]), ...(Array.isArray(body.tgMirror)?body.tgMirror:[]).map(row=>row?.journal)].filter(isMoneyMovement);
          if (journals.length) show([...new Set(journals.map(row=>row.id).filter(Boolean))]);
        }
      } catch (_) { /* Preserve the original response and the posting result. */ }
    }
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
  window.TT_POST_CONFIRMATION = {show,showBill};
})();

