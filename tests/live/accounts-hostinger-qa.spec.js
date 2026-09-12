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
    expect(login.status(), 'Login page must be reachable').toBe(200);
    const html = await login.text();
    const token = html.match(/name="csrf" value="([^"]+)"/)?.[1];
    expect(token, 'Login CSRF token must be present').toBeTruthy();
    const signed = await page.request.post(`${BASE_URL}/login.php`, {
      form: { csrf: token, username: QA_USERNAME, password: QA_PASSWORD },
      timeout: 45_000,
    });
    expect(signed.status(), 'QA login request must complete').toBe(200);

    const started = Date.now();
    await page.goto(`${BASE_URL}/accounts/index.php`, { waitUntil: 'domcontentloaded', timeout: 90_000 });
    const navigationMs = Date.now() - started;
    lastUrl = page.url();
    if (lastUrl.includes('/accounts/index.php') && /Transtrade Accounts/i.test(await page.title())) {
      expect(navigationMs, 'Accounts DOM must become usable within 45 seconds').toBeLessThan(45_000);
      return;
    }
    if (attempt < 3) await page.waitForTimeout(1_500 * attempt);
  }
  throw new Error(`QA authentication did not reach Accounts after 3 attempts; final URL: ${lastUrl}`);
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
  const editorBack = page.locator('[data-editor-back]:visible');
  if (await editorBack.count()) {
    await activate(editorBack.first());
  }
  await activate(page.getByRole('button', { name: /Accounts Home/i }));
  await expect(page.locator('#entityTitle')).toBeVisible();
  await page.waitForTimeout(1_000);
  await expect(page.locator('#entityHome'), 'Accounts home must remain visible after delayed feature refreshes').toBeVisible();
  await responsive(page, 'Accounts home');
}

async function expectTopEditor(page, editorSelector) {
  const editor = page.locator(editorSelector);
  await expect(editor).toHaveClass(/tt-editor-stage/);
  await expect(page.locator('.workspace.tt-editor-open > .tt-editor-bar')).toBeVisible();
  await expect(page.locator('.workspace.tt-editor-open > .subGrid')).toBeHidden();
  const box = await editor.boundingBox();
  expect(box, 'Top-level editor must have a visible layout box').not.toBeNull();
  expect(box.y, 'Editor must open near the top of the Accounts page').toBeLessThan(260);
}

test('authenticated Accounts live smoke: full module loads and every workspace remains responsive', async ({ page }) => {
  test.setTimeout(480_000);
  const pageErrors = [];
  const failedRequests = [];
  page.on('pageerror', error => pageErrors.push(error.stack || String(error)));
  page.on('requestfailed', request => {
    if (request.url().includes('/accounts/')) failedRequests.push(`${request.method()} ${request.url()}: ${request.failure()?.errorText || 'failed'}`);
  });
  await signIn(page);

  await expect(page).toHaveTitle(/Transtrade Accounts/i);
  await expect(page.locator('#entityTitle')).toBeVisible();
  await expect(page.locator('#ttEntityLanding')).toHaveCount(0);
  await expect.poll(() => page.evaluate(() => window.TT_ACCOUNTS_NAVIGATION?.directLanding || false), {
    timeout: 30_000,
    message: 'Accounts must land directly on the entity home',
  }).toBe(true);
  const companyMenu = page.getByRole('button', { name: 'Change Company' });
  await expect(companyMenu).toBeVisible();
  await expect(page.locator('.entityBtn[data-entity="TTI"]')).toBeHidden();
  await activate(companyMenu);
  await expect(page.locator('.entityBtn[data-entity="TTI"]')).toBeVisible();
  await activate(page.locator('.entityBtn[data-entity="TTI"]'));
  await expect(page.locator('.entityBtn[data-entity="TTI"]')).toBeHidden();
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

  await activate(page.locator('.appCard[data-key="expenses"]'));
  await expect(page.getByRole('heading', { name: 'Expenses & Overheads' })).toBeVisible();
  for (const key of ['utility', 'card', 'rent', 'salary', 'donations', 'reimburse', 'general']) {
    await expect(page.locator(`[data-expense="${key}"]`), `${key} expense control must be visible`).toBeVisible();
  }

  await activate(page.locator('[data-expense="salary"]'));
  const salaryPanel = page.locator('#expenseEditor');
  await expectTopEditor(page, '#expenseEditor');
  await expect(salaryPanel.getByText('Add Salary Master', { exact: false })).toBeVisible({ timeout: 30_000 });
  await expect(salaryPanel.getByText(/Salary advances are not used/i)).toBeVisible();
  await expect(salaryPanel.getByText('Salary Master', { exact: true })).toBeVisible();
  await expect(salaryPanel.getByText(/^Talha$/i).first()).toBeVisible({ timeout: 30_000 });
  await backHome(page);

  await activate(page.locator('.appCard[data-key="expenses"]'));
  await activate(page.locator('[data-expense="donations"]'));
  const donationPanel = page.locator('#expenseEditor');
  await expect(donationPanel.getByRole('heading', { name: 'Record Donation' })).toBeVisible({ timeout: 30_000 });
  for (const ledger of ['Zakat Ledger', 'Sadqa Ledger', 'Fi Sabilillah Ledger']) {
    await expect(donationPanel.getByText(ledger, { exact: true }).first()).toBeVisible();
  }
  await backHome(page);

  await activate(page.locator('.appCard[data-key="expenses"]'));
  await activate(page.locator('[data-expense="rent"]'));
  await expect(page.locator('#expenseEditor').getByText('Add Rent Master', { exact: true }).first()).toBeVisible({ timeout: 30_000 });
  await backHome(page);

  await activate(page.locator('.appCard[data-key="purchases"]'));
  await activate(page.locator('[data-purchase="other"]'));
  const purchasePanel = page.locator('#purchaseEditor');
  await expectTopEditor(page, '#purchaseEditor');
  await expect(purchasePanel.getByRole('heading', { name: 'Other Purchase' })).toBeVisible({ timeout: 30_000 });
  await expect(purchasePanel.getByRole('heading', { name: 'Fixed Asset Register' })).toBeVisible();
  await backHome(page);

  await activate(page.locator('.appCard[data-key="jv"]'));
  await expect(page.locator('#ws-jv').getByRole('heading', { name: 'Journal Voucher' })).toBeVisible({ timeout: 30_000 });
  await backHome(page);

  await activate(page.locator('.appCard[data-key="reports"]'));
  await expect(page.locator('#ttReportsPanel [data-rpt="gl"]')).toBeVisible({ timeout: 30_000 });
  await responsive(page, 'Reports workspace');

  expect(failedRequests, 'Accounts resources must not fail').toEqual([]);
  expect(pageErrors, 'Accounts must not raise uncaught browser errors').toEqual([]);
});
