const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{JSDOM}=require('jsdom');
const dom=new JSDOM('<body><input id="draft" value="unfinished bill"><button data-refresh-mill-bill-status>Refresh Accounts Status</button><p data-mill-bill-status-note></p></body>',{url:'https://fixture.invalid/module.php?id=milling',runScripts:'outside-only'}),w=dom.window;
w.currentMill={name:'Mill A',entity:'TTI'};w.stockEntityForView=()=>w.currentMill.entity;let calls=[],renders=0,formBuilds=0;
w.openPanel=()=>{formBuilds++;};w.renderProcessingExpenses=()=>renders++;w.renderReprocessHistory=()=>renders++;
w.fetch=(url,opt={})=>new Promise(resolve=>{assert.notEqual(opt.method,'POST');calls.push({url,resolve});});w.setInterval=()=>{throw Error('Timed refresh forbidden');};
w.eval(fs.readFileSync(path.join(__dirname,'../../accounts/mill-liability-status.js'),'utf8'));
const tick=()=>new Promise(r=>setTimeout(r,0));
function finish(i,mill,id){calls[i].resolve({ok:true,json:async()=>({ok:true,entity:'TTI',millName:mill,checkedAt:'2026-10-17T11:00:00Z',rows:[{entity:'TTI',millName:mill,collection:'tt38kebills',sourceId:'KE1',status:'Posted',postId:id,postedAmount:1000,outstanding:400,sourceNeedsReview:false,reversalPostId:''}]})});}
(async()=>{
 assert.equal(calls.length,0,'Script loading read financial state');w.openPanel('labour');assert.equal(calls.length,1);
 w.document.querySelector('#draft').value='typing during read';
 w.currentMill={name:'Mill B',entity:'TTI'};w.openPanel('labour');assert.equal(calls.length,2,'New mill was blocked behind the previous read');
 finish(1,'Mill B','POST-B');await tick();assert.match(w.TT_MILL_BILL_STATUS.text({id:'KE1',readFrom:'2026-10-01'}),/POST-B/);
 finish(0,'Mill A','POST-A');await tick();assert.doesNotMatch(w.TT_MILL_BILL_STATUS.text({id:'KE1',readFrom:'2026-10-01'}),/POST-A/,'Late previous-mill response replaced current status');
 assert.equal(formBuilds,2,'Status response rebuilt the entry form');assert.equal(w.document.querySelector('#draft').value,'typing during read');assert.equal(renders,2);
 w.document.querySelector('#draft').dispatchEvent(new w.Event('input'));await tick();assert.equal(calls.length,2,'Typing read or saved financial data');
 const refresh=w.TT_MILL_BILL_STATUS.refresh();assert.equal(calls.length,3);finish(2,'Wrong Mill','POST-WRONG');await refresh;
 assert.match(w.document.querySelector('[data-mill-bill-status-note]').textContent,/another company or mill/);assert.match(w.TT_MILL_BILL_STATUS.text({id:'KE1',readFrom:'2026-10-01'}),/POST-B/);assert.equal(w.document.querySelector('#draft').value,'typing during read');
 dom.window.close();console.log('PASS Mill status reads only on explicit open/refresh, preserves active form, verifies company/mill, rejects stale responses and never posts');
})().catch(e=>{console.error(e);process.exitCode=1;dom.window.close();});
