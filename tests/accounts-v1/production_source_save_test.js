const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const root=path.join(__dirname,'../..'),storage=new Map();let records=[],version=0,posts=[],reads=0,loseAck=false,conflict=false,alerts=[];
const w={TT_MODULE_ACCESS:{userId:7,module:'Mill',moduleId:'milling',csrf:'fixture'}};
const context=vm.createContext({window:w,document:{querySelector:()=>null,activeElement:null},localStorage:{getItem:k=>storage.get(k)??null,setItem:(k,v)=>storage.set(k,v),removeItem:k=>storage.delete(k)},alert:s=>alerts.push(s),crypto:require('node:crypto').webcrypto,Date,JSON,Set,Map,console});
context.fetch=async(url,options={})=>{
 if(options.method!=='POST'){reads++;return {ok:true,json:async()=>({ok:true,values:{tt30prod:JSON.stringify(records)},meta:{tt30prod:{version}}})};}
 const body=JSON.parse(options.body);posts.push(body);
 if(conflict){conflict=false;version++;records.push({id:999,reason:'Another user saved this report'});}
 if(body.baseVersion!==version)return {ok:false,status:409,json:async()=>({ok:false,conflict:true})};
 records=JSON.parse(body.value).map((r,i)=>({...r,createdAt:r.createdAt||'2026-10-17T12:00:00Z',updatedAt:'2026-10-17T12:00:00Z',serverSequence:r.serverSequence||i+1}));version++;
 if(loseAck){loseAck=false;throw Error('Connection lost after commit');}
 return {ok:true,json:async()=>({ok:true,value:JSON.stringify(records),keyVersion:version,related:{tt36bagissues:{value:'[]',version:1}}})};
};
vm.runInContext(fs.readFileSync(path.join(root,'accounts/production-source-save.js'),'utf8'),context);
const record={id:100,entity:'TTI',millId:1,millName:'TTI Rice Mills',date:'2026-10-17',shift:'Day',rows:[{product:'Ready Rice — ASAS',bags:10,bagWeight:50}],rawInputs:[{grossKg:500}],clientSaveRequestId:'production-save-original-0001'},input={endpoint:'api/operations.mysql.php',access:w.TT_MODULE_ACCESS,record};
(async()=>{
 assert.equal(reads,0,'Loading source helper refreshed transaction data');
 loseAck=true;await assert.rejects(w.TT_PRODUCTION_SOURCE_SAVE.commit(input),/Connection lost/);assert.equal(records.length,1);
 let result=await w.TT_PRODUCTION_SOURCE_SAVE.commit(input);assert.equal(result.replayed,true);assert.equal(posts.length,1,'Lost acknowledgement caused a second write');
 await assert.rejects(w.TT_PRODUCTION_SOURCE_SAVE.commit({...input,record:{...record,rawInputs:[{grossKg:600}]}}),/different facts/);
 const before=JSON.parse(JSON.stringify(result.record)),edit={...before,rawInputs:[{grossKg:600}],clientSaveRequestId:'production-save-amended-0002'};
 result=await w.TT_PRODUCTION_SOURCE_SAVE.commit({...input,record:edit,before});assert.equal(result.record.rawInputs[0].grossKg,600);assert.equal(records.length,1);
 await assert.rejects(w.TT_PRODUCTION_SOURCE_SAVE.commit({...input,record:{...edit,clientSaveRequestId:'production-save-concurrent-0003'},before}),/changed since/);
 records=[];version=0;posts=[];conflict=true;await assert.rejects(w.TT_PRODUCTION_SOURCE_SAVE.commit(input),/Another production/);
 result=await w.TT_PRODUCTION_SOURCE_SAVE.commit(input);assert.equal(records.length,2);assert.equal(records[0].id,999);assert.equal(posts[1].baseVersion,1);
 // The actual bootstrap adapter acknowledges only this production and its atomic derivatives.
 const module=fs.readFileSync(path.join(root,'module.php'),'utf8'),start=module.indexOf('  async function commitProduction('),end=module.indexOf('  async function appendMillBill(',start);
 vm.runInContext(`const access=window.TT_MODULE_ACCESS,endpoint='api/operations.mysql.php',pending=new Map([['tt38kebills','Unfinished KE draft']]),inFlight=new Set(),committedValues=new Map(),receipts=new Map(),keyVersions=new Map(),remoteKeys=new Set();let saveHolds=0;const waiting=()=>{},directSet=(k,v)=>localStorage.setItem(k,v);${module.slice(start,end)}window.TT_SHARED_SYNC={commitProduction};`,context);
 records=[];version=0;posts=[];await w.TT_SHARED_SYNC.commitProduction(record);assert.equal(posts.length,1);assert.equal(posts[0].key,'tt30prod');assert.equal(vm.runInContext('pending.get("tt38kebills")',context),'Unfinished KE draft');assert.equal(storage.has('tt38kebills'),false);assert.equal(storage.get('tt36bagissues'),'[]');
 vm.runInContext('pending.set("tt36bagissues","Unfinished packing change")',context);await assert.rejects(w.TT_SHARED_SYNC.commitProduction(record),/unfinished local/);vm.runInContext('pending.delete("tt36bagissues")',context);
 // Failed explicit Save retains one durable identity and invokes no reset callback.
 let attempts=[],fail=true,resets=0;w.TT_SHARED_SYNC.commitProduction=async(rec,before)=>{attempts.push(JSON.parse(JSON.stringify({rec,before})));if(fail)throw Error('Network unavailable');return rec;};
 await w.TT_PRODUCTION_SOURCE_SAVE.save(record,()=>resets++);assert.equal(resets,0);assert.ok([...storage.keys()].some(k=>k.startsWith('tt_ui_production_pending_7_')));
 fail=false;await w.TT_PRODUCTION_SOURCE_SAVE.save({...record,id:555},()=>resets++);assert.equal(resets,1);assert.deepEqual(attempts[0],attempts[1],'Production retry changed its stable identity or original intent');
 // A late acknowledgement must not clear a production form reopened meanwhile.
 let finish;w.TT_SHARED_SYNC.commitProduction=()=>new Promise(resolve=>{finish=resolve;});
 const waiting=w.TT_PRODUCTION_SOURCE_SAVE.save({...record,date:'2026-10-16'},()=>resets++);await Promise.resolve();w.TT_PRODUCTION_SOURCE_SAVE.remember({id:200});finish(record);await waiting;assert.equal(resets,1,'Late save acknowledgement cleared a reopened form');
 // A definitive refusal permits correction of this source, never another report.
 context.editingProdId=200;w.TT_PRODUCTION_SOURCE_SAVE.remember({...record,id:200});
 let refused=0;w.TT_SHARED_SYNC.commitProduction=async()=>{refused++;throw Object.assign(Error('Validation refused'),{editable:true});};
 await w.TT_PRODUCTION_SOURCE_SAVE.save({...record,id:200},()=>resets++);assert.equal(refused,1);
 context.editingProdId=201;w.TT_PRODUCTION_SOURCE_SAVE.remember({...record,id:201});await w.TT_PRODUCTION_SOURCE_SAVE.save({...record,id:201},()=>resets++);assert.equal(refused,1,'A corrected retry was redirected to a different production source');
 const native=fs.readFileSync(path.join(root,'milling/Transtrade_Master_Milling_V3_3_2_AUDITED.html'),'utf8').split('\n').find(l=>l.startsWith('async function saveProduction('));
 assert.ok(native.includes('await window.TT_PRODUCTION_SOURCE_SAVE.save(rec,'));assert.ok(!native.includes('set(STORE_')&&!native.includes('syncProductionBagIssues(rec)')&&!native.includes('Directors + Master Admin alerted'));
 console.log('PASS production-only acknowledged save: exact lost-ack replay, edit baseline/concurrency holds, isolated cache, stable durable intent, retained failed form and no premature native reset or derivative write');
})().catch(e=>{console.error(e);process.exitCode=1;});
