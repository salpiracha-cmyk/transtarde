const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.TRANSTRADE_BASE_URL || 'https://app.transtradeinternational.com';
const QA_USERNAME = process.env.TRANSTRADE_QA_USERNAME;
const QA_PASSWORD = process.env.TRANSTRADE_QA_PASSWORD;

async function signIn(page) {
  expect(QA_USERNAME, 'TRANSTRADE_QA_USERNAME is required').toBeTruthy();
  expect(QA_PASSWORD, 'TRANSTRADE_QA_PASSWORD is required').toBeTruthy();
  const login = await page.request.get(`${BASE_URL}/login.php`, { timeout: 30_000 });
  expect(login.status(), 'Login page must be reachable').toBe(200);
  const html = await login.text();
  const token = html.match(/name="csrf" value="([^"]+)"/)?.[1];
  expect(token, 'Login CSRF token must be present').toBeTruthy();
  const signed = await page.request.post(`${BASE_URL}/login.php`, {
    form: { csrf: token, username: QA_USERNAME, password: QA_PASSWORD },
    timeout: 30_000,
  });
  expect(signed.status(), 'QA login must succeed').toBe(200);

  const started = Date.now();
  await page.goto(`${BASE_URL}/accounts/index.php`, { waitUntil: 'domcontentloaded', timeout: 90_000 });
  const navigationMs = Date.now() - started;
  expect(navigationMs, 'Accounts DOM must become usable within 45 seconds').toBeLessThan(45_000);
}

async function activate(locator) {
  await expect(locator).toBeVisible({ timeout: 30_000 });
  await locator.evaluate(element => element.click());
}

async function responsive(page, label) {
  const elapsed = await page.evaluate(() => new Promise(resolve => {
    const started = performance.now();
    requestAnimationFrame(() => requestAnimationFrame(() => resolve(performance.now() - started)));
  }));
  expect(elapsed, `${label} must keep responding to animation frames`).toBeLessThan(5_000);
}

async function backHome(page) {
  await activate(page.getByRole('button', { name: /Accounts Home/i }));
  await expect(page.getByRole('heading', { name: 'Transtrade International' })).toBeVisible();
  await page.waitForTimeout(1_000);
  await expect(page.locator('#entityHome'), 'Accounts home must remain visible after delayed feature refreshes').toBeVisible();
  await responsive(page, 'Accounts home');
}

test('authenticated Accounts live smoke: full module loads and every workspace remains responsive', async ({ page }) => {
  test.setTimeout(480_000);
  const pageErrors = [];
  const failedRequests = [];
  page.on('pageerror', error => pageErrors.push(String(error)));
  page.on('requestfailed', request => {
    if (request.url().includes('/accounts/')) failedRequests.push(`${request.method()} ${request.url()}: ${request.failure()?.errorText || 'failed'}`);
  });
  await signIn(page);

  await expect(page).toHaveTitle(/Transtrade Accounts/i);
  await expect(page.getByRole('heading', { name: 'Transtrade International' })).toBeVisible();
  await expect(page.locator('.entityBtn[data-entity="TTI"]')).toBeVisible();
  await expect.poll(() => page.evaluate(() => window.TT_ACCOUNT_RUNTIME?.observerCoalescing || false), {
    timeout: 30_000,
    message: 'Accounts observer coalescing runtime must be active',
  }).toBe(true);

  const scriptResources = await page.evaluate(() => performance.getEntriesByType('resource')
    .map(entry => entry.name)
    .filter(name => /\/accounts\/.*(?:\.js|app-bundle\.php)/.test(name)));
  expect(scriptResources.filter(name => /\/accounts\/[^/]+\.js(?:\?|$)/.test(name)), 'Legacy individual Accounts scripts must not load').toEqual([]);
  expect(scriptResources.filter(name => name.includes('/accounts/app-bundle.php')).length, 'Exactly one Accounts feature bundle must load').toBe(1);
  await responsive(page, 'Initial Accounts load');

  const workspaces = ['expenses', 'purchases', 'bank', 'receivables', 'payables', 'jv', 'reconciliation', 'tg', 'reports', 'masters'];
  for (const key of workspaces) {
    await activate(page.locator(`.appCard[data-key="${key}"]`));
    await expect(page.locator(`#ws-${key}`)).toBeVisible({ timeout: 30_000 });
    await responsive(page, `${key} workspace`);
    await backHome(page);
  }

  await activate(page.getByRole('button', { name: /Expenses & Overheads/i }));
  await expect(page.getByRole('heading', { name: 'Expenses & Overheads' })).toBeVisible();
  for (const key of ['utility', 'card', 'rent', 'salary', 'donations', 'reimburse', 'general']) {
    await expect(page.locator(`[data-expense="${key}"]`), `${key} expense control must be visible`).toBeVisible();
  }

  await activate(page.getByRole('button', { name: /Salaries & Staff/i }));
  await expect(page.getByText('Add Salary Master', { exact: false })).toBeVisible({ timeout: 30_000 });
  await expect(page.getByText(/Salary advances are not used/i)).toBeVisible();
  await expect(page.getByText('Salary Master', { exact: true })).toBeVisible();
  await expect(page.getByText('Talha', { exact: true }).first()).toBeVisible({ timeout: 30_000 });
  await backHome(page);

  await activate(page.getByRole('button', { name: /Expenses & Overheads/i }));
  await activate(page.getByRole('button', { name: /^Donations/i }));
  await expect(page.getByRole('heading', { name: 'Record Donation' })).toBeVisible({ timeout: 30_000 });
  for (const ledger of ['Zakat Ledger', 'Sadqa Ledger', 'Fi Sabilillah Ledger']) {
    await expect(page.getByText(ledger, { exact: true }).first()).toBeVisible();
  }
  await backHome(page);

  await activate(page.getByRole('button', { name: /Expenses & Overheads/i }));
  await activate(page.getByRole('button', { name: /Rent & Recurring/i }));
  await expect(page.getByText('Add Rent Master', { exact: false })).toBeVisible({ timeout: 30_000 });
  await backHome(page);

  await activate(page.getByRole('button', { name: /^Purchases/i }));
  await activate(page.getByRole('button', { name: /Other Purchase/i }));
  await expect(page.getByRole('heading', { name: 'Other Purchase' })).toBeVisible({ timeout: 30_000 });
  await expect(page.getByRole('heading', { name: 'Fixed Asset Register' })).toBeVisible();
  await backHome(page);

  await activate(page.getByRole('button', { name: /Journal Voucher/i }));
  await expect(page.getByRole('heading', { name: 'Journal Voucher' })).toBeVisible({ timeout: 30_000 });
  await backHome(page);

  await activate(page.getByRole('button', { name: /^Reports/i }));
  await expect(page.getByText(/General Ledger/i).first()).toBeVisible({ timeout: 30_000 });
  await responsive(page, 'Reports workspace');

  expect(failedRequests, 'Accounts resources must not fail').toEqual([]);
  expect(pageErrors, 'Accounts must not raise uncaught browser errors').toEqual([]);
});
