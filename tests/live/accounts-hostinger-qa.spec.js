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
  const cookieEpochs = new Map();
  const epoch=value=>{if(!value)return 0;if(!cookieEpochs.has(value))cookieEpochs.set(value,cookieEpochs.size+1);return cookieEpochs.get(value)};
  trace.flush = () => Promise.allSettled(pending);
  page.on('response', response => {
    pending.push((async () => {
    const path = new URL(response.url()).pathname;
    if (new URL(response.url()).origin!==new URL(BASE_URL).origin||!path.endsWith('.php')) return;
    const [headers, requestHeaders] = await Promise.all([response.allHeaders(), response.request().allHeaders()]);
    trace.push({
      ms: Date.now() - started, path, status: response.status(),
      serverTiming:headers['server-timing']||'',
      serverWaitMs:Math.round(response.request().timing().responseStart-response.request().timing().requestStart),
      location: headers.location || '', method: response.request().method(),
      sentSessionCookie: /(?:^|;\s*)TRANSTRADE_SESSION=/.test(requestHeaders.cookie || ''),
      requestCookieEpoch:epoch((requestHeaders.cookie||'').match(/(?:^|;\s*)TRANSTRADE_SESSION=([^;]*)/)?.[1]||''),
      responseCookieEpoch:epoch((headers['set-cookie']||'').match(/TRANSTRADE_SESSION=([^;]*)/)?.[1]||''),
      setsSessionCookie: /TRANSTRADE_SESSION=/.test(headers['set-cookie'] || ''),
      fetchSite: requestHeaders['sec-fetch-site'] || '',
      server: headers.server || '', cache: headers['x-hcdn-cache-status'] || headers['x-litespeed-cache'] || headers['x-cache'] || headers['cf-cache-status'] || '',
      age: headers.age || '', cacheControl: headers['cache-control'] || '',
      authRevision:headers['x-tt-auth-revision']||'', sessionHandler:headers['x-tt-session-handler']||'', sessionSerializer:headers['x-tt-session-serializer']||'', sessionNode:headers['x-tt-session-node']||'', sessionFile:headers['x-tt-session-file']||'', sessionTransition:headers['x-tt-session-transition']||''
    });
    const entry=trace[trace.length-1];
    console.log('SESSION_REQUEST_TIMING '+JSON.stringify({path:entry.path,method:entry.method,status:entry.status,serverWaitMs:entry.serverWaitMs,serverTiming:entry.serverTiming}));
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
    await expect(page).toHaveTitle(/Transtrade Accounts/, {timeout:30_000});
    await expect.poll(()=>page.evaluate(()=>window.TT_ACCOUNTING_DESK?.installed||false),{timeout:30_000}).toBe(true);
    await trace.flush();
    console.log('SESSION_NAVIGATION_OK ' + JSON.stringify(trace.slice(-6)));
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
  const workspaceClose = page.locator('.workspace.active .tt-clean-close:visible, .workspace.active > .panelHead [data-back]:visible');
  if (await page.locator('.workspace.active').count()) {
    await expect(workspaceClose.first()).toBeVisible();
    await activate(workspaceClose.first());
  }
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
  await closeWorkspace(page);
  await deskAction(page, 'routine', 'Salaries & Staff');
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

  await deskAction(page, 'ledgers', 'Ledger');
  await expect(page.locator('#tal-view')).toBeVisible();
  await expect(page.locator('#tal-view')).toHaveValue('party');
  const suggestions=await page.locator('#tal-parties option').evaluateAll(rows=>rows.map(r=>r.value.trim().toLowerCase()));
  expect(new Set(suggestions).size,'Canonical party suggestions must not repeat name and code aliases').toBe(suggestions.length);
  const partyResponse=await page.request.get(BASE_URL+'/api/accounts_ledger_browser.php?entity=TTI&category=party&from=0001-01-01&to=2099-12-31');
  expect(partyResponse.status()).toBe(200);
  const partyBrowser=await partyResponse.json();
  const aliName=partyBrowser.parties.find(name=>name.toLowerCase().replace(/[^a-z0-9]/g,'').startsWith('alisul'));
  expect(Boolean(aliName),'Resolve the saved full party name before requesting its ledger').toBe(true);
  const invoiceResponse=await page.request.get(BASE_URL+'/api/accounts_ledger_browser.php?'+new URLSearchParams({entity:'TTI',category:'party',party:aliName,from:'0001-01-01',to:'2099-12-31'}));
  expect(invoiceResponse.status()).toBe(200);
  const invoiceLedger=await invoiceResponse.json();
  expect(invoiceLedger.invoiceRows.length,'Ali Sulaiman issued invoices must reach the party ledger').toBeGreaterThan(0);
  expect(invoiceLedger.invoiceRows.every(r=>r.currency&&typeof r.amount==='number'&&r.amount>0)).toBe(true);
  console.log('LIVE_CUSTOMER_INVOICE_LINK_OK '+JSON.stringify({invoiceRows:invoiceLedger.invoiceRows.length}));
  await expect(page.locator('#tal-party')).toBeVisible({ timeout: 30_000 });
  await expect(page.locator('#tal-print')).toBeVisible();
  await expect(page.locator('#tal-export')).toContainText('Excel');
  await expect(page.locator('#tal-period')).toHaveValue('till');
  await expect(page.locator('#tal-account')).toHaveCount(0);
  await activate(page.locator('#tal-close'));

  await deskAction(page, 'ledgers', 'Ledger');
  await page.locator('#tal-view').selectOption('other');
  await expect(page.locator('#tal-account')).toBeVisible({ timeout: 30_000 });
  await activate(page.locator('#tal-close'));

  await deskAction(page, 'ledgers', 'Post ID Register');
  await expect(page.locator('#tal-filter')).toBeVisible();
  await expect(page.locator('#tal-view')).toHaveCount(0);
  await activate(page.locator('#tal-close'));

  await deskAction(page, 'routine', 'Pay Expense');
  await expect(page.locator('#dexPayee')).toBeVisible({ timeout: 30_000 });
  await expect(page.locator('#dexPerson')).toHaveCount(0);
  await expect(page.locator('#ttExpenseDesk [data-post]')).toBeDisabled();
  await activate(page.locator('#ttExpenseDesk [data-add]'));
  await expect(page.locator('#ttExpenseDesk .dex-row')).toHaveCount(2);
  await activate(page.locator('#ttExpenseDesk [data-close]'));
  await expect(page.locator('#ttPaymentPlanningTop')).toHaveCount(0);
  await deskAction(page, 'routine', 'Pay Expense');
  await expect(page.locator('#ttExpenseDesk [data-expense-mode]')).toHaveCount(3);
  await activate(page.locator('#ttExpenseDesk [data-expense-mode=utility]'));
  await expect(page.locator('#ttExpenseDesk #evPayMaster').locator('..')).toBeVisible({ timeout: 30_000 });
  await expect(page.locator('#ttExpenseDesk #evUtilityFields')).toBeHidden();
  const utilityChoices=await page.locator('#evPayMaster option').evaluateAll(options=>options.filter(o=>o.value&&o.value!=='MANUAL').slice(0,3).map(o=>({value:o.value,label:o.textContent})));
  for(const choice of utilityChoices){
    await page.locator('#evPayMaster').selectOption(choice.value,{force:true});
    await expect(page.locator('#evUtilityFields')).toBeVisible();
    const saved=await page.evaluate(()=>{const q=id=>document.getElementById(id),location=q('evPayLocName'),type=q('evPayType');return {location:location.selectedOptions[0]?.textContent||'',visibleLocation:location.closest('.tt-search-select')?.querySelector('input')?.value,type:type.selectedOptions[0]?.textContent||'',visibleType:type.closest('.tt-search-select')?.querySelector('input')?.value,provider:q('evPayee').value,reference:q('evConsumerRef').value};});
    expect(saved.provider).toBeTruthy();expect(choice.label).toContain(saved.provider);expect(choice.label).toContain(saved.location);
    if(saved.visibleLocation!==undefined)expect(saved.visibleLocation).toBe(saved.location);
    if(saved.visibleType!==undefined)expect(saved.visibleType).toBe(saved.type);
    if(choice.label.includes('…'))expect(saved.reference.replace(/\s/g,'').slice(-5)).toBe(choice.label.split('…').pop().trim());
  }
  console.log('LIVE_SAVED_UTILITY_SELECTIONS_OK '+utilityChoices.length);
  await page.locator('#evPayMaster').selectOption('',{force:true});

  await expect(page.locator('#ttExpenseDesk #evUtilityFor')).toHaveValue('SHARED');
  await expect(page.locator('#ttExpenseDesk #evUtilityForWrap')).toBeHidden();
  await activate(page.locator('#ttExpenseDesk [data-expense-mode=card]'));
  await expect(page.locator('#ttExpenseDesk #evStatementForm')).toBeVisible({ timeout: 30_000 });
  await expect(page.locator('#ttExpenseDesk #evAddStatement')).toBeHidden();
  await expect(page.locator('#evCardFields')).toBeHidden();
  const savedCard=await page.locator('#evStatementCard option').evaluateAll(options=>options.find(o=>o.value)?.value||'');
  if(savedCard){await page.locator('#evStatementCard').selectOption(savedCard,{force:true});await expect(page.locator('#evCardFields')).toBeVisible();}

  await expect(page.locator('#ttExpenseDesk [data-expense-mode=card]')).toHaveAttribute('type','radio');
  await expect(page.locator('#ttExpenseDesk [data-expense-mode=card]')).not.toHaveAttribute('role','combobox');
  await expect(page.locator('#ttExpenseDesk #evManageCard')).toHaveAttribute('type','checkbox');
  await expect(page.locator('#ttExpenseDesk #evManageCard')).toBeDisabled();
  await expect(page.locator('#ttExpenseDesk .tte-alloc')).toHaveCount(0);
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
  expect(await page.locator('#tgRcGross').evaluate(node=>node.tagName)).toBe('OUTPUT');
  await expect(page.locator('#tgRcChargeBank')).toHaveCount(0);
  await expect(page.locator('#tgRcRef')).toBeHidden();
  await expect(page.locator('#tgRcRef').locator('xpath=..')).toContainText('optional');
  await expect(page.locator('#tgRcCharge').locator('xpath=ancestor::label[1]')).toContainText('selected bank currency');
  if (amtContract.outstandingAdvance > 0) {
    const advanceLabel = await page.locator('#tgRcTarget option').filter({ hasText: `Sales contract advance · ${amtContract.ref}` }).textContent();
    const amount = value => Number(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    expect(advanceLabel).toContain(`Original ${amtContract.currency} ${amount(amtContract.expectedAdvance)}`);
    expect(advanceLabel).toContain(`Remaining ${amtContract.currency} ${amount(amtContract.outstandingAdvance)}`);
  }
  const target = await page.locator('#tgRcTarget option').evaluateAll(rows => rows.find(row => row.value)?.value || '');
  await page.locator('#tgRcTarget').evaluate((select, value) => { select.value = value; select.dispatchEvent(new Event('change', { bubbles: true })); }, target);
  if(target) await expect(page.locator('#tgRcRef')).toBeVisible();
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

test('session persists through twelve Masters and Accounts round trips', async ({ page }) => {
  test.setTimeout(240_000);
  const trace=traceSessionNavigation(page);
  await signIn(page);
  for(let round=0;round<12;round++){
    await expect(page.locator('#ttMasterTop')).toBeVisible({timeout:30_000});
    await page.locator('#ttMasterTop').click();
    await expect(page.locator('#masterBackTop')).toBeVisible({timeout:30_000});
    await returnFromMasters(page,trace);
    console.log('SESSION_ROUNDTRIP_OK ' + (round+1));
  }
});


test('read-only Exports and Milling loading, errors and Masters navigation', async ({ page }) => {
  test.setTimeout(300_000);
  await signIn(page);
  const failures=[],errors=[],requests=[];
  page.on('pageerror',error=>{errors.push(String(error.message));console.log('MODULE_JS_ERROR '+String(error.message))});
  page.on('response',response=>{
    const path=new URL(response.url()).pathname;
    if(response.status()>=500)failures.push({path,status:response.status()});
    if(response.request().method()==='POST'&&!path.endsWith('/session_activity.php'))requests.push(path);
  });
  for(const moduleId of ['exports','milling']){
    for(let round=0;round<2;round++){
      const started=Date.now();
      const response=await page.goto(BASE_URL+'/module.php?id='+moduleId,{waitUntil:'domcontentloaded',timeout:90_000});
      console.log('MODULE_STARTUP_STATE '+JSON.stringify(await page.evaluate(()=>({module:window.TT_MODULE_ACCESS?.moduleId,loadError:!!window.TT_EXPORTS_LOAD_ERROR,ready:document.readyState,hasHome:!!document.querySelector('#homeGrid'),hasApp:!!document.querySelector('#app'),runtime:document.querySelector('#exports-app-js')?.getAttribute('src')}))));
      expect(response.status()).toBe(200);
      await expect(page).toHaveTitle(moduleId==='exports'?/Transtrade Exports/:/Transtrade|Milling/i);
      await expect.poll(()=>page.evaluate(()=>window.TT_MODULE_ACCESS?.moduleId),{timeout:30_000}).toBe(moduleId);
      await expect(page.locator('#ttMasterTop')).toBeVisible({timeout:30_000});
      if(moduleId==='exports'){
        await expect(page.locator('#app')).toBeVisible();
        await expect(page.locator('#ttExportsLoadError')).toHaveCount(0);
      }else await expect(page.locator('#homeGrid')).toHaveCount(1);
      const body=await response.body();
      const metadata=await page.evaluate(()=>({
        domReadyMs:Math.round(performance.getEntriesByType('navigation')[0]?.domContentLoadedEventEnd||0),
        resources:performance.getEntriesByType('resource').filter(r=>new URL(r.name).origin===location.origin).map(r=>({path:new URL(r.name).pathname,ms:Math.round(r.duration),bytes:r.transferSize})),
        initialKeys:Object.keys(window.TT_SHARED_SYNC||{}),loadError:!!window.TT_EXPORTS_LOAD_ERROR
      }));
      const artworkMetadata=await page.evaluate(()=>{
        const raw=localStorage.getItem('transtrade_export_v3_operational')||'';
        return {exportStateBytes:new TextEncoder().encode(raw).length,inlineImages:(raw.match(/data:image[/]/g)||[]).length,artworkRefs:(raw.match(/export-assets[.]php[?]name=legacy/g)||[]).length};
      });
      console.log('MODULE_ARTWORK_TRANSPORT '+JSON.stringify({module:moduleId,round,...artworkMetadata}));
      console.log('MODULE_LOAD_TIMING '+JSON.stringify({module:moduleId,round,elapsedMs:Date.now()-started,htmlBytes:body.length,inlineImageBytes:(body.toString().match(/data:image[/][^;]+;base64,[A-Za-z0-9+/=]+/g)||[]).reduce((n,s)=>n+s.length,0),serverTiming:response.headers()['server-timing']||'',...metadata}));
      if(moduleId==='exports'&&round===0&&await page.locator('#exports-app-js[src="/export-assets.php?name=app.js"]').count()){
        const runtime=await page.request.get(BASE_URL+'/export-assets.php?name=app.js');
        expect(runtime.status()).toBe(200);
        const etag=runtime.headers().etag;
        expect(etag).toBeTruthy();
        const script=await runtime.text();
        expect(script).toContain('/export-assets.php?name=TG_sign.png&v=');
        expect(script).not.toContain('data:image/png;base64,');
        const unchanged=await page.request.get(BASE_URL+'/export-assets.php?name=app.js',{headers:{'If-None-Match':etag}});
        expect(unchanged.status()).toBe(304);
        const legacyUrl=await page.evaluate(()=>{
          const raw=localStorage.getItem('transtrade_export_v3_operational')||'';
          return raw.match(/[/]export-assets[.]php[?]name=legacy&sha256=[a-f0-9]{64}/)?.[0]||'';
        });
        if(legacyUrl){
          const legacy=await page.request.get(BASE_URL+legacyUrl);
          expect(legacy.status()).toBe(200);expect(legacy.headers()['content-type']).toMatch(/^image[/](png|jpeg|gif|webp)$/);
          console.log('LEGACY_ARTWORK_LOAD_OK '+JSON.stringify({bytes:(await legacy.body()).length,status:legacy.status()}));
        }
        const artwork=await page.request.get(BASE_URL+'/export-assets.php?name=TG_sign.png');
        expect(artwork.status()).toBe(200);expect(artwork.headers()['content-type']).toBe('image/png');
        const missing=await page.request.get(BASE_URL+'/export-assets.php?name=../auth_store.php');
        expect(missing.status()).toBe(404);
        console.log('EXPORT_RUNTIME_CACHE_OK '+JSON.stringify({runtimeBytes:Buffer.byteLength(script),conditionalStatus:unchanged.status()}));
      }
    }
    await page.locator('#ttMasterTop').click();
    await expect(page).toHaveURL(BASE_URL+'/index.php?view=masters&from='+moduleId,{timeout:30_000});
    await expect(page.locator('#masterBackTop')).toBeVisible({timeout:30_000});
    await page.locator('#masterBackTop').click();
    await expect.poll(()=>page.evaluate(()=>window.TT_MODULE_ACCESS?.moduleId),{timeout:30_000}).toBe(moduleId);
  }
  console.log('MODULE_STARTUP_POST_PATHS '+JSON.stringify([...new Set(requests)]));
  expect(failures,'Modules must load without server errors').toEqual([]);
  expect(errors,'Modules must load without JavaScript errors').toEqual([]);
});


test('deployed Bank Entry, Bank Finance and subaccount forms respect read-only access', async ({ page }) => {
  test.setTimeout(120_000);
  const writes=[];
  await signIn(page);
  page.on('request',r=>{if(r.method()==='POST'&&/\/api\/(?:bank_entries|accounts_subaccounts)\.php/.test(r.url()))writes.push(r.url());});
  await expect.poll(()=>page.evaluate(()=>typeof window.TT_BANK_ENTRIES?.open),{timeout:30_000}).toBe('function');
  await deskAction(page,'bank','Bank Entry');
  const form=page.locator('#amBankForm');
  await expect(form).toBeVisible();
  await expect(form.getByRole('button',{name:'Post',exact:true})).toBeDisabled();
  await form.locator('[name=type]').selectOption('SAVING_PROFIT');
  await expect(form.locator('[name=withholdingTax]')).toBeVisible();
  await expect(page.locator('#amPayBankDetails')).toBeHidden();
  await form.locator('[name=type]').selectOption('FINANCE_REPAY');
  await expect(form.locator('[name=facilityId]')).toBeVisible();
  await expect(form.locator('[name=withholdingTax]')).toBeHidden();
  await activate(page.locator('#amWindow [data-close]'));
  const financeResponse=await page.request.get(BASE_URL+'/api/bank_entries.php?entity=TTI');expect(financeResponse.status()).toBe(200);const financeData=await financeResponse.json();
  if((financeData.facilities||[]).some(f=>f.active)||financeData.canCreate){
    await deskAction(page,'bank','Bank Finance');await expect(page.locator('#amWindow')).toContainText('Principal outstanding');await expect(page.locator('#amWindow [data-facility]')).toHaveCount(0);await activate(page.locator('#amWindow [data-close]'));
  }else {await activate(page.locator('.tt-back-areas'));await activate(page.locator('[data-tt-area="bank"]'));await expect(page.locator('[data-area-palette="bank"]')).toBeVisible();await expect(page.locator('.tt-action').filter({hasText:'Bank Finance'})).toHaveCount(0);}

  await deskAction(page,'ledgers','Ledger');
  await expect(page.locator('#tal-view')).toHaveValue('party');
  await page.locator('#tal-view').selectOption('other');
  await activate(page.locator('#tal-manage'));
  await expect(page.locator('#amWindow')).toContainText('Withholding Tax Report');
  await expect(page.locator('#amWindow [data-add]')).toHaveCount(0);
  await expect(page.locator('#amWindow [data-edit]')).toHaveCount(0);
  await expect(page.locator('#amWindow [data-delete]')).toHaveCount(0);
  await activate(page.locator('#amWindow [data-heads]'));
  await expect(page.locator('#amWindow')).toContainText('Bank Finance Principal');
  await activate(page.locator('#amWindow [data-close]'));
  expect(writes).toEqual([]);
});


test('Customer / Export Receivables links group buyer invoices without posting', async ({page}) => {
  await signIn(page);
  await expect(page.locator('#ttSummaryCards [data-summary="export"]')).toBeVisible({timeout:30000});
  await expect(page.locator('#ttSummaryCards [data-summary="export"] small')).toHaveText('Customer / Export Receivables');
  const entity=await page.evaluate(()=>localStorage.getItem('tt_accounts_entity')||'TTI');
  const response=await page.request.get(`${BASE_URL}/api/accounts_dashboard.php?entity=${entity}`);
  expect(response.ok()).toBeTruthy();
  const payload=await response.json();
  expect(payload.customerReceivables).toBeTruthy();
  expect(payload.summaries.export).toEqual(payload.customerReceivables.rows.map(row=>({...row,dateDisplay:/^\d{4}-\d{2}-\d{2}$/.test(row.date||'')?row.date.split('-').reverse().join('-'):''})).sort((a,b)=>b.amount-a.amount));
  for(const row of payload.customerReceivables.rows){
    expect(payload.customerReceivables.entities).toContain(row.entity);
    expect(row.draft).toBe(false);
    expect(row.amount).toBe(Math.max(0,Math.round((row.invoiceAmount-row.received)*100)/100));
  }
  await activate(page.locator('#ttSummaryCards [data-summary="export"]'));
  await expect(page.locator('#ttCustomerReceivables')).toBeVisible();
  await expect(page.locator('#ttCustomerReceivables h2')).toHaveText('Customer / Export Receivables');
  await expect(page.locator('#ttCustomerReceivables')).toContainText('TG settlement packs are excluded.');
  await activate(page.locator('#ttCustomerReceivables .tt-window-close'));
  await expect(page.locator('#ttCustomerReceivables')).toBeHidden();
});


test('party payment roles and company-wide ledger matching review are read-only', async ({page}) => {
  test.setTimeout(120_000);
  await signIn(page);
  const to=new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Karachi',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
  const params=new URLSearchParams({entity:'TTI',category:'party',from:'1900-01-01',to});
  const overview=await page.request.get(`${BASE_URL}/api/accounts_ledger_browser.php?${params}`);
  expect(overview.status()).toBe(200);const company=await overview.json();expect(company.ok).toBe(true);
  console.log('COMPANY_PARTY_MATCHING_REVIEW '+JSON.stringify({count:company.missingPartyLines,sources:(company.unmatchedPartyRows||[]).map(r=>({account:r.account,sourceType:r.sourceType}))}));
  for(const r of company.unmatchedPartyRows||[]){
    const response=await page.request.get(`${BASE_URL}/api/accounts_ledger_browser.php?${params}&postId=${encodeURIComponent(r.voucher)}`);expect(response.status()).toBe(200);const {post}=await response.json();
    console.log('UNASSIGNED_POST_SOURCE '+JSON.stringify({account:r.account,sourceType:post.sourceType,sourceLinkFields:Object.keys(post.meta||{}).filter(key=>/^(supplierBillId|billId|bagBillId|purchaseId|receiptId|candidateId|openingHelper|openingBalance|salaryRunId|runId)$/.test(key)),lineKeys:post.lines.filter(l=>l.account===r.account).map(l=>Object.keys(l))}));
  }
  const register=await page.request.get(`${BASE_URL}/api/accounts_ledger_browser.php?entity=ALL&account=POSTS&from=1900-01-01&to=${to}`);expect(register.status()).toBe(200);const posts=await register.json(),types={};for(const r of posts.rows)types[r.sourceType]=(types[r.sourceType]||0)+1;
  const requestedPost=posts.rows.find(r=>/(?:^|-)2026-0*196$/.test(r.voucher)||/(?:^|-)2026-0*196$/.test(r.publicPostId||''));console.log('REQUESTED_POST_DELETE_STATUS '+JSON.stringify({found:!!requestedPost,sourceType:requestedPost?.sourceType,canDeleteAsQa:requestedPost?.canDelete,requiresOriginalWorkflow:!!requestedPost?.deleteReason}));
  console.log('POSTED_BOOKS_PROVENANCE '+JSON.stringify({sourceTypes:Object.keys(types),hasEntriesBeforeFinancialYear:posts.rows.some(r=>r.date<'2026-07-01'),importTypes:[...new Set(posts.rows.filter(r=>/IMPORT|HISTORICAL|TRIAL_BALANCE/i.test(r.sourceType)).map(r=>r.sourceType))]}));
  await page.evaluate(()=>{localStorage.setItem('tt_accounts_entity','TTI');return window.TT_SUPPLIER_SETTLEMENT_UI.openBills('TRANSPORT');});
  await expect(page.locator('#ttSimpleBills h2')).toHaveText('Party Payments');
  const input=page.locator('#ttSimpleBills [data-payee]');await input.fill('ditta');await input.click();
  console.log('LIVE_TRANSPORT_PICKER_STATE '+JSON.stringify(await page.evaluate(()=>({entity:localStorage.getItem('tt_accounts_entity'),role:document.querySelector('#ttSimpleBills [data-payee]')?.dataset.masterRole,managed:document.querySelector('#ttSimpleBills [data-payee]')?.dataset.ttMasterManage,sourceHasDitta:[...document.querySelectorAll('#ttSimplePayees option')].some(o=>/MEHR.*ALLAH.*DITTA/i.test(o.value)),menuHasDitta:[...document.querySelectorAll('.tt-party-menu .tt-option-row>button:first-child')].some(b=>/MEHR.*ALLAH.*DITTA/i.test(b.textContent))}))));
  const ditta=page.locator('.tt-party-menu:not([hidden]) .tt-option-row>button:first-child').filter({hasText:/MEHR\s+ALLAH\s+DITTA/i});await expect(ditta,'Ditta must be an eligible transport party').toHaveCount(1);
  const providerName=await ditta.textContent();
  const unpaidResponse=await page.request.get(`${BASE_URL}/api/supplier_settlements.php?entity=TTI`);expect(unpaidResponse.status()).toBe(200);const unpaid=await unpaidResponse.json();
  const identity=n=>String(n||'').toLowerCase().replace(/[^\p{L}\p{N}]/gu,'');
  for(const aleem of (company.parties||[]).filter(n=>/ALEEM/i.test(n))){
    const ar=await page.request.get(`${BASE_URL}/api/accounts_ledger_browser.php?${params}&party=${encodeURIComponent(aleem)}`);expect(ar.status()).toBe(200);const al=await ar.json();
    const openingRows=(unpaid.payables||[]).filter(r=>r.openingBalance&&identity(r.supplier)===identity(aleem));
    const openingSources=[];
    for(const row of (al.rows||[]).filter(r=>['2110','2120','2130','2140','2190'].includes(r.account)).slice(-10)){
      const jr=await page.request.get(`${BASE_URL}/api/accounts_ledger_browser.php?${params}&postId=${encodeURIComponent(row.voucher)}`);const jp=await jr.json();if(jp.post)openingSources.push({sourceType:jp.post.sourceType,markedOpening:!!jp.post.meta?.openingBalance||!!jp.post.openingBalance,isFinancialYearStart:jp.post.date==='2026-07-01',control:row.account});
    }
    console.log('ALEEM_PARTY_OPENING_STATUS '+JSON.stringify({hasUnpaidOpening:openingRows.length>0,hasCreditorBalance:Number(al.closing)<-.005,openingSources}));
  }

  const providerBills=(unpaid.payables||[]).filter(r=>r.category==='TRANSPORT'&&identity(r.supplier)===identity(providerName));
  const ledgerResponse=await page.request.get(`${BASE_URL}/api/accounts_ledger_browser.php?${params}&party=${encodeURIComponent(providerName)}`);expect(ledgerResponse.status()).toBe(200);const providerLedger=await ledgerResponse.json();
  console.log('LIVE_TRANSPORT_PAYABLE_RECONCILIATION '+JSON.stringify({hasUnpaidBills:providerBills.length>0,hasCreditBalance:Number(providerLedger.closing)<-.005,hasOpeningBalance:(unpaid.payables||[]).some(r=>r.openingBalance&&identity(r.supplier)===identity(providerName))}));
  expect(providerBills.length>0||Number(providerLedger.closing)>=-.005,'A transport credit balance must have a payable item').toBe(true);
  await ditta.click();await expect(page.locator('#ttSimpleBills [data-payee]')).toHaveValue(providerName.trim());
  await expect(page.locator('#ttSimpleBills [data-bill]')).toHaveCount(providerBills.length);
  const voucherDate=page.locator('#ttSimpleBills [data-date]'),chequeDate=page.locator('#ttSimpleBills [data-cheque-date]');
  if(await chequeDate.count()){
    await voucherDate.fill('2026-10-01');await expect(chequeDate).toHaveValue('2026-10-01');
    await chequeDate.fill('2026-09-30');await voucherDate.fill('2026-10-02');await expect(chequeDate).toHaveValue('2026-09-30');
    await page.locator('#ttSimpleBills [data-bank-method]').selectOption('ONLINE_BANKING');await expect(page.locator('#ttSimpleBills [data-cheque-date]')).toBeVisible();await page.locator('#ttSimpleBills [data-cheque-date]').fill('2026-09-29');await voucherDate.fill('2026-10-03');await expect(page.locator('#ttSimpleBills [data-cheque-date]')).toHaveValue('2026-09-29');console.log('LIVE_ONLINE_BANKING_PAYMENT_DATE_OK');
    console.log('LIVE_VOUCHER_CHEQUE_DATES_EDITABLE_OK');
  }

  if(providerBills.length){await page.locator('#ttSimpleBills [data-bill]').first().check();await page.locator('#ttSimpleBills [data-amount]').fill('1');await expect(page.locator('#ttSimpleBills [data-discount-choice]')).toContainText('as discount?');expect(await page.locator('#ttSimpleBills [data-discount-enabled]').isChecked()).toBe(false);}
  const openingTick=page.locator('#ttSimpleBills [data-include-opening]');
  const providerOpenings=(unpaid.payables||[]).filter(r=>r.openingBalance&&identity(r.supplier)===identity(providerName));
  await expect(openingTick).toHaveCount(1);
  if(providerOpenings.length){
    await expect(openingTick).toBeEnabled();
    await openingTick.check();
    for(const box of await page.locator('#ttSimpleBills [data-bill]:not([disabled])').all())if(await box.isChecked())await box.uncheck();
    const onlyOpening=await page.locator('#ttSimpleBills [data-allocation-preview]').textContent();expect(onlyOpening).toContain('Balance B/F');
    for(const row of await page.locator('#ttSimpleBills [data-allocation-preview] tr').all())await expect(row).toContainText('Balance B/F');
    console.log('LIVE_TRANSPORT_OPENING_ONLY_SELECTION_OK');
  }else{
    await expect(openingTick).toBeDisabled();await expect(openingTick).not.toBeChecked();
    await expect(openingTick).toBeHidden();
    await expect(page.locator('#ttSimpleBills [data-review-opening]')).toBeVisible();
    console.log('LIVE_TRANSPORT_NO_UNPAID_OPENING_GUARD_OK');
  }
  expect(await page.locator('#ttSimpleBills [data-discount],#ttSimpleBills [data-discount-reason]').count()).toBe(0);
  await page.locator('#ttSimpleBills [data-close]').click();
  await page.evaluate(()=>window.TT_ALL_LEDGERS.open('','party'));
  await expect(page.locator('#tal-result')).not.toContainText('Loading ledger');
  if(company.unmatchedPartyRows?.length)await expect(page.locator('#tal-result')).toContainText('Company-wide party matching review');
  console.log('LIVE_TRANSPORT_PARTY_AND_DISCOUNT_UI_OK');
});


test('forward booking and register are Pakistan-only and remain read-only for QA',async({page})=>{
 test.setTimeout(120_000);await signIn(page);const writes=[];page.on('request',r=>{if(r.method()==='POST'&&/\/api\/(forward_bookings|export_receipts)\.php/.test(r.url()))writes.push(r.url());});
 const r=await page.request.get(BASE_URL+'/api/forward_bookings.php?entity=TTI');expect(r.status()).toBe(200);const d=await r.json();expect(d.canCreate).toBe(false);expect(d.canEdit).toBe(false);expect(d.canDelete).toBe(false);
 for(const b of d.bookings){expect(b.waitingDays).toBeGreaterThanOrEqual(0);expect(b.windowDays).toBeGreaterThan(0);expect(b.remainingUsd).toBeGreaterThanOrEqual(0);expect(b.usedUsd+b.remainingUsd).toBeCloseTo(b.amountUsd,2);}
 await deskAction(page,'bank','Forward Booking');await expect(page.locator('#ttForwardBookings')).toBeVisible();await expect(page.locator('#ttForwardBookings [data-new]')).toHaveCount(0);await expect(page.locator('#ttForwardBookings [data-settle]')).toHaveCount(0);await activate(page.locator('#ttForwardBookings [data-close]'));
 await deskAction(page,'ledgers','Forward Booking Register');await expect(page.locator('#ttForwardBookings')).toContainText('Forward Booking Register');await expect(page.locator('#ttForwardBookings [data-new]')).toHaveCount(0);await activate(page.locator('#ttForwardBookings [data-close]'));
 const offshore=await page.request.get(BASE_URL+'/api/forward_bookings.php?entity=TG');expect(offshore.status()).toBe(422);expect(writes).toEqual([]);console.log('LIVE_FORWARD_BOOKING_REGISTER_READ_ONLY_OK');
});


test('historical bag entry and service ledger groups preserve live read-only books',async({page})=>{
 test.setTimeout(120_000);await signIn(page);const writes=[];page.on('request',r=>{if(r.method()==='POST'&&/\/api\/(bag_purchases|supplier_settlements)\.php/.test(r.url()))writes.push(r.url());});
 const response=await page.request.get(BASE_URL+'/api/bag_purchases.php?entity=TTI');expect(response.status()).toBe(200);const view=await response.json();expect(view.canCreateHistorical).toBe(false);expect(view.canEditHistorical).toBe(false);
 await deskAction(page,'exports','Bags Bill');await activate(page.locator('#ttBillDesk [data-post]'));await expect(page.locator('[name=bgPurposeChoice][value=HISTORICAL]')).toBeVisible();await page.locator('[name=bgPurposeChoice][value=HISTORICAL]').check();await expect(page.locator('#bgLine')).toHaveCount(0);await expect(page.locator('#bgSave')).toBeDisabled();await expect(page.locator('#purchaseEditor')).toContainText('Historical Export Bag Bill');
 const period='&from=0001-01-01&to='+new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Karachi'}).format(new Date());
 const all=await page.request.get(BASE_URL+'/api/accounts_ledger_browser.php?entity=TTI&account=2130'+period);expect(all.status()).toBe(200);const control=await all.json();let grouped=0;
 for(const kind of ['CLEARING','TRANSPORT','FREIGHT','OTHER']){const r=await page.request.get(BASE_URL+'/api/accounts_ledger_browser.php?entity=TTI&account=SERVICE%7C'+kind+period);expect(r.status()).toBe(200);const group=await r.json();grouped+=Number(group.closing||0);expect(group.rows.every(row=>row.serviceGroup===kind)).toBe(true);}
 expect(grouped).toBeCloseTo(Number(control.closing||0),2);expect(control.accounts.find(a=>a.code==='2130').name).toBe('Transport, Freight & Clearing Payables');
 console.log('LIVE_SERVICE_CLASSIFICATION_REVIEW '+JSON.stringify((control.serviceClassificationReviews||[]).map(r=>({party:r.party,postedService:r.postedService,providerService:r.providerService}))));
 await page.evaluate(()=>window.TT_ALL_LEDGERS.open('','party'));await expect(page.locator('#tal-service-group')).toBeVisible();await page.locator('#tal-service-group').selectOption('TRANSPORT');await expect(page.locator('#tal-result')).not.toContainText('Loading ledger');expect(writes).toEqual([]);console.log('LIVE_HISTORICAL_BAGS_AND_RECONCILED_SERVICE_GROUPS_OK');
});


test('Mill Petty Cash reads the same Accounts postings without writes',async({page})=>{
 test.setTimeout(120_000);await signIn(page);const writes=[];page.on('request',request=>{if(request.method()==='POST'&&/\/api\/(bank_entries|mill_cash_ledger|operations)\.php/.test(request.url()))writes.push(request.url());});
 const expenseResponse=await page.request.get(BASE_URL+'/api/expenses_v1.php?entity=TTI');expect(expenseResponse.status()).toBe(200);const expenseView=await expenseResponse.json();const mill=(expenseView.locations||[]).find(location=>location.location==='MILL');expect(mill,'TTI has an owned mill location').toBeTruthy();
 const response=await page.request.get(BASE_URL+'/api/mill_cash_ledger.php?'+new URLSearchParams({entity:'TTI',locationId:mill.id}));expect(response.status()).toBe(200);const cash=await response.json();expect(cash.ok).toBe(true);expect(cash.location.id).toBe(mill.id);expect(Array.isArray(cash.rows)).toBe(true);expect(new Set(cash.rows.map(row=>row.id)).size).toBe(cash.rows.length);
 for(const row of cash.rows){expect(row.source).toBe('Accounts');expect(Number.isFinite(row.debit)&&Number.isFinite(row.credit)).toBe(true);expect(row.debit>=0&&row.credit>=0).toBe(true);expect(row.postId).toBeTruthy();}
 if(cash.rows.length){const row=cash.rows[0],posted=await page.request.get(BASE_URL+'/api/accounts_ledger_browser.php?'+new URLSearchParams({entity:'TTI',account:'POSTS',postId:row.postId}));expect(posted.status()).toBe(200);const view=await posted.json();expect(view.post.lines.some(line=>line.account==='1120'&&line.millLocationId===mill.id)).toBe(true);}
 expect(writes).toEqual([]);
});

test('Accounts review release: company views, reconciliation, entry cleanup and TG transfer',async({page})=>{
  test.setTimeout(240_000);await signIn(page);
  const attemptedWrites=[];await page.route('**/api/**',route=>{if(route.request().method()==='POST'&&new URL(route.request().url()).pathname!=='/api/session_activity.php'){attemptedWrites.push(new URL(route.request().url()).pathname);return route.abort();}return route.continue();});
  const now=new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Karachi',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
  let comparisons=0,reads=0;
  for(const entity of ['TTI','BRM','TG']){
    const bankResponse=await page.request.get(`${BASE_URL}/api/bank_accounts.php?entity=${entity}`);expect(bankResponse.status()).toBe(200);const banks=await bankResponse.json();expect(banks.ok).toBe(true);
    for(const endpoint of ['accounts_dashboard','bank_entries','other_purchases','company_investments']){
      const r=await page.request.get(`${BASE_URL}/api/${endpoint}.php?entity=${entity}`);expect(r.status(),entity+' '+endpoint).toBe(200);expect((await r.json()).ok).toBe(true);reads++;
    }
    for(const bank of (banks.accounts||[]).filter(b=>b.currency==='PKR'&&b.id!=='CASH|'+entity&&b.settings?.reconciliationEnabled!==false)){
      const r=await page.request.get(`${BASE_URL}/api/bank_reconciliation.php?`+new URLSearchParams({entity,bankId:bank.id,statementDate:'9999-12-31'}));expect(r.status()).toBe(200);const reconciliation=await r.json();expect(reconciliation.ok).toBe(true);
      if(reconciliation.revision===banks.revision){expect(Math.abs(Number(reconciliation.bookBalance)-Number(bank.bookBalance)),'Reconciliation and bank posted balance must agree for '+entity+' '+bank.id).toBeLessThan(.011);comparisons++;}
    }
  }
  expect(comparisons,'Live books must contain at least one comparable PKR bank').toBeGreaterThan(0);
  console.log('ACCOUNTS_REVIEW_BALANCES '+JSON.stringify({companyReads:reads,pkrBankComparisons:comparisons}));
  await page.locator('#ttChangeCompanyDesk').click();await page.locator('#ttCompanyMenu [data-entity="TTI"]').click();
  await page.locator('[data-tt-area="routine"]').click();expect(await page.locator('#ttExpenseMasters').count()).toBe(0);
  await page.getByRole('button',{name:/^Pay Expense /i}).click();await page.locator('[data-expense-mode="card"]').check();await expect(page.locator('#evStatementForm')).toBeVisible();await expect(page.locator('#evAddStatement')).toBeHidden();
  const payment=page.locator('#evStatementPayDate');if(await payment.count()){const date=await payment.inputValue();expect(await page.locator('#evStatementPayAccountDetails [data-cheque-date]').inputValue()).toBe(date);}
  await page.locator('#ttExpenseDesk [data-close]').click();
  await page.goto(`${BASE_URL}/accounts/bag-reports.php?view=report&entity=TTI`,{waitUntil:'domcontentloaded'});await expect(page.locator('#bgRun')).toBeVisible();expect(await page.locator('#bgSave').count()).toBe(0);expect(await page.locator('.ttbag-tabs').count()).toBe(0);
  const restricted=await page.request.get(`${BASE_URL}/accounts/bag-reports.php?view=master&entity=TTI`);expect(restricted.status()).toBe(403);
  await page.goto(`${BASE_URL}/accounts/index.php`,{waitUntil:'domcontentloaded'});await expect(page.locator('#ttChangeCompanyDesk')).toBeVisible();await page.locator('#ttChangeCompanyDesk').click();await page.locator('#ttCompanyMenu [data-entity="TG"]').click();
  await page.locator('[data-tt-area="tg-bank"]').click();await page.getByRole('button',{name:/^Inter Account Transfer /i}).click();await expect(page.locator('#tgfxDate')).toBeVisible();await expect(page.locator('#ws-bank')).toHaveClass(/active/);await expect(page.locator('#tgfxPost')).toBeDisabled();
  expect(attemptedWrites).toEqual([]);console.log('ACCOUNTS_REVIEW_READ_ONLY '+JSON.stringify({companyReads:reads,pkrBankComparisons:comparisons,tgTransferVisible:true,financialWrites:0,checkedAt:now}));
});


test('posted JV history is represented in the dated Post ID Register without writes', async ({ page }) => {
  const writes=[];await page.route('**/api/**',route=>{if(route.request().method()!=='GET'){writes.push(new URL(route.request().url()).pathname);return route.abort();}return route.continue();});
  await signIn(page);
  const response=await page.request.get(`${BASE_URL}/api/accounts_ledger_browser.php?entity=ALL&account=POSTS&from=0001-01-01&to=9999-12-31`);
  expect(response.status()).toBe(200);const register=await response.json();expect(register.ok).toBe(true);
  const rows=register.rows||[],publicIds=new Set(rows.map(r=>r.publicPostId||r.voucher)),internalIds=new Set(rows.map(r=>r.voucher));let checked=0,legacy=0,pending=0;
  for(let index=1;index<rows.length;index++)expect(rows[index-1].date>=rows[index].date,'Register remains ordered by transaction date').toBe(true);
  for(const company of ['TTI','BRM','TG']){
    const historyResponse=await page.request.get(`${BASE_URL}/api/journal_vouchers.php?entity=${company}`);expect(historyResponse.status()).toBe(200);const history=await historyResponse.json();expect(history.ok).toBe(true);
    for(const jv of history.jvs||[]){
      if(jv.status==='Pending Approval'){pending++;continue;}
      if(jv.status!=='Posted'||!jv.journalId)continue;
      expect(jv.publicPostId,'Every posted JV has a public Post ID').toBeTruthy();
      expect(publicIds.has(jv.publicPostId)||internalIds.has(jv.journalId),`Posted JV ${jv.journalId} must be represented in the register`).toBe(true);
      checked++;if(jv.publicPostId.endsWith('-JV'))legacy++;
    }
  }
  expect(writes).toEqual([]);console.log('LIVE_JV_REGISTER_AUDIT '+JSON.stringify({postedJvsChecked:checked,historicalJvLabels:legacy,pendingApproval:pending,registerRows:rows.length,financialWrites:0}));
});
