'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync('accounts/accounts-accounting-desk.js','utf8');
const calls=[],context={URLSearchParams,fetch:(url,options)=>new Promise(resolve=>calls.push({url,options,resolve}))};
vm.createContext(context);vm.runInContext(source.slice(source.indexOf('  const dashboardRequests ='),source.indexOf('  async function loadSodas()'))+';globalThis.request=json;',context);
const done=(index,body={ok:true},status=200)=>calls[index].resolve({ok:status<400,status,json:async()=>body});
(async()=>{
 const url='../api/accounts_dashboard.php?entity=TTI';
 const first=context.request(url),duplicate=context.request(url);assert.equal(first,duplicate);assert.equal(calls.length,1);
 const tg=context.request('../api/accounts_dashboard.php?entity=TG');assert.equal(calls.length,2);done(1);await tg;done(0);await first;
 const fresh=context.request(url);assert.equal(calls.length,3,'Completed balances must never be cached');done(2);await fresh;
 const old=context.request(url),write=context.request('../api/supplier_settlements.php',{method:'POST'});done(4);await write;
 const afterWrite=context.request(url);assert.equal(calls.length,6,'Successful mutation must bypass a pre-mutation read');done(3);await old;
 assert.equal(context.request(url),afterWrite,'Old completion must not evict a newer in-flight request');done(5);await afterWrite;
 const failed=context.request(url);done(6,{ok:false,error:'Unavailable'},500);await assert.rejects(failed,/Unavailable/);
 const retry=context.request(url);assert.equal(calls.length,8);done(7);await retry;
 const recent='../api/accounts_ledger_browser.php?entity=TTI&activity=vouchers&page=1&pageSize=50';const r=context.request(recent);assert.equal(context.request(recent),r);done(8);await r;
 console.log('PASS concurrent reads, company isolation, uncached completion, mutation freshness, stale completion and error retry');
})().catch(e=>{console.error(e);process.exitCode=1;});
