(()=>{
  'use strict';
  const parse=(k,d=[])=>{try{const v=JSON.parse(localStorage.getItem(k));return v??d}catch{return d}};
  const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const root=()=>parse('transtrade_export_v3_operational',{});
  const contract=ref=>(root().contracts||[]).find(c=>String(c.ref||'')===String(ref||''))||{};
  const records=(key,fallback)=>{const stored=parse(key,null);if(Array.isArray(stored))return stored;try{const fn=window[fallback];return typeof fn==='function'?fn():[]}catch{return []}};
  const pctValue=v=>{const m=String(v??'').match(/(\d+(?:\.\d+)?)\s*%/);return m?m[1]:(/^\d+(?:\.\d+)?$/.test(String(v??'').trim())?String(v).trim():'')};
  function quality(row={}){
    const c=contract(row.contractRef), raw=c.broken??c.brokenPct??c.brokenSpec??row.brokenPct??row.broken??row.qualityLabel??row.quality??'';
    let s=String(raw??'').trim(),pct=pctValue(s);
    if(pct)return {pct,label:`${pct}% BROKEN`};
    if(!s)return {pct:'',label:'QUALITY NOT AVAILABLE'};
    s=s.toUpperCase();
    if(s.includes('BROKEN'))return {pct:'',label:s};
    if(s.includes('%'))return {pct:'',label:`${s} BROKEN`};
    return {pct:'',label:s};
  }
  const refs=row=>({contract:String(row?.contractRef||'').trim(),lot:String(row?._ttLotId||row?.ref||row?.shipment||'').trim()});
  function badgeHtml(row,kind='lot'){
    const q=quality(row),r=refs(row),refText=[r.contract&&`CONTRACT: ${r.contract}`,r.lot&&`${kind.toUpperCase()}: ${r.lot}`].filter(Boolean).join(' · ');
    return `<div class="tt-quality-id"><b>${esc(q.label)}</b>${refText?`<span>${esc(refText)}</span>`:''}</div>`;
  }
  function installCss(){if(document.getElementById('ttQualityIdentityCss'))return;const st=document.createElement('style');st.id='ttQualityIdentityCss';st.textContent=`.tt-quality-id{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:7px;font-size:11px}.tt-quality-id b{display:inline-block;background:#fff0d6;color:#744600;border:1px solid #e2be78;border-radius:999px;padding:5px 9px;font-size:12px}.tt-quality-id span{color:#536172;font-weight:700}.tt-load-quality{border:2px solid #d28b18;background:#fff8e8;color:#553800;border-radius:10px;padding:11px 13px;margin:0 0 10px;font-weight:800;font-size:14px}.tt-load-quality strong{font-size:17px;color:#8a3f00}.tt-quality-missing{border-color:#b42318;background:#fff1f0;color:#8c1d18}`;document.head.appendChild(st)}
  function enrichStores(){
    for(const key of ['tt30prodinst','tt30ship','tt35exmill']){
      const a=parse(key,null);if(!Array.isArray(a))continue;let changed=false;
      a.forEach(x=>{const c=contract(x.contractRef);if(!c||!Object.keys(c).length)return;const q=quality(x);if(q.label&&x.qualityLabel!==q.label){x.qualityLabel=q.label;changed=true}if(q.pct&&String(x.brokenPct||'')!==q.pct){x.brokenPct=q.pct;changed=true}if(q.label&&String(x.broken||'')!==q.label){x.broken=q.label;changed=true}});
      if(changed)localStorage.setItem(key,JSON.stringify(a));
    }
  }
  function decorateProduction(){
    const a=records('tt30prodinst','defaultProductionInstructions'),cards=[...document.querySelectorAll('#instructionList .instruction-card')];
    cards.forEach((card,i)=>{const x=a[i];if(!x)return;const h=card.querySelector('h3');if(h&&!h.querySelector('.tt-quality-title')){const q=quality(x),b=document.createElement('span');b.className='tt-quality-title';b.style.cssText='display:inline-block;margin-left:8px;background:#fff0d6;color:#744600;border:1px solid #e2be78;border-radius:999px;padding:4px 8px;font:800 11px Arial';b.textContent=q.label;h.appendChild(b)}if(!card.querySelector(':scope > .tt-quality-id')&&h)h.insertAdjacentHTML('afterend',badgeHtml(x,'PI'))});
  }
  function decorateProductionDetail(id){
    const a=records('tt30prodinst','defaultProductionInstructions'),x=a.find(y=>String(y.id)===String(id)),d=document.getElementById('piDetail_'+id);if(x&&d&&!d.querySelector('.tt-load-quality'))d.insertAdjacentHTML('afterbegin',`<div class="tt-load-quality"><strong>${esc(quality(x).label)}</strong> &nbsp; ${esc(refs(x).contract?`CONTRACT: ${refs(x).contract}`:'')} &nbsp; ${esc(x.ref?`PI: ${x.ref}`:'')}</div>`)
  }
  function decorateShipments(){
    const a=records('tt30ship','defaultShipments'),rows=[...document.querySelectorAll('#shipmentBody tr.clickrow')];
    rows.forEach((tr,i)=>{const x=a[i],cell=tr.cells?.[0];if(!x||!cell)return;let box=cell.querySelector('.tt-quality-id');if(box)box.remove();cell.insertAdjacentHTML('beforeend',badgeHtml(x,'LOT'))});
    document.querySelectorAll('[id^="inlineShipment_"]').forEach(host=>{const id=host.id.replace('inlineShipment_',''),x=a.find(s=>String(s.id)===id),banner=host.querySelector('#instructionBanner');if(!x||!banner)return;let old=host.querySelector('.tt-load-quality');if(old)old.remove();const q=quality(x),r=refs(x),missing=q.label==='QUALITY NOT AVAILABLE';banner.insertAdjacentHTML('beforebegin',`<div class="tt-load-quality${missing?' tt-quality-missing':''}"><strong>LOAD THIS QUALITY: ${esc(q.label)}</strong><br>${esc(r.contract?`CONTRACT: ${r.contract}`:'CONTRACT: —')} &nbsp; | &nbsp; ${esc(r.lot?`LOT: ${r.lot}`:'LOT: —')}</div>`);const title=host.querySelector('#shipmentTitle');if(title)title.textContent=`${x.brand||''} — ${q.label} — ${r.lot||x.ref||''}`});
  }
  function decorateExMillWorkspace(){
    let selected='';try{selected=exSelectedMill||''}catch{}const a=records('tt35exmill','defaultExMillSodas').filter(x=>!selected||x.mill===selected).filter(x=>x.shipment&&Number(x.qtyKg||0)>Number(x.loadedKg||0)),rows=[...document.querySelectorAll('#exMillShipmentRows .clickrow')];
    rows.forEach((row,i)=>{const x=a[i];if(!x)return;const old=row.querySelector(':scope > .tt-quality-id');if(old)old.remove();const b=row.querySelector('b');if(b)b.insertAdjacentHTML('afterend',badgeHtml(x,'LOT'))});
  }
  function decorateExLoad(id,box){const x=records('tt35exmill','defaultExMillSodas').find(y=>String(y.id)===String(id));if(!x||!box)return;const old=box.querySelector(':scope > .tt-load-quality');if(old)old.remove();const q=quality(x),r=refs(x);box.insertAdjacentHTML('afterbegin',`<div class="tt-load-quality"><strong>LOAD THIS QUALITY: ${esc(q.label)}</strong><br>${esc(r.contract?`CONTRACT: ${r.contract}`:'CONTRACT: —')} &nbsp; | &nbsp; ${esc(r.lot?`LOT: ${r.lot}`:'LOT: —')}</div>`)}
  function wrap(name,after){const orig=window[name];if(typeof orig!=='function'||orig.__ttQualityWrapped)return;const wrapped=function(...args){const out=orig.apply(this,args);try{after(...args)}catch(e){console.error('Quality identity '+name,e)}return out};wrapped.__ttQualityWrapped=true;window[name]=wrapped}
  function install(){
    installCss();enrichStores();
    wrap('renderProductionInstructions',decorateProduction);
    wrap('openProductionInstruction',decorateProductionDetail);
    wrap('renderShipments',decorateShipments);
    wrap('renderExMillWorkspace',decorateExMillWorkspace);
    wrap('renderExLoadForm',decorateExLoad);
    const originalSync=window.syncContainerToExport;if(typeof originalSync==='function'&&!originalSync.__ttQualityWrapped){const fn=function(s,c){const out=originalSync.apply(this,arguments);try{const feed=parse('tt32exportsync',[]),row=feed[feed.length-1];if(row&&String(row.container||'')===String(c?.container||'')){const q=quality(s),r=refs(s);Object.assign(row,{contractRef:r.contract,lotRef:r.lot,broken:q.label,qualityLabel:q.label});localStorage.setItem('tt32exportsync',JSON.stringify(feed))}}catch(e){console.error('Quality identity sync',e)}return out};fn.__ttQualityWrapped=true;window.syncContainerToExport=fn}
    const originalPrint=window.printGate;if(typeof originalPrint==='function'&&!originalPrint.__ttQualityWrapped){const fn=function(c,s){const q=quality(s),r=refs(s),w=open('');if(!w)return;w.document.write(`<h2>EXPORT GATE PASS</h2><p><b>Brand:</b> ${esc(s?.brand||'')}</p><p><b>Broken / Quality:</b> ${esc(q.label)}</p><p><b>Contract:</b> ${esc(r.contract||'—')}</p><p><b>Lot:</b> ${esc(r.lot||'—')}</p><p><b>Container:</b> ${esc(c?.container||'')}</p><p><b>Seal:</b> ${esc(c?.seal||'')}</p><p><b>Truck:</b> ${esc(c?.truck||'')}</p><p><b>Weighbridge Weight:</b> ${esc(c?.weight||'')} kg</p><p><b>Bags Loaded:</b> ${esc(c?.bags||'')}</p><p><b>Empty Bags:</b> ${esc(c?.empty||0)}</p><p><b>Gate Pass:</b> ${esc(c?.gate||'')}</p><script>print()<\/script>`);w.document.close()};fn.__ttQualityWrapped=true;window.printGate=fn}
    try{decorateProduction();decorateShipments();decorateExMillWorkspace()}catch(e){console.error('Quality identity initial render',e)}
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',install,{once:true});else install();
})();
