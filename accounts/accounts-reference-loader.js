(()=>{
  'use strict';
  // Share simultaneous startup reads only. Settled responses are never cached
  // across saves, company changes, permission changes or subsequent refreshes.
  const pending=new Map();
  window.TT_ACCOUNTS_REFERENCE={read(entity){
    const company=String(entity||'');
    if(pending.has(company))return pending.get(company);
    const request=fetch('../api/accounts.php?entity='+encodeURIComponent(company),{credentials:'same-origin',headers:{Accept:'application/json'}})
      .then(async response=>{const data=await response.json();if(!response.ok||!data.ok)throw Error(data.error||'Account references could not load.');return data;})
      .finally(()=>{if(pending.get(company)===request)pending.delete(company);});
    pending.set(company,request);return request;
  }};
})();
