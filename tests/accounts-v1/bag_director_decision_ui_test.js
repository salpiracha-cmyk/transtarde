'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
(async()=>{
 const dom=new JSDOM('<button id="refreshBagApprovals"></button><div id="bagAcceptanceApprovals"></div>',{url:'https://fixture.test/directors/',runScripts:'outside-only'}),w=dom.window,requests=[];
 w.TT_DIRECTORS_ACCESS={csrf:'fixture'};let queue=[{kind:'bag',billId:'B1',entity:'TTI',fingerprint:'CURRENT',canDecide:false,title:'<img src=x> Qty approval',detail:'Supplier contact missing'}];
 w.fetch=async(url,options={})=>{requests.push({url,body:options.body&&JSON.parse(options.body)});return {ok:true,json:async()=>options.body?{ok:true}:{ok:true,approvals:queue}}};w.alert=m=>{throw Error(m)};
 w.eval(fs.readFileSync('directors/bag-acceptance-approvals.js','utf8'));await new Promise(setImmediate);
 let root=w.document.querySelector('#bagAcceptanceApprovals');assert.equal(root.querySelector('button').disabled,true);assert.equal(root.querySelector('img'),null,'Supplier text cannot inject markup');
 queue=[{...queue[0],canDecide:true,detail:'Called supplier; documented accepted excess'}];await w.document.querySelector('#refreshBagApprovals').onclick();
 root.querySelector('textarea').value='Director accepts the settled extra quantity';queue=[];await root.querySelector('button').onclick();
 const decision=requests.find(x=>x.body);assert.ok(decision);assert.match(decision.url,/bag_purchases\.php$/);assert.equal(decision.body.action,'approve_rate_exception');assert.equal(decision.body.expectedAcceptanceFingerprint,'CURRENT');assert.equal(decision.body.entity,'TTI');assert.equal(decision.body.csrf,'fixture');assert.match(root.textContent,/No bag discrepancy approvals/);
 dom.window.close();console.log('PASS Director UI: shared endpoint, exact bill fingerprint, settlement prerequisite, safe text and removal after decision');
})().catch(e=>{console.error(e);process.exitCode=1});
