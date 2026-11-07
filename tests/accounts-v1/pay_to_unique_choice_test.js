'use strict';
const assert=require('node:assert/strict'),{JSDOM}=require('jsdom'),fs=require('node:fs');
(async()=>{
 const d=new JSDOM('<body><form><label>Pay To<input id=payto required></label><input id=amount value=123><label>Party<select id=native data-payee-choice><option value="">Choose</option><option value=A>Asif Example</option><option value=B>Asim Example</option></select></label><button>Post</button></form></body>',{url:'https://fixture.test/accounts/',runScripts:'outside-only',pretendToBeVisual:true}),w=d.window;
 const observers=[],Original=w.MutationObserver;w.MutationObserver=class extends Original{constructor(fn){super(fn);observers.push(this)}};w.TT_ACCOUNT_ACCESS={super:true,masters:{}};w.eval(fs.readFileSync('accounts/master-autocomplete.js','utf8'));w.document.dispatchEvent(new w.Event('DOMContentLoaded'));
 const input=w.document.querySelector('#payto');w.TT_ACCOUNTS_MASTER_CHOICES.manageInput(input,{label:'Recipient',values:()=>['Asif Example','Asim Example','Tayyab Example'],canAdd:()=>false,canEdit:()=>false});let changes=0;input.addEventListener('change',()=>changes++);
 const type=value=>{input.value=value;input.dispatchEvent(new w.Event('input',{bubbles:true}));};
 type('Tayy');assert.equal(input.value,'Tayy','Typing must remain uninterrupted');input.dispatchEvent(new w.Event('blur'));assert.equal(input.value,'Tayyab Example');assert.equal(changes,1);assert.equal(w.document.querySelector('#amount').value,'123');
 type('Asi');input.dispatchEvent(new w.Event('blur'));assert.equal(input.value,'Asi','Ambiguous short names cannot be auto-selected');
 type('Asif');w.TT_ACCOUNTS_MASTER_CHOICES.resolveInput(input);assert.equal(input.value,'Asif Example');
 type('Unknown name');w.TT_ACCOUNTS_MASTER_CHOICES.resolveInput(input);assert.equal(input.value,'Unknown name','Free-text new recipients are retained');
 input.value='Tayy';input.dispatchEvent(new w.Event('change',{bubbles:true}));assert.equal(input.value,'Tayyab Example','Native change must resolve before downstream posting handlers');
 w.eval(fs.readFileSync('accounts/accounts-clean-ui.js','utf8'));const native=w.document.querySelector('#native');w.TT_ACCOUNTS_CLEAN_UI.manageSelect(native,{label:'Party'});const search=native.closest('.tt-search-select').querySelector('input');search.value='Asif';search.dispatchEvent(new w.Event('change',{bubbles:true}));assert.equal(native.value,'A','Bank payment party must resolve before Post validation');assert.equal(search.value,'Asif Example');
 observers.forEach(o=>o.disconnect());w.close();console.log('PASS Pay To unique-name acceptance, ambiguous/no-match protection, preserved draft and one change event');
})().catch(e=>{console.error(e);process.exitCode=1});
