const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.TRANSTRADE_BASE_URL || 'https://app.transtradeinternational.com';
const QA_USERNAME = process.env.TRANSTRADE_QA_USERNAME;
const QA_PASSWORD = process.env.TRANSTRADE_QA_PASSWORD;

async function signIn(page) {
  expect(QA_USERNAME, 'TRANSTRADE_QA_USERNAME is required').toBeTruthy();
  expect(QA_PASSWORD, 'TRANSTRADE_QA_PASSWORD is required').toBeTruthy();

  await page.goto(`${BASE_URL}/login.php`, { waitUntil: 'domcontentloaded', timeout: 90_000 });
  await expect(page.locator('form')).toBeVisible({ timeout: 30_000 });
  await page.locator('input[name="username"]').fill(QA_USERNAME);
  await page.locator('input[name="password"]').fill(QA_PASSWORD);
  await page.locator('button[type="submit"]').click();
  await page.waitForLoadState('domcontentloaded');

  const lastUrl = page.url();
  if (lastUrl.includes('/accounts/index.php') && /Transtrade Accounts/i.test(await page.title())) return;

  const loginError = (await page.locator('.error').textContent().catch(() => ''))?.trim() || '';
  throw new Error(`QA browser authentication did not reach Accounts; final URL: ${lastUrl}${loginError ? `; login error: ${loginError}` : ''}`);
}

async function activate(locator) {
  await expect(locator).toBeVisible({ timeout: 30_000 });
  await locator.evaluate(element => element.click());
}


function traceSessionNavigation(page) {
  const trace = [];
  const started = Date.now();
  const pending = [];
  trace.flush = () => Promise.allSettled(pending);
  page.on('response', response => {
    pending.push((async () => {
    const path = new URL(response.url()).pathname;
    if (!['/login.php','/logout.php','/index.php','/accounts/index.php','/api/session_activity.php','/accounts/app-bundle.php'].includes(path)) return;
    const [headers, requestHeaders] = await Promise.all([response.allHeaders(), response.request().allHeaders()]);
    trace.push({
      ms: Date.now() - started, path, status: response.status(),
      location: headers.location || '', method: response.request().method(),
      sentSessionCookie: /(?:^|;\s*)TRANSTRADE_SESSION=/.test(requestHeaders.cookie || ''),
      setsSessionCookie: /TRANSTRADE_SESSION=/.test(headers['set-cookie'] || ''),
      fetchSite: requestHeaders['sec-fetch-site'] || '',
      server: headers.server || '', cache: headers['x-hcdn-cache-status'] || headers['x-litespeed-cache'] || headers['x-cache'] || headers['cf-cache-status'] || '',
      age: headers.age || '', cacheControl: headers['cache-control'] || ''
    });
    if (trace.length > 60) trace.shift();
    })());
  });
  return trace;
}

async function returnFromMasters(page, trace) {
  const before = (await page.context().cookies()).find(cookie => cookie.name === 'TRANSTRADE_SESSION');
  await activate(page.locator('#masterBackTop'));
  try {
    await expect(page).toHaveURL(/\/accounts\/index\.php$/, { timeout: 30_000 });
  } catch (error) {
    await trace.flush();
    const probe = await page.request.get(BASE_URL + '/accounts/index.php?tt_session_probe=' + Date.now(), {maxRedirects:0});
    const after = (await page.context().cookies()).find(cookie => cookie.name === 'TRANSTRADE_SESSION');
    console.log('SESSION_NAVIGATION_DIAGNOSTICS ' + JSON.stringify({
      trace, uncachedProbe:{status:probe.status(),location:probe.headers().location||'',cacheControl:probe.headers()['cache-control']||''}, finalPath: new URL(page.url()).pathname,
      cookieBefore: before ? {domain:before.domain,path:before.path,secure:before.secure,sameSite:before.sameSite} : null,
      cookieAfter: after ? {domain:after.domain,path:after.path,secure:after.secure,sameSite:after.sameSite} : null,
      sessionCookieChanged: before?.value !== after?.value
    }));
    throw error;
  }
}

async function responsive(page, label) {
  const elapsed = await page.evaluate(() => new Promise(resolve => {
    const started = performance.now();
    requestAnimationFrame(() => requestAnimationFrame(() => resolve(performance.now() - started)));
  }));
  expect(elapsed, `${label} must remain responsive`).toBeLessThan(5_000);
}

async function deskAction(page, area, action) {
  const back = page.locator('#ttDeskWork .tt-back-areas');
  if (await back.count()) await activate(back);
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
  const sessionTrace = traceSessionNavigation(page);
  const pageErrors = [];
  const failedRequests = [];
  const failedResponses = [];
  const consoleErrors = [];
  const adminRuntimeResponses = [];
  page.on('pageerror', error => pageErrors.push(error.stack || String(error)));
  page.on('console', message => { if (message.type() === 'error') consoleErrors.push(message.text()); });
  page.on('requestfailed', request => {
    const errorText = request.failure()?.errorText || 'failed';
    if (errorText === 'net::ERR_ABORTED') return;
    if (request.url().includes('/accounts/') || request.url().includes('/api/accounts_') || request.url().includes('/api/purchase_sodas')) failedRequests.push(`${request.method()} ${request.url()}: ${errorText}`);
  });
  page.on('response', response => {
    const url = response.url();
    if (url.includes('/admin/app.js')) adminRuntimeResponses.push(`${response.status()} ${url}`);
    if (response.status() < 400) return;
    if (url.includes('/accounts/') || url.includes('/api/accounts_') || url.includes('/api/purchase_sodas')) failedResponses.push(`${response.status()} ${response.request().method()} ${url}`);
  });
  await signIn(page);

  const qaIdentity = await page.evaluate(() => ({
    user: window.TT_ACCOUNT_ACCESS?.user || '',
    role: window.TT_ACCOUNT_ACCESS?.role || ''
  }));
  expect(qaIdentity, 'Live smoke must run as the stable dedicated QA identity').toEqual({
    user: 'Transtrade QA',
    role: 'QA Tester'
  });

  await expect(page.locator('#ttEntityLanding')).toHaveCount(0);
  await expect.poll(() => page.evaluate(() => window.TT_ACCOUNTING_DESK?.installed || false), { timeout: 30_000 }).toBe(true);
  await expect.poll(() => page.evaluate(() => window.TT_ACCOUNTS_CLEAN_UI?.installed || false), { timeout: 30_000 }).toBe(true);
  await expect.poll(() => page.evaluate(() => window.TT_ACCOUNT_RUNTIME?.observerCoalescing || false), { timeout: 30_000 }).toBe(true);
  await expect(page.locator('#ttAccountingDesk')).toBeVisible();
  await expect(page.locator('#ttAccountingDesk [data-tt-entity-name]').first()).toContainText(/Transtrade|Buksh|Trans Grains/);

  const areas = ['exports','commodity','routine','ledgers','reports'];
  for (const key of areas) await expect(page.locator(`[data-tt-area="${key}"]`), `${key} work area must be visible`).toBeVisible();
  await expect(page.locator('#ttMainJV .tt-area-glyph svg')).toBeVisible();
  await expect(page.locator('#ttNativeLaunchers')).toBeHidden();

  await activate(page.locator('#ttChangeCompanyDesk'));
  await expect(page.locator('#ttCompanyMenu')).toBeVisible();
  await activate(page.locator('#ttCompanyMenu [data-entity="TTI"]'));
  await expect(page.locator('#ttCompanyMenu')).toBeHidden();

  await expect(page.locator('#ttMasterTop'), 'M must be visible to every ordinary module user').toBeVisible();
  await activate(page.locator('#ttMasterTop'));
  await expect(page).toHaveURL(/\/index\.php\?view=masters&from=accounts$/, { timeout: 30_000 });
  await page.waitForLoadState('domcontentloaded');
  await expect.poll(() => page.evaluate(() => ({
    user: window.TT_SESSION?.name || '',
    role: window.TT_SESSION?.role || '',
    returnUrl: window.TT_SESSION?.masterReturn?.url || ''
  })), {
    message: 'Master Records must retain the ordinary Accounts session',
    timeout: 30_000
  }).toEqual({
    user: 'Transtrade QA', role: 'QA Tester', returnUrl: '/accounts/index.php'
  });
  await page.waitForTimeout(1_000);
  if (!(await page.locator('#view-masters').isVisible())) {
    const masterDiagnostics = await page.evaluate(() => ({
      title: document.title,
      bodyClass: document.body.className,
      masterClass: document.querySelector('#view-masters')?.className || '',
      session: window.TT_SESSION || null,
      scriptSrc: document.querySelector('script[src*="admin/app.js"]')?.getAttribute('src') || '',
      readyState: document.readyState
    }));
    throw new Error(`Master Records runtime did not activate. diagnostics=${JSON.stringify(masterDiagnostics)} pageErrors=${JSON.stringify(pageErrors)} consoleErrors=${JSON.stringify(consoleErrors)} adminRuntimeResponses=${JSON.stringify(adminRuntimeResponses)}`);
  }
  await expect(page.locator('#view-masters')).toBeVisible({ timeout: 30_000 });
  await expect(page.locator('#masterTitle')).toBeVisible();
  await expect(page.locator('#masterBackTop')).toHaveAccessibleName('Close Master Records and return to Accounts');
  await returnFromMasters(page, sessionTrace);
  await expect.poll(() => page.evaluate(() => window.TT_ACCOUNTING_DESK?.installed || false), { timeout: 30_000 }).toBe(true);

  await deskAction(page, 'exports', 'Bank Receipt / Credit Advice');
  await expect(page.locator('#ttExportReceiptDialog')).toBeVisible({ timeout: 30_000 });
  await expect(page.locator('#ws-bank')).not.toHaveClass(/active/);
  await expect(page.locator('#ttDeskWork .tt-action-mark svg').first()).toBeVisible();
  await expect(page.locator('#erPkrBank')).toBeVisible({ timeout: 30_000 });
  if (await page.locator('#erPkrBank option:not([value=""])').count() === 0) {
    await expect(page.locator('#ttExportReceiptDialog .tter-alert').first()).toContainText(/account number or IBAN|Active in Accounts|Allow Receipts|Active in Company Master|currency in Company Master|No TTI bank account/);
  }
  await activate(page.locator('#ttExportReceiptDialog [data-er-close]'));
  await expect(page.locator('#ttExportReceiptDialog')).toBeHidden();
  await activate(page.locator('#ttDeskWork .tt-back-areas'));
  await activate(page.locator('#ttMainJV'));
  await expect(page.locator('#ws-jv')).toContainText(/view access only/i, { timeout: 30_000 });
  await expect(page.locator('#jvwSubmit')).toHaveCount(0);
  await closeWorkspace(page);
  await expect(page.locator('#ws-jv')).not.toHaveClass(/active/);

  await deskAction(page, 'commodity', 'Soda Centre');
  await expect(page.locator('#ttSodaLayer')).toBeVisible();
  await expect(page.locator('#ttSodaForm')).toBeVisible({ timeout: 30_000 });
  await expect(page.locator('#ttSodaForm').getByText(/does not create a General Ledger entry/i)).toBeVisible();
  await expect(page.locator('#ttSdProduct')).not.toHaveValue('');
  await expect(page.locator('#ttSdProduct option:checked')).toContainText(/IRRI-6.*White.*Raw/i);
  await expect(page.locator('#ttSdStock')).not.toHaveValue('');
  await expect(page.locator('#ttSdStock option:checked')).toContainText(/TTI Rice Mills/i);
  await page.locator('#ttSdProduct').locator('xpath=..').locator(':scope > input').focus();
  await expect(page.locator('body > .tt-select-menu[data-tt-select-for="ttSdProduct"]')).toBeVisible();
  await page.locator('#ttSdStock').locator('xpath=..').locator(':scope > input').focus();
  await expect(page.locator('body > .tt-select-menu[data-tt-select-for="ttSdProduct"]')).toBeHidden();
  await activate(page.locator('#ttSodaLayer [data-soda-mode="search"]'));
  await expect(page.locator('#ttSodaSearch')).toBeVisible();
  await activate(page.locator('#ttSodaLayer .tt-window-close'));

  await deskAction(page, 'commodity', 'Bill Posting');
  await expect(page.locator('#ws-purchases')).toHaveClass(/tt-clean-modal/);
  await expect(page.locator('#purchaseEditor')).toHaveClass(/tt-editor-stage/);
  await expect(page.locator('#purchaseEditor')).toBeVisible({ timeout: 30_000 });
  await closeWorkspace(page);

  await deskAction(page, 'routine', 'Salaries & Staff');
  await expect(page.locator('#ws-expenses')).toHaveClass(/tt-entry-only/);
  await expect(page.locator('#rsPrepare')).toBeVisible({ timeout: 30_000 });
  await expect(page.locator('#rsSaveSal')).toBeHidden();
  await expect(page.locator('#rsAdvanceIcon')).toBeVisible();
  await activate(page.locator('#rsAdvanceIcon'));
  await expect(page.locator('#rsAdvanceAmount')).toBeVisible();
  await activate(page.locator('#rsSalaryBack'));
  await activate(page.locator('#rsPrepare'));
  await expect(page.locator('#expenseEditor h1')).toContainText('TOTAL SALARY PKR');
  await expect(page.locator('#rsMonth')).toBeVisible();
  const salaryPay = page.locator('[data-rs-draft-pay]').first();
  if (await salaryPay.count()) {
    await activate(salaryPay);
    await expect(page.locator('#rsPaySalAmt')).toBeVisible();
    await expect(page.locator('#rsPaySalBtn')).toHaveText('POST');
  }
  await closeWorkspace(page);

  await deskAction(page, 'exports', 'Inspection Bill');
  await expect(page.locator('#ttBillDesk [data-post]')).toBeVisible();
  await activate(page.locator('#ttBillDesk [data-post]'));
  await expect(page.locator('#ttBillShipmentSearch')).toBeVisible({ timeout: 30_000 });
  await expect(page.locator('#ttBillShipmentQuery')).toBeVisible();
  await expect.poll(() => page.locator('#ttBillShipmentQuery').evaluate(el => {
    const rect=el.getBoundingClientRect();return rect.top>=0&&rect.bottom<=innerHeight;
  }), { message: 'New popup must show the first input without scrolling' }).toBe(true);
  await activate(page.locator('#ttBillShipmentSearch .tt-window-close'));
  await deskAction(page, 'exports', 'Other Export Expense');
  await expect(page.locator('#ttOtherExportExpense')).toBeVisible({ timeout: 30_000 });
  await expect(page.locator('#ttExportExpenseForm [name="description"]')).toBeVisible();
  await expect(page.locator('#ttExportExpenseForm [name="paymentAccountId"]')).toBeVisible();
  await expect(page.locator('#ttExportExpenseForm')).not.toContainText(/gas|utility location/i);
  await activate(page.locator('#ttOtherExportExpense .tt-window-close'));

  await deskAction(page, 'exports', 'Bags Bill');
  await activate(page.locator('#ttBillDesk [data-post]'));
  await expect(page.locator('#purchaseEditor .ttbag')).toBeVisible({ timeout: 30_000 });
  await expect(page.locator('#bgLine')).toBeVisible();
  await expect(page.locator('#bgSupplier')).toBeHidden();
  await expect(page.locator('#bgGst')).toBeHidden();
  await expect(page.locator('#bgSave')).toBeHidden();
  await closeWorkspace(page);

  await deskAction(page, 'commodity', 'Local Sales & Receipts');
  await expect(page.locator('#ws-receivables')).toHaveClass(/tt-clean-modal/, { timeout: 30_000 });
  await expect(page.locator('#ws-receivables .tt-prev-search')).toBeVisible();
  await closeWorkspace(page);

  await deskAction(page, 'ledgers', 'Supplier / Broker');
  await expect(page.locator('#tal-party')).toBeVisible({ timeout: 30_000 });
  await expect(page.locator('#tal-print')).toBeVisible();
  await expect(page.locator('#tal-export')).toContainText('Excel');
  await expect(page.locator('#tal-period')).toHaveValue('till');
  await activate(page.locator('#tal-close'));

  await deskAction(page, 'ledgers', 'Party Ledgers');
  await expect(page.locator('#tal-party')).toBeVisible({ timeout: 30_000 });
  await expect(page.locator('#tal-account')).toHaveCount(0);
  await activate(page.locator('#tal-close'));

  await deskAction(page, 'ledgers', 'Account Ledgers');
  await expect(page.locator('#tal-account')).toBeVisible({ timeout: 30_000 });
  await activate(page.locator('#tal-close'));

  await deskAction(page, 'registers', 'Bill & Invoice Registers');
  await expect(page.locator('#ttSearchLayer')).toBeVisible();
  await expect(page.locator('#ttUniversalSearch')).toBeVisible();
  await activate(page.locator('#ttSearchLayer .tt-window-close'));

  await deskAction(page, 'routine', 'Utilities');
  await expect(page.locator('#expenseEditor .tt-search-select input').first()).toBeVisible({ timeout: 30_000 });
  await expect(page.locator('#ttUtilityTreatment')).toBeVisible();
  await expect(page.locator('#ttUtilityTreatment')).toContainText(/Debit.*Credit.*Balanced|Complete form/s);
  await responsive(page, 'professional Accounts modal');

  expect(failedRequests, 'Accounts resources must not fail').toEqual([]);
  expect(failedResponses, 'Accounts resources must not return HTTP errors').toEqual([]);
  expect(pageErrors, 'Accounts must not raise uncaught browser errors').toEqual([]);
});

test('TG customer receipt reads the live Exports and bank links without posting', async ({ page }) => {
  test.setTimeout(120_000);
  await signIn(page);
  const source = await page.evaluate(async () => {
    const response = await fetch('../api/tg_bank_transactions.php', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    return { status: response.status, body: await response.json() };
  });
  expect(source.status).toBe(200);
  expect(source.body.ok).toBe(true);
  const exportsReceipts = await page.evaluate(async () => { const response = await fetch('../api/tg_export_accounts_receipts.php', { credentials: 'same-origin' }); return { status: response.status, body: await response.json() }; });
  expect(exportsReceipts.status, 'Exports must be able to read posted TG Accounts receipts').toBe(200);
  expect(exportsReceipts.body.ok).toBe(true);
  expect(Array.isArray(exportsReceipts.body.receipts)).toBe(true);
  const contracts = source.body.contracts || [];
  expect(contracts.length, 'A saved TG Exports contract must reach TG Accounts').toBeGreaterThan(0);
  await page.evaluate(() => { localStorage.setItem('tt_accounts_entity', 'TG'); window.TT_TG_CUSTOMER_RECEIPTS.open(); });
  await expect(page.locator('#tgRcCustomer')).toBeAttached();
  await expect(page.locator('#tgRcTarget')).toBeAttached();
  await expect(page.locator('#tgRcContract')).toHaveCount(0);
  const amtContract = contracts.find(row => /AMT/i.test(row.ref || '') && /AMT/i.test(row.customer || ''));
  expect(amtContract, 'AMT must reach TG Accounts from its Exports contract').toBeTruthy();
  const customerSearch = page.locator('.tt-search-select:has(#tgRcCustomer) > input');
  await expect(customerSearch).toBeVisible();
  await customerSearch.fill('AMT');
  const customerMenu = page.locator('.tt-select-menu[data-tt-select-for="tgRcCustomer"]');
  await expect(customerMenu).toBeVisible();
  await customerMenu.getByRole('button', { name: /AMT/i }).click();
  await expect(page.locator('#tgRcCustomer')).toHaveValue(amtContract.customer);
  const options = await page.locator('#tgRcTarget option').allTextContents();
  const hasOpenTarget = amtContract.outstandingAdvance > 0 || (source.body.invoices || []).some(row => row.contractRef === amtContract.ref && row.outstandingNative > 0);
  expect(options.some(label => /advance|invoice/i.test(label) && /\b(?:USD|AED|EUR|GBP)\b/.test(label)), 'Confirmed advances and invoices must display their currency').toBe(hasOpenTarget);
  if (amtContract.paymentCode === 'CUSTOM' && amtContract.expectedAdvance === 0) expect(options.some(label => label.includes('amount not specified in sales contract'))).toBe(true);
  if (amtContract.paymentCode === 'CUSTOM') expect(amtContract.expectedAdvance, 'Custom wording must not reuse an old advance percentage').toBe(0);
  await expect(page.locator('#tgRcGross')).toHaveAttribute('readonly', '');
  await expect(page.locator('#tgRcChargeBank')).toHaveCount(0);
  await expect(page.locator('#tgRcRef')).toBeVisible();
  await expect(page.locator('#tgRcRef').locator('xpath=..')).toContainText('optional');
  await expect(page.locator('#tgRcCharge').locator('xpath=..')).toContainText('selected bank currency');
  if (amtContract.outstandingAdvance > 0) {
    const advanceLabel = await page.locator('#tgRcTarget option').filter({ hasText: `Sales contract advance · ${amtContract.ref}` }).textContent();
    const amount = value => Number(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    expect(advanceLabel).toContain(`Original ${amtContract.currency} ${amount(amtContract.expectedAdvance)}`);
    expect(advanceLabel).toContain(`Remaining ${amtContract.currency} ${amount(amtContract.outstandingAdvance)}`);
  }
  const target = await page.locator('#tgRcTarget option').evaluateAll(rows => rows.find(row => row.value)?.value || '');
  await page.locator('#tgRcTarget').evaluate((select, value) => { select.value = value; select.dispatchEvent(new Event('change', { bubbles: true })); }, target);
  const selected = contracts.find(row => target.endsWith(row.ref));
  if (selected && (source.body.banks || []).some(bank => bank.currency === selected.currency)) {
    expect(await page.locator('#tgRcBank option').count(), 'Matching TG bank must be selectable').toBeGreaterThan(1);
  }
  await expect(page.locator('#tgReceiptDialog h2')).toHaveText('TG Customer Receipt');
  await page.goto(`${BASE_URL}/module.php?id=exports`, { waitUntil: 'domcontentloaded', timeout: 90_000 });
  await expect(page.locator('#exports-tg-accounts-receipts')).toHaveCount(1);
  await expect.poll(() => page.evaluate(() => JSON.parse(localStorage.getItem('tt40exportreceipts') || 'null')), { timeout: 15_000 }).toEqual(exportsReceipts.body.receipts);
});


test('read-only transporter bill register reconciliation', async ({ page }) => {
  test.setTimeout(120_000);
  await signIn(page);
  const response = await page.request.get(`${BASE_URL}/api/accounts_workflows_v1.php?entity=TTI&section=transport`);
  expect(response.ok()).toBeTruthy();
  const result = await response.json();
  expect(result.ok).toBeTruthy();
  const matches = (result.bills || []).filter(b => String(b.invoiceNo || '').trim() === '204');
  // Report only the status of the user-reported invoice, never real amounts, party details or ledger content.
  console.log(`TRANSPORT_INVOICE_204_STATUS=${matches.length ? 'RECORDED' : 'NOT_FOUND'}`);
  const lookup = await page.request.get(`${BASE_URL}/api/accounts_shipment_lookup.php?entity=TTI&q=SEJ-73%2F27`);
  expect(lookup.ok()).toBeTruthy();
  const shipments = await lookup.json();expect(shipments.ok).toBeTruthy();
  console.log(`TRANSPORT_PROGRAMME_SEJ_LINK=${(shipments.rows || []).some(row => row.loadingProgramme === 'SEJ-73/27' && row.pakistanExporter === 'TTI') ? 'READY' : 'NO_CURRENT_MATCH'}`);
  const source = await page.request.get(`${BASE_URL}/accounts/accounts-accounting-desk.js`);
  expect(source.ok()).toBeTruthy();expect(await source.text()).toContain('Add another shipment');

});


test('Accounts repair: clear held-entry label and ledger survives focus changes', async ({ page }) => {
  test.setTimeout(180_000);
  await signIn(page);
  await expect(page.locator('#ttAccountingDesk')).toBeVisible({ timeout: 30_000 });
  await expect(page.getByRole('heading', { name: 'Held / Incomplete Entries', exact: true })).toBeVisible();
  await expect(page.getByText('Only entries that need review before posting appear here.', { exact: true })).toBeVisible();
  await activate(page.locator('[data-tt-area="commodity"]'));
  await expect(page.locator('#ttDeskWork .tt-action').filter({ hasText: 'Due Payment Working' })).toHaveCount(0);
  await page.evaluate(() => window.TT_ALL_LEDGERS.open('POSTS'));
  await expect(page.locator('#tt-all-ledgers .tal-controls')).toBeVisible({ timeout: 30_000 });
  await page.locator('#tal-filter').fill('ledger focus draft');
  await page.evaluate(() => {
    window.__qaFilterNode = document.querySelector('#tal-filter');
    window.__qaLedgerNode = document.querySelector('#tt-all-ledgers');
    window.dispatchEvent(new Event('blur'));
    document.dispatchEvent(new Event('visibilitychange'));
    window.dispatchEvent(new Event('focus'));
  });
  await responsive(page, 'Ledger after browser focus change');
  await expect(page.locator('#tt-all-ledgers .tal-controls')).toBeVisible();
  expect(await page.evaluate(() => window.__qaLedgerNode === document.querySelector('#tt-all-ledgers') && window.__qaFilterNode === document.querySelector('#tal-filter'))).toBe(true);
  await expect(page.locator('#tal-filter')).toHaveValue('LEDGER FOCUS DRAFT');
});


test('authenticated QA cannot read the Super Admin Director approval feed', async ({ page }) => {
  await signIn(page);
  const response=await page.request.get(`${BASE_URL}/api/director_approvals.php`);
  expect(response.status()).toBe(403);
  const result=await response.json();
  expect(result.ok).toBe(false);
  expect(result).not.toHaveProperty('approvals');
});
