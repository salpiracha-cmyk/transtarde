'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const read=file=>fs.readFileSync(file,'utf8');

const admin=read('admin/app.js');
const auth=read('auth_store.php');
const masters=read('api/masters.php');
const workflows=read('api/accounts_workflows_v1.php');
const bills=read('api/commodity_bills.php');
const bundle=read('accounts/app-bundle.php');
const lookup=read('api/commodity_lookup.php');
const bridge=read('accounts/source-bridge.js');
const billUi=read('accounts/commodity-calculation-ui.js');
const localSales=read('api/local_sales_control.php');
const exportAccounting=read('api/export_accounting.php');
const chart=read('accounts/accounting_master_v1.json');
const exportsApp=read('exports/app.js');

assert.match(masters,/'business_parties'=>13/);
assert.match(admin,/Buying Brokery/);
assert.match(admin,/Selling Brokery/);
for(const basis of ['PER_100_KG','PER_50_KG_BAG','PER_BAG','PER_MAUND','PER_TON']){
  assert.match(admin,new RegExp(basis));
  assert.match(masters,new RegExp(basis));
}
assert.match(admin,/data-add-brokery-rate/);
assert.match(admin,/data-remove-brokery-rate/);
assert.match(auth,/function tt_broker_profiles/);
assert.match(auth,/function tt_broker_profile/);
assert.match(auth,/function tt_brokery_amount/);
assert.match(workflows,/tt_broker_profile\(\$broker,\$date,'buying'\)/);
assert.match(bills,/tt_broker_profiles\(\(string\)\(\$soda\['sodaDate'\]/);
assert.match(bills,/function cb_brokery_amount/);
assert.match(bills,/\$brokerageGross=cb_brokery_amount\(\$brokeryRate,\$brokeryWeightKg,\$brokeryBags\)/);
assert.match(bills,/Per bag Buying Brokery/);
assert.match(bridge,/bags:Number\(saved\.bags\|\|0\)/);
assert.match(lookup,/'bags'=>\(float\)/);
assert.match(billUi,/input\.readOnly=true/);
assert.match(billUi,/Buying Brokery is taken from the selected Soda broker/);
assert.doesNotMatch(workflows,/brokerageRsPer100Kg|brokerageRsPerMaund/);
assert.doesNotMatch(bundle,/'brokerage-ui\.js'/);
assert.doesNotMatch(bundle,/'commodity-kat-master-ui\.js'/);
assert.doesNotMatch(admin,/<label>Brokerage rule<input/);
assert.doesNotMatch(admin,/<label>Inventory account<input/);
assert.match(localSales,/tt_broker_profile\(\$broker,\$date,'selling'\)/);
assert.match(localSales,/tt_brokery_amount\(\$sellingRate,\$kg,\$bags\)/);
assert.match(localSales,/ls_line\('5600',\$brokery/);
assert.match(localSales,/ls_line\('2120',0,\$brokery/);
assert.match(exportAccounting,/tt_broker_profile\(\$broker,\$onBoard,'selling'\)/);
assert.match(exportAccounting,/exa_line\('5600',\$sellingBrokery/);
assert.match(exportAccounting,/exa_line\('2120',0,\$sellingBrokery/);
assert.match(exportsApp,/Selling Broker/);
assert.match(chart,/"code": "5600"[\s\S]*"name": "Sales Brokery \/ Commission"/);

console.log('Broker profile Buying/Selling Brokery master audit passed.');
