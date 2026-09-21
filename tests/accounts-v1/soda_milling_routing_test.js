'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const read=file=>fs.readFileSync(file,'utf8');

const desk=read('accounts/accounts-accounting-desk.js');
const soda=read('api/purchase_sodas.php');
const bills=read('api/commodity_bills.php');
const feed=read('api/milling_purchase_sodas.php');
const mill=read('milling/Transtrade_Master_Milling_V3_3_2_AUDITED.html');
const bridge=read('accounts/source-bridge.js');
const index=read('accounts/index.php');
const enhancements=read('accounts/accounts-enhancements.js');

assert.match(desk,/PAYMENT TERM/);
assert.match(desk,/ttSdCreditWrap/);
assert.match(desk,/\.tt-layer \[hidden\]\{display:none!important\}/);
assert.match(desk,/Cash payment becomes due on Arrival \/ Pohanch date \+ 2 days/);
assert.match(desk,/Credit Days are required|days\.required=isCredit/);
assert.match(soda,/\$payment==='CREDIT'&&\(\$creditDays < 1/);
assert.match(bills,/\$term==='CASH'\?2:cb_credit_days/);

assert.match(desk,/rawPurchaseProductId/);
assert.match(soda,/purchase-products-rice-irri6-white-raw/);
assert.match(soda,/Select an active Raw or Ready purchase product/);
assert.doesNotMatch(desk,/id="ttSdVariety"/);

assert.match(soda,/ps_stock_types/);
assert.match(soda,/Office locations are not allowed/);
assert.match(soda,/\['Own Mill','Reprocessing Mill','Warehouse','Stock Location'\]/);
assert.match(desk,/Choose route — no default/);
assert.match(desk,/EX-MILL — remains at outside mill/);
assert.match(desk,/DELIVER TO OUR MILL \/ STOCK LOCATION/);
assert.match(soda,/\$route==='EX_MILL'/);
assert.match(soda,/\$location\['type'\].*External Mill/);
assert.doesNotMatch(desk,/Lifting Location/i);

for (const id of ['ttSdAddProduct','ttSdRemoveProduct','ttSdAddBroker','ttSdRemoveBroker','ttSdAddSupplier','ttSdRemoveSupplier','ttSdAddStock','ttSdRemoveStock','ttSdAddMill','ttSdRemoveMill']) {
  assert.match(desk,new RegExp(`id="${id}"`),`${id} add/remove control missing`);
}
assert.match(desk,/add_party_category/);
assert.match(desk,/remove_party_category/);
assert.match(soda,/tt_business_party_has_category\(\$v\[2\],'Supplier'\)/);
assert.match(soda,/canAddProduct/);
assert.match(soda,/canRemoveLocation/);

assert.match(soda,/linkedExternalMillId/);
assert.match(desk,/suggestSupplierMill/);
assert.match(desk,/locationTouched/);
assert.match(soda,/link_supplier_mill/);
assert.match(soda,/add_location/);
assert.match(soda,/tt_find_location_duplicate/);

assert.match(feed,/purchaseSodas/);
assert.match(feed,/purchaseSodasV2/);
assert.match(mill,/api\/milling_purchase_sodas\.php/);
assert.match(mill,/id="qSoda"/);
assert.match(mill,/\['RAW','READY'\]\.includes\(x\.stage\)/);
assert.match(mill,/stage=linked\?\.productStage\|\|selected\?\.productStage\|\|'RAW'/);
assert.match(mill,/ready=identity\.productStage==='READY'/);
assert.match(mill,/brokenKat:ready\?'':/);
assert.match(mill,/completedHistoryPreserved:true/);
assert.match(mill,/_ttPurchaseSodaId/);
assert.doesNotMatch(mill,/const demo=\{'Shams Broker'/);
assert.match(bridge,/sourceSodaId:saved\.sourceSodaId/);
assert.match(bridge,/purchaseProductId:saved\.purchaseProductId/);
assert.match(bills,/\['purchaseSodas','purchaseSodasV2'\]/);

assert.match(index,/tt-accounts-boot/);
assert.doesNotMatch(enhancements,/await load\(\);buildEntityLanding/);
assert.match(desk,/classList\.remove\('tt-accounts-boot'\)/);

console.log('Accounts Soda → Milling routing and stable startup audit passed.');
