'use strict';
const {JSDOM}=require('jsdom'),assert=require('node:assert/strict'),fs=require('node:fs');
const turn=()=>new Promise(setImmediate);
(async()=>{
 const d=new JSDOM('<!doctype html><body></body>',{url:'https://fixture.test/accounts/',runScripts:'outside-only'}),w=d.window,posts=[];
 const data={ok:true,revision:0,currency:'PKR',canCreate:true,canEdit:true,canDelete:true,brokers:[],routes:[],holdings:[],banks:[{id:'B',label:'Fixture Bank',isDefault:true}],salaryPeriods:[],transactions:[]};
 w.TT_ACCOUNT_ACCESS={csrf:'fixture'};w.TT_FORM_VIEWPORT={open(){}};w.TT_PRINT={create(){return null}};
 w.fetch=async(u,opt={})=>{if(opt.method==='POST'){const p=JSON.parse(opt.body);posts.push(p);if(p.action==='master')data[p.kind==='broker'?'brokers':'routes'].push({id:p.kind==='broker'?'BROKER':'ROUTE',name:p.name,active:true,balanceCents:0});if(p.action==='post')data.transactions.push({id:'TX',journalId:'POST-FIXTURE',type:p.type,status:'Posted',date:p.date,amountCents:100,reference:p.reference});if(p.action==='reverse')data.transactions=[];data.revision++;}return {ok:true,json:async()=>({...data,result:{message:'Saved'}})};};
 w.eval(fs.readFileSync('accounts/company-investments-ui.js','utf8'));await w.TT_INVESTMENTS_UI.open('TTI');
 const q=s=>w.document.querySelector(s),submit=async selector=>{q(selector).dispatchEvent(new w.Event('submit',{cancelable:true}));await turn();await turn();};
 q('#ivNewBroker').click();q('[name=name]').value='Fixture Broker';await submit('#ivMaster');assert.equal(posts[0].action,'master');assert(!Object.hasOwn(posts[0],'bankPaymentDate'));
 q('#ivNewRoute').click();q('[name=name]').value='Fixture Route';await submit('#ivMaster');assert.equal(posts[1].kind,'route');assert(!Object.hasOwn(posts[1],'bankPaymentDate'));
 q('#ivNewPost').click();q('[name=bankPaymentMethod]').value='ONLINE_BANKING';q('[name=bankPaymentMethod]').dispatchEvent(new w.Event('change',{bubbles:true}));assert(!q('[data-iv-payment-date]').hidden);assert(q('[name=chequeNo]').disabled);
 q('[name=chequeDate]').value='2026-10-01';q('[name=reference]').value='Fixture Transfer';q('[name=amount]').value='100';await submit('#ivEntry');assert.equal(posts[2].bankPaymentDate,'2026-10-01');
 q('[data-iv-reverse]').click();q('[name=reason]').value='Fixture reversal';await submit('#ivReverse');assert.equal(posts[3].action,'reverse');assert(!Object.hasOwn(posts[3],'bankPaymentDate'));
 w.close();console.log('PASS investment setup/reversal avoid payment fields; Online Banking exposes and saves its payment date');
})().catch(e=>{console.error(e);process.exitCode=1});
