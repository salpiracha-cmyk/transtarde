const fs=require('fs');
const assert=require('assert');
const app=fs.readFileSync(__dirname+'/../../exports/app.js','utf8');
const css=fs.readFileSync(__dirname+'/../../exports/app.css','utf8');
const customerMaster=fs.readFileSync(__dirname+'/../../customer-master.js','utf8');
const operations=fs.readFileSync(__dirname+'/../../api/operations.mysql.php','utf8');

assert.match(app,/id='cCustomerSearch'/,'new Sales Contract uses a type-ahead customer field');
assert.match(app,/\.startsWith\(q\)/,'customer matches are prefix-filtered as staff type');
assert.match(app,/data-customer-pick/,'matching customers are clickable');
assert.match(app,/Contract fields remain empty until a customer is selected/,'contract fields remain gated until customer selection');
assert.match(app,/ref:'',seller:'TTI',customerId:'',date:''/,'new contract starts without reference or date values');
assert.match(app,/next\.ref=nextContractRef\(customerId,next\.seller\)/,'selected customer receives the next +1 reference');
assert.match(app,/function reusableContractTemplate\(customerId\)/,'last customer contract is available as a reusable template');
assert.match(app,/const reusable=\['seller','product'/,'previous contract commercial fields load after customer selection');
assert.match(css,/\.customerMatches/,'customer result list is styled below the search box');
assert.doesNotMatch(customerMaster,/nav\.appendChild\(b\)/,'Master Data is not injected beside +FI');
assert.match(operations,/2026-09-11-operational-reset-v1/,'one-time operational reset is versioned');
for(const key of ["'fi', 'contracts', 'shipments', 'accountsReceipts', 'alerts'"])assert.ok(operations.includes(key),'operational reset arrays missing');
assert.match(operations,/customer and shared masters preserved/,'reset explicitly preserves master data');
assert.match(operations,/operations_reset_export_documents/,'uploaded operational documents are included in reset');
assert.match(operations,/currentMarker !== '' && \$incomingMarker !== \$currentMarker/,'stale clients cannot restore reset Export data');

console.log('PASS Export reset, blank new contract, prefix customer picker, +1 reference and removed Master Data nav icon');
