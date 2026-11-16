'use strict';
const fs=require('fs'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
(async()=>{const d=new JSDOM('<body><input id="draft" value="preserved"></body>',{url:'https://fixture.test',runScripts:'outside-only'}),w=d.window;w.HTMLDialogElement.prototype.showModal=function(){this.open=true};w.HTMLDialogElement.prototype.close=function(){this.open=false};w.eval(fs.readFileSync('accounts/all-ledgers-ui.js','utf8').replace('  window.TT_ALL_LEDGERS=','  window.testDeletion=reviewDeletion; window.testDeletionRows=rows=>{data={rows};}; window.TT_ALL_LEDGERS='));const impact={deletionImpact:[{postId:'POST-1',entity:'TG',date:'2026-07-13',source:'TG_BANK_PAYMENT',narration:'Vendor payment',lines:[{account:'6800',name:'Online Banking Charges',currency:'AED',debit:30,credit:0},{account:'1140',name:'Input VAT',currency:'AED',debit:1.5,credit:0},{account:'1110',bankAccountId:'AED-BANK',currency:'AED',debit:0,credit:31.5}]}],affectedSources:[{register:'tgBankTransactions',id:'T1',beforeStatus:'Posted',afterStatus:'Deleted',effect:'Outstanding balance restored'}]};let result=w.testDeletion(impact,'POST-1'),dialog=w.document.querySelector('dialog');assert.equal(dialog.style.borderColor,'rgb(180, 35, 24)');for(const text of ['POST-1','Online Banking Charges','Input VAT','AED-BANK','tgBankTransactions','Outstanding balance restored'])assert(dialog.textContent.includes(text));dialog.querySelector('[data-keep]').click();assert.equal(await result,false);result=w.testDeletion(impact,'POST-1');w.document.querySelector('[data-confirm-delete]').click();assert.equal(await result,true);assert.equal(w.document.querySelector('#draft').value,'preserved');
 w.testDeletionRows([{voucher:'INTERNAL-REPLACEMENT',publicPostId:'POST-PUBLIC',entity:'TG'}]);const pending=w.TT_ALL_LEDGERS.deletePosting('INTERNAL-REPLACEMENT');await Promise.resolve();assert(w.document.querySelector('dialog').textContent.includes('POST-PUBLIC'),'Initial cancellation must use the public Post ID');w.document.querySelector('dialog [data-back]').click();await pending;
 d.window.close();console.log('PASS red detailed deletion impact: native bank/VAT/party lines, linked registers, explicit confirmation and cancellation preserve draft');})().catch(e=>{console.error(e);process.exit(1)});

async function searchPostingActions(company, sourceType) {
 const dom=new JSDOM('<body></body>',{url:'https://fixture.test/accounts/',runScripts:'outside-only'});
 const {window:w}=dom, d=w.document;
 const select=selector=>d.querySelector(selector);
 const settle=()=>new Promise(resolve=>setImmediate(resolve));
 w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};
 w.HTMLDialogElement.prototype.close=function(){this.open=false;};
 w.TT_ACCOUNT_ACCESS={csrf:'fixture'};
 w.localStorage.setItem('tt_accounts_entity',company);
 const postId='INTERNAL-1', publicPostId='POST-2026-04321';
 const calls=[];
 const record={
  postId,type:sourceType,printable:true,
  postingActions:{entity:company,publicPostId,canEdit:true,canDelete:true},
 };
 w.fetch=async(path,options)=>{
  if(path.startsWith('../api/accounts_search.php?')){
   assert.equal(new URL(path,w.location).searchParams.get('entity'),company);
   return {ok:true,json:async()=>({ok:true,results:[record]})};
  }
  assert.equal(path,'../api/accounts_post_amend.php');
  calls.push(JSON.parse(options.body));
  return {ok:true,json:async()=>({ok:true,previews:[{}],deletionImpact:[{postId:publicPostId,entity:company,source:sourceType,lines:[]}]})};
 };
 w.eval(fs.readFileSync('accounts/all-ledgers-ui.js','utf8'));
 w.eval(fs.readFileSync('accounts/accounts-accounting-desk.js','utf8').replace(
  "  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once:true}); else init();",
  '  window.openPostingSearch=openSearch;'
 ));
 w.openPostingSearch('4321');
 await settle();
 assert.match(select('.tt-record-card').textContent,new RegExp(publicPostId));
 assert(select('[data-print-record]'));
 select('[data-cancel-search-post]').click();
 const dialog=select('dialog[open]');
 assert.match(dialog.textContent,new RegExp(publicPostId));
 dialog.querySelector('textarea').value='Incorrect posting';
 dialog.querySelector('button[type=submit]').click();
 await settle();
 assert.equal(calls.length,1);
 assert.deepEqual(calls[0],{csrf:'fixture',entity:company,action:'delete_post',postId,reason:'Incorrect posting',preview:true});
 assert.match(select('dialog[open]').textContent,new RegExp(sourceType));
 select('[data-keep]').click();
 await settle();
 assert.equal(calls.length,1,'Keeping the posting must leave only the read-only preview request');
 assert(select('#ttSearchLayer:not([hidden])'));
 const editCalls=[];
 w.TT_ALL_LEDGERS.open=async account=>editCalls.push(account);
 w.TT_ALL_LEDGERS.editPosting=async id=>editCalls.push(id);
 select('[data-edit-search-post]').click();
 await settle();
 assert.deepEqual(editCalls,['POSTS',postId]);
 record.postingActions={entity:company,publicPostId,canEdit:false,canDelete:false};
 w.openPostingSearch('4321');
 await settle();
 assert(!select('[data-edit-search-post]'));
 assert(!select('[data-cancel-search-post]'));
 assert(select('[data-print-record]'));
 dom.window.close();
}

(async()=>{
 await searchPostingActions('BRM','DIRECT_EXPENSE_PAYMENT');
 await searchPostingActions('TG','BANK_ENTRY');
 console.log('PASS BRM and TG search routes Edit and previews Delete in the correct company, with view-only actions preserved');
})().catch(error=>{console.error(error);process.exitCode=1;});
