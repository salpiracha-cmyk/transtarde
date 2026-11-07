const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const root=path.join(__dirname,'../..'),values=new Map(),storage=new Map();let posts=[],reads=0,loseAck=false,conflict=false,deny=false,printed=0,reset=0,alerts=[],windows=[];
const w={TT_MODULE_ACCESS:{userId:7,module:'Mill',moduleId:'milling',csrf:'fixture'},showNonBlockingMessage:()=>{}};
const context=vm.createContext({window:w,document:{querySelector:()=>null,querySelectorAll:()=>[],activeElement:null},localStorage:{getItem:k=>storage.get(k)??null,setItem:(k,v)=>storage.set(k,v),removeItem:k=>storage.delete(k)},alert:m=>alerts.push(m),open:()=>{const popup={closed:false,document:{write:()=>{}},close(){this.closed=true;}};windows.push(popup);return popup;},crypto:require('node:crypto').webcrypto,Date,JSON,Set,Map,console});
context.fetch=async(url,opt={})=>{
 if(opt.method!=='POST'){reads++;return {ok:true,json:async()=>({ok:true,values:Object.fromEntries([...values].map(([k,v])=>[k,JSON.stringify(v.rows)])),meta:Object.fromEntries([...values].map(([k,v])=>[k,{version:v.version}]))})};}
 const body=JSON.parse(opt.body);posts.push(body);if(deny)return {ok:false,json:async()=>({ok:false,error:'Create permission required'})};
 const current=values.get(body.key)||{version:0,rows:[]};if(conflict){conflict=false;current.version++;current.rows.push({...JSON.parse(body.value).at(-1),id:800,billNo:'LB-00001',ref:'LB-00001',amount:99});values.set(body.key,current);}
 if(body.baseVersion!==current.version)return {ok:false,json:async()=>({ok:false,conflict:true})};
 const next={rows:JSON.parse(body.value),version:current.version+1};values.set(body.key,next);if(loseAck){loseAck=false;throw Error('Connection lost after server saved')};
 return {ok:true,json:async()=>({ok:true,value:JSON.stringify(next.rows),keyVersion:next.version})};
};
vm.runInContext(fs.readFileSync(path.join(root,'accounts/mill-source-save.js'),'utf8'),context);
const key='tt38labourbills',row={id:100,date:'2026-10-17',entity:'TTI',millId:1,billNo:'LB-00001',ref:'LB-00001',amount:1200,contractor:'Contractor',lines:[{label:'Work',qty:10,rate:120,basis:'bag'}],time:'saved time'},input={endpoint:'api/operations.mysql.php',access:w.TT_MODULE_ACCESS,key,record:row,prefix:'LB-'};
(async()=>{
 assert.equal(reads,0,'Loading source helper read operational data');
 loseAck=true;await assert.rejects(w.TT_MILL_SOURCE_SAVE.append(input),/Connection lost/);assert.equal(values.get(key).rows.length,1);
 let saved=await w.TT_MILL_SOURCE_SAVE.append(input);assert.equal(saved.replayed,true);assert.equal(posts.length,1,'Lost acknowledgement retry duplicated a write');
 await assert.rejects(w.TT_MILL_SOURCE_SAVE.append({...input,record:{...row,amount:2}}),/different details/);
 values.clear();posts=[];conflict=true;await assert.rejects(w.TT_MILL_SOURCE_SAVE.append(input),/Another bill/);
 saved=await w.TT_MILL_SOURCE_SAVE.append(input);assert.equal(saved.record.billNo,'LB-00002');assert.equal(values.get(key).rows.length,2,'Concurrent saved bill was lost');
 assert.equal(values.get(key).rows[0].amount,99);assert.equal(posts[1].baseVersion,1);
 await assert.rejects(w.TT_MILL_SOURCE_SAVE.append({...input,record:{...row,id:300}}),/identical bill/);
 values.clear();posts=[];deny=true;await assert.rejects(w.TT_MILL_SOURCE_SAVE.append(input),/Create permission/);assert.equal(values.size,0);deny=false;
 // Rent/advance references use the same acknowledged append and never flush another draft.
 values.clear();posts=[];const rentReference={id:401,entity:'TTI',millId:1,millName:'Selected Mill',date:'2026-10-17',amount:125,fromMonth:'2026-09',tillMonth:'2026-09',ref:'RENT-ORIGINAL'};
 const referenceInput={...input,key:'tt39rentpayments',record:rentReference,prefix:''};loseAck=true;
 await assert.rejects(w.TT_MILL_SOURCE_SAVE.append(referenceInput),/Connection lost/);saved=await w.TT_MILL_SOURCE_SAVE.append(referenceInput);assert(saved.replayed&&posts.length===1&&values.get('tt39rentpayments').rows.length===1,'Reference retry duplicated the saved source');
 const advanceInput={...input,key:'tt39salaryadvances',prefix:'',record:{id:402,entity:'TTI',millId:1,date:'2026-10-17',person:'Alice',amount:25,ref:''}};
 await w.TT_MILL_SOURCE_SAVE.append(advanceInput);await w.TT_MILL_SOURCE_SAVE.append({...advanceInput,record:{...advanceInput.record,id:403,amount:35}});assert.equal(values.get('tt39salaryadvances').rows.length,2,'Optional blank references incorrectly merge distinct advances');
 await assert.rejects(w.TT_MILL_SOURCE_SAVE.append({...advanceInput,record:{...advanceInput.record,id:404}}),/identical reference/);
 values.clear();posts=[];
 // Evaluate the real shared-bootstrap adapter: it must not save another key/draft.
 const module=fs.readFileSync(path.join(root,'module.php'),'utf8'),start=module.indexOf('  async function appendMillBill('),end=module.indexOf('  window.TT_SHARED_SYNC=',start);
 vm.runInContext(`const access=window.TT_MODULE_ACCESS,endpoint='api/operations.mysql.php',pending=new Map([['tt30prod','unsaved production']]),inFlight=new Set(),committedValues=new Map(),receipts=new Map(),keyVersions=new Map(),remoteKeys=new Set();let saveHolds=0;const waiting=()=>{},directSet=(k,v)=>localStorage.setItem(k,v);${module.slice(start,end)}window.TT_SHARED_SYNC={appendMillBill};`,context);
 saved=await w.TT_SHARED_SYNC.appendMillBill(key,row,'LB-');assert.equal(saved.amount,1200);assert.equal(storage.has('tt30prod'),false);assert.equal(posts.at(-1).key,key);
 assert.equal(JSON.parse(storage.get(key)).length,1);assert.equal(vm.runInContext('pending.get("tt30prod")',context),'unsaved production');
 vm.runInContext('pending.set("tt38labourbills","another draft")',context);await assert.rejects(w.TT_SHARED_SYNC.appendMillBill(key,row,'LB-'),/unfinished local change/);vm.runInContext('pending.delete("tt38labourbills")',context);
 // Actual Save holds a durable intent; failed Save & Print never resets or prints.
 const original=w.TT_SHARED_SYNC.appendMillBill;let first=true,attempts=[];
 w.TT_SHARED_SYNC.appendMillBill=async(k,r,p)=>{attempts.push(JSON.parse(JSON.stringify(r)));if(first){first=false;throw Error('Network unavailable')};return {...r,billNo:'LB-00003',ref:'LB-00003'};};
 const options={prefix:'LB-',printAfter:true,print:()=>printed++,onSaved:()=>reset++};
 await w.TT_MILL_SOURCE_SAVE.save(key,row,options);assert.equal(printed,0);assert.equal(reset,0);assert.equal(windows[0].closed,true);assert.ok([...storage.keys()].some(k=>k.startsWith('tt_ui_mill_bill_pending_7_')));
 await w.TT_MILL_SOURCE_SAVE.save(key,{...row,id:777,time:'retry time'},options);assert.equal(printed,1);assert.equal(reset,1);assert.deepEqual(attempts[0],attempts[1],'Retry changed source identity or bill details');assert.equal([...storage.keys()].filter(k=>k.startsWith('tt_ui_mill_bill_pending_')).length,0);
 w.TT_SHARED_SYNC.appendMillBill=original;
 // Native bill handlers must pass through acknowledged save, with no legacy feed write.
 const html=fs.readFileSync(path.join(root,'milling/Transtrade_Master_Milling_V3_3_2_AUDITED.html'),'utf8');
 for(const name of ['saveKEBill','saveLabourBill','saveReprocessBill']){const line=html.split('\n').find(l=>l.startsWith('async function '+name+'('));assert.ok(line&&line.includes('await window.TT_MILL_SOURCE_SAVE.save('),name+' bypasses acknowledged source save');assert.ok(!line.includes('postAccountsLiability(')&&!line.includes('set(STORE_'),name+' stages a parallel legacy liability');}
 console.log('PASS source-only durable bill save, lost-ack replay, retained failed form/print, stable retry identity, concurrent CAS merge, duplicate/permission holds and isolated cache acknowledgement');
})().catch(e=>{console.error(e);process.exitCode=1;});
