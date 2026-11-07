'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync('exports/shipment-files.js','utf8');
const start=source.indexOf('async function labelledCommercialBlobs('),end=source.indexOf('async function bankBlobsForDocuments(',start);
const context={calls:[],relabelCopy:(html,label)=>html+':'+label,pdf:async html=>{context.calls.push(html);return{html}}};
vm.createContext(context);vm.runInContext(source.slice(start,end)+';this.renderCopies=labelledCommercialBlobs;',context);
(async()=>{
 const output=await context.renderCopies({render:()=>'<saved invoice>'},{},null,3,2);
 assert.equal(output.length,5);assert.equal(context.calls.length,2,'Render each distinct label once per immutable source');
 assert.deepEqual(Array.from(output,x=>x.html),['<saved invoice>:ORIGINAL','<saved invoice>:ORIGINAL','<saved invoice>:ORIGINAL','<saved invoice>:COPY','<saved invoice>:COPY']);
 context.calls=[];await context.renderCopies({render:()=>'<revised invoice>'},{},null,1,1);assert(context.calls.every(html=>html.startsWith('<revised invoice>')),'Never reuse PDFs across different source documents');
 context.pdf=async()=>{throw Error('Renderer unavailable')};await assert.rejects(()=>context.renderCopies({render:()=>'<source>'},{},null,2,1),/Renderer unavailable/);
 console.log('PASS preserve all ORIGINAL/COPY counts while rendering each identical label once, source isolation and atomic render failure');
})().catch(error=>{console.error(error);process.exitCode=1});
