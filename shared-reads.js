(() => {
  'use strict';
  if (window.TT_SHARED_READS) return;
  const pending = new Map(), nativeFetch = window.fetch.bind(window);
  const routes = new Set(['accounts_dashboard.php','journal_vouchers.php','accounts_ledger_browser.php','business_reports.php','export_receipts.php','tg_export_accounts_receipts.php','masters.php','mill_versions.php']);
  const invalidate = () => pending.clear();
  window.TT_SHARED_READS = {invalidate};
  window.fetch = (input, options = {}) => {
    const isRequest = typeof Request !== 'undefined' && input instanceof Request;
    if (!isRequest && typeof input !== 'string' && !(input instanceof URL)) return nativeFetch(input, options);
    const url = new URL(isRequest ? input.url : input, location.href), method = String(options.method || (isRequest ? input.method : 'GET')).toUpperCase();
    if (url.origin !== location.origin) return nativeFetch(input, options);
    if (!['GET','HEAD'].includes(method)) {
      invalidate();
      return nativeFetch(input, options).finally(invalidate);
    }
    if (isRequest || method !== 'GET' || options.signal || !url.pathname.startsWith('/api/') || !routes.has(url.pathname.split('/').pop())) return nativeFetch(input, options);
    const headers = [...new Headers(options.headers).entries()].sort();
    const key = JSON.stringify([url.href, headers, options.credentials, options.cache, options.mode, options.redirect, options.integrity]);
    let request = pending.get(key);
    if (!request) {
      request = nativeFetch(input, options).finally(() => { if (pending.get(key) === request) pending.delete(key); });
      pending.set(key, request);
    }
    // Every consumer owns its response body; completed responses are never cached.
    return request.then(response => response.clone());
  };
})();
