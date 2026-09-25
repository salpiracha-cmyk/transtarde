const assert=require('node:assert/strict');
const fs=require('node:fs');
const {JSDOM}=require('jsdom');

const source=fs.readFileSync('milling/Transtrade_Master_Milling_V3_3_2_AUDITED.html','utf8');
function fn(name){
  const start=source.indexOf('function '+name+'(');assert.ok(start>=0,'missing '+name);
  const body=source.indexOf('{',start);let depth=0,quote='',escaped=false;
  for(let i=body;i<source.length;i++){const c=source[i];if(quote){if(escaped)escaped=false;else if(c==='\\')escaped=true;else if(c===quote)quote='';continue}if(c==='"'||c==="'"||c==='`'){quote=c;continue}if(c==='{')depth++;else if(c==='}'&&--depth===0)return source.slice(start,i+1)}
  throw new Error('unterminated '+name);
}

const dom=new JSDOM('<div id="exMillName"></div><span id="exMillBalance"></span><div id="exMillShipmentRows"></div><div id="exMillSummaryRows"></div>',{runScripts:'dangerously'});
const w=dom.window,stores={
  loads:[],
  sodas:[{id:71,_ttBridge:'accounts-soda',_ttPurchaseSodaId:'ACC-1',qtyKg:270000}],
  instructions:[{id:91,_ttBridge:'exports',_ttBridgeId:'SHIP-1|C-1|LOT-01|0',localSodaId:71,sourceSodaId:'ACC-1',allocationStatus:'LINKED',mill:'Al-Harmain Rice Mills',brand:'ASAS',displayName:'PK-386 White Ready Rice',baseVariety:'PK-386',riceType:'White',contractRef:'C-1',lotRef:'LOT-01',shipmentId:'SHIP-1',totalContainers:10,instructionQtyKg:270000,bagTare:'110 g',dryon:'No',craft:'No',dpp:'Yes'}]
};
Object.assign(w,{STORE_EXLOAD:'loads',STORE_EXMILL:'sodas',STORE_EXINSTR:'instructions',get:(k,d)=>structuredClone(stores[k]??d),set:(k,v)=>{stores[k]=structuredClone(v)},escHtml:s=>String(s??'').replace(/[&<>"']/g,''),millProductIdentity:(base,stage,type)=>({displayName:[base,type,'Ready Rice'].filter(Boolean).join(' ')}),today:()=> '2026-09-25',containerFormat:v=>v,formatTruckTyping:v=>v,safeJsToken:encodeURIComponent,containerIdentity:v=>String(v).replace(/-/g,''),validContainer:v=>/^[A-Z]{4}\d{6}-\d$/.test(v),validTruckNo:v=>/^[A-Z]+-\d+$/.test(v)});
w.eval(`let exOpenKey='',exSelectedMill='Al-Harmain Rice Mills',editingExLoadId=null;${fn('exMillSodas')}${fn('exMillInstructions')}${fn('exInstructionLoads')}${fn('exLoadIsComplete')}${fn('canCompleteExInstruction')}${fn('renderExMillWorkspace')}${fn('toggleExInline')}${fn('renderExLoadForm')}`);
w.renderExMillWorkspace();
const row=w.document.querySelector('[data-ex-instruction-id="91"]');assert.ok(row,'instruction row should render');
row.dispatchEvent(new w.MouseEvent('click',{bubbles:true}));
const box=w.document.getElementById('exinline_load_91'),input=w.document.getElementById('exCont_91');
assert.ok(input&&!box.classList.contains('hidden'),'clicking the lot should open its form underneath');
input.dispatchEvent(new w.MouseEvent('click',{bubbles:true}));input.value='ABCD123456-7';input.dispatchEvent(new w.Event('input',{bubbles:true}));
assert.ok(!box.classList.contains('hidden'),'clicking or typing in the form must not collapse the lot');
assert.equal(input.value,'ABCD123456-7','container entry must remain in the open form');
console.log('PASS Ex-Mill lot form stays open while container details are entered');
