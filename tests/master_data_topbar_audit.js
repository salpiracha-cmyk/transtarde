const fs=require('fs');
const assert=require('assert');

const modulePhp=fs.readFileSync(__dirname+'/../module.php','utf8');
const customerMaster=fs.readFileSync(__dirname+'/../customer-master.js','utf8');
const exportIndex=fs.readFileSync(__dirname+'/../exports/index.html','utf8');
const milling=fs.readFileSync(__dirname+'/../milling/Transtrade_Master_Milling_V3_3_2_AUDITED.html','utf8');

assert.match(modulePhp,/master\.id='ttMasterTop'/,'operational header creates one Master Data button');
assert.match(modulePhp,/master\.textContent='M'/,'Master Data button is the requested bold M');
assert.doesNotMatch(modulePhp,/master\.dataset\.homeRole='admin-only'/,'M remains visible to ordinary module users');
assert.match(modulePhp,/top\.insertBefore\(master,date\)/,'M is fixed immediately before the header date');
assert.match(modulePhp,/window\.TTOpenMasterData/,'Exports M opens the existing Export master workspace');
assert.match(modulePhp,/window\.openPanel\('masters'\)/,'Milling M opens the existing Milling master workspace');
assert.match(modulePhp,/#ttMasterTop[^}]*font:900 18px\/1 Arial/,'M is styled in bold');
assert.doesNotMatch(modulePhp,/\.stock-prominent,#ttMasterTop/,'permission filtering does not hide M from ordinary users');
assert.match(modulePhp,/if\(target\.id==='ttMasterTop'\)\{current='masters';return\}/,'ordinary users may open Master Data while subsequent actions remain permission controlled');
assert.match(customerMaster,/window\.TTOpenMasterData=openManager/,'Export Master Data workspace exposes a safe top-bar entry point');
assert.match(exportIndex,/customer-master\.js\?v=20260911-header-m-2/,'Exports invalidates the cached Master Data controller');
assert.doesNotMatch(milling,/data-home-role="admin-only" onclick="openPanel\('masters'\)"/,'Milling no longer shows Master Data as a home tile');
assert.match(milling,/<section id="masters" class="panel">/,'Milling retains the underlying Master Data workspace opened by M');

console.log('PASS operational Master Data uses permission-aware top-bar M before date; Milling home tile removed; workspaces retained');
