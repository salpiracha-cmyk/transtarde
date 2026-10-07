'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(require('node:path').join(__dirname,'../../module.php'),'utf8').match(/<script id="tt-shared-operations-bootstrap">([\s\S]*?)<\/script>/)[1];
const STORE='transtrade_export_v3_operational';
const tick=async()=>{for(let i=0;i<12;i++)await Promise.resolve()};
function fixture({quota=false,initial='{"shipments":[]}',moduleId='exports'}={}){
 const classes=new Set(),requests=[],timers=[],listeners={};
 const c=vm.createContext({console:{error(){},log(){}},Date,JSON,Map,Set,Promise,Error,Number,String,Math,CustomEvent:class{constructor(type,options){this.type=type;this.detail=options?.detail}},setTimeout:fn=>{timers.push(fn);return timers.length},clearTimeout(){},dispatchEvent:event=>(listeners[event.type]||[]).forEach(fn=>fn(event)),addEventListener:(type,fn)=>(listeners[type]??=[]).push(fn),document:{documentElement:{classList:{add:k=>classes.add(k),remove:k=>classes.delete(k),contains:k=>classes.has(k),toggle:(k,on)=>on?classes.add(k):classes.delete(k)}},addEventListener(){},hidden:false},TT_MODULE_ACCESS:{moduleId,module:moduleId==='exports'?'Exports':'Milling',csrf:'QA'}});
 vm.runInContext(`window=globalThis;class TestStorage{constructor(){this.values=new Map();this.quota=false}getItem(k){return this.values.get(String(k))??null}setItem(k,v){if(this.quota){const e=new Error('full');e.name='QuotaExceededError';throw e}this.values.set(String(k),String(v))}removeItem(k){this.values.delete(String(k))}clear(){this.values.clear()}};Storage=TestStorage;localStorage=new Storage();sessionStorage=new Storage();`,c);
 c.localStorage.quota=quota;
 c.XMLHttpRequest=class{open(){}send(){this.status=200;this.responseText=JSON.stringify({ok:true,revision:1,values:{[STORE]:initial},meta:{[STORE]:{version:1}}})}};
 c.fetch=(url,opts)=>new Promise((resolve,reject)=>requests.push({body:JSON.parse(opts.body),resolve,reject}));
 vm.runInContext(source,c);
 return{c,requests,timers,classes,ack:(n,value)=>{const r=requests[n];r.resolve({ok:true,json:async()=>({ok:true,revision:n+2,keyVersion:n+2,value:value??r.body.value})})}};
}
(async()=>{
 let f=fixture({quota:true});assert.equal(f.c.localStorage.getItem(STORE),'{"shipments":[]}');
 const a='{"shipments":[{"id":"L1","completed":true}]}';f.c.localStorage.setItem(STORE,a);let done=false;
 let p=f.c.TT_SHARED_SYNC.saveNow({key:STORE,value:a}).then(()=>done=true);assert.equal(f.requests[0].body.value,a);assert.equal(f.c.localStorage.getItem(STORE),a);assert.equal(f.c.TT_SHARED_SYNC.readCommitted(STORE),'{"shipments":[]}');f.ack(0);await p;assert.equal(done,true);assert.equal(f.c.TT_SHARED_SYNC.readCommitted(STORE),a);
 console.log('PASS initial inbound and staged quota fallback share one reader and durable acknowledgement');
 f=fixture();f.c.localStorage.setItem(STORE,a);f.c.TT_SHARED_SYNC.flush();const b='{"shipments":[{"id":"L1","completed":true,"reason":"newer"}]}';f.c.localStorage.setItem(STORE,b);done=false;p=f.c.TT_SHARED_SYNC.saveNow({key:STORE,value:b}).then(()=>done=true);f.ack(0);await tick();assert.equal(done,false);assert.equal(f.requests.length,2);assert.equal(f.requests[1].body.baseVersion,2);assert.equal(f.requests[1].body.value,b);f.ack(1);await p;assert.equal(done,true);
 console.log('PASS older acknowledgement cannot satisfy newer pending write; newer write rebases after acknowledgement');
 f=fixture();f.c.localStorage.setItem(STORE,a);p=f.c.TT_SHARED_SYNC.saveNow({key:STORE,value:a});const canonical=JSON.stringify({shipments:[{id:'L1',completed:true},{id:'L2',completed:false}]});f.ack(0,canonical);await p;assert.equal(f.c.TT_SHARED_SYNC.readCommitted(STORE),canonical);
 console.log('PASS packaging reads canonical merged server snapshot rather than transient cache');
 for(const mode of ['network','conflict','session']){
  f=fixture();f.c.localStorage.setItem(STORE,a);p=f.c.TT_SHARED_SYNC.saveNow({key:STORE,value:a});const rejected=assert.rejects(p);if(mode==='network')f.requests[0].reject(new Error('lost'));else f.requests[0].resolve({ok:false,json:async()=>({ok:false,conflict:mode==='conflict',error:'Session expired'})});await rejected;
  assert.equal(f.c.TT_SHARED_SYNC.readCommitted(STORE),'{"shipments":[]}');assert.equal(f.c.localStorage.getItem(STORE),a);assert.equal(f.requests.length,1);
  assert.equal(f.c.TT_SHARED_SYNC.restoreStaged(STORE,'{"shipments":[]}',a),true);await f.c.TT_SHARED_SYNC.saveNow();assert.equal(f.requests.length,1);
 }
 console.log('PASS connection, conflict and authentication failures reject; local rollback never posts an old root');
 f=fixture();f.c.localStorage.setItem(STORE,a);p=f.c.TT_SHARED_SYNC.saveNow({key:STORE,value:a});const rejected=assert.rejects(p,/not confirmed/);f.timers[0]();await rejected;f.c.TT_SHARED_SYNC.restoreStaged(STORE,'{"shipments":[]}',a);f.ack(0);await tick();assert.equal(f.c.TT_SHARED_SYNC.readCommitted(STORE),a);assert.equal(f.requests.length,1);
 console.log('PASS timeout then late commit retains receipt without automatically reopening server lot');
 f=fixture();const release=f.c.TT_SHARED_SYNC.hold();assert.equal(f.classes.has('tt-save-waiting'),true);await f.c.TT_SHARED_SYNC.saveNow();assert.equal(f.classes.has('tt-save-waiting'),true);release();assert.equal(f.classes.has('tt-save-waiting'),false);
 f.c.localStorage.setItem('archiveMarker','new');f.c.localStorage.removeItem('archiveMarker');assert.equal(f.c.localStorage.getItem('archiveMarker'),null);
 assert.throws(()=>{f.c.sessionStorage.quota=true;f.c.sessionStorage.setItem('x','y')},/full/);
 console.log('PASS pipeline hold releases correctly and non-local storage keeps native errors');
 f=fixture({moduleId:'milling',quota:true});f.c.localStorage.setItem('tt30bags','[{"id":1}]');p=f.c.TT_SHARED_SYNC.saveNow();f.ack(0);await p;assert.equal(f.c.TT_SHARED_SYNC.readCommitted('tt30bags'),'[{"id":1}]');assert.equal(f.requests[0].body.sourceModule,'Milling');
 console.log('PASS Milling storage keys retain acknowledged save semantics');
 for(const [cash,source] of [['tt30petty','tt37usedbags'],['tt33pettyexp','tt37processingexpenses']]){
  f=fixture({moduleId:'milling'});f.c.localStorage.setItem(cash,'[{"id":2}]');f.c.localStorage.setItem(source,'[{"id":1}]');
  p=f.c.TT_SHARED_SYNC.saveNow();assert.equal(f.requests.length,1);assert.equal(f.requests[0].body.key,source);
  f.ack(0);await tick();assert.equal(f.requests.length,2);assert.equal(f.requests[1].body.key,cash);f.ack(1);await p;
  f=fixture({moduleId:'milling'});f.c.localStorage.setItem(cash,'[{"id":2}]');f.c.localStorage.setItem(source,'[{"id":1}]');
  p=f.c.TT_SHARED_SYNC.saveNow();const denied=assert.rejects(p);f.requests[0].resolve({ok:false,json:async()=>({ok:false,error:'Source rejected'})});await denied;
  assert.equal(f.requests.length,1,'Failed source must not post linked cash');
 }
 console.log('PASS Milling linked cash waits for acknowledged source and rejects when that source fails');
 f=fixture();f.c.localStorage.setItem('tt40exportreceipts','[{"id":"CACHE"}]');await f.c.TT_SHARED_SYNC.saveNow();assert.equal(f.requests.length,0);assert.equal(f.c.localStorage.getItem('tt40exportreceipts'),'[{"id":"CACHE"}]');console.log('PASS Accounts receipt projection remains local and never enters shared write queue');
 f=fixture();f.c.localStorage.setItem('tt30bags','[{"id":"B1"}]');f.c.localStorage.setItem(STORE,a);p=f.c.TT_SHARED_SYNC.saveNow();assert.equal(f.requests.length,1);assert.equal(f.requests[0].body.key,STORE);f.ack(0);await tick();assert.equal(f.requests.length,2);assert.equal(f.requests[1].body.key,'tt30bags');f.ack(1);await p;console.log('PASS Export root acknowledges before dependent bridge writes');
 f=fixture({moduleId:'milling'});f.c.localStorage.setItem('tt39localsales','[]');await f.c.TT_SHARED_SYNC.saveNow();assert.equal(f.requests.length,0);
 f.c.localStorage.setItem('tt30bags','[{"id":"B1"}]');p=f.c.TT_SHARED_SYNC.saveNow();f.ack(0);await p;f.c.localStorage.setItem('tt30bags','[{"id":"B1"}]');await f.c.TT_SHARED_SYNC.saveNow();assert.equal(f.requests.length,1);
 f.c.localStorage.setItem('tt30bags','[]');p=f.c.TT_SHARED_SYNC.saveNow();assert.equal(f.requests.length,2);f.ack(1);await p;
  f=fixture();f.c.localStorage.setItem(STORE,'{"shipments":[]}');p=f.c.TT_SHARED_SYNC.saveNow();assert.equal(f.requests.length,1,'Explicit Export root save must obtain fresh server validation');f.ack(0);await p;
 console.log('PASS empty initialization and unchanged receipts do not stage writes; clearing existing data still saves');
})().catch(error=>{console.error(error);process.exitCode=1});
