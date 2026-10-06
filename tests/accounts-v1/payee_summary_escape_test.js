'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const payload='"><img src=x onerror="window.injected=true">';
const nodes=new Map();const box={id:'ttBrokerPaySummary',className:'',innerHTML:'',remove(){}};
nodes.set('#ttstBroker',{value:payload});nodes.set('.ttst-body',{firstElementChild:{insertAdjacentElement(){}}});nodes.set('#ttBrokerPaySummary',box);nodes.set('#ttBrokerPaySummaryStyle',{});
const callbacks={};
const document={querySelector:s=>nodes.get(s)||null,addEventListener:(name,fn)=>{callbacks[name]=fn},documentElement:{},head:{appendChild(){}},createElement:()=>({})};
let pending;
const context={document,window:{},location:{href:'https://example.test/accounts/index.php'},localStorage:{getItem:()=> 'TTI'},sessionStorage:{getItem:()=> 'RICE'},URL,Intl,Date,Number,String,setTimeout:fn=>{pending=fn();return 1},clearTimeout(){},MutationObserver:class{observe(){}},fetch:async()=>({ok:true,json:async()=>({ok:true,payables:[{broker:payload,outstanding:100,dueDate:'2026-01-01',held:false}]})})};
vm.runInNewContext(fs.readFileSync('accounts/supplier-payment-broker-summary-ui.js','utf8'),context);
callbacks.change({target:{id:'ttstBroker'}});
Promise.resolve(pending).then(()=>{
  assert.doesNotMatch(box.innerHTML,/<img\b|<script\b/i);
  assert.ok(box.innerHTML.includes('&lt;img src=x onerror=&quot;window.injected=true&quot;&gt;'));
  assert.ok(box.innerHTML.includes('Rs 100'));
  console.log('PASS hostile payee stays literal while supplier totals render');
}).catch(error=>{console.error(error);process.exitCode=1});
