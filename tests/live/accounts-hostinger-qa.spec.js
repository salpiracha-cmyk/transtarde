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

async function deskAction(page, area, action) {
  await activate(page.locator(`[data-tt-area="${area}"]`));
  await activate(page.locator('#ttDeskWork .tt-action').filter({ hasText: action }));
}

async function closeWorkspace(page) {
  const editorClose = page.locator('[data-editor-back]:visible');
  if (await editorClose.count()) await activate(editorClose.first());
  const workspaceClose = page.locator('.workspace.active .tt-clean-close:visible');
  if (await workspaceClose.count()) await activate(workspaceClose.first());
  await expect(page.locator('#entityHome')).toBeVisible();
  await expect(page.locator('body')).not.toHaveClass(/tt-modal-open/);
}

test('authenticated Accounts live smoke: professional desk and popup workflows', async ({ page }) => {
  test.setTimeout(480_000);
  const pageErrors = [];
  const failedRequests = [];
  page.on('pageerror', error => pageErrors.push(error.stack || String(error)));
  page.on('requestfailed', request => {
    if (request.url().includes('/accounts/') || request.url().includes('/api/accounts_') || request.url().includes('/api/purchase_sodas')) failedRequests.push(`${request.method()} ${request.url()}: ${request.failure()?.errorText || 'failed'}`);
  });
  await signIn(page);

  await expect(page.locator('#ttEntityLanding')).toHaveCount(0);
  await expect.poll(() => page.evaluate(() => window.TT_ACCOUNTING_DESK?.installed || false), { timeout: 30_000 }).toBe(true);
  await expect.poll(() => page.evaluate(() => window.TT_ACCOUNTS_CLEAN_UI?.installed || false), { timeout: 30_000 }).toBe(true);
  await expect.poll(() => page.evaluate(() => window.TT_ACCOUNT_RUNTIME?.observerCoalescing || false), { timeout: 30_000 }).toBe(true);
  await expect(page.locator('#ttAccountingDesk')).toBeVisible();
  await expect(page.locator('#ttAccountingDesk [data-tt-entity-name]').first()).toContainText(/Transtrade|Buksh|Trans Grains/);

  const areas = ['purchases','payments','receipts','expenses','ledgers','reports','control'];
  for (const key of areas) await expect(page.locator(`[data-tt-area="${key}"]`), `${key} work area must be visible`).toBeVisible();
  await expect(page.locator('#ttNativeLaunchers')).toBeHidden();

  await activate(page.locator('#ttChangeCompanyDesk'));
  await expect(page.locator('#ttCompanyMenu')).toBeVisible();
  await activate(page.locator('#ttCompanyMenu [data-entity="TTI"]'));
  await expect(page.locator('#ttCompanyMenu')).toBeHidden();

  await activate(page.locator('#ttMasterTop'));
  await expect(page.locator('#ws-masters')).toHaveClass(/active.*tt-clean-modal|tt-clean-modal.*active/, { timeout: 30_000 });
  await expect(page.locator('#ws-masters .masterTabs')).toBeVisible();
  await closeWorkspace(page);

  await deskAction(page, 'purchases', 'Soda Centre');
  await expect(page.locator('#ttSodaLayer')).toBeVisible();
  await expect(page.locator('#ttSodaForm')).toBeVisible({ timeout: 30_000 });
  await expect(page.locator('#ttSodaForm').getByText(/does not create a General Ledger entry/i)).toBeVisible();
  await activate(page.locator('#ttSodaLayer [data-soda-mode="search"]'));
  await expect(page.locator('#ttSodaSearch')).toBeVisible();
  await activate(page.locator('#ttSodaLayer .tt-window-close'));

  await deskAction(page, 'purchases', 'Arrival / Rice Bill');
  await expect(page.locator('#ws-purchases')).toHaveClass(/tt-clean-modal/);
  await expect(page.locator('#purchaseEditor')).toHaveClass(/tt-editor-stage/);
  await expect(page.locator('#purchaseEditor')).toBeVisible({ timeout: 30_000 });
  await closeWorkspace(page);

  await deskAction(page, 'expenses', 'Prepare Salaries');
  await expect(page.locator('#ws-expenses')).toHaveClass(/tt-entry-only/);
  await expect(page.locator('#rsPrepare')).toBeVisible({ timeout: 30_000 });
  await expect(page.locator('#rsSaveSal')).toBeHidden();
  await closeWorkspace(page);

  await deskAction(page, 'payments', 'Export Shipment Bills');
  await expect(page.locator('#ttShipmentLayer')).toBeVisible();
  await activate(page.locator('#ttShipmentLayer .tt-action').filter({ hasText: 'Inspection' }));
  await expect(page.locator('#ws-services')).toHaveClass(/tt-clean-modal/, { timeout: 30_000 });
  await expect(page.locator('#svKind')).toHaveValue('INSPECTION');
  await closeWorkspace(page);

  await deskAction(page, 'purchases', 'Bags');
  await expect(page.locator('#purchaseEditor .ttbag')).toBeVisible({ timeout: 30_000 });
  await closeWorkspace(page);

  await deskAction(page, 'receipts', 'Local Sale Payment');
  await expect(page.locator('#ws-receivables')).toHaveClass(/tt-clean-modal/, { timeout: 30_000 });
  await expect(page.locator('#ws-receivables .tt-prev-search')).toBeVisible();
  await closeWorkspace(page);

  await deskAction(page, 'ledgers', 'Supplier Ledger');
  await expect(page.locator('#ws-payables')).toHaveClass(/tt-clean-modal/, { timeout: 30_000 });
  await closeWorkspace(page);

  await deskAction(page, 'ledgers', 'General Ledger');
  await expect(page.locator('#ttReportsPanel [data-rpt="gl"]')).toBeVisible({ timeout: 30_000 });
  await closeWorkspace(page);

  await deskAction(page, 'control', 'Search Previous');
  await expect(page.locator('#ttSearchLayer')).toBeVisible();
  await expect(page.locator('#ttUniversalSearch')).toBeVisible();
  await activate(page.locator('#ttSearchLayer .tt-window-close'));

  await deskAction(page, 'expenses', 'Utilities & Bills');
  await expect(page.locator('#expenseEditor .tt-search-select input').first()).toBeVisible({ timeout: 30_000 });
  await expect(page.locator('#ttUtilityTreatment')).toBeVisible();
  await expect(page.locator('#ttUtilityTreatment')).toContainText(/Debit.*Credit.*Balanced|Complete form/s);
  await responsive(page, 'professional Accounts modal');

  expect(failedRequests, 'Accounts resources must not fail').toEqual([]);
  expect(pageErrors, 'Accounts must not raise uncaught browser errors').toEqual([]);
});
