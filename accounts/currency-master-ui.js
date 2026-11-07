(() => {
  'use strict';
  const names={USD:'United States Dollars',AED:'United Arab Emirates Dirhams',PKR:'Pakistani Rupees',EUR:'Euros',GBP:'Pounds Sterling'};
  let codes=Object.keys(names);
  window.TT_CURRENCY_MASTER={codes:()=>[...codes],name:code=>names[String(code||'').toUpperCase()]||String(code||'')};
  const allowed=window.TT_ACCOUNT_ACCESS?.entities||[],selected=localStorage.getItem('tt_accounts_entity')||'';
  const company=allowed.includes(selected)?selected:allowed[0]||'TTI';
  fetch('../api/currency_master.php?entity='+encodeURIComponent(company),{credentials:'same-origin',headers:{Accept:'application/json'}}).then(response=>response.ok?response.json():null).then(data=>{
    if(!data?.ok)return;
    codes=(data.currencies||[]).map(row=>row.code);
    (data.currencies||[]).forEach(row=>{names[row.code]=row.name});
    window.dispatchEvent(new Event('tt-currencies-updated'));
  }).catch(()=>{});
})();
