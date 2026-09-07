(()=>{
  'use strict';
  const access=window.TT_MODULE_ACCESS||{};
  const api='api/accounts.php';
  const OUTBOX='tt39accountsoutbox';
  const parse=(k,d)=>{try{const v=JSON.parse(localStorage.getItem(k));return v??d}catch{return d}};
  const put=(k,v)=>localStorage.setItem(k,JSON.stringify(v));
  const money=n=>Number(n||0).toFixed(2);

  function outbox(){const a=parse(OUTBOX,[]);return Array.isArray(a)?a:[]}
  function saveOutbox(a){put(OUTBOX,a)}
  function addEvent(event){
    const a=outbox();
    if(a.some(x=>x.key===event.key))return;
    a.push({...event,status:'Pending',createdAt:new Date().toISOString(),tries:0,lastError:''});saveOutbox(a);flush();
  }
  async function postEvent(item){
    const body={action:'post_event',csrf:access.csrf,...item.body};
    const r=await fetch(api,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(body)});
    let d={};try{d=await r.json()}catch{}
    if(r.status===409)return {ok:true,duplicate:true,journal:d.existing?.journalId||''};
    if(!r.ok||!d.ok)throw new Error(d.error||'Automatic accounting post failed.');
    return {ok:true,journal:d.journal?.id||''};
  }
  let flushing=false;
  async function flush(){
    if(flushing)return;flushing=true;
    try{
      let a=outbox();
      for(const item of a.filter(x=>x.status!=='Posted')){
        try{
          const d=await postEvent(item);item.status='Posted';item.postedAt=new Date().toISOString();item.journalId=d.journal||item.journalId||'';item.lastError='';
        }catch(e){item.tries=(item.tries||0)+1;item.lastError=String(e.message||e);item.lastTriedAt=new Date().toISOString()}
        saveOutbox(a);
      }
    } finally {flushing=false}
  }
  function signal(msg,good=true){
    if(typeof window.showNonBlockingMessage==='function')return window.showNonBlockingMessage(msg);
    let e=document.getElementById('ttAccountingBridgeNotice');if(!e){e=document.createElement('div');e.id='ttAccountingBridgeNotice';e.style.cssText='position:fixed;left:50%;bottom:20px;transform:translateX(-50%);z-index:100001;color:#fff;padding:10px 14px;border-radius:10px;font:700 12px Arial;box-shadow:0 7px 24px #0003';document.body.appendChild(e)}e.style.background=good?'#147a5b':'#a93a34';e.textContent=msg;e.hidden=false;clearTimeout(e._t);e._t=setTimeout(()=>e.hidden=true,2200);
  }
  function commodityFromVariety(v){const s=String(v||'').toUpperCase();if(/CORN|MAIZE/.test(s))return 'CORN';if(/SESAME/.test(s))return 'SESAME';return 'RICE'}
  function provisionalReceiptValue(s){
    const kg=Number(s.payableWeight||0),rate=Number(s.purchaseRate||0);
    const katPaisa=['brokenKat','moistureKat','damageKat','chalkyKat','paddyKat'].reduce((n,k)=>n+Number(s[k]||0),0);
    const netRate=Math.max(0,rate-katPaisa/100);
    return {kg,rate,katPaisa,netRate,amount:Math.round(kg*netRate*100)/100};
  }

  function bridgePohanch(){
    if(access.moduleId!=='milling'||typeof window.saveSlip!=='function'||window.saveSlip._ttAccountsWrapped)return;
    const original=window.saveSlip;
    function wrappedSaveSlip(){
      const before=parse('tt30slips',[]),ids=new Set((Array.isArray(before)?before:[]).map(x=>String(x.id)));
      const result=original.apply(this,arguments);
      const after=parse('tt30slips',[]),saved=(Array.isArray(after)?after:[]).find(x=>!ids.has(String(x.id)));
      if(saved){
        const v=provisionalReceiptValue(saved);
        if(v.kg>0&&v.amount>0){
          const sourceKey='POHANCH|'+String(saved.slip||saved.id);
          addEvent({
            key:'TTI|COMMODITY_RECEIPT_ACCEPTED|'+sourceKey,
            body:{
              eventType:'COMMODITY_RECEIPT_ACCEPTED',entity:'TTI',date:saved.unloadingDate||saved.arrivalDate||new Date().toISOString().slice(0,10),
              sourceKey,reference:saved.slip||sourceKey,narration:(commodityFromVariety(saved.variety)==='RICE'?'Rice':commodityFromVariety(saved.variety)==='CORN'?'Corn':'Sesame')+' receipt — Soda '+(saved.soda||'')+' — '+(saved.truck||''),
              amount:v.amount,commodity:commodityFromVariety(saved.variety),
              meta:{soda:saved.soda||'',pohanch:saved.slip||'',truck:saved.truck||'',broker:saved.broker||'',party:saved.party||'',variety:saved.variety||'',payableWeightKg:v.kg,grossRatePerKg:v.rate,katPaisaPerKg:v.katPaisa,provisionalNetRatePerKg:v.netRate,valuation:'Provisional receipt value; final bill clears GRNI and posts authorized differences.'}
            }
          });
          signal('Pohanch saved · provisional Accounts receipt queued');
        }
      }
      return result;
    }
    wrappedSaveSlip._ttAccountsWrapped=true;wrappedSaveSlip._ttOriginal=original;window.saveSlip=wrappedSaveSlip;
  }

  function showOutboxAttention(){
    const p=outbox().filter(x=>x.status!=='Posted');if(!p.length)return;
    let b=document.getElementById('ttAccountsOutboxBadge');if(!b){b=document.createElement('button');b.id='ttAccountsOutboxBadge';b.type='button';b.style.cssText='position:fixed;right:12px;top:98px;z-index:100000;border:0;border-radius:10px;padding:9px 12px;background:#9a6000;color:#fff;font:700 11px Arial;box-shadow:0 5px 18px #0003';b.onclick=()=>flush().then(showOutboxAttention);document.body.appendChild(b)}b.textContent=p.length+' Accounts post'+(p.length===1?'':'s')+' pending · retry';
  }
  async function boot(){bridgePohanch();await flush();showOutboxAttention()}
  addEventListener('DOMContentLoaded',boot);
  setTimeout(boot,300);
  setInterval(()=>{flush().then(showOutboxAttention)},30000);
  window.TT_ACCOUNTS_SOURCE_BRIDGE={flush,outbox};
})();