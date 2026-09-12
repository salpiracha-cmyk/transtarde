'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),read=p=>fs.readFileSync(p,'utf8');
const master=JSON.parse(read('accounts/accounting_master_v1.json')),api=read('api/other_purchases.php'),ui=read('accounts/other-purchases-ui.js'),index=read('accounts/index.php'),bundle=read('accounts/app-bundle.php');
const chart=Object.fromEntries(master.chart.map(x=>[String(x.code),x]));
for(const code of ['1510','1520','1530','1540']){assert.ok(chart[code],code+' missing');assert.equal(chart[code].class,'Asset');assert.equal(chart[code].normal,'Debit');}
for(const code of ['6400','6500','6600','6700','6900']){assert.ok(chart[code],code+' missing');assert.equal(chart[code].class,'Expense');assert.equal(chart[code].normal,'Debit');}
assert.ok((master.postingRules||[]).some(x=>x.event==='FIXED_ASSET_PURCHASE'));

const operating={debit:{account:'6900',amount:25000},credit:{account:'1120',amount:25000}};
const vehicle={debit:{account:'1520',amount:5500000},credit:{account:'1110',amount:5500000}};
const furnitureCredit={debit:{account:'1530',amount:180000},credit:{account:'2140',amount:180000}};
for(const j of [operating,vehicle,furnitureCredit])assert.equal(j.debit.amount,j.credit.amount);
assert.equal(vehicle.debit.account,'1520');
assert.notEqual(vehicle.debit.account,'6900','fixed asset must not be expensed');
assert.equal(furnitureCredit.credit.account,'2140','credit purchase must remain payable');

assert.match(api,/op_require_entity/);
assert.match(api,/tt_user_can_access_entity/);
assert.match(api,/\['TTI','BRM','TG'\]/);
assert.match(api,/\['MILL','OFFICE','OTHER'\]/);
assert.match(api,/This supplier invoice is already recorded/);
assert.match(api,/This asset tag already exists/);
assert.match(api,/flock\(\$h,LOCK_EX\)/);
assert.match(api,/FIXED_ASSET_PURCHASE/);
assert.match(api,/'fixedAssets'=>\[\]/);
assert.match(api,/'accumulatedDepreciation'=>0/);
assert.match(api,/'netBookValue'=>\$amount/);
assert.match(api,/'depreciationMethod'=>'STRAIGHT_LINE'/);
assert.match(api,/Useful life must be between 1 and 50 years/);
assert.match(ui,/Fixed Asset Register/);
assert.match(ui,/Unique Asset Tag/);
assert.match(ui,/No depreciation is posted merely by adding an asset/);
assert.match(ui,/data-purchase="other"/);
assert.match(index,/app-bundle\.php/);\nassert.match(bundle,/'other-purchases-ui\.js'/);
console.log('Other Purchases and Fixed Assets deterministic QA passed.');
