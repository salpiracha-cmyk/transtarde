'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),{JSDOM}=require('jsdom');
(async()=>{
 const w=new JSDOM('',{url:'https://fixture.test/accounts/',runScripts:'outside-only'}).window;
 let reads=0,resolvers=[];
 w.fetch=()=>{reads++;return new Promise(resolve=>resolvers.push(resolve));};
 w.eval(fs.readFileSync('accounts/accounts-reference-loader.js','utf8'));
 const a=w.TT_ACCOUNTS_REFERENCE.read('TTI'),b=w.TT_ACCOUNTS_REFERENCE.read('TTI'),c=w.TT_ACCOUNTS_REFERENCE.read('BRM');
 assert.equal(a,b);assert.equal(reads,2,'Share only simultaneous reads of the same company');
 resolvers.shift()({ok:true,json:async()=>({ok:true,entity:'TTI'})});resolvers.shift()({ok:true,json:async()=>({ok:true,entity:'BRM'})});
 assert.equal((await a).entity,'TTI');assert.equal((await c).entity,'BRM');
 const next=w.TT_ACCOUNTS_REFERENCE.read('TTI');assert.equal(reads,3,'Later reads always reach the server');
 resolvers.shift()({ok:false,json:async()=>({ok:false,error:'Denied'})});await assert.rejects(next,/Denied/);
 const retry=w.TT_ACCOUNTS_REFERENCE.read('TTI');assert.equal(reads,4);resolvers.shift()({ok:true,json:async()=>({ok:true})});await retry;
 w.close();console.log('PASS simultaneous startup reference deduplication, company isolation, no settled cache, failed read retry');
})().catch(e=>{console.error(e);process.exitCode=1;});
