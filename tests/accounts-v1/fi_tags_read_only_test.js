const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
(async()=>{
 const source=fs.readFileSync('exports/app.js','utf8'),a=source.indexOf('let fiCreditAdviceTagSequence=0;'),b=source.indexOf('function renderFI(){',a),pending=[],requests=[],listeners={};let saves=0;
 const cell={dataset:{fiAdviceTag:'FI1'},textContent:''},context={view:'fi',STORE:'exports-fixture',window:{TT_SHARED_SYNC:{saveNow:async()=>saves++},addEventListener:(name,fn)=>listeners[name]=fn},document:{querySelectorAll:()=>[cell]},fmt:String,Number,Error,
 fetch:(url,options)=>{requests.push({url,options});return new Promise(resolve=>pending.push(resolve))}};
 vm.createContext(context);vm.runInContext(source.slice(a,b),context);
 const reply=(resolve,reference)=>resolve({ok:true,json:async()=>({ok:true,links:{FI1:{status:'Matched',bankAdviceRef:reference,receiptDate:'2026-10-01',amountDifference:0}}})});
 const first=context.refreshFICreditAdviceTags();reply(pending.shift(),'FIRST');await first;assert.match(cell.textContent,/FIRST/);assert.equal(saves,0,'Opening the FI register is read-only');
 const older=context.refreshFICreditAdviceTags(),oldReply=pending.shift(),newer=context.refreshFICreditAdviceTags();reply(pending.shift(),'NEW');await newer;reply(oldReply,'OLD');await older;assert.match(cell.textContent,/NEW/);assert.doesNotMatch(cell.textContent,/OLD/);
 listeners['tt:shared-saved']({detail:{key:'unrelated'}});assert.equal(pending.length,0);
 listeners['tt:shared-saved']({detail:{key:'exports-fixture'}});reply(pending.shift(),'COMMITTED');await new Promise(resolve=>setImmediate(resolve));assert.match(cell.textContent,/COMMITTED/);
 const late=context.refreshFICreditAdviceTags();context.view='commercial';reply(pending.shift(),'LATE');await late;assert.doesNotMatch(cell.textContent,/LATE/);
 assert.equal(saves,0);assert(requests.every(x=>!x.options.method||x.options.method==='GET'));
 console.log('PASS FI reads never save, committed-save tags refresh, unrelated saves ignored, newer replies own the display and navigation drops stale tags');
})().catch(error=>{console.error(error);process.exitCode=1});
