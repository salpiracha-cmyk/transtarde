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
assert.match(auth,/preg_split\('\/\\s\*\(\?:;\|,\|\\\/\|\\\|\)\\s\*\/u'/);
assert.match(auth,/tt_business_party_has_category\(\$v\[2\],'Broker'\)/);
assert.match(soda,/tt_business_party_has_category\(\$v\[2\],'Supplier'\)/);
assert.match(soda,/function ps_upsert_party_category/);
assert.match(soda,/function ps_remove_party_category/);
assert.match(soda,/strcasecmp\(\(string\)\(\$p\['baseVariety'\]\?\?''\),'IRRI-6'\)===0/);

assert.match(desk,/Ready Rice Route/);
assert.match(desk,/routeWrap\.hidden=!ready/);
assert.match(desk,/daysWrap\.hidden=!isCredit/);
assert.match(desk,/rawPurchaseProductId/);
assert.match(desk,/rawLocationId/);

const cleanUi=read('accounts/accounts-clean-ui.js');
assert.match(cleanUi,/const closeMenus = \(\) => qa\('\.tt-select-menu'\)/);
assert.match(cleanUi,/if \(candidate !== menu\) candidate\.hidden = true/);

console.log('Accounts Soda master fallback and legacy category compatibility audit passed.');
