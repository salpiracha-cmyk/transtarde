const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.TRANSTRADE_BASE_URL || 'https://app.transtradeinternational.com';
const QA_USERNAME = process.env.TRANSTRADE_QA_USERNAME;
const QA_PASSWORD = process.env.TRANSTRADE_QA_PASSWORD;
const STORE = 'transtrade_export_v3_operational';

async function gotoWithRetry(page, url) {
  let last;
  for (let attempt = 0; attempt < 4; attempt += 1) {
    try { return await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 45_000 }); }
    catch (error) { last = error; await page.waitForTimeout(1500 * (attempt + 1)); }
  }
  throw last;
}

async function signIn(page) {
  await gotoWithRetry(page, BASE_URL + '/login.php');
  await page.getByLabel('Username').fill(QA_USERNAME);
  await page.getByLabel('Password').fill(QA_PASSWORD);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect.poll(() => new URL(page.url()).pathname, { timeout: 45_000 }).not.toBe('/login.php');
}

async function readShared(request) {
  const response = await request.get(BASE_URL + '/api/operations.mysql.php?r=' + Date.now(), { timeout: 60_000 });
  const result = await response.json();
  expect(response.ok(), result.error || 'Shared data request failed').toBeTruthy();
  expect(result.ok, result.error || 'Shared data request failed').toBeTruthy();
  return result;
}

async function writeShared(request, csrf, snapshot, key, value) {
  const response = await request.post(BASE_URL + '/api/operations.mysql.php', {
    data: {
      csrf,
      key,
      value: JSON.stringify(value),
      baseVersion: Number(snapshot.meta?.[key]?.version || 0),
      sourceModule: 'Exports',
    },
    timeout: 60_000,
  });
  const result = await response.json();
  expect(response.ok(), result.error || ('Reset failed for ' + key)).toBeTruthy();
  expect(result.ok, result.error || ('Reset failed for ' + key)).toBeTruthy();
}

const parse = (value, fallback) => {
  try { const parsed = JSON.parse(value || ''); return parsed ?? fallback; }
  catch { return fallback; }
};

test('server-confirmed full Export reset survives a new login', async ({ page }) => {
  test.setTimeout(300_000);
  await signIn(page);
  await gotoWithRetry(page, BASE_URL + '/module.php?id=exports');
  await expect(page.getByRole('button', { name: /NEW SALES CONTRACT/i })).toBeVisible({ timeout: 60_000 });
  await expect.poll(
    () => page.evaluate(() => window.TT_MODULE_ACCESS?.csrf || '').catch(() => ''),
    { timeout: 60_000 },
  ).not.toBe('');
  const csrf = await page.evaluate(() => window.TT_MODULE_ACCESS?.csrf || '');

  // Detach the module before reading and resetting the server. Waiting after
  // pagehide lets any startup bridge flush finish first, while ensuring no
  // application page remains capable of restoring pre-reset state later.
  await page.goto('about:blank');
  await page.waitForTimeout(1_000);

  const snapshot = await readShared(page.request);
  const root = parse(snapshot.values?.[STORE], {});
  const contracts = Array.isArray(root.contracts) ? root.contracts : [];
  const shipments = Array.isArray(root.shipments) ? root.shipments : [];
  const refs = [...new Set([
    ...contracts.map(row => String(row?.ref || '').trim()),
    ...shipments.map(row => String(row?.contractRef || '').trim()),
  ].filter(Boolean))];

  for (const contractRef of refs) {
    const response = await page.request.post(BASE_URL + '/api/export_documents.php', {
      multipart: { csrf, action: 'delete-shipment', contractRef },
      timeout: 60_000,
    });
    const result = await response.json();
    expect(response.ok(), result.error || ('Document cleanup failed for ' + contractRef)).toBeTruthy();
    expect(result.ok, result.error || ('Document cleanup failed for ' + contractRef)).toBeTruthy();
  }

  await writeShared(page.request, csrf, snapshot, STORE, {
    version: 'clean-v3',
    customers: [],
    suppliers: [],
    fi: [],
    contracts: [],
    shipments: [],
    accountsReceipts: [],
    millSync: { newExportBags: [], productionInstructions: [], exportLoading: [] },
    audits: Array.isArray(root.audits) ? root.audits : [],
    alerts: [],
    settings: root.settings && typeof root.settings === 'object' ? root.settings : {},
    deletedShipments: [],
  });

  for (const key of ['tt30bags', 'tt30prodinst', 'tt30ship', 'tt32exportsync', 'tt39bridgequarantine']) {
    if (snapshot.values?.[key] !== undefined) await writeShared(page.request, csrf, snapshot, key, []);
  }

  const exportLinked = row => row && typeof row === 'object' && String(row._ttBridge || '').toLowerCase() === 'exports';
  const exMill = parse(snapshot.values?.tt35exmill, []);
  const removedExMillIds = new Set(exMill.filter(exportLinked).map(row => String(row?.id || '')).filter(Boolean));
  if (snapshot.values?.tt35exmill !== undefined) {
    await writeShared(page.request, csrf, snapshot, 'tt35exmill', exMill.filter(row => !exportLinked(row)));
  }
  const exLoads = parse(snapshot.values?.tt35exload, []);
  if (snapshot.values?.tt35exload !== undefined) {
    await writeShared(page.request, csrf, snapshot, 'tt35exload', exLoads.filter(row => !exportLinked(row) && !removedExMillIds.has(String(row?.sodaId || ''))));
  }

  await gotoWithRetry(page, BASE_URL + '/logout.php');
  await signIn(page);
  const verification = await readShared(page.request);
  const clean = parse(verification.values?.[STORE], {});
  for (const key of ['customers', 'suppliers', 'fi', 'contracts', 'shipments', 'accountsReceipts', 'alerts', 'deletedShipments']) {
    expect(Array.isArray(clean[key]) ? clean[key].length : -1, key + ' must be empty').toBe(0);
  }
  for (const key of ['newExportBags', 'productionInstructions', 'exportLoading']) {
    expect(Array.isArray(clean.millSync?.[key]) ? clean.millSync[key].length : -1, key + ' must be empty').toBe(0);
  }
  for (const key of ['tt30bags', 'tt30prodinst', 'tt30ship', 'tt32exportsync', 'tt39bridgequarantine']) {
    if (verification.values?.[key] !== undefined) {
      expect(parse(verification.values[key], []).length, key + ' must be empty').toBe(0);
    }
  }
  for (const key of ['tt35exmill', 'tt35exload']) {
    if (verification.values?.[key] !== undefined) {
      expect(parse(verification.values[key], []).filter(row => row && typeof row === 'object' && String(row._ttBridge || '').toLowerCase() === 'exports').length, key + ' must not retain Export-linked rows').toBe(0);
    }
  }
});
