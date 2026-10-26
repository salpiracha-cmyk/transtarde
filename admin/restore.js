/* Explicit owner actions only. Passwords and ZIP contents never enter browser storage. */
(() => {
  'use strict';
  const $ = id => document.getElementById(id), piece = 1048576;
  let state = { state: 'empty' }, busy = false, selectionChanged = false;
  const active = s => ['safety', 'applying', 'database', 'finishing', 'rollback', 'rollback_database'].includes(s);
  const hex = data => Array.from(new Uint8Array(data), b => b.toString(16).padStart(2, '0')).join('');
  const tell = text => { const box = $('restoreVerification'); box.hidden = false; box.textContent = text; };
  async function request(body, query = '', extra = {}) {
    const response = await fetch('api/restore.php' + query, {
      method: body === null ? 'GET' : 'POST', credentials: 'same-origin', cache: 'no-store',
      headers: { 'X-CSRF-Token': window.TT_SESSION.csrf, 'Content-Type': query ? 'application/octet-stream' : 'application/json', ...extra },
      ...(body === null ? {} : { body: query ? body : JSON.stringify(body) })
    });
    const data = await response.json().catch(() => ({ error: 'The connection stopped. Reopen Restore and continue.' }));
    if (!response.ok || !data.ok) throw new Error(data.error || 'Recovery could not continue.');
    state = data; return data;
  }
  function enable() {
    $('restoreBackupNow').disabled = busy || selectionChanged || state.state !== 'verified' || $('restoreConfirmation').value.trim().toUpperCase() !== 'RESTORE';
  }
  function render() {
    $('restoreConfirmArea').hidden = selectionChanged || state.state !== 'verified';
    $('continueRestore').hidden = !active(state.state);
    $('clearRestore').hidden = state.state === 'empty' || active(state.state);
    $('verifyBackupFile').disabled = busy || active(state.state);
    $('restoreBackupFile').disabled = busy || active(state.state);
    $('restoreBackupPassword').disabled = busy || active(state.state);
    $('continueRestore').disabled = busy; $('clearRestore').disabled = busy;
    enable();
    if (state.state === 'empty') return;
    const labels = { uploading: `Uploaded ${Math.round(100 * state.received / state.size)}%. Select the same ZIP to resume.`, verifying: `Verified ${state.verified} of ${state.total || '?'} files.`, safety: 'Creating and checking the safety copy. Other changes are paused.', applying: 'Restoring data. Other changes are paused.', database: 'Restoring the business database.', finishing: 'Checking restored records and finishing.', rollback: 'Reverting to the safety copy. Keep this page open.', rollback_database: 'Reverting the business database.' };
    if (state.state === 'verified') {
      tell(`Ready for your confirmation.\nFile: ${state.name}\nBackup date: ${state.createdAt || 'Recorded in archive'}\nVerified files: ${state.total}\nModules: Accounts, Mill, Exports, Directors; masters and documents.\nBackup software: ${state.backupRevision}\nInstalled software: ${state.installedRevision}\n${state.backupRevision !== state.installedRevision ? 'The software revisions differ. This restore supports the recorded data format; install the matching software first if you are moving to different hosting.\n' : ''}Current records will be replaced only after Confirm Restore.`);
    } else if (state.state === 'done') {
      tell(`RESTORE COMPLETE. ${state.restoredFiles} files restored and checked.\nSafety copy: ${state.safetySnapshot}\nSign in again, then check Accounts, Mill, Exports and Directors before staff resume work.`);
      $('restoreResult').replaceChildren(Object.assign(document.createElement('a'), { href: 'login.php', textContent: 'Sign in and check restored data' }));
    } else if (state.state === 'rolled_back') tell('The restore did not complete. The previous data was recovered from the safety copy. Sign in again and review before retrying.');
    else tell(`${labels[state.state] || state.error || state.state}${state.totalWork ? `\nFiles: ${state.progress} / ${state.totalWork}` : ''}`);
  }
  async function run(work) {
    if (busy) return; busy = true; render();
    try { await work(); } catch (error) { tell(`${error.message}\nYour saved progress is retained. Reopen Restore to check the status and continue.`); }
    finally { busy = false; $('verifyBackupFile').disabled = active(state.state); $('restoreBackupFile').disabled = active(state.state); $('restoreBackupPassword').disabled = active(state.state); $('continueRestore').disabled = false; $('clearRestore').disabled = false; enable(); }
  }
  async function verify() {
    selectionChanged = true; $('restoreConfirmation').value = '';
    await run(async () => {
      const file = $('restoreBackupFile').files?.[0], password = $('restoreBackupPassword').value;
      if (!file || !file.size || file.size > 0xffffffff) throw new Error('Select a Data or older Complete ZIP smaller than 4 GB.');
      if (password.length < 12) throw new Error('Enter the password used when this ZIP was created.');
      const chunks = [];
      for (let offset = 0; offset < file.size; offset += piece) {
        tell(`Identifying the selected ZIP: ${Math.floor(100 * offset / file.size)}%.`);
        chunks.push(hex(await crypto.subtle.digest('SHA-256', await file.slice(offset, offset + piece).arrayBuffer())));
      }
      await request({ action: 'start', name: file.name, size: file.size, chunks });
      while (state.state === 'uploading' && state.received < file.size) {
        const offset = state.received;
        await request(await file.slice(offset, offset + piece).arrayBuffer(), '?action=chunk', { 'X-Restore-Id': state.id, 'X-Restore-Offset': String(offset) });
        tell(`Uploading: ${Math.round(100 * state.received / file.size)}% (${state.received.toLocaleString()} of ${file.size.toLocaleString()} bytes).`);
      }
      while (['uploading', 'verifying'].includes(state.state)) { await request({ action: 'verify', id: state.id, password }); render(); }
      selectionChanged = false; $('restoreBackupPassword').value = ''; render();
    });
  }
  async function advance() { while (active(state.state)) { await request({ action: 'step', id: state.id }); render(); } }
  async function confirm() {
    if ($('restoreBackupNow').disabled || !window.confirm(`Restore ${state.name}, dated ${state.createdAt}, and replace website records? A safety copy will be checked first. All users must sign in again afterward.`)) return;
    await run(async () => { await request({ action: 'confirm', id: state.id, confirmation: 'RESTORE' }); render(); await advance(); });
  }
  async function load() { await run(async () => { await request(null); render(); }); }
  window.TT_RESTORE = { verify, confirm, enable, load };
  if (document.body.hasAttribute('data-standalone-restore')) {
    $('restoreBackupFile').addEventListener('change', () => { selectionChanged = true; $('restoreConfirmation').value = ''; render(); });
    $('verifyBackupFile').addEventListener('click', verify);
    $('restoreBackupNow').addEventListener('click', confirm);
    $('restoreConfirmation').addEventListener('input', enable);
    $('continueRestore').addEventListener('click', () => run(advance));
    $('clearRestore').addEventListener('click', () => { if (window.confirm('Clear this prepared upload? Your original PC ZIP and any safety copy remain unchanged.')) run(async () => { await request({ action: 'clear', id: state.id }); $('restoreVerification').hidden = true; render(); }); });
    load();
  }
})();
