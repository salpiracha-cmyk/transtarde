'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const src=fs.readFileSync('milling/Transtrade_Master_Milling_V3_3_2_AUDITED.html','utf8');
let assertions=0;
function ok(condition,name){assertions++;assert.ok(condition,name)}
function eq(value,want,name){assertions++;assert.deepEqual(value,want,name)}
function fn(name){
 const start=src.indexOf('function '+name+'(');assert.ok(start>=0,name);
 const body=src.indexOf('){',start)+1;let depth=0,quote='',escape=false;
 for(let i=body;i<src.length;i++){const c=src[i];if(quote){if(escape)escape=false;else if(c==='\\')escape=true;else if(c===quote)quote='';continue}if(c==='"'||c==="'"||c==='`'){quote=c;continue}if(c==='{')depth++;else if(c==='}'&&--depth===0)return src.slice(start,i+1)}throw Error('Unterminated '+name);
}
const page=src.split('<script>')[0];
for(const text of ['Ghati Statement','ghatiStatementBody','ghatiRate','Processing Reconciliation','name="completionMode"','id="shortProdBox"','id="excessBox"'])ok(!page.includes(text),'Mill markup has no '+text);
ok(src.includes('let editingQueueId=null'),'First arrival has initialized edit state');
ok(!src.includes('function postGhatiEvent('),'Mill cannot maintain the financial ledger');
ok(!src.includes('function applyPendingNilGainsToCurrentProduction('),'Management fixed rows cannot enter operational production');
ok(src.includes('id="prodShiftComplete"'),'Neutral all-lots-entered confirmation exists');
const stores={},elements={prodEntity:{value:'TTI'},prodShiftComplete:{checked:false},prodInputStock:{value:'IRRI-6 White Ready Rice'}};
const context={
 currentMill:{id:2,name:'Reprocessing Mill',type:'External Milling / Processing'},
 STORE_PROD:'prod',STORE_PRODAUDIT:'audit',STORE_SHIP:'ship',editingProdId:null,productionRows:[],
 get:(key,d)=>structuredClone(stores[key]??d),set:(key,v)=>{stores[key]=structuredClone(v)},
 document:{getElementById:id=>elements[id]??null},window:{},
 prodDate:{value:'2026-09-22'},prodShift:{value:'Day'},prodStart:{value:'06:00'},prodEnd:{value:'18:00'},prodVariety:{value:'IRRI-6 White Rice'},capacityReason:{value:''},
 calcProduction:()=>8,alert:msg=>{context.lastAlert=msg},confirm:()=>true,today:()=> '2026-09-22',
 validateProductionBagAvailability:()=>'',syncProductionBagIssues:()=>{},initProduction:()=>{},setShiftTimes:()=>{},
 renderRecentProduction:()=>{},renderProductionReport:()=>{},renderRecoveryReport:()=>{},renderProcessingExpenses:()=>{},
};
vm.createContext(context);
for(const name of ['millBaseVariety','millRiceType','millProductIdentity','isReprocessingMill','stockEntityForView','operationalStockScope','isOperationalProductionRow','saveProduction'])vm.runInContext(fn(name),context);
context.productionRows=[{product:'Ready Rice — ASAS',bags:500,bagWeight:50}];context.saveProduction();
let p=stores.prod[0];eq(p.inputStage,'READY','Rework retains Ready input');eq(p.inputStockName,'IRRI-6 White Ready Rice','Rework retains actual source stock');eq(p.millName,'Reprocessing Mill','Production writer retains location');eq(p.entity,'TTI','Production writer retains company');eq(p.shiftEntriesComplete,false,'First partial lot is not a complete shift');
stores.prod=[];context.currentMill={id:1,name:'TTI Rice Mills',type:'Own Mill'};context.productionRows=[{product:'Ready Rice — ASAS',bags:500,bagWeight:50}];elements.prodShiftComplete.checked=true;context.saveProduction();
p=stores.prod[0];eq(p.inputStage,'RAW','Normal processing retains Raw input');eq(p.inputStockName,'IRRI-6 White Raw Rice','Raw input retains Rice Type');eq(p.shiftEntriesComplete,true,'Final-lot control persists to server');
context.productionRows=[{product:'Ready Rice — ASAS',bags:10,bagWeight:50},{product:'Last loading excess',systemFixed:true,noStockPost:true,bags:100,bagWeight:50}];context.saveProduction();
eq(stores.prod.at(-1).rows.length,1,'Historical management row cannot become new production');
// Exercise original loading save with no finished stock and no production entries.
stores.prod=[];stores.ship=[{id:101,status:'Loading',containers:[],audit:[],totalContainers:1,bagsPerContainer:500}];
Object.assign(context,{currentShipment:101,containerCommitPending:null,editingContainerId:null,defaultShipments:()=>[],
 clearContainerFeedback:()=>{},showContainerFeedback:msg=>{throw Error(msg)},containerFormat:s=>s,validContainer:()=>true,containerExists:()=>false,formatTruckNo:s=>s,validTruckNo:()=>true,
 showContainerShortPrompt:()=>{throw Error('Unexpected short instruction')},nowText:()=> '22-09-2026 10:00',syncContainerToExport:()=>{},confirmContainerSharedSave:x=>{context.sent=x},
 computedStockRows:()=>{throw Error('Loading must not be blocked by an unentered production report')},
 contNo:{value:'TGHU1234567'},contTruck:{value:'QA-101'},contSeal:{value:'S1'},contBags:{value:'500'},contWeight:{value:'25000'},contDriver:{value:''},contPhone:{value:''},contDate:{value:'2026-09-22'},emptyBagTick:{checked:false},emptyBagQty:{value:''},shortShipment:{checked:false}});
for(const name of ['shipmentLoadedBags','shipmentAskedBags','saveContainerOnly'])vm.runInContext(fn(name),context);
context.saveContainerOnly();eq(stores.ship[0].containers[0].weight,25000,'Actual loading saves before production is recorded');eq(stores.prod.length,0,'Loading cannot fabricate production');ok(!!context.sent,'Actual loading still follows server acknowledgement');ok(!Object.keys(stores).some(k=>/ghati|nilqueue|processingrecon/.test(k)),'Loading writes no financial Ghati store');
console.log(`PASS inventory reconciliation client: ${assertions} assertions`);
