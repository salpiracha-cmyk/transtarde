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
 for(const p of ['accounts/accounts-clean-ui.js','accounts/bank-payment-details.js','accounts/expenses-v1-ui.js'])w.eval(fs.readFileSync(p,'utf8'));
 const q=s=>w.document.querySelector(s),change=(id,value)=>{q('#'+id).value=value;q('#'+id).dispatchEvent(new w.Event('change',{bubbles:true}))};
 await w.TT_EXPENSES_V1.open('utility');await turn();
 assert.equal(q('#evPayMaster').value,'');assert(q('#evUtilityFields').hidden);assert(q('#evPayAmount').matches(':disabled'));
 // Check the real searchable display as well as the underlying native values.
 w.TT_ACCOUNTS_CLEAN_UI.manageSelect(q('#evPayLocName'),{});w.TT_ACCOUNTS_CLEAN_UI.manageSelect(q('#evPayType'),{});w.TT_ACCOUNTS_CLEAN_UI.manageSelect(q('#evTreatment'),{});
 change('evPayMaster','MILL');assert(!q('#evUtilityFields').hidden);assert.equal(q('#evPayLocName').value,'M');assert.equal(q('#evPayLocName').previousElementSibling.value,'Rice Mill');assert.equal(q('#evPayee').value,'Mill Power');assert.equal(q('#evConsumerRef').value,'12374937');assert(!q('#evMeterPeriod').hidden);assert.equal(q('#evTreatment').previousElementSibling.value,'Production Expense');
 q('#evPreviousReading').value='100';change('evPayMaster','HOME');assert.equal(q('#evPayLocName').previousElementSibling.value,'Home');assert.equal(q('#evPayType').previousElementSibling.value,'Internet');assert.equal(q('#evPayee').value,'Home Internet');assert.equal(q('#evConsumerRef').value,'98765');assert.equal(q('#evPreviousReading').value,'');assert(q('#evMeterPeriod').hidden);assert.equal(q('#evUtilityFor').value,'SHARED');
 q('#evPayAmount').value='250';q('#evPayNotes').value='Custom payment note';q('#evManageUtility').checked=true;q('#evManageUtility').dispatchEvent(new w.Event('change'));q('#evUmCancel').click();assert.equal(q('#evPayMaster').value,'HOME');assert.equal(q('#evPayAmount').value,'250');assert.equal(q('#evPayNotes').value,'Custom payment note');assert(!q('#evUtilityFields').hidden);
 change('evPayMaster','MANUAL');assert(!q('#evConsumerRef').readOnly);assert.equal(q('#evPayee').value,'');q('#evPayee').value='Manual provider';change('evPayLocName','O');q('#evManageUtility').checked=true;q('#evManageUtility').dispatchEvent(new w.Event('change'));q('#evUmCancel').click();assert.equal(q('#evPayee').value,'Manual provider');assert.equal(q('#evPayLocName').value,'O');
 // Reopen a saved electricity payment while a different unsaved utility is visible.
 const saved={id:'UTP-SAVED',journalId:'POST-SAVED',utilityMasterId:'MILL',utilityType:'ELECTRICITY',utilityTypeName:'Electricity',location:'MILL',locationId:'M',locationName:'Rice Mill',payee:'Mill Power',billMonth:'2026-09',amount:12515,paymentDate:'2026-10-13',accountingTreatment:'BUSINESS_EXPENSE',paymentAccountId:'BANK',reference:'INV-SAVED',notes:'Saved invoice note',bankPaymentMethod:'ONLINE_BANKING',bankReference:'TX-SAVED',bankPaymentDate:'2026-10-08',paymentNarration:'Saved separate bank narration'};
 data.utilityPayments.push(saved);await w.TT_EXPENSES_V1.open('utility');await turn();
 for(const id of ['evPayLocName','evPayType','evTreatment'])w.TT_ACCOUNTS_CLEAN_UI.manageSelect(q('#'+id),{});
 // Install the actual production accounting preview, without starting the unrelated Home desk.
 const desk=fs.readFileSync('accounts/accounts-accounting-desk.js','utf8');const preview=desk.slice(desk.indexOf('  function ensureLiveTreatments()'),desk.indexOf('\n  async function init()',desk.indexOf('  function ensureLiveTreatments()')));
 w.eval('(()=>{const q=s=>document.querySelector(s),esc=v=>String(v??""),money=v=>Number(v||0).toLocaleString("en-PK",{minimumFractionDigits:2,maximumFractionDigits:2});'+preview+';ensureLiveTreatments();})()');
 change('evPayMaster','HOME');q('#evPayAmount').value='100';q('#evPayAmount').dispatchEvent(new w.Event('input',{bubbles:true}));
 assert.match(q('#ttUtilityTreatment').textContent,/100\.00/);
 const paymentRow=[...w.document.querySelectorAll('tbody tr')].find(row=>row.textContent.includes('POST-SAVED'));assert(paymentRow);
 [...paymentRow.querySelectorAll('button')].find(button=>button.textContent==='Edit').click();await turn();
 assert.equal(q('#evPayMaster').previousElementSibling.value,q('#evPayMaster').selectedOptions[0].textContent.trim());
 assert.equal(q('#evPayType').previousElementSibling.value,'Electricity');assert.equal(q('#evPayLocName').previousElementSibling.value,'Rice Mill');assert.equal(q('#evTreatment').previousElementSibling.value,'Production Expense');
 assert(q('#evPayType').previousElementSibling.disabled,'Saved read-only utility is also disabled visually');
 assert.equal(q('#evPayAmount').value,'12515');assert.equal(q('#evConsumerRef').value,'12374937');assert.equal(q('#evPayNotes').value,'Saved invoice note');
 assert.match(q('#ttUtilityTreatment').textContent,/12,515\.00/);assert.doesNotMatch(q('#ttUtilityTreatment').textContent,/Rs 100\.00/);
 const bank=q('#evPayAccountDetails');assert.equal(bank.querySelector('[data-method]').value,'ONLINE_BANKING');assert.equal(bank.querySelector('[data-reference]').value,'TX-SAVED');assert.equal(bank.querySelector('[data-cheque-date]').value,'2026-10-08');assert.equal(bank.querySelector('[data-narration]').value,saved.paymentNarration);
 assert.equal(q('#evPayUtility').textContent,'Save Utility Correction');
 // Shared display refresh follows programmatic assignment without overwriting a live search.
 q('#evTreatment').value='FAMILY_ALLOCATION';w.TT_ACCOUNTS_CLEAN_UI.syncSelects(q('#expenseEditor'));await turn();assert.equal(q('#evTreatment').previousElementSibling.value,'Personal / Family Allocation');
 const hidden=w.document.createElement('select');hidden.hidden=true;hidden.innerHTML='<option>BAG</option><option>KG</option>';q('#expenseEditor').append(hidden);w.TT_ACCOUNTS_CLEAN_UI.manageSelect(hidden,{});assert(!hidden.dataset.ttSearchable,'Dot-choice backing selects never get duplicate controls');
 change('evPayMaster','');assert(q('#evUtilityFields').hidden);assert(q('#evPayUtility').disabled);
 await w.TT_EXPENSES_V1.open('card');await turn();assert.equal(q('#evStatementCard').value,'');assert(q('#evCardFields').hidden);assert(q('#evStatementTotal').matches(':disabled'));
 change('evStatementCard','C1');assert(!q('#evCardFields').hidden);assert(q('#evPersonalEnabled').compareDocumentPosition(q('#evStatementSave'))&w.Node.DOCUMENT_POSITION_FOLLOWING,'Final card posting action follows personal deductions');change('evStatementMonth','2026-10');change('evStatementDate','2026-10-01');assert.equal(q('#evDueDate').value,'2026-10-10');change('evStatementCard','C2');assert.equal(q('#evDueDate').value,'2026-10-22');
 q('#evStatementTotal').value='700';q('#evManageCard').checked=true;q('#evManageCard').dispatchEvent(new w.Event('change'));q('#evCardCancel').click();assert.equal(q('#evStatementCard').value,'C2');assert.equal(q('#evStatementTotal').value,'700');assert(!q('#evCardFields').hidden);
 change('evStatementCard','');assert(q('#evCardFields').hidden);assert(q('#evStatementSave').disabled);assert.equal(posts.length,0,'Selection and reminder drafts must never post');
 console.log('PASS saved utility/card first, visible location/type sync, meter switching, manual entry, due dates and draft preservation; zero writes');
 }finally{observers.forEach(o=>o.disconnect());w.close()}
})().catch(e=>{console.error(e);process.exitCode=1});
