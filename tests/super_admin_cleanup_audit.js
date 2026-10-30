const fs = require('fs');
const assert = require('assert');

const html = fs.readFileSync(__dirname + '/../index.html', 'utf8');
const app = fs.readFileSync(__dirname + '/../admin/app.js', 'utf8');
const cleanup = fs.readFileSync(__dirname + '/../scripts/cleanup_qa_data.php', 'utf8');
const workflow = fs.readFileSync(__dirname + '/../.github/workflows/qa-data-cleanup.yml', 'utf8');

assert.doesNotMatch(html, /TTI-EXP-042|Late Soda delivery|Jazib requested print access/, 'Super Admin has no hard-coded demo activity');
assert.match(html, /No pending approvals/, 'Super Admin has an honest approval empty state');
assert.match(html, /No verified notifications/, 'Super Admin has an honest notification empty state');
assert.doesNotMatch(app, /status: "Live trial"/, 'connected modules are no longer labelled as trials');
assert.match(app, /const STATE_VERSION = 5/, 'browser console state migrates once');
assert.match(app, /localStorage\.removeItem\(STORAGE_KEY\)/, 'legacy cached Master, staff and demo audit rows are removed');
assert.match(app, /initial\.audit=\[\]/, 'browser audit starts empty rather than hydrating old demo records');
assert.match(cleanup, /pre-qa-cleanup/, 'server cleanup creates a protected snapshot first');
assert.match(cleanup, /QA\|TEST\|DUMMY\|DEMO\|BULK/, 'cleanup is limited to explicit test markers');
assert.match(cleanup, /QA_ACCOUNTS_PREFIX/, 'Accounts cleanup requires its exact QA prefix');
assert.match(workflow, /workflow_run:[\s\S]*Manual Hostinger Live QA/, 'cleanup follows live QA');
assert.match(workflow, /workflow_run\.conclusion == 'success'/, 'failed QA cannot trigger cleanup');
assert.match(workflow, /POST_CLEANUP_RECOVERY_AUDIT/, 'cleanup proves no business contract became missing');
console.log('PASS Super Admin placeholders removed and scoped QA cleanup protected');

// Exercise the actual tab controller against the rendered owner markup.
const {JSDOM}=require('jsdom');
const dom=new JSDOM(html,{url:'https://fixture.test/',runScripts:'outside-only'}),w=dom.window;
w.loadBackupStatus=()=>{};w.toast=()=>{};w.backupFetchJson=()=>{throw new Error('Tabs must never submit credentials');};
const tabs=app.slice(app.indexOf('  function selectBackupTab('),app.indexOf('  document.getElementById("refreshBackupStatus").addEventListener'));
w.eval(tabs);
const d=w.document;
assert.equal(d.getElementById('backup-security').hidden,true);
assert.equal(d.getElementById('backup-security').contains(d.getElementById('downloadOfficeSetup')),true);
assert.equal(d.getElementById('issueOfficeBackupToken'),null);
assert.equal(d.getElementById('officeBackupToken'),null);
d.getElementById('sharedBackupPassword').value='fixture-draft-password';
d.getElementById('backup-tab-security').click();
assert.equal(d.getElementById('backup-files').hidden,true);
assert.equal(d.getElementById('backup-security').hidden,false);
assert.equal(d.getElementById('backup-tab-security').getAttribute('aria-selected'),'true');
d.getElementById('backup-tab-security').dispatchEvent(new w.KeyboardEvent('keydown',{key:'ArrowLeft',bubbles:true}));
assert.equal(d.getElementById('backup-files').hidden,false);
assert.equal(d.getElementById('sharedBackupPassword').value,'fixture-draft-password');
assert.ok([...d.querySelectorAll('iframe[data-security-src]')].every(f=>!f.getAttribute('src')));
w.close();
console.log('PASS owner backup/security tab separation, keyboard navigation and retained drafts without credential submission');

(async()=>{
  const testDom=new JSDOM(html,{url:'https://fixture.test/',runScripts:'outside-only'}),win=testDom.window;
  const submitted=[],actions=[];
  win.HTMLFormElement.prototype.submit=function(){submitted.push({action:this.action,method:this.method,target:this.target,fields:Object.fromEntries([...this.elements].map(x=>[x.name,x.value]))});};
  win.sharedBackupPasswordConfigured=true;win.SESSION={csrf:'fixture-csrf'};win.toast=()=>{};win.selectBackupTab=()=>{};win.backupBytes=n=>n+' bytes';win.setTimeout=fn=>{fn();return 0;};
  win.backupFetchJson=async(url,options)=>{assert.equal(url,'api/owner_backup.php');const data=JSON.parse(options.body);actions.push(data);return data.action==='prepare'?{job:'a'.repeat(32),state:'preparing',stage:'building'}:{job:'a'.repeat(32),state:'ready',length:1000,sha256:'b'.repeat(64)};};
  win.eval(app.slice(app.indexOf('  async function downloadBackup(kind) {'),app.indexOf('  async function createServerSnapshot()')));
  await win.downloadBackup('business-download');
  assert.deepEqual(actions.map(x=>x.action),['prepare','status']);assert.equal(submitted.length,1);
  assert.equal(submitted[0].action,'https://fixture.test/api/owner_backup.php');assert.equal(submitted[0].method,'post');
  assert.deepEqual(submitted[0].fields,{action:'file',kind:'business',job:'a'.repeat(32),csrf:'fixture-csrf'});
  assert.match(win.document.getElementById('businessDownloadStatus').textContent,/verified.*Download requested/);
  win.backupFetchJson=async()=>({job:'a'.repeat(32),state:'failed',error:'fixture preparation failure'});
  await win.downloadBackup('full-download');assert.equal(submitted.length,1);
  assert.match(win.document.getElementById('fullDownloadStatus').textContent,/fixture preparation failure/);
  assert.equal(win.document.getElementById('downloadFullBackup').disabled,false);
  testDom.window.close();console.log('PASS background preparation progress, native POST download without password URLs, and persistent failure state');
})().catch(error=>{console.error(error);process.exitCode=1;});
