const fs=require('fs');
const assert=require('assert');
const source=fs.readFileSync(__dirname+'/../../customer-master.js','utf8');
const api=fs.readFileSync(__dirname+'/../../api/masters.php','utf8');

assert.match(source,/tt-cm-icon-grid/,'Master Data starts with an icon grid');
assert.match(source,/activeMaster===b\.dataset\.cmMaster\?'':b\.dataset\.cmMaster/,'same icon closes and another icon replaces the open master');
assert.match(source,/id="ttCmMasterDetail"/,'selected master renders directly below the icon row');
for(const label of ['Customers & Notify Parties','Products & Quality','Documents Presented','Other Terms','Other Parties','Mills & Locations','Companies','Banks & Accounts'])assert.ok(source.includes(label),label+' icon is missing');
assert.match(source,/renderCustomerList\(detail\)/,'customer records render only in their selected section');
assert.match(source,/renderSharedList\(detail,type\)/,'shared records render only in their selected section');
assert.match(source,/SAVE CHANGES/,'explicit final save is retained');
assert.match(source,/Updates are restricted to the Super Admin/,'shared-master updates remain permission controlled');
assert.match(api,/['"]export_documents['"]=>5/,'document master updates are accepted by the protected master API');
assert.match(api,/['"]export_terms['"]=>3/,'term master updates are accepted by the protected master API');

console.log('PASS Export Master Data icon accordion: one selected section, below-row details, explicit permission-controlled updates');
