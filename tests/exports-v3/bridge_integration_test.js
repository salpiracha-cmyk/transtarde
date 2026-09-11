const fs=require('fs');
const vm=require('vm');
const assert=require('assert');
const php=fs.readFileSync(__dirname+'/../main/module.php','utf8');
assert.match(php,/'masters'=>tt_list_masters\(\)/,'Milling and Exports receive the canonical shared Super Admin masters');
const match=php.match(/<script id="tt-shared-operations-bootstrap">([\s\S]*?)<\/script>/);
assert.ok(match,'shared bridge bootstrap found');

class Storage{
  constructor(){this.data=new Map()}
  getItem(k){return this.data.has(k)?this.data.get(k):null}
  setItem(k,v){this.data.set(String(k),String(v))}
  removeItem(k){this.data.delete(String(k))}
  key(i){return [...this.data.keys()][i]||null}
  get length(){return this.data.size}
}
const localStorage=new Storage();
const root={contracts:[{ref:'TTI/NS/01',product:'IRRI-6 White Rice',packingUnit:'KG',quality:'Export quality',packings:[{brand:'STAR',size:25,type:'PP Bags',tare:80,containers:1,weightPer:27}]}],shipments:[{id:'P-1',kind:'process',contractRef:'TTI/NS/01',loading:{lots:[{lotId:'LOT-01',lotRecordId:'L-1'}]},millActuals:[]},{id:'L-1',kind:'lot',parentProcessId:'P-1',lotId:'LOT-01',contractRef:'TTI/NS/01',containers:1,plannedQty:27,millActuals:[]}],millSync:{newExportBags:[],productionInstructions:[],exportLoading:[{contractRef:'TTI/NS/01',shipmentId:'L-1',lotId:'LOT-01',production:{dryOn:'No',craftPaper:'No'},plan:{allocations:[{packIndex:0,name:'TTI Rice Mills',type:'TTI',containers:1,weightPer:27,emptyBags:0,dryOn:'Yes',craftPaper:'Yes',dpp:'Yes',inspection:'SGS'}]}}]},alerts:[]};
localStorage.setItem('transtrade_export_v3_operational',JSON.stringify(root));
localStorage.setItem('tt32exportsync','[]');
const listeners={};
const document={activeElement:null,body:{appendChild(){}},getElementById(){return null},createElement(){return{style:{},appendChild(){}}},addEventListener(){}};
let serverValues={transtrade_export_v3_operational:JSON.stringify(root)};
class XMLHttpRequest{open(method,url,async){this.async=async}send(){this.status=200;this.responseText=JSON.stringify({ok:true,revision:1,values:serverValues,meta:{}});if(this.onload)this.onload()}}
const resolved=v=>({then(fn){try{const next=fn(v);return next&&typeof next.then==='function'?next:resolved(next)}catch(error){return rejected(error)}},catch(){return this}}),rejected=error=>({then(){return this},catch(fn){return resolved(fn(error))}});
const posts=[];const context={window:{TT_MODULE_ACCESS:{csrf:'test',module:'Exports',moduleId:'exports'},TRANSTRADE_SERVER_NOW_ISO:''},Storage,localStorage,document,XMLHttpRequest,console,fetch:(url,options)=>{posts.push(JSON.parse(options.body));return resolved({json:()=>resolved({ok:true,revision:1,keyVersion:1})})},setTimeout:()=>0,clearTimeout(){},setInterval:()=>0,addEventListener:(n,fn)=>listeners[n]=fn,location:{reload(){}}};
context.window.localStorage=localStorage;context.window.document=document;context.window.Storage=Storage;context.globalThis=context;
vm.runInNewContext(match[1],context,{filename:'shared-bridge.js'});
assert.equal(listeners.DOMContentLoaded,undefined,'initial bridge must run before module application state loads');
const directInstruction=JSON.parse(localStorage.getItem('tt30ship'))[0];
directInstruction.containers=[{id:'C-1',container:'MSCU1234567',seal:'SL001',bags:1080,weight:27000,gate:'GP-9',truck:'TRK-1',date:'2026-09-08'},{id:'C-2',container:'MSCU7654321',seal:'SL002',bags:1080,weight:27000,gate:'GP-10',truck:'TRK-2',date:'2026-09-08'}];
localStorage.setItem('tt30ship',JSON.stringify([directInstruction]));
context.window.TT_SHARED_SYNC.bridge();context.window.TT_SHARED_SYNC.bridge();
context.window.TT_SHARED_SYNC.flush(); // explicit committed action; page load alone must never autosave
const millInstruction=JSON.parse(localStorage.getItem('tt30ship'))[0];
assert.equal(millInstruction.dryon,'Yes');
assert.equal(millInstruction.craft,'Yes');
assert.equal(millInstruction.inspection,'SGS');
const synced=JSON.parse(localStorage.getItem('transtrade_export_v3_operational'));
assert.equal(synced.shipments[0].millActuals.length,0,'process shell must not receive lot actuals');
assert.equal(synced.shipments[1].millActuals.length,1);
assert.equal(synced.shipments[1].millActuals[0].number,'MSCU123456-7');
assert.equal(synced.shipments[1].millActuals[0].gatePass,'GP-9');
assert.equal(synced.shipments[1].millActuals[0].source,'Milling');
assert.equal(synced.shipments[1].millActuals[0].shipmentId,'L-1');
assert.equal(synced.shipments[1].millActuals[0].contractRef,'TTI/NS/01');
assert.equal(synced.shipments[1].millActuals[0].lotRef,'LOT-01');
assert.equal(synced.shipments[1].millActuals[0].millNetKg,27000);
assert.equal(synced.shipments[1].millActuals[0].documentNetKg,27000);
assert.equal(synced.alerts.length,2,'repeated bridge polls must not multiply identical Mill Update or Container Limit alerts');
assert.deepEqual(Array.from(new Set(synced.alerts.map(x=>x.kind))).sort(),['Container Limit','Mill Update']);
const root2=structuredClone(root);root2.shipments.push({id:'L-2',kind:'lot',parentProcessId:'P-1',lotId:'LOT-02',contractRef:'TTI/NS/01',containers:1,plannedQty:27,millActuals:[]});root2.millSync.exportLoading.push({contractRef:'TTI/NS/01',shipmentId:'L-2',lotId:'LOT-02',production:{dryOn:'No',craftPaper:'No'},plan:{allocations:[{packIndex:0,name:'TTI Rice Mills',type:'TTI',containers:1,weightPer:27,emptyBags:0,dryOn:'No',craftPaper:'No',dpp:'Yes',inspection:'SGS'}]}});serverValues={transtrade_export_v3_operational:JSON.stringify(root2)};
assert.equal(typeof listeners.focus,'function','bridge must register a safe inbound check when staff return to a module tab');listeners.focus();
assert.equal(JSON.parse(localStorage.getItem('tt30ship')).filter(x=>x._ttBridge==='exports').length,2,'focus refresh must deliver a newly sent exact Loading Instruction without timed polling');
assert.ok(JSON.parse(localStorage.getItem('tt30ship')).some(x=>x._ttShipmentId==='L-2'&&x.contractRef==='TTI/NS/01'&&x._ttLotId==='LOT-02'),'inbound refresh preserves Shipment ID + Contract + Lot identity');
assert.ok(posts.length>0);assert.ok(posts.every(x=>Number.isInteger(x.baseVersion)));assert.ok(posts.every(x=>x.sourceModule==='Exports'));
console.log('PASS Export ⇄ Milling lot-reference bridge');
