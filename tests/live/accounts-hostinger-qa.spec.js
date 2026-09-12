const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.TRANSTRADE_BASE_URL || 'https://app.transtradeinternational.com';
const QA_USERNAME = process.env.TRANSTRADE_QA_USERNAME;
const QA_PASSWORD = process.env.TRANSTRADE_QA_PASSWORD;

async function signIn(page) {
  expect(QA_USERNAME, 'TRANSTRADE_QA_USERNAME is required').toBeTruthy();
  expect(QA_PASSWORD, 'TRANSTRADE_QA_PASSWORD is required').toBeTruthy();
  let lastUrl = '';
  for (let attempt = 1; attempt <= 3; attempt += 1) {
    const login = await page.request.get(`${BASE_URL}/login.php`, { timeout: 45_000 });
    expect(login.status()).toBe(200);
    const token = (await login.text()).match(/name="csrf" value="([^"]+)"/)?.[1];
    expect(token, 'Login CSRF token must be present').toBeTruthy();
    const signed = await page.request.post(`${BASE_URL}/login.php`, { form: { csrf: token, username: QA_USERNAME, password: QA_PASSWORD }, timeout: 45_000 });
    expect(signed.status()).toBe(200);
    await page.goto(`${BASE_URL}/accounts/index.php`, { waitUntil: 'domcontentloaded', timeout: 90_000 });
    lastUrl = page.url();
    if (lastUrl.includes('/accounts/index.php') && /Transtrade Accounts/i.test(await page.title())) return;
    if (attempt < 3) await page.waitForTimeout(1_500 * attempt);
  }
  throw new Error(`QA authentication did not reach Accounts; final URL: ${lastUrl}`);
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
  expect(elapsed, `${label} must remain responsive`).toBeLessThan(5_000);
}

const cleanCard = (page, key) => page.locator(`.tt-clean-card[data-clean-key="${key}"]`);

async function openGroupItem(page, group, item) {
  await activate(cleanCard(page, group));
  await expect(page.locator('#ttQuickDialog')).toBeVisible();
  await activate(page.locator('#ttQuickDialog .tt-quick-item').filter({ hasText: item }));
}

async function closeWorkspace(page) {
  const editorClose = page.locator('[data-editor-back]:visible');
  if (await editorClose.count()) await activate(editorClose.first());
  const workspaceClose = page.locator('.workspace.active > .panelHead [data-back]:visible');
  if (await workspaceClose.count()) await activate(workspaceClose.first());
  await expect(page.locator('#entityHome')).toBeVisible();
  await expect(page.locator('body')).not.toHaveClass(/tt-modal-open/);
}

test('authenticated Accounts live smoke: clean icon hub and popup workflows', async ({ page }) => {
  test.setTimeout(480_000);
  const pageErrors = [];
  const failedRequests = [];
  page.on('pageerror', error => pageErrors.push(error.stack || String(error)));
  page.on('requestfailed', request => {
    if (request.url().includes('/accounts/')) failedRequests.push(`${request.method()} ${request.url()}: ${request.failure()?.errorText || 'failed'}`);
  });
  await signIn(page);

  await expect(page.locator('#ttEntityLanding')).toHaveCount(0);
  await expect(page.locator('#entityTitle')).toBeVisible();
  await expect.poll(() => page.evaluate(() => window.TT_ACCOUNTS_CLEAN_UI?.installed || false), { timeout: 30_000 }).toBe(true);
  await expect.poll(() => page.evaluate(() => window.TT_ACCOUNT_RUNTIME?.observerCoalescing || false), { timeout: 30_000 }).toBe(true);

  const icons = ['purchases','ledgers','bags','local','export','expenses','reports','masters'];
  for (const key of icons) await expect(cleanCard(page, key), `${key} icon must be visible`).toBeVisible();
  await expect(page.locator('#homeGrid > .appCard')).toHaveCount(8);

  const companyMenu = page.getByRole('button', { name: 'Change Company' });
  await activate(companyMenu);
  await expect(page.locator('.entityBtn[data-entity="TTI"]')).toBeVisible();
  await activate(page.locator('.entityBtn[data-entity="TTI"]'));
  await expect(page.locator('.entityBtn[data-entity="TTI"]')).toBeHidden();

  await openGroupItem(page, 'purchases', 'Sodas');
  await expect(page.locator('#ws-purchases')).toHaveClass(/tt-clean-modal/);
  await expect(page.locator('#purchaseEditor')).toHaveClass(/tt-editor-stage/);
  await expect(page.locator('#purchaseEditor').getByText(/Start with what you know/i)).toBeVisible({ timeout: 30_000 });
  await closeWorkspace(page);

  await openGroupItem(page, 'purchases', 'Arrival Bill Posting');
  await expect(page.locator('#purchaseEditor')).toHaveClass(/tt-editor-stage/);
  await expect(page.locator('#purchaseEditor').getByText(/Commodity Purchase Bills|Commodity Purchase Bill/i).first()).toBeVisible({ timeout: 30_000 });
  await closeWorkspace(page);

  await activate(cleanCard(page, 'expenses'));
  await expect(page.locator('#ws-expenses')).toHaveClass(/tt-clean-modal/);
  await activate(page.locator('[data-expense="salary"]'));
  await expect(page.locator('#ws-expenses')).toHaveClass(/tt-entry-only/);
  await expect(page.locator('#rsPrepare')).toBeVisible({ timeout: 30_000 });
  await expect(page.locator('#rsSaveSal')).toBeHidden();
  await closeWorkspace(page);

  await openGroupItem(page, 'masters', 'Salary Master');
  await expect(page.locator('#ws-expenses')).toHaveClass(/tt-master-only/);
  await expect(page.locator('.tt-master-add')).toBeVisible({ timeout: 30_000 });
  await expect(page.getByText(/^Talha$/i).first()).toBeVisible({ timeout: 30_000 });
  await closeWorkspace(page);

  await openGroupItem(page, 'masters', 'Rent & Recurring Master');
  await expect(page.locator('.tt-master-add')).toBeVisible({ timeout: 30_000 });
  await expect(page.locator('#rsSaveRent')).toBeHidden();
  await closeWorkspace(page);

  await openGroupItem(page, 'export', 'Inspection');
  await expect(page.locator('#ws-services')).toHaveClass(/tt-clean-modal/);
  await expect(page.locator('#svKind')).toHaveValue('INSPECTION');
  await closeWorkspace(page);

  await openGroupItem(page, 'bags', 'Bag Bill');
  await expect(page.locator('#purchaseEditor .ttbag')).toBeVisible({ timeout: 30_000 });
  await closeWorkspace(page);

  await openGroupItem(page, 'local', 'Sale Approvals');
  await expect(page.locator('#ws-receivables')).toHaveClass(/tt-clean-modal/);
  await closeWorkspace(page);

  await openGroupItem(page, 'ledgers', 'Supplier Ledger');
  await expect(page.locator('#ws-payables')).toHaveClass(/tt-clean-modal/);
  await closeWorkspace(page);

  await activate(cleanCard(page, 'reports'));
  await expect(page.locator('#ttReportsPanel [data-rpt="gl"]')).toBeVisible({ timeout: 30_000 });
  await closeWorkspace(page);

  await activate(cleanCard(page, 'expenses'));
  await activate(page.locator('[data-expense="utility"]'));
  await expect(page.locator('#expenseEditor .tt-search-select input').first()).toBeVisible({ timeout: 30_000 });
  await responsive(page, 'clean Accounts modal');

  expect(failedRequests, 'Accounts resources must not fail').toEqual([]);
  expect(pageErrors, 'Accounts must not raise uncaught browser errors').toEqual([]);
});
