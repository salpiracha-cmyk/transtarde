(()=>{
  'use strict';
  const access=window.TT_MODULE_ACCESS||{};
  const accountsApi='api/accounts.php', exportApi='api/export_accounting.php';
  const OUTBOX='tt39accountsoutbox';
  const parse=(k,d)=>{try{const v=JSON.parse(localStorage.getItem(k));return v??d}catch{return d}};
  const put=(k,v)=>localStorage.setItem(k,JSON.stringify(v));

  function outbox(){const a=parse(OUTBOX,[]);return Array.isArray(a)?a:[]}
  function saveOutbox(a){put(OUTBOX,a)}
  function addItem(event){const a=outbox();if(a.some(x=>x.key===event.key))return;a.push({...event,status:'Pending',createdAt:new Date().toISOString(),tries:0,lastError:''});saveOutbox(a);flush()}
  async function postItem(item){
    const isExport=item.kind==='exportCandidate',endpoint=isExport?exportApi:accountsApi;
    const body=isExport?{action:'queue_candidate',csrf:access.csrf,...item.body}:{action:'post_event',csrf:access.csrf,...item.body};
    const r=await fetch(endpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(body)});let d={};try{d=await r.json()}catch{}
    if(r.status===409)return {ok:true,duplicate:true,journal:d.existing?.journalId||'',candidate:d.candidate?.id||''};
    if(!r.ok||!d.ok)throw new Error(d.error||'Automatic accounting handoff failed.');
    return {ok:true,journal:d.journal?.id||'',candidate:d.candidate?.id||''};
  }
  let flushing=false;
  async function flush(){
    if(flushing)return;flushing=true;
    try{let a=outbox();for(const item of a.filter(x=>x.status!=='Posted')){try{const d=await postItem(item);item.status='Posted';item.postedAt=new Date().toISOString();item.journalId=d.journal||item.journalId||'';item.candidateId=d.candidate||item.candidateId||'';item.lastError=''}catch(e){item.tries=(item.tries||0)+1;item.lastError=String(e.message||e);item.lastTriedAt=new Date().toISOString()}saveOutbox(a)}}finally{flushing=false}
  }
  function signal(msg,good=true){if(typeof window.showNonBlockingMessage==='function')return window.showNonBlockingMessage(msg);let e=document.getElementById('ttAccountingBridgeNotice');if(!e){e=document.createElement('div');e.id='ttAccountingBridgeNotice';e.style.cssText='position:fixed;left:50%;bottom:20px;transform:translateX(-50%);z-index:100001;color:#fff;padding:10px 14px;border-radius:10px;font:700 12px Arial;box-shadow:0 7px 24px #0003';document.body.appendChild(e)}e.style.background=good?'#147a5b':'#a93a34';e.textContent=msg;e.hidden=false;clearTimeout(e._t);e._t=setTimeout(()=>e.hidden=true,2400)}
  function commodityFromVariety(v){const s=String(v||'').toUpperCase();if(/CORN|MAIZE/.test(s))return 'CORN';if(/SESAME/.test(s))return 'SESAME';return 'RICE'}
  function provisionalReceiptValue(s){const kg=Number(s.payableWeight||0),rate=Number(s.purchaseRate||0),katPaisa=['brokenKat','moistureKat','damageKat','chalkyKat','paddyKat'].reduce((n,k)=>n+Number(s[k]||0),0),netRate=Math.max(0,rate-katPaisa/100);return {kg,rate,katPaisa,netRate,amount:Math.round(kg*netRate*100)/100}}

  function bridgePohanch(){
    if(access.moduleId!=='milling'||typeof window.saveSlip!=='function'||window.saveSlip._ttAccountsWrapped)return;
    const original=window.saveSlip;
    function wrappedSaveSlip(){
      const before=parse('tt30slips',[]),ids=new Set((Array.isArray(before)?before:[]).map(x=>String(x.id))),result=original.apply(this,arguments),after=parse('tt30slips',[]),saved=(Array.isArray(after)?after:[]).find(x=>!ids.has(String(x.id)));
      if(saved){const v=provisionalReceiptValue(saved);if(v.kg>0&&v.amount>0){const sourceKey='POHANCH|'+String(saved.slip||saved.id);addItem({key:'TTI|COMMODITY_RECEIPT_ACCEPTED|'+sourceKey,kind:'accountEvent',body:{eventType:'COMMODITY_RECEIPT_ACCEPTED',entity:'TTI',date:saved.unloadingDate||saved.arrivalDate||new Date().toISOString().slice(0,10),sourceKey,reference:saved.slip||sourceKey,narration:(commodityFromVariety(saved.variety)==='RICE'?'Rice':commodityFromVariety(saved.variety)==='CORN'?'Corn':'Sesame')+' receipt — Soda '+(saved.soda||'')+' — '+(saved.truck||''),amount:v.amount,commodity:commodityFromVariety(saved.variety),meta:{soda:saved.soda||'',pohanch:saved.slip||'',truck:saved.truck||'',broker:saved.broker||'',party:saved.party||'',variety:saved.variety||'',payableWeightKg:v.kg,grossRatePerKg:v.rate,katPaisaPerKg:v.katPaisa,provisionalNetRatePerKg:v.netRate,valuation:'Provisional receipt value; final bill clears GRNI and posts authorized differences.'}}});signal('Pohanch saved · provisional Accounts receipt queued')}}
      return result;
    }
    wrappedSaveSlip._ttAccountsWrapped=true;wrappedSaveSlip._ttOriginal=original;window.saveSlip=wrappedSaveSlip;
  }

  function exportRefs(s){
    let fi=[];try{fi=(s.customs?.fiAllocations||[]).map(a=>window.state?.fi?.find(f=>f.id===a.fiId)?.number).filter(Boolean)}catch(e){}
    let gd=[...(s.commercial?.gd||[]),...(s.bl?.gd||[])].map(g=>g?.no).filter(Boolean);gd=[...new Set(gd)];return {fi,gd};
  }
  function exportNetAmount(s,c){const qty=Number(s.actualShippedQty||((typeof window.shipmentActualQtyFor==='function')?window.shipmentActualQtyFor(s):s.plannedQty)||0),rate=Number(s.commercial?.rate||c?.packings?.[0]?.price||c?.price||0),adj=(s.commercial?.adjustments||[]).reduce((n,a)=>n+(a.sign==='+'?1:-1)*Number(a.amount||0),0);return {qty,rate,amount:Math.round((qty*rate+adj)*100)/100}}
  function bridgeExportCompletion(){
    if(access.moduleId!=='exports'||typeof window.markComplete!=='function'||window.markComplete._ttAccountsWrapped)return;
    const original=window.markComplete;
    function wrappedMarkComplete(){
      let s=null,c=null,before=false;try{s=window.getShip();c=window.getContract(s.contractRef);before=!!s.completed}catch(e){}
      const result=original.apply(this,arguments);
      try{
        if(s&&!before&&s.completed){const val=exportNetAmount(s,c),refs=exportRefs(s),sourceKey='EXPORT|'+String(s.id||s.lotNo||s.contractRef),seller=String(s.seller||c?.seller||'TTI').toUpperCase();
          if(val.qty>0&&val.amount>0&&s.bl?.onBoardDate){addItem({key:seller+'|EXPORT_CANDIDATE|'+sourceKey,kind:'exportCandidate',body:{sellerEntity:seller,sourceKey,lotRef:String(s.lotNo||s.id||s.contractRef),contractRef:String(s.contractRef||''),customer:String(s.buyer||c?.customer||''),product:String(c?.product||''),currency:String(c?.currency||'USD'),transactionAmount:val.amount,actualQtyMT:val.qty,incoterm:String(c?.incoterm||'OTHER'),onBoardDate:String(s.bl.onBoardDate),blNo:String(s.bl?.blNo||''),commercialInvoiceDate:String(s.commercial?.date||''),fiRefs:refs.fi,gdRefs:refs.gd,tgInternalValue:seller==='TG'?Number(s.customs?.tgInternalValue||0):0}});signal('Export lot completed · Accounts recognition candidate queued')}
        }
      }catch(e){console.error('Export → Accounts bridge',e)}
      return result;
    }
    wrappedMarkComplete._ttAccountsWrapped=true;wrappedMarkComplete._ttOriginal=original;window.markComplete=wrappedMarkComplete;
  }

  function showOutboxAttention(){const p=outbox().filter(x=>x.status!=='Posted');let b=document.getElementById('ttAccountsOutboxBadge');if(!p.length){if(b)b.remove();return}if(!b){b=document.createElement('button');b.id='ttAccountsOutboxBadge';b.type='button';b.style.cssText='position:fixed;right:12px;top:98px;z-index:100000;border:0;border-radius:10px;padding:9px 12px;background:#9a6000;color:#fff;font:700 11px Arial;box-shadow:0 5px 18px #0003';b.onclick=()=>flush().then(showOutboxAttention);document.body.appendChild(b)}b.textContent=p.length+' Accounts handoff'+(p.length===1?'':'s')+' pending · retry'}
  async function boot(){bridgePohanch();bridgeExportCompletion();await flush();showOutboxAttention()}
  addEventListener('DOMContentLoaded',boot);setTimeout(boot,300);setTimeout(boot,1200);setInterval(()=>{bridgePohanch();bridgeExportCompletion();flush().then(showOutboxAttention)},30000);
  window.TT_ACCOUNTS_SOURCE_BRIDGE={flush,outbox};
})();