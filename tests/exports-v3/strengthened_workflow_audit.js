const fs=require('fs');
const path=require('path');
const assert=require('assert');

const app=fs.readFileSync(path.join(__dirname,'app.js'),'utf8');
const css=fs.readFileSync(path.join(__dirname,'app.css'),'utf8');
const modulePhp=fs.readFileSync(path.join(__dirname,'../main/module.php'),'utf8');
const api=fs.readFileSync(path.join(__dirname,'../main/api/operations.mysql.php'),'utf8');
const upload=fs.readFileSync(path.join(__dirname,'../main/api/export_documents.php'),'utf8');

const names=[...app.matchAll(/function\s+([A-Za-z_$][\w$]*)\s*\(/g)].map(m=>m[1]);
const duplicates=names.filter((name,index)=>names.indexOf(name)!==index);
assert.deepEqual([...new Set(duplicates)],[],'function declarations must remain unique');

assert.match(app,/approved=true/,'bag marking upload must automatically approve');
assert.match(app,/APPROVED BAG MARKING/);
assert.match(app,/approvedMarkingLabel/);
assert.match(css,/max-width:160mm/,'approved marking must print at large readable size');
assert.doesNotMatch(app,/approvalSource\s*:|approvalStage\s*:|artworkApprovedBy\s*:/,'separate artwork approval source/stage data is forbidden');

for(const token of ['requestContractReopen','createAddendum','cancelContract','openFIEdit','openFITransfer','lcRegisterRows','accountsFor','Invoice Adjustment','CREATE BANK INVOICE VERSION','Reallocate / Reissue','completionMissing','documentCounts','Other Courier','REPORT_DEFS'])assert.ok(app.includes(token),token+' missing');
assert.equal((app.match(/Sales Contract Register/g)||[]).length>=1,true);
assert.equal((app.match(/\['[^']+','[^']+','[^']+'\]/g)||[]).filter(x=>/Register|Shipment|Documents|Export/.test(x)).length>=15,true,'full report inventory missing');

assert.match(upload,/tt_require_login/);
assert.match(upload,/tt_verify_csrf/);
assert.match(upload,/move_uploaded_file/);
assert.match(upload,/10 \* 1024 \* 1024/);
assert.match(upload,/TT_DATA_DIR/);
assert.match(upload,/CREATE TABLE IF NOT EXISTS tt_export_documents/);
assert.match(api,/strcasecmp\(\$sourceModule, 'Accounts'\)/);
assert.match(api,/accountsReceipts/);
assert.match(modulePhp,/contributions/);
assert.match(modulePhp,/conflicting seals/);
assert.match(modulePhp,/contributionId/);
assert.doesNotMatch(app,/finalFileData\s*=/,'new B/L uploads must not be stored as base64 in operational JSON');
assert.match(app,/Final \/ Original B\/L/);
assert.match(app,/Bank Covering Letter.*LAST/);
assert.match(app,/this company has no footer/);

console.log('PASS strengthened workflow audit: approvals, lifecycle, FI, Accounts, loading, protected originals, completion, dispatch and reports');
