const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.TRANSTRADE_BASE_URL || 'https://app.transtradeinternational.com';
const QA_USERNAME = process.env.TRANSTRADE_QA_USERNAME;
const QA_PASSWORD = process.env.TRANSTRADE_QA_PASSWORD;
const EXPORT_STORE = 'transtrade_export_v3_operational';
const MILL_MASTER_KEYS = new Set([
  'tt30mills','tt30arrivaldefaults','tt35brandmeta','tt37users',
  'tt38costmaster','tt38labourrates','tt39rentmaster','tt39salarymaster',
]);

async function signIn(page) {
  await page.goto(BASE_URL + '/login.php', { waitUntil: 'domcontentloaded', timeout: 45_000 });
  await page.getByLabel('Username').fill(QA_USERNAME);
  await page.getByLabel('Password').fill(QA_PASSWORD);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect.poll(() => new URL(page.url()).pathname, { timeout: 45_000 }).not.toBe('/login.php');
}

async function verifyEmpty(page) {
  await page.goto(BASE_URL + '/module.php?id=exports', { waitUntil: 'domcontentloaded', timeout: 60_000 });
  await expect(page.getByRole('button', { name: /NEW SALES CONTRACT/i })).toBeVisible({ timeout: 60_000 });
  await page.waitForTimeout(1500);

  const operationsResponse = await page.request.get(BASE_URL + '/api/operations.mysql.php?r=' + Date.now(), { timeout: 60_000 });
  const operations = await operationsResponse.json();
  expect(operationsResponse.ok(), operations.error || 'Operational verification failed').toBeTruthy();
  expect(operations.ok).toBeTruthy();
  const root = JSON.parse(operations.values?.[EXPORT_STORE] || '{}');
  for (const key of ['customers','suppliers','fi','contracts','shipments','accountsReceipts','alerts']) {
    expect(Array.isArray(root[key]) ? root[key].length : -1, key + ' should be empty').toBe(0);
  }
  for (const key of ['newExportBags','productionInstructions','exportLoading']) {
    expect(Array.isArray(root.millSync?.[key]) ? root.millSync[key].length : -1, 'millSync.' + key + ' should be empty').toBe(0);
  }
  for (const [key, value] of Object.entries(operations.values || {})) {
    if (!/^tt[0-9]{2}[a-z0-9_]{2,60}$/.test(key)) continue;
    if (MILL_MASTER_KEYS.has(key)) continue;
    const parsed = JSON.parse(value || '[]');
    expect(Array.isArray(parsed), key + ' should be an operational array after reset').toBeTruthy();
    expect(parsed.length, key + ' should be empty').toBe(0);
  }

  const customersResponse = await page.request.get(BASE_URL + '/api/export_customers.php?r=' + Date.now(), { timeout: 60_000 });
  const customers = await customersResponse.json();
  expect(customersResponse.ok(), customers.error || 'Customer master verification failed').toBeTruthy();
  expect(customers.ok).toBeTruthy();
  expect(Array.isArray(customers.customers) ? customers.customers.length : -1, 'Export customer/party sync must remain empty').toBe(0);
}

test('owner reset stays empty across Exports load and fresh login', async ({ page }) => {
  test.setTimeout(240_000);
  await signIn(page);
  await verifyEmpty(page);
  await page.goto(BASE_URL + '/logout.php', { waitUntil: 'domcontentloaded', timeout: 45_000 });
  await signIn(page);
  await verifyEmpty(page);
});
