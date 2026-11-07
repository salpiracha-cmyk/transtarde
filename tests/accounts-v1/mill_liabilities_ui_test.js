const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{JSDOM}=require('jsdom');
const dom=new JSDOM('<!doctype html><body></body>',{url:'https://fixture.invalid/accounts/',runScripts:'outside-only'}),w=dom.window;
const row={kind:'ELECTRICITY',sourceId:'KE1',key:'MILL|TTI|1|tt38kebills|KE1',fingerprint:'SOURCE1',reference:'KE-SEP',date:'2026-10-17',from:'2026-09-01',to:'2026-09-30',millName:'TTI Rice Mills',party:'K-Electric',amount:1000,blocker:'',posted:null};
let extraRows=[],reads=0,posts=[],failSave=false,failRead=false,payCalls=[],opened=[];
w.TT_ACCOUNT_ACCESS={csrf:'fixture'};w.localStorage.setItem('tt_accounts_entity','TTI');w.TT_FORM_VIEWPORT={open:x=>opened.push(x)};w.TT_SUPPLIER_SETTLEMENT_UI={openBills:(...a)=>payCalls.push(a)};
w.fetch=async(url,opt={})=>{let data;if(opt.method==='POST'){const b=JSON.parse(opt.body);posts.push(b);if(failSave)return {ok:false,json:async()=>({ok:false,error:'Bill changed after review'})};row.posted={status:'Posted',journalId:'POST-1',billId:'MB-1',fingerprint:'SOURCE1',amount:1000};data={ok:true,result:{record:row.posted}};}else{reads++;if(failRead)throw Error('Connection unavailable');data={ok:true,entity:'TTI',rows:[structuredClone(row),...extraRows],canPost:true,canCorrect:true};}return {ok:true,json:async()=>data};};
w.eval(fs.readFileSync(path.join(__dirname,'../../accounts/mill-liabilities-ui.js'),'utf8'));
const tick=()=>new Promise(r=>setTimeout(r,0)),q=s=>w.document.querySelector(s);
(async()=>{
 await w.TT_MILL_LIABILITIES.open();assert.equal(posts.length,0);assert.equal(reads,1);assert.doesNotMatch(q('[data-message]').textContent,/deferred/);assert.match(q('[data-kind]').textContent,/Maize/);assert.equal(opened.at(-1),q('[data-kind]'),'First relevant field not opened');
 q('[data-review-bill]').click();assert.equal(posts.length,0);assert.match(q('[data-review]').textContent,/1,000/);
 failRead=true;q('[data-post]').click();await tick();await tick();assert.equal(posts.length,1);assert.match(q('[data-message]').textContent,/Saved in Accounts.*POST-1/,'Committed post was shown as failure after refresh lost');assert.ok(q('[data-pay]'));
 q('[data-reason]').value='Correct reading period';q('[data-reason]').dispatchEvent(new w.Event('input',{bubbles:true}));q('[data-correction-date]').value='2026-10-18';await tick();assert.equal(posts.length,1,'Typing saved a correction');assert.equal(reads,2,'Typing refreshed source');
 failSave=true;q('[data-reverse]').click();await tick();assert.equal(posts.length,2);assert.equal(q('[data-reason]').value,'Correct reading period');assert.equal(q('[data-correction-date]').value,'2026-10-18');assert.match(q('[data-message]').textContent,/retained/);
 q('[data-reverse]').click();await tick();assert.equal(posts[1].requestKey,posts[2].requestKey,'Failed retry changed idempotency identity');
 q('[data-pay]').click();assert.deepEqual(payCalls,[['','K-Electric','MB-1']]);assert.equal(q('#ttMillLiabilities'),null);
 assert.equal(posts.filter(p=>p.action==='post').length,1);
 // Review exports honour the same bill-type filter and retain outstanding/source warnings.
 failRead=false;row.fingerprint='CHANGED';row.posted.outstandingAmount=400;await w.TT_MILL_LIABILITIES.open();q('[data-review-bill]').click();assert.equal(q('[data-pay]'),null,'Changed source still offers payment');
 let csv='',paper='';w.Blob=class{constructor(parts){csv=parts.join('')}};w.URL.createObjectURL=()=> 'blob:fixture';w.URL.revokeObjectURL=()=>{};w.HTMLAnchorElement.prototype.click=()=>{};
 w.open=()=>({document:{write:s=>paper=s,close:()=>{}},focus:()=>{},print:()=>{}});
 q('[data-csv]').click();assert.match(csv,/Outstanding PKR/);assert.match(csv,/400/);assert.match(csv,/17-10-2026/);assert.match(csv,/Source changed/);
 q('[data-kind]').value='RICE_LABOUR';q('[data-kind]').dispatchEvent(new w.Event('change'));q('[data-csv]').click();assert.doesNotMatch(csv,/KE-SEP/,'CSV ignored visible filter');q('[data-print]').click();assert.doesNotMatch(paper,/KE-SEP/,'Print ignored visible filter');assert.match(paper,/Rice labour/);
 extraRows=[{...structuredClone(row),kind:'LABOUR',commodity:'Rice',sourceId:'RICE',reference:'RICE-WORK',key:'RICE-KEY'},{...structuredClone(row),kind:'LABOUR',commodity:'Makai',sourceId:'CORN',reference:'CORN-WORK',key:'CORN-KEY'},{...structuredClone(row),kind:'LABOUR',commodity:'',sourceId:'UNKNOWN',reference:'UNKNOWN-WORK',key:'UNKNOWN-KEY',blocker:'Choose source commodity'}];
 await w.TT_MILL_LIABILITIES.open();q('[data-kind]').value='MAIZE_LABOUR';q('[data-kind]').dispatchEvent(new w.Event('change'));assert.match(q('[data-list]').textContent,/CORN-WORK/);assert.doesNotMatch(q('[data-list]').textContent,/RICE-WORK|UNKNOWN-WORK/);q('[data-csv]').click();assert.match(csv,/CORN-WORK/);assert.match(csv,/Commodity/);assert.doesNotMatch(csv,/RICE-WORK/);q('[data-print]').click();assert.match(paper,/CORN-WORK/);assert.doesNotMatch(paper,/RICE-WORK/);
 q('[data-kind]').value='RICE_LABOUR';q('[data-kind]').dispatchEvent(new w.Event('change'));assert.match(q('[data-list]').textContent,/RICE-WORK/);assert.doesNotMatch(q('[data-list]').textContent,/CORN-WORK|UNKNOWN-WORK/);q('[data-kind]').value='';q('[data-kind]').dispatchEvent(new w.Event('change'));assert.match(q('[data-list]').textContent,/commodity unverified/);
 dom.window.close();console.log('PASS Mill review explicit-only requests, source detail, first field, acknowledged-save refresh failure, retained correction draft, stable retries and linked payment launcher');
})().catch(e=>{console.error(e);process.exitCode=1;dom.window.close();});
