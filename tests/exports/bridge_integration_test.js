const fs=require('fs');
const vm=require('vm');
const assert=require('assert');
const php=fs.readFileSync(__dirname+'/../main/module.php','utf8');
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
const root={contracts:[{ref:'TTI/NS/01',product:'IRRI-6 White Rice',packingUnit:'KG',quality:'Export quality',packings:[{brand:'STAR',size:25,type:'PP Bags',tare:80,containers:1,weightPer:27}]}],shipments:[{id:'S-1',contractRef:'TTI/NS/01',loading:{lots:[{lotId:'LOT-01'}]},millActuals:[]}],millSync:{newExportBags:[],productionInstructions:[],exportLoading:[{contractRef:'TTI/NS/01',lotId:'LOT-01',production:{},plan:{allocations:[{packIndex:0,name:'TTI Rice Mills',type:'TTI',containers:1,weightPer:27,emptyBags:0}]}}]},alerts:[]};
localStorage.setItem('transtrade_export_v2_operational',JSON.stringify(root));
localStorage.setItem('tt32exportsync',JSON.stringify([{shipment:'LOT-01',container:'MSCU1234567',seal:'SL001',bags:1080,weight:27000,brand:'STAR',gate:'GP-9',truck:'TRK-1',date:'2026-09-08'}]));
const listeners={};
const document={activeElement:null,body:{appendChild(){}},getElementById(){return null},createElement(){return{style:{},appendChild(){}}},addEventListener(){}};
class XMLHttpRequest{open(){}send(){this.status=200;this.responseText=JSON.stringify({ok:true,revision:0,values:{},meta:{}});if(this.onload)this.onload()}}
const context={window:{TT_MODULE_ACCESS:{csrf:'test'},TRANSTRADE_SERVER_NOW_ISO:''},Storage,localStorage,document,XMLHttpRequest,console,fetch:()=>Promise.resolve({json:()=>Promise.resolve({ok:true,revision:1})}),setTimeout:()=>0,clearTimeout(){},setInterval:()=>0,addEventListener:(n,fn)=>listeners[n]=fn,location:{reload(){}}};
context.window.localStorage=localStorage;context.window.document=document;context.window.Storage=Storage;context.globalThis=context;
vm.runInNewContext(match[1],context,{filename:'shared-bridge.js'});
listeners.DOMContentLoaded();
const synced=JSON.parse(localStorage.getItem('transtrade_export_v2_operational'));
assert.equal(synced.shipments[0].millActuals.length,1);
assert.equal(synced.shipments[0].millActuals[0].number,'MSCU123456-7');
assert.equal(synced.shipments[0].millActuals[0].gatePass,'GP-9');
assert.equal(synced.shipments[0].millActuals[0].source,'Milling');
console.log('PASS Export ⇄ Milling lot-reference bridge');
