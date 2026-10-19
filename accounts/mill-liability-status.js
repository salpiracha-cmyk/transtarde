(() => {
  'use strict';
  let checkedScope='',checkedAt='',records=new Map(),busy=false,activeScope='',generation=0;
  function context(){const mill=typeof currentMill!=='undefined'?currentMill:null;return {entity:typeof stockEntityForView==='function'?stockEntityForView():String(mill?.entity||''),millName:String(mill?.name||'')};}
  const scope=c=>c.entity+'|'+c.millName, money=v=>Number(v).toLocaleString('en-PK',{maximumFractionDigits:2});
  function text(row){
    if(checkedScope!==scope(context()))return '';
    const collection=row._sourceCollection||(row.readFrom?'tt38kebills':row.contractor?'tt38labourbills':row.billableKg!==undefined?'tt38reprocessbills':'');
    const result=records.get(collection+'|'+String(row.id));if(!result)return '';
    return result.status+' · '+result.postId+' · Rs '+money(result.postedAmount)+(result.reversalPostId?' · reversal '+result.reversalPostId:'')+(result.outstanding!==null?' · outstanding Rs '+money(result.outstanding):' · payable link unverified')+(result.sourceNeedsReview?' · source needs review':'');
  }
  function referenceText(collection,row){
    if(checkedScope!==scope(context()))return 'Accounts link not checked';
    const r=records.get(collection+'|'+String(row.id));if(!r)return 'No verified Accounts link';
    return r.status+(r.postId?' · '+r.postId+' · Rs '+money(r.postedAmount):'')+(r.referenceKind==='SALARY_ADVANCE'&&r.outstanding!==null?' · Canonical advance remaining Rs '+money(r.outstanding):'')+(r.blocker?' · '+r.blocker:'');
  }
  async function refresh(){
    const c=context();if(!c.millName||(busy&&activeScope===scope(c)))return;const token=++generation;activeScope=scope(c);busy=true;
    const notes=[...document.querySelectorAll('[data-mill-bill-status-note]')];notes.forEach(n=>n.textContent='Checking committed Accounts postings…');
    const buttons=[...document.querySelectorAll('[data-refresh-mill-bill-status]')];buttons.forEach(b=>b.disabled=true);
    try{
      const r=await fetch('api/mill_liability_status.php?entity='+encodeURIComponent(c.entity)+'&millName='+encodeURIComponent(c.millName),{credentials:'same-origin',headers:{Accept:'application/json'}}),d=await r.json();
      if(!r.ok||!d.ok)throw Error(d.error||'Accounts status could not be checked.');
      if(d.entity!==c.entity||d.millName!==c.millName||d.rows.some(x=>x.entity!==c.entity||x.millName!==c.millName))throw Error('Accounts returned another company or mill. Refresh the selected mill.');
      if(token!==generation||scope(c)!==scope(context()))return;
      records=new Map(d.rows.map(x=>[x.collection+'|'+x.sourceId,x]));checkedScope=scope(c);checkedAt=new Date(d.checkedAt).toLocaleString('en-PK',{timeZone:'Asia/Karachi'});
      document.querySelectorAll('[data-mill-reference-status]').forEach(n=>n.textContent=referenceText(n.dataset.collection,{id:n.dataset.sourceId}));
      if(typeof renderProcessingExpenses==='function')renderProcessingExpenses();
      if(typeof renderReprocessHistory==='function')renderReprocessHistory();
      notes.forEach(n=>n.textContent='Accounts checked '+checkedAt+' PKT. Refresh to load later changes.');
    }catch(e){if(token!==generation||scope(c)!==scope(context()))return;notes.forEach(n=>n.textContent=e.message+(checkedScope===scope(c)?' Last confirmed status from '+checkedAt+' PKT is retained.':' Posting remains unverified until a successful check.'));}
    finally{if(token!==generation)return;busy=false;buttons.forEach(b=>{if(b.isConnected)b.disabled=false;});}
  }
  document.querySelectorAll('[data-refresh-mill-bill-status]').forEach(b=>b.addEventListener('click',refresh));
  if(typeof window.openPanel==='function'){
    const previous=window.openPanel;
    window.openPanel=function(id){const result=previous.apply(this,arguments);if(id==='labour'||id==='reprocessbill')void refresh();return result;};
  }
  window.TT_MILL_BILL_STATUS={text,refresh,referenceText};
})();
