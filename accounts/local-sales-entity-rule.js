(()=>{
  'use strict';
  const access=window.TT_MODULE_ACCESS||{};
  if(access.moduleId!=='milling')return;
  const endpoint='api/local_sales_entity_rule.php';

  function localFrame(){const f=document.querySelector('#partySaleWrap iframe');try{return f?.contentWindow||null}catch(e){return null}}
  function selectedBankEntity(w){const o=w?.document?.getElementById('payBank')?.selectedOptions?.[0];return String(o?.dataset?.entity||'').toUpperCase()}
  function existingBankEntity(sale){
    const banks=(sale?.pay||[]).filter(p=>String(p?.type||'')==='Transtrade Bank').map(p=>String(p?.bankEntity||'').toUpperCase()).filter(x=>x==='TTI'||x==='BRM');
    if(banks.includes('BRM'))return 'BRM';
    if(banks.includes('TTI'))return 'TTI';
    return '';
  }
  function setNonBankEntity(sale,entity){(sale?.pay||[]).forEach(p=>{if(String(p?.type||'')!=='Transtrade Bank')p.bankEntity=entity});}
  function targetForCurrentAction(w){
    const sale=w?.sel;if(!sale)return 'TTI';
    const type=String(w.document?.getElementById('payType')?.value||'');
    if(type==='Transtrade Bank'){
      const chosen=selectedBankEntity(w);if(chosen!=='TTI'&&chosen!=='BRM')return '';
      const prior=existingBankEntity(sale);
      if(prior&&prior!==chosen){w.alert('This Local Soda already has a payment in a '+prior+' bank account. Use the same company bank for this Soda.');return null;}
      sale.accountEntity=chosen;setNonBankEntity(sale,chosen);return chosen;
    }
    const prior=existingBankEntity(sale),target=prior==='BRM'?'BRM':'TTI';
    sale.accountEntity=target;setNonBankEntity(sale,target);return target;
  }
  async function reclassify(sale,target){
    if(!sale?.soda||!['TTI','BRM'].includes(target))return;
    try{
      const r=await fetch(endpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({csrf:access.csrf,soda:String(sale.soda),targetEntity:target})});
      let d={};try{d=await r.json()}catch{}
      if(!r.ok||!d.ok){console.error('Local Sales company rule',d.error||r.status);return;}
      if(window.TT_ACCOUNTS_SOURCE_BRIDGE?.flush)window.TT_ACCOUNTS_SOURCE_BRIDGE.flush();
    }catch(e){console.error('Local Sales company rule',e)}
  }
  function install(){
    const w=localFrame();if(!w||!w.document||!Array.isArray(w.sales))return;
    if(typeof w.savePay==='function'&&!w.savePay._ttEntityRuleWrapped){const original=w.savePay;function wrapped(){if(!w.sel)return original.apply(this,arguments);const target=targetForCurrentAction(w);if(target===null)return;if(target==='')return w.alert('Select the Transtrade bank account receiving this payment.');const result=original.apply(this,arguments);try{w.persistSales?.()}catch(e){}setTimeout(()=>reclassify(w.sel,target),250);setTimeout(()=>reclassify(w.sel,target),1400);return result}wrapped._ttEntityRuleWrapped=true;wrapped._ttOriginal=original;w.savePay=wrapped}
    if(typeof w.gate==='function'&&!w.gate._ttEntityRuleWrapped){const original=w.gate;function wrapped(i){if(!w.sel)return original.apply(this,arguments);const prior=existingBankEntity(w.sel),target=prior==='BRM'?'BRM':'TTI';w.sel.accountEntity=target;setNonBankEntity(w.sel,target);const result=original.apply(this,arguments);try{w.persistSales?.()}catch(e){}setTimeout(()=>reclassify(w.sel,target),250);setTimeout(()=>reclassify(w.sel,target),1400);return result}wrapped._ttEntityRuleWrapped=true;wrapped._ttOriginal=original;w.gate=wrapped}
  }
  addEventListener('DOMContentLoaded',()=>{setTimeout(install,400);setTimeout(install,1300)});setInterval(install,5000);
})();

