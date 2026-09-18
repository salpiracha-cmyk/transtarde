const fs=require('fs');
const assert=require('node:assert/strict');
const app=fs.readFileSync(__dirname+'/../../exports/app.js','utf8');
const operations=fs.readFileSync(__dirname+'/../../api/operations.mysql.php','utf8');
const marker='/* 2026-09-12 server-confirmed permanent shipment deletion. */';
const start=app.indexOf(marker);
assert.ok(start>=0,'server-confirmed deletion override exists');
const flow=app.slice(start,app.indexOf("window.addEventListener('error'",start));

assert.match(flow,/result=deleteShipmentData\(processId\)/);
assert.match(flow,/await shared\.saveNow\(\)/);
assert.match(flow,/RETRY DELETE/);
assert.match(flow,/Retry this same deletion before leaving the screen/);
assert.match(flow,/action:'Permanently deleted'/);
assert.doesNotMatch(flow,/shared-server confirmation|confirmed by the shared server/i);

const mutate=flow.indexOf('result=deleteShipmentData(processId)');
const acknowledge=flow.indexOf('await shared.saveNow()');
const documents=flow.indexOf('await deleteShipmentDocuments(contractRef)');
const finish=flow.indexOf('renderHome();',acknowledge);
assert.ok(mutate>=0&&mutate<acknowledge,'deletion marker is prepared before server acknowledgement');
assert.ok(acknowledge<documents,'uploaded documents are removed only after the deletion marker is acknowledged');
assert.ok(documents<finish,'the main screen updates only after server acknowledgement and cleanup attempt');

const failure=flow.slice(flow.indexOf('}catch(saveError){'));
assert.doesNotMatch(failure,/renderHome\(\)/,'failed acknowledgement must keep the confirmation screen open');
assert.match(app,/function restoreReleasedContractReference\(ref\)/,'reusing an available contract reference must retire its old deletion marker');
assert.match(app,/restoreReleasedContractReference\(draft\.ref\)/,'the first server-saved draft must release an older marker for the same reference');
assert.match(operations,/Contract reference legitimately reused after deletion/,'the server must recognize a genuinely recreated contract after its prior deletion');
assert.match(operations,/\$recreatedAt\[\$ref\] <= \$deletedAt/,'the server must require recreation evidence newer than the deletion');
assert.match(operations,/permanent server decision: never let that browser reactivate/,'a stale client must not reactivate a restored deletion marker');
console.log('PASS shipment deletion waits for shared-server acknowledgement');
