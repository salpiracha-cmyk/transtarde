'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
(async()=>{
 const dom=new JSDOM('<input id="cfDate" value="2026-09-30"><button id="cfRefresh"></button><select id="cfSaved"></select><button id="cfPrint"></button><button id="cfCSV"></button><p id="cfMessage"></p><section id="cfOutput"></section><a id="cfReview"></a>',{url:'https://fixture.test/accounts/cash-flow.php?view=estimates',runScripts:'outside-only'}),w=dom.window,d=w.document;
 let writes=0,exportBlob,filename;w.TT_CASHFLOW_CONFIG={origin:'accounts',view:'estimates'};
 const fixture={ok:true,record:{date:'2026-09-30',sections:{},cashflowComparison:{available:true,previousTotalPKR:444,rows:[]}},records:[],source:{rows:[
 {entity:'TTI',account:'1110',label:'PRIVATE BANK',currency:'PKR',amount:999},
 {entity:'TTI',account:'2210',cashflowSection:'estimates',party:'=Malicious supplier',label:'<script>bad()</script>',shipmentId:'S1',currency:'USD',amount:-100,quantity:2,unitRate:50,whtAmount:0,sourceKey:'FREIGHT|TTI|S1',postIds:[],basis:'Agreed freight'},
 {entity:'BRM',account:'2210',cashflowSection:'estimates',party:'Grower',label:'Pohanch required',currency:'PKR',amount:null,excluded:true,reason:'Review rate',postIds:[]}
 ],warnings:['Source warning']},dailyRateDefaults:{USD:280}};
 w.fetch=async(url,opt={})=>{if(opt.body)writes++;return{ok:true,json:async()=>structuredClone(fixture)}};
 w.Blob=class {constructor(parts){this.text=parts.join('')}};w.URL.createObjectURL=b=>{exportBlob=b;return'blob:test'};w.URL.revokeObjectURL=()=>{};w.HTMLAnchorElement.prototype.click=function(){filename=this.download};
 w.eval(fs.readFileSync('accounts/cash-flow-presentation.js','utf8'));w.eval(fs.readFileSync('accounts/cash-flow.js','utf8'));await new Promise(r=>setImmediate(r));
 const output=d.querySelector('#cfOutput');assert.match(output.textContent,/28,000/);assert.match(output.textContent,/FREIGHT\|TTI\|S1/);assert.match(output.textContent,/Review — excluded/);assert.doesNotMatch(output.textContent,/PRIVATE BANK|Previous position/);assert.equal(output.querySelectorAll('script').length,0);assert.equal(writes,0);
 d.querySelector('#cfCSV').click();assert.equal(filename,'Group - Estimated Bills - 2026-09-30.csv');assert.match(exportBlob.text,/'=Malicious supplier/);assert.doesNotMatch(exportBlob.text,/PRIVATE BANK|Previous position/);assert.match(exportBlob.text,/WHT within gross/);
 dom.window.close();console.log('PASS Estimated Bills report: source detail, PKR conversion, visible holds, print/export scope, escaped content and read-only output');
})().catch(e=>{console.error(e);process.exitCode=1;});
