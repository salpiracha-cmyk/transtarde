// Read-only fixture inspection. No production requests or real user mutations.
const fs = require('node:fs');
const assert = require('node:assert/strict');
const { JSDOM, VirtualConsole } = require('jsdom');
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));

(async () => {
  const requests = [], opened = [], errors = [], dialogs = [];
  const console = new VirtualConsole();
  console.on('jsdomError', error => errors.push(error.message));
  const dom = new JSDOM(fs.readFileSync('index.html', 'utf8'), {
    url: 'https://fixture.test/index.php', runScripts: 'outside-only', pretendToBeVisual: true,
    virtualConsole: console,
  });
  const w = dom.window;
  const staff = { id: 'fixture-staff', name: 'Fixture Staff', username: 'fixture.staff', role: 'Director', location: 'All authorized locations', active: true, modules: ['Accounts', 'Directors'], permissions: { Accounts: { reports: ['View'] }, Directors: { dashboard: ['View'], tgmaster: ['View', 'Edit'] } } };
  w.TT_SESSION = { id: 'fixture-owner', name: 'Fixture Owner', role: 'Super Admin', permissions: {}, csrf: 'fixture' };
  w.alert = message => dialogs.push(message);
  w.confirm = message => { dialogs.push(message); return true; };
  w.open = (...args) => opened.push(args);
  w.scrollTo = () => {};
  w.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
  w.HTMLDialogElement.prototype.showModal = function () { this.open = true; };
  w.HTMLDialogElement.prototype.close = function () { this.open = false; };
  w.fetch = async (url, options = {}) => {
    requests.push({ url: String(url), method: options.method || 'GET', body: options.body ? JSON.parse(options.body) : null });
    return { ok: true, json: async () => ({ ok: true, users: [staff], masters: {}, options: {}, approvals: [], audit: [{ action: 'SERVER_AUDIT_SENTINEL' }], serverSnapshots: [], snapshots: [], office: {} }) };
  };
  const code = fs.readFileSync('admin/app.js', 'utf8').replace('  initialize();', '  window.__review={MODULES,MASTER_TYPES,MASTER_GROUPS,MODULE_ICONS,state,toggleLock,renderAudit,openUserDialog,saveUser}; initialize();');
  w.eval(code);
  await pause(60);
  const nav = [];
  for (const button of w.document.querySelectorAll('.nav-item[data-view]')) {
    button.click();
    nav.push({ view: button.dataset.view, active: w.document.querySelector('.view.active')?.id });
  }
  assert(nav.every(item => item.active === 'view-' + item.view));
  w.document.querySelector('[data-open-module="directors"]').click();
  const directorCard = w.document.querySelector('[data-open-module="directors"]').closest('.module-card');
  const directors = { opened: opened.length, text: directorCard.textContent.replace(/\s+/g, ' ').trim(), routeExists: fs.existsSync('directors/index.php') };
  requests.length = 0;
  w.__review.toggleLock('exports');
  const lock = { locked: w.__review.state.locks.exports.locked, requests: [...requests], persistedLocally: JSON.parse(w.localStorage.getItem('transtrade_super_admin_v1')).locks.exports.locked };
  w.__review.renderAudit();
  const audit = { serverSentinelVisible: w.document.querySelector('#auditRows').textContent.includes('SERVER_AUDIT_SENTINEL'), source: 'state.audit loaded from localStorage' };
  w.__review.openUserDialog(staff.id);
  const directorsCheckboxes = w.document.querySelectorAll('[data-permission-module="Directors"]').length;
  requests.length = 0;
  await w.__review.saveUser({ preventDefault() {} });
  const userEdit = { originalDirectorPermissions: staff.permissions.Directors, directorsCheckboxes, submittedPermissions: requests.find(item => item.method === 'POST')?.body?.permissions };
  const masters = w.__review.MASTER_TYPES.map(type => ({ id: type.id, name: type.name, fields: type.fields.map((field, index) => ({ index, label: field.label, required: !!field.required, type: field.type || 'text' })) }));
  const result = { baseline: '1beeac3f880fda95ab2dd722e653bdab0ee067ab', fixtureOnly: true, nav, directors, lock, audit, userEdit, masters, modulePermissionIcons: w.__review.MODULE_ICONS, errors };
  fs.writeFileSync(process.argv[2] || '/tmp/tt-console-probe.json', JSON.stringify(result, null, 2) + '\n');
  process.stdout.write(JSON.stringify({ navViews: nav.length, directors, lock, audit, userEdit, masterTypes: masters.length, fields: masters.reduce((n, x) => n + x.fields.length, 0), errors }, null, 2) + '\n');
  w.close();
})().catch(error => { process.stderr.write(error.stack + '\n'); process.exitCode = 1; });
