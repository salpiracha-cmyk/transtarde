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
assert.match(app, /demoRefs/, 'known legacy demo audit rows are removed');
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
