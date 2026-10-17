const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{JSDOM}=require('jsdom');
const dom=new JSDOM('<!doctype html><body></body>',{url:'https://fixture.invalid/accounts/',runScripts:'outside-only'}),w=dom.window;
const row={kind:'ELECTRICITY',sourceId:'KE1',key:'MILL|TTI|1|tt38kebills|KE1',fingerprint:'SOURCE1',reference:'KE-SEP',date:'2026-10-17',from:'2026-09-01',to:'2026-09-30',millName:'TTI Rice Mills',party:'K-Electric',amount:1000,blocker:'',posted:null};
let reads=0,posts=[],failSave=false,failRead=false,payCalls=[],opened=[];
w.TT_ACCOUNT_ACCESS={csrf:'fixture'};w.localStorage.setItem('tt_accounts_entity','TTI');w.TT_FORM_VIEWPORT={open:x=>opened.push(x)};w.TT_SUPPLIER_SETTLEMENT_UI={openBills:(...a)=>payCalls.push(a)};
w.fetch=async(url,opt={})=>{let data;if(opt.method==='POST'){const b=JSON.parse(opt.body);posts.push(b);if(failSave)return {ok:false,json:async()=>({ok:false,error:'Bill changed after review'})};row.posted={status:'Posted',journalId:'POST-1',billId:'MB-1',fingerprint:'SOURCE1',amount:1000};data={ok:true,result:{record:row.posted}};}else{reads++;if(failRead)throw Error('Connection unavailable');data={ok:true,rows:[structuredClone(row)],canPost:true,canCorrect:true};}return {ok:true,json:async()=>data};};
w.eval(fs.readFileSync(path.join(__dirname,'../../accounts/mill-liabilities-ui.js'),'utf8'));
const tick=()=>new Promise(r=>setTimeout(r,0)),q=s=>w.document.querySelector(s);
(async()=>{
 await w.TT_MILL_LIABILITIES.open();assert.equal(posts.length,0);assert.equal(reads,1);assert.equal(opened.at(-1),q('[data-kind]'),'First relevant field not opened');
 q('[data-review-bill]').click();assert.equal(posts.length,0);assert.match(q('[data-review]').textContent,/1,000/);
 failRead=true;q('[data-post]').click();await tick();await tick();assert.equal(posts.length,1);assert.match(q('[data-message]').textContent,/Saved in Accounts.*POST-1/,'Committed post was shown as failure after refresh lost');assert.ok(q('[data-pay]'));
 q('[data-reason]').value='Correct reading period';q('[data-reason]').dispatchEvent(new w.Event('input',{bubbles:true}));q('[data-correction-date]').value='2026-10-18';await tick();assert.equal(posts.length,1,'Typing saved a correction');assert.equal(reads,2,'Typing refreshed source');
 failSave=true;q('[data-reverse]').click();await tick();assert.equal(posts.length,2);assert.equal(q('[data-reason]').value,'Correct reading period');assert.equal(q('[data-correction-date]').value,'2026-10-18');assert.match(q('[data-message]').textContent,/retained/);
 q('[data-reverse]').click();await tick();assert.equal(posts[1].requestKey,posts[2].requestKey,'Failed retry changed idempotency identity');
 q('[data-pay]').click();assert.deepEqual(payCalls,[['','K-Electric','MB-1']]);assert.equal(q('#ttMillLiabilities'),null);
 assert.equal(posts.filter(p=>p.action==='post').length,1);dom.window.close();console.log('PASS Mill review explicit-only requests, source detail, first field, acknowledged-save refresh failure, retained correction draft, stable retries and linked payment launcher');
})().catch(e=>{console.error(e);process.exitCode=1;dom.window.close();});
