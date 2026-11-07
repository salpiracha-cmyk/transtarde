const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{JSDOM}=require('jsdom');
const dom=new JSDOM('<body><input id="unrelated" value="unfinished production"></body>',{url:'https://fixture.invalid/module.php?id=milling',runScripts:'outside-only'}),w=dom.window;
w.TT_MODULE_ACCESS={userId:7,super:true};w.stockEntityForView=()=> 'TTI';let posts=[],reads=0,renders=0,fail=true,definitive=false,readonly=false;
const row={id:'KE1',entity:'TTI',millId:1,millName:'TTI Rice Mills',date:'2026-10-17',readFrom:'2026-09-01',readTo:'2026-09-30',party:'K-Electric',ref:'KE-SEP',units:20,amount:1000};
w.fetch=async()=>{reads++;return {ok:true,json:async()=>({ok:true,collection:'tt38kebills',entity:'TTI',record:row,fingerprint:'RAW1',canEdit:!readonly,hold:readonly?'Payment allocations hold this bill':'',arrivals:null})};};
w.TT_SHARED_SYNC={amendMillBill:async(collection,body)=>{posts.push(structuredClone(body));if(fail)throw Object.assign(Error('Connection unavailable'),{definitive});return {record:{...row,amount:1200}};}};
w.renderProcessingExpenses=w.renderReprocessHistory=()=>renders++;w.TT_FORM_VIEWPORT={open:()=>{}};
w.eval(fs.readFileSync(path.join(__dirname,'../../accounts/mill-source-amend-ui.js'),'utf8'));
const q=s=>w.document.querySelector(s),tick=()=>new Promise(r=>setTimeout(r,0));
(async()=>{
 assert.equal(reads,0);await w.TT_MILL_SOURCE_AMEND.open('tt38kebills','KE1');assert.equal(reads,1);assert.equal(posts.length,0);assert.equal(w.document.activeElement,q('[data-field="date"]'));
 q('[data-field="amount"]').value='1200';q('[data-field="amount"]').dispatchEvent(new w.Event('input',{bubbles:true}));q('[data-reason]').value='Correct original received amount';assert.equal(posts.length,0);assert.equal(reads,1);
 q('[data-save]').click();await tick();assert.equal(posts.length,1);assert.equal(q('[data-field="amount"]').value,'1200');assert.equal(q('[data-reason]').value,'Correct original received amount');assert.match(q('[data-message]').textContent,/retained/);assert.ok(q('[data-retry]'));
 q('[data-close]').click();assert.equal(q('[data-mill-source-amend]'),null);await w.TT_MILL_SOURCE_AMEND.open('tt38kebills','KE1');assert.ok(q('[data-retry]'),'Reopen lost pending explicit amendment');
 fail=false;q('[data-retry]').click();await tick();assert.equal(posts.length,2);assert.deepEqual(posts[1],posts[0],'Recovery changed the reviewed request or identity');assert.match(q('[data-message]').textContent,/confirmed/);assert.equal(q('#unrelated').value,'unfinished production');assert.equal(renders,2);
 q('[data-close]').click();readonly=true;await w.TT_MILL_SOURCE_AMEND.open('tt38kebills','KE1');assert.ok(q('[data-fields]').disabled);assert.match(q('[data-message]').textContent,/Payment allocations/);q('[data-close]').click();
 // A definitive validation refusal allows correction; an uncertain response retains its identity.
 readonly=false;fail=true;definitive=true;await w.TT_MILL_SOURCE_AMEND.open('tt38kebills','KE1');q('[data-reason]').value='Correct amount';q('[data-save]').click();await tick();assert.equal(q('[data-retry]'),null);assert.match(q('[data-message]').textContent,/retained for correction/);q('[data-field="amount"]').value='1300';q('[data-save]').click();await tick();assert.equal(posts.at(-1).changes.amount,1300);assert.notEqual(posts.at(-1).requestKey,posts.at(-2).requestKey);
 dom.window.close();console.log('PASS amendment UI explicit reads/saves, first field, no writes while typing, retained form and durable lost-ack replay, unrelated draft preservation, paid/read-only hold and validation correction');
})().catch(e=>{console.error(e);process.exitCode=1;dom.window.close();});
