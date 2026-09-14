const fs=require('fs');
const assert=require('assert');
const app=fs.readFileSync(__dirname+'/../../exports/app.js','utf8');
const css=fs.readFileSync(__dirname+'/../../exports/app.css','utf8');
const customerMaster=fs.readFileSync(__dirname+'/../../customer-master.js','utf8');
const operations=fs.readFileSync(__dirname+'/../../api/operations.mysql.php','utf8');
const sourceBridge=fs.readFileSync(__dirname+'/../../accounts/source-bridge.js','utf8');

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
assert.match(operations,/2026-09-14-operational-reset-v3/,'Super Admin-requested operational reset is versioned');
for(const key of ["'customers', 'suppliers', 'fi', 'contracts', 'shipments', 'accountsReceipts', 'alerts', 'deletedShipments'"])assert.ok(operations.includes(key),'full operational reset arrays missing');
assert.match(operations,/settings and shared master definitions preserved/,'reset explicitly preserves configuration and shared master definitions');
assert.match(operations,/\$root\['audits'\] = \[\[/,'prior user and bulk-test audit entries are cleared');
assert.match(operations,/operations_reset_export_documents/,'uploaded operational documents are included in reset');
assert.match(operations,/currentMarker !== '' && \$incomingMarker !== \$currentMarker/,'stale clients cannot restore reset Export data');
assert.match(app,/if\(contractStep===4&&packingDraft&&!persistPackingDraft\(\)\)return;const err=validateContractStep\(contractStep\)/,'active contract Next handler persists the visible packing before validation');
const activeEditor=app.lastIndexOf('function renderContractEditor(){');
const packingNext=app.indexOf('if(contractStep===4&&packingDraft&&!persistPackingDraft())return;',activeEditor);
assert.ok(activeEditor>=0&&packingNext>activeEditor,'packing save belongs to the final active contract editor, not an obsolete override');


assert.match(sourceBridge,/function showOutboxAttention\(\)\{document\.getElementById\('ttAccountsOutboxBadge'\)\?\.remove\(\)\}/,'Accounts retry processing stays active without exposing the pending badge');
assert.doesNotMatch(sourceBridge,/badge\.textContent=.*Accounts handoffs pending/,'Accounts pending retry badge is not recreated');
assert.match(app,/const TT_PORTS=\['Port Qasim, Pakistan','Karachi Port, Pakistan','Port Qasim, Pakistan or Karachi Port, Pakistan'\]/,'Port of Loading contains the three approved choices');
assert.match(app,/pol:'Port Qasim, Pakistan or Karachi Port, Pakistan'/,'combined Port of Loading choice is the new-contract default');
assert.match(app,/addable=key==='packing_types'/,'packing type selector exposes Add New to Export users');
assert.match(app,/settings\.customPackingTypes\.push\(added\);save\(\)/,'new Export-user packing types persist locally without Super Admin access');
assert.match(app,/terminalHandling=.*Load Port Terminal Handling Charges[\s\S]*filter\(x=>!automaticInsurance\.test\(x\)&&!automaticInspection\.test\(x\)&&!terminalHandling\.test\(x\)\);\s*if\(c\.incoterm==='FOB'\)stored\.push/,'terminal handling is removed from inherited terms and added only for FOB');
assert.match(app,/requestAnimationFrame\(\(\)=>form\?\.scrollIntoView\(\{block:'start',behavior:'auto'\}\)\)/,'Next and Back rerenders position the form at its top');
assert.match(app,/const CONTRACT_DRAFT_STORE='tt-export-contract-draft-v1'/,'Sales Contract explicit-step recovery checkpoint is defined');
assert.match(app,/contractStep\+\+;packingDraft=null;if\(!persistContractStepDraft\(\)\)return;renderContractEditor\(\)/,'each successful Next persists and checkpoints the in-progress contract');
assert.match(app,/draft.status='Draft';draft.issued=false;draft.draftStep=contractStep/,'Sales Contract drafts are saved into shared contract state with their current step');
assert.match(app,/mount\(\);restoreContractCheckpoint\(\);/,'same-user contract checkpoint is restored after reload or renewed login');
assert.match(app,/function ttPartyOutput\(party\)\{return\{name:ttProperNounOutput\(party\?\.name\|\|''\),address:ttProperNounOutput\(party\?\.address\|\|''\)\}\}/,'seller and buyer output capitalization is normalized independently of entry casing');
assert.ok(app.includes("/^(?:[A-Z]\\.)+[A-Z]?$/.test(upper)"),'dotted proper-noun abbreviations such as L.L.C. and U.A.E. remain uppercase');

console.log('PASS Export reset, blank new contract, prefix customer picker, +1 reference and removed Master Data nav icon');
