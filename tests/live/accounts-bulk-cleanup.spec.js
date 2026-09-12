const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.TRANSTRADE_BASE_URL || 'https://app.transtradeinternational.com';
const QA_USERNAME = process.env.TRANSTRADE_QA_USERNAME;
const QA_PASSWORD = process.env.TRANSTRADE_QA_PASSWORD;

async function authenticate(request) {
  expect(QA_USERNAME).toBeTruthy();
  expect(QA_PASSWORD).toBeTruthy();
  const login = await request.get(`${BASE_URL}/login.php`, { timeout: 45_000 });
  expect(login.status()).toBe(200);
  const csrf = (await login.text()).match(/name="csrf" value="([^"]+)"/)?.[1];
  expect(csrf).toBeTruthy();
  const signed = await request.post(`${BASE_URL}/login.php`, { form:{csrf, username:QA_USERNAME, password:QA_PASSWORD}, timeout:45_000 });
  expect(signed.status()).toBe(200);
  const accounts = await request.get(`${BASE_URL}/accounts/index.php`, { timeout:45_000 });
  expect(accounts.status()).toBe(200);
  const access = (await accounts.text()).match(/window\.TT_ACCOUNT_ACCESS=(\{.*?\});<\/script>/s)?.[1];
  expect(access).toBeTruthy();
  return JSON.parse(access).csrf;
}

async function cleanup(request, csrf, action, confirm = '') {
  const response = await request.post(`${BASE_URL}/api/accounts_bulk_test_cleanup.php`, {
    data:{csrf, action, confirm}, timeout:90_000
  });
  const text = await response.text();
  let body = {}; try { body = JSON.parse(text); } catch {}
  expect(response.status(), text.slice(0, 500)).toBe(200);
  expect(body.ok, text.slice(0, 500)).toBeTruthy();
  return body;
}

test('delete all marked Accounts bulk QA data and retain a protected backup', async ({ request }, testInfo) => {
  test.setTimeout(180_000);
  const csrf = await authenticate(request);
  const before = await cleanup(request, csrf, 'dry_run');
  const deleted = await cleanup(request, csrf, 'execute', 'PURGE_TEST_DUMMY_ACCOUNTS_BULK');
  const after = await cleanup(request, csrf, 'dry_run');
  expect(deleted.deleted).toEqual(before.counts);
  expect(deleted.backupFile).toMatch(/^accounts-bulk-test-cleanup-\d{8}-\d{6}-[a-f0-9]{8}\.json$/);
  expect(after.counts).toEqual({journals:0, events:0, postingIdentities:0});
  await testInfo.attach('accounts-bulk-cleanup-result.json', {body:Buffer.from(JSON.stringify({before,deleted,after},null,2)), contentType:'application/json'});
  console.log('ACCOUNTS_BULK_CLEANUP=' + JSON.stringify({before,deleted,after}));
});
