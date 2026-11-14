'use strict';
const fs=require('fs'),vm=require('vm'),assert=require('node:assert/strict');
const source=fs.readFileSync('milling/Transtrade_Master_Milling_V3_3_2_AUDITED.html','utf8');
const functions=source.slice(source.indexOf('function recentProductionLinks('),source.indexOf('function toggleRecentProduction('));
const particulars=source.slice(source.indexOf('function pettyLedgerParticulars('),source.indexOf('function renderPettyLedger('));
const shifts=[
 {id:1,date:'2026-11-13',entity:'TTI',mill:'M1',rows:[{product:'Ready Rice — Closed',bags:1,shipmentId:'L1',contractRef:'C1'}]},
 {id:2,date:'2026-11-13',entity:'TTI',mill:'M1',rows:[{product:'Ready Rice — Open',bags:1,shipmentId:'L2',contractRef:'C1'}]},
 {id:3,date:'2026-11-13',entity:'TTI',mill:'M1',rows:[{product:'Ready Rice — Mixed',bags:1,shipmentId:'L1'},{product:'Ready Rice — Keep',bags:1,shipmentId:'L2'}]},
 {id:4,date:'2026-11-13',entity:'BRM',mill:'M1',rows:[{product:'Ready Rice — OtherCompany',bags:1}]},
 {id:5,date:'2026-11-13',entity:'TTI',mill:'M2',rows:[{product:'Ready Rice — OtherMill',bags:1}]},
 {id:6,date:'2026-11-15',entity:'TTI',mill:'M1',rows:[{product:'Ready Rice — Future',bags:1}]},
 {id:7,date:'2026-11-01',entity:'TTI',mill:'M1',rows:[{product:'Ready Rice — Old',bags:1}]},
 {id:8,date:'2026-11-13',entity:'TTI',mill:'M1',rows:[{product:'Ready Rice — Finished',bags:1,contractRef:'C2'}]},
 {id:9,date:'2026-11-13',entity:'TTI',mill:'M1',rows:[{product:'Ready Rice — Unknown',bags:1,contractRef:'MISSING'}]}
];
const exportsData={contracts:[{ref:'C1',closed:false},{ref:'C2',status:'Completed'}],shipments:[{id:'L1',kind:'process',contractRef:'C1',completed:true},{id:'L2',contractRef:'C1',closed:'false'}]};
const snapshot=JSON.stringify({shifts,exportsData});
const context={window:{TT_MILL_DELETE:{button:(_,id)=>`DELETE-${id}`}},productionRecent:{innerHTML:''},prodEntity:{value:'TTI'},STORE_PROD:'prod',today:()=> '2026-11-14',get:k=>k==='prod'?shifts:exportsData,stockScopeMatches:(r,e)=>r.entity===e&&r.mill==='M1',escHtml:v=>String(v??'').replaceAll('&','&amp;').replaceAll('<','&lt;')};
context.window.productionRecent=context.productionRecent;vm.createContext(context);vm.runInContext(functions+particulars,context);context.renderRecentProduction();
const html=context.productionRecent.innerHTML;for(const name of ['Open','Keep','Unknown','DELETE-2','loadProductionForEdit(2)'])assert(html.includes(name),name);
for(const name of ['Closed','OtherCompany','OtherMill','Future','Old','Finished','Mixed'])assert(!html.includes(name),name);
assert.equal(JSON.stringify({shifts,exportsData}),snapshot,'Read-only rendering must retain history');
context.prodEntity.value='BRM';context.renderRecentProduction();assert(context.productionRecent.innerHTML.includes('OtherCompany'));assert(!context.productionRecent.innerHTML.includes('Unknown'));
const row={type:'Milling Expense',payee:'<Electrician>',expensePurposes:['Motor repair','Wiring'],ref:'V-1',narration:'Repair machine',credit:100,debit:0};const before=JSON.stringify(row),text=context.pettyLedgerParticulars(row);
for(const s of ['Paid to: &lt;Electrician>','Motor repair / Wiring','Reference: V-1','Narration: Repair machine'])assert(text.includes(s),s);
assert.equal(JSON.stringify(row),before);assert(context.pettyLedgerParticulars({type:'Receipt',payee:'Office',debit:100}).includes('Received from: Office'));
console.log('PASS scoped recent production, mixed/open lots, unknown history, dates, preserved actions, escaped full cash particulars and no mutations');
