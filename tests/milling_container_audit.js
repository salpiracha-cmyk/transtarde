const fs=require('fs');
const assert=require('assert');
const vm=require('vm');

const html=fs.readFileSync(__dirname+'/../milling/Transtrade_Master_Milling_V3_3_2_AUDITED.html','utf8');
const start=html.indexOf('function containerFormat');
const end=html.indexOf('function containerExists');
assert.ok(start>=0&&end>start,'container helper functions must exist');

const context={};
vm.runInNewContext(html.slice(start,end),context);

assert.equal(context.containerFormat('ttqu0000083'),'TTQU000008-3','container input is normalized');
assert.equal(context.containerIdentity('TTQU000008-3'),'TTQU000008','permanent identity excludes the derived check digit');
assert.equal(context.containerIdentity('TTQU000008-6'),'TTQU000008','changing only the check digit cannot create another identity');
assert.equal(context.validContainer('TTQU000008-3'),true,'valid ISO 6346 check digit is accepted');
assert.equal(context.validContainer('TTQU000008-6'),false,'wrong ISO 6346 check digit is rejected');
assert.match(html,/function containerExists\(c\)[\s\S]{0,700}containerIdentity\(v\)===id/,'duplicate lookup compares permanent container identities');
assert.ok(html.includes('Changing only the check digit is not allowed.'),'operator receives a clear duplicate-identity message');

const syncStart=html.indexOf('function syncContainerToExport');
const syncEnd=html.indexOf('function clearContainerForm',syncStart);
const syncBody=html.slice(syncStart,syncEnd);
assert.doesNotMatch(syncBody,/set\(STORE_EXPORTSYNC/,'new container saves must use one canonical tt30ship write, not a second partial Export-sync write');
assert.match(html,/if\(containerCommitPending\)\{const pending=containerCommitPending;containerCommitPending=null;currentShipment=pending\.s\.id;editingContainerId=pending\.c\.id/,'a failed shared save must reopen the same stable record for correction and full validation');
assert.match(html,/RETRY OR CORRECT CONTAINER SAVE/,'the operator must be told that the pending record can be corrected');
assert.match(html,/SHIPMENT: \$\{escHtml\(x\._ttShipmentId[\s\S]{0,300}CONTRACT: \$\{escHtml\(x\.contractRef[\s\S]{0,300}LOT: \$\{escHtml\(x\.ref/,'selected Milling loading instructions must show the exact shipment, contract, and lot identity');
console.log('PASS milling container audit: ISO identity, canonical persistence, and failed-save correction are enforced');
