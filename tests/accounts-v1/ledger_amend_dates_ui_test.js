'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
const turn=()=>new Promise(setImmediate);
(async()=>{for(const currency of ['PKR','USD']){
 const dom=new JSDOM('<!doctype html><body></body>',{url:'https://fixture.test/accounts/',runScripts:'outside-only'}),w=dom.window,observers=[],Observer=w.MutationObserver,posts=[];
 w.MutationObserver=class extends Observer{constructor(fn){super(fn);observers.push(this)}};w.localStorage.setItem('tt_accounts_entity','TTI');w.TT_ACCOUNT_ACCESS={csrf:'fixture'};w.TT_POST_CONFIRMATION={show(){}};w.TT_FORM_VIEWPORT={open(){}};w.alert=e=>{throw Error(e)};
 const post={id:'POST-2026-00083',entity:'TTI',sourceType:'UTILITY_PAYMENT',date:'2026-08-01',narration:'Fixture utility',reference:'Utility 1',lines:[{account:'6110',debit:100,credit:0},{account:'1110',bankAccountId:'BANK1',currency,bankPaymentMethod:'CHEQUE',chequeNo:'FIXTURE',chequeDate:'2026-07-31',bankCredit:currency==='PKR'?100:10,debit:0,credit:100}]};
 const accounts=[{code:'6110',name:'Electricity'},{code:'1110',name:'Banks'}],bankAccounts=[{id:'BANK1',label:'Fixture bank',currency}];
 w.fetch=async(url,opt={})=>({ok:true,json:async()=>opt.method==='POST'?(posts.push(JSON.parse(opt.body)),{ok:true,result:{replacementPostId:'POST-2026-00999'}}):String(url).includes('accounts_search')?{ok:true,results:[]}:String(url).includes('postId=')?{ok:true,post,bankAccounts}:{ok:true,entity:'TTI',accounts,rows:[],currency:'PKR',parties:[]}});
 for(const file of ['cheque-date-ui.js','all-ledgers-ui.js'])w.eval(fs.readFileSync('accounts/'+file,'utf8'));
 await w.TT_ALL_LEDGERS.open('POSTS','other');await turn();await w.TT_ALL_LEDGERS.editPosting(post.id);await turn();
 const q=s=>w.document.querySelector(s);assert.equal(q('#tal-date').value,post.date,'Amendment must start with saved posting date');assert(q('#tal-date').closest('label').textContent.includes('Posting date'));assert.equal(q('.tal-cheque-date').value,'2026-07-31');assert.equal(w.document.querySelectorAll('.tal-native').length,currency==='PKR'?0:1);
 q('#tal-date').value='2026-08-02';q('#tal-date').dispatchEvent(new w.Event('change',{bubbles:true}));q('.tal-cheque-date').value='2026-07-30';q('#tal-reason').value='Fixture date correction';q('#tal-save').click();await turn();await turn();
 assert.equal(posts[0].date,'2026-08-02');assert.equal(posts[0].lines[1].chequeDate,'2026-07-30');assert.equal(Object.hasOwn(posts[0].lines[1],'nativeAmount'),currency!=='PKR','PKR uses debit/credit rather than an extra native amount');if(currency==='USD')assert.equal(posts[0].lines[1].nativeAmount,'10');
 observers.forEach(o=>o.disconnect());w.close();
 }console.log('PASS saved/corrected posting dates, independently editable cheque dates, no duplicate PKR bank amount and retained labelled foreign amount');})().catch(e=>{console.error(e);process.exitCode=1});
