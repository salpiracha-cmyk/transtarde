const fs = require('fs');
const assert = require('assert/strict');
const { JSDOM } = require('jsdom');
const { webcrypto } = require('crypto');
const dom = new JSDOM(`<body data-standalone-restore>
<input id="restoreBackupFile"><input id="restoreBackupPassword"><input id="restoreConfirmation">
<button id="verifyBackupFile"></button><button id="restoreBackupNow"></button><button id="continueRestore"></button><button id="clearRestore"></button>
<div id="restoreVerification"></div><div id="restoreConfirmArea"></div><div id="restoreResult"></div></body>`, { url: 'https://fixture.invalid/restore.php', runScripts: 'outside-only' });
const w = dom.window;
Object.defineProperty(w.crypto, 'subtle', { value: webcrypto.subtle });
w.TT_SESSION = { csrf: 'fixture' };w.confirm = () => true;
let saved = { state: 'empty' }, fail = true;
const calls = [];
w.fetch = async (url, options) => {
  const body = options.body && !url.includes('?') ? JSON.parse(options.body) : null;
  const action = url.includes('?') ? 'chunk' : body?.action || 'status';calls.push(action);
  if (action === 'start' && saved.state === 'empty') saved = { id: 'fixture', state: 'uploading', size: body.size, received: 0 };
  if (action === 'chunk') { if (fail) { fail = false; throw new Error('Fixture interrupted connection'); } saved.received = saved.size; }
  if (action === 'verify') saved = { ...saved, state: 'verified', total: 3, createdAt: '2026-10-26', backupRevision: 'same', installedRevision: 'same' };
  if (action === 'confirm') saved.state = 'safety';
  if (action === 'step') saved = { ...saved, state: 'done', restoredFiles: 3, safetySnapshot: 'fixture-safety' };
  return { ok: true, json: async () => ({ ...saved, ok: true }) };
};
w.eval(fs.readFileSync('admin/restore.js', 'utf8'));
const tick = () => new Promise(resolve => setTimeout(resolve, 0));
(async () => {
  await tick();
  const bytes = new Uint8Array([1, 2, 3]).buffer;
  Object.defineProperty(w.document.getElementById('restoreBackupFile'), 'files', { value: [{ name: 'fixture.zip', size: 3, slice: () => ({ arrayBuffer: async () => bytes }) }] });
  w.document.getElementById('restoreBackupPassword').value = 'FixturePassword123!';
  await w.TT_RESTORE.verify();
  assert.match(w.document.getElementById('restoreVerification').textContent, /interrupted/);
  assert(!calls.includes('confirm'));
  await w.TT_RESTORE.verify();
  assert.equal(saved.state, 'verified');assert.equal(w.document.getElementById('restoreConfirmArea').hidden, false);
  assert.equal(w.document.getElementById('restoreBackupNow').disabled, true);
  assert.equal(w.localStorage.length, 0);assert.equal(w.document.getElementById('restoreBackupPassword').value, '');
  await w.TT_RESTORE.confirm();assert(!calls.includes('confirm'));
  w.document.getElementById('restoreConfirmation').value = 'RESTORE';w.TT_RESTORE.enable();
  await w.TT_RESTORE.confirm();assert.equal(saved.state, 'done');assert.match(w.document.getElementById('restoreVerification').textContent, /RESTORE COMPLETE/);
  assert.equal(calls.filter(x => x === 'confirm').length, 1);
  assert(!fs.readFileSync('admin/restore.js', 'utf8').includes('saveState('));
  console.log('WEBSITE_RESTORE_UI_INTERRUPTION_EXPLICIT_CONFIRM_OK');dom.window.close();
})().catch(e => { console.error(e);process.exitCode = 1;dom.window.close(); });
