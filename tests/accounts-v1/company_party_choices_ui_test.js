'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),{JSDOM}=require('jsdom');
(async()=>{
 const d=new JSDOM('<body></body>',{url:'https://fixture.test/accounts/',runScripts:'outside-only',pretendToBeVisual:true}),w=d.window;
 const row=(id,name,profile={})=>({id,values:[name,'','Supplier','','','','','','','','Active','',JSON.stringify(profile)]});
 const masters={business_parties:[row('PK','Pakistan Bags',{buying:[]}),row('USED','Rice Exporter'),row('TG','TG Auditor',{accountingCompanies:['TG']})]};let saved;
 w.TT_ACCOUNT_ACCESS={super:true,csrf:'fixture',masters};w.localStorage.setItem('tt_accounts_entity','TG');
 w.fetch=async(url,options={})=>{if(options.method==='POST'){saved=JSON.parse(options.body);return {ok:true,json:async()=>({ok:true,masters})};}return {ok:true,json:async()=>String(url).includes('accounts_party_choices')?{ok:true,entity:'TG',businessPartyIds:['USED']}:{ok:true,options:{service_expense_heads:[]}}};};
 w.eval(fs.readFileSync('accounts/master-autocomplete.js','utf8'));w.document.dispatchEvent(new w.Event('DOMContentLoaded'));await new Promise(r=>setTimeout(r,30));
 assert.deepEqual(Array.from(w.TT_ACCOUNTS_MASTER_CHOICES.namesForRole('supplier')),['Rice Exporter','TG Auditor']);
 w.localStorage.setItem('tt_accounts_entity','TTI');assert(w.TT_ACCOUNTS_MASTER_CHOICES.namesForRole('supplier').includes('Pakistan Bags'));assert(!w.TT_ACCOUNTS_MASTER_CHOICES.namesForRole('supplier').includes('TG Auditor'));
 w.localStorage.setItem('tt_accounts_entity','TG');w.HTMLDialogElement.prototype.showModal=function(){this.open=true};w.HTMLDialogElement.prototype.close=function(){this.open=false};
 w.TT_ACCOUNTS_MASTER_CHOICES.manageRole('party','Pakistan Bags',()=>{});const f=w.document.querySelector('#ttPartyInlineEditor form');assert(f);for(const box of f.querySelectorAll('[name=accountingCompany]'))box.checked=box.value==='TG';f.dispatchEvent(new w.Event('submit',{bubbles:true,cancelable:true}));await new Promise(r=>setTimeout(r,20));
 assert(saved);const profile=JSON.parse(saved.values[12]);assert.deepEqual(profile.accountingCompanies,['TG']);assert.deepEqual(profile.buying,[]);assert.equal(saved.id,'PK','Existing canonical party must not duplicate');d.window.close();console.log('PASS scoped TG/PK suggestions, company switches, canonical party assignment and profile preservation');
})().catch(e=>{console.error(e);process.exitCode=1});
