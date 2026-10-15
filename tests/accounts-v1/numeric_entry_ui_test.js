const {JSDOM}=require('jsdom'),fs=require('node:fs'),assert=require('node:assert/strict');
(async()=>{
const dom=new JSDOM('<form><label>Amount<input id="amount" name="amount" class="sum" type="number" min="0.01" step="0.01" value="1234.50" required></label><input id="rate" type="number" step="0.000001"><input id="decimalText" inputmode="decimal" value="95493274.63"><input id="reference" value="2026-00123"></form>',{runScripts:'outside-only',url:'https://fixture.test'}),w=dom.window;
const observers=[],Native=w.MutationObserver;w.MutationObserver=class extends Native{constructor(cb){super(cb);observers.push(this)}};
w.eval(fs.readFileSync('brand-theme.js','utf8'));w.TT_NUMERIC_ENTRY.refresh();
const text=w.document.getElementById('decimalText');assert.equal(text.nextElementSibling.value,'95,493,274.63');text.nextElementSibling.value='1,209,349.28';text.nextElementSibling.dispatchEvent(new w.Event('input',{bubbles:true}));assert.equal(text.value,'1209349.28');
const original=w.document.getElementById('amount'),proxy=original.nextElementSibling,form=original.form;
assert.equal(proxy.value,'1,234.50');assert.equal(original.value,'1234.50');assert.equal(w.document.querySelectorAll('.sum').length,1,'Numeric calculations must not count a companion field twice');
let events=0,total=0;original.oninput=e=>{events++;total=Number(e.target.value)*2};
const enter=value=>{proxy.focus();proxy.value=value;proxy.setSelectionRange(value.length,value.length);proxy.dispatchEvent(new w.Event('input',{bubbles:true}))};
enter('1234567.89');assert.equal(proxy.value,'1,234,567.89');assert.equal(original.valueAsNumber,1234567.89);assert.equal(total,2469135.78);assert.equal(events,1);assert.equal(new w.FormData(form).get('amount'),'1234567.89');assert.equal(proxy.selectionStart,12);
enter('1234.');assert.equal(proxy.value,'1,234.');assert.equal(original.valueAsNumber,1234);enter('1234.50');assert.equal(proxy.value,'1,234.50');
enter('-2');assert.equal(original.validity.rangeUnderflow,true);enter('abc');assert.equal(original.validity.customError,true);enter('0.001');assert.equal(original.validity.stepMismatch,true);enter('12.25');assert.equal(original.checkValidity(),true);
original.focus();for(const key of ['ArrowUp','ArrowDown']){const e=new w.KeyboardEvent('keydown',{key,bubbles:true,cancelable:true});original.dispatchEvent(e);assert(e.defaultPrevented);assert.equal(original.value,'12.25');}
const wheel=new w.WheelEvent('wheel',{deltaY:-1,bubbles:true,cancelable:true});original.dispatchEvent(wheel);assert(wheel.defaultPrevented);assert.equal(original.value,'12.25');
const css=fs.readFileSync('brand-theme.css','utf8');assert.match(css,/input\[type="number"\]::-webkit-inner-spin-button/);assert.match(css,/-moz-appearance:textfield!important/);
original.focus();assert.equal(w.document.activeElement,original,'Existing native controls retain programmatic focus and fill behavior');Object.getOwnPropertyDescriptor(w.HTMLInputElement.prototype,'value').set.call(original,'38000');original.dispatchEvent(new w.Event('input',{bubbles:true}));assert.equal(proxy.value,'38,000');assert.equal(total,76000);
original.value='2000000.00';assert.equal(proxy.value,'2,000,000.00');assert.equal(original.value,'2000000.00');
const rate=w.document.getElementById('rate');rate.value='3.670001';assert.equal(rate.nextElementSibling.value,'3.670001');assert.equal(rate.valueAsNumber,3.670001);
original.disabled=true;await new Promise(setImmediate);assert.equal(proxy.disabled,true);original.disabled=false;
form.reset();await new Promise(setImmediate);assert.equal(original.value,'1234.50');assert.equal(proxy.value,'1,234.50');
assert.equal(w.document.getElementById('reference').value,'2026-00123');assert.equal(w.document.getElementById('reference').nextElementSibling,null);
const dynamic=w.document.createElement('input');dynamic.type='number';form.append(dynamic);await new Promise(setImmediate);assert.equal(dynamic.nextElementSibling.dataset.ttNumericProxy,'1');
observers.forEach(x=>x.disconnect());w.close();
const bankDom=new JSDOM('<!doctype html><body></body>',{url:'https://fixture.test/accounts/',runScripts:'outside-only',pretendToBeVisual:true}),bankWindow=bankDom.window;
const bankObservers=[],BankObserver=bankWindow.MutationObserver;
bankWindow.MutationObserver=class extends BankObserver{constructor(fn){super(fn);bankObservers.push(this)}};
bankWindow.requestAnimationFrame=()=>0;bankWindow.cancelAnimationFrame=()=>{};
bankWindow.TT_ACCOUNT_ACCESS={csrf:'fixture'};
let bankWrites=0;
bankWindow.fetch=async(url,options={})=>{if(options.method==='POST')bankWrites++;return {ok:true,json:async()=>({ok:true,banks:[{id:'bank',name:'Company bank',currency:'PKR'}],facilities:[],subaccounts:[],payees:[],heads:[],millLocations:[],canCreate:true,history:[]})}};
bankWindow.eval(fs.readFileSync('accounts/account-management-ui.js','utf8'));
bankWindow.eval(fs.readFileSync('brand-theme.js','utf8'));
await bankWindow.TT_BANK_ENTRIES.open();
await new Promise(setImmediate);bankWindow.TT_NUMERIC_ENTRY.refresh();
const bankForm=bankWindow.document.querySelector('#amBankForm'),bankAmount=bankForm.elements.amount;
const bankAmountLabel=bankAmount.closest('label').querySelector(':scope > span'),bankProxy=bankAmount.nextElementSibling;
for(const mode of ['SAVING_PROFIT','RECEIPT','TRANSFER','BANK_CHARGE','FINANCE_DRAW','FINANCE_REPAY','FINANCE_MARKUP']){
 bankForm.elements.type.value=mode;bankForm.elements.type.dispatchEvent(new bankWindow.Event('change'));
 await new Promise(setImmediate);
 assert.equal(bankAmountLabel.textContent,'Amount / Gross profit',`Correct amount label after enhanced Bank Payment changes to ${mode}`);
 assert.equal(bankAmount.textContent,'','The numeric source must not receive stray label text');
 bankProxy.value='1,234.50';bankProxy.dispatchEvent(new bankWindow.Event('input',{bubbles:true}));
 assert.equal(bankAmount.value,'1234.50');assert.equal(bankProxy.getAttribute('aria-label'),'Amount / Gross profit');
}
assert.equal(bankWrites,0,'Switching modes and entering amounts must not post');bankObservers.forEach(o=>o.disconnect());bankWindow.close();
console.log('Numeric entry: grouping, decimals, caret, calculations, form submission, validation, reset, dynamic fields and enhanced Bank Entry mode labels passed.');
})().catch(e=>{console.error(e);process.exitCode=1});
