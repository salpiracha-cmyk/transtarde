'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
(async()=>{
 // The real phone transport is bounded through response-body consumption.
 const network=new JSDOM('',{url:'https://fixture.test/module.php?id=milling',runScripts:'outside-only'}),n=network.window;let expire,requests=0,bodyPending=false;
 n.TT_MODULE_ACCESS={moduleId:'milling'};n.setTimeout=(fn,ms)=>{assert.equal(ms,45000);expire=fn;return 1;};n.clearTimeout=()=>{};
 n.fetch=async(url,opts)=>{requests++;if(url==='https://external.test/asset')return {external:true};if(bodyPending)return {json:()=>new Promise((resolve,reject)=>opts.signal.addEventListener('abort',()=>reject(Error('aborted'))))};return await new Promise((resolve,reject)=>opts.signal.addEventListener('abort',()=>reject(Error('aborted'))));};
 n.eval(fs.readFileSync('milling/safe-network.js','utf8'));let pending=n.fetch('/api/operations.mysql.php',{method:'POST'});expire();await assert.rejects(pending,/timed out.*entry is retained/);bodyPending=true;const response=await n.fetch('/api/operations.mysql.php');pending=response.json();expire();await assert.rejects(pending,/timed out/);assert((await n.fetch('https://external.test/asset')).external);assert.equal(requests,3);network.window.close();
 // Every action collection uses the same exact original-ID acknowledgement and retry contract.
 const actions=['tt30queue','tt30slips','tt30directoralerts','tt37usedbags','tt33pettyexp','tt32stockadj','tt35localsales','tt35exportersale'];
 for(const key of actions){const dom=new JSDOM('',{url:'https://fixture.test',runScripts:'outside-only'}),w=dom.window;let saved=[],version=0,posts=0;
  w.fetch=async(url,opts={})=>{if(!opts.method)return {ok:true,json:async()=>({ok:true,values:{[key]:JSON.stringify(saved)},meta:{[key]:{version}}})};posts++;saved=JSON.parse(JSON.parse(opts.body).value);version++;return {ok:true,json:async()=>({ok:true})};};w.eval(fs.readFileSync('accounts/mill-action-save.js','utf8'));const input={endpoint:'/fixture',access:{},key,record:{id:101,entity:'TTI',millId:1,quantity:5}};
  await assert.rejects(w.TT_MILL_ACTION_SAVE.commit(input),/acknowledgement is incomplete/);const confirmed=await w.TT_MILL_ACTION_SAVE.commit(input);assert.equal(confirmed.record.id,101);assert.equal(posts,1,key+' replay duplicated a save');dom.window.close();
 }
 // Labour, electricity, processing, rent/advance references and reprocessing all reject invented receipts.
 for(const key of ['tt38labourbills','tt38kebills','tt38reprocessbills','tt37processingexpenses','tt39rentpayments','tt39salaryadvances']){const dom=new JSDOM('',{url:'https://fixture.test',runScripts:'outside-only'}),w=dom.window;let saved=[],version=0,posts=0;
  w.fetch=async(url,opts={})=>{if(!opts.method)return {ok:true,json:async()=>({ok:true,values:{[key]:JSON.stringify(saved)},meta:{[key]:{version}}})};posts++;saved=JSON.parse(JSON.parse(opts.body).value);version++;return {ok:true,json:async()=>({ok:true})};};w.eval(fs.readFileSync('accounts/mill-source-save.js','utf8'));const input={endpoint:'/fixture',access:{},key,record:{id:101,entity:'TTI',millId:1,amount:5,ref:'FIXTURE'},prefix:''};await assert.rejects(w.TT_MILL_SOURCE_SAVE.append(input),/acknowledgement is incomplete/);await w.TT_MILL_SOURCE_SAVE.append(input);assert.equal(posts,1);dom.window.close();
 }
 // Production cannot accept a saved value without its advancing source version.
 const prod=new JSDOM('',{url:'https://fixture.test',runScripts:'outside-only'}),p=prod.window;let rows=[],version=0,posts=0;
 p.fetch=async(url,opts={})=>{if(!opts.method)return {ok:true,json:async()=>({ok:true,values:{tt30prod:JSON.stringify(rows)},meta:{tt30prod:{version}}})};posts++;rows=JSON.parse(JSON.parse(opts.body).value);version++;return {ok:true,json:async()=>({ok:true,value:JSON.stringify(rows)})};};p.eval(fs.readFileSync('accounts/production-source-save.js','utf8'));const input={endpoint:'/fixture',access:{},record:{id:101,entity:'TTI',millId:1,clientSaveRequestId:'same-original',rows:[]}};await assert.rejects(p.TT_PRODUCTION_SOURCE_SAVE.commit(input),/did not return/);await p.TT_PRODUCTION_SOURCE_SAVE.commit(input);assert.equal(posts,1);prod.window.close();
 console.log('PASS bounded phone/PC API and body timeouts; all 8 action and 6 bill collections plus production reject incomplete acknowledgements and replay one original ID without duplicate writes');
})().catch(e=>{console.error(e);process.exitCode=1});
