const assert=require('node:assert/strict');
const fs=require('node:fs');
const {JSDOM}=require('jsdom');

const source=fs.readFileSync('milling/Transtrade_Master_Milling_V3_3_2_AUDITED.html','utf8');
function fn(name){
  const start=source.indexOf('function '+name+'(');assert.ok(start>=0,'missing '+name);
  const body=source.indexOf('{',start);let depth=0,quote='',escaped=false;
  for(let i=body;i<source.length;i++){const c=source[i];if(quote){if(escaped)escaped=false;else if(c==='\\')escaped=true;else if(c===quote)quote='';continue}if(c==='"'||c==="'"||c==='`'){quote=c;continue}if(c==='{')depth++;else if(c==='}'&&--depth===0)return source.slice(source.slice(Math.max(0,start-6),start)==='async '?start-6:start,i+1)}
  throw new Error('unterminated '+name);
}

const dom=new JSDOM('<div id="exMillTabs"></div><div id="exMillWorkspace"><div id="exLoadingPanel"><div id="exMillName"></div><div id="exMillShipmentRows"></div></div><div id="exMillSummaryRows"></div></div>',{runScripts:'dangerously'});
const w=dom.window,stores={
  loads:[],
  sodas:[{id:71,_ttBridge:'accounts-soda',_ttPurchaseSodaId:'ACC-1',qtyKg:270000}],
  instructions:[{id:91,_ttBridge:'exports',_ttBridgeId:'SHIP-1|C-1|LOT-01|0',localSodaId:71,sourceSodaId:'ACC-1',allocationStatus:'LINKED',mill:'Al-Harmain Rice Mills',brand:'ASAS',displayName:'PK-386 White Ready Rice',baseVariety:'PK-386',riceType:'White',contractRef:'C-1',lotRef:'LOT-01',shipmentId:'SHIP-1',totalContainers:10,instructionQtyKg:270000,bagTare:'110 g',dryon:'No',craft:'No',dpp:'Yes'}]
};
let accountsPosts=0,sharedSaves=0;
Object.assign(w,{STORE_EXLOAD:'loads',STORE_EXMILL:'sodas',STORE_EXINSTR:'instructions',STORE_INSTRUCTION_SEEN:'seen',exMillTabs:w.document.getElementById('exMillTabs'),exMillWorkspace:w.document.getElementById('exMillWorkspace'),exLoadingPanel:w.document.getElementById('exLoadingPanel'),exMillName:w.document.getElementById('exMillName'),exMillShipmentRows:w.document.getElementById('exMillShipmentRows'),exMillSummaryRows:w.document.getElementById('exMillSummaryRows'),get:(k,d)=>structuredClone(stores[k]??d),set:(k,v)=>{stores[k]=structuredClone(v)},escHtml:s=>String(s??'').replace(/[&<>"']/g,''),millProductIdentity:(base,stage,type)=>({displayName:[base,type,'Ready Rice'].filter(Boolean).join(' ')}),today:()=> '2026-09-25',nowText:()=> '2026-09-25 12:00',containerFormat:v=>v,formatTruckTyping:v=>v,formatTruckNo:v=>v,safeJsToken:encodeURIComponent,containerIdentity:v=>String(v).replace(/-/g,''),validContainer:v=>/^[A-Z]{4}\d{6}-\d$/.test(v),validTruckNo:v=>/^[A-Z]+-\d+$/.test(v),containerExists:()=>false,instructionSeenUser:()=> 'mill',instructionIsUnseen:()=>false,showNonBlockingMessage:()=>{},showContainerFeedback:message=>{throw new Error(message)},fetch:async()=>{accountsPosts++;return{ok:true,json:async()=>({ok:true,journal:{id:'J-1'}})}}});
w.TT_SHARED_SYNC={saveNow:async()=>{sharedSaves++}};
w.eval(`let exOpenKey='',exSelectedMill='Al-Harmain Rice Mills',editingExLoadId=null;${fn('defaultExMillSodas')}${fn('exMillSodas')}${fn('exMillInstructions')}${fn('exInstructionLoads')}${fn('exLoadIsComplete')}${fn('canCompleteExInstruction')}${fn('renderExMill')}${fn('renderExMillWorkspace')}${fn('toggleExInline')}${fn('renderExLoadForm')}${fn('editExMillLoad')}${fn('saveExMillLoad')}`);
w.renderExMillWorkspace();
const row=w.document.querySelector('[data-ex-instruction-id="91"]');assert.ok(row,'instruction row should render');
row.dispatchEvent(new w.MouseEvent('click',{bubbles:true}));
const box=w.document.getElementById('exinline_load_91'),input=w.document.getElementById('exCont_91');
assert.ok(input&&!box.classList.contains('hidden'),'clicking the lot should open its form underneath');
input.dispatchEvent(new w.MouseEvent('click',{bubbles:true}));input.value='ABCD123456-7';input.dispatchEvent(new w.Event('input',{bubbles:true}));
assert.ok(!box.classList.contains('hidden'),'clicking or typing in the form must not collapse the lot');
assert.equal(input.value,'ABCD123456-7','container entry must remain in the open form');
console.log('PASS Ex-Mill lot form stays open while container details are entered');

(async()=>{
  // Saving one field at a time must persist the same row, without an Accounts posting.
  w.document.getElementById('exTruck_91').value='KHI-123';
  await w.saveExMillLoad(91);
  assert.equal(stores.loads.length,1);
  assert.equal(stores.loads[0].truck,'KHI-123');
  assert.equal(stores.loads[0].serverPosted,false);
  assert.equal(accountsPosts,0);
  assert.equal(sharedSaves,1);
  assert.match(w.document.querySelector('[data-ex-load-id]').textContent,/KHI-123/);

  // A later amendment must update the stored row, not a detached copy.
  w.editExMillLoad(91,stores.loads[0].id);
  w.document.getElementById('exSeal_91').value='SEAL-1';
  await w.saveExMillLoad(91);
  assert.equal(stores.loads.length,1);
  assert.equal(stores.loads[0].truck,'KHI-123');
  assert.equal(stores.loads[0].seal,'SEAL-1');
  assert.equal(accountsPosts,0);
  assert.match(w.document.querySelector('[data-ex-load-id]').textContent,/SEAL-1/);

  // A refresh must retain both the open form and unsaved field values.
  w.editExMillLoad(91,stores.loads[0].id);
  w.document.getElementById('exBags_91').value='550';
  w.renderExMill();
  assert.ok(!w.document.getElementById('exinline_load_91').classList.contains('hidden'));
  assert.equal(w.document.getElementById('exBags_91').value,'550');

  // Only the complete row posts the purchase liability against the same SODA.
  w.document.getElementById('exWeight_91').value='27000';
  await w.saveExMillLoad(91);
  assert.equal(stores.loads.length,1);
  assert.equal(stores.loads[0].bags,550);
  assert.equal(stores.loads[0].kg,27000);
  assert.equal(stores.loads[0].liabilityJournalId,'J-1');
  assert.equal(accountsPosts,1);
  assert.equal(sharedSaves,3);
  console.log('PASS staged Ex-Mill amendments persist, refresh preserves the form, and Accounts posts only complete loads');
})().catch(error=>{console.error(error);process.exitCode=1});
