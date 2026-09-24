const fs=require('node:fs');
const assert=require('node:assert/strict');

const receiptApi=fs.readFileSync('api/export_receipts.php','utf8');
const bagSync=fs.readFileSync('accounts/bag-ops-sync-ui.js','utf8');
const moduleBridge=fs.readFileSync('accounts/bag-control-bridge.js','utf8');
const exportApp=fs.readFileSync('exports/app.js','utf8');

assert.match(exportApp,/const STORE='transtrade_export_v3_operational'/,'Exports must use the V3 operational store');

assert.match(receiptApi,/TT_EXPORT_STORE_KEY = 'transtrade_export_v3_operational'/,'receipt API must target the live V3 store');
assert.match(receiptApi,/tt_operation_records/,'receipt API must read and update MySQL operational storage');
assert.match(receiptApi,/FOR UPDATE/,'receipt injection must lock the current V3 record');
assert.match(receiptApi,/accountsReceipts/,'receipts must populate the field consumed by Exports');
assert.match(receiptApi,/tt_operation_history/,'receipt injection must preserve V3 history');
assert.match(receiptApi,/\['version'=>\$keyVersion/,'file fallback must advance the key version');
assert.doesNotMatch(receiptApi,/transtrade_export_v2_operational/,'receipt API must never fall back to the retired V2 Export state');
assert.doesNotMatch(receiptApi,/\$root\['receipts'\]/,'receipt API must not write the retired receipt collection');

assert.doesNotMatch(bagSync,/method:\s*['"]POST['"]/,'opening the Accounts Bags workspace must never write or synchronize in the background');
assert.match(bagSync,/TT_BAG_PURCHASES_UI\?\.reload/,'opening the Accounts Bags workspace may refresh its server-owned view');
assert.doesNotMatch(bagSync,/operations\.php/,'Accounts bag sync must not call the retired endpoint');
assert.doesNotMatch(bagSync,/transtrade_export_v2_operational/,'Accounts bag sync must not read retired Export data');

assert.match(moduleBridge,/transtrade_export_v3_operational/,'module bag bridge must use current Export data');
assert.match(moduleBridge,/tt:shared-saved/,'module bag handoffs must follow an acknowledged explicit workflow save');
assert.doesNotMatch(moduleBridge,/transtrade_export_v2_operational/,'no module bag bridge may retain the retired Export key');

console.log('PASS Accounts ↔ Exports V3 receipt and bag linkage audit');
