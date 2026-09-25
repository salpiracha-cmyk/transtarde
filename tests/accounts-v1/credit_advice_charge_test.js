'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

const source=fs.readFileSync('accounts/export-receipts-ui.js','utf8');
const ui=source.replace('window.TT_EXPORT_RECEIPTS_UI={openForm};','window.__creditQA={setData:value=>{data=value},calculateChargeRows};window.TT_EXPORT_RECEIPTS_UI={openForm};');
assert.notEqual(ui,source,'the live form must expose its calculation to this test');
const fields=(code,percent,amount='')=>({
  '[data-ded-code]':{value:code},
  '[data-ded-percent]':{value:percent},
  '[data-ded-amount]':{value:amount,dataset:{}},
});
const raw=[fields('EXP-AWT-NTR','1.25'),fields('EXP-BANK-COMM','0.03'),fields('EXP-FED-BANK','15')];
const rows=raw.map(inputs=>({querySelector:selector=>inputs[selector]}));
const window={TT_ACCOUNT_ACCESS:{}};
const document={readyState:'loading',addEventListener(){},querySelector(){return null},querySelectorAll:selector=>selector==='[data-er-ded]'?rows:[]};
vm.runInNewContext(ui,{window,document,localStorage:{getItem:()=> 'TTI'},setInterval(){},clearTimeout(){},Intl,Date});
window.__creditQA.setData({deductionMaster:[
  {code:'EXP-AWT-NTR',base:'PKR_PAYMENT'},
  {code:'EXP-BANK-COMM',base:'PKR_PAYMENT'},
  {code:'EXP-FED-BANK',base:'CHARGE:EXP-BANK-COMM'},
]});
window.__creditQA.calculateChargeRows(29943000);
assert.equal(raw[0]['[data-ded-amount]'].value,'374287.50','withholding uses total PKR realization');
assert.equal(raw[1]['[data-ded-amount]'].value,'8982.90','commission uses total PKR realization');
assert.equal(raw[2]['[data-ded-amount]'].value,'1347.44','FED uses bank commission, not total PKR realization');
raw[0]['[data-ded-amount]'].value='374286';raw[0]['[data-ded-amount]'].dataset.manual='1';
raw[1]['[data-ded-amount]'].value='8983';raw[1]['[data-ded-amount]'].dataset.manual='1';
raw[2]['[data-ded-amount]'].value='1347';raw[2]['[data-ded-amount]'].dataset.manual='1';
window.__creditQA.calculateChargeRows(29943000);
assert.equal(raw.reduce((sum,row)=>sum+Number(row['[data-ded-amount]'].value),0),384616,'actual bank advice amounts must override rounded percentage amounts');
assert.match(source,/host\.insertAdjacentHTML\('beforeend',deductionRowHtml/,'adding a charge must append without rebuilding the entered receipt');
assert.doesNotMatch(source,/id="erFileRef"|id="erExpectedForeign"|id="erShortfall"|id="erExpectedPkr"/,'removed boxes must not return');
assert.match(fs.readFileSync('auth_store.php','utf8'),/\$visible=\[[^\n]*'export_realization_charges'/,'the shared charge master must be visible to both UIs');
console.log('PASS credit advice charge calculation, manual bank amounts and stable form');
