'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const read=file=>fs.readFileSync(file,'utf8');

const auth=read('auth_store.php');
const soda=read('api/purchase_sodas.php');
const desk=read('accounts/accounts-accounting-desk.js');

assert.match(auth,/foreach \(\['purchase_products','mills'\] as \$requiredType\)/);
assert.match(auth,/count\(\(array\)\$masters\[\$requiredType\]\)===0/);
assert.match(auth,/\$existingIds\[\(string\)\(\$row\['id'\] \?\? ''\)\]=true/);
assert.match(auth,/!isset\(\$existingIds\[\$defaultId\]\) && !isset\(\$existingIdentities\[\$defaultIdentity\]\)/);
assert.match(auth,/function tt_business_party_categories/);
assert.match(auth,/function tt_master_name_identity/);
assert.match(auth,/brokers\?\|suppliers\?\|vendors\?/);
assert.match(auth,/transtrade\(\?:\\s\+international\)\?/);
assert.match(auth,/function tt_master_names_conflict/);
assert.match(auth,/tt_master_name_identity\(\(string\)\(\$values\[0\] \?\? ''\),'mills'\)/);
assert.match(auth,/preg_split\('\/\\s\*\(\?:;\|,\|\\\/\|\\\|\)\\s\*\/u'/);
assert.match(auth,/tt_business_party_has_category\(\$v\[2\],'Broker'\)/);
assert.match(soda,/tt_business_party_has_category\(\$v\[2\],'Supplier'\)/);
assert.match(soda,/function ps_upsert_party_category/);
assert.match(soda,/tt_master_names_conflict\(\$name,\(string\)\$v\[0\],'business_parties'\)/);
assert.match(soda,/function ps_remove_party_category/);
assert.match(soda,/strcasecmp\(\(string\)\(\$p\['baseVariety'\]\?\?''\),'IRRI-6'\)===0/);

assert.match(desk,/Ready Rice Route/);
assert.match(desk,/routeWrap\.hidden=!ready/);
assert.match(desk,/daysWrap\.hidden=!isCredit/);
assert.match(desk,/rawPurchaseProductId/);
assert.match(desk,/rawLocationId/);

const cleanUi=read('accounts/accounts-clean-ui.js');
const masterAutocomplete=read('accounts/master-autocomplete.js');
assert.match(cleanUi,/const closeMenus = \(\) => qa\('\.tt-select-menu'\)/);
assert.match(cleanUi,/if \(candidate !== menu\) candidate\.hidden = true/);
assert.match(cleanUi,/input\.removeAttribute\('list'\)/);
assert.match(cleanUi,/select\.value === '' \? '' : selectedText\(\)/);
assert.match(cleanUi,/render\(true\)/);
assert.match(cleanUi,/event\.key === 'Enter'/);
assert.match(cleanUi,/event\.preventDefault\(\)/);
assert.match(cleanUi,/slice\(0, 100\)/);
assert.match(masterAutocomplete,/input\.closest\('\.tt-search-select'\)/);

const masterApi=read('api/masters.php');
const admin=read('admin/app.js');
assert.match(masterApi,/\$action==='purge'/);
assert.match(masterApi,/Deactivate this mill \/ location before deleting it permanently/);
assert.match(masterApi,/already linked to operational history/);
assert.match(admin,/data-purge-master/);
assert.match(admin,/row-action deactivated/);
assert.match(admin,/\[\["TTI Rice Mills", "TTI-MILL"/);

console.log('Accounts Soda master fallback and legacy category compatibility audit passed.');
