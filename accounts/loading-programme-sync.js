(()=>{
  const access=window.TT_MODULE_ACCESS||{};
  if(access.moduleId!=='exports')return;
  const outboxApi='api/bridge_outbox.php',storeKey='transtrade_export_v3_operational',sentKey='tt_accounts_loading_programme_signatures';
  let busy=false;
  const read=(key,fallback)=>{try{const value=JSON.parse(localStorage.getItem(key)||'');return value&&typeof value==='object'?value:fallback}catch{return fallback}};
  const entityFor=(lot,contract)=>{const seller=String(lot?.seller||contract?.seller||'').toLowerCase();if(seller.includes('buksh'))return'BRM';if(seller.includes('trans grains'))return'TG';return'TTI'};
  const cleanNumbers=lot=>[...new Set((lot?.millActuals||[]).map(x=>String(x?.number||'').trim().toUpperCase()).filter(Boolean))];
  async function sync(){
    if(busy)return;busy=true;
    try{
      const root=read(storeKey,null);if(!root?.shipments)return;
      const contracts=new Map((root.contracts||[]).map(x=>[String(x.ref||''),x])),sent=read(sentKey,{});
      for(const lot of root.shipments.filter(x=>x?.kind==='lot'&&x.loadingProgrammeNo)){
        const contract=contracts.get(String(lot.contractRef||''))||{},numbers=cleanNumbers(lot);
        const payload={action:'register_loading_program',entity:entityFor(lot,contract),loadingProgrammeNo:String(lot.loadingProgrammeNo),contractRef:String(lot.contractRef||''),lotRef:String(lot.lotId||''),blNo:String(lot.bl?.blNo||''),shippingLine:String(contract.shippingLine||''),loadingMill:[...new Set((lot.loadingPlan?.allocations||[]).map(x=>x.name).filter(Boolean))].join(' + '),destinationPort:String(contract.destinationPort||contract.dischargePort||''),loadedContainers:numbers.length,containerNumbers:numbers};
        const signature=JSON.stringify(payload);if(sent[lot.loadingProgrammeNo]===signature)continue;
        const queueBody={action:'enqueue',csrf:access.csrf,key:payload.entity+'|LOADING_PROGRAMME|'+payload.loadingProgrammeNo,kind:'loadingProgramme',body:payload};const response=await fetch(outboxApi,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(queueBody)});let data={};try{data=await response.json()}catch{}
        if(!response.ok||!data.ok){if(response.status===403)break;throw new Error(data.error||'Loading Programme sync failed.')}sent[lot.loadingProgrammeNo]=signature;localStorage.setItem(sentKey,JSON.stringify(sent));window.TT_ACCOUNTS_SOURCE_BRIDGE?.mergeServerOutbox?.().then(()=>window.TT_ACCOUNTS_SOURCE_BRIDGE?.flush?.());
      }
    }catch(error){console.error('Accounts Loading Programme sync',error)}finally{busy=false}
  }
  addEventListener('DOMContentLoaded',()=>setTimeout(sync,800));
  addEventListener('focus',sync);
  document.addEventListener('click',event=>{if(event.target.closest('#sendLoading,[data-reissue-loading]'))setTimeout(sync,1800)},true);
  setInterval(()=>{if(!document.hidden)sync()},15000);
})();
