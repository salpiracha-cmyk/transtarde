'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),{JSDOM}=require('jsdom'),turn=()=>new Promise(setImmediate);
(async()=>{
 const dom=new JSDOM('<!doctype html><div class="topbar"></div>',{url:'https://fixture.invalid/accounts/',runScripts:'outside-only',pretendToBeVisual:true}),w=dom.window,d=w.document;
 w.scrollTo=()=>{};w.localStorage.setItem('tt_accounts_entity','TTI');w.TT_ACCOUNT_ACCESS={csrf:'fixture'};w.TT_FORM_VIEWPORT={open(){}};let mode='fail',approve=true,reviewed=0,saved=0,shown=0;
 let post={id:'PK',publicPostId:'POST-2026-00047',entity:'TTI',sourceType:'EXPORT_FOREIGN_RECEIPT',date:'2026-10-01',meta:{receiptId:'R',tgRemittanceId:'REMIT',remitter:'TG'},lines:[{account:'1110',debit:100,credit:0},{account:'2510',debit:0,credit:100}],totalDebit:100,totalCredit:100};
 w.alert=message=>{throw Error('Unexpected alert '+message)};w.TT_POST_CONFIRMATION={review:async()=>{reviewed++;return approve},show(){shown++}};
 w.fetch=async(url,options={})=>{
  if(options.method==='POST'){
   const body=JSON.parse(options.body);assert.equal(url,'../api/export_receipts.php');assert.equal(body.action,'correct_date');assert.equal(body.expectedDate,'2026-10-01');assert.equal(body.entity,'TTI');assert.equal(body.reason,'Correct saved bank date');assert.equal(body.date,'2026-09-24');assert.equal(body.lines,undefined,'Date correction must not send editable amounts');
   if(mode==='fail')return {ok:false,json:async()=>({ok:false,error:'Date follows the linked application.'})};
   if(body.preview)return {ok:true,json:async()=>({ok:true,previews:[post]})};
   saved++;post={...post,id:'NEW',date:body.date};return {ok:true,json:async()=>({ok:true,result:{replacementPostId:'NEW'},sourceSynced:true})};
  }
  return {ok:true,json:async()=>String(url).includes('postId=')?{ok:true,post,bankAccounts:[]}:{ok:true,entity:'TTI',currency:'PKR',accounts:[],rows:[],parties:[]}};
 };
 try{
  w.eval(fs.readFileSync('accounts/tg-remittances-ui.js','utf8'));w.eval(fs.readFileSync('accounts/all-ledgers-ui.js','utf8'));
  await w.TT_ALL_LEDGERS.openPosting('PK');const origin=d.querySelector('#tt-all-ledgers');assert(origin.textContent.includes('00047'));
  await w.TT_ALL_LEDGERS.editPosting('PK');let form=d.querySelector('#tt-linked-post-date form');assert(form,'Linked TTI Edit must open date correction directly');assert(origin.isConnected,'Do not remove originating post before correction opens');
  form.elements.date.value='2026-09-24';form.elements.reason.value='Correct saved bank date';const submit=()=>form.dispatchEvent(new w.Event('submit',{bubbles:true,cancelable:true}));
  submit();await turn();await turn();assert.equal(d.querySelector('[data-error]').textContent,'Date follows the linked application.');assert.equal(form.elements.date.value,'2026-09-24');assert.equal(form.elements.reason.value,'Correct saved bank date');assert(origin.isConnected);assert.equal(saved,0);assert(!form.querySelector('button').disabled);
  mode='ok';approve=false;submit();await turn();await turn();assert.equal(reviewed,1);assert.equal(saved,0,'Cancelling green review cannot save');assert(form.isConnected);assert.equal(form.elements.reason.value,'Correct saved bank date');
  approve=true;submit();await turn();await turn();await turn();assert.equal(saved,1);assert.equal(shown,1);assert(!form.isConnected);assert(origin.isConnected);assert(origin.textContent.includes('2026-09-24'),'Success returns to the updated posting');
  // A TG remittance uses its native company endpoint and retains the form on failure.
  w.localStorage.setItem('tt_accounts_entity','TG');let tgCalls=0;w.fetch=async(url,opt)=>{tgCalls++;assert.equal(url,'../api/accounts_post_amend.php');assert.equal(JSON.parse(opt.body).entity,'TG');return {ok:false,json:async()=>({ok:false,error:'Current source changed.'})}};
  w.TT_TG_REMITTANCES.correctDate({...post,entity:'TG',sourceType:'TG_REMITTANCE_CONFIRMED'});form=d.querySelector('#tt-linked-post-date form');form.elements.date.value='2026-09-20';form.elements.reason.value='Correct saved bank date';submit();await turn();await turn();assert.equal(tgCalls,1);assert(form.isConnected);assert.equal(d.querySelector('[data-error]').textContent,'Current source changed.');
  d.querySelector('#tt-linked-post-date [data-close]').click();assert(!form.isConnected);assert(origin.isConnected,'Closing correction retains exact original screen');
  console.log('PASS actual linked-post Edit: company endpoint, retained origin and draft on error/cancel, green preview before write, updated posting on success and one confirmation');
 }finally{w.close()}
})().catch(e=>{console.error(e);process.exitCode=1});
