'use strict';
const assert=require('assert');
const fs=require('fs');
const read=file=>fs.readFileSync(file,'utf8');

const stage=read('product_stage.php');
const masters=read('auth_store.php');
const masterApi=read('api/masters.php');
const workflows=read('api/accounts_workflows_v1.php');
const sodaUi=read('accounts/accounts-v1-workflow-ui.js');
const bridge=read('accounts/source-bridge.js');
const lookup=read('api/commodity_lookup.php');
const bags=read('api/bag_purchases.php');
const bagsUi=read('accounts/bag-purchases-ui.js');
const milling=read('milling/Transtrade_Master_Milling_V3_3_2_AUDITED.html');
const exportsApp=read('exports/app.js');

for(const value of ['RAW','READY','FINISHED']) assert(stage.includes(`'${value}'`),`canonical ${value} stage missing`);
assert(stage.includes("$stage==='RAW'" )&&stage.includes("$stage==='READY'"),'Rice stage display rules missing');
assert(stage.includes("/^READY\\s+RICE"),'legacy own-mill Ready Rice compatibility missing');

assert(masters.includes("'purchase_products'=>["),'Purchase Products master missing');
assert(masterApi.includes("'purchase_products'=>10"),'Purchase Products API schema missing');
assert(masterApi.includes('Create the Rice base variety in Export Products first'),'shared base-product identity guard missing');

assert(workflows.includes("'purchaseProducts'=>tt_purchase_product_profiles()"),'Soda API does not expose Purchase Products');
assert(workflows.includes("'purchaseProductId'=>$purchaseProduct['id']"),'Soda does not persist the selected Purchase Product');
assert(workflows.includes("'amendmentHistory'"),'Soda amendment history missing');
assert(sodaUi.includes('Search Previous Soda'),'Soda search is missing');
assert(sodaUi.includes('Amendment Reason'),'Soda amendment reason is missing');
assert(sodaUi.includes('Select from Purchase Products / KAT'),'Soda still lacks master selection');

for(const key of ['baseVariety','productStage','displayName']){
  assert(bridge.includes(key),`Pohanch bridge missing ${key}`);
  assert(lookup.includes(`'${key}'`),`Accounts lookup missing ${key}`);
}

assert(bags.includes('salesTaxInvoice'), 'bag bill tax-invoice flag missing');
assert(bags.includes("if($hasTax&&(float)$b['gstAmount']>0)"),'GST recoverable posting is not conditional');
assert(bags.includes("array_key_exists('salesTaxInvoice',$b)"),'legacy bag-tax invoices are not backward compatible');
assert(!bags.includes('Non-Woven bags are excluded'),'Non-Woven is still excluded from unified bag billing');
assert(bagsUi.includes('Sales Tax Invoice received'),'bag invoice checkbox missing');
assert(bagsUi.includes('Every bag type is available'),'bag workflow is not unified');

assert(milling.includes("inputStage:isReprocessingMill()?'FINISHED':'RAW'"),'reprocessing is not FINISHED to FINISHED');
assert(milling.includes("outputStage:'FINISHED'"),'own-mill finished stage missing');
assert(milling.includes("productStage:identity.productStage"),'Pohanch stage metadata missing');
assert(exportsApp.includes('commercialProductName'),'Exports commercial-name stage stripping missing');

console.log('Product-stage architecture audit passed.');
