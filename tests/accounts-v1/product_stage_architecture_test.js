'use strict';
const assert=require('assert');
const fs=require('fs');
const read=file=>fs.readFileSync(file,'utf8');

const stage=read('product_stage.php');
const masters=read('auth_store.php');
const masterApi=read('api/masters.php');
const admin=read('admin/app.js');
const workflows=read('api/accounts_workflows_v1.php');
const sodaUi=read('accounts/accounts-v1-workflow-ui.js');
const bridge=read('accounts/source-bridge.js');
const lookup=read('api/commodity_lookup.php');
const bags=read('api/bag_purchases.php');
const bagsUi=read('accounts/bag-purchases-ui.js');
const milling=read('milling/Transtrade_Master_Milling_V3_3_2_AUDITED.html');
const exportsApp=read('exports/app.js');
const bridgeRuntime=read('module.php');
const commodityBills=read('api/commodity_bills.php');

for(const value of ['RAW','READY','FINISHED']) assert(stage.includes(`'${value}'`),`canonical ${value} stage missing`);
assert(stage.includes("$stage==='RAW'" )&&stage.includes("$stage==='READY'"),'Rice stage display rules missing');
assert(stage.includes("/^READY\\s+RICE"),'legacy own-mill Ready Rice compatibility missing');
assert(stage.includes('PARBOIL(?:ED)?')&&stage.includes('STEAM')&&stage.includes('SELLA'),'legacy product labels are not normalized to the canonical base variety');
assert(stage.includes("'riceType'"),'canonical Rice Type is not retained separately');
assert(stage.includes("strcasecmp($base,'Sesame')===0"),'Sesame display must not duplicate the base name');

assert(masters.includes("'purchase_products'=>["),'Purchase Products master missing');
assert(masterApi.includes("'purchase_products'=>11"),'Purchase Products API schema must retain the broken grade');
assert(!masterApi.includes('Create the Rice base variety in Export Quality & Specs first'),'new purchase products must be addable from the Soda form');
assert(stage.includes("'brokenGrade'=>$grade"),'broken grade must be shown separately from the base variety');
assert(admin.includes('name: "Export Quality & Specs"'),'Export Quality & Specs master name missing');
assert(admin.includes('name: "Purchase Commodities & KAT"'),'combined Purchase Commodities & KAT workspace missing');
assert(admin.includes('["products","purchase_products"]'),'separate Commodity and KAT menu entries were not merged');
assert(admin.includes('options: ["RAW", "READY"]'),'new purchase setup still offers FINISHED');
assert(masterApi.includes("!in_array($values[3],['RAW','READY'],true)"),'purchase API does not enforce RAW/READY');
assert(admin.includes('data-manage-product-option')&&admin.includes('data-add-product-option')&&admin.includes('data-delete-product-option'),'managed product dropdown controls missing');
assert(admin.includes('optionAction:"delete"'),'product dropdown deactivation is not wired');
assert(admin.includes('data-purchase-commodity')&&admin.includes('data-purchase-stage'),'purchase hierarchy is not product then RAW/READY');
assert(admin.includes('data-edit-product-kat'),'KAT rules are not opened from the exact purchase product');
assert(!admin.includes('<label>Brokerage rule<input'),'product master still asks the user for a brokerage rule');
assert(!admin.includes('<label>Inventory account<input'),'product master still asks the user for an inventory account');
assert(admin.includes('Buying Brokery')&&admin.includes('Selling Brokery'),'broker profile does not contain both Brokery tabs');
for(const basis of ['PER_100_KG','PER_50_KG_BAG','PER_BAG','PER_MAUND','PER_TON'])assert(admin.includes(basis),`missing Brokery basis ${basis}`);
assert(masters.includes('function tt_broker_profiles'),'broker profile resolver is missing');
assert(commodityBills.includes('cb_brokery_rate'),'commodity billing does not resolve Buying Brokery from the broker profile');
assert(!workflows.includes('brokerageRsPer100Kg')&&!workflows.includes('brokerageRsPerMaund'),'KAT rules still own Brokery rates');

assert(workflows.includes("'purchaseProducts'=>tt_purchase_product_profiles()"),'Soda API does not expose Purchase Products');
assert(workflows.includes("'purchaseProductId'=>$purchaseProduct['id']"),'Soda does not persist the selected Purchase Product');
assert(workflows.includes("'amendmentHistory'"),'Soda amendment history missing');
assert(sodaUi.includes('Search Previous Soda'),'Soda search is missing');
assert(sodaUi.includes('Amendment Reason'),'Soda amendment reason is missing');
assert(sodaUi.includes('Select from Purchase Commodities & KAT'),'Soda still lacks the combined master selection');
assert(sodaUi.includes("x.productStage==='RAW'||x.productStage==='READY'"),'Soda purchase selector still exposes new FINISHED purchases');
assert(workflows.includes('FINAL is created only through own-mill or reprocessing production'),'Soda API does not block new FINAL purchases');
assert(workflows.includes("'locations'=>aw_locations()")&&workflows.includes("'movementRole'"),'Soda API does not link the central Location Master');
assert(sodaUi.includes('ttvLocationAdd')&&sodaUi.includes('ttvLocationRemove'),'Soda location + / − controls are missing');

for(const key of ['baseVariety','riceType','productStage','displayName']){
  assert(bridge.includes(key),`Pohanch bridge missing ${key}`);
  assert(lookup.includes(`'${key}'`),`Accounts lookup missing ${key}`);
}

assert(bags.includes('salesTaxInvoice'), 'bag bill tax-invoice flag missing');
assert(bags.includes("if($hasTax&&(float)$b['gstAmount']>0)"),'GST recoverable posting is not conditional');
assert(bags.includes("array_key_exists('salesTaxInvoice',$b)"),'legacy bag-tax invoices are not backward compatible');
assert(!bags.includes('Non-Woven bags are excluded'),'Non-Woven is still excluded from unified bag billing');
assert(bagsUi.includes('Sales Tax Invoice received'),'bag invoice checkbox missing');
assert(bagsUi.includes('Every bag type is available'),'bag workflow is not unified');

assert(milling.includes("inputStockName:inputStock||millProductIdentity(prodVariety.value,'RAW').displayName"),'normal raw processing must retain the typed Raw Rice source');
assert(milling.includes("inputStage:inputStock?(/READY RICE$/i.test(inputStock)?'READY':'FINISHED'):'RAW'"),'reprocessing must preserve the actual Ready/Finished input rather than relabel it RAW');
assert(milling.includes("outputStage:'FINISHED'"),'own-mill finished stage missing');
assert(milling.includes("productStage:identity.productStage"),'Pohanch stage metadata missing');
assert(milling.includes('function millBaseVariety(name)'),'Milling still lacks canonical base-variety normalization');
assert(milling.includes('function millRiceType(name)'),'Milling does not retain Rice Type separately');
assert(milling.includes('function katParameterKey(value)')&&['broken','moisture','damage','chalky','paddy'].every(name=>milling.includes(`katFromMaster('${name}'`)),'Milling does not consume the complete product-specific KAT profile');
assert(!milling.includes("m={'RAW RICE':0}"),'Milling still collapses every raw variety into one stock bucket');
assert(milling.includes("rawStockName"),'Milling physical adjustments do not preserve their raw variety');
assert(milling.includes("primary?(r.displayName||finished.displayName)"),'Finished stock is not derived from the FINISHED product identity');
assert(milling.includes("display=s.displayName||millProductIdentity"),'Printed Pohanch still uses an unstaged variety label');
assert(milling.includes("n:'Arrival — '+x.truck+' — '+display"),'Rice ledger still hides the arrival product stage');
assert(bridgeRuntime.includes("productStage:'READY'"),'Ex-Mill stock is not classified as READY');
assert(bridgeRuntime.includes('displayName=readyDisplay(baseVariety)')&&bridgeRuntime.includes("productStage:'READY',displayName"),'Ex-Mill stock lacks its READY display identity');
assert(exportsApp.includes('commercialProductName'),'Exports commercial-name stage stripping missing');

assert(commodityBills.includes("$deductionKg=round($weight*$deductionPer100/100,3)"),'Corn/Sesame KAT is not deducted in kilograms');
assert(commodityBills.includes("$value=round($netKg/$maund*$rate,2)"),'Corn/Sesame payable value is not based on net kg divided by maund');

console.log('Product-stage architecture audit passed.');
