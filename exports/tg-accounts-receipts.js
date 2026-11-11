(() => {
  'use strict';
  // Exports uses this read-only projection for its commercial invoice receipt summary.
  // The Accounts ledger remains authoritative; no Exports record is written here.
  const key = 'tt40exportreceipts';
  localStorage.removeItem(key);
  let loading = false, lastRefresh = 0;
  async function refresh() {
    if (loading || document.hidden || Date.now()-lastRefresh < 60000) return;
    loading = true;
    lastRefresh = Date.now();
    try {
      const response = await fetch('../api/tg_export_accounts_receipts.php', {
        credentials: 'same-origin', headers: { Accept: 'application/json' }
      });
      if (!response.ok) return;
      const payload = await response.json();
      if (!payload.ok || !Array.isArray(payload.receipts)) return;
      localStorage.setItem(key, JSON.stringify(payload.receipts));
      window.dispatchEvent(new Event('tt:shared-updated'));
    } catch (error) {
      console.warn('TG Accounts receipt summary unavailable', error);
    } finally {
      loading = false;
    }
  }
  refresh();
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
  window.addEventListener('focus', refresh);
})();
