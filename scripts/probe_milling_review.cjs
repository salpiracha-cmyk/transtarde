// Disposable DOM fixture; records observations and never contacts production.
const fs = require('node:fs');
const { JSDOM, VirtualConsole } = require('jsdom');
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
(async () => {
  const errors = [], requests = [], alerts = [];
  const vc = new VirtualConsole();
  vc.on('jsdomError', error => errors.push(error.message));
  const source = fs.readFileSync('milling/Transtrade_Master_Milling_V3_3_2_AUDITED.html', 'utf8');
  const dom = new JSDOM(source, { url: 'https://fixture.test/module.php?id=milling', runScripts: 'outside-only', pretendToBeVisual: true, virtualConsole: vc });
  const w = dom.window;
  w.TT_MODULE_ACCESS = { super: true, user: 'Fixture Owner', role: 'Super Admin', permissions: 'all', masters: {} };
  w.TRANSTRADE_SERVER_NOW_ISO = '2026-10-16T12:00:00Z';
  w.scrollTo = () => {};
  w.alert = message => alerts.push(message);
  w.confirm = () => false;
  w.fetch = async (url, options = {}) => {
    requests.push({ url: String(url), method: options.method || 'GET' });
    return { ok: true, json: async () => ({ ok: true, sodas: [], accounts: [], entries: [], totals: {}, groups: [], filters: {}, entities: ['TTI', 'BRM'] }) };
  };
  w.eval(fs.readFileSync('milling/production-recovery-entry.js', 'utf8'));
  w.eval(fs.readFileSync('accounts/mill-recovery-ui.js', 'utf8'));
  for (const script of w.document.scripts) if (!script.src && script.textContent.trim()) w.eval(script.textContent);
  await pause(30);
  w.chooseMill(1);
  const panels = [];
  for (const id of ['home','queue','arrival','newbags','instructions','production','export','local','petty','labour','oldbags','reports','users','masters']) {
    const before = errors.length;
    try { w.openPanel(id); await pause(10); panels.push({ id, active: w.document.querySelector('.panel.active')?.id, errors: errors.slice(before) }); }
    catch (error) { panels.push({ id, error: error.message }); }
  }
  w.localStorage.setItem('tt30slips', JSON.stringify([{ id: 1, millId: 1, entity: 'TTI', unloadingDate: '2026-10-16', payableWeight: 1000 }, { id: 2, millId: 2, entity: 'BRM', unloadingDate: '2026-10-16', payableWeight: 9000 }]));
  w.openPanel('labour');
  w.processingTab('cost');
  w.document.querySelector('#costFrom').value = '2026-10-16';
  w.document.querySelector('#costTill').value = '2026-10-16';
  w.renderMillingCostReport();
  const oldCostReport = { selectedMill: 'TTI Rice Mills', ownArrivalKg: 1000, otherCompanyArrivalKg: 9000, text: w.document.querySelector('#costReportOutput').textContent.replace(/\s+/g, ' ').trim() };
  w.openPanel('users');
  w.document.querySelector('#userName').value = 'fixture.staff';
  w.document.querySelector('#userDisplay').value = 'Fixture Staff';
  const beforePermission = requests.length;
  w.saveUserPermission();
  const permission = { operationalRows: JSON.parse(w.localStorage.getItem('tt37users') || '[]'), requests: requests.slice(beforePermission) };
  const masterSection = w.document.querySelector('#masters');
  const masters = { hardcodedBankNames: [...masterSection.querySelectorAll('tr')].map(row => row.textContent).filter(text => /Meezan Bank|Bank Alfalah|HBL|Collection Account/.test(text)), prototypeMigrationPresent: /Prototype Test Data Migration/.test(masterSection.textContent), directNavigation: [...w.document.querySelectorAll('[onclick]')].filter(el => /openPanel\('masters'\)/.test(el.getAttribute('onclick'))).length };
  w.processingTab('rent');
  w.document.querySelector('#rentPayAmount').value = '25000';
  w.document.querySelector('#rentPayFrom').value = '2026-10';
  w.document.querySelector('#rentPayTill').value = '2026-10';
  w.document.querySelector('#rentPayRef').value = 'FIXTURE-RENT-ONLY';
  const beforeRent = requests.length;
  w.saveRentPayment();
  const rentPayment = { saved: JSON.parse(w.localStorage.getItem('tt39rentpayments') || '[]'), requests: requests.slice(beforeRent) };
  w.TT_MODULE_ACCESS.masters.business_parties = [{ values: ['Fixture Labour', '', 'Labour Contractor', '', '', '', '', '', '', '', 'Active'] }];
  w.processingTab('labourbill');
  w.document.querySelector('#labContractor').value = 'Fixture Labour';
  w.document.querySelector('#labFrom').value = '2026-10-01';
  w.document.querySelector('#labTill').value = '2026-10-16';
  w.document.querySelector('#labBillDate').value = '2026-10-16';
  w.document.querySelector('#labBillNo').value = 'FIXTURE-LABOUR-1';
  w.generateLabourDraft();
  const beforeLabour = requests.length;
  w.saveLabourBill(false);
  const labourBill = { saved: JSON.parse(w.localStorage.getItem('tt38labourbills') || '[]'), feed: JSON.parse(w.localStorage.getItem('tt38accountsfeed') || '[]'), requests: requests.slice(beforeLabour) };
  let automaticSaveCalls = 0;
  w.TT_SHARED_SYNC = { saveNow: async () => { automaticSaveCalls++; } };
  const sodas = [{ id: 'FIXTURE-SODA', sodaNo: 'FIXTURE-1', entity: 'TTI', productStage: 'READY', readyRoute: 'EX_MILL', locationName: 'Fixture External Mill', baseVariety: 'IRRI-6', riceType: 'White', brokenGrade: '5%', displayName: 'IRRI-6 White Ready Rice', qtyToKg: 1000 }];
  w.fetch = async (url, options = {}) => { requests.push({ url: String(url), method: options.method || 'GET' }); return { ok: true, json: async () => ({ ok: true, sodas }) }; };
  await w.loadPurchaseSodas();
  await pause(10);
  const navigationSave = { automaticSaveCalls, finalSaveClicked: false, exMillRecords: JSON.parse(w.localStorage.getItem('tt35exmill') || '[]').length };
  const result = { fixtureOnly: true, panels, oldCostReport, permission, masters, rentPayment, labourBill, navigationSave, errors, alerts, requests };
  fs.writeFileSync(process.argv[2] || '/tmp/tt-milling-probe.json', JSON.stringify(result, null, 2) + '\n');
  process.stdout.write(JSON.stringify(result, null, 2) + '\n');
  w.close();
})().catch(error => { process.stderr.write(error.stack + '\n'); process.exitCode = 1; });
