'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),{JSDOM}=require('jsdom');
(async()=>{
 const dom=new JSDOM('<!doctype html><body></body>',{url:'https://fixture.test/accounts/',runScripts:'outside-only'}),w=dom.window,q=s=>w.document.querySelector(s);
 w.localStorage.setItem('tt_accounts_entity','TTI');w.TT_ACCOUNT_ACCESS={super:true};
 const rows=Array.from({length:121},(_,i)=>({voucher:'POST-2026-'+String(i+1).padStart(5,'0'),date:'2026-11-11',entity:'TTI',bookCurrency:'PKR',account:'POSTS',accountName:'TTI',debit:10,credit:10,narration:'Fixture '+(i+1)}));
 const reads=[],errors=[];let printed='',blob,downloads=0,delayed;
 w.alert=e=>errors.push(e);w.TT_PRINT={create:()=>({document:{write:s=>printed=s,close(){}},print(){},close(){}})};
 w.TextEncoder=TextEncoder;w.Blob=Blob;w.URL.createObjectURL=b=>{blob=b;return 'blob:fixture';};w.URL.revokeObjectURL=()=>{};w.HTMLAnchorElement.prototype.click=()=>downloads++;w.setTimeout=()=>{};
 w.fetch=async url=>{const params=new URL(url,w.location.href).searchParams;reads.push(params);if(delayed)await new Promise(resolve=>delayed=resolve);const page=Number(params.get('page')),body={ok:true,entity:'TTI',currency:'PKR',accounts:[],parties:[],opening:0,closing:0,rows:page?rows.slice().reverse().slice((page-1)*50,page*50):rows};if(page)Object.assign(body,{pagination:{page,pages:3,pageSize:50,totalPostings:121},registerTotals:[{company:'TTI',currency:'PKR',debit:1210,credit:1210,count:121}],periodTotals:{debit:1210,credit:1210}});return{ok:true,json:async()=>body};};
 w.eval(fs.readFileSync('accounts/all-ledgers-ui.js','utf8'));
 await w.TT_ALL_LEDGERS.open('POSTS');assert.equal(reads.at(-1).get('pageSize'),'50');assert.equal(w.document.querySelectorAll('.tal-posting-row').length,50);assert(q('.tal-total').textContent.includes('1,210.00'));assert.equal(q('.tal-posting-row').dataset.postId,'POST-2026-00121');
 q('#tal-next').click();await new Promise(setImmediate);assert.equal(reads.at(-1).get('page'),'2');assert.equal(q('.tal-posting-row').dataset.postId,'POST-2026-00071');
 q('#tal-filter').value='UNSUBMITTED';await q('#tal-print').onclick();assert.equal(reads.at(-1).get('page'),null);assert.equal(reads.at(-1).get('q'),'');assert(printed.includes('Fixture 121')&&printed.includes('Fixture 1'));assert(printed.indexOf('Fixture 1<')<printed.indexOf('Fixture 121<'),'Print ascending');assert.equal(w.document.querySelectorAll('.tal-posting-row').length,50,'Export must preserve current page');
 await q('#tal-export').onclick();assert.equal(downloads,1);const workbook=Buffer.from(await blob.arrayBuffer()).toString();assert(workbook.includes('Fixture 121')&&workbook.includes('Fixture 1'));
 q('#tal-next').click();await new Promise(setImmediate);assert.equal(w.document.querySelectorAll('.tal-posting-row').length,21);assert(q('#tal-next').disabled);
 assert(q('#tal-from').disabled,'From date is visible but inactive for All dates');assert.equal(q('#tal-period option:checked').textContent,'All dates');
 const before=reads.length;q('#tal-period').value='range';q('#tal-period').onchange();assert(!q('#tal-from').disabled);q('#tal-from').value='2026-09-11';q('#tal-to').value='2026-11-11';q('#tal-from').oninput();assert(q('#tal-print').disabled&&q('#tal-export').disabled);assert.equal(reads.length,before,'Editing dates waits for Apply');q('#tal-go').click();await new Promise(setImmediate);assert.equal(reads.at(-1).get('page'),'1');assert.equal(reads.at(-1).get('from'),'2026-09-11');assert.equal(reads.at(-1).get('to'),'2026-11-11');assert(!q('#tal-print').disabled);await q('#tal-print').onclick();assert.equal(reads.at(-1).get('page'),null);assert.equal(reads.at(-1).get('from'),'2026-09-11');assert(printed.includes('2026-09-11'));assert(!q('#tal-output-options'),'Print opens directly without a second date popup');

 delayed=true;const pending=q('#tal-print').onclick();await new Promise(setImmediate);q('#tal-close').click();delayed();await pending;assert.match(errors.pop(),/selection changed/);assert.deepEqual(errors,[]);
 w.close();console.log('PASS 50-posting pages, full totals, complete ascending print/Excel, unsubmitted filters and abandoned export isolation');
})().catch(e=>{console.error(e);process.exitCode=1;});
