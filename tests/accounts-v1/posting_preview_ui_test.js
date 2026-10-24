'use strict';
const {JSDOM}=require('jsdom'),fs=require('node:fs'),assert=require('node:assert/strict');
module.exports=async()=>{
 const dom=new JSDOM('<input id="draft" value="Retain my entry">',{url:'https://fixture.test/accounts/',runScripts:'outside-only'}),w=dom.window;
 w.Response=Response;w.crypto.randomUUID=()=>Math.random().toString(16).slice(2)+'-fixture';w.localStorage.setItem('tt_accounts_entity','TTI');
 let actual=0,previews=0,edits=[],acknowledged=false;const journal={id:'POST-2026-00001',entity:'TTI',date:'2026-10-06',lines:[{account:'6900',accountName:'Office Expense',debit:10,credit:0},{account:'1110',accountName:'Bank',debit:0,credit:10}]};
 w.TT_ALL_LEDGERS={editPosting:id=>edits.push(id)};w.fetch=async(url,init)=>{
  if(!init?.body)return new Response(JSON.stringify({ok:true}),{headers:{'Content-Type':'application/json','X-TT-Posting-Preview':'available'}});
  const b=JSON.parse(init.body);if(b.preview){previews++;if(acknowledged)return new Response(JSON.stringify({ok:true,journal}));return new Response(JSON.stringify({ok:true,previews:[journal]}));}
  actual++;return new Response(JSON.stringify({ok:true,journal}));
 };
 w.eval(fs.readFileSync('accounts/post-confirmation-ui.js','utf8'));
 const turn=()=>new Promise(r=>setTimeout(r,15));await w.fetch('../api/bank_entries.php');
 const init={method:'POST',body:JSON.stringify({action:'post',csrf:'fixture',requestKey:'actual-key'})};
 const cancelled=w.fetch('../api/bank_entries.php',init);await turn();assert.equal(actual,0);assert.equal(previews,1);assert(w.document.querySelector('[data-review-post]'));assert.equal(w.document.querySelector('[data-review-post]').closest('section').style.backgroundColor,'rgb(232, 246, 237)');
 assert.equal(w.document.querySelector('thead th:nth-child(2)').style.textAlign,'right');w.document.querySelector('[data-review-cancel]').click();assert.equal((await cancelled).status,409);assert.equal(actual,0);assert.equal(w.document.querySelector('#draft').value,'Retain my entry');
 const posted=w.fetch('../api/bank_entries.php',init);await turn();w.document.querySelector('[data-review-post]').click();assert.equal((await posted).status,200);assert.equal(actual,1);assert.equal(previews,2);
 const result=w.document.querySelector('#tt-post-confirmation');assert(result.querySelector('[data-edit]'));assert(result.querySelector('[data-print]'));assert.equal(result.querySelector('thead th:nth-child(2)').style.textAlign,'right');result.querySelector('[data-edit]').click();assert.deepEqual(edits,['POST-2026-00001']);
 acknowledged=true;const replay=await w.fetch('../api/bank_entries.php',init);assert.equal(replay.status,200);assert.equal(actual,1,'A lost-response retry must reuse the acknowledgement without another POST');assert(w.document.querySelector('#tt-post-confirmation'));w.TT_POST_CONFIRMATION.dismiss();acknowledged=false;const navigation=w.fetch('../api/bank_entries.php',init);await turn();const switcher=w.document.createElement('button');switcher.className='entityBtn';w.document.body.appendChild(switcher);switcher.click();assert.equal((await navigation).status,409);assert.equal(actual,1,'Navigating away from review must not post');
 await w.fetch('../api/assets_registry.php');
 const assetInit={method:'POST',body:JSON.stringify({action:'register',csrf:'fixture',requestKey:'asset-review-key'})};
 const assetCancelled=w.fetch('../api/assets_registry.php',assetInit);await turn();assert.equal(actual,1,'Asset purchase waits for green review');w.document.querySelector('[data-review-cancel]').click();assert.equal((await assetCancelled).status,409);assert.equal(actual,1);
 const assetPosted=w.fetch('../api/assets_registry.php',assetInit);await turn();w.document.querySelector('[data-review-post]').click();assert.equal((await assetPosted).status,200);assert.equal(actual,2,'Exactly one confirmed asset commit');dom.window.close();console.log('PASS green pre-post review, cancellation retains draft and prevents commit, one confirmed commit, aligned amounts and Edit/Print result actions');
};
if(require.main===module)module.exports().catch(e=>{console.error(e);process.exitCode=1;});
