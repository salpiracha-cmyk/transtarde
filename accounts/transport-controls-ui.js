(() => {
  'use strict';
  const api = '../api/accounts_workflows_v1.php';
  const entity = () => localStorage.getItem('tt_accounts_entity') || 'TTI';
  let routes = [];
  async function load() {
    const company = entity();
    try {
      const response = await fetch(api + '?entity=' + encodeURIComponent(company) + '&section=transport', {credentials:'same-origin'});
      const data = await response.json();
      if (company === entity()) routes = response.ok && data.ok ? data.routes || [] : [];
    } catch { if (company === entity()) routes = []; }
  }
  function install(row) {
    const from = row.querySelector('.tfrom'), to = row.querySelector('.tto'), rate = row.querySelector('.trate');
    if (!from || !to || !rate || row.dataset.ttRouteControl) return;
    row.dataset.ttRouteControl = '1';
    const suggest = () => {
      const match = routes.find(route => String(route.from || '').trim().toLowerCase() === from.value.trim().toLowerCase() && String(route.to || '').trim().toLowerCase() === to.value.trim().toLowerCase());
      if (match && !rate.value) { rate.value = String(match.rate); rate.title = 'Suggested from Transport Route Master'; }
    };
    from.addEventListener('change', suggest);
    to.addEventListener('change', suggest);
    suggest();
  }
  const scan = () => document.querySelectorAll('#trLines .ttv-row').forEach(install);
  new MutationObserver(scan).observe(document.documentElement, {childList:true, subtree:true});
  document.addEventListener('click', event => {
    if (event.target.closest('.appCard[data-key="transport"]')) { routes = []; load().then(scan); }
    if (event.target.closest('[data-entity]')) routes = [];
  });
  load().then(scan);
})();
