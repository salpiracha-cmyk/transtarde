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
    banner.style.cssText = 'position:fixed;top:72px;left:50%;transform:translateX(-50%);z-index:100150;min-width:min(460px,94vw);max-width:94vw;padding:18px 22px;background:#e8f6ed;color:#153e2b;border:2px solid #258454;border-radius:12px;box-shadow:0 14px 42px #132d2a40;font:700 15px Arial;text-align:center';
    banner.innerHTML = `<div style="font-size:12px">POSTED · ${ids.length > 1 ? ids.length + ' LINKED POST IDS' : 'POST ID'}</div>${ids.map(id => `<button type="button" data-post-id="${esc(id)}" style="display:block;margin:9px auto 0;background:transparent;border:0;text-decoration:underline;color:inherit;font:bold 23px Arial;cursor:pointer">${esc(id)}</button>`).join('')}<div style="font-size:11px;font-weight:400;margin-top:8px">Select a Post ID to see its ledger entries.</div>`;
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
        if (body?.ok) {
          const ids = [body.journal?.id, body.advanceJournal?.id, body.postId, body.journalId, body.event?.journalId, body.receipt?.journalId, body.transaction?.journalId, body.transfer?.journalId, body.bill?.journalId, body.payment?.journalId, ...(Array.isArray(body.journals)?body.journals:[]).map(row => row?.id), ...(Array.isArray(body.journalIds)?body.journalIds:[]), ...(Array.isArray(body.bill?.postingJournalIds)?body.bill.postingJournalIds:[]), ...(Array.isArray(body.tgMirror)?body.tgMirror:[]).map(row => row?.journal?.id), ...(Array.isArray(body.indentorPayableJournals)?body.indentorPayableJournals:[]).map(row => row?.id), body.separateChargeJournal?.id].filter(Boolean);
          if (ids.length) show([...new Set(ids)]);
        }
      } catch (_) { /* Preserve the original response and the posting result. */ }
    }
    return result;
  };
  window.TT_POST_CONFIRMATION = {show};
})();
