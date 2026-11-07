'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
(async()=>{
 const storage=new Map(),posts=[],listeners={};let access={moduleId:'milling',csrf:'fixture'};
 const rows=[{id:1,poNo:'P',poLineKey:'L2',_ttBridge:'exports',_ttBridgeId:'P|2|C|BRAND|50|75',received:100,keepReceiptOpen:true},{id:2,poNo:'P',_ttBridge:'exports',_ttBridgeId:'P|2|C|BRAND|50|75|MASTER',received:10}];
 storage.set('tt30bags',JSON.stringify(rows));storage.set('tt33bagreceipts',JSON.stringify([{id:3,bagId:1,date:'2026-10-16',qty:100,actualTareGrams:70,tareMismatch:true,qualityMismatch:true},{id:4,bagId:2,date:'2026-10-16',qty:10,actualTareGrams:150}]));
 const context={window:{TT_MODULE_ACCESS:access},localStorage:{getItem:k=>storage.get(k)||null,setItem:(k,v)=>storage.set(k,v)},console,addEventListener:(key,fn)=>listeners[key]=fn,fetch:async(url,options)=>{posts.push(JSON.parse(options.body));return {ok:true,json:async()=>({ok:true})}}};
 vm.createContext(context);vm.runInContext(fs.readFileSync('accounts/bag-control-bridge.js','utf8'),context);
 assert.equal(posts.length,0,'Opening a module never commits acceptance evidence');await listeners['tt:shared-saved']();
 assert.deepEqual(posts.map(x=>x.lineKey),['L2','L2-MASTER'],'Real bridge keys keep master and ordinary packing lines separate');
 assert.equal(posts[0].acceptance.receivedWeightKg,7);assert.equal(posts[0].acceptance.tareMismatch,true);assert.equal(posts[0].acceptance.quality,true);assert.equal(posts[0].keepReceiptOpen,true);assert.equal(posts[1].acceptance.receivedWeightKg,1.5);
 await listeners['tt:shared-saved']();assert.equal(posts.length,2,'Identical acknowledged evidence is not posted again');
 console.log('PASS committed Mill receipt handoff, multi-field line identity, master bags, measured tare, quality holds and explicit-save-only synchronization');
})().catch(e=>{console.error(e);process.exitCode=1;});
