'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),vm=require('node:vm');
const page=fs.readFileSync('directors/index.php','utf8');
const start=page.indexOf("(()=>{'use strict';\n const access=window.TT_DIRECTORS_ACCESS");
assert.ok(start>=0);
const code=page.slice(start,page.indexOf('</script>',start));
const banks=[{id:'old',currency:'PKR',isDefault:true},{id:'new',currency:'PKR',accountNumber:'123',bankName:'<img src=x>',accountTitle:'Fixture'},
 {id:'usd',currency:'USD',accountNumber:'456'},{id:'inactive',currency:'PKR',accountNumber:'789',status:'Inactive'},
 {id:'retention',currency:'PKR',accountNumber:'999',accountType:'Retention Account'}];
const request={id:'R1',companyId:'C1',bankId:'old',status:'Pending',company:'TTI',bank:'Old bank'};
let pending=[request],currentBanks=banks,posts=[],alerts=[];
const root={addEventListener:(event,handler)=>{root.handler=handler}},w={document:{getElementById:()=>root}};
w.window=w;
vm.createContext(w);
w.TT_DIRECTORS_ACCESS={csrf:'fixture'};w.alert=message=>alerts.push(message);
w.fetch=async(url,options)=>{
 const body=options?.body?JSON.parse(options.body):null;
 if(body){posts.push(body);pending=[];}
 return {ok:true,json:async()=>({ok:true,masters:{companies:[{id:'C1',values:Array.from({length:14},(_,i)=>i===13?JSON.stringify(currentBanks):'')}]},bankDeletionRequests:pending})};
};
const settle=()=>new Promise(resolve=>setImmediate(resolve));
(async()=>{
 vm.runInContext(code,w);await settle();
 const select={value:'',focus:()=>{}};
 const click=async decision=>{const button={dataset:{bankApproval:'R1',decision},closest:()=>({querySelector:()=>select})};await root.handler({target:{closest:()=>button}});await settle();};
 assert.deepEqual([...root.innerHTML.matchAll(/<option value="([^"]*)"/g)].map(x=>x[1]),['','new']);assert.ok(!root.innerHTML.includes('<img'));
 await click('Approve');assert.equal(posts.length,0);assert.equal(alerts.length,1);
 select.value='new';await click('Approve');
 assert.equal(posts[0].action,'review-bank-deletion');assert.equal(posts[0].requestId,'R1');assert.equal(posts[0].replacementBankId,'new');assert.equal(posts[0].csrf,'fixture');
 assert.ok(root.innerHTML.includes('No bank deletion approvals'));
 pending=[request];currentBanks=[banks[0]];vm.runInContext(code,w);await settle();
 assert.ok(/data-decision="Approve" disabled/.test(root.innerHTML));
 select.value='';await click('Reject');assert.equal(posts[1].decision,'Reject');
 console.log('PASS Director bank approvals: eligible replacement, escaped content, selection required, shared endpoint and rejection without replacement.');
})().catch(error=>{console.error(error);process.exitCode=1});
