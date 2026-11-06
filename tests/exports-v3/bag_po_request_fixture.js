'use strict';
// Exercise the shipped form handler, then send its request through the HTTP test.
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const app=fs.readFileSync(require('node:path').join(__dirname,'../../exports/app.js'),'utf8');
const input=JSON.parse(fs.readFileSync(0,'utf8'));
const state=input.state,shipment=state.shipments.find(row=>row.id===input.shipmentId);
const requests=[],button={disabled:false};
const context=vm.createContext({
 state,structuredClone,console,Date,Map,Set,JSON,
 contractByRef:ref=>state.contracts.find(row=>row.ref===ref),
 shipment:()=>shipment,ensureBagDraft:()=>input.order,num:value=>Number(value)||0,
 bagSupplierMasterNames:()=>[{name:input.order.supplier}],
 document:{getElementById:()=>button},pendingFinalOrders:new Map(),bagOrderDrafts:new Map(),
 uid:()=>input.auditId,currentUser:()=> 'Exports test operator',
 documentAreaLabel:()=>'',exportActionBusy:false,
 alert:message=>{throw Error(message)},save:()=>{throw Error('PO must use its scoped save')},
 showExportSaveError:()=>{},
 window:{TT_MODULE_ACCESS:{user:'Exports test operator'},TT_SHARED_SYNC:{
  commitContractChanges:async(changes,matches,identity)=>{requests.push({changes,identity});throw Error('Captured request');}
 }},
});
const auditLine=app.split('\n').find(line=>line.startsWith('function audit__legacy_v0('));
vm.runInContext(auditLine+'\nconst audit=audit__legacy_v0;',context);
vm.runInContext(app.slice(app.indexOf('function exportWorkspaceChanges('),app.indexOf('async function commitScopedContractSave(')),context);
vm.runInContext(app.slice(app.indexOf('async function issuePO()'),app.indexOf('function purchaseOrderPrint__morning_base(')),context);
(async()=>{
 await vm.runInContext('issuePO()',context);
 assert.equal(requests.length,1);
 const history=requests[0].changes.filter(change=>change.path[0]==='audits');
 assert.equal(history.length,1,'Adding an audit must not rewrite historical rows');
 assert.equal(history[0].action,'prepend');
 assert.equal(history[0].value.length,1);
 // Retry must reuse the same PO, timestamp and history event.
 await vm.runInContext('issuePO()',context);
 assert.deepEqual(requests[1],requests[0]);
 process.stdout.write(JSON.stringify(requests[0]));
})().catch(error=>{console.error(error);process.exitCode=1});
