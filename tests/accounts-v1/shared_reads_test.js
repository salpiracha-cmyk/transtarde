const assert=require('node:assert/strict'),fs=require('node:fs'),{JSDOM}=require('jsdom');
const w=new JSDOM('',{url:'https://fixture.test/module.php',runScripts:'outside-only'}).window;
w.Headers=Headers;w.Request=Request;let calls=[],resolve;
w.fetch=(url,options)=>{calls.push(String(url));return new Promise(r=>resolve=r)};
w.eval(fs.readFileSync('shared-reads.js','utf8'));
(async()=>{
 const a=w.fetch('/api/business_reports.php?entity=TTI'),b=w.fetch('/api/business_reports.php?entity=TTI');assert.equal(calls.length,1);resolve(new Response('{"ok":true}'));assert.deepEqual(await (await a).json(),await (await b).json());
 const c=w.fetch('/api/business_reports.php?entity=TTI');assert.equal(calls.length,2);resolve(new Response('{}'));await c;
 const old=w.fetch('/api/masters.php'),finishOld=resolve;const post=w.fetch('/api/masters.php',{method:'POST'}),finishPost=resolve;const after=w.fetch('/api/masters.php'),finishAfter=resolve;assert.equal(calls.length,5);finishOld(new Response('{}'));finishPost(new Response('{}'));finishAfter(new Response('{}'));await Promise.all([old,post,after]);
 const tti=w.fetch('/api/business_reports.php?entity=TTI'),finishTti=resolve;const tg=w.fetch('/api/business_reports.php?entity=TG'),finishTg=resolve;assert.equal(calls.length,7);finishTti(new Response('{}'));finishTg(new Response('{}'));await Promise.all([tti,tg]);
 const beforeRequest=w.fetch('/api/masters.php'),finishBefore=resolve;const requestPost=w.fetch(new Request('https://fixture.test/api/masters.php',{method:'POST'})),finishRequest=resolve;const fresh=w.fetch('/api/masters.php'),finishFresh=resolve;assert.equal(calls.length,10);finishBefore(new Response('{}'));finishRequest(new Response('{}'));finishFresh(new Response('{}'));await Promise.all([beforeRequest,requestPost,fresh]);
 w.close();console.log('PASS concurrent reads share network only; bodies independent, completion uncached, writes invalidate and companies isolated');
})().catch(e=>{console.error(e);w.close();process.exitCode=1});
