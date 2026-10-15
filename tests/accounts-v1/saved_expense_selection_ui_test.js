'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
const turn=()=>new Promise(setImmediate);
(async()=>{
 const dom=new JSDOM('<!doctype html><div id="expenseEditor"></div>',{url:'https://fixture.test/accounts/',runScripts:'outside-only',pretendToBeVisual:true}),w=dom.window;
 const observers=[],Observer=w.MutationObserver;w.MutationObserver=class extends Observer{constructor(fn){super(fn);observers.push(this)}};
 try{
 w.TT_ACCOUNT_ACCESS={super:true};w.TT_FORM_VIEWPORT={open(){}};w.localStorage.setItem('tt_accounts_entity','TTI');w.HTMLDialogElement.prototype.showModal=function(){this.open=true};
 const posts=[],data={ok:true,utilityMasters:[{id:'MILL',status:'Active',utilityType:'ELECTRICITY',utilityTypeName:'Electricity',location:'MILL',locationId:'M',locationName:'Rice Mill',payee:'Mill Power',accountReference:'12374937',notes:'Saved mill note'},{id:'HOME',status:'Active',utilityType:'INTERNET',utilityTypeName:'Internet',location:'HOME',locationName:'Home',payee:'Home Internet',accountReference:'98765',notes:'Saved home note'}],locations:[{id:'O',location:'OFFICE',name:'Karachi Office'},{id:'M',location:'MILL',name:'Rice Mill'},{id:'H',location:'HOME',name:'Home'}],creditCardMasters:[{id:'C1',status:'Active',cardName:'First Visa',holder:'Owner',last4:'1234',dueDay:10},{id:'C2',status:'Active',cardName:'Second Visa',holder:'Owner',last4:'5678',dueDay:22}],creditCardStatements:[],utilityPayments:[],generalExpenses:[],reminders:[],paymentAccounts:[{id:'BANK',label:'Company Bank',bookBalance:1000}]};
 w.fetch=async(url,opt={})=>{if(opt.body)posts.push(JSON.parse(opt.body));return {ok:true,json:async()=>data}};
 for(const p of ['accounts/accounts-clean-ui.js','accounts/expenses-v1-ui.js'])w.eval(fs.readFileSync(p,'utf8'));
 const q=s=>w.document.querySelector(s),change=(id,value)=>{q('#'+id).value=value;q('#'+id).dispatchEvent(new w.Event('change',{bubbles:true}))};
 await w.TT_EXPENSES_V1.open('utility');await turn();
 assert.equal(q('#evPayMaster').value,'');assert(q('#evUtilityFields').hidden);assert(q('#evPayAmount').matches(':disabled'));
 // Check the real searchable display as well as the underlying native values.
 w.TT_ACCOUNTS_CLEAN_UI.manageSelect(q('#evPayLocName'),{});w.TT_ACCOUNTS_CLEAN_UI.manageSelect(q('#evPayType'),{});w.TT_ACCOUNTS_CLEAN_UI.manageSelect(q('#evTreatment'),{});
 change('evPayMaster','MILL');assert(!q('#evUtilityFields').hidden);assert.equal(q('#evPayLocName').value,'M');assert.equal(q('#evPayLocName').previousElementSibling.value,'Rice Mill');assert.equal(q('#evPayee').value,'Mill Power');assert.equal(q('#evConsumerRef').value,'12374937');assert(!q('#evMeterPeriod').hidden);assert.equal(q('#evTreatment').previousElementSibling.value,'Production Expense');
 q('#evPreviousReading').value='100';change('evPayMaster','HOME');assert.equal(q('#evPayLocName').previousElementSibling.value,'Home');assert.equal(q('#evPayType').previousElementSibling.value,'Internet');assert.equal(q('#evPayee').value,'Home Internet');assert.equal(q('#evConsumerRef').value,'98765');assert.equal(q('#evPreviousReading').value,'');assert(q('#evMeterPeriod').hidden);assert.equal(q('#evUtilityFor').value,'SHARED');
 q('#evPayAmount').value='250';q('#evPayNotes').value='Custom payment note';q('#evManageUtility').checked=true;q('#evManageUtility').dispatchEvent(new w.Event('change'));q('#evUmCancel').click();assert.equal(q('#evPayMaster').value,'HOME');assert.equal(q('#evPayAmount').value,'250');assert.equal(q('#evPayNotes').value,'Custom payment note');assert(!q('#evUtilityFields').hidden);
 change('evPayMaster','MANUAL');assert(!q('#evConsumerRef').readOnly);assert.equal(q('#evPayee').value,'');q('#evPayee').value='Manual provider';change('evPayLocName','O');q('#evManageUtility').checked=true;q('#evManageUtility').dispatchEvent(new w.Event('change'));q('#evUmCancel').click();assert.equal(q('#evPayee').value,'Manual provider');assert.equal(q('#evPayLocName').value,'O');
 change('evPayMaster','');assert(q('#evUtilityFields').hidden);assert(q('#evPayUtility').disabled);
 await w.TT_EXPENSES_V1.open('card');await turn();assert.equal(q('#evStatementCard').value,'');assert(q('#evCardFields').hidden);assert(q('#evStatementTotal').matches(':disabled'));
 change('evStatementCard','C1');assert(!q('#evCardFields').hidden);change('evStatementMonth','2026-10');change('evStatementDate','2026-10-01');assert.equal(q('#evDueDate').value,'2026-10-10');change('evStatementCard','C2');assert.equal(q('#evDueDate').value,'2026-10-22');
 q('#evStatementTotal').value='700';q('#evManageCard').checked=true;q('#evManageCard').dispatchEvent(new w.Event('change'));q('#evCardCancel').click();assert.equal(q('#evStatementCard').value,'C2');assert.equal(q('#evStatementTotal').value,'700');assert(!q('#evCardFields').hidden);
 change('evStatementCard','');assert(q('#evCardFields').hidden);assert(q('#evStatementSave').disabled);assert.equal(posts.length,0,'Selection and reminder drafts must never post');
 console.log('PASS saved utility/card first, visible location/type sync, meter switching, manual entry, due dates and draft preservation; zero writes');
 }finally{observers.forEach(o=>o.disconnect());w.close()}
})().catch(e=>{console.error(e);process.exitCode=1});
