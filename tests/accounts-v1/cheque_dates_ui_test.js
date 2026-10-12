'use strict';
const {JSDOM}=require('jsdom'),assert=require('node:assert/strict'),fs=require('node:fs');
(async()=>{
 const d=new JSDOM('<!doctype html><body><form><label>Voucher date<input name=date type=date value=2026-10-01></label><label>Bank<select id=bank><option value=B>BANK</option></select></label></form><form><input name=date type=date value=2026-10-02><input name=chequeDate type=date value=2026-10-03></form></body>',{url:'https://fixture.test/accounts/',runScripts:'outside-only'}),w=d.window,observers=[],Observer=w.MutationObserver;
 w.MutationObserver=class extends Observer{constructor(fn){super(fn);observers.push(this)}};w.localStorage.setItem('tt_accounts_entity','TTI');
 for(const file of ['cheque-date-ui.js','bank-payment-details.js'])w.eval(fs.readFileSync('accounts/'+file,'utf8'));
 w.TT_BANK_PAYMENT_DETAILS.mount('bank',[{id:'B',entity:'TTI'}]);const box=w.document.getElementById('bankDetails'),cheque=box.querySelector('[data-cheque-date]'),voucher=w.document.forms[0].elements.date;
 assert.equal(cheque.value,'2026-10-01');assert(!cheque.readOnly&&!cheque.disabled);
 const edit=(input,value)=>{input.value=value;input.dispatchEvent(new w.Event('input',{bubbles:true}));input.dispatchEvent(new w.Event('change',{bubbles:true}));};
 edit(voucher,'2026-10-04');assert.equal(cheque.value,'2026-10-04');edit(cheque,'2026-09-30');edit(voucher,'2026-10-05');assert.equal(cheque.value,'2026-09-30','A manually edited cheque date survives voucher date changes');
 box.querySelector('[data-reference]').value='Fixture cheque';assert.equal(w.TT_BANK_PAYMENT_DETAILS.read('bank',voucher.value).chequeDate,'2026-09-30');
 edit(cheque,'');edit(voucher,'2026-10-06');assert.equal(cheque.value,'2026-10-06');
 assert.equal(w.document.forms[1].elements.chequeDate.value,'2026-10-03','A reopened saved cheque date is preserved');
 box.querySelector('[data-method]').value='ONLINE_BANKING';box.querySelector('[data-method]').dispatchEvent(new w.Event('change'));assert(box.querySelector('[data-cheque-date-wrap]').hidden);assert.equal(w.TT_BANK_PAYMENT_DETAILS.read('bank',voucher.value).chequeDate,'');
 observers.forEach(o=>o.disconnect());w.close();console.log('PASS shared cheque dates default to editable voucher dates, preserve explicit/saved dates, and save their actual chosen value');
})().catch(e=>{console.error(e);process.exitCode=1});
