const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync('module.php','utf8');
const root={contracts:[{ref:'C1',product:'Rice',packings:[{brand:'A',size:50}]}],shipments:[{id:'S1',kind:'lot',contractRef:'C1',lotId:'L1',containers:2}],millSync:{exportLoading:[{contractRef:'C1',shipmentId:'S1',lotId:'L1',production:{dryOn:'Yes',craftPaper:'Yes',inspection:'SGS',dpp:'Yes'},plan:{allocations:[{name:'TTI Rice Mills',type:'Mill',packIndex:0,containers:1,weightPer:20,_ttAllocationIndex:1}]}}]}};
const old={id:'CARD-KEEP',_ttBridge:'exports',_ttBridgeId:'S1|C1|L1|1',_ttBridgeIndex:1,contractRef:'C1',_ttLotId:'L1',ref:'L1',containers:[{container:'ABCD1234567',bags:20,weight:1000}]};
const values=new Map([['root',root],['tt30ship',[old]],['tt30bags',[{id:'RECEIPT-KEEP',_ttBridge:'exports',received:20}]]]);
const context={access:{moduleId:'exports'},EXPORT_STORE:'root',parse:(k,f)=>values.get(k)??f,put:(k,v)=>values.set(k,v),stableId:k=>'NEW-'+k,cornContract:()=>false,saveNow:async()=>{},setTimeout,console,Date,Map,Set,Number,String};vm.createContext(context);
vm.runInContext(source.slice(source.indexOf('  function exportsToMill(){'),source.indexOf('  function millToExports(){')),context);context.exportsToMill();
assert.equal(values.get('tt30bags')[0].id,'RECEIPT-KEEP','A hidden bag instruction family must not delete acknowledged receipts');
const rows=values.get('tt30ship');assert.equal(rows.length,1);assert.equal(rows[0].id,'CARD-KEEP');assert.equal(rows[0]._ttBridgeIndex,1);assert.equal(rows[0]._ttBridgeId,'S1|C1|L1|1');assert.deepEqual(rows[0].containers,old.containers);
assert.equal(rows[0].dryon,'Yes');assert.equal(rows[0].craft,'Yes');assert.equal(rows[0].inspection,'SGS');assert.equal(rows[0].dpp,'Yes');
console.log('PASS scoped allocation retains canonical bridge identity, committed containers and inherited physical instructions');

(async()=>{
 const snapshot={ok:true,revision:11,values:{tt30queue:'[]',tt30slips:'[]',tt30directoralerts:'[]',tt30ship:'SERVER-SHIP',transtrade_export_v3_operational:'SERVER-ROOT'},meta:{tt30queue:{version:3},tt30ship:{version:5}},restrictedKeys:['tt34ghati']};
 let requests=0,applied=null,serverFailure=false,duringRead=null;
 const pending=new Map([['transtrade_export_v3_operational','KEEP-STAGED-EXPORT'],['tt30ship','KEEP-STAGED-SHIP']]),inFlight=new Set();
 const refreshContext={pending,inFlight,endpoint:'/operations',allowed:key=>/^tt[0-9]{2}[a-z0-9_]{2,60}$/.test(key)&&key!=='tt34ghati',fetch:async url=>{requests++;assert.match(url,/asset_refs=1/);return {json:async()=>{duringRead?.();return serverFailure?{ok:false,error:'Read failed'}:snapshot}}},applyRemote:data=>{applied=data},Promise,Error,Set,Object,Date};
 vm.createContext(refreshContext);vm.runInContext(source.slice(source.indexOf('  function refreshNow('),source.indexOf('  const legacyToken=',source.indexOf('  function refreshNow('))),refreshContext);
 await assert.rejects(refreshContext.refreshNow(),/Finish the current save/);assert.equal(requests,0);
 const result=await refreshContext.refreshNow({keys:['tt30queue','tt30slips','tt30directoralerts']});
 assert.deepEqual(Object.keys(result.values).sort(),['tt30directoralerts','tt30queue','tt30slips']);assert.deepEqual(Object.keys(applied.meta),['tt30queue']);assert.equal(applied.restrictedKeys.length,0);assert.equal(applied.values.tt30ship,undefined);assert.equal(applied.values.transtrade_export_v3_operational,undefined);assert.equal(pending.get('tt30ship'),'KEEP-STAGED-SHIP');assert.equal(pending.get('transtrade_export_v3_operational'),'KEEP-STAGED-EXPORT');assert.equal(pending.size,2);
 pending.set('tt30queue','KEEP-UNCONFIRMED-ARRIVAL');await assert.rejects(refreshContext.refreshNow({keys:['tt30queue']}),/Finish the current save/);assert.equal(pending.get('tt30queue'),'KEEP-UNCONFIRMED-ARRIVAL');pending.delete('tt30queue');
 inFlight.add('tt30ship');await assert.rejects(refreshContext.refreshNow({keys:['tt30queue']}),/Finish the current save/);inFlight.clear();
 await assert.rejects(refreshContext.refreshNow({keys:[]}),/invalid/);await assert.rejects(refreshContext.refreshNow({keys:['tt34ghati']}),/invalid/);
 duringRead=()=>pending.set('tt30queue','EDIT-DURING-REFRESH');applied=null;await assert.rejects(refreshContext.refreshNow({keys:['tt30queue']}),/Finish the current save/);assert.equal(applied,null);assert.equal(pending.get('tt30queue'),'EDIT-DURING-REFRESH');pending.delete('tt30queue');duringRead=null;
 serverFailure=true;applied=null;await assert.rejects(refreshContext.refreshNow({keys:['tt30queue']}),/Read failed/);assert.equal(applied,null);assert.equal(pending.size,2);
 console.log('PASS scoped Arrival report refresh preserves unrelated staged bridge data, rejects edited sources/in-flight saves and applies no failed or unrelated snapshot');
})().catch(error=>{console.error(error);process.exitCode=1});

{
 // Arrival/Pohanch sources are not inputs of the Exports display bridge.
 // Their delivery must redraw consumers without rebuilding another icon's draft.
 const local=new Map([['tt30ship','KEEP-STAGED-SHIP']]),pending=new Map([['tt30ship','KEEP-STAGED-SHIP']]),committedValues=new Map(),receipts=new Map(),keyVersions=new Map(),remoteKeys=new Set();let bridges=0,notified=[];
 const c={LOCAL_ONLY_STORES:new Set(),localStorage:{getItem:key=>local.get(key),removeItem:key=>local.delete(key)},pending,remoteKeys,keyVersions,committedValues,receipts,inFlight:new Set(),exportWorkspaceBusy:()=>false,deferInbound:()=>{throw Error('Unexpected defer')},applying:false,revision:0,lastRemoteBy:'',allowed:()=>true,directSet:(key,value)=>local.set(key,value),window:{},bridge:()=>bridges++,notifyRemote:keys=>notified.push(...keys),Number,Math,Object};
 vm.createContext(c);vm.runInContext(source.slice(source.indexOf('  function applyRemote('),source.indexOf('  function settleCommits(',source.indexOf('  function applyRemote('))),c);
 c.applyRemote({ok:true,revision:5,values:{tt30queue:'[{"id":1}]',tt30slips:'[]',tt30ship:'DO-NOT-REPLACE'},meta:{tt30queue:{version:4},tt30ship:{version:2}}},false);
 assert.equal(bridges,0,'Arrival delivery rebuilt unrelated display bridge drafts');assert.equal(local.get('tt30ship'),'KEEP-STAGED-SHIP');assert.equal(pending.get('tt30ship'),'KEEP-STAGED-SHIP');assert(notified.includes('tt30queue'));assert.equal(committedValues.get('tt30queue'),'[{"id":1}]');assert.equal(keyVersions.get('tt30queue'),4);
 c.applyRemote({ok:true,revision:6,values:{tt30bags:'[{"id":2}]'},meta:{tt30bags:{version:1}}},false);
 assert.equal(bridges,1,'A real bridge input lost its existing reconciliation');assert(notified.includes('tt30bags'));assert.equal(local.get('tt30ship'),'KEEP-STAGED-SHIP');
 console.log('PASS incoming Arrival/Pohanch redraw skips unrelated display-bridge rebuild; other bridge inputs keep existing reconciliation');
}
