(()=>{'use strict';
const STORE='transtrade_export_v3_operational';
const readState=()=>{try{return JSON.parse(localStorage.getItem(STORE)||'null')||{contracts:[],shipments:[]}}catch{return{contracts:[],shipments:[]}}};
const writeState=data=>localStorage.setItem(STORE,JSON.stringify(data));
function reopenLot(id){
 const data=readState(),lot=(data.shipments||[]).find(row=>row.id===id&&row.kind==='lot');
 if(!lot)return alert('This lot could not be found. Refresh the page and try again.');
 const label=[lot.contractRef,lot.lotId].filter(Boolean).join(' / '),reason=String(prompt('Reason for reopening '+label+':')||'').trim();
 if(!reason)return alert('A reason is required to reopen this completed lot.');
 lot.reopenHistory=Array.isArray(lot.reopenHistory)?lot.reopenHistory:[];
 lot.reopenHistory.unshift({at:new Date().toISOString(),by:window.TT_MODULE_ACCESS?.user||'User',reason,previousStatus:lot.status||'',previousCompletedAt:lot.completedAt||'',previousDocumentFolder:lot.documentFolder||''});
 lot.completed=false;
 lot.status='Reopened';
 lot.next='Review and close lot again';
 lot.reopenedAt=new Date().toISOString();
 lot.reopenedBy=window.TT_MODULE_ACCESS?.user||'User';
 lot.reopenReason=reason;
 delete lot.completedAt;
 const parent=(data.shipments||[]).find(row=>row.id===lot.parentProcessId&&row.kind!=='lot');
 if(parent){parent.completed=false;if(String(parent.status||'')==='Completed')parent.status='Lot Reopened';parent.next='Review reopened lot'}
 const contract=(data.contracts||[]).find(row=>row.ref===lot.contractRef);
 if(contract&&String(contract.status||'')==='Completed')contract.status=contract.received?'Contract Received':'Awaiting Customer Confirmation';
 writeState(data);
 window.dispatchEvent(new Event('tt:shared-updated'));
 const done=()=>{window.dispatchEvent(new Event('tt:shared-updated'));alert(label+' reopened. It should now appear under Active Shipments.')};
 const fail=error=>alert((error&&error.message)||'The lot was reopened locally, but server sync did not confirm. Press refresh and check before continuing.');
 try{const sync=window.TT_SHARED_SYNC?.saveNow?.();sync&&sync.then?sync.then(done).catch(fail):done()}catch(error){fail(error)}
}
function installButtons(){
 const heading=[...document.querySelectorAll('h2')].find(node=>/Completed Shipments/i.test(node.textContent||''));
 if(!heading)return;
 document.querySelectorAll('[data-open-lot]').forEach(open=>{
  const id=open.dataset.openLot,exists=[...(open.parentElement?.querySelectorAll('[data-reopen-lot]')||[])].some(button=>button.dataset.reopenLot===id);
  if(!id||exists)return;
  const button=document.createElement('button');button.type='button';button.className='btn amber';button.dataset.reopenLot=id;button.textContent='REOPEN';button.onclick=event=>{event.preventDefault();event.stopPropagation();reopenLot(id)};
  open.insertAdjacentElement('afterend',button);
 })
}
installButtons();
new MutationObserver(installButtons).observe(document.documentElement,{childList:true,subtree:true});
})();
